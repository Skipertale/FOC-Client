#test
import sys
import os
import logging
import asyncio
import importlib
import traceback
import hashlib
import re
import shutil
import subprocess
import tempfile
import time
import wave
import threading
from functools import partial
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from array import array
from pathlib import Path
from urllib.parse import urlparse

import websockets
import geoip2.database
import yaml

import server.logger
from server import achievements
from server import database
from server.hub_manager import HubManager

from server.client_manager import ClientManager
from server.emotes import Emotes
from server.discord_bot import UnifiedBot
from server.exceptions import ClientError, ServerError
from server.network.aoprotocol import AOProtocol
from server.network.aoprotocol_ws import new_websocket_client
from server.network.masterserverclient import MasterServerClient
from server.network.webhooks import Webhooks
from server.constants import remove_URL, dezalgo
from server.webpanel import broadcast_event

SFX_DIR = "storage/sfx"


logger = logging.getLogger("main")


class TsuServer3:
    """The main class for KFO-Server derivative of tsuserver3 server software."""

    def __init__(self):
        self.software = "KFO-Server"
        self.release = 3
        self.major_version = 3
        self.minor_version = 0

        self.config = None
        self.censors = None
        self.allowed_iniswaps = []
        self.char_list = None
        self.char_emotes = None
        self.music_list = []
        self.music_whitelist = []
        self.backgrounds = None
        self.backgrounds_categories = None
        self.server_links = None
        self.zalgo_tolerance = None
        self.ipRange_bans = []
        self.geoIpReader = None
        self.useGeoIp = False
        self.need_webhook = False
        self.supported_features = [
            "yellowtext",
            "customobjections",
            "prezoom",
            "flipping",
            "fastloading",
            "noencryption",
            "deskmod",
            "evidence",
            "modcall_reason",
            "cccc_ic_support",
            "casing_alerts",
            "arup",
            "looping_sfx",
            "additive",
            "effects",
            "expanded_desk_mods",
            "y_offset",
            "triplex",
            "typing_timer",
            "video_support",
        ]
        self.command_aliases = {}
        self.is_locked_down = False

        try:
            self.geoIpReader = geoip2.database.Reader(
                "./storage/GeoLite2-ASN.mmdb")
            self.useGeoIp = True
            # on debian systems you can use /usr/share/GeoIP/GeoIPASNum.dat if the geoip-database-extra package is installed
        except FileNotFoundError:
            self.useGeoIp = False

        self.ms_client = None
        self.loop = None
        self._ws_task = None
        self._stop_event = asyncio.Event()
        self._last_tts_cleanup = 0
        self.queue_manager = None
        self._asset_http_server = None
        self._asset_http_thread = None
        self._sfx_http_server = None
        self._sfx_http_thread = None
        self._sfx_http_url = ""
        sys.setrecursionlimit(2000)
        try:
            self.load_config()
            self.load_command_aliases()
            self.load_censors()
            self.load_iniswaps()
            self.load_characters()
            self.load_music()
            self.load_backgrounds()
            self.load_server_links()
            self.load_ipranges()
            self.hub_manager = HubManager(self)
        except yaml.YAMLError:
            print("There was a syntax error parsing a configuration file:")
            traceback.print_exc()
            print("Please revise your syntax and restart the server.")
            sys.exit(1)
        except OSError:
            print("There was an error opening or writing to a file:")
            traceback.print_exc()
            sys.exit(1)
        except Exception:
            print("There was a configuration error:")
            traceback.print_exc()
            print("Please check sample config files for the correct format.")
            sys.exit(1)

        self.client_manager = ClientManager(self)
        server.logger.setup_logging(debug=self.config["debug"])

        self.webhooks = Webhooks(self)
        self.discord_bot = None
        self.start_time = None
        self._webpanel_task = None

    def start(self):
        """Start the server."""
        logger.info("Starting server")
        loop = asyncio.get_event_loop_policy().get_event_loop()
        self.loop = loop
        from server.queue_manager import QueueManager
        self.queue_manager = QueueManager(self)

        bound_ip = "0.0.0.0"
        if self.config["local"]:
            bound_ip = "127.0.0.1"

        self._start_tts_asset_http_server()
        self._start_sfx_http_server()

        ao_server_crt = loop.create_server(
            lambda: AOProtocol(self), bound_ip, self.config["port"]
        )
        ao_server = loop.run_until_complete(ao_server_crt)

        if self.config["use_websockets"]:
            async def _serve_ws():
                async with websockets.serve(
                    new_websocket_client(self), bound_ip, self.config["websocket_port"]
                ):
                    await self._stop_event.wait()
            self._ws_task = asyncio.ensure_future(_serve_ws())

        if self.config["use_masterserver"]:
            self.ms_client = MasterServerClient(self)
            asyncio.ensure_future(self.ms_client.connect(), loop=loop)

        if self.config["zalgo_tolerance"]:
            self.zalgo_tolerance = self.config["zalgo_tolerance"]



        # UnifiedBot — запускается, если есть хоть один включённый раздел с token
        discord_token = ""
        for section, cfg in self.config.items():
            if isinstance(cfg, dict) and cfg.get("enabled") and cfg.get("token"):
                discord_token = cfg["token"]
                break
        if discord_token:
            try:
                self.discord_bot = UnifiedBot(self)
                asyncio.ensure_future(
                    self.discord_bot.init(discord_token), loop=loop
                )
            except Exception as ex:
                print(ex)
                
        asyncio.ensure_future(self.schedule_unbans())

        if self.config.get("webpanel", {}).get("enabled"):
            from server.webpanel import run_webpanel
            async def _wp_wrapper():
                try:
                    await run_webpanel(self)
                except Exception as exc:
                    import traceback
                    logger.error("Webpanel failed: %s", exc)
                    for line in traceback.format_exc().splitlines():
                        logger.error("  %s", line)
            self._webpanel_task = asyncio.ensure_future(_wp_wrapper())

            

        self.start_time = time.time()
        database.log_misc("start")
        print("Server started and is listening on port {}".format(
            self.config["port"]))

        try:
            loop.run_forever()
        except KeyboardInterrupt:
            print("KEYBOARD INTERRUPT")

        database.log_misc("stop")

        # Checkpoint CRM database to prevent WAL data loss
        try:
            from server.webpanel import _crm_repo
            if _crm_repo:
                _crm_repo.checkpoint()
        except Exception:
            pass

        # Signal shutdown first, then let loop process cleanup
        self._stop_event.set()
        ao_server.close()

        if self._ws_task:
            self._ws_task.cancel()
            try:
                loop.run_until_complete(self._ws_task)
            except (asyncio.CancelledError, Exception):
                pass

        loop.run_until_complete(
            ao_server.wait_closed()
        )
        loop.stop()
        loop.close()

    async def schedule_unbans(self):
        while True:
            database.schedule_unbans()
            await asyncio.sleep(3600 * 12)

    @property
    def version(self):
        """Get the server's current version."""
        return f"{self.release}.{self.major_version}.{self.minor_version}"

    def new_client(self, transport):
        """
        Create a new client based on a raw transport by passing
        it to the client manager.
        :param transport: asyncio transport
        :returns: created client object
        """
        peername = transport.get_extra_info("peername")[0]

        if self.useGeoIp:
            try:
                geoIpResponse = self.geoIpReader.asn(peername)
                asn = str(geoIpResponse.autonomous_system_number)
            except geoip2.errors.AddressNotFoundError:
                asn = "Loopback"
                pass
        else:
            asn = "Loopback"

        for line, rangeBan in enumerate(self.ipRange_bans):
            if rangeBan != "" and ((peername.startswith(rangeBan) and (rangeBan.endswith('.') or rangeBan.endswith(':'))) or asn == rangeBan):
                msg = "BD#"
                msg += "Abuse\r\n"
                msg += f"ID: {line}\r\n"
                msg += "Until: N/A"
                msg += "#%"

                transport.write(msg.encode("utf-8"))
                raise ClientError

        c = self.client_manager.new_client(transport)
        c.server = self
        c.area = self.hub_manager.default_hub().default_area()
        c.area.new_client(c)
        return c

    def remove_client(self, client):
        """
        Remove a disconnected client.
        :param client: client object

        """
        if client.area:
            area = client.area
            if (
                not area.dark
                and not area.force_sneak
                and not client.sneaking
                and not client.hidden
            ):
                area.broadcast_ooc(
                    f"[{client.id}] {client.showname} has disconnected.")
            area.remove_client(client)
        broadcast_event("disconnect", {
            "time": time.strftime("%H:%M:%S"),
            "who": getattr(client, "name", "?"),
            "char": getattr(client, "char_name", ""),
            "what": "Отключился",
        })
        self.client_manager.remove_client(client)
        if self.discord_bot is not None:
            try:
                asyncio.ensure_future(self.discord_bot.cancel_login_for_client(client), loop=self.loop)
            except Exception:
                pass

    @property
    def player_count(self):
        """Get the number of non-spectating clients."""
        return len(
            [client for client in self.client_manager.clients if client.char_id != -1]
        )


    def _normalize_url_prefix(self, value):
        value = str(value or '').strip()
        if not value:
            return ''
        if not value.endswith('/'):
            value += '/'
        return value

    def _guess_asset_http_public_url(self):
        http_cfg = self.config.get("tts", {}).get("asset_http", {})
        explicit = self._normalize_url_prefix(http_cfg.get("public_url", ""))
        if explicit:
            return explicit

        base_path = str(http_cfg.get("base_path", "/base/") or "/base/").strip()
        if not base_path.startswith('/'):
            base_path = '/' + base_path
        if not base_path.endswith('/'):
            base_path += '/'

        configured_asset_url = str(self.config.get("asset_url", "") or '').strip()
        parsed = urlparse(configured_asset_url) if configured_asset_url else None
        scheme = 'http'
        host = '127.0.0.1' if self.config.get('local') else 'localhost'
        if parsed and parsed.scheme and parsed.netloc:
            scheme = parsed.scheme
            host = parsed.hostname or host
        port = int(http_cfg.get('port', 8030) or 8030)
        return f"{scheme}://{host}:{port}{base_path.lstrip('/')}"

    def _start_tts_asset_http_server(self):
        http_cfg = self.config.get("tts", {}).get("asset_http", {})
        if not http_cfg.get("enabled"):
            return
        if self._asset_http_server is not None:
            return

        asset_dir = Path(self.config.get("tts", {}).get("asset_dir", "") or "")
        if not asset_dir:
            logger.warning("TTS asset HTTP server is enabled, but tts.asset_dir is empty.")
            return
        if not asset_dir.exists():
            logger.warning("TTS asset HTTP server is enabled, but asset_dir does not exist: %s", asset_dir)
            return

        serve_parent = bool(http_cfg.get("serve_parent_of_asset_dir", True))
        directory = asset_dir.parent if serve_parent else asset_dir
        host = str(http_cfg.get("host", "0.0.0.0") or "0.0.0.0")
        port = int(http_cfg.get("port", 8030) or 8030)

        class QuietHandler(SimpleHTTPRequestHandler):
            def log_message(self, format, *args):
                logger.debug("TTS asset HTTP: " + (format % args))

        handler = partial(QuietHandler, directory=str(directory))
        try:
            self._asset_http_server = ThreadingHTTPServer((host, port), handler)
            self._asset_http_thread = threading.Thread(
                target=self._asset_http_server.serve_forever,
                name="tts-asset-http",
                daemon=True,
            )
            self._asset_http_thread.start()
            public_url = self._guess_asset_http_public_url()
            logger.info("Started built-in TTS asset HTTP server on %s:%s serving %s", host, port, directory)
            logger.info("Built-in TTS asset URL prefix: %s", public_url)
            if http_cfg.get("override_asset_url", True):
                self.config["asset_url"] = public_url
                logger.info("asset_url overridden for clients: %s", self.config["asset_url"])
        except Exception as ex:
            self._asset_http_server = None
            self._asset_http_thread = None
            logger.warning("Failed to start built-in TTS asset HTTP server: %s", ex)

    def _start_sfx_http_server(self):
        sfx_cfg = self.config.get("sfx_http", {})
        if not sfx_cfg.get("enabled"):
            return
        if self._sfx_http_server is not None:
            return

        sfx_dir = SFX_DIR
        if not os.path.isdir(sfx_dir):
            try:
                os.makedirs(sfx_dir)
            except Exception:
                logger.warning("SFX HTTP server: cannot create %s", sfx_dir)
                return

        serve_parent = bool(sfx_cfg.get("serve_parent_dir", True))
        directory = os.path.dirname(sfx_dir) if serve_parent else sfx_dir

        host = str(sfx_cfg.get("host", "0.0.0.0") or "0.0.0.0")
        port = int(sfx_cfg.get("port", 8041) or 8041)

        class QuietHandler(SimpleHTTPRequestHandler):
            def log_message(self, format, *args):
                logger.debug("SFX HTTP: " + (format % args))

        handler = partial(QuietHandler, directory=directory)
        try:
            self._sfx_http_server = ThreadingHTTPServer((host, port), handler)
            self._sfx_http_thread = threading.Thread(
                target=self._sfx_http_server.serve_forever,
                name="sfx-http",
                daemon=True,
            )
            self._sfx_http_thread.start()
            logger.info("Started SFX HTTP server on %s:%s serving %s", host, port, directory)

            # Compute public SFX HTTP URL for the SFX command to use
            public_url = str(sfx_cfg.get("public_url", "") or "").strip()
            if not public_url:
                if host in ("0.0.0.0", "::"):
                    # Try to guess a reachable hostname
                    asset_url = self.config.get("asset_url", "")
                    if asset_url:
                        parsed = urlparse(asset_url)
                        sfx_host = parsed.hostname or "localhost"
                    else:
                        sfx_host = "localhost"
                else:
                    sfx_host = host
                public_url = f"http://{sfx_host}:{port}/"
            self._sfx_http_url = public_url.rstrip("/") + "/"
            logger.info("SFX HTTP URL: %s", self._sfx_http_url)

            # Set asset_url for the AO client if not already configured
            if sfx_cfg.get("override_asset_url", True) and not self.config.get("asset_url"):
                self.config["asset_url"] = self._sfx_http_url
                logger.info("asset_url set to %s for SFX client access", self.config["asset_url"])
        except Exception as ex:
            self._sfx_http_server = None
            self._sfx_http_thread = None
            logger.warning("Failed to start SFX HTTP server: %s", ex)

    def can_generate_tts_assets(self):
        tts_cfg = self.config.get("tts", {})
        return bool(tts_cfg.get("enabled") and tts_cfg.get("asset_dir"))

    def build_tts_line(self, speaker_name, message):
        if not self.config.get("tts", {}).get("enabled", True):
            return ""
        if message is None:
            return ""

        text = str(message)
        text = text.replace("<num>", "#")
        text = text.replace("<and>", " и ")
        text = text.replace("<percent>", " процент ")
        text = text.replace("<dollar>", " доллар ")
        text = re.sub(r"\s+", " ", text).strip()
        text = re.sub(r"[{}|`~]", "", text)
        if not text:
            return ""

        max_chars = int(self.config.get("tts", {}).get("max_chars", 500) or 500)
        if len(text) > max_chars:
            text = text[:max_chars].rstrip() + "..."

        speaker_name = re.sub(r"\s+", " ", str(speaker_name or "Кто-то")).strip()
        if not speaker_name:
            speaker_name = "Кто-то"
        return f"{speaker_name} сказала. {text}"

    def _cleanup_tts_cache(self):
        tts_cfg = self.config.get("tts", {})
        asset_dir = tts_cfg.get("asset_dir", "")
        asset_subdir = tts_cfg.get("asset_subdir", "tts")
        ttl = int(tts_cfg.get("cleanup_after_seconds", 900) or 900)
        if not asset_dir or ttl <= 0:
            return

        now = time.time()
        if now - self._last_tts_cleanup < 60:
            return
        self._last_tts_cleanup = now

        target_dir = Path(asset_dir) / asset_subdir
        cutoff = now - ttl

        cleanup_dirs = []
        if target_dir.exists():
            cleanup_dirs.append(target_dir)
        asset_root = Path(asset_dir)
        if asset_root.exists() and asset_root != target_dir:
            cleanup_dirs.append(asset_root)

        for base_dir in cleanup_dirs:
            for pattern in ("*.wav", "sfx-tts-*", "tts_*"):
                for entry in base_dir.glob(pattern):
                    try:
                        if entry.is_file() and entry.stat().st_mtime < cutoff:
                            entry.unlink()
                    except OSError:
                        continue

    def _get_powershell_binary(self):
        return (
            shutil.which("powershell.exe")
            or shutil.which("powershell")
            or shutil.which("pwsh.exe")
            or shutil.which("pwsh")
        )

    def _get_cscript_binary(self):
        return shutil.which("cscript.exe") or shutil.which("cscript")

    def _tts_audio_has_content(self, path):
        try:
            with wave.open(str(path), "rb") as wav_file:
                if wav_file.getnframes() <= 0:
                    return False
                if wav_file.getcomptype() != "NONE":
                    return True
                if wav_file.getsampwidth() != 2:
                    raw = wav_file.readframes(min(wav_file.getnframes(), 8192))
                    return any(b not in (0, 128) for b in raw)
                raw = wav_file.readframes(min(wav_file.getnframes(), 32768))
                samples = array("h")
                samples.frombytes(raw)
                if sys.byteorder != "little":
                    samples.byteswap()
                if not samples:
                    return False
                peak = max(abs(int(sample)) for sample in samples)
                return peak > 2
        except Exception:
            try:
                return Path(path).stat().st_size > 1024
            except OSError:
                return False

    def _transliterate_cyrillic(self, value):
        if not value:
            return value

        multi_map = {
            "ж": "zh", "ц": "ts", "ч": "ch", "ш": "sh", "щ": "sch",
            "ю": "yu", "я": "ya", "ё": "yo", "х": "kh",
            "Ж": "Zh", "Ц": "Ts", "Ч": "Ch", "Ш": "Sh", "Щ": "Sch",
            "Ю": "Yu", "Я": "Ya", "Ё": "Yo", "Х": "Kh",
        }
        single_map = str.maketrans({
            "а": "a", "б": "b", "в": "v", "г": "g", "д": "d", "е": "e",
            "з": "z", "и": "i", "й": "y", "к": "k", "л": "l", "м": "m",
            "н": "n", "о": "o", "п": "p", "р": "r", "с": "s", "т": "t",
            "у": "u", "ф": "f", "ы": "y", "э": "e", "і": "i", "ї": "yi",
            "є": "ye", "ґ": "g", "ь": "", "ъ": "",
            "А": "A", "Б": "B", "В": "V", "Г": "G", "Д": "D", "Е": "E",
            "З": "Z", "И": "I", "Й": "Y", "К": "K", "Л": "L", "М": "M",
            "Н": "N", "О": "O", "П": "P", "Р": "R", "С": "S", "Т": "T",
            "У": "U", "Ф": "F", "Ы": "Y", "Э": "E", "І": "I", "Ї": "Yi",
            "Є": "Ye", "Ґ": "G", "Ь": "", "Ъ": "",
        })

        pieces = []
        for char in value:
            pieces.append(multi_map.get(char, char.translate(single_map)))
        transliterated = "".join(pieces)
        transliterated = re.sub(r"\s+", " ", transliterated).strip()
        return transliterated or value

    def _apply_demonic_effect(self, path, pitch_setting):
        try:
            with wave.open(str(path), "rb") as src:
                params = src.getparams()
                if params.sampwidth != 2 or params.nframes <= 0:
                    return
                frames = src.readframes(params.nframes)

            samples = array("h")
            samples.frombytes(frames)
            if sys.byteorder != "little":
                samples.byteswap()
            if not samples:
                return

            channels = max(1, params.nchannels)
            frame_rate = max(8000, params.framerate)

            pitch_percent = 0
            try:
                pitch_match = re.search(r"-?\d+", str(pitch_setting))
                if pitch_match:
                    pitch_percent = int(pitch_match.group(0))
            except Exception:
                pitch_percent = 0

            slow_factor = 1.0 - (pitch_percent / 100.0)
            slow_factor = max(0.72, min(0.95, slow_factor))
            out_rate = max(8000, int(frame_rate * slow_factor))

            delay_frames = max(1, int(frame_rate * 0.035))
            delay_samples = delay_frames * channels
            echo_gain = 0.45
            dry_gain = 0.9

            mixed = array("h", samples)
            for index in range(len(mixed)):
                value = int(mixed[index] * dry_gain)
                if index >= delay_samples:
                    value += int(samples[index - delay_samples] * echo_gain)
                if value > 32767:
                    value = 32767
                elif value < -32768:
                    value = -32768
                mixed[index] = value

            temp_path = Path(str(path) + ".tmp")
            if sys.byteorder != "little":
                mixed.byteswap()
            with wave.open(str(temp_path), "wb") as dst:
                dst.setnchannels(params.nchannels)
                dst.setsampwidth(params.sampwidth)
                dst.setframerate(out_rate)
                dst.writeframes(mixed.tobytes())
            temp_path.replace(path)
        except Exception as exc:
            logger.warning("TTS post-processing failed: %s", exc)


    def generate_tts_asset(self, text):
        tts_cfg = self.config.get("tts", {})
        if not tts_cfg.get("enabled", True):
            return None

        asset_dir = tts_cfg.get("asset_dir", "")
        asset_subdir = tts_cfg.get("asset_subdir", "tts")
        if not asset_dir:
            return None

        self._cleanup_tts_cache()

        target_dir = Path(asset_dir) / asset_subdir
        target_dir.mkdir(parents=True, exist_ok=True)

        voice_name = str(tts_cfg.get("voice_name", "") or "")
        pitch = str(tts_cfg.get("pitch", "-45%") or "-45%")
        rate = str(tts_cfg.get("rate", "-20%") or "-20%")
        volume = str(tts_cfg.get("volume", 100) or 100)

        min_valid_size = 1024
        engine_tag = "sapi-vbs-demon-v8-kfo-sfx"
        spoken_text = self._transliterate_cyrillic(text)
        digest = hashlib.sha1(
            f"{engine_tag}|{voice_name}|{pitch}|{rate}|{volume}|{spoken_text}".encode("utf-8")
        ).hexdigest()
        filename = f"tts_{digest}.wav"
        output_path = target_dir / filename
        sfx_basename = f"sfx-tts-{digest}"
        root_output_wav = Path(asset_dir) / f"{sfx_basename}.wav"
        root_output_alias = Path(asset_dir) / sfx_basename
        relative_path = sfx_basename

        def sync_client_aliases():
            shutil.copyfile(output_path, root_output_wav)
            shutil.copyfile(output_path, root_output_alias)

        if output_path.exists():
            try:
                if output_path.stat().st_size >= min_valid_size and self._tts_audio_has_content(output_path):
                    try:
                        sync_client_aliases()
                    except OSError:
                        pass
                    return relative_path
                output_path.unlink()
            except OSError:
                pass

        try:
            rate_match = re.search(r"-?\d+", rate)
            sapi_rate = int(round(int(rate_match.group(0)) / 10.0)) if rate_match else 0
        except Exception:
            sapi_rate = 0
        sapi_rate = max(-10, min(10, sapi_rate))

        try:
            volume_int = int(volume)
        except Exception:
            volume_int = 100
        volume_int = max(0, min(100, volume_int))

        vbs = r''' 
Dim outputPath, textPath, requestedVoice, speechRate, speechVolume
outputPath = WScript.Arguments(0)
textPath = WScript.Arguments(1)
requestedVoice = WScript.Arguments(2)
speechRate = CInt(WScript.Arguments(3))
speechVolume = CInt(WScript.Arguments(4))

Function ReadAsciiFile(path)
    Dim fso, stream
    Set fso = CreateObject("Scripting.FileSystemObject")
    If Not fso.FileExists(path) Then
        ReadAsciiFile = ""
        Exit Function
    End If
    Set stream = CreateObject("ADODB.Stream")
    stream.Type = 2
    stream.Charset = "us-ascii"
    stream.Open
    stream.LoadFromFile path
    ReadAsciiFile = stream.ReadText
    stream.Close
End Function

Sub SelectVoiceByName(spVoice, requested)
    On Error Resume Next
    If Trim(requested) = "" Then Exit Sub
    Dim voices, i, token, desc
    Set voices = spVoice.GetVoices()
    For i = 0 To voices.Count - 1
        Set token = voices.Item(i)
        desc = ""
        desc = token.GetDescription
        If InStr(1, desc, requested, 1) > 0 Then
            Set spVoice.Voice = token
            Exit Sub
        End If
    Next
End Sub

Dim textToSpeak
textToSpeak = Trim(ReadAsciiFile(textPath))
If textToSpeak = "" Then
    WScript.StdErr.WriteLine "TTS input text is empty."
    WScript.Quit 1
End If

On Error Resume Next
Dim spVoice, fileStream
Set spVoice = CreateObject("SAPI.SpVoice")
If Err.Number <> 0 Then
    WScript.StdErr.WriteLine "Failed to create SAPI.SpVoice: " & Err.Description
    WScript.Quit 1
End If
Err.Clear

Call SelectVoiceByName(spVoice, requestedVoice)
spVoice.Rate = speechRate
spVoice.Volume = speechVolume

Set fileStream = CreateObject("SAPI.SpFileStream")
If Err.Number <> 0 Then
    WScript.StdErr.WriteLine "Failed to create SAPI.SpFileStream: " & Err.Description
    WScript.Quit 1
End If
Err.Clear

fileStream.Format.Type = 22
fileStream.Open outputPath, 3, False
If Err.Number <> 0 Then
    WScript.StdErr.WriteLine "Failed to open output WAV: " & Err.Description
    WScript.Quit 1
End If
Err.Clear

Set spVoice.AudioOutputStream = fileStream
spVoice.Speak textToSpeak
If Err.Number <> 0 Then
    WScript.StdErr.WriteLine "Speak failed: " & Err.Description
    On Error Resume Next
    fileStream.Close
    WScript.Quit 1
End If

On Error Resume Next
fileStream.Close
Set spVoice.AudioOutputStream = Nothing
'''

        cscript = self._get_cscript_binary()
        powershell = self._get_powershell_binary()
        script_path = None
        text_path = None
        result = None

        def run_cscript():
            return subprocess.run(
                [
                    cscript,
                    "//NoLogo",
                    script_path,
                    str(output_path),
                    text_path,
                    voice_name,
                    str(sapi_rate),
                    str(volume_int),
                ],
                capture_output=True,
                text=True,
                timeout=45,
                check=False,
            )

        def run_powershell_fallback():
            ps_script = r'''
param(
    [string]$OutputPath,
    [string]$TextPath,
    [string]$RequestedVoice,
    [int]$SpeechRate,
    [int]$SpeechVolume
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Speech
$text = [System.IO.File]::ReadAllText($TextPath, [System.Text.Encoding]::ASCII).Trim()
if ([string]::IsNullOrWhiteSpace($text)) {
    throw 'TTS input text is empty.'
}
$synth = New-Object System.Speech.Synthesis.SpeechSynthesizer
try {
    if ($RequestedVoice -and $RequestedVoice.Trim() -ne '') {
        try { $synth.SelectVoice($RequestedVoice) } catch {}
    }
    $synth.Rate = $SpeechRate
    $synth.Volume = $SpeechVolume
    $synth.SetOutputToWaveFile($OutputPath)
    $synth.Speak($text)
} finally {
    try { $synth.Dispose() } catch {}
}
if (-not (Test-Path -LiteralPath $OutputPath)) {
    throw 'TTS output file was not created.'
}
'''
            ps_script_path = None
            try:
                with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8-sig", suffix=".ps1", delete=False) as psf:
                    psf.write(ps_script)
                    ps_script_path = psf.name
                return subprocess.run(
                    [
                        powershell,
                        "-NoProfile",
                        "-ExecutionPolicy",
                        "Bypass",
                        "-File",
                        ps_script_path,
                        str(output_path),
                        text_path,
                        voice_name,
                        str(sapi_rate),
                        str(volume_int),
                    ],
                    capture_output=True,
                    text=True,
                    timeout=45,
                    check=False,
                )
            finally:
                if ps_script_path:
                    try:
                        Path(ps_script_path).unlink(missing_ok=True)
                    except OSError:
                        pass

        try:
            with tempfile.NamedTemporaryFile(mode="w", encoding="ascii", suffix=".vbs", delete=False) as script_file:
                script_file.write(vbs)
                script_path = script_file.name
            with tempfile.NamedTemporaryFile(mode="w", encoding="ascii", suffix=".txt", delete=False) as text_file:
                text_file.write(spoken_text)
                text_path = text_file.name

            if cscript is not None:
                result = run_cscript()
                if result.returncode != 0 and powershell is not None:
                    logger.warning("VBScript TTS path failed, falling back to PowerShell/System.Speech: %s", (result.stderr or result.stdout or "").strip())
                    try:
                        output_path.unlink(missing_ok=True)
                    except OSError:
                        pass
                    result = run_powershell_fallback()
            elif powershell is not None:
                result = run_powershell_fallback()
            else:
                logger.warning("TTS requested but no cscript.exe or PowerShell executable was found.")
                return None
        except Exception as exc:
            logger.warning("TTS generation failed: %s", exc)
            return None
        finally:
            for temp_path in (script_path, text_path):
                if temp_path:
                    try:
                        Path(temp_path).unlink(missing_ok=True)
                    except OSError:
                        pass

        if result is None:
            return None
        if result.returncode != 0:
            stderr = (result.stderr or result.stdout or "").strip()
            if stderr:
                logger.warning("TTS generation failed: %s", stderr)
            return None
        if not output_path.exists():
            return None
        try:
            if output_path.stat().st_size < min_valid_size:
                output_path.unlink(missing_ok=True)
                return None
        except OSError:
            return None

        if not self._tts_audio_has_content(output_path):
            try:
                output_path.unlink(missing_ok=True)
            except OSError:
                pass
            logger.warning("TTS generation produced a silent WAV file for text: %s", text[:120])
            return None

        self._apply_demonic_effect(output_path, pitch)

        if not self._tts_audio_has_content(output_path):
            try:
                output_path.unlink(missing_ok=True)
            except OSError:
                pass
            logger.warning("TTS post-processed WAV file became silent for text: %s", text[:120])
            return None

        try:
            sync_client_aliases()
        except OSError as exc:
            logger.warning("TTS alias copy failed: %s", exc)
            return None

        return relative_path

    def load_config(self):
        """Load the main server configuration from a YAML file."""
        try:
            with open("config/config.yaml", "r", encoding="utf-8") as cfg:
                self.config = yaml.safe_load(cfg)
                self.config["motd"] = self.config["motd"].replace("\\n", " \n")
        except OSError:
            print("error: config/config.yaml wasn't found.")
            print("You are either running from the wrong directory, or")
            print("you forgot to rename config_sample (read the instructions).")
            sys.exit(1)

        if "music_change_floodguard" not in self.config:
            self.config["music_change_floodguard"] = {
                "times_per_interval": 1,
                "interval_length": 0,
                "mute_length": 0,
            }
        if "wtce_floodguard" not in self.config:
            self.config["wtce_floodguard"] = {
                "times_per_interval": 1,
                "interval_length": 0,
                "mute_length": 0,
            }
        if "ooc_floodguard" not in self.config:
            self.config["ooc_floodguard"] = {
                "times_per_interval": 1,
                "interval_length": 0,
                "mute_length": 0,
            }

        if "zalgo_tolerance" not in self.config:
            self.config["zalgo_tolerance"] = 3

        if isinstance(self.config["modpass"], str):
            self.config["modpass"] = {"default": {
                "password": self.config["modpass"]}}
        if "multiclient_limit" not in self.config:
            self.config["multiclient_limit"] = 16
        if "asset_url" not in self.config:
            self.config["asset_url"] = ""
        if "block_repeat" not in self.config:
            self.config["block_repeat"] = True
        if "block_relative" not in self.config:
            self.config["block_relative"] = False
        if "global_chat" not in self.config:
            self.config["global_chat"] = True
        if "debug" not in self.config:
            self.config["debug"] = False

        if "login_approval" not in self.config:
            self.config["login_approval"] = {}
        if "whitelist" not in self.config:
            self.config["whitelist"] = {}
        self.config["whitelist"].setdefault("enabled", False)
        self.config["whitelist"].setdefault("token", "")
        self.config["whitelist"].setdefault("guild_id", None)
        self.config["whitelist"].setdefault("request_channel_id", None)
        self.config["whitelist"].setdefault("request_channel", "")
        self.config["whitelist"].setdefault("admin_user_id", None)
        self.config["whitelist"].setdefault("admin_role_id", None)

        if "tts" not in self.config:
            self.config["tts"] = {}
        self.config["tts"].setdefault("enabled", True)
        self.config["tts"].setdefault("asset_dir", "")
        self.config["tts"].setdefault("asset_subdir", "tts")
        self.config["tts"].setdefault("voice_name", "")
        self.config["tts"].setdefault("volume", 100)
        self.config["tts"].setdefault("rate", "-20%")
        self.config["tts"].setdefault("pitch", "-45%")
        self.config["tts"].setdefault("max_chars", 500)
        self.config["tts"].setdefault("cleanup_after_seconds", 900)
        self.config["tts"].setdefault("asset_http", {})
        self.config["tts"]["asset_http"].setdefault("enabled", False)
        self.config["tts"]["asset_http"].setdefault("host", "0.0.0.0")
        self.config["tts"]["asset_http"].setdefault("port", 8030)
        self.config["tts"]["asset_http"].setdefault("public_url", "")
        self.config["tts"]["asset_http"].setdefault("base_path", "/base/")
        self.config["tts"]["asset_http"].setdefault("serve_parent_of_asset_dir", True)
        self.config["tts"]["asset_http"].setdefault("override_asset_url", True)
        self.config["login_approval"].setdefault("enabled", False)
        self.config["login_approval"].setdefault("token", "")
        self.config["login_approval"].setdefault("guild_id", None)
        self.config["login_approval"].setdefault("channel_id", None)
        self.config["login_approval"].setdefault("channel", "")
        self.config["login_approval"].setdefault("admin_user_id", None)
        self.config["login_approval"].setdefault("admin_role_id", None)
        self.config["login_approval"].setdefault("mention_here", False)
        self.config["login_approval"].setdefault("request_timeout", 300)
        self.config["login_approval"].setdefault("admin_panel_channel_id", None)
        self.config["login_approval"].setdefault("admin_panel_channel", "")
        self.config["login_approval"].setdefault("admin_panel_category_id", None)
        self.config["login_approval"].setdefault("admin_panel_category", "")
        self.config["login_approval"].setdefault("admin_panel_log_path", "logs/server.log")
        self.config["login_approval"].setdefault("log_update_interval", 3)
        self.config["login_approval"].setdefault("log_stream_timeout", 300)
        self.config["login_approval"].setdefault("log_tail_lines", 40)
        self.config["login_approval"].setdefault("log_max_chars", 1500)
        self.config["login_approval"].setdefault("admin_ooc_prefix", "[M] ")

        if "sfx_http" not in self.config:
            self.config["sfx_http"] = {}
        self.config["sfx_http"].setdefault("enabled", True)
        self.config["sfx_http"].setdefault("host", "0.0.0.0")
        self.config["sfx_http"].setdefault("port", 8041)
        self.config["sfx_http"].setdefault("public_url", "")
        self.config["sfx_http"].setdefault("serve_parent_dir", True)
        self.config["sfx_http"].setdefault("override_asset_url", True)

    def load_command_aliases(self):
        """Load a list of alternative command names."""
        try:
            with open(
                "config/command_aliases.yaml", "r", encoding="utf-8"
            ) as command_aliases:
                self.command_aliases = yaml.safe_load(command_aliases)
        except Exception:
            logger.debug("Cannot find command_aliases.yaml")

    def load_censors(self):
        """Load a list of banned words to scrub from chats."""
        try:
            with open("config/censors.yaml", "r", encoding="utf-8") as censors:
                self.censors = yaml.safe_load(censors)
        except Exception:
            logger.debug("Cannot find censors.yaml")

    def load_characters(self):
        """Load the character list from a YAML file."""
        with open("config/characters.yaml", "r", encoding="utf-8") as chars:
            self.char_list = yaml.safe_load(chars)
        self.char_emotes = {char: Emotes(char) for char in self.char_list}

    def load_music(self):
        self.load_music_list()

    def load_backgrounds(self):
        """Load the backgrounds list from a YAML file and scan storage/backgrounds/."""
        with open("config/backgrounds.yaml", "r", encoding="utf-8") as bgs:
            bg_yaml = yaml.safe_load(bgs)
            # old style of backgrounds.yaml
            if type(bg_yaml) is list:
                self.backgrounds_categories = {"backgrounds": bg_yaml}
                self.backgrounds = bg_yaml
            # new style of categorized backgrounds.yaml
            else:
                self.backgrounds_categories = bg_yaml
                self.backgrounds = sum(list(self.backgrounds_categories.values()), [])
        # Scan storage/backgrounds/ for additional server-side backgrounds
        bg_storage = "storage/backgrounds"
        try:
            os.makedirs(bg_storage, exist_ok=True)
        except Exception:
            pass
        if os.path.isdir(bg_storage):
            for d in sorted(os.listdir(bg_storage)):
                if os.path.isdir(os.path.join(bg_storage, d)) and d.lower() not in (b.lower() for b in self.backgrounds):
                    self.backgrounds.append(d)
                    if "Server" in self.backgrounds_categories:
                        self.backgrounds_categories["Server"].append(d)
                    else:
                        self.backgrounds_categories["Server"] = [d]

    def load_server_links(self):
        """Load the server links list from a YAML file."""
        try:
            with open("config/server_links.yaml", "r", encoding="utf-8") as links:
                self.server_links = yaml.safe_load(links)
        except Exception as e:
            logger.debug("Cannot find server_links.yaml, error: (%s)", e)

    def load_iniswaps(self):
        """Load a list of characters for which INI swapping is allowed."""
        try:
            with open("config/iniswaps.yaml", "r", encoding="utf-8") as iniswaps:
                self.allowed_iniswaps = yaml.safe_load(iniswaps)
        except Exception:
            logger.debug("Cannot find iniswaps.yaml")

    def load_ipranges(self):
        """Load a list of banned IP ranges."""
        try:
            with open("config/iprange_ban.txt", "r", encoding="utf-8") as ipranges:
                self.ipRange_bans = ipranges.read().splitlines()
        except Exception:
            logger.debug("Cannot find iprange_ban.txt")

    def load_music_list(self):
        try:
            with open("config/music.yaml", "r", encoding="utf-8") as music:
                self.music_list = yaml.safe_load(music)
        except Exception:
            logger.debug("Cannot find music.yaml")

    def build_music_list(self, music_list):
        song_list = []
        for item in music_list:
            if "category" not in item:  # skip settings n stuff
                continue
            song_list.append(item["category"])
            for song in item["songs"]:
                song_list.append(song["name"])
        return song_list

    def get_song_data(self, music_list, music):
        """
        Get information about a track, if exists.
        :param music_list: music list to search
        :param music: track name
        :returns: tuple (name, length or -1)
        :raises: ServerError if track not found
        """
        for item in music_list:
            if "category" not in item:  # skip settings n stuff
                continue
            if item["category"] == music:
                return item["category"], 0
            for song in item["songs"]:
                if song["name"] == music:
                    length = -1
                    if "length" in song:
                        length = song["length"]

                    if "path" in song:
                        return song["path"], length

                    return song["name"], length
        raise ServerError("Music not found.")

    def get_song_is_category(self, music_list, music):
        """
        Get whether a track is a category.
        :param music_list: music list to search
        :param music: track name
        :returns: bool
        """
        for item in music_list:
            if "category" not in item:  # skip settings n stuff
                continue
            if item["category"] == music:
                return True
        return False

    def send_all_cmd_pred(self, cmd, *args, pred=lambda x: True):
        """
        Broadcast an AO-compatible command to all clients that satisfy
        a predicate.
        """
        for client in self.client_manager.clients:
            if pred(client):
                client.send_command(cmd, *args)

    def broadcast_global(self, client, msg, as_mod=False):
        """
        Broadcast an OOC message to all clients that do not have
        global chat muted.
        :param client: sender
        :param msg: message
        :param as_mod: add moderator prefix (Default value = False)

        """
        if as_mod:
            as_mod = "[M]"
        else:
            as_mod = ""
        ooc_name = (
            f"<dollar>G[{client.area.area_manager.abbreviation}]|{as_mod}{client.name}"
        )
        self.send_all_cmd_pred("CT", ooc_name, msg,
                               pred=lambda x: not x.muted_global)

    def send_modchat(self, client, msg):
        """
        Send an OOC message to all mods.
        :param client: sender
        :param msg: message

        """
        ooc_name = "{}[{}][{}]".format(
            "<dollar>M", client.area.id, client.name)
        self.send_all_cmd_pred("CT", ooc_name, msg, pred=lambda x: x.is_mod)

    def broadcast_need(self, client, msg):
        """
        Broadcast an OOC "need" message to all clients who do not
        have advertisements muted.
        :param client: sender
        :param msg: message

        """
        self.send_all_cmd_pred(
            "CT",
            self.config["hostname"],
            f"=== Advert ===\r\n{client.name} in {client.area.name} [{client.area.id}] (Hub {client.area.area_manager.id}) needs {msg}\r\n===============",
            "1",
            pred=lambda x: not x.muted_adverts,
        )

    def send_arup(self, client, args):
        """Update the area properties for this 2.6 client.

        Playercount:
            ARUP#0#<area1_p: int>#<area2_p: int>#...
        Status:
            ARUP#1#<area1_s: string>#<area2_s: string>#...
        CM:
            ARUP#2#<area1_cm: string>#<area2_cm: string>#...
        Lockedness:
            ARUP#3#<area1_l: string>#<area2_l: string>#...

        :param args:

        """
        if len(args) < 2:
            # An argument count smaller than 2 means we only got the identifier of ARUP.
            return
        if args[0] not in (0, 1, 2, 3):
            return

        if args[0] == 0:
            for part_arg in args[1:]:
                try:
                    int(part_arg)
                except Exception:
                    return
        elif args[0] in (1, 2, 3):
            for part_arg in args[1:]:
                try:
                    str(part_arg)
                except Exception:
                    return

        client.send_command("ARUP", *args)

    def _bridge_cfg_for(self, hub_id, area_id):
        for section, cfg in self.config.items():
            if isinstance(cfg, dict) and cfg.get("enabled") and cfg.get("channel"):
                if cfg.get("hub_id") == hub_id and cfg.get("area_id") == area_id:
                    return cfg
        return None

    def send_discord_chat(self, name, message, hub_id=0, area_id=0, section=None):
        if section:
            cfg = self.config.get(section, {})
        else:
            cfg = self._bridge_cfg_for(hub_id, area_id)
        if not cfg:
            return
        area = self.hub_manager.get_hub_by_id(hub_id).get_area_by_id(area_id)
        message = dezalgo(message)
        message = remove_URL(message)
        message = (
            message.replace("}", "\\}")
            .replace("{", "\\{")
            .replace("`", "\\`")
            .replace("|", "\\|")
            .replace("~", "\\~")
            .replace("º", "\\º")
            .replace("№", "\\№")
            .replace("√", "\\√")
            .replace("\\s", "")
            .replace("\\f", "")
        )
        message = cfg.get("prefix", "") + message
        if len(name) > 14:
            name = name[:14].rstrip() + "."
        area.send_ic(
            folder=cfg.get("character", ""),
            anim=cfg.get("emote", ""),
            showname=name,
            msg=message,
            pos=cfg.get("pos", ""),
        )

    def refresh(self):
        """
        Refresh as many parts of the server as possible:
         - MOTD
         - Mod credentials (unmodding users if necessary)
         - Censors
         - Characters
         - Music
         - Backgrounds
         - Commands
         - Banlists
        """
        with open("config/config.yaml", "r", encoding='utf8') as cfg:
            cfg_yaml = yaml.safe_load(cfg)
            self.config["motd"] = cfg_yaml["motd"].replace("\\n", " \n")

            # Reload moderator passwords list and unmod any moderator affected by
            # credential changes or removals
            if isinstance(self.config["modpass"], str):
                self.config["modpass"] = {
                    "default": {"password": self.config["modpass"]}
                }
            if isinstance(cfg_yaml["modpass"], str):
                cfg_yaml["modpass"] = {"default": {
                    "password": cfg_yaml["modpass"]}}

            for profile in self.config["modpass"]:
                if (
                    profile not in cfg_yaml["modpass"]
                    or self.config["modpass"][profile] != cfg_yaml["modpass"][profile]
                ):
                    for client in filter(
                        lambda c: c.mod_profile_name == profile,
                        self.client_manager.clients,
                    ):
                        client.is_mod = False
                        client.mod_profile_name = None
                        database.log_misc("unmod.modpass", client)
                        client.send_ooc(
                            "Your moderator credentials have been revoked.")
            self.config["modpass"] = cfg_yaml["modpass"]

        self.load_config()
        self.load_command_aliases()
        self.load_censors()
        self.load_iniswaps()
        self.load_characters()
        self.load_music()
        self.load_backgrounds()

        # TODO: Only do the refresh if the server link list has changed
        # Clear the list of user links so they can be reloaded after.
        for client in self.client_manager.clients:
            client.refresh_server_link_list()
        # Load the new server links
        self.load_server_links()

        self.load_ipranges()

        import server.logger
        
        server.logger.setup_logging(debug=self.config["debug"])
        
        import server.commands

        importlib.reload(server.commands)
        server.commands.reload()
        
    def lockdown(self, client):
        if not self.is_locked_down:
            self.is_locked_down = True
            database.log_misc("lockdown", client)
            client.server.send_all_cmd_pred("CT", client.server.config["hostname"], "Lockdown is now in effect", "1")
        
    def release_act(self, client):
        if self.is_locked_down:
            self.is_locked_down = False
            database.log_misc("release", client)
            client.server.send_all_cmd_pred("CT", client.server.config["hostname"], "Lockdown has been lifted", "1")

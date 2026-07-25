import asyncio
import types
import logging
from unittest.mock import MagicMock
from server.exceptions import ClientError

logger = logging.getLogger("player_client")

_HDID_COUNTER = 1000000

def _next_hdid():
    global _HDID_COUNTER
    _HDID_COUNTER += 1
    return str(_HDID_COUNTER)

def _fmt_seconds(secs):
    if not secs:
        return "0 мин"
    h = int(secs // 3600)
    m = int((secs % 3600) // 60)
    if h:
        return "{}ч {}мин".format(h, m)
    return "{} мин".format(m) if m else "<1 мин"


class PlayerTransport:
    """Bridge: AO wire bytes → JSON WebSocket events."""

    def __init__(self, ws, uvicorn_loop):
        self.ws = ws
        self.uvicorn_loop = uvicorn_loop

    def send_json(self, data):
        try:
            asyncio.run_coroutine_threadsafe(
                self.ws.send_json(data), self.uvicorn_loop
            )
        except Exception:
            pass

    def write(self, data):
        """Called from main AO thread with bytes (wire protocol) or dict."""
        if isinstance(data, dict):
            self.send_json(data)
            return
        if isinstance(data, bytes):
            self._parse_protocol(data)
        elif isinstance(data, str):
            self._parse_protocol(data.encode())

    def _parse_protocol(self, raw: bytes):
        """Parse AO wire bytes → JSON events."""
        text = raw.decode("utf-8", errors="replace")
        for part in text.split("#%"):
            part = part.strip()
            if not part:
                continue
            self._parse_command(part)

    def _parse_command(self, cmd_str: str):
        """Parse a single AO protocol command to JSON."""
        parts = cmd_str.split("#")
        if not parts:
            return
        cmd = parts[0]
        args = parts[1:]

        if cmd == "CT":
            name = args[0] if len(args) > 0 else ""
            msg = args[1] if len(args) > 1 else ""
            self.send_json({"type": "ooc", "name": name, "text": msg})

        elif cmd == "MS":
            text = args[4] if len(args) > 4 else ""
            showname = args[15] if len(args) > 15 else ""
            cid = args[8] if len(args) > 8 else ""
            self.send_json({"type": "ic", "name": showname or ("#" + cid), "text": text, "char_id": int(cid) if cid else -1})

        elif cmd == "SC":
            chars = [{"id": i, "name": n} for i, n in enumerate(args)]
            self.send_json({"type": "chars", "list": chars})

        elif cmd == "CharsCheck":
            avail = [{"id": i, "taken": v == "-1"} for i, v in enumerate(args)]
            self.send_json({"type": "chars_status", "list": avail})

        elif cmd == "PV":
            # PV#<target_id>#CID#<char_id>
            if len(args) > 2 and args[1] == "CID":
                cid = int(args[2]) if args[2] else -1
                self.send_json({"type": "char_select", "char_id": cid})

        elif cmd == "BN":
            bg = args[0] if len(args) > 0 else ""
            self.send_json({"type": "bg", "background": bg})

        elif cmd == "DONE":
            self.send_json({"type": "ready"})

        elif cmd == "HP":
            pass

        elif cmd == "CHECK":
            pass

        elif cmd == "FA":
            self.send_json({"type": "area_list", "list": args})

        elif cmd == "ARUP":
            if len(args) > 0:
                self.send_json({"type": "arup", "sub": args[0], "data": args[1:]})

        elif cmd == "MC":
            song = args[0] if len(args) > 0 else ""
            channel = int(args[4]) if len(args) > 4 else 0
            self.send_json({"type": "music", "song": song, "channel": channel})

        elif cmd == "ASS":
            self.send_json({"type": "asset_url", "url": args[0] if args else ""})

        elif cmd == "SI":
            self.send_json({"type": "sizes", "chars": int(args[0]) if args else 0})

        elif cmd == "ID":
            cid = args[0] if args else ""
            self.send_json({"type": "id", "client_id": cid})

        elif cmd == "TT":
            state = int(args[0]) if len(args) > 0 and args[0] in ("0", "1") else 0
            name = args[1] if len(args) > 1 else ""
            emote = args[2] if len(args) > 2 else ""
            self.send_json({"type": "typing", "state": state, "name": name, "emote": emote})

        elif cmd == "KK":
            self._kicked = True
            msg = args[0] if args else "Отключено"
            self.send_json({"type": "error", "msg": msg})

        elif cmd == "KB":
            self._kicked = True
            self.send_json({"type": "error", "msg": " ".join(args) if args else "Вы забанены"})


class PlayerClient:
    """Manages a real server Client with thread-safe dispatch."""

    def __init__(self, server, transport, username, cid=None):
        self.server = server
        self.transport = transport
        self.username = username
        self.client = None
        self._protocol = None
        self._cid = cid

    def setup_sync(self):
        """Synchronous setup — call from UVICORN thread only."""
        future = asyncio.run_coroutine_threadsafe(
            self._do_setup(), self.server.loop
        )
        self.client = future.result(timeout=15)
        logger.info("Player client %s ready (id=%s)", self.username, self.client.id)

    async def setup_async(self):
        """Async setup — call from main event loop thread."""
        self.client = await self._do_setup()
        logger.info("Player client %s ready (id=%s)", self.username, self.client.id)

    async def _do_setup(self):
        """Runs on the main event loop."""
        mock_transport = MagicMock()
        mock_transport.get_extra_info.return_value = ("127.0.0.1", 0)
        mock_transport.is_closing.return_value = False
        mock_transport.write = lambda d: None

        # Create the client (same as AOProtocol.connection_made does)
        c = self.server.new_client(mock_transport)

        # Intercept send_command to forward AO wire events to the browser
        original_send = c.send_command

        def intercepted_send(_self, command, *args):
            cid = self._cid
            if command == "SC":
                chars = [{"id": i, "name": n} for i, n in enumerate(args)]
                self.transport.send_json({"type": "chars", "list": chars, "client_id": cid})
                return
            if command == "PV" and len(args) > 2 and args[1] == "CID":
                pv_cid = int(args[2]) if args[2] else -1
                self.transport.send_json({"type": "char_select", "char_id": pv_cid, "client_id": cid})
                return
            parts = [command] + [str(a) for a in args]
            wire = "#".join(parts) + "#%"
            self.transport.write(wire.encode())
            return original_send(command, *args)

        c.send_command = types.MethodType(intercepted_send, c)

        # Create an AOProtocol and attach our client
        from server.network.aoprotocol import AOProtocol
        proto = AOProtocol(self.server)
        proto.client = c
        self._protocol = proto

        # Simulate the AO handshake by feeding protocol messages
        hdid = _next_hdid()
        try:
            proto.data_received(f"HI#{hdid}#%".encode())
            proto.data_received("ID#5#webpanel#1.0#%".encode())
            proto.data_received("askchaa#%".encode())
            proto.data_received("RC#%".encode())
            proto.data_received("RD#%".encode())
        except ClientError:
            logger.warning("Player client handshake rejected")
            raise
        except Exception as e:
            logger.warning("Player client handshake error: %s", e)

        return c

    def teardown(self):
        """Synchronous teardown (call from UVICORN thread)."""
        if not self.client:
            return
        try:
            future = asyncio.run_coroutine_threadsafe(
                self._do_teardown(), self.server.loop
            )
            future.result(timeout=10)
        except Exception as e:
            logger.warning("Teardown error: %s", e)
        self.client = None
        self._protocol = None

    async def teardown_async(self):
        """Async teardown (call from main event loop)."""
        if not self.client:
            return
        await self._do_teardown()
        self.client = None
        self._protocol = None

    async def _do_teardown(self):
        """Runs on the main event loop."""
        c = self.client
        if not c:
            return
        if c.area:
            c.area.remove_client(c)
        # Remove from server
        cm = self.server.client_manager
        if c in cm.clients:
            cm.clients.discard(c)
            cm.cur_id.append(c.id)
        c.server = None

    def handle_command(self, data):
        """Dispatch a JSON command from the browser to the main event loop."""
        asyncio.run_coroutine_threadsafe(
            self._execute(data), self.server.loop
        )

    def get_iniswap(self):
        return getattr(self, '_iniswap_name', '')

    def set_iniswap(self, name):
        self._iniswap_name = name

    def get_oocname(self):
        return getattr(self, '_ooc_name', '')

    def set_oocname(self, name):
        self._ooc_name = name

    async def _execute(self, data):
        """Runs on the main event loop."""
        c = self.client
        if not c:
            return
        cmd = data.get("type", "")

        if cmd == "IC":
            text = data.get("text", "")
            emote = data.get("emote", "normal")
            folder = data.get("folder", "")
            pos = data.get("pos", "")
            iniswap = self.get_iniswap()
            if iniswap:
                folder = iniswap
            if not folder and c.char_id is not None and c.char_id >= 0:
                try:
                    folder = c.area.area_manager.char_list[c.char_id]
                except (IndexError, AttributeError):
                    pass
            if c.narrator:
                emote = ""
            if c.area:
                c.area.send_ic(client=c, msg=text, cid=c.char_id or -1, showname=c.showname or c.char_name or "", anim=emote, folder=folder or c.char_name or "", pos=pos)

        elif cmd == "OOC":
            text = data.get("text", "")
            ooc_name = data.get("ooc_name", "") or self.get_oocname() or self.username
            if text and self._protocol:
                self._protocol.net_cmd_ct([ooc_name, text])

        elif cmd == "OOC_NAME":
            self.set_oocname(data.get("name", ""))

        elif cmd == "IC_NAME":
            name = data.get("name", "")
            c.showname = name
            if not name:
                c.showname = c.char_name or ""

        elif cmd == "CHAR":
            char_id = data.get("char_id")
            if char_id is not None:
                c.change_character(char_id)

        elif cmd == "AREA":
            target = data.get("area_id")
            hub_id = data.get("hub_id")
            if target is not None:
                hubs = self.server.hub_manager.hubs
                for hub in hubs:
                    if hub_id is not None and hub.id != hub_id:
                        continue
                    for area in hub.areas:
                        if area.id == target:
                            c.change_area(area)
                            return

        elif cmd == "GET_AREA_LIST":
            hubs = self.server.hub_manager.hubs
            hub_data = []
            for hub in hubs:
                areas = []
                for area in hub.areas:
                    areas.append({"id": area.id, "name": area.name, "hub_id": hub.id})
                hub_data.append({"id": hub.id, "name": hub.name, "areas": areas})
            self.transport.send_json({"type": "area_list_full", "hubs": hub_data})

        elif cmd == "GET_CHAR_LIST":
            chars = c.area.area_manager.char_list if c.area else []
            char_list = [{"id": i, "name": name} for i, name in enumerate(chars)]
            self.transport.send_json({"type": "chars", "list": char_list})

        elif cmd == "INISWAP":
            name = data.get("name", "")
            self.set_iniswap(name)


class PlayerManager:
    """Manages multiple PlayerClient instances for one WebSocket connection."""

    def __init__(self, server, transport, username):
        self.server = server
        self.transport = transport
        self.username = username
        self.clients = {}       # client_id -> PlayerClient
        self._active_id = None
        self._next_id = 0

    def setup(self):
        """Create the first client (called from UVICORN thread)."""
        cid = self._next_id
        self._next_id += 1
        name = f"{self.username}#{cid}"
        pc = PlayerClient(self.server, self.transport, name, cid=cid)
        pc.setup_sync()
        self.clients[cid] = pc
        self._active_id = cid
        pc.set_iniswap("")
        self.transport.send_json({
            "type": "client_added", "client_id": cid, "name": f"Клиент {cid}"
        })

    def teardown(self):
        """Remove all clients."""
        for cid, pc in list(self.clients.items()):
            try:
                pc.teardown()
            except Exception as e:
                logger.warning("Teardown client %s: %s", cid, e)
        self.clients.clear()

    async def _connect_client(self):
        """Create a new client (called from main event loop)."""
        cid = self._next_id
        self._next_id += 1
        name = f"{self.username}#{cid}"
        pc = PlayerClient(self.server, self.transport, name, cid=cid)
        await pc.setup_async()
        self.clients[cid] = pc
        self._active_id = cid
        pc.set_iniswap("")
        self.transport.send_json({
            "type": "client_added", "client_id": cid, "name": f"Клиент {cid}"
        })
        return cid

    async def _disconnect_client(self, cid):
        pc = self.clients.pop(cid, None)
        if pc:
            try:
                await pc.teardown_async()
            except Exception as e:
                logger.warning("Teardown client %s: %s", cid, e)
        if self._active_id == cid:
            self._active_id = next(iter(self.clients)) if self.clients else None
        self.transport.send_json({
            "type": "client_removed", "client_id": cid
        })

    def handle_command(self, data):
        """Dispatch to the main loop."""
        asyncio.run_coroutine_threadsafe(
            self._execute(data), self.server.loop
        )

    def _get_client_config(self, cid):
        """Return (PlayerClient, config_dict) for the given client_id."""
        if cid is None or cid not in self.clients:
            return None
        return self.clients[cid]

    async def _execute(self, data):
        """Runs on the main event loop."""
        cmd = data.get("type", "")

        if cmd == "CONNECT":
            await self._connect_client()
            return

        if cmd == "DISCONNECT":
            cid = data.get("client_id")
            if cid is not None:
                await self._disconnect_client(cid)
            return

        if cmd == "SET_ACTIVE":
            cid = data.get("client_id")
            if cid is not None and cid in self.clients:
                self._active_id = cid
                self.transport.send_json({
                    "type": "active_changed", "client_id": cid
                })
            return

        if cmd == "GET_CLIENTS":
            info = []
            for cid, pc in self.clients.items():
                area = pc.client.area if pc.client else None
                info.append({
                    "client_id": cid,
                    "active": cid == self._active_id,
                    "char_id": pc.client.char_id if pc.client else -1,
                    "iniswap": pc.get_iniswap() if pc.client else "",
                    "oocname": pc.get_oocname() if pc.client else "",
                    "icname": pc.client.showname if pc.client else "",
                    "hub_id": area.hub.id if area and area.hub else None,
                    "area_id": area.id if area else None,
                })
            self.transport.send_json({"type": "client_list", "clients": info, "active_id": self._active_id})
            return

        if cmd == "GET_AREA_LIST":
            hubs = self.server.hub_manager.hubs
            hub_data = []
            for hub in hubs:
                areas = []
                for area in hub.areas:
                    areas.append({"id": area.id, "name": area.name, "hub_id": hub.id})
                hub_data.append({"id": hub.id, "name": hub.name, "areas": areas})
            self.transport.send_json({"type": "area_list_full", "hubs": hub_data})
            return

        # Route by client_id to the specific PlayerClient
        cid = data.get("client_id", self._active_id)
        pc = self.clients.get(cid)
        if not pc:
            return

        if cmd == "TYPING":
            state = data.get("state", 0)
            emote = data.get("emote", "normal")
            c = pc.client
            if c and state in (0, 1):
                name = pc.get_iniswap() or c.char_name or ""
                for other in c.area.clients:
                    if other.id != c.id:
                        other.send_command("TT", str(state), name, emote)

        if cmd == "IC":
            text = data.get("text", "")
            emote = data.get("emote", "normal")
            folder = data.get("folder", "")
            pos = data.get("pos", "")
            iniswap = pc.get_iniswap()
            if iniswap:
                folder = iniswap
            c = pc.client
            if not folder and c and c.char_id is not None and c.char_id >= 0:
                try:
                    folder = c.area.area_manager.char_list[c.char_id]
                except (IndexError, AttributeError):
                    pass
            if c and c.narrator:
                emote = ""
            # /p command in IC — queue preset
            if text.lstrip().startswith("/p") and not text.lstrip().startswith("/p_"):
                if not (c and (c.is_mod or c in c.area.owners or c in c.area.area_manager.owners)):
                    c.send_ooc("[Queue] /p — только для модеров, CM и GM.") if c else None
                    return
                qm = c.server.queue_manager
                if not qm:
                    c.send_ooc("[Queue] Очередь не инициализирована.")
                    return
                arg = text.lstrip()
                if arg == "/p":
                    c.send_ooc("[Queue] Укажи текст сообщения. /p [сек] текст")
                    return
                arg = arg[3:].strip()
                delay = 0
                msg = arg
                parts = arg.split(None, 1)
                if parts and parts[0].isdigit():
                    delay = int(parts[0])
                    msg = parts[1] if len(parts) > 1 else ""
                if not msg:
                    c.send_ooc("[Queue] Укажи текст сообщения. /p [сек] текст")
                    return
                # Сохраняем текущий folder (iniswap), emote, pos
                qm.add(c, msg, delay, folder=folder, anim=emote, pos=pos)
                if delay > 0:
                    c.send_ooc(f"[Queue] Добавлено (отпр. через {delay}c): {msg[:50]}")
                else:
                    c.send_ooc(f"[Queue] Добавлено в очередь: {msg[:50]}")
                return
            if c and c.area:
                c.area.send_ic(client=c, msg=text, cid=c.char_id or -1, showname=c.showname or c.char_name or "", anim=emote, folder=folder or c.char_name or "", pos=pos)

        elif cmd == "OOC":
            text = data.get("text", "")
            ooc_name = data.get("ooc_name", "") or pc.get_oocname() or pc.username
            if text and pc and pc._protocol:
                pc._protocol.net_cmd_ct([ooc_name, text])

        elif cmd == "OOC_NAME":
            pc.set_oocname(data.get("name", ""))

        elif cmd == "IC_NAME":
            name = data.get("name", "")
            c = pc.client
            if c:
                c.showname = name
                if not name:
                    c.showname = c.char_name or ""

        elif cmd == "CHAR":
            char_id = data.get("char_id")
            c = pc.client
            if char_id is not None and c:
                c.change_character(char_id)

        elif cmd == "AREA":
            target = data.get("area_id")
            hub_id = data.get("hub_id")
            c = pc.client
            if target is not None and c:
                hubs = self.server.hub_manager.hubs
                for hub in hubs:
                    if hub_id is not None and hub.id != hub_id:
                        continue
                    for area in hub.areas:
                        if area.id == target:
                            c.change_area(area)
                            return

        elif cmd == "INISWAP":
            name = data.get("name", "")
            pc.set_iniswap(name)
            self.transport.send_json({"type": "iniswap_set", "client_id": cid, "name": name})

        elif cmd == "GET_CHAR_SUBFOLDERS":
            folder = data.get("name", "")
            if not folder:
                self.transport.send_json({"type": "char_subfolders", "list": [], "name": "", "emote_map": {}, "emote_names": []})
                return
            import os
            char_path = os.path.join("characters", folder)
            subdirs = []
            emote_map = {}
            emote_names = []
            if os.path.isdir(char_path):
                # root emotes
                root_emotes = []
                for fname in sorted(os.listdir(char_path)):
                    fp = os.path.join(char_path, fname)
                    if os.path.isfile(fp):
                        name_no_ext = os.path.splitext(fname)[0]
                        if name_no_ext.isdigit():
                            root_emotes.append(int(name_no_ext))
                emote_map[""] = root_emotes
                # subfolder emotes
                for entry in sorted(os.listdir(char_path)):
                    sub_path = os.path.join(char_path, entry)
                    if os.path.isdir(sub_path):
                        subdirs.append(entry)
                        sub_emotes = []
                        for fname in sorted(os.listdir(sub_path)):
                            fp = os.path.join(sub_path, fname)
                            if os.path.isfile(fp):
                                name_no_ext = os.path.splitext(fname)[0]
                                if name_no_ext.isdigit():
                                    sub_emotes.append(int(name_no_ext))
                        emote_map[entry] = sub_emotes
                # read char.ini for emote name → index mapping
                ini_path = os.path.join(char_path, "char.ini")
                if os.path.isfile(ini_path):
                    import configparser
                    cp = configparser.ConfigParser()
                    try:
                        cp.read(ini_path, encoding="utf-8")
                    except Exception:
                        try:
                            cp.read(ini_path, encoding="cp1251")
                        except Exception:
                            cp = None
                    if cp and cp.has_section("Emotions"):
                        for k, v in cp.items("Emotions"):
                            try:
                                idx = int(v)
                                emote_names.append({"name": k, "index": idx})
                            except (ValueError, TypeError):
                                pass
                    if cp and cp.has_section("Options"):
                        pass
                # sort by index
                emote_names.sort(key=lambda x: x["index"])
            self.transport.send_json({"type": "char_subfolders", "list": subdirs, "name": folder, "emote_map": emote_map, "emote_names": emote_names})

        elif cmd == "GET_SFX_LIST":
            import os
            sfx_dir = "storage/sfx"
            files = []
            if os.path.isdir(sfx_dir):
                files = [f for f in os.listdir(sfx_dir) if os.path.isfile(os.path.join(sfx_dir, f))]
            self.transport.send_json({"type": "sfx_list", "files": files})

        elif cmd == "GET_CHAR_LIST":
            c = pc.client
            if c and c.area:
                chars = c.area.area_manager.char_list
            else:
                chars = []
            char_list = [{"id": i, "name": name} for i, name in enumerate(chars)]
            self.transport.send_json({"type": "chars", "list": char_list, "client_id": cid})

        elif cmd == "GET_STATS":
            c = pc.client
            if not c:
                return
            import time
            from datetime import datetime as dt
            ipid = c.ipid
            with self.server.database.db as conn:
                stats = conn.execute("SELECT * FROM player_stats WHERE ipid=?", (ipid,)).fetchone()
            info = dict(stats) if stats else {}
            # calc online time for current session
            if info.get("last_connect"):
                lc = info["last_connect"]
                if isinstance(lc, str):
                    for fmt in ("%Y-%m-%d %H:%M:%S.%f", "%Y-%m-%d %H:%M:%S"):
                        try:
                            lc = dt.strptime(lc, fmt)
                            break
                        except ValueError:
                            continue
                    else:
                        lc = None
                if lc:
                    elapsed = int((dt.utcnow() - lc).total_seconds())
                    if elapsed > 0:
                        info["playtime_seconds"] = (info.get("playtime_seconds") or 0) + elapsed
            msg = "[Stats] Сообщений IC: {} | OOC: {} | Смен перса: {} | Заходов: {} | Время: {} | Комнат: {}".format(
                info.get("ic_messages", 0),
                info.get("ooc_messages", 0),
                info.get("char_switches", 0),
                info.get("logins", 0),
                _fmt_seconds(info.get("playtime_seconds", 0)),
                info.get("areas_visited", "[]").count(",") + 1 if info.get("areas_visited", "[]") != "[]" else 0,
            )
            pc._protocol.net_cmd_ct(["[Система]", msg])

        elif cmd == "GET_SESSION_LOGS":
            c = pc.client
            if not c:
                return
            ipid = c.ipid
            with self.server.database.db as conn:
                rows = conn.execute(
                    """SELECT a.event_time, t.type_name, a.area_name, a.char_name, a.ooc_name, a.message
                       FROM area_events a JOIN area_event_types t ON a.event_subtype = t.type_id
                       WHERE a.ipid = ? AND t.type_name IN ('chat.ic','chat.ooc')
                       ORDER BY a.event_time DESC LIMIT 200""",
                    (ipid,),
                ).fetchall()
            msgs = []
            for r in reversed(rows):
                msgs.append({
                    "time": str(r["event_time"]), "type": r["type_name"],
                    "area": r["area_name"], "char_name": r["char_name"],
                    "ooc_name": r["ooc_name"], "message": r["message"],
                })
            self.transport.send_json({"type": "session_logs", "messages": msgs})



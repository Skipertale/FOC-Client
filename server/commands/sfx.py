import os
import urllib.parse
import urllib.request
import logging

from server import database
from server.exceptions import ArgumentError

from . import mod_only

logger = logging.getLogger(__name__)

SFX_DIR = "storage/sfx"

def ooc_cmd_sfx(client, arg):
    """
    Set a sound effect to play on the next IC message.
    Usage: /sfx <url|name> [0|1]
    0 = loop until manually disabled
    1 = play once (default)
    """
    parts = arg.strip().split(maxsplit=1)
    if not parts:
        client.send_ooc("Usage: /sfx <url|name> [0|1]")
        return

    sfx_val = parts[0]
    mode = 1
    if len(parts) > 1:
        try:
            mode = int(parts[1])
            if mode not in (0, 1):
                raise ValueError
        except ValueError:
            client.send_ooc("Mode must be 0 (loop) or 1 (once).")
            return

    if sfx_val.startswith(("http://", "https://")):
        sfx_value = sfx_val
    else:
        found = _find_sfx_file(sfx_val)
        if found:
            local_path = "sfx/" + os.path.relpath(found, SFX_DIR).replace("\\", "/")
            sfx_value = _get_sfx_url(client.server, local_path) or local_path
        else:
            sfx_value = sfx_val

    client.sfx_url = sfx_value
    client.sfx_looping = "0" if mode == 1 else "1"

    mode_text = "на постоянной основе" if mode == 0 else "однократно"
    client.send_ooc(f"SFX установлен: {sfx_value} ({mode_text}). Отправьте IC-сообщение чтобы проиграть его.")


@mod_only()
def ooc_cmd_sfx_save(client, arg):
    """
    Download an SFX from a URL and save it to storage/sfx/ with a given name.
    Usage: /sfx_save <name> <url>
    """
    parts = arg.strip().split(None, 1)
    if len(parts) < 2:
        raise ArgumentError("Usage: /sfx_save <name> <url>")

    name = parts[0].strip()
    url = parts[1].strip()

    if not url.startswith(("http://", "https://")):
        raise ArgumentError("URL must start with http:// or https://")

    os.makedirs(SFX_DIR, exist_ok=True)

    ext = _guess_ext(url) or ".mp3"
    filename = name.replace("\\", "/") + ext
    dest = os.path.join(SFX_DIR, filename.replace("/", os.sep))
    os.makedirs(os.path.dirname(dest), exist_ok=True)

    try:
        client.send_ooc(f"Скачиваю {url}...")
        _download_url(url, dest)
    except Exception as exc:
        raise ArgumentError(f"Не удалось скачать: {exc}")

    database.log_misc("sfx.save", client, data={"file": filename, "url": url})
    client.send_ooc(f"SFX сохранён как {filename}")


def ooc_cmd_sfx_list(client, arg):
    """List all saved SFX files in storage/sfx/ recursively."""
    if not os.path.isdir(SFX_DIR):
        client.send_ooc("Нет сохранённых SFX.")
        return

    files = []
    for root, _dirs, fnames in os.walk(SFX_DIR):
        for f in fnames:
            rel = os.path.relpath(os.path.join(root, f), SFX_DIR).replace("\\", "/")
            files.append(rel)
    files.sort()
    if not files:
        client.send_ooc("Нет сохранённых SFX.")
        return

    msg = "Сохранённые SFX:\n" + "\n".join(f"  {f}" for f in files)
    client.send_ooc(msg)


def ooc_cmd_sfx_clear(client, arg):
    """Clear the personal SFX preset."""
    client.sfx_url = ""
    client.sfx_looping = "0"
    client.send_ooc("SFX сброшен.")


def _find_sfx_file(name):
    """Look for a file in SFX_DIR matching the given name (with any extension), searching subdirectories."""
    if not os.path.isdir(SFX_DIR):
        return None
    name_lower = name.lower().replace("\\", "/")
    for root, _dirs, files in os.walk(SFX_DIR):
        for f in files:
            f_no_ext = os.path.splitext(f)[0]
            rel = os.path.relpath(os.path.join(root, f_no_ext), SFX_DIR).replace("\\", "/")
            if rel.lower() == name_lower:
                return os.path.join(root, f)
    # fallback: try as-is
    full = os.path.join(SFX_DIR, name)
    if os.path.isfile(full):
        return full
    return None


def _guess_ext(url):
    """Try to guess file extension from a URL."""
    path = urllib.parse.urlparse(url).path
    _, ext = os.path.splitext(path)
    if ext:
        return ext
    return None


def _download_url(url, dest):
    """Download a URL to a local file with browser-like headers to avoid 403."""
    req = urllib.request.Request(url, headers={
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
        "Accept": "*/*",
        "Referer": "https://discord.com/",
    })
    with urllib.request.urlopen(req, timeout=30) as src:
        with open(dest, "wb") as f:
            f.write(src.read())


def _get_sfx_url(server, path):
    """Convert a relative SFX path to a full URL via the SFX HTTP server."""
    url = getattr(server, "_sfx_http_url", "")
    if url:
        return url + path
    asset_url = server.config.get("asset_url", "")
    if asset_url:
        return asset_url.rstrip("/") + "/" + path
    return None

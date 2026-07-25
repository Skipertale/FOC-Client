import random
import string
import socket
from .. import database
from . import mod_only

__all__ = ["ooc_cmd_player_code", "ooc_cmd_my_code"]


def _generate_code():
    return "PC-" + "".join(random.choices(string.ascii_uppercase + string.digits, k=8))


@mod_only()
def ooc_cmd_player_code(client, arg):
    """Generate a personal access code by IPID (hardware ID). Usage: /player_code <ipid>"""
    parts = arg.strip().split()
    if len(parts) < 1 or not parts[0]:
        client.send_ooc("Использование: /player_code <IPID игрока>")
        client.send_ooc("IPID можно узнать через /get_area")
        return
    ipid_str = parts[0].strip()
    if len(ipid_str) < 4:
        client.send_ooc("Слишком короткий IPID.")
        return
    # Find player by IPID (ip-based ID visible in player list)
    target = None
    for c in client.server.client_manager.clients:
        if str(getattr(c, 'ipid', '')) == ipid_str:
            target = c
            break
    if not target:
        client.send_ooc(f"Игрок с IPID {ipid_str} не найден онлайн.")
        return
    hdid = target.hdid
    if not hdid:
        client.send_ooc("У игрока нет HDID.")
        return
    existing = database.get_player_code(hdid)
    if existing:
        client.send_ooc(f"У игрока {target.char_name or 'игрока'} уже есть код: {existing}")
        return
    code = _generate_code()
    database.set_player_code(hdid, code, client.char_name or "mod")
    client.send_ooc(f"Код доступа для {target.char_name or 'игрока'} (IPID {ipid_str}): {code}")
    target.send_ooc(f"Вам назначен персональный код доступа к панели игрока: {code}")


def ooc_cmd_my_code(client, arg):
    """Show your personal access code for the webpanel player mode."""
    hdid = client.hdid
    if not hdid:
        client.send_ooc("У вас нет HDID.")
        return
    code = database.get_player_code(hdid)
    if code:
        host = socket.gethostbyname(socket.gethostname())
        wp = client.server.config.get("webpanel", {})
        port = wp.get("port", 8081)
        client.send_ooc(f"Ваш код: {code}")
        client.send_ooc(f"Ссылка: http://{host}:{port}/player")
    else:
        client.send_ooc("У вас нет кода доступа. Попросите модератора выдать командой /player_code <ваш IPID>")

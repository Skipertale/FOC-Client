import json

from server import achievements as ach
from server import database
from server.exceptions import ClientError, ArgumentError

from . import mod_only

__all__ = [
    "ooc_cmd_achievements",
    "ooc_cmd_achievement_list",
    "ooc_cmd_achievement_grant",
    "ooc_cmd_stats",
]


def ooc_cmd_achievements(client, arg):
    """Показать свои достижения и прогресс."""
    unlocked = ach.get_player_achievements(client.ipid)
    count = sum(1 for a in unlocked if a["unlocked"])
    total = len(unlocked)
    client.send_ooc(f"Достижения: {count}/{total}")
    stats = ach.get_player_stats(client.ipid)
    areas = len(json.loads(stats["areas_visited"]))
    playtime = stats['playtime_seconds']
    h = playtime // 3600
    m = (playtime % 3600) // 60
    s = playtime % 60
    client.send_ooc(
        f"Статистика: IC: {stats['ic_messages']} | OOC: {stats['ooc_messages']} | "
        f"Входов: {stats['logins']} | Комнат: {areas} | "
        f"Смен персонажа: {stats['char_switches']} | Время: {h}ч {m}м {s}с"
    )
    for a in unlocked:
        status = "[V]" if a["unlocked"] else "[ ]"
        client.send_ooc(f"{status} {a['name']}: {a['description']}")


def ooc_cmd_achievement_list(client, arg):
    """Показать все доступные достижения."""
    unlocked_ids = {r["achievement_id"] for r in ach.get_unlocked(client.ipid)}
    defs = ach._all_defs()
    for aid in sorted(defs.keys()):
        adef = defs[aid]
        status = "[V]" if aid in unlocked_ids else "[ ]"
        client.send_ooc(f"{status} {adef['name']} — {adef['description']}")


@mod_only()
def ooc_cmd_achievement_grant(client, arg):
    """Выдать достижение игроку. Использование: /achievement_grant <ipid> <achievement_id>"""
    parts = arg.split()
    if len(parts) != 2:
        raise ArgumentError("Использование: /achievement_grant <ipid> <achievement_id>")
    target_ipid, achievement_id = parts[0], parts[1]
    try:
        target_ipid = int(target_ipid)
    except ValueError:
        raise ArgumentError("IPID должен быть числом")
    adef = ach._get_achievement(achievement_id)
    if adef is None:
        raise ClientError(f"Достижение '{achievement_id}' не найдено")
    if ach._is_unlocked(target_ipid, achievement_id):
        raise ClientError("У этого игрока уже есть данное достижение")
    with database.db as conn:
        conn.execute(
            "INSERT INTO player_achievements(achievement_id, ipid) VALUES (?, ?)",
            (achievement_id, target_ipid),
        )
    client.send_ooc(f"Выдано достижение '{adef['name']}' IPID {target_ipid}")


def ooc_cmd_stats(client, arg):
    """Показать свою статистику. Использование: /stats"""
    stats = ach.get_player_stats(client.ipid)
    areas = len(json.loads(stats["areas_visited"]))
    playtime = stats['playtime_seconds']
    if stats.get("last_connect"):
        import datetime
        lc = stats["last_connect"]
        if isinstance(lc, str):
            try:
                lc = datetime.datetime.strptime(lc, "%Y-%m-%d %H:%M:%S")
            except ValueError:
                lc = None
        if lc:
            elapsed = int((datetime.datetime.utcnow() - lc).total_seconds())
            if elapsed > 0:
                playtime += elapsed
    h = playtime // 3600
    m = (playtime % 3600) // 60
    s = playtime % 60
    client.send_ooc(
        f"Статистика: IC: {stats['ic_messages']} | OOC: {stats['ooc_messages']} | "
        f"Входов: {stats['logins']} | Комнат: {areas} | "
        f"Смен персонажа: {stats['char_switches']} | Время: {h}ч {m}м {s}с"
    )

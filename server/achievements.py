import json
import logging
import sqlite3
from textwrap import dedent

from server import database

logger = logging.getLogger("achievements")

ACHIEVEMENT_CACHE = {}
STATS_CACHE = {}


def _migrate_on_hdid(server, client):
    """If client has an HDID previously seen with a different IPID,
    merge stats/achievements from old IPID(s) into the current one."""
    hdid = client.hdid
    if not hdid:
        return
    new_ipid = client.ipid
    with database.db as conn:
        old_ipids = [
            r["ipid"] for r in conn.execute(
                "SELECT DISTINCT ipid FROM hdids WHERE hdid=? AND ipid!=?",
                (hdid, new_ipid),
            ).fetchall()
        ]
        if not old_ipids:
            return
        for old_ipid in old_ipids:
            old_stats = conn.execute(
                "SELECT * FROM player_stats WHERE ipid=?", (old_ipid,)
            ).fetchone()
            if old_stats is None:
                continue
            new_stats = conn.execute(
                "SELECT * FROM player_stats WHERE ipid=?", (new_ipid,)
            ).fetchone()
            if new_stats is None:
                conn.execute(
                    "UPDATE player_stats SET ipid=? WHERE ipid=?", (new_ipid, old_ipid)
                )
            else:
                for col in ("ic_messages","ooc_messages","playtime_seconds","logins","char_switches","modcalls","kicks"):
                    conn.execute(
                        "UPDATE player_stats SET {} = MAX({}, ?) WHERE ipid=?".format(col, col),
                        (old_stats[col], new_ipid),
                    )
                old_areas = json.loads(old_stats["areas_visited"] or "[]")
                new_areas = json.loads(new_stats["areas_visited"] or "[]")
                merged = list(set(old_areas + new_areas))
                conn.execute(
                    "UPDATE player_stats SET areas_visited=? WHERE ipid=?",
                    (json.dumps(merged), new_ipid),
                )
                if old_stats["last_connect"] and (
                    not new_stats["last_connect"] or old_stats["last_connect"] > new_stats["last_connect"]
                ):
                    conn.execute(
                        "UPDATE player_stats SET last_connect=? WHERE ipid=?",
                        (old_stats["last_connect"], new_ipid),
                    )
            conn.execute(
                """INSERT OR IGNORE INTO player_achievements(achievement_id, ipid, unlocked_at)
                   SELECT achievement_id, ?, unlocked_at FROM player_achievements WHERE ipid=?""",
                (new_ipid, old_ipid),
            )
            conn.execute("DELETE FROM player_stats WHERE ipid=? AND "
                         "ic_messages=0 AND ooc_messages=0 AND playtime_seconds=0 AND logins=0"
                         " AND char_switches=0 AND modcalls=0 AND kicks=0",
                         (old_ipid,))
        conn.execute("UPDATE hdids SET ipid=? WHERE hdid=?", (new_ipid, hdid))
        conn.execute("DELETE FROM hdids WHERE hdid=? AND ipid!=?", (hdid, new_ipid))
    for oid in old_ipids:
        STATS_CACHE.pop(oid, None)


def _ensure_stats(ipid):
    if ipid in STATS_CACHE:
        return STATS_CACHE[ipid]
    with database.db as conn:
        row = conn.execute(
            "SELECT * FROM player_stats WHERE ipid = ?", (ipid,)
        ).fetchone()
        if row is None:
            conn.execute(
                "INSERT OR IGNORE INTO player_stats(ipid) VALUES (?)", (ipid,)
            )
            row = {
                "ipid": ipid,
                "ic_messages": 0,
                "ooc_messages": 0,
                "playtime_seconds": 0,
                "logins": 0,
                "areas_visited": "[]",
                "char_switches": 0,
                "modcalls": 0,
                "kicks": 0,
                "last_connect": None,
            }
        else:
            row = dict(row)
    STATS_CACHE[ipid] = row
    return row


def _save_stats(ipid):
    stats = STATS_CACHE.get(ipid)
    if stats is None:
        return
    with database.db as conn:
        conn.execute(
            dedent("""
                UPDATE player_stats SET
                    ic_messages = ?,
                    ooc_messages = ?,
                    playtime_seconds = ?,
                    logins = ?,
                    areas_visited = ?,
                    char_switches = ?,
                    modcalls = ?,
                    kicks = ?,
                    last_connect = ?
                WHERE ipid = ?
            """),
            (
                stats["ic_messages"],
                stats["ooc_messages"],
                stats["playtime_seconds"],
                stats["logins"],
                stats["areas_visited"],
                stats["char_switches"],
                stats["modcalls"],
                stats["kicks"],
                stats["last_connect"],
                ipid,
            ),
        )


def _load_defs():
    if ACHIEVEMENT_CACHE:
        return
    with database.db as conn:
        rows = conn.execute("SELECT * FROM achievement_defs").fetchall()
        for row in rows:
            ACHIEVEMENT_CACHE[row["id"]] = dict(row)


def _get_achievement(achievement_id):
    _load_defs()
    return ACHIEVEMENT_CACHE.get(achievement_id)


def _all_defs():
    _load_defs()
    return dict(ACHIEVEMENT_CACHE)

def get_all_defs():
    return _all_defs()

def get_achievement(achievement_id):
    return _get_achievement(achievement_id)


def _is_unlocked(ipid, achievement_id):
    with database.db as conn:
        row = conn.execute(
            "SELECT 1 FROM player_achievements WHERE achievement_id = ? AND ipid = ?",
            (achievement_id, ipid),
        ).fetchone()
        return row is not None


def get_unlocked(ipid):
    with database.db as conn:
        rows = conn.execute(
            "SELECT achievement_id, unlocked_at FROM player_achievements WHERE ipid = ?",
            (ipid,),
        ).fetchall()
        return [dict(r) for r in rows]


def get_player_achievements(ipid):
    unlocked = {r["achievement_id"] for r in get_unlocked(ipid)}
    defs = _all_defs()
    result = []
    for aid, adef in defs.items():
        result.append({
            "id": aid,
            "name": adef["name"],
            "description": adef["description"],
            "icon": adef["icon"],
            "category": adef["category"],
            "unlocked": aid in unlocked,
        })
    return result


def get_player_stats(ipid):
    return _ensure_stats(ipid)


def _check_and_unlock(server, client, achievement_id):
    if _is_unlocked(client.ipid, achievement_id):
        return False
    adef = _get_achievement(achievement_id)
    if adef is None:
        return False
    with database.db as conn:
        conn.execute(
            "INSERT OR IGNORE INTO player_achievements(achievement_id, ipid) VALUES (?, ?)",
            (achievement_id, client.ipid),
        )
    logger.info(
        "Achievement unlocked: %s for IPID %s (%s)", achievement_id, client.ipid, client.name
    )
    msg = f"[Достижение] {client.name} открыл: {adef['name']} — {adef['description']}"
    try:
        if client.area:
            client.area.broadcast_ooc(msg)
    except Exception:
        pass
    try:
        client.send_ooc(f"[Достижение] Ты открыл: {adef['name']} — {adef['description']}")
    except Exception:
        pass
    return True


def check_ic_message(server, client):
    stats = _ensure_stats(client.ipid)
    stats["ic_messages"] += 1
    _save_stats(client.ipid)
    count = stats["ic_messages"]
    for aid, required in [("first_ic", 1), ("chatterbox", 100), ("loquacious", 1000), ("gift_of_gab", 10000)]:
        if count >= required:
            _check_and_unlock(server, client, aid)


def check_ooc_message(server, client):
    stats = _ensure_stats(client.ipid)
    stats["ooc_messages"] += 1
    _save_stats(client.ipid)
    count = stats["ooc_messages"]
    for aid, required in [("first_ooc", 1), ("socialite", 500)]:
        if count >= required:
            _check_and_unlock(server, client, aid)


def check_login(server, client):
    _migrate_on_hdid(server, client)
    stats = _ensure_stats(client.ipid)
    stats["logins"] += 1
    import datetime
    stats["last_connect"] = datetime.datetime.utcnow()
    _save_stats(client.ipid)
    count = stats["logins"]
    for aid, required in [("welcome_back", 10), ("regular", 50), ("veteran", 200)]:
        if count >= required:
            _check_and_unlock(server, client, aid)


def check_disconnect(server, client):
    stats = STATS_CACHE.get(client.ipid)
    if stats is None:
        stats = _ensure_stats(client.ipid)
    last_connect = stats.get("last_connect")
    if last_connect:
        import datetime
        now = datetime.datetime.utcnow()
        if isinstance(last_connect, str):
            try:
                last_connect = datetime.datetime.strptime(last_connect, "%Y-%m-%d %H:%M:%S")
            except ValueError:
                last_connect = None
        if last_connect:
            elapsed = int((now - last_connect).total_seconds())
            if elapsed > 0:
                stats["playtime_seconds"] += elapsed
                _save_stats(client.ipid)
                total = stats["playtime_seconds"]
                for aid, required in [("one_hour", 3600), ("day_player", 86400), ("week_player", 604800)]:
                    if total >= required:
                        _check_and_unlock(server, client, aid)


def check_area_change(server, client, area_id):
    stats = _ensure_stats(client.ipid)
    visited = json.loads(stats["areas_visited"])
    hub_id = client.area.area_manager.id if client.area else 0
    area_key = f"{hub_id}:{area_id}"
    if area_key not in visited:
        visited.append(area_key)
        stats["areas_visited"] = json.dumps(visited)
        _save_stats(client.ipid)
    all_areas = set()
    try:
        for hub in server.hub_manager.hubs:
            for area in hub.areas:
                all_areas.add(f"{hub.id}:{area.id}")
    except Exception:
        pass
    count = len(visited)
    for aid, required in [("wanderer", 5), ("explorer", 20)]:
        if count >= required:
            _check_and_unlock(server, client, aid)
    if all_areas and set(visited) >= all_areas:
        _check_and_unlock(server, client, "cartographer")


def check_char_switch(server, client):
    stats = _ensure_stats(client.ipid)
    stats["char_switches"] += 1
    _save_stats(client.ipid)
    count = stats["char_switches"]
    for aid, required in [("stylist", 5), ("fashionista", 50)]:
        if count >= required:
            _check_and_unlock(server, client, aid)


def check_modcall(server, client):
    stats = _ensure_stats(client.ipid)
    stats["modcalls"] += 1
    _save_stats(client.ipid)
    count = stats["modcalls"]
    if count >= 5:
        _check_and_unlock(server, client, "peacekeeper")


def check_kick(server, client):
    stats = _ensure_stats(client.ipid)
    stats["kicks"] += 1
    _save_stats(client.ipid)
    count = stats["kicks"]
    if count >= 10:
        _check_and_unlock(server, client, "enforcer")

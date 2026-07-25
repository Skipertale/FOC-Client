import time
import logging
from datetime import datetime, timedelta
from collections import defaultdict
from server import database as db

logger = logging.getLogger("automod")

_message_log = defaultdict(list)
_strikes = defaultdict(list)

_config_cache = {}
_config_cache_ts = 0
_CONFIG_CACHE_TTL = 30

def _configs(hub_name, area_name):
    global _config_cache, _config_cache_ts
    now = time.time()
    if now - _config_cache_ts > _CONFIG_CACHE_TTL:
        _config_cache = {}
        _config_cache_ts = now
    key = (hub_name or "", area_name or "")
    if key in _config_cache:
        return _config_cache[key]
    try:
        with db._database_singleton.db as conn:
            rows = conn.execute(
                """SELECT * FROM automod_configs
                   WHERE enabled=1 AND (hub_name='' OR hub_name=?)
                   AND (area_name='' OR area_name=?)""",
                (hub_name, area_name)
            ).fetchall()
        result = [dict(r) for r in rows]
        _config_cache[key] = result
        return result
    except Exception:
        return []

def check_all(client, text, hub_name, area_name):
    hdid = client.hdid
    now = time.time()
    log = _message_log[hdid]
    log[:] = [(t, txt) for t, txt in log if now - t < 60]

    for cfg in _configs(hub_name, area_name):
        if cfg["flood_msg_limit"] > 0 and cfg["flood_window_secs"] > 0:
            recent = [t for t, _ in log if now - t < cfg["flood_window_secs"]]
            if len(recent) >= cfg["flood_msg_limit"]:
                _action(client, "Флуд", cfg.get("action", "warn"))
                return

        if cfg["caps_percent"] > 0 and cfg["caps_min_len"] > 0 and len(text) >= cfg["caps_min_len"]:
            upper = sum(1 for c in text if c.isupper())
            if upper / len(text) * 100 > cfg["caps_percent"]:
                _action(client, "Капс", cfg.get("action", "warn"))
                return

        if cfg["repeat_count"] > 1 and cfg["repeat_window_secs"] > 0:
            same = [t for t, txt in log if now - t < cfg["repeat_window_secs"] and txt == text]
            if len(same) >= cfg["repeat_count"]:
                _action(client, "Повтор сообщения", cfg.get("action", "warn"))
                return

    log.append((now, text))

def warn(client, reason):
    hdid = client.hdid
    ipid = client.ipid
    now = time.time()
    s = _strikes[hdid]
    s[:] = [t for t in s if now - t < 86400]
    n = len(s) + 1
    s.append(now)
    try:
        with db._database_singleton.db as conn:
            conn.execute(
                "INSERT INTO player_warnings(ipid, warned_by, reason) VALUES (?, 'Automod', ?)",
                (ipid, reason)
            )
    except Exception:
        pass
    msg = f"[Автомод] Предупреждение №{n}: {reason}"
    client.send_ooc(msg)
    if n >= 3:
        _strikes[hdid] = []
        try:
            with db._database_singleton.db as conn:
                conn.execute("INSERT OR IGNORE INTO ipids(ipid, ip_address) VALUES (0, 'automod-system')")
                unban = (datetime.utcnow() + timedelta(hours=2)).strftime("%Y-%m-%d %H:%M:%S")
                cur = conn.execute(
                    "INSERT INTO bans(reason, banned_by, unban_date) VALUES (?, 0, ?)",
                    (f"[Automod] 3 предупреждения: {reason}", unban)
                )
                ban_id = cur.lastrowid
                conn.execute("INSERT OR REPLACE INTO ip_bans(ipid, ban_id) VALUES (?, ?)", (ipid, ban_id))
                conn.execute("DELETE FROM player_warnings WHERE ipid=?", (ipid,))
            db._schedule_unban(ban_id)
        except Exception as e:
            logger.error("ban after 3 warns failed: %s", e)
        msg2 = f"[Автомод] 3 предупреждения. Бан на 2 часа."
        client.send_ooc(msg2)
        client.send_command("KB", "3 предупреждения — бан 2ч")
        client.disconnect()

def _action(client, reason, action):
    hdid = client.hdid
    if action == "mute":
        msg = f"[Автомод] Вы заглушены: {reason}"
        client.send_ooc(msg)
        client.is_muted = True
        _schedule_unmute(client, 600)
        return
    warn(client, reason)

def _schedule_unmute(client, delay):
    import asyncio
    loop = asyncio.get_event_loop()
    if loop.is_running():
        loop.call_later(delay, lambda: setattr(client, "is_muted", False))

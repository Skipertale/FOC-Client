import re
import time
from server import database as db

_cache = {}
_cache_ts = 0
_CACHE_TTL = 30

def _get_filters_for(hub_name, area_name):
    global _cache, _cache_ts
    now = time.time()
    if now - _cache_ts > _CACHE_TTL:
        _cache = {}
        _cache_ts = now
    key = (hub_name or "", area_name or "")
    if key in _cache:
        return _cache[key]
    try:
        with db._database_singleton.db as conn:
            rows = conn.execute(
                """SELECT pattern, replacement, warn_on_match FROM word_filters
                   WHERE enabled=1 AND (hub_name='' OR hub_name=?)
                   AND (area_name='' OR area_name=?)""",
                (hub_name, area_name)
            ).fetchall()
        result = [dict(r) for r in rows]
        _cache[key] = result
        return result
    except Exception:
        return []

def apply(text, hub_name, area_name):
    filters = _get_filters_for(hub_name, area_name)
    if not filters:
        return text
    for f in filters:
        pattern, replacement = f["pattern"], f["replacement"]
        try:
            text = re.sub(pattern, replacement, text, flags=re.IGNORECASE)
        except re.error:
            text = re.sub(re.escape(pattern), replacement, text, flags=re.IGNORECASE)
    return text

def apply_check(text, hub_name, area_name):
    """Returns (filtered_text, should_warn)."""
    filters = _get_filters_for(hub_name, area_name)
    if not filters:
        return text, False
    should_warn = False
    for f in filters:
        pattern, replacement = f["pattern"], f["replacement"]
        try:
            new_text = re.sub(pattern, replacement, text, flags=re.IGNORECASE)
        except re.error:
            new_text = re.sub(re.escape(pattern), replacement, text, flags=re.IGNORECASE)
        if new_text != text:
            text = new_text
            if f.get("warn_on_match"):
                should_warn = True
    return text, should_warn

import json
import os
import threading
import time

WL_PATH = os.path.abspath("server/whitelist.json")

_whitelist_data = {
    "hdids": {},
    "ipids": {},
    "ips": {},
    "gm_access": {},
    "discord_invite": "https://discord.gg/n95zkcBE8h"
}
_lock = threading.Lock()

# Cooldown: не чаще одного уведомления в 5 минут на один IPID/HDID
_NOTIFY_COOLDOWN_SECS = 300
_notify_cooldowns = {}


def _normalize_entry(value, entry_type="hdid"):
    """Приводит запись к единому dict-формату."""
    if isinstance(value, dict):
        entry = {
            "name": value.get("name", value.get("nick", "")),
            "discord": value.get("discord", ""),
            "added_by": value.get("added_by", value.get("addedby", "")),
            "added_at": value.get("added_at", ""),
            "type": value.get("type", entry_type),
        }
        if not entry["added_at"]:
            entry["added_at"] = time.strftime("%Y-%m-%d %H:%M", time.gmtime())
        return entry
    return {
        "name": str(value) if value else "",
        "discord": "",
        "added_by": "migrated",
        "added_at": time.strftime("%Y-%m-%d %H:%M", time.gmtime()),
        "type": entry_type,
    }


def _migrate_from_txt():
    txt_path = "config/whitelist.txt"
    try:
        with open(txt_path, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or ":" not in line:
                    continue
                key, comment = line.split(":", 1)
                if not key.isdigit():
                    hdid = key.strip()
                    if hdid not in _whitelist_data["hdids"]:
                        _whitelist_data["hdids"][hdid] = _normalize_entry(comment.strip(), "hdid")
        if _whitelist_data["hdids"]:
            print(f"[Whitelist] Импортировано {len(_whitelist_data['hdids'])} HDID из whitelist.txt")
    except FileNotFoundError:
        pass
    except Exception as e:
        print(f"[Whitelist] Ошибка импорта из whitelist.txt: {e}")


def _load_whitelist():
    global _whitelist_data
    try:
        with open(WL_PATH, "r", encoding="utf-8") as f:
            data = json.load(f)

        hdids_raw = data.get("hdids", {})
        if isinstance(hdids_raw, list):
            parsed_hdids = {}
            for h in hdids_raw:
                parsed_hdids[h] = _normalize_entry("", "hdid")
        elif isinstance(hdids_raw, dict):
            parsed_hdids = {h: _normalize_entry(v, "hdid") for h, v in hdids_raw.items()}
        else:
            parsed_hdids = {}

        ipids_raw = data.get("ipids", {})
        if isinstance(ipids_raw, dict):
            parsed_ipids = {i: _normalize_entry(v, "ipid") for i, v in ipids_raw.items()}
        else:
            parsed_ipids = {}

        ips_raw = data.get("ips", {})
        if isinstance(ips_raw, dict):
            parsed_ips = {i: _normalize_entry(v, "ip") for i, v in ips_raw.items()}
        else:
            parsed_ips = {}

        gm_raw = data.get("gm_access", {})
        if isinstance(gm_raw, dict):
            parsed_gm = {k: dict(v) if isinstance(v, dict) else {} for k, v in gm_raw.items()}
        else:
            parsed_gm = {}

        with _lock:
            _whitelist_data["hdids"] = parsed_hdids
            _whitelist_data["ipids"] = parsed_ipids
            _whitelist_data["ips"] = parsed_ips
            _whitelist_data["gm_access"] = parsed_gm
            _whitelist_data["discord_invite"] = data.get("discord_invite", "https://discord.gg/n95zkcBE8h")
        print(f"[Whitelist] Загружено HDID: {len(parsed_hdids)}, IPID: {len(parsed_ipids)}, IP: {len(parsed_ips)}, GM: {len(parsed_gm)}")
    except FileNotFoundError:
        print("[Whitelist] Файл whitelist.json не найден, пробуем импорт из whitelist.txt...")
        _migrate_from_txt()
        if _whitelist_data["hdids"]:
            _save_whitelist()
        else:
            print("[Whitelist] Вайт-лист пуст, файл будет создан при первом добавлении.")
            _save_whitelist()
    except Exception as e:
        print(f"[Whitelist] Ошибка загрузки: {e}")


def _save_whitelist():
    global _whitelist_data
    try:
        with open(WL_PATH, "w", encoding="utf-8") as f:
            json.dump(_whitelist_data, f, ensure_ascii=False, indent=2)
    except Exception as e:
        print(f"[Whitelist] Ошибка сохранения в {WL_PATH}: {type(e).__name__}: {e}")


_load_whitelist()
print(f"[Whitelist] Файл: {WL_PATH}")
try:
    with open(WL_PATH, "a", encoding="utf-8") as f:
        pass
    print("[Whitelist] Файл доступен для записи.")
except Exception as e:
    print(f"[Whitelist] ОШИБКА: файл недоступен для записи: {type(e).__name__}: {e}")


def is_allowed(hdid="", ipid="", ip="") -> bool:
    """Проверка: пускаем, если HDID, IPID или IP есть в вайт-листе."""
    with _lock:
        if hdid and hdid in _whitelist_data.get("hdids", {}):
            return True
        if ipid and ipid in _whitelist_data.get("ipids", {}):
            return True
        if ip and ip in _whitelist_data.get("ips", {}):
            return True
    return False


def add_entry(identifier, name="", discord_tag="", added_by="", entry_type="hdid") -> bool:
    """Добавить запись (HDID, IPID или IP)."""
    with _lock:
        if entry_type == "hdid":
            target = _whitelist_data["hdids"]
        elif entry_type == "ipid":
            target = _whitelist_data["ipids"]
        elif entry_type == "ip":
            target = _whitelist_data["ips"]
        else:
            return False
        if identifier in target:
            return False
        target[identifier] = {
            "name": name,
            "discord": discord_tag,
            "added_by": added_by,
            "added_at": time.strftime("%Y-%m-%d %H:%M", time.gmtime()),
            "type": entry_type,
        }
        _save_whitelist()
    print(f"[Whitelist] Добавлен {entry_type}: {identifier}")
    return True


def add_hdid(hdid, name="", discord_tag="", added_by="") -> bool:
    """Alias для add_entry с type=hdid (обратная совместимость)."""
    return add_entry(hdid, name, discord_tag, added_by, "hdid")


def update_entry(identifier, name="", discord_tag="") -> bool:
    """Обновить name/discord у записи (ищет в hdids, ipids, ips)."""
    with _lock:
        target = None
        if identifier in _whitelist_data.get("hdids", {}):
            target = _whitelist_data["hdids"]
        elif identifier in _whitelist_data.get("ipids", {}):
            target = _whitelist_data["ipids"]
        elif identifier in _whitelist_data.get("ips", {}):
            target = _whitelist_data["ips"]
        else:
            return False
        entry = target[identifier]
        if name:
            entry["name"] = name
        if discord_tag:
            entry["discord"] = discord_tag
        _save_whitelist()
    print(f"[Whitelist] Обновлён: {identifier}")
    return True


def remove_entry(identifier) -> bool:
    """Удалить запись (ищет в hdids, ipids, ips)."""
    with _lock:
        if identifier in _whitelist_data.get("hdids", {}):
            del _whitelist_data["hdids"][identifier]
        elif identifier in _whitelist_data.get("ipids", {}):
            del _whitelist_data["ipids"][identifier]
        elif identifier in _whitelist_data.get("ips", {}):
            del _whitelist_data["ips"][identifier]
        else:
            return False
        _save_whitelist()
    print(f"[Whitelist] Удалён: {identifier}")
    return True


def get_all() -> dict:
    """Все записи (HDID + IPID + IP)."""
    with _lock:
        result = {}
        for k, v in _whitelist_data.get("hdids", {}).items():
            result[k] = dict(v)
        for k, v in _whitelist_data.get("ipids", {}).items():
            result[k] = dict(v)
        for k, v in _whitelist_data.get("ips", {}).items():
            result[k] = dict(v)
        return result


def get_invite() -> str:
    with _lock:
        return _whitelist_data.get("discord_invite", "https://discord.gg/n95zkcBE8h")


def reload_whitelist():
    _load_whitelist()
    print("[Whitelist] Перезагружено.")


def can_notify(key: str) -> bool:
    """Проверка кулдауна уведомлений: не чаще 1 раза в 5 мин на ключ (HDID/IPID)."""
    now = time.time()
    last = _notify_cooldowns.get(key, 0.0)
    if now - last < _NOTIFY_COOLDOWN_SECS:
        return False
    _notify_cooldowns[key] = now
    return True


def gm_is_allowed(hdid="", ipid="", ip="") -> bool:
    """Проверка: есть ли доступ к /gm (по HDID, IPID или IP)."""
    with _lock:
        gm = _whitelist_data.get("gm_access", {})
        if hdid and hdid in gm:
            return True
        if ipid and ipid in gm:
            return True
        if ip and ip in gm:
            return True
    return False


def gm_add(identifier, name="", discord_tag="", added_by="") -> bool:
    """Добавить идентификатор в GM-доступ."""
    with _lock:
        gm = _whitelist_data.setdefault("gm_access", {})
        if identifier in gm:
            return False
        gm[identifier] = {
            "name": name,
            "discord": discord_tag,
            "added_by": added_by,
            "added_at": time.strftime("%Y-%m-%d %H:%M", time.gmtime()),
        }
        _save_whitelist()
    print(f"[Whitelist] GM-доступ добавлен: {identifier}")
    return True


def gm_remove(identifier) -> bool:
    """Удалить идентификатор из GM-доступа."""
    with _lock:
        gm = _whitelist_data.setdefault("gm_access", {})
        if identifier not in gm:
            return False
        del gm[identifier]
        _save_whitelist()
    print(f"[Whitelist] GM-доступ удалён: {identifier}")
    return True


def gm_get_all() -> dict:
    """Все записи GM-доступа."""
    with _lock:
        gm = _whitelist_data.get("gm_access", {})
        return {k: dict(v) for k, v in gm.items()}

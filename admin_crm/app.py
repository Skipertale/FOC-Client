#!/usr/bin/env python3
import argparse
import csv
import hashlib
import html
import io
import os
import secrets
import re
import sqlite3
import sys
import threading
import time
from collections import defaultdict
from datetime import datetime, timezone, timedelta
from http import cookies
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Dict, Iterable, Iterator, List, Optional, Sequence, Tuple
from urllib.parse import parse_qs, quote, unquote, urlparse

ROOT_DIR = Path(__file__).resolve().parent
DEFAULT_SOURCE_DB = (ROOT_DIR.parent / "storage" / "db.sqlite3").resolve()
DEFAULT_CRM_DB = (ROOT_DIR / "crm.sqlite3").resolve()
STATIC_DIR = ROOT_DIR / "static"

APP_TITLE = "FoC Admin CRM"
PAGE_SIZE = 50
SYNC_ALIAS_LIMIT = 12
SYNC_HUB_LIMIT = 10
SYNC_IP_LIMIT = 15
LOG_LIMIT_OPTIONS = (5, 10, 15, 20)
MSK_TZ = timezone(timedelta(hours=3), name="MSK")
CACHE_SCHEMA_VERSION = 3
AUTO_SYNC_CHECK_INTERVAL = 5.0
SESSION_COOKIE_NAME = "crm_session"
RECENT_CONNECTION_LIMIT = 20
RECENT_LOG_CACHE_LIMIT = 500
LOGS_PAGE_SIZE = 20
SESSION_HISTORY_LIMIT = 20
PLAYER_CARD_SESSION_TTL_HOURS = 12
PLAYER_CARD_SESSION_GRACE_MINUTES = 15
PLAYER_CARD_SESSION_HEARTBEAT_SECONDS = 75
PLAYER_CARD_SESSION_PING_INTERVAL_MS = 20000
RECENT_SEED_CONNECT_ROWS = 40000
RECENT_SEED_ACTIVITY_ROWS = 40000
CRM_BUSY_TIMEOUT_MS = 1500
CRM_WRITE_RETRIES = 4
CRM_WRITE_RETRY_BASE_DELAY = 0.2

SORT_SQL = {
    "last_seen_desc": "pc.last_seen DESC, pc.hdid ASC",
    "last_seen_asc": "pc.last_seen ASC, pc.hdid ASC",
    "hdid_asc": "pc.hdid ASC",
    "hdid_desc": "pc.hdid DESC",
    "connect_desc": "pc.connect_count DESC, pc.last_seen DESC",
    "connect_asc": "pc.connect_count ASC, pc.last_seen DESC",
}

PROFILE_SORT_SQL = {
    "updated_desc": "p.updated_at DESC, p.id DESC",
    "created_desc": "p.created_at DESC, p.id DESC",
    "title_asc": "LOWER(p.title) ASC, p.id DESC",
}


def now_ts() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def e(value: object) -> str:
    return html.escape("" if value is None else str(value), quote=True)


def normalize_text(value: str) -> str:
    return " ".join((value or "").replace("\u0000", " ").split()).strip()


def parse_multi_value_field(value: str) -> List[str]:
    raw = value.replace(";", "\n").replace(",", "\n")
    items = []
    seen = set()
    for part in raw.splitlines():
        item = normalize_text(part)
        if item and item not in seen:
            seen.add(item)
            items.append(item)
    return items


def split_pipe_values(value: Optional[str]) -> List[str]:
    items = []
    seen = set()
    for part in (value or "").split("|"):
        item = normalize_text(part)
        if item and item not in seen:
            seen.add(item)
            items.append(item)
    return items


def preview_text(value: Optional[str], limit: int = 160) -> str:
    text = normalize_text(value or "")
    if len(text) <= limit:
        return text
    return text[: limit - 1].rstrip() + "…"


def parse_dt(value: Optional[str]) -> Optional[datetime]:
    if not value:
        return None
    text = str(value).strip()
    if not text:
        return None
    normalized = text.replace("Z", "+00:00")
    try:
        dt = datetime.fromisoformat(normalized)
    except Exception:
        try:
            dt = datetime.strptime(text, "%Y-%m-%d %H:%M:%S")
        except Exception:
            return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(MSK_TZ)


def format_dt(value: Optional[str], include_tz: bool = True) -> str:
    if not value:
        return "—"
    dt = parse_dt(value)
    if not dt:
        return str(value)
    return dt.strftime("%d.%m.%Y %H:%M" + (" МСК" if include_tz else ""))


def hash_password(password: str, salt_hex: str) -> str:
    return hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), bytes.fromhex(salt_hex), 200_000).hex()


def generate_password_hash(password: str) -> Tuple[str, str]:
    salt_hex = secrets.token_hex(16)
    return salt_hex, hash_password(password, salt_hex)


def hash_session_token(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


class CRMRepository:
    def __init__(self, source_db: Path, crm_db: Path):
        self.source_db = Path(source_db)
        self.crm_db = Path(crm_db)
        self.crm_db.parent.mkdir(parents=True, exist_ok=True)
        self._sync_job_lock = threading.Lock()
        self._sync_check_lock = threading.Lock()
        self._sync_thread: Optional[threading.Thread] = None
        self._last_sync_check = 0.0
        self._sync_state = {
            "status": "idle",
            "mode": "—",
            "message": "",
            "started_at": None,
            "finished_at": None,
        }
        self._init_crm_db()
        self.normalize_profile_links()
        self._schema_upgrade_required = self._meta_int("cache_schema_version", 0) < CACHE_SCHEMA_VERSION

    def source_conn(self) -> sqlite3.Connection:
        conn = sqlite3.connect(f"file:{self.source_db}?mode=ro", uri=True, check_same_thread=False)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA query_only = ON")
        conn.execute("PRAGMA read_uncommitted = 1")
        conn.execute("PRAGMA busy_timeout = 1200")
        return conn

    def crm_conn(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.crm_db, timeout=CRM_BUSY_TIMEOUT_MS / 1000.0, check_same_thread=False)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA foreign_keys = ON")
        conn.execute(f"PRAGMA busy_timeout = {CRM_BUSY_TIMEOUT_MS}")
        return conn

    def _run_write_transaction(self, callback, retries: int = CRM_WRITE_RETRIES):
        last_exc = None
        for attempt in range(retries):
            try:
                with self.crm_conn() as conn:
                    conn.execute("BEGIN IMMEDIATE")
                    return callback(conn)
            except sqlite3.IntegrityError:
                raise
            except sqlite3.OperationalError as exc:
                if "locked" not in str(exc).lower() and "busy" not in str(exc).lower():
                    raise
                last_exc = exc
                time.sleep(min(2.0, CRM_WRITE_RETRY_BASE_DELAY * (1.7 ** attempt)))
        if last_exc:
            raise last_exc
        raise sqlite3.OperationalError("database is locked")

    def _column_exists(self, conn: sqlite3.Connection, table: str, column: str) -> bool:
        rows = conn.execute(f"PRAGMA table_info({table})").fetchall()
        return any(str(row[1]) == column for row in rows)

    def _init_crm_db(self) -> None:
        with self.crm_conn() as conn:
            conn.executescript(
                """
                PRAGMA journal_mode=WAL;
                PRAGMA synchronous=NORMAL;
                CREATE TABLE IF NOT EXISTS meta (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS player_cache (
                    hdid TEXT PRIMARY KEY,
                    first_seen TEXT,
                    last_seen TEXT,
                    connect_count INTEGER NOT NULL DEFAULT 0,
                    failed_count INTEGER NOT NULL DEFAULT 0,
                    ip_count INTEGER NOT NULL DEFAULT 0,
                    last_ipid INTEGER,
                    last_ip TEXT,
                    is_hdid_banned INTEGER NOT NULL DEFAULT 0,
                    is_ip_banned INTEGER NOT NULL DEFAULT 0,
                    last_ooc_name TEXT,
                    last_ic_name TEXT,
                    last_char_name TEXT,
                    last_hub_name TEXT,
                    ooc_names_text TEXT,
                    ic_names_text TEXT,
                    char_names_text TEXT,
                    hub_names_text TEXT,
                    ip_addresses_text TEXT,
                    search_blob TEXT
                );
                CREATE INDEX IF NOT EXISTS idx_player_cache_last_seen ON player_cache(last_seen DESC);
                CREATE INDEX IF NOT EXISTS idx_player_cache_connect_count ON player_cache(connect_count DESC);
                CREATE INDEX IF NOT EXISTS idx_player_cache_bans ON player_cache(is_hdid_banned, is_ip_banned);

                CREATE TABLE IF NOT EXISTS profiles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    title TEXT NOT NULL,
                    ooc_name TEXT,
                    discord TEXT,
                    status TEXT NOT NULL DEFAULT 'new',
                    risk_level TEXT NOT NULL DEFAULT 'medium',
                    tags TEXT,
                    notes TEXT,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_profiles_updated_at ON profiles(updated_at DESC);
                CREATE INDEX IF NOT EXISTS idx_profiles_status ON profiles(status);

                CREATE TABLE IF NOT EXISTS accounts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL COLLATE NOCASE UNIQUE,
                    display_name TEXT,
                    password_salt TEXT NOT NULL,
                    password_hash TEXT NOT NULL,
                    role TEXT NOT NULL DEFAULT 'user',
                    can_access_panel INTEGER NOT NULL DEFAULT 0,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    last_login_at TEXT,
                    approved_by_account_id INTEGER,
                    approved_at TEXT,
                    FOREIGN KEY (approved_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
                );
                CREATE INDEX IF NOT EXISTS idx_accounts_role ON accounts(role);
                CREATE INDEX IF NOT EXISTS idx_accounts_access ON accounts(can_access_panel);

                CREATE TABLE IF NOT EXISTS account_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    account_id INTEGER NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE,
                    created_at TEXT NOT NULL,
                    last_seen_at TEXT NOT NULL,
                    user_agent TEXT,
                    ip_address TEXT,
                    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                );
                CREATE INDEX IF NOT EXISTS idx_account_sessions_account ON account_sessions(account_id);
                CREATE INDEX IF NOT EXISTS idx_account_sessions_last_seen ON account_sessions(last_seen_at DESC);

                CREATE TABLE IF NOT EXISTS player_access_rules (
                    hdid TEXT PRIMARY KEY,
                    requires_ga_accept INTEGER NOT NULL DEFAULT 0,
                    set_by_account_id INTEGER,
                    updated_at TEXT NOT NULL,
                    FOREIGN KEY (set_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
                );
                CREATE INDEX IF NOT EXISTS idx_player_access_rules_accept ON player_access_rules(requires_ga_accept);

                CREATE TABLE IF NOT EXISTS player_card_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    hdid TEXT NOT NULL,
                    account_id INTEGER NOT NULL,
                    started_at TEXT NOT NULL,
                    last_seen_at TEXT,
                    ended_at TEXT,
                    ended_reason TEXT,
                    request_ip TEXT,
                    user_agent TEXT,
                    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
                );
                CREATE TABLE IF NOT EXISTS player_access_requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    hdid TEXT NOT NULL,
                    requester_account_id INTEGER NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    resolved_at TEXT,
                    resolved_by_account_id INTEGER,
                    note TEXT,
                    FOREIGN KEY (requester_account_id) REFERENCES accounts(id) ON DELETE CASCADE,
                    FOREIGN KEY (resolved_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
                );
                CREATE INDEX IF NOT EXISTS idx_player_access_requests_hdid_status ON player_access_requests(hdid, status, created_at DESC, id DESC);
                CREATE INDEX IF NOT EXISTS idx_player_access_requests_requester ON player_access_requests(requester_account_id, created_at DESC, id DESC);

                CREATE TABLE IF NOT EXISTS profile_hdids (
                    profile_id INTEGER NOT NULL,
                    hdid TEXT NOT NULL,
                    is_primary INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY (profile_id, hdid),
                    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
                );
                CREATE INDEX IF NOT EXISTS idx_profile_hdids_hdid ON profile_hdids(hdid);
                CREATE UNIQUE INDEX IF NOT EXISTS ux_profile_hdids_hdid_unique ON profile_hdids(hdid);

                CREATE TABLE IF NOT EXISTS player_cache_ipids (
                    hdid TEXT NOT NULL,
                    ipid INTEGER NOT NULL,
                    PRIMARY KEY (hdid, ipid)
                );
                CREATE INDEX IF NOT EXISTS idx_player_cache_ipids_hdid ON player_cache_ipids(hdid);
                CREATE INDEX IF NOT EXISTS idx_player_cache_ipids_ipid ON player_cache_ipids(ipid);

                CREATE TABLE IF NOT EXISTS player_ipid_sessions (
                    ipid INTEGER PRIMARY KEY,
                    hdid TEXT NOT NULL,
                    event_time TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_player_ipid_sessions_hdid ON player_ipid_sessions(hdid);

                CREATE TABLE IF NOT EXISTS player_recent_connections (
                    source_rowid INTEGER PRIMARY KEY,
                    hdid TEXT NOT NULL,
                    event_time TEXT NOT NULL,
                    ipid INTEGER NOT NULL,
                    failed INTEGER NOT NULL DEFAULT 0,
                    ip_address TEXT
                );
                CREATE INDEX IF NOT EXISTS idx_player_recent_connections_hdid_time
                    ON player_recent_connections(hdid, event_time DESC, source_rowid DESC);

                CREATE TABLE IF NOT EXISTS player_recent_logs (
                    source_uid TEXT PRIMARY KEY,
                    source_kind TEXT NOT NULL,
                    source_rowid INTEGER NOT NULL,
                    hdid TEXT NOT NULL,
                    event_time TEXT NOT NULL,
                    ipid INTEGER NOT NULL,
                    ip_address TEXT,
                    event_type TEXT,
                    category TEXT,
                    message TEXT,
                    ooc_name TEXT,
                    char_name TEXT,
                    hub_name TEXT
                );
                CREATE INDEX IF NOT EXISTS idx_player_recent_logs_hdid_time
                    ON player_recent_logs(hdid, event_time DESC, source_rowid DESC);
                """
            )

            if not self._column_exists(conn, "player_card_sessions", "last_seen_at"):
                conn.execute("ALTER TABLE player_card_sessions ADD COLUMN last_seen_at TEXT")
            if not self._column_exists(conn, "player_card_sessions", "ended_at"):
                conn.execute("ALTER TABLE player_card_sessions ADD COLUMN ended_at TEXT")
            if not self._column_exists(conn, "player_card_sessions", "ended_reason"):
                conn.execute("ALTER TABLE player_card_sessions ADD COLUMN ended_reason TEXT")
            if not self._column_exists(conn, "player_card_sessions", "request_ip"):
                conn.execute("ALTER TABLE player_card_sessions ADD COLUMN request_ip TEXT")
            if not self._column_exists(conn, "player_card_sessions", "user_agent"):
                conn.execute("ALTER TABLE player_card_sessions ADD COLUMN user_agent TEXT")

            conn.execute("CREATE INDEX IF NOT EXISTS idx_player_card_sessions_hdid_started ON player_card_sessions(hdid, started_at DESC, id DESC)")
            conn.execute("CREATE INDEX IF NOT EXISTS idx_player_card_sessions_account ON player_card_sessions(account_id, started_at DESC)")
            conn.execute("CREATE INDEX IF NOT EXISTS idx_player_card_sessions_active ON player_card_sessions(account_id, hdid, ended_at, last_seen_at DESC, id DESC)")


    def _meta_int(self, key: str, default: int = 0) -> int:
        value = self.get_meta(key)
        if value in {None, ""}:
            return default
        try:
            return int(value)
        except Exception:
            try:
                return int(float(value))
            except Exception:
                return default

    def _meta_float(self, key: str, default: float = 0.0) -> float:
        value = self.get_meta(key)
        if value in {None, ""}:
            return default
        try:
            return float(value)
        except Exception:
            return default

    def _has_cache(self) -> bool:
        return self._meta_int("player_cache_count", 0) > 0

    def _has_incremental_state(self) -> bool:
        if not self._has_cache():
            return True
        required_keys = [
            "source_connect_rowid",
            "source_area_rowid",
            "source_misc_rowid",
            "source_bans_rowid",
            "source_hdid_bans_rowid",
            "source_ip_bans_rowid",
            "source_bans_count",
            "source_hdid_bans_count",
            "source_ip_bans_count",
        ]
        if any(self.get_meta(key) is None for key in required_keys):
            return False
        with self.crm_conn() as conn:
            return bool(conn.execute("SELECT COUNT(*) FROM player_cache_ipids").fetchone()[0])

    def _current_source_state(self) -> dict:
        with self.source_conn() as conn:
            return {
                "mtime": float(self.source_db.stat().st_mtime) if self.source_db.exists() else 0.0,
                "connect_rowid": int(conn.execute("SELECT COALESCE(MAX(rowid), 0) FROM connect_events").fetchone()[0]),
                "area_rowid": int(conn.execute("SELECT COALESCE(MAX(rowid), 0) FROM area_events").fetchone()[0]),
                "misc_rowid": int(conn.execute("SELECT COALESCE(MAX(rowid), 0) FROM misc_events").fetchone()[0]),
                "bans_rowid": int(conn.execute("SELECT COALESCE(MAX(rowid), 0) FROM bans").fetchone()[0]),
                "hdid_bans_rowid": int(conn.execute("SELECT COALESCE(MAX(rowid), 0) FROM hdid_bans").fetchone()[0]),
                "ip_bans_rowid": int(conn.execute("SELECT COALESCE(MAX(rowid), 0) FROM ip_bans").fetchone()[0]),
                "bans_count": int(conn.execute("SELECT COUNT(*) FROM bans").fetchone()[0]),
                "hdid_bans_count": int(conn.execute("SELECT COUNT(*) FROM hdid_bans").fetchone()[0]),
                "ip_bans_count": int(conn.execute("SELECT COUNT(*) FROM ip_bans").fetchone()[0]),
            }

    def _stored_source_state(self) -> dict:
        return {
            "mtime": self._meta_float("source_mtime", 0.0),
            "connect_rowid": self._meta_int("source_connect_rowid", 0),
            "area_rowid": self._meta_int("source_area_rowid", 0),
            "misc_rowid": self._meta_int("source_misc_rowid", 0),
            "bans_rowid": self._meta_int("source_bans_rowid", 0),
            "hdid_bans_rowid": self._meta_int("source_hdid_bans_rowid", 0),
            "ip_bans_rowid": self._meta_int("source_ip_bans_rowid", 0),
            "bans_count": self._meta_int("source_bans_count", 0),
            "hdid_bans_count": self._meta_int("source_hdid_bans_count", 0),
            "ip_bans_count": self._meta_int("source_ip_bans_count", 0),
        }

    def _store_source_state(self, state: dict) -> None:
        self.set_meta("source_mtime", str(state["mtime"]))
        self.set_meta("source_connect_rowid", str(state["connect_rowid"]))
        self.set_meta("source_area_rowid", str(state["area_rowid"]))
        self.set_meta("source_misc_rowid", str(state["misc_rowid"]))
        self.set_meta("source_bans_rowid", str(state["bans_rowid"]))
        self.set_meta("source_hdid_bans_rowid", str(state["hdid_bans_rowid"]))
        self.set_meta("source_ip_bans_rowid", str(state["ip_bans_rowid"]))
        self.set_meta("source_bans_count", str(state["bans_count"]))
        self.set_meta("source_hdid_bans_count", str(state["hdid_bans_count"]))
        self.set_meta("source_ip_bans_count", str(state["ip_bans_count"]))
        self.set_meta("cache_schema_version", str(CACHE_SCHEMA_VERSION))
        self._schema_upgrade_required = False

    def sync_status(self) -> dict:
        status = dict(self._sync_state)
        status["running"] = bool(self._sync_thread and self._sync_thread.is_alive())
        return status

    def users_exist(self) -> bool:
        with self.crm_conn() as conn:
            return bool(conn.execute("SELECT 1 FROM accounts LIMIT 1").fetchone())

    def users_count(self) -> int:
        with self.crm_conn() as conn:
            return int(conn.execute("SELECT COUNT(*) FROM accounts").fetchone()[0])

    def get_account_by_id(self, account_id: int) -> Optional[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                "SELECT id, username, display_name, role, can_access_panel, is_active, created_at, updated_at, last_login_at, approved_at FROM accounts WHERE id = ?",
                (account_id,),
            ).fetchone()

    def get_account_by_username(self, username: str) -> Optional[sqlite3.Row]:
        username = normalize_text(username).lower()
        if not username:
            return None
        with self.crm_conn() as conn:
            return conn.execute(
                "SELECT id, username, display_name, role, can_access_panel, is_active, created_at, updated_at, last_login_at, approved_at FROM accounts WHERE username = ?",
                (username,),
            ).fetchone()

    def register_account(self, username: str, password: str, display_name: str = "") -> Tuple[Optional[sqlite3.Row], Optional[str]]:
        username = normalize_text(username).lower()
        display_name = normalize_text(display_name)
        if not username:
            return None, "Укажите логин"
        if len(username) < 3:
            return None, "Логин должен быть не короче 3 символов"
        if not re.fullmatch(r"[a-zA-Z0-9_.-]+", username):
            return None, "Логин: латиница, цифры, точка, подчёркивание или дефис"
        if len(password or "") < 6:
            return None, "Пароль должен быть не короче 6 символов"
        stamp = now_ts()
        salt_hex, password_hash = generate_password_hash(password)

        def writer(conn: sqlite3.Connection) -> int:
            existing_count = int(conn.execute("SELECT COUNT(*) FROM accounts").fetchone()[0])
            role = "superadmin" if existing_count == 0 else "user"
            can_access_panel = 1
            approved_at = stamp
            return int(conn.execute(
                """
                INSERT INTO accounts(username, display_name, password_salt, password_hash, role, can_access_panel, created_at, updated_at, approved_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (username, display_name, salt_hex, password_hash, role, can_access_panel, stamp, stamp, approved_at),
            ).lastrowid)

        try:
            account_id = self._run_write_transaction(writer)
        except sqlite3.IntegrityError:
            return None, "Такой логин уже существует"
        except sqlite3.OperationalError:
            return None, "CRM-база сейчас занята синхронизацией. Повторите регистрацию через пару секунд."
        return self.get_account_by_id(int(account_id)), None

    def authenticate_account(self, username: str, password: str) -> Optional[sqlite3.Row]:
        username = normalize_text(username).lower()
        with self.crm_conn() as conn:
            row = conn.execute(
                "SELECT * FROM accounts WHERE username = ? AND is_active = 1",
                (username,),
            ).fetchone()
        if not row:
            return None
        if hash_password(password or "", row["password_salt"]) != row["password_hash"]:
            return None
        stamp = now_ts()
        try:
            self._run_write_transaction(lambda conn: conn.execute("UPDATE accounts SET last_login_at = ?, updated_at = ? WHERE id = ?", (stamp, stamp, row["id"])))
        except sqlite3.OperationalError:
            pass
        return self.get_account_by_id(int(row["id"]))

    def create_account_session(self, account_id: int, user_agent: str = "", ip_address: str = "") -> str:
        token = secrets.token_urlsafe(32)
        token_hash = hash_session_token(token)
        stamp = now_ts()
        self._run_write_transaction(lambda conn: conn.execute(
            "INSERT INTO account_sessions(account_id, token_hash, created_at, last_seen_at, user_agent, ip_address) VALUES (?, ?, ?, ?, ?, ?)",
            (account_id, token_hash, stamp, stamp, normalize_text(user_agent)[:255], normalize_text(ip_address)[:255]),
        ))
        return token

    def get_account_by_session(self, token: str) -> Optional[sqlite3.Row]:
        if not token:
            return None
        token_hash = hash_session_token(token)
        with self.crm_conn() as conn:
            row = conn.execute(
                """
                SELECT a.id, a.username, a.display_name, a.role, a.can_access_panel, a.is_active, a.created_at, a.updated_at, a.last_login_at, a.approved_at
                FROM account_sessions s
                JOIN accounts a ON a.id = s.account_id
                WHERE s.token_hash = ? AND a.is_active = 1
                LIMIT 1
                """,
                (token_hash,),
            ).fetchone()
        if row:
            try:
                self._run_write_transaction(lambda conn: conn.execute("UPDATE account_sessions SET last_seen_at = ? WHERE token_hash = ?", (now_ts(), token_hash)), retries=2)
            except sqlite3.OperationalError:
                pass
        return row

    def revoke_session(self, token: str) -> None:
        if not token:
            return
        try:
            self._run_write_transaction(lambda conn: conn.execute("DELETE FROM account_sessions WHERE token_hash = ?", (hash_session_token(token),)), retries=2)
        except sqlite3.OperationalError:
            pass

    def has_panel_access(self, account: Optional[sqlite3.Row]) -> bool:
        if not account:
            return False
        return str(account["role"]) == "superadmin" or int(account["can_access_panel"] or 0) == 1

    def list_accounts(self) -> List[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                """
                SELECT a.id, a.username, a.display_name, a.role, a.can_access_panel, a.is_active, a.created_at, a.updated_at, a.last_login_at,
                       approver.username AS approved_by_username, a.approved_at
                FROM accounts a
                LEFT JOIN accounts approver ON approver.id = a.approved_by_account_id
                ORDER BY CASE WHEN a.role = 'superadmin' THEN 0 ELSE 1 END, a.created_at ASC, a.id ASC
                """
            ).fetchall()

    def set_account_access(self, account_id: int, can_access_panel: bool, approved_by_account_id: Optional[int]) -> None:
        def writer(conn: sqlite3.Connection) -> None:
            row = conn.execute("SELECT role FROM accounts WHERE id = ?", (account_id,)).fetchone()
            if not row or str(row["role"]) == "superadmin":
                return
            stamp = now_ts()
            conn.execute(
                "UPDATE accounts SET can_access_panel = ?, approved_by_account_id = ?, approved_at = ?, updated_at = ? WHERE id = ?",
                (1 if can_access_panel else 0, approved_by_account_id if can_access_panel else None, stamp if can_access_panel else None, stamp, account_id),
            )

        self._run_write_transaction(writer)

    def checkpoint(self) -> None:
        try:
            conn = sqlite3.connect(self.crm_db, timeout=2.0)
            conn.execute("PRAGMA wal_checkpoint(FULL)")
            conn.close()
        except Exception:
            pass

    def normalize_profile_links(self) -> None:
        with self.crm_conn() as conn:
            rows = conn.execute(
                """
                SELECT ph.hdid, ph.profile_id
                FROM profile_hdids ph
                JOIN profiles p ON p.id = ph.profile_id
                ORDER BY ph.hdid ASC, ph.is_primary DESC, p.updated_at DESC, p.id DESC
                """
            ).fetchall()
            keep: Dict[str, int] = {}
            for row in rows:
                hdid = row[0]
                profile_id = int(row[1])
                if hdid not in keep:
                    keep[hdid] = profile_id
                    continue
                conn.execute(
                    "DELETE FROM profile_hdids WHERE hdid = ? AND profile_id = ?",
                    (hdid, profile_id),
                )

    def set_meta(self, key: str, value: str) -> None:
        with self.crm_conn() as conn:
            conn.execute(
                "INSERT INTO meta(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                (key, value),
            )

    def get_meta(self, key: str) -> Optional[str]:
        with self.crm_conn() as conn:
            row = conn.execute("SELECT value FROM meta WHERE key = ?", (key,)).fetchone()
            return row[0] if row else None


    def _recent_activity_ready(self) -> bool:
        with self.crm_conn() as conn:
            connections = conn.execute("SELECT COUNT(*) FROM player_recent_connections").fetchone()[0]
            logs = conn.execute("SELECT COUNT(*) FROM player_recent_logs").fetchone()[0]
            sessions = conn.execute("SELECT COUNT(*) FROM player_ipid_sessions").fetchone()[0]
        return bool(connections and logs and sessions)

    def start_background_seed(self) -> bool:
        if self._sync_thread and self._sync_thread.is_alive():
            return False

        def worker() -> None:
            try:
                self._seed_recent_activity_cache()
                self._sync_state = {
                    "status": "ok",
                    "mode": "seed",
                    "message": "Быстрый журнал и последние подключения обновлены",
                    "started_at": started_at,
                    "finished_at": now_ts(),
                }
            except Exception as exc:
                self._sync_state = {
                    "status": "error",
                    "mode": "seed",
                    "message": str(exc),
                    "started_at": started_at,
                    "finished_at": now_ts(),
                }

        started_at = now_ts()
        self._sync_state = {
            "status": "running",
            "mode": "seed",
            "message": "Обновляем быстрый журнал без полной пересборки",
            "started_at": started_at,
            "finished_at": None,
        }
        self._sync_thread = threading.Thread(target=worker, daemon=True, name="crm-activity-seed")
        self._sync_thread.start()
        return True


    def ensure_cache(self, force: bool = False) -> None:
        if force or not self._has_cache():
            self.sync_player_cache(force_full=True)
            return
        # Не стартуем фоновые тяжёлые задачи прямо при запуске процесса,
        # чтобы регистрация/логин не упирались в write-lock CRM-базы.
        return

    def maybe_start_auto_sync(self, force_check: bool = False) -> bool:
        now = time.time()
        with self._sync_check_lock:
            if not force_check and now - self._last_sync_check < AUTO_SYNC_CHECK_INTERVAL:
                return False
            self._last_sync_check = now
        if self._sync_thread and self._sync_thread.is_alive():
            return False
        if self._has_cache() and not self._recent_activity_ready():
            return self.start_background_seed()
        current_state = self._current_source_state()
        stored_state = self._stored_source_state()
        changed = self._schema_upgrade_required or any(
            current_state.get(key) != stored_state.get(key)
            for key in (
                "mtime",
                "connect_rowid",
                "area_rowid",
                "misc_rowid",
                "bans_rowid",
                "hdid_bans_rowid",
                "ip_bans_rowid",
                "bans_count",
                "hdid_bans_count",
                "ip_bans_count",
            )
        )
        if changed:
            return self.start_background_sync(force_full=self._schema_upgrade_required or not self._has_incremental_state())
        return False

    def start_background_sync(self, force_full: bool = False) -> bool:
        if self._sync_thread and self._sync_thread.is_alive():
            return False

        def worker() -> None:
            try:
                self.sync_player_cache(force_full=force_full)
            except Exception as exc:
                self._sync_state = {
                    "status": "error",
                    "mode": "full" if force_full else "incremental",
                    "message": str(exc),
                    "started_at": self._sync_state.get("started_at"),
                    "finished_at": now_ts(),
                }

        self._sync_state = {
            "status": "running",
            "mode": "full" if force_full else "incremental",
            "message": "Синхронизация выполняется в фоне",
            "started_at": now_ts(),
            "finished_at": None,
        }
        self._sync_thread = threading.Thread(target=worker, daemon=True, name="crm-cache-sync")
        self._sync_thread.start()
        return True

    def _base_summary(self) -> dict:
        return {
            "first_seen": None,
            "last_seen": None,
            "connect_count": 0,
            "failed_count": 0,
            "known_ipids": set(),
            "last_ipid": None,
            "last_ip": "",
            "last_ooc_name": "",
            "last_ooc_at": None,
            "last_ic_name": "",
            "last_ic_at": None,
            "last_char_name": "",
            "last_char_at": None,
            "last_hub_name": "",
            "last_hub_at": None,
            "ooc_names": set(),
            "ic_names": set(),
            "char_names": set(),
            "hub_names": set(),
            "ip_addresses": set(),
            "is_hdid_banned": 0,
            "is_ip_banned": 0,
        }

    def _add_limited(self, bucket: set, value: str, limit: int) -> None:
        value = normalize_text(value)
        if not value:
            return
        if value in bucket or len(bucket) < limit:
            bucket.add(value)

    def _summary_from_cache_row(self, row: Optional[sqlite3.Row], ipids: Optional[Sequence[int]] = None) -> dict:
        summary = self._base_summary()
        if row:
            summary.update(
                {
                    "first_seen": row["first_seen"],
                    "last_seen": row["last_seen"],
                    "connect_count": int(row["connect_count"] or 0),
                    "failed_count": int(row["failed_count"] or 0),
                    "last_ipid": row["last_ipid"],
                    "last_ip": row["last_ip"] or "",
                    "last_ooc_name": row["last_ooc_name"] or "",
                    "last_ic_name": row["last_ic_name"] or "",
                    "last_char_name": row["last_char_name"] or "",
                    "last_hub_name": row["last_hub_name"] or "",
                    "ooc_names": set(split_pipe_values(row["ooc_names_text"])),
                    "ic_names": set(split_pipe_values(row["ic_names_text"])),
                    "char_names": set(split_pipe_values(row["char_names_text"])),
                    "hub_names": set(split_pipe_values(row["hub_names_text"])),
                    "ip_addresses": set(split_pipe_values(row["ip_addresses_text"])),
                    "is_hdid_banned": int(row["is_hdid_banned"] or 0),
                    "is_ip_banned": int(row["is_ip_banned"] or 0),
                }
            )
            if row["last_ooc_name"]:
                summary["last_ooc_at"] = row["last_seen"]
            if row["last_ic_name"]:
                summary["last_ic_at"] = row["last_seen"]
            if row["last_char_name"]:
                summary["last_char_at"] = row["last_seen"]
            if row["last_hub_name"]:
                summary["last_hub_at"] = row["last_seen"]
        if ipids:
            summary["known_ipids"].update(int(ipid) for ipid in ipids)
        return summary

    def _serialize_summary(self, hdid: str, summary: dict) -> tuple:
        ip_addresses_text = " | ".join(sorted(summary["ip_addresses"]))
        ooc_names_text = " | ".join(sorted(summary["ooc_names"]))
        ic_names_text = " | ".join(sorted(summary["ic_names"]))
        char_names_text = " | ".join(sorted(summary["char_names"]))
        hub_names_text = " | ".join(sorted(summary["hub_names"]))
        search_parts = [
            hdid,
            summary["last_ip"] or "",
            ip_addresses_text,
            summary["last_ooc_name"] or "",
            summary["last_ic_name"] or "",
            summary["last_char_name"] or "",
            summary["last_hub_name"] or "",
            ooc_names_text,
            ic_names_text,
            char_names_text,
            hub_names_text,
        ]
        return (
            hdid,
            summary["first_seen"],
            summary["last_seen"],
            int(summary["connect_count"]),
            int(summary["failed_count"]),
            len(summary["known_ipids"]),
            summary["last_ipid"],
            summary["last_ip"],
            int(summary.get("is_hdid_banned", 0)),
            int(summary.get("is_ip_banned", 0)),
            summary["last_ooc_name"],
            summary["last_ic_name"],
            summary["last_char_name"],
            summary["last_hub_name"],
            ooc_names_text,
            ic_names_text,
            char_names_text,
            hub_names_text,
            ip_addresses_text,
            normalize_text(" ".join(search_parts)).lower(),
        )

    def _load_existing_summaries(self, hdids: Sequence[str]) -> Tuple[Dict[str, dict], Dict[str, set]]:
        hdids = [normalize_text(hdid) for hdid in hdids if normalize_text(hdid)]
        if not hdids:
            return {}, {}
        placeholders = ", ".join("?" for _ in hdids)
        with self.crm_conn() as conn:
            cache_rows = {
                row["hdid"]: row
                for row in conn.execute(f"SELECT * FROM player_cache WHERE hdid IN ({placeholders})", hdids).fetchall()
            }
            ipid_rows = conn.execute(
                f"SELECT hdid, ipid FROM player_cache_ipids WHERE hdid IN ({placeholders})", hdids
            ).fetchall()
        ipids_by_hdid: Dict[str, set] = {}
        for row in ipid_rows:
            ipids_by_hdid.setdefault(row["hdid"], set()).add(int(row["ipid"]))
        summaries = {
            hdid: self._summary_from_cache_row(cache_rows.get(hdid), ipids_by_hdid.get(hdid, set()))
            for hdid in hdids
        }
        return summaries, ipids_by_hdid

    def _upsert_player_cache_rows(self, summaries: Dict[str, dict], new_ipid_pairs: Sequence[Tuple[str, int]]) -> None:
        if not summaries and not new_ipid_pairs:
            return
        rows = [self._serialize_summary(hdid, summary) for hdid, summary in summaries.items()]
        with self.crm_conn() as conn:
            if new_ipid_pairs:
                conn.executemany(
                    "INSERT OR IGNORE INTO player_cache_ipids(hdid, ipid) VALUES (?, ?)",
                    list(new_ipid_pairs),
                )
            if rows:
                conn.executemany(
                    """
                    INSERT INTO player_cache (
                        hdid, first_seen, last_seen, connect_count, failed_count, ip_count, last_ipid, last_ip,
                        is_hdid_banned, is_ip_banned, last_ooc_name, last_ic_name, last_char_name, last_hub_name,
                        ooc_names_text, ic_names_text, char_names_text, hub_names_text, ip_addresses_text, search_blob
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(hdid) DO UPDATE SET
                        first_seen=excluded.first_seen,
                        last_seen=excluded.last_seen,
                        connect_count=excluded.connect_count,
                        failed_count=excluded.failed_count,
                        ip_count=excluded.ip_count,
                        last_ipid=excluded.last_ipid,
                        last_ip=excluded.last_ip,
                        is_hdid_banned=excluded.is_hdid_banned,
                        is_ip_banned=excluded.is_ip_banned,
                        last_ooc_name=excluded.last_ooc_name,
                        last_ic_name=excluded.last_ic_name,
                        last_char_name=excluded.last_char_name,
                        last_hub_name=excluded.last_hub_name,
                        ooc_names_text=excluded.ooc_names_text,
                        ic_names_text=excluded.ic_names_text,
                        char_names_text=excluded.char_names_text,
                        hub_names_text=excluded.hub_names_text,
                        ip_addresses_text=excluded.ip_addresses_text,
                        search_blob=excluded.search_blob
                    """,
                    rows,
                )
            cache_count = conn.execute("SELECT COUNT(*) FROM player_cache").fetchone()[0]
            conn.execute(
                "INSERT INTO meta(key, value) VALUES ('player_cache_count', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                (str(cache_count),),
            )


    def _prune_recent_connections(self, conn: sqlite3.Connection, hdids: Sequence[str]) -> None:
        unique_hdids = sorted({normalize_text(hdid) for hdid in hdids if normalize_text(hdid)})
        for hdid in unique_hdids:
            conn.execute(
                """
                DELETE FROM player_recent_connections
                WHERE hdid = ?
                  AND source_rowid NOT IN (
                      SELECT source_rowid
                      FROM player_recent_connections
                      WHERE hdid = ?
                      ORDER BY event_time DESC, source_rowid DESC
                      LIMIT ?
                  )
                """,
                (hdid, hdid, RECENT_CONNECTION_LIMIT),
            )

    def _prune_recent_logs(self, conn: sqlite3.Connection, hdids: Sequence[str]) -> None:
        unique_hdids = sorted({normalize_text(hdid) for hdid in hdids if normalize_text(hdid)})
        for hdid in unique_hdids:
            conn.execute(
                """
                DELETE FROM player_recent_logs
                WHERE hdid = ?
                  AND source_uid NOT IN (
                      SELECT source_uid
                      FROM player_recent_logs
                      WHERE hdid = ?
                      ORDER BY event_time DESC, source_rowid DESC
                      LIMIT ?
                  )
                """,
                (hdid, hdid, RECENT_LOG_CACHE_LIMIT),
            )

    def _upsert_recent_activity(
        self,
        connection_items: Sequence[Tuple[int, str, str, int, int, str]],
        log_items: Sequence[Tuple[str, str, int, str, str, int, str, str, str, str, str, str, str]],
        replace_all: bool = False,
    ) -> None:
        connection_items = list(connection_items)
        log_items = list(log_items)
        affected_connection_hdids = [row[1] for row in connection_items]
        affected_log_hdids = [row[3] for row in log_items]
        if replace_all:
            with self.crm_conn() as conn:
                conn.execute("DELETE FROM player_recent_connections")
                conn.execute("DELETE FROM player_recent_logs")

        if connection_items:
            for start in range(0, len(connection_items), 2000):
                chunk = connection_items[start:start + 2000]
                with self.crm_conn() as conn:
                    conn.executemany(
                        """
                        INSERT INTO player_recent_connections(source_rowid, hdid, event_time, ipid, failed, ip_address)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON CONFLICT(source_rowid) DO UPDATE SET
                            hdid=excluded.hdid,
                            event_time=excluded.event_time,
                            ipid=excluded.ipid,
                            failed=excluded.failed,
                            ip_address=excluded.ip_address
                        """,
                        chunk,
                    )
                    if not replace_all:
                        self._prune_recent_connections(conn, [row[1] for row in chunk])

        if log_items:
            for start in range(0, len(log_items), 2000):
                chunk = log_items[start:start + 2000]
                with self.crm_conn() as conn:
                    conn.executemany(
                        """
                        INSERT INTO player_recent_logs(
                            source_uid, source_kind, source_rowid, hdid, event_time, ipid, ip_address,
                            event_type, category, message, ooc_name, char_name, hub_name
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON CONFLICT(source_uid) DO UPDATE SET
                            source_kind=excluded.source_kind,
                            source_rowid=excluded.source_rowid,
                            hdid=excluded.hdid,
                            event_time=excluded.event_time,
                            ipid=excluded.ipid,
                            ip_address=excluded.ip_address,
                            event_type=excluded.event_type,
                            category=excluded.category,
                            message=excluded.message,
                            ooc_name=excluded.ooc_name,
                            char_name=excluded.char_name,
                            hub_name=excluded.hub_name
                        """,
                        chunk,
                    )
                    if not replace_all:
                        self._prune_recent_logs(conn, [row[3] for row in chunk])

    def _seed_recent_activity_cache(self, source: Optional[sqlite3.Connection] = None) -> None:
        own_source = False
        if source is None:
            source = self.source_conn()
            own_source = True
        try:
            ip_map = {row[0]: row[1] for row in source.execute("SELECT ipid, ip_address FROM ipids")}
            recent_connect_rows = source.execute(
                """
                SELECT rowid, ipid, event_time, hdid, failed
                FROM connect_events
                ORDER BY rowid DESC
                LIMIT ?
                """,
                (RECENT_SEED_CONNECT_ROWS,),
            ).fetchall()
            sessions_by_ipid: Dict[int, List[sqlite3.Row]] = defaultdict(list)
            last_session_rows: Dict[int, Tuple[str, str]] = {}
            connection_items: List[Tuple[int, str, str, int, int, str]] = []
            connect_log_items: List[Tuple[str, str, int, str, str, int, str, str, str, str, str, str, str]] = []
            for row in reversed(recent_connect_rows):
                ipid = int(row[1])
                hdid = normalize_text(row[3])
                if not hdid:
                    continue
                sessions_by_ipid[ipid].append(row)
            for row in recent_connect_rows:
                source_rowid = int(row[0])
                ipid = int(row[1])
                event_time = row[2]
                hdid = normalize_text(row[3])
                if not hdid:
                    continue
                failed = int(row[4] or 0)
                ip_address = ip_map.get(ipid, "")
                connection_items.append((source_rowid, hdid, event_time, ipid, failed, ip_address))
                last_session_rows[ipid] = (event_time, hdid)
                event_type = "connect.failed" if failed else "connect.ok"
                connect_log_items.append((
                    f"connect:{source_rowid}", "connect", source_rowid, hdid, event_time, ipid, ip_address,
                    event_type, self.classify_log_event(event_type), self.format_log_message(event_type, {}), "", "", ""
                ))

            def map_recent_events(rows: Sequence[sqlite3.Row], kind: str) -> List[Tuple[str, str, int, str, str, int, str, str, str, str, str, str, str]]:
                items: List[Tuple[str, str, int, str, str, int, str, str, str, str, str, str, str]] = []
                session_index_by_ipid: Dict[int, int] = defaultdict(int)
                for row in reversed(rows):
                    source_rowid = int(row[0])
                    event_time = row[1]
                    if row[2] is None:
                        continue
                    ipid = int(row[2])
                    sessions = sessions_by_ipid.get(ipid)
                    if not sessions:
                        continue
                    idx = session_index_by_ipid[ipid]
                    while idx + 1 < len(sessions) and sessions[idx + 1][2] <= event_time:
                        idx += 1
                    session_index_by_ipid[ipid] = idx
                    session = sessions[idx]
                    if session[2] > event_time:
                        continue
                    hdid = normalize_text(session[3])
                    if not hdid:
                        continue
                    ip_address = ip_map.get(ipid, "")
                    if kind == "area":
                        event_type = normalize_text(row[3] or "") or "area.unknown"
                        payload = {
                            "area_name": row[4] or "",
                            "char_name": row[5] or "",
                            "ooc_name": row[6] or "",
                            "ic_name": row[7] or "",
                            "hub_name": row[8] or "",
                            "message": row[9] or "",
                        }
                        items.append((
                            f"area:{source_rowid}", "area", source_rowid, hdid, event_time, ipid, ip_address,
                            event_type, self.classify_log_event(event_type), self.format_log_message(event_type, payload),
                            payload["ooc_name"], payload["char_name"], payload["hub_name"]
                        ))
                    else:
                        event_type = normalize_text(row[3] or "") or "misc.unknown"
                        payload = {"event_data": row[4] or ""}
                        items.append((
                            f"misc:{source_rowid}", "misc", source_rowid, hdid, event_time, ipid, ip_address,
                            event_type, self.classify_log_event(event_type), self.format_log_message(event_type, payload),
                            "", "", ""
                        ))
                return items

            recent_area_rows = source.execute(
                """
                SELECT ae.rowid, ae.event_time, ae.ipid, aet.type_name, ae.area_name, ae.char_name,
                       ae.ooc_name, ae.ic_name, ae.hub_name, ae.message
                FROM area_events ae
                LEFT JOIN area_event_types aet ON aet.type_id = ae.event_subtype
                ORDER BY ae.rowid DESC
                LIMIT ?
                """,
                (RECENT_SEED_ACTIVITY_ROWS,),
            ).fetchall()
            recent_misc_rows = source.execute(
                """
                SELECT me.rowid, me.event_time, me.ipid, met.type_name, me.event_data
                FROM misc_events me
                LEFT JOIN misc_event_types met ON met.type_id = me.event_subtype
                ORDER BY me.rowid DESC
                LIMIT ?
                """,
                (RECENT_SEED_ACTIVITY_ROWS,),
            ).fetchall()
            log_items = connect_log_items + map_recent_events(recent_area_rows, "area") + map_recent_events(recent_misc_rows, "misc")
            self._upsert_recent_activity(connection_items, log_items, replace_all=True)
            if last_session_rows:
                with self.crm_conn() as conn:
                    conn.executemany(
                        "INSERT OR REPLACE INTO player_ipid_sessions(ipid, hdid, event_time) VALUES (?, ?, ?)",
                        [(ipid, data[1], data[0]) for ipid, data in last_session_rows.items()],
                    )
        finally:
            if own_source:
                source.close()

    def _full_sync_player_cache(self, source_state: dict) -> str:
        start = time.time()
        source = self.source_conn()
        ipid_to_ip = {row[0]: row[1] for row in source.execute("SELECT ipid, ip_address FROM ipids")}
        hdid_banned = {row[0] for row in source.execute("SELECT hdid FROM hdid_bans")}
        ip_banned = {int(row[0]) for row in source.execute("SELECT ipid FROM ip_bans")}
        summaries: Dict[str, dict] = {}
        ipid_pairs: List[Tuple[str, int]] = []
        last_session_by_ipid: Dict[int, Tuple[str, str]] = {}

        def grouped_rows(cursor: sqlite3.Cursor) -> Iterator[Tuple[int, List[sqlite3.Row]]]:
            current_key = None
            bucket: List[sqlite3.Row] = []
            for row in cursor:
                key = row[0]
                if current_key is None:
                    current_key = key
                if key != current_key:
                    yield current_key, bucket
                    current_key = key
                    bucket = [row]
                else:
                    bucket.append(row)
            if current_key is not None:
                yield current_key, bucket

        connect_iter = grouped_rows(
            source.execute("SELECT ipid, event_time, hdid, failed FROM connect_events ORDER BY ipid ASC, event_time ASC")
        )
        area_iter = grouped_rows(
            source.execute("SELECT ipid, event_time, ooc_name, ic_name, char_name, hub_name FROM area_events ORDER BY ipid ASC, event_time ASC")
        )
        current_connect = next(connect_iter, None)
        current_area = next(area_iter, None)

        while current_connect is not None or current_area is not None:
            next_ipid_candidates = []
            if current_connect is not None:
                next_ipid_candidates.append(current_connect[0])
            if current_area is not None:
                next_ipid_candidates.append(current_area[0])
            ipid = min(next_ipid_candidates)
            connect_rows = current_connect[1] if current_connect is not None and current_connect[0] == ipid else []
            area_rows = current_area[1] if current_area is not None and current_area[0] == ipid else []
            sessions: List[Tuple[str, str]] = []
            ip_address = ipid_to_ip.get(ipid, "")
            banned_ip = 1 if ipid in ip_banned else 0
            for row in connect_rows:
                event_time = row[1]
                hdid = row[2]
                failed = int(row[3] or 0)
                summary = summaries.setdefault(hdid, self._base_summary())
                summary["connect_count"] += 1
                summary["failed_count"] += failed
                summary["known_ipids"].add(int(ipid))
                self._add_limited(summary["ip_addresses"], ip_address, SYNC_IP_LIMIT)
                if summary["first_seen"] is None or event_time < summary["first_seen"]:
                    summary["first_seen"] = event_time
                if summary["last_seen"] is None or event_time > summary["last_seen"]:
                    summary["last_seen"] = event_time
                    summary["last_ipid"] = ipid
                    summary["last_ip"] = ip_address
                if banned_ip:
                    summary["is_ip_banned"] = 1
                ipid_pairs.append((hdid, int(ipid)))
                last_session_by_ipid[int(ipid)] = (event_time, hdid)
                sessions.append((event_time, hdid))
            if sessions and area_rows:
                session_index = 0
                for row in area_rows:
                    event_time = row[1]
                    while session_index + 1 < len(sessions) and sessions[session_index + 1][0] <= event_time:
                        session_index += 1
                    if sessions[session_index][0] > event_time:
                        continue
                    hdid = sessions[session_index][1]
                    summary = summaries.setdefault(hdid, self._base_summary())
                    ooc_name = normalize_text(row[2] or "")
                    ic_name = normalize_text(row[3] or "")
                    char_name = normalize_text(row[4] or "")
                    hub_name = normalize_text(row[5] or "")
                    if ooc_name:
                        self._add_limited(summary["ooc_names"], ooc_name, SYNC_ALIAS_LIMIT)
                        if summary["last_ooc_at"] is None or event_time >= summary["last_ooc_at"]:
                            summary["last_ooc_at"] = event_time
                            summary["last_ooc_name"] = ooc_name
                    if ic_name:
                        self._add_limited(summary["ic_names"], ic_name, SYNC_ALIAS_LIMIT)
                        if summary["last_ic_at"] is None or event_time >= summary["last_ic_at"]:
                            summary["last_ic_at"] = event_time
                            summary["last_ic_name"] = ic_name
                    if char_name:
                        self._add_limited(summary["char_names"], char_name, SYNC_ALIAS_LIMIT)
                        if summary["last_char_at"] is None or event_time >= summary["last_char_at"]:
                            summary["last_char_at"] = event_time
                            summary["last_char_name"] = char_name
                    if hub_name:
                        self._add_limited(summary["hub_names"], hub_name, SYNC_HUB_LIMIT)
                        if summary["last_hub_at"] is None or event_time >= summary["last_hub_at"]:
                            summary["last_hub_at"] = event_time
                            summary["last_hub_name"] = hub_name
            if current_connect is not None and current_connect[0] == ipid:
                current_connect = next(connect_iter, None)
            if current_area is not None and current_area[0] == ipid:
                current_area = next(area_iter, None)

        for hdid, summary in summaries.items():
            summary["is_hdid_banned"] = 1 if hdid in hdid_banned else 0
            summary["is_ip_banned"] = 1 if summary["known_ipids"] & ip_banned else 0

        rows = [self._serialize_summary(hdid, summary) for hdid, summary in summaries.items()]
        with self.crm_conn() as conn:
            conn.execute("DELETE FROM player_cache")
            conn.execute("DELETE FROM player_cache_ipids")
            conn.execute("DELETE FROM player_ipid_sessions")
            conn.executemany(
                """
                INSERT INTO player_cache (
                    hdid, first_seen, last_seen, connect_count, failed_count, ip_count, last_ipid, last_ip,
                    is_hdid_banned, is_ip_banned, last_ooc_name, last_ic_name, last_char_name, last_hub_name,
                    ooc_names_text, ic_names_text, char_names_text, hub_names_text, ip_addresses_text, search_blob
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                rows,
            )
            conn.executemany("INSERT OR IGNORE INTO player_cache_ipids(hdid, ipid) VALUES (?, ?)", ipid_pairs)
            conn.executemany(
                "INSERT OR REPLACE INTO player_ipid_sessions(ipid, hdid, event_time) VALUES (?, ?, ?)",
                [(ipid, data[1], data[0]) for ipid, data in last_session_by_ipid.items()],
            )
            conn.execute(
                "INSERT INTO meta(key, value) VALUES ('player_cache_count', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                (str(len(rows)),),
            )
        self._seed_recent_activity_cache(source)
        source.close()
        self._store_source_state(source_state)
        self.set_meta("last_sync_at", now_ts())
        self.set_meta("last_sync_seconds", f"{time.time() - start:.2f}")
        self.set_meta("last_sync_mode", "full")
        return "full"

    def _incremental_sync_player_cache(self, prev_state: dict, source_state: dict) -> str:
        start = time.time()
        source = self.source_conn()
        changed_connect_rows: List[sqlite3.Row] = []
        if source_state["connect_rowid"] > prev_state["connect_rowid"]:
            changed_connect_rows = source.execute(
                "SELECT rowid, ipid, event_time, hdid, failed FROM connect_events WHERE rowid > ? ORDER BY rowid ASC",
                (prev_state["connect_rowid"],),
            ).fetchall()
        changed_area_rows: List[sqlite3.Row] = []
        if source_state["area_rowid"] > prev_state["area_rowid"]:
            changed_area_rows = source.execute(
                """
                SELECT ae.rowid, ae.event_time, ae.ipid, ae.ooc_name, ae.ic_name, ae.char_name, ae.hub_name,
                       aet.type_name, ae.area_name, ae.message
                FROM area_events ae
                LEFT JOIN area_event_types aet ON aet.type_id = ae.event_subtype
                WHERE ae.rowid > ?
                ORDER BY ae.rowid ASC
                """,
                (prev_state["area_rowid"],),
            ).fetchall()
        changed_misc_rows: List[sqlite3.Row] = []
        if source_state["misc_rowid"] > prev_state["misc_rowid"]:
            changed_misc_rows = source.execute(
                """
                SELECT me.rowid, me.event_time, me.ipid, met.type_name, me.event_data
                FROM misc_events me
                LEFT JOIN misc_event_types met ON met.type_id = me.event_subtype
                WHERE me.rowid > ?
                ORDER BY me.rowid ASC
                """,
                (prev_state["misc_rowid"],),
            ).fetchall()

        changed_hdids = {normalize_text(row["hdid"]) for row in changed_connect_rows if normalize_text(row["hdid"])}
        affected_ipids = sorted({int(row["ipid"]) for row in changed_connect_rows if row["ipid"] is not None} | {int(row["ipid"]) for row in changed_area_rows if row["ipid"] is not None} | {int(row["ipid"]) for row in changed_misc_rows if row["ipid"] is not None})

        sessions_by_ipid: Dict[int, List[Tuple[str, str]]] = defaultdict(list)
        if affected_ipids:
            placeholders = ", ".join("?" for _ in affected_ipids)
            with self.crm_conn() as crm:
                for row in crm.execute(
                    f"SELECT ipid, hdid, event_time FROM player_ipid_sessions WHERE ipid IN ({placeholders})",
                    affected_ipids,
                ).fetchall():
                    sessions_by_ipid[int(row["ipid"])].append((row["event_time"], normalize_text(row["hdid"])))
        for row in changed_connect_rows:
            hdid = normalize_text(row["hdid"])
            if hdid:
                sessions_by_ipid[int(row["ipid"])].append((row["event_time"], hdid))
        for ipid in list(sessions_by_ipid.keys()):
            sessions_by_ipid[ipid] = sorted(sessions_by_ipid[ipid], key=lambda item: item[0])

        def session_hdid_for(ipid: int, event_time: str) -> str:
            sessions = sessions_by_ipid.get(ipid, [])
            if not sessions:
                return ""
            candidate = ""
            for session_time, hdid in sessions:
                if session_time <= event_time:
                    candidate = hdid
                else:
                    break
            return candidate

        mapped_area_rows: List[Tuple[str, sqlite3.Row]] = []
        for row in changed_area_rows:
            hdid = session_hdid_for(int(row["ipid"]), row["event_time"])
            if hdid:
                changed_hdids.add(hdid)
                mapped_area_rows.append((hdid, row))

        mapped_misc_rows: List[Tuple[str, sqlite3.Row]] = []
        for row in changed_misc_rows:
            if row["ipid"] is None:
                continue
            hdid = session_hdid_for(int(row["ipid"]), row["event_time"])
            if hdid:
                changed_hdids.add(hdid)
                mapped_misc_rows.append((hdid, row))

        ban_changed = any(
            [
                source_state["bans_rowid"] != prev_state["bans_rowid"],
                source_state["hdid_bans_rowid"] != prev_state["hdid_bans_rowid"],
                source_state["ip_bans_rowid"] != prev_state["ip_bans_rowid"],
                source_state["bans_count"] != prev_state["bans_count"],
                source_state["hdid_bans_count"] != prev_state["hdid_bans_count"],
                source_state["ip_bans_count"] != prev_state["ip_bans_count"],
            ]
        )
        if ban_changed:
            changed_hdids.update(row[0] for row in source.execute("SELECT hdid FROM hdid_bans").fetchall())
            with self.crm_conn() as crm:
                changed_hdids.update(
                    row[0]
                    for row in crm.execute(
                        "SELECT hdid FROM player_cache WHERE is_hdid_banned = 1 OR is_ip_banned = 1"
                    ).fetchall()
                )
            banned_ipids = [int(row[0]) for row in source.execute("SELECT ipid FROM ip_bans").fetchall()]
            if banned_ipids:
                placeholders = ", ".join("?" for _ in banned_ipids)
                with self.crm_conn() as crm:
                    changed_hdids.update(
                        row[0]
                        for row in crm.execute(
                            f"SELECT DISTINCT hdid FROM player_cache_ipids WHERE ipid IN ({placeholders})",
                            banned_ipids,
                        ).fetchall()
                    )

        if not changed_hdids and not ban_changed and not changed_connect_rows and not changed_area_rows and not changed_misc_rows:
            source.close()
            self._store_source_state(source_state)
            self.set_meta("last_sync_at", now_ts())
            self.set_meta("last_sync_seconds", f"{time.time() - start:.2f}")
            self.set_meta("last_sync_mode", "incremental")
            return "incremental"

        summaries, ipids_by_hdid = self._load_existing_summaries(sorted(changed_hdids))
        ip_map = {}
        if affected_ipids:
            placeholders = ", ".join("?" for _ in affected_ipids)
            ip_map = {
                int(row[0]): row[1]
                for row in source.execute(f"SELECT ipid, ip_address FROM ipids WHERE ipid IN ({placeholders})", affected_ipids).fetchall()
            }

        connection_cache_items: List[Tuple[int, str, str, int, int, str]] = []
        log_cache_items: List[Tuple[str, str, int, str, str, int, str, str, str, str, str, str, str]] = []
        new_ipid_pairs: List[Tuple[str, int]] = []
        last_session_rows: Dict[int, Tuple[str, str]] = {}

        for row in changed_connect_rows:
            hdid = normalize_text(row["hdid"])
            if not hdid:
                continue
            summary = summaries.setdefault(hdid, self._base_summary())
            if not summary["known_ipids"]:
                summary["known_ipids"] = set(ipids_by_hdid.get(hdid, set()))
            ipid = int(row["ipid"])
            source_rowid = int(row["rowid"])
            event_time = row["event_time"]
            failed = int(row["failed"] or 0)
            ip_address = ip_map.get(ipid, "")
            summary["connect_count"] += 1
            summary["failed_count"] += failed
            if ipid not in summary["known_ipids"]:
                summary["known_ipids"].add(ipid)
                new_ipid_pairs.append((hdid, ipid))
            self._add_limited(summary["ip_addresses"], ip_address, SYNC_IP_LIMIT)
            if summary["first_seen"] is None or event_time < summary["first_seen"]:
                summary["first_seen"] = event_time
            if summary["last_seen"] is None or event_time > summary["last_seen"]:
                summary["last_seen"] = event_time
                summary["last_ipid"] = ipid
                summary["last_ip"] = ip_address
            last_session_rows[ipid] = (event_time, hdid)
            connection_cache_items.append((source_rowid, hdid, event_time, ipid, failed, ip_address))
            event_type = "connect.failed" if failed else "connect.ok"
            log_cache_items.append((
                f"connect:{source_rowid}", "connect", source_rowid, hdid, event_time, ipid, ip_address,
                event_type, self.classify_log_event(event_type), self.format_log_message(event_type, {}), "", "", ""
            ))

        for hdid, row in mapped_area_rows:
            summary = summaries.setdefault(hdid, self._base_summary())
            if not summary["known_ipids"]:
                summary["known_ipids"] = set(ipids_by_hdid.get(hdid, set()))
            event_time = row["event_time"]
            ooc_name = normalize_text(row["ooc_name"] or "")
            ic_name = normalize_text(row["ic_name"] or "")
            char_name = normalize_text(row["char_name"] or "")
            hub_name = normalize_text(row["hub_name"] or "")
            if ooc_name:
                self._add_limited(summary["ooc_names"], ooc_name, SYNC_ALIAS_LIMIT)
                if summary["last_ooc_at"] is None or event_time >= summary["last_ooc_at"]:
                    summary["last_ooc_at"] = event_time
                    summary["last_ooc_name"] = ooc_name
            if ic_name:
                self._add_limited(summary["ic_names"], ic_name, SYNC_ALIAS_LIMIT)
                if summary["last_ic_at"] is None or event_time >= summary["last_ic_at"]:
                    summary["last_ic_at"] = event_time
                    summary["last_ic_name"] = ic_name
            if char_name:
                self._add_limited(summary["char_names"], char_name, SYNC_ALIAS_LIMIT)
                if summary["last_char_at"] is None or event_time >= summary["last_char_at"]:
                    summary["last_char_at"] = event_time
                    summary["last_char_name"] = char_name
            if hub_name:
                self._add_limited(summary["hub_names"], hub_name, SYNC_HUB_LIMIT)
                if summary["last_hub_at"] is None or event_time >= summary["last_hub_at"]:
                    summary["last_hub_at"] = event_time
                    summary["last_hub_name"] = hub_name
            ipid = int(row["ipid"])
            ip_address = ip_map.get(ipid, "")
            event_type = normalize_text(row["type_name"] or "") or "area.unknown"
            payload = {
                "area_name": row["area_name"] or "",
                "char_name": char_name,
                "ooc_name": ooc_name,
                "ic_name": ic_name,
                "hub_name": hub_name,
                "message": row["message"] or "",
            }
            log_cache_items.append((
                f"area:{int(row['rowid'])}", "area", int(row["rowid"]), hdid, event_time, ipid, ip_address,
                event_type, self.classify_log_event(event_type), self.format_log_message(event_type, payload),
                ooc_name, char_name, hub_name
            ))

        for hdid, row in mapped_misc_rows:
            ipid = int(row["ipid"])
            ip_address = ip_map.get(ipid, "")
            event_type = normalize_text(row["type_name"] or "") or "misc.unknown"
            payload = {"event_data": row["event_data"] or ""}
            log_cache_items.append((
                f"misc:{int(row['rowid'])}", "misc", int(row["rowid"]), hdid, row["event_time"], ipid, ip_address,
                event_type, self.classify_log_event(event_type), self.format_log_message(event_type, payload),
                "", "", ""
            ))

        current_hdid_banned = {row[0] for row in source.execute("SELECT hdid FROM hdid_bans").fetchall()}
        current_ip_banned = {int(row[0]) for row in source.execute("SELECT ipid FROM ip_bans").fetchall()}
        source.close()
        for hdid, summary in summaries.items():
            summary["is_hdid_banned"] = 1 if hdid in current_hdid_banned else 0
            summary["is_ip_banned"] = 1 if summary["known_ipids"] & current_ip_banned else 0

        self._upsert_player_cache_rows(summaries, new_ipid_pairs)
        self._upsert_recent_activity(connection_cache_items, log_cache_items)
        if last_session_rows:
            with self.crm_conn() as conn:
                conn.executemany(
                    "INSERT OR REPLACE INTO player_ipid_sessions(ipid, hdid, event_time) VALUES (?, ?, ?)",
                    [(ipid, data[1], data[0]) for ipid, data in last_session_rows.items()],
                )
        self._store_source_state(source_state)
        self.set_meta("last_sync_at", now_ts())
        self.set_meta("last_sync_seconds", f"{time.time() - start:.2f}")
        self.set_meta("last_sync_mode", "incremental")
        return "incremental"

    def sync_player_cache(self, force_full: bool = False) -> None:
        started_at = now_ts()
        with self._sync_job_lock:
            mode = "full"
            try:
                source_state = self._current_source_state()
                previous_state = self._stored_source_state()
                if (
                    force_full
                    or not self._has_cache()
                    or self._schema_upgrade_required
                    or not self._has_incremental_state()
                    or any(
                        source_state[key] < previous_state.get(key, 0)
                        for key in ("connect_rowid", "area_rowid", "misc_rowid", "bans_rowid", "hdid_bans_rowid", "ip_bans_rowid")
                    )
                ):
                    mode = self._full_sync_player_cache(source_state)
                else:
                    mode = self._incremental_sync_player_cache(previous_state, source_state)
                self._sync_state = {
                    "status": "ok",
                    "mode": mode,
                    "message": "Кэш обновлён без остановки сайта",
                    "started_at": started_at,
                    "finished_at": now_ts(),
                }
            except Exception as exc:
                self._sync_state = {
                    "status": "error",
                    "mode": mode,
                    "message": str(exc),
                    "started_at": started_at,
                    "finished_at": now_ts(),
                }
                raise

    def stats(self) -> dict:
        sync = self.sync_status()
        with self.crm_conn() as conn:
            cache_count = conn.execute("SELECT COUNT(*) FROM player_cache").fetchone()[0]
            profiles_count = conn.execute("SELECT COUNT(*) FROM profiles").fetchone()[0]
            linked_count = conn.execute("SELECT COUNT(DISTINCT hdid) FROM profile_hdids").fetchone()[0]
            accounts_count = conn.execute("SELECT COUNT(*) FROM accounts").fetchone()[0]
            approved_accounts = conn.execute("SELECT COUNT(*) FROM accounts WHERE role = 'superadmin' OR can_access_panel = 1").fetchone()[0]
            pending_accounts = conn.execute("SELECT COUNT(*) FROM accounts WHERE role <> 'superadmin' AND can_access_panel = 0").fetchone()[0]
            hdid_bans = conn.execute("SELECT COUNT(*) FROM player_cache WHERE is_hdid_banned = 1").fetchone()[0]
            ip_bans = conn.execute("SELECT COUNT(*) FROM player_cache WHERE is_ip_banned = 1").fetchone()[0]
            return {
                "players": cache_count,
                "profiles": profiles_count,
                "linked_hdids": linked_count,
                "accounts": accounts_count,
                "approved_accounts": approved_accounts,
                "pending_accounts": pending_accounts,
                "hdid_bans": hdid_bans,
                "ip_bans": ip_bans,
                "last_sync_at": self.get_meta("last_sync_at") or "—",
                "last_sync_seconds": self.get_meta("last_sync_seconds") or "—",
                "last_sync_mode": self.get_meta("last_sync_mode") or "—",
                "sync_running": sync["running"],
                "sync_message": sync.get("message", ""),
                "sync_status": sync.get("status", "idle"),
            }

    def _players_where(self, query: str, only_profiled: bool, only_banned: bool) -> Tuple[str, List[str]]:
        clauses = ["1=1"]
        params: List[str] = []
        if only_profiled:
            clauses.append("EXISTS (SELECT 1 FROM profile_hdids ph WHERE ph.hdid = pc.hdid)")
        if only_banned:
            clauses.append("(pc.is_hdid_banned = 1 OR pc.is_ip_banned = 1)")
        tokens = [token.lower() for token in normalize_text(query).split() if token.strip()]
        for token in tokens:
            like = f"%{token}%"
            clauses.append(
                "(" + " OR ".join(
                    [
                        "LOWER(pc.hdid) LIKE ?",
                        "LOWER(COALESCE(pc.search_blob, '')) LIKE ?",
                        "LOWER(COALESCE(profile_data.profile_titles, '')) LIKE ?",
                        "LOWER(COALESCE(profile_data.profile_ooc_names, '')) LIKE ?",
                        "LOWER(COALESCE(profile_data.profile_discords, '')) LIKE ?",
                        "LOWER(COALESCE(profile_data.profile_tags, '')) LIKE ?",
                        "LOWER(COALESCE(profile_data.profile_notes, '')) LIKE ?",
                    ]
                ) + ")"
            )
            params.extend([like] * 7)
        return " AND ".join(clauses), params

    def list_players(
        self,
        query: str = "",
        page: int = 1,
        page_size: int = PAGE_SIZE,
        sort: str = "last_seen_desc",
        only_profiled: bool = False,
        only_banned: bool = False,
    ) -> dict:
        sort_sql = SORT_SQL.get(sort, SORT_SQL["last_seen_desc"])
        page = max(page, 1)
        offset = (page - 1) * page_size
        profile_cte = """
            WITH profile_data AS (
                SELECT
                    ph.hdid,
                    COUNT(DISTINCT ph.profile_id) AS profile_count,
                    GROUP_CONCAT(DISTINCT p.title) AS profile_titles,
                    GROUP_CONCAT(DISTINCT COALESCE(p.ooc_name, '')) AS profile_ooc_names,
                    GROUP_CONCAT(DISTINCT COALESCE(p.discord, '')) AS profile_discords,
                    GROUP_CONCAT(DISTINCT COALESCE(p.tags, '')) AS profile_tags,
                    GROUP_CONCAT(DISTINCT COALESCE(p.notes, '')) AS profile_notes
                FROM profile_hdids ph
                JOIN profiles p ON p.id = ph.profile_id
                GROUP BY ph.hdid
            )
        """
        where_sql, params = self._players_where(query, only_profiled, only_banned)
        base_from = f"FROM player_cache pc LEFT JOIN profile_data ON profile_data.hdid = pc.hdid WHERE {where_sql}"
        with self.crm_conn() as conn:
            total = conn.execute(profile_cte + f" SELECT COUNT(*) {base_from}", params).fetchone()[0]
            rows = conn.execute(
                profile_cte
                + f"""
                SELECT pc.*, COALESCE(profile_data.profile_count, 0) AS profile_count,
                       COALESCE(profile_data.profile_titles, '') AS profile_titles
                {base_from}
                ORDER BY {sort_sql}
                LIMIT ? OFFSET ?
                """,
                params + [page_size, offset],
            ).fetchall()
        return {
            "items": rows,
            "total": total,
            "page": page,
            "pages": max(1, (total + page_size - 1) // page_size),
            "page_size": page_size,
        }

    def export_players_csv(self, query: str = "", sort: str = "last_seen_desc", only_profiled: bool = False, only_banned: bool = False) -> str:
        sort_sql = SORT_SQL.get(sort, SORT_SQL["last_seen_desc"])
        profile_cte = """
            WITH profile_data AS (
                SELECT ph.hdid, COUNT(DISTINCT ph.profile_id) AS profile_count,
                       GROUP_CONCAT(DISTINCT p.title) AS profile_titles
                FROM profile_hdids ph
                JOIN profiles p ON p.id = ph.profile_id
                GROUP BY ph.hdid
            )
        """
        where_sql, params = self._players_where(query, only_profiled, only_banned)
        sql = (
            profile_cte
            + f"""
            SELECT pc.hdid, pc.first_seen, pc.last_seen, pc.connect_count, pc.failed_count, pc.ip_count,
                   pc.last_ip, pc.is_hdid_banned, pc.is_ip_banned, pc.last_ooc_name, pc.last_ic_name,
                   pc.last_char_name, pc.last_hub_name, pc.ooc_names_text, pc.ic_names_text,
                   pc.char_names_text, pc.hub_names_text, pc.ip_addresses_text,
                   COALESCE(profile_data.profile_count, 0) AS profile_count,
                   COALESCE(profile_data.profile_titles, '') AS profile_titles
            FROM player_cache pc
            LEFT JOIN profile_data ON profile_data.hdid = pc.hdid
            WHERE {where_sql}
            ORDER BY {sort_sql}
            """
        )
        output = io.StringIO()
        writer = csv.writer(output)
        writer.writerow([
            "hdid", "first_seen", "last_seen", "connect_count", "failed_count", "ip_count",
            "last_ip", "is_hdid_banned", "is_ip_banned", "last_ooc_name", "last_ic_name",
            "last_char_name", "last_hub_name", "ooc_names", "ic_names", "char_names", "hub_names",
            "ip_addresses", "profile_count", "profile_titles"
        ])
        with self.crm_conn() as conn:
            for row in conn.execute(sql, params):
                writer.writerow(row)
        return output.getvalue()

    def get_player(self, hdid: str) -> Optional[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                """
                SELECT pc.*,
                       EXISTS (SELECT 1 FROM profile_hdids ph WHERE ph.hdid = pc.hdid) AS is_profiled
                FROM player_cache pc
                WHERE pc.hdid = ?
                """,
                (hdid,),
            ).fetchone()

    def get_primary_profile_for_hdid(self, hdid: str) -> Optional[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                """
                SELECT p.*, ph.is_primary
                FROM profile_hdids ph
                JOIN profiles p ON p.id = ph.profile_id
                WHERE ph.hdid = ?
                ORDER BY ph.is_primary DESC, p.updated_at DESC, p.id DESC
                LIMIT 1
                """,
                (hdid,),
            ).fetchone()

    def get_player_profiles(self, hdid: str) -> List[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                """
                SELECT p.*, ph.is_primary
                FROM profile_hdids ph
                JOIN profiles p ON p.id = ph.profile_id
                WHERE ph.hdid = ?
                ORDER BY ph.is_primary DESC, p.updated_at DESC
                """,
                (hdid,),
            ).fetchall()

    def get_player_connections(self, hdid: str, limit: int = 30) -> List[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                """
                SELECT event_time, ipid, failed, ip_address
                FROM player_recent_connections
                WHERE hdid = ?
                ORDER BY event_time DESC, source_rowid DESC
                LIMIT ?
                """,
                (hdid, limit),
            ).fetchall()

    def resolve_actor_identity(self, conn: sqlite3.Connection, ipid: Optional[int]) -> str:
        if not ipid:
            return "—"
        with self.crm_conn() as crm:
            row = crm.execute(
                """
                SELECT pc.last_ooc_name, pc.last_char_name, pc.last_ic_name, pc.last_ip
                FROM player_cache_ipids pci
                JOIN player_cache pc ON pc.hdid = pci.hdid
                WHERE pci.ipid = ?
                ORDER BY pc.last_seen DESC
                LIMIT 1
                """,
                (ipid,),
            ).fetchone()
        if row:
            parts = [normalize_text(row[0] or ""), normalize_text(row[1] or ""), normalize_text(row[2] or "")]
            parts = [part for part in parts if part]
            if parts:
                return " / ".join(parts[:2])
            if row[3]:
                return f"IPID {ipid} · {row[3]}"
        ip_row = conn.execute("SELECT ip_address FROM ipids WHERE ipid = ?", (ipid,)).fetchone()
        if ip_row and ip_row[0]:
            return f"IPID {ipid} · {ip_row[0]}"
        return f"IPID {ipid}"

    def get_player_bans(self, hdid: str) -> List[dict]:
        with self.crm_conn() as crm:
            ipids = [int(row[0]) for row in crm.execute(
                "SELECT ipid FROM player_cache_ipids WHERE hdid = ? ORDER BY ipid ASC",
                (hdid,),
            ).fetchall()]
        with self.source_conn() as conn:
            items: List[dict] = []
            seen = set()

            for row in conn.execute(
                """
                SELECT 'hdid' AS ban_scope, hb.hdid AS target_value, b.ban_id, b.ban_date, b.unban_date, b.banned_by, b.reason
                FROM hdid_bans hb
                JOIN bans b ON b.ban_id = hb.ban_id
                WHERE hb.hdid = ?
                ORDER BY b.ban_date DESC
                """,
                (hdid,),
            ).fetchall():
                key = (row[2], row[0], row[1])
                if key in seen:
                    continue
                seen.add(key)
                items.append({
                    "scope": row[0],
                    "target": row[1],
                    "ban_id": row[2],
                    "ban_date": row[3],
                    "unban_date": row[4],
                    "banned_by": row[5],
                    "banned_by_name": self.resolve_actor_identity(conn, row[5]),
                    "reason": normalize_text(row[6] or "") or "Причина не указана",
                })

            if ipids:
                placeholders = ", ".join("?" for _ in ipids)
                for row in conn.execute(
                    f"""
                    SELECT 'ip' AS ban_scope, CAST(ib.ipid AS TEXT) AS target_value, b.ban_id, b.ban_date, b.unban_date, b.banned_by, b.reason
                    FROM ip_bans ib
                    JOIN bans b ON b.ban_id = ib.ban_id
                    WHERE ib.ipid IN ({placeholders})
                    ORDER BY b.ban_date DESC
                    """,
                    ipids,
                ).fetchall():
                    key = (row[2], row[0], row[1])
                    if key in seen:
                        continue
                    seen.add(key)
                    target_ipid = int(row[1])
                    ip_row = conn.execute("SELECT ip_address FROM ipids WHERE ipid = ?", (target_ipid,)).fetchone()
                    target_label = f"IPID {target_ipid}"
                    if ip_row and ip_row[0]:
                        target_label += f" · {ip_row[0]}"
                    items.append({
                        "scope": row[0],
                        "target": target_label,
                        "ban_id": row[2],
                        "ban_date": row[3],
                        "unban_date": row[4],
                        "banned_by": row[5],
                        "banned_by_name": self.resolve_actor_identity(conn, row[5]),
                        "reason": normalize_text(row[6] or "") or "Причина не указана",
                    })

        items.sort(key=lambda item: item.get("ban_date") or "", reverse=True)
        return items

    def classify_log_event(self, event_type: str) -> str:
        name = normalize_text(event_type or "").lower()
        if not name:
            return "other"
        if name.startswith("chat."):
            return "chat"
        if name.startswith("connect."):
            return "system"
        moderation_tokens = (
            "ban", "kick", "mute", "mod", "announce", "lockdown", "release", "invite", "gm.", "cm.", "vote"
        )
        if any(token in name for token in moderation_tokens):
            return "moderation"
        action_prefixes = (
            "area.", "char.", "doc.", "music", "status", "roll", "coinflip", "wtce", "play", "hp", "bg",
            "overlay", "evidence", "case", "notecard", "jukebox", "desc.", "chardesc.", "forcepos", "minigame"
        )
        if name.startswith(action_prefixes) or name in {"info.request", "area.pref", "ooc_unmute", "ooc_mute"}:
            return "action"
        if name in {"start", "stop", "refresh", "login", "login.invalid", "webhook.ok", "webhook.err", "kms"}:
            return "system"
        return "other"

    def format_log_message(self, event_type: str, payload: dict) -> str:
        event_type = normalize_text(event_type or "").lower()
        if event_type.startswith("connect."):
            return "Подключение успешно" if event_type == "connect.ok" else "Неуспешное подключение"
        if event_type.startswith("chat."):
            message = normalize_text(payload.get("message") or "")
            return message or "Сообщение без текста"
        if event_type == "area.join":
            return f"Вошёл в область {payload.get('area_name') or '—'}"
        if event_type == "area.leave":
            return f"Покинул область {payload.get('area_name') or '—'}"
        if event_type == "char.change":
            return normalize_text(payload.get("message") or "") or f"Сменил персонажа на {payload.get('char_name') or '—'}"
        if event_type in {"ban", "unban", "kick", "mute", "unmute"}:
            return normalize_text(payload.get("event_data") or "") or "Системное действие модерации"
        return normalize_text(payload.get("message") or payload.get("event_data") or "") or "Системное событие"

    def get_player_logs(self, hdid: str, log_filter: str = "all", query: str = "", limit: int = 60) -> List[dict]:
        with self.crm_conn() as conn:
            rows = conn.execute(
                """
                SELECT event_time, ipid, ip_address AS ip, event_type, category, message, ooc_name, char_name, hub_name
                FROM player_recent_logs
                WHERE hdid = ?
                ORDER BY event_time DESC, source_rowid DESC
                LIMIT ?
                """,
                (hdid, RECENT_LOG_CACHE_LIMIT),
            ).fetchall()
        search_tokens = [token.lower() for token in normalize_text(query).split() if token]
        items: List[dict] = []
        for row in rows:
            item = dict(row)
            if log_filter != "all" and item["category"] != log_filter:
                continue
            if search_tokens:
                blob = " ".join(
                    [
                        str(item.get("event_type") or ""),
                        str(item.get("message") or ""),
                        str(item.get("ooc_name") or ""),
                        str(item.get("char_name") or ""),
                        str(item.get("hub_name") or ""),
                        str(item.get("ip") or ""),
                    ]
                ).lower()
                if not all(token in blob for token in search_tokens):
                    continue
            items.append(item)
            if len(items) >= limit:
                break
        return items

    def get_player_access_rule(self, hdid: str) -> Optional[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                """
                SELECT r.hdid, r.requires_ga_accept, r.updated_at,
                       a.username AS set_by_username,
                       COALESCE(NULLIF(a.display_name, ''), a.username, '—') AS set_by_display_name
                FROM player_access_rules r
                LEFT JOIN accounts a ON a.id = r.set_by_account_id
                WHERE r.hdid = ?
                """,
                (hdid,),
            ).fetchone()

    def set_player_access_rule(self, hdid: str, requires_ga_accept: bool, actor_account_id: int) -> None:
        stamp = now_ts()
        self._run_write_transaction(
            lambda conn: conn.execute(
                """
                INSERT INTO player_access_rules(hdid, requires_ga_accept, set_by_account_id, updated_at)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(hdid) DO UPDATE SET
                    requires_ga_accept = excluded.requires_ga_accept,
                    set_by_account_id = excluded.set_by_account_id,
                    updated_at = excluded.updated_at
                """,
                (hdid, 1 if requires_ga_accept else 0, actor_account_id, stamp),
            )
        )

    def get_latest_player_access_request(self, hdid: str, account_id: int) -> Optional[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                """
                SELECT r.id, r.hdid, r.requester_account_id, r.status, r.created_at, r.updated_at,
                       r.resolved_at, r.resolved_by_account_id, r.note,
                       COALESCE(NULLIF(req.display_name, ''), req.username, '—') AS requester_display_name,
                       req.username AS requester_username,
                       COALESCE(NULLIF(res.display_name, ''), res.username, '—') AS resolver_display_name,
                       res.username AS resolver_username
                FROM player_access_requests r
                JOIN accounts req ON req.id = r.requester_account_id
                LEFT JOIN accounts res ON res.id = r.resolved_by_account_id
                WHERE r.hdid = ? AND r.requester_account_id = ?
                ORDER BY r.id DESC
                LIMIT 1
                """,
                (hdid, account_id),
            ).fetchone()

    def has_active_player_access_grant(self, account_id: int, hdid: str, not_before: str = '') -> bool:
        row = self.get_latest_player_access_request(hdid, account_id)
        if not row or str(row['status']) != 'approved':
            return False
        approved_at = normalize_text(row['resolved_at'] or row['updated_at'] or row['created_at'])
        if not_before and approved_at and approved_at < not_before:
            return False
        # Один аццепт даёт право только на один старт рабочей сессии.
        # Даже если статус запроса по какой-то причине остался approved,
        # повторный вход по тому же самому одобрению быть не должен.
        with self.crm_conn() as conn:
            params = [account_id, hdid]
            sql = """
                SELECT 1
                FROM player_card_sessions
                WHERE account_id = ?
                  AND hdid = ?
            """
            if approved_at:
                sql += " AND started_at >= ?"
                params.append(approved_at)
            if not_before:
                sql += " AND started_at >= ?"
                params.append(not_before)
            sql += " ORDER BY id DESC LIMIT 1"
            session_row = conn.execute(sql, tuple(params)).fetchone()
        if session_row:
            return False
        return True

    def has_active_player_card_session(self, account_id: int, hdid: str, not_before: str = '') -> bool:
        with self.crm_conn() as conn:
            params = [account_id, hdid, f'-{max(30, int(PLAYER_CARD_SESSION_HEARTBEAT_SECONDS))} seconds']
            sql = """
                SELECT 1
                FROM player_card_sessions
                WHERE account_id = ?
                  AND hdid = ?
                  AND ended_at IS NULL
                  AND COALESCE(last_seen_at, started_at) >= datetime('now', ?)
            """
            if not_before:
                sql += " AND started_at >= ?"
                params.append(not_before)
            sql += " ORDER BY id DESC LIMIT 1"
            row = conn.execute(sql, tuple(params)).fetchone()
        return bool(row)

    def touch_player_card_session(self, hdid: str, account_id: int) -> None:
        stamp = now_ts()

        def writer(conn: sqlite3.Connection) -> None:
            conn.execute(
                """
                UPDATE player_card_sessions
                SET last_seen_at = ?
                WHERE account_id = ? AND hdid = ? AND ended_at IS NULL
                """,
                (stamp, account_id, hdid),
            )

        self._run_write_transaction(writer, retries=2)

    def end_player_card_session(self, hdid: str, account_id: int, reason: str = 'left_card') -> None:
        stamp = now_ts()

        def writer(conn: sqlite3.Connection) -> None:
            conn.execute(
                """
                UPDATE player_card_sessions
                SET ended_at = ?, ended_reason = ?, last_seen_at = COALESCE(last_seen_at, ?)
                WHERE account_id = ? AND hdid = ? AND ended_at IS NULL
                """,
                (stamp, normalize_text(reason or 'left_card')[:64], stamp, account_id, hdid),
            )

        self._run_write_transaction(writer, retries=2)

    def end_all_player_card_sessions(self, account_id: int, reason: str = 'left_card') -> None:
        stamp = now_ts()

        def writer(conn: sqlite3.Connection) -> None:
            conn.execute(
                """
                UPDATE player_card_sessions
                SET ended_at = ?, ended_reason = ?, last_seen_at = COALESCE(last_seen_at, ?)
                WHERE account_id = ? AND ended_at IS NULL
                """,
                (stamp, normalize_text(reason or 'left_card')[:64], stamp, account_id),
            )

        self._run_write_transaction(writer, retries=2)

    def request_player_access(self, hdid: str, requester_account_id: int) -> Tuple[str, Optional[int]]:
        existing = self.get_latest_player_access_request(hdid, requester_account_id)
        if existing and str(existing['status']) in {'pending', 'approved'}:
            return str(existing['status']), int(existing['id'])
        stamp = now_ts()

        def writer(conn: sqlite3.Connection) -> int:
            row = conn.execute(
                """
                SELECT id, status FROM player_access_requests
                WHERE hdid = ? AND requester_account_id = ?
                ORDER BY id DESC
                LIMIT 1
                """,
                (hdid, requester_account_id),
            ).fetchone()
            if row and str(row['status']) in {'pending', 'approved'}:
                return int(row['id'])
            return int(conn.execute(
                """
                INSERT INTO player_access_requests(hdid, requester_account_id, status, created_at, updated_at)
                VALUES (?, ?, 'pending', ?, ?)
                """,
                (hdid, requester_account_id, stamp, stamp),
            ).lastrowid)

        request_id = self._run_write_transaction(writer, retries=2)
        latest = self.get_latest_player_access_request(hdid, requester_account_id)
        return (str(latest['status']) if latest else 'pending'), int(request_id)

    def get_pending_player_access_requests(self, hdid: str, limit: int = 12) -> List[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                """
                SELECT r.id, r.hdid, r.status, r.created_at, r.updated_at,
                       COALESCE(NULLIF(req.display_name, ''), req.username, '—') AS requester_display_name,
                       req.username AS requester_username,
                       req.role AS requester_role
                FROM player_access_requests r
                JOIN accounts req ON req.id = r.requester_account_id
                WHERE r.hdid = ? AND r.status = 'pending'
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT ?
                """,
                (hdid, limit),
            ).fetchall()

    def resolve_player_access_request(self, request_id: int, resolver_account_id: int, approve: bool) -> Optional[str]:
        stamp = now_ts()
        status = 'approved' if approve else 'denied'
        note = 'Одноразовый вход разрешён главным администратором.' if approve else 'Запрос отклонён главным администратором.'

        def writer(conn: sqlite3.Connection) -> Optional[str]:
            row = conn.execute(
                'SELECT hdid, requester_account_id, status FROM player_access_requests WHERE id = ?',
                (request_id,),
            ).fetchone()
            if not row or str(row['status']) != 'pending':
                return None
            conn.execute(
                """
                UPDATE player_access_requests
                SET status = ?, updated_at = ?, resolved_at = ?, resolved_by_account_id = ?, note = ?
                WHERE id = ?
                """,
                (status, stamp, stamp, resolver_account_id, note, request_id),
            )
            return normalize_text(row['hdid'])

        return self._run_write_transaction(writer, retries=2)

    def can_open_player_card(self, account: Optional[sqlite3.Row], hdid: str) -> bool:
        if not account:
            return False
        account_id = int(account['id'])
        if str(account['role']) == 'superadmin':
            return True
        rule = self.get_player_access_rule(hdid)
        if not rule or int(rule['requires_ga_accept'] or 0) == 0:
            return True
        rule_since = normalize_text(rule['updated_at'] or rule['created_at'] or '')
        if self.has_active_player_card_session(account_id, hdid, not_before=rule_since):
            return True
        return self.has_active_player_access_grant(account_id, hdid, not_before=rule_since)

    def create_player_card_session(self, hdid: str, account_id: int, request_ip: str = '', user_agent: str = '') -> None:
        stamp = now_ts()
        account = self.get_account_by_id(int(account_id))

        def writer(conn: sqlite3.Connection) -> None:
            conn.execute(
                """
                UPDATE player_card_sessions
                SET ended_at = ?, ended_reason = 'superseded', last_seen_at = COALESCE(last_seen_at, ?)
                WHERE account_id = ? AND hdid = ? AND ended_at IS NULL
                """,
                (stamp, stamp, account_id, hdid),
            )
            conn.execute(
                """
                INSERT INTO player_card_sessions(hdid, account_id, started_at, last_seen_at, request_ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?)
                """,
                (hdid, account_id, stamp, stamp, normalize_text(request_ip)[:255], normalize_text(user_agent)[:255]),
            )
            if account and str(account['role']) != 'superadmin':
                conn.execute(
                    """
                    UPDATE player_access_requests
                    SET status = 'consumed', updated_at = ?, resolved_at = COALESCE(resolved_at, ?), note = COALESCE(note, 'Разовый вход использован.')
                    WHERE hdid = ? AND requester_account_id = ? AND status = 'approved'
                    """,
                    (stamp, stamp, hdid, account_id),
                )

        self._run_write_transaction(writer, retries=2)

    def get_player_card_sessions(self, hdid: str, limit: int = SESSION_HISTORY_LIMIT) -> List[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                """
                SELECT pcs.started_at, pcs.request_ip, pcs.user_agent,
                       a.username,
                       COALESCE(NULLIF(a.display_name, ''), a.username, '—') AS display_name,
                       a.role
                FROM player_card_sessions pcs
                JOIN accounts a ON a.id = pcs.account_id
                WHERE pcs.hdid = ?
                ORDER BY pcs.started_at DESC, pcs.id DESC
                LIMIT ?
                """,
                (hdid, limit),
            ).fetchall()

    def count_player_card_sessions(self, hdid: str) -> int:
        with self.crm_conn() as conn:
            row = conn.execute(
                "SELECT COUNT(*) AS total FROM player_card_sessions WHERE hdid = ?",
                (hdid,),
            ).fetchone()
        return int(row[0]) if row else 0

    def list_player_logs(self, hdid: str, log_filter: str = 'all', query: str = '', page: int = 1, page_size: int = LOGS_PAGE_SIZE) -> dict:
        with self.crm_conn() as conn:
            rows = conn.execute(
                """
                SELECT event_time, ipid, ip_address AS ip, event_type, category, message, ooc_name, char_name, hub_name
                FROM player_recent_logs
                WHERE hdid = ?
                ORDER BY event_time DESC, source_rowid DESC
                LIMIT ?
                """,
                (hdid, RECENT_LOG_CACHE_LIMIT),
            ).fetchall()
        search_tokens = [token.lower() for token in normalize_text(query).split() if token]
        items: List[dict] = []
        for row in rows:
            item = dict(row)
            if log_filter != 'all' and item['category'] != log_filter:
                continue
            if search_tokens:
                blob = ' '.join(
                    [
                        str(item.get('event_type') or ''),
                        str(item.get('message') or ''),
                        str(item.get('ooc_name') or ''),
                        str(item.get('char_name') or ''),
                        str(item.get('hub_name') or ''),
                        str(item.get('ip') or ''),
                    ]
                ).lower()
                if not all(token in blob for token in search_tokens):
                    continue
            items.append(item)
        total = len(items)
        page = max(1, page)
        pages = max(1, (total + page_size - 1) // page_size)
        if page > pages:
            page = pages
        start = (page - 1) * page_size
        end = start + page_size
        return {
            'items': items[start:end],
            'total': total,
            'page': page,
            'pages': pages,
            'page_size': page_size,
        }

    def list_profiles(self, query: str = "", page: int = 1, page_size: int = PAGE_SIZE, sort: str = "updated_desc") -> dict:
        sort_sql = PROFILE_SORT_SQL.get(sort, PROFILE_SORT_SQL["updated_desc"])
        page = max(page, 1)
        offset = (page - 1) * page_size
        clauses = ["1=1"]
        params: List[str] = []
        for token in [token.lower() for token in normalize_text(query).split() if token.strip()]:
            like = f"%{token}%"
            clauses.append(
                "(" + " OR ".join(
                    [
                        "LOWER(p.title) LIKE ?",
                        "LOWER(COALESCE(p.ooc_name, '')) LIKE ?",
                        "LOWER(COALESCE(p.discord, '')) LIKE ?",
                        "LOWER(COALESCE(p.status, '')) LIKE ?",
                        "LOWER(COALESCE(p.risk_level, '')) LIKE ?",
                        "LOWER(COALESCE(p.tags, '')) LIKE ?",
                        "LOWER(COALESCE(p.notes, '')) LIKE ?",
                        "LOWER(COALESCE(hdids_blob.hdids_text, '')) LIKE ?",
                    ]
                ) + ")"
            )
            params.extend([like] * 8)
        where_sql = " AND ".join(clauses)
        cte = """
            WITH hdids_blob AS (
                SELECT profile_id,
                       COUNT(*) AS hdid_count,
                       GROUP_CONCAT(hdid, ', ') AS hdids_text
                FROM profile_hdids
                GROUP BY profile_id
            )
        """
        with self.crm_conn() as conn:
            total = conn.execute(
                cte + f" SELECT COUNT(*) FROM profiles p LEFT JOIN hdids_blob ON hdids_blob.profile_id = p.id WHERE {where_sql}",
                params,
            ).fetchone()[0]
            rows = conn.execute(
                cte
                + f"""
                SELECT p.*, COALESCE(hdids_blob.hdid_count, 0) AS hdid_count,
                       COALESCE(hdids_blob.hdids_text, '') AS hdids_text
                FROM profiles p
                LEFT JOIN hdids_blob ON hdids_blob.profile_id = p.id
                WHERE {where_sql}
                ORDER BY {sort_sql}
                LIMIT ? OFFSET ?
                """,
                params + [page_size, offset],
            ).fetchall()
        return {
            "items": rows,
            "total": total,
            "page": page,
            "pages": max(1, (total + page_size - 1) // page_size),
            "page_size": page_size,
        }

    def get_profile(self, profile_id: int) -> Optional[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute("SELECT * FROM profiles WHERE id = ?", (profile_id,)).fetchone()

    def get_profile_hdids(self, profile_id: int) -> List[sqlite3.Row]:
        with self.crm_conn() as conn:
            return conn.execute(
                """
                SELECT ph.hdid, ph.is_primary, pc.last_seen, pc.last_ooc_name, pc.last_ip
                FROM profile_hdids ph
                LEFT JOIN player_cache pc ON pc.hdid = ph.hdid
                WHERE ph.profile_id = ?
                ORDER BY ph.is_primary DESC, pc.last_seen DESC, ph.hdid ASC
                """,
                (profile_id,),
            ).fetchall()

    def save_profile(self, payload: dict) -> int:
        profile_id = int(payload.get("id") or 0)
        title = normalize_text(payload.get("title") or "")
        ooc_name = normalize_text(payload.get("ooc_name") or "")
        discord = normalize_text(payload.get("discord") or "")
        status = normalize_text(payload.get("status") or "new") or "new"
        risk_level = normalize_text(payload.get("risk_level") or "medium") or "medium"
        tags = normalize_text(payload.get("tags") or "")
        notes = normalize_text(payload.get("notes") or "")
        hdids = parse_multi_value_field(payload.get("hdids") or "")
        if not title:
            title = ooc_name or (hdids[0] if hdids else "Новый профиль")
        stamp = now_ts()

        def writer(conn: sqlite3.Connection) -> int:
            nonlocal profile_id
            if not profile_id and hdids:
                placeholders = ", ".join("?" for _ in hdids)
                existing_row = conn.execute(
                    f"SELECT profile_id FROM profile_hdids WHERE hdid IN ({placeholders}) ORDER BY is_primary DESC, profile_id DESC LIMIT 1",
                    hdids,
                ).fetchone()
                if existing_row:
                    profile_id = int(existing_row[0])
            if profile_id:
                conn.execute(
                    """
                    UPDATE profiles
                    SET title = ?, ooc_name = ?, discord = ?, status = ?, risk_level = ?, tags = ?, notes = ?, updated_at = ?
                    WHERE id = ?
                    """,
                    (title, ooc_name, discord, status, risk_level, tags, notes, stamp, profile_id),
                )
            else:
                profile_id = int(conn.execute(
                    """
                    INSERT INTO profiles(title, ooc_name, discord, status, risk_level, tags, notes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    (title, ooc_name, discord, status, risk_level, tags, notes, stamp, stamp),
                ).lastrowid)
            conn.execute("DELETE FROM profile_hdids WHERE profile_id = ?", (profile_id,))
            for index, hdid in enumerate(hdids):
                conn.execute("DELETE FROM profile_hdids WHERE hdid = ? AND profile_id <> ?", (hdid, profile_id))
                conn.execute(
                    "INSERT INTO profile_hdids(profile_id, hdid, is_primary) VALUES (?, ?, ?)",
                    (profile_id, hdid, 1 if index == 0 else 0),
                )
            return int(profile_id)

        return self._run_write_transaction(writer)

    def delete_profile(self, profile_id: int) -> None:
        self._run_write_transaction(lambda conn: (conn.execute("DELETE FROM profile_hdids WHERE profile_id = ?", (profile_id,)), conn.execute("DELETE FROM profiles WHERE id = ?", (profile_id,))))


class CRMRequestHandler(BaseHTTPRequestHandler):
    server_version = "FoCCRM/1.0"

    @property
    def repo(self) -> CRMRepository:
        return self.server.repo  # type: ignore[attr-defined]

    @property
    def secret_key(self) -> str:
        return self.server.secret_key  # type: ignore[attr-defined]

    def parse_query(self, parsed) -> dict:
        raw = parse_qs(parsed.query)
        return {key: values[-1] for key, values in raw.items() if values}

    def get_session_token(self) -> str:
        cookie_header = self.headers.get("Cookie")
        if not cookie_header:
            return ""
        jar = cookies.SimpleCookie()
        jar.load(cookie_header)
        token = jar.get(SESSION_COOKIE_NAME)
        return token.value if token else ""

    def current_user(self) -> Optional[sqlite3.Row]:
        return self.repo.get_account_by_session(self.get_session_token())

    def is_superadmin(self, user: Optional[sqlite3.Row]) -> bool:
        return bool(user and str(user["role"]) == "superadmin")

    def set_session_cookie(self, token: str) -> str:
        return f"{SESSION_COOKIE_NAME}={token}; Path=/; HttpOnly; SameSite=Lax"

    def clear_session_cookie(self) -> str:
        return f"{SESSION_COOKIE_NAME}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0"

    def request_ip(self) -> str:
        forwarded = self.headers.get("X-Forwarded-For", "")
        if forwarded:
            return forwarded.split(",", 1)[0].strip()
        return self.client_address[0] if self.client_address else ""

    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        path = parsed.path
        if path.startswith("/static/"):
            self.serve_static(path)
            return

        public_routes = {"/login", "/register", "/logout"}
        user = self.current_user()

        if path == "/logout":
            if user:
                try:
                    self.repo.end_all_player_card_sessions(int(user['id']), reason='logout')
                except sqlite3.OperationalError:
                    pass
            self.handle_logout()
            return

        if path in public_routes:
            if user:
                if self.repo.has_panel_access(user):
                    self.redirect("/players")
                else:
                    self.redirect("/awaiting-access")
                return
            if path == "/login":
                self.render_login_page()
            elif path == "/register":
                self.render_register_page()
            return

        if not user:
            self.redirect("/login")
            return

        if not self.repo.has_panel_access(user):
            if path == "/awaiting-access":
                self.render_pending_access_page(user)
            else:
                self.redirect("/awaiting-access")
            return

        self.repo.maybe_start_auto_sync()
        active_card_route = path.startswith('/players/') and self.parse_query(parsed).get('open') == '1'
        if not active_card_route:
            try:
                self.repo.end_all_player_card_sessions(int(user['id']), reason='navigated_away')
            except sqlite3.OperationalError:
                pass
        if path == "/awaiting-access":
            self.redirect("/players")
        elif path == "/" or path == "/players":
            self.render_players(parsed)
        elif path == "/players/export.csv":
            self.render_players_csv(parsed)
        elif path.startswith("/players/"):
            tail = unquote(path.split("/players/", 1)[1])
            if tail.endswith('/preview'):
                self.render_player_preview_fragment(unquote(tail[:-8]))
            else:
                if self.parse_query(parsed).get('open') == '1':
                    self.render_player_detail(parsed, tail)
                else:
                    self.render_player_preview_page(parsed, tail)
        elif path == "/profiles":
            self.render_profiles(parsed)
        elif path == "/profiles/new":
            self.render_profile_form(parsed, None)
        elif path.startswith("/profiles/"):
            tail = path.split("/profiles/", 1)[1]
            if tail.isdigit():
                self.render_profile_form(parsed, int(tail))
            else:
                self.not_found()
        elif path == "/users":
            if not self.is_superadmin(user):
                self.not_found()
            else:
                self.render_users_page(parsed)
        else:
            self.not_found()

    def do_POST(self) -> None:
        parsed = urlparse(self.path)
        path = parsed.path
        content_length = int(self.headers.get("Content-Length", "0") or "0")
        body = self.rfile.read(content_length).decode("utf-8") if content_length else ""
        form = {key: values[-1] for key, values in parse_qs(body, keep_blank_values=True).items()}

        if path == "/login":
            self.handle_login(form)
            return
        if path == "/register":
            self.handle_register(form)
            return
        if path == "/logout":
            self.handle_logout()
            return

        user = self.current_user()
        if not user:
            self.redirect("/login")
            return
        if not self.repo.has_panel_access(user):
            self.redirect("/awaiting-access")
            return

        if path == "/sync":
            force_full = form.get("mode") == "full"
            self.repo.start_background_sync(force_full=force_full)
            target = form.get("next") or "/players"
            self.redirect(target)
        elif path == "/profiles/save":
            profile_id = self.repo.save_profile(form)
            self.redirect(f"/profiles/{profile_id}")
        elif path == "/profiles/delete":
            profile_id = int(form.get("id") or 0)
            if profile_id:
                self.repo.delete_profile(profile_id)
            self.redirect("/profiles")
        elif path == "/users/access":
            if not self.is_superadmin(user):
                self.not_found()
                return
            account_id = int(form.get("id") or 0)
            grant = form.get("grant") == "1"
            if account_id:
                self.repo.set_account_access(account_id, grant, int(user["id"]))
            self.redirect("/users")
        elif path == '/players/session/ping':
            hdid = normalize_text(form.get('hdid') or '')
            if hdid:
                try:
                    self.repo.touch_player_card_session(hdid, int(user['id']))
                except sqlite3.OperationalError:
                    pass
            self.text_response('', status=204)
        elif path == '/players/session/end':
            hdid = normalize_text(form.get('hdid') or '')
            if hdid:
                try:
                    self.repo.end_player_card_session(hdid, int(user['id']), reason=normalize_text(form.get('reason') or 'left_card'))
                except sqlite3.OperationalError:
                    self.text_response('session_end_failed', status=503)
                    return
            self.text_response('', status=204)
        elif path == '/players/session/end-all':
            try:
                self.repo.end_all_player_card_sessions(int(user['id']), reason=normalize_text(form.get('reason') or 'left_card'))
            except sqlite3.OperationalError:
                self.text_response('session_end_failed', status=503)
                return
            self.text_response('', status=204)
        elif path == '/players/access-request':
            hdid = normalize_text(form.get('hdid') or '')
            next_url = form.get('next') or f'/players/{quote(hdid)}'
            if not hdid:
                self.redirect('/players')
                return
            if self.is_superadmin(user):
                self.redirect(next_url)
                return
            rule = self.repo.get_player_access_rule(hdid)
            if rule and int(rule['requires_ga_accept'] or 0) == 1:
                try:
                    self.repo.request_player_access(hdid, int(user['id']))
                except sqlite3.OperationalError:
                    pass
            self.redirect(next_url)
        elif path == '/players/access-request/resolve':
            if not self.is_superadmin(user):
                self.not_found()
                return
            request_id = int(form.get('request_id') or 0)
            approve = form.get('decision') == 'approve'
            next_url = form.get('next') or '/players'
            if request_id:
                hdid = self.repo.resolve_player_access_request(request_id, int(user['id']), approve)
                if hdid and '/players/' not in next_url:
                    next_url = f'/players/{quote(hdid)}'
            self.redirect(next_url)
        elif path == '/players/session/start':
            hdid = normalize_text(form.get('hdid') or '')
            next_url = form.get('next') or f'/players/{quote(hdid)}?open=1'
            if not hdid:
                self.redirect('/players')
                return
            if not self.repo.can_open_player_card(user, hdid):
                self.redirect(f'/players/{quote(hdid)}')
                return
            try:
                self.repo.create_player_card_session(hdid, int(user['id']), self.request_ip(), self.headers.get('User-Agent', ''))
            except sqlite3.OperationalError:
                self.redirect(f'/players/{quote(hdid)}?session_error=1')
                return
            self.redirect(next_url)
        elif path == '/players/access-rule':
            if not self.is_superadmin(user):
                self.not_found()
                return
            hdid = normalize_text(form.get('hdid') or '')
            requires_accept = form.get('requires_accept') == '1'
            next_url = form.get('next') or f'/players/{quote(hdid)}'
            if hdid:
                self.repo.set_player_access_rule(hdid, requires_accept, int(user['id']))
            self.redirect(next_url)
        else:
            self.not_found()

    def handle_login(self, form: dict) -> None:
        if self.current_user():
            self.redirect("/players")
            return
        username = form.get("username") or ""
        password = form.get("password") or ""
        account = self.repo.authenticate_account(username, password)
        if not account:
            self.render_login_page(error="Неверный логин или пароль", username=username)
            return
        try:
            token = self.repo.create_account_session(int(account["id"]), self.headers.get("User-Agent", ""), self.request_ip())
        except sqlite3.OperationalError:
            self.render_login_page(error="Сессия не создалась из-за временной блокировки CRM-базы. Повторите вход через пару секунд.", username=username)
            return
        headers = {"Set-Cookie": self.set_session_cookie(token)}
        if self.repo.has_panel_access(account):
            self.redirect("/players", extra_headers=headers)
        else:
            self.redirect("/awaiting-access", extra_headers=headers)

    def handle_register(self, form: dict) -> None:
        if self.current_user():
            self.redirect("/players")
            return
        username = form.get("username") or ""
        display_name = form.get("display_name") or ""
        password = form.get("password") or ""
        password_repeat = form.get("password_repeat") or ""
        if password != password_repeat:
            self.render_register_page(error="Пароли не совпадают", username=username, display_name=display_name)
            return
        account, error = self.repo.register_account(username=username, password=password, display_name=display_name)
        if error or not account:
            self.render_register_page(error=error or "Не удалось создать аккаунт", username=username, display_name=display_name)
            return
        try:
            token = self.repo.create_account_session(int(account["id"]), self.headers.get("User-Agent", ""), self.request_ip())
        except sqlite3.OperationalError:
            self.render_login_page(error="Аккаунт создан, но сессия не открылась из-за временной блокировки CRM-базы. Просто войдите под новым аккаунтом через пару секунд.", username=username)
            return
        headers = {"Set-Cookie": self.set_session_cookie(token)}
        if self.repo.has_panel_access(account):
            self.redirect("/players", extra_headers=headers)
        else:
            self.redirect("/awaiting-access", extra_headers=headers)

    def handle_logout(self) -> None:
        token = self.get_session_token()
        if token:
            self.repo.revoke_session(token)
        self.redirect("/login", extra_headers={"Set-Cookie": self.clear_session_cookie()})

    def auth_layout(self, title: str, subtitle: str, body: str, alt_action_html: str = "") -> str:
        return f"""
<!doctype html>
<html lang='ru'>
<head>
  <meta charset='utf-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1'>
  <title>{e(title)} · {APP_TITLE}</title>
  <link rel='stylesheet' href='/static/styles.css'>
</head>
<body class='login-body'>
  <div class='login-card auth-card-wide'>
    <div class='brand-pill'>Attorney Online · KFO</div>
    <h1>{e(title)}</h1>
    <p class='muted'>{e(subtitle)}</p>
    {body}
    {alt_action_html}
  </div>
</body>
</html>
        """

    def render_login_page(self, error: str = "", username: str = "") -> None:
        error_html = f"<div class='error-box'>{e(error)}</div>" if error else ""
        register_hint = "<div class='auth-switch'>Нет аккаунта? <a href='/register'>Зарегистрироваться</a></div>"
        body = f"""
        {error_html}
        <form class='auth-form-grid' method='post' action='/login'>
          <label>
            <span>Логин</span>
            <input type='text' name='username' value='{e(username)}' autocomplete='username' autofocus>
          </label>
          <label>
            <span>Пароль</span>
            <input type='password' name='password' autocomplete='current-password'>
          </label>
          <button class='primary-button full-width' type='submit'>Войти</button>
        </form>
        """
        self.html_response(self.auth_layout("Вход в CRM", "Вход по аккаунту. После регистрации первый пользователь получает права главного администратора автоматически.", body, register_hint))

    def render_register_page(self, error: str = "", username: str = "", display_name: str = "") -> None:
        error_html = f"<div class='error-box'>{e(error)}</div>" if error else ""
        login_hint = "<div class='auth-switch'>Уже есть аккаунт? <a href='/login'>Войти</a></div>"
        body = f"""
        {error_html}
        <form class='auth-form-grid' method='post' action='/register'>
          <label>
            <span>Логин</span>
            <input type='text' name='username' value='{e(username)}' autocomplete='username' autofocus>
          </label>
          <label>
            <span>Отображаемое имя</span>
            <input type='text' name='display_name' value='{e(display_name)}' placeholder='Необязательно'>
          </label>
          <label>
            <span>Пароль</span>
            <input type='password' name='password' autocomplete='new-password'>
          </label>
          <label>
            <span>Повторите пароль</span>
            <input type='password' name='password_repeat' autocomplete='new-password'>
          </label>
          <button class='primary-button full-width' type='submit'>Зарегистрироваться</button>
        </form>
        """
        self.html_response(self.auth_layout("Регистрация в CRM", "Первый зарегистрированный пользователь становится главным администратором.", body, login_hint))

    def render_pending_access_page(self, user: sqlite3.Row) -> None:
        display_name = normalize_text(user["display_name"] or "") or normalize_text(user["username"] or "")
        body = f"""
        <div class='pending-card'>
          <div class='brand-pill'>Доступ ожидает одобрения</div>
          <h1>Привет, {e(display_name)}</h1>
          <p class='muted'>Ваш аккаунт создан, но доступ к CRM-панели пока не выдан. Обратитесь к главному администратору, чтобы он открыл вам доступ.</p>
          <div class='pending-meta'>
            <div><span>Логин</span><strong>{e(user['username'])}</strong></div>
            <div><span>Роль</span><strong>{'Главный администратор' if self.is_superadmin(user) else 'Пользователь'}</strong></div>
            <div><span>Доступ к панели</span><strong>{'Разрешён' if self.repo.has_panel_access(user) else 'Ожидает'}</strong></div>
          </div>
          <form method='post' action='/logout'>
            <button class='ghost-button' type='submit'>Выйти</button>
          </form>
        </div>
        """
        self.html_response(self.auth_layout("Ожидание доступа", "Сейчас эта учётная запись не может открывать админ-панель.", body))

    def session_cleanup_script(self) -> str:
        parsed = urlparse(self.path)
        path = parsed.path
        if path.startswith('/players/') and self.parse_query(parsed).get('open') == '1':
            return ''
        user = self.current_user()
        if not user or not self.repo.has_panel_access(user):
            return ''
        return """
  <script>
    (function () {
      var sentRecently = false;
      function sendCleanup(reason, preferBeacon) {
        if (sentRecently) return;
        sentRecently = true;
        setTimeout(function () { sentRecently = false; }, 1200);
        var body = new URLSearchParams();
        body.set('reason', reason || 'outside_card');
        if (preferBeacon && navigator.sendBeacon) {
          try { navigator.sendBeacon('/players/session/end-all', body); return; } catch (error) {}
        }
        try {
          fetch('/players/session/end-all', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            keepalive: true
          }).catch(function () {});
        } catch (error) {}
      }
      sendCleanup('outside_card_load', false);
      window.addEventListener('pageshow', function () { sendCleanup('outside_card_pageshow', true); });
      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') sendCleanup('outside_card_visible', true);
      });
    })();
  </script>
        """

    def base_layout(self, title: str, body: str, active_tab: str = "players") -> str:
        stats = self.repo.stats()
        user = self.current_user()
        display_name = normalize_text((user["display_name"] if user else "") or (user["username"] if user else "")) or "Админ"
        nav_players_cls = "nav-link active" if active_tab == "players" else "nav-link"
        nav_profiles_cls = "nav-link active" if active_tab == "profiles" else "nav-link"
        nav_users_cls = "nav-link active" if active_tab == "users" else "nav-link"
        users_nav = ""
        if self.is_superadmin(user):
            users_nav = f"""
        <a class='{nav_users_cls}' href='/users'>
          <span class='nav-title'>Пользователи</span>
          <span class='nav-note'>Выдача доступа и роли</span>
        </a>
            """
        user_card = f"""
        <section class='sidebar-panel user-panel'>
          <div class='sidebar-panel-head'>
            <span class='eyebrow'>Аккаунт</span>
            <strong>{e(display_name)}</strong>
          </div>
          <div class='sync-list'>
            <div class='sync-row'><span>Логин</span><strong>{e(user['username'] if user else '—')}</strong></div>
            <div class='sync-row'><span>Роль</span><strong>{'Главный администратор' if self.is_superadmin(user) else 'Пользователь'}</strong></div>
            <div class='sync-row'><span>Доступ</span><strong>{'Разрешён' if self.repo.has_panel_access(user) else 'Ожидает'}</strong></div>
          </div>
          <form method='post' action='/logout'>
            <button class='ghost-button full-width' type='submit'>Выйти</button>
          </form>
        </section>
        """
        return f"""
<!doctype html>
<html lang='ru'>
<head>
  <meta charset='utf-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1'>
  <title>{e(title)} · {APP_TITLE}</title>
  <link rel='stylesheet' href='/static/styles.css'>
</head>
<body>
  <div class='app-shell'>
    <aside class='sidebar'>
      <div class='sidebar-top'>
        <div class='brand-pill'>Attorney Online · KFO</div>
        <div class='brand-block'>
          <h1>{APP_TITLE}</h1>
          <p class='muted'>Тёмная CRM-панель для администрации: HDID, связи, профили, заметки и быстрый разбор игрока.</p>
        </div>
      </div>

      <nav class='nav'>
        <a class='{nav_players_cls}' href='/players'>
          <span class='nav-title'>Игроки</span>
          <span class='nav-note'>Реестр из storage/db.sqlite3</span>
        </a>
        <a class='{nav_profiles_cls}' href='/profiles'>
          <span class='nav-title'>Профили</span>
          <span class='nav-note'>Ручные карточки админов</span>
        </a>
        {users_nav}
      </nav>

      <section class='sidebar-panel stats-panel'>
        <div class='sidebar-panel-head'>
          <span class='eyebrow'>Общий срез</span>
          <strong>Состояние базы</strong>
        </div>
        <div class='mini-stats'>
          <div class='mini-stat'><span>Игроков в кэше</span><strong>{stats['players']}</strong></div>
          <div class='mini-stat'><span>Профилей</span><strong>{stats['profiles']}</strong></div>
          <div class='mini-stat'><span>HDID в профилях</span><strong>{stats['linked_hdids']}</strong></div>
          <div class='mini-stat'><span>Аккаунтов</span><strong>{stats['accounts']}</strong></div>
          <div class='mini-stat'><span>Доступ открыт</span><strong>{stats['approved_accounts']}</strong></div>
          <div class='mini-stat'><span>Ожидают</span><strong>{stats['pending_accounts']}</strong></div>
        </div>
      </section>

      <section class='sidebar-panel sync-panel'>
        <div class='sidebar-panel-head'>
          <span class='eyebrow'>Синхронизация</span>
          <strong>Кэш игроков</strong>
        </div>
        <div class='sync-list'>
          <div class='sync-row'><span>Последнее обновление</span><strong>{e(format_dt(stats['last_sync_at']))}</strong></div>
          <div class='sync-row'><span>Время сборки</span><strong>{e(stats['last_sync_seconds'])} сек</strong></div>
          <div class='sync-row'><span>Режим</span><strong>{e(stats['last_sync_mode'])}</strong></div>
          <div class='sync-row'><span>Статус</span><strong>{'Идёт фоновая синхронизация' if stats['sync_running'] else e(stats['sync_message'] or 'Готово')}</strong></div>
        </div>
        <form method='post' action='/sync'>
          <input type='hidden' name='next' value='{e(self.path)}'>
          <button class='primary-button full-width' type='submit'>Фоново пересобрать кэш</button>
        </form>
      </section>
      {user_card}
    </aside>

    <main class='main-content'>
      <div class='topbar'>
        <div class='topbar-title'>
          <span class='topbar-dot'></span>
          <span>Админская панель сервера</span>
        </div>
        <div class='topbar-meta'>
          <span class='topbar-chip'>Источник: storage/db.sqlite3</span>
          <span class='topbar-chip'>Режим доступа: аккаунты</span>
        </div>
      </div>
      {body}
    </main>
  </div>

  <dialog class='preview-dialog' id='player-preview-dialog'>
    <div class='preview-dialog-shell'>
      <div id='player-preview-content' class='preview-dialog-content'></div>
    </div>
  </dialog>

  <script>
    document.addEventListener('click', function (event) {{
      var button = event.target.closest('[data-copy]');
      if (button) {{
        var value = button.getAttribute('data-copy') || '';
        if (value && navigator.clipboard) {{
          navigator.clipboard.writeText(value).then(function () {{
            var original = button.dataset.label || button.textContent;
            button.dataset.label = original;
            button.textContent = 'Скопировано';
            button.classList.add('is-copied');
            setTimeout(function () {{
              button.textContent = original;
              button.classList.remove('is-copied');
            }}, 1000);
          }});
        }}
        return;
      }}

      var closeButton = event.target.closest('[data-close-preview]');
      if (closeButton) {{
        var dialog = document.getElementById('player-preview-dialog');
        if (dialog) dialog.close();
        return;
      }}

      var link = event.target.closest('.player-preview-link');
      if (!link || window.innerWidth < 900 || !window.HTMLDialogElement) return;
      event.preventDefault();
      var dialog = document.getElementById('player-preview-dialog');
      var content = document.getElementById('player-preview-content');
      if (!dialog || !content) return;
      content.innerHTML = "<div class='preview-loading'>Загрузка сводки игрока…</div>";
      dialog.showModal();
      fetch(link.dataset.previewUrl || (link.getAttribute('href') + '/preview'), {{ credentials: 'same-origin' }})
        .then(function (response) {{ return response.text(); }})
        .then(function (html) {{ content.innerHTML = html; }})
        .catch(function () {{
          content.innerHTML = "<div class='preview-error'>Не удалось загрузить сводку. <a href='" + link.getAttribute('href') + "'>Открыть страницу</a></div>";
        }});
    }});

    document.addEventListener('click', function (event) {{
      var dialog = document.getElementById('player-preview-dialog');
      if (!dialog || !dialog.open) return;
      var shell = dialog.querySelector('.preview-dialog-shell');
      if (event.target === dialog && shell) dialog.close();
    }});
  </script>
  {self.session_cleanup_script()}
</body>
</html>
        """

    def render_users_page(self, parsed) -> None:
        user = self.current_user()
        if not self.is_superadmin(user):
            self.not_found()
            return
        accounts = self.repo.list_accounts()
        approved_count = sum(1 for row in accounts if int(row["can_access_panel"] or 0) == 1 or str(row["role"]) == "superadmin")
        pending_count = max(len(accounts) - approved_count, 0)
        cards = []
        for account in accounts:
            is_superadmin = str(account['role']) == 'superadmin'
            role_label = 'Главный администратор' if is_superadmin else 'Пользователь'
            access_open = int(account['can_access_panel'] or 0) == 1 or is_superadmin
            access_label = 'Доступ открыт' if access_open else 'Ожидает выдачи доступа'
            note_text = 'У этой учётной записи постоянный полный доступ к панели.' if is_superadmin else ('Пользователь уже может открывать CRM.' if access_open else 'Пока не может открыть CRM до выдачи доступа.')
            action_html = "<span class='soft-badge success-badge user-action-badge'>Полный доступ</span>" if is_superadmin else ''
            if not is_superadmin:
                grant = 0 if int(account['can_access_panel'] or 0) == 1 else 1
                action_label = 'Закрыть доступ' if grant == 0 else 'Выдать доступ'
                action_class = 'danger-button' if grant == 0 else 'primary-button'
                action_html = f"""
                <form method='post' action='/users/access' class='user-action-form'>
                  <input type='hidden' name='id' value='{int(account['id'])}'>
                  <input type='hidden' name='grant' value='{grant}'>
                  <button class='{action_class} user-action-button' type='submit'>{action_label}</button>
                </form>
                """
            cards.append(f"""
            <article class='surface user-card-clean'>
              <div class='user-card-head'>
                <div class='user-card-identity'>
                  <div class='account-avatar user-card-avatar'>{e((normalize_text(account['display_name'] or '') or account['username'] or '?')[:1].upper())}</div>
                  <div class='user-card-copy'>
                    <h3>{e(normalize_text(account['display_name'] or '') or account['username'])}</h3>
                    <p class='muted mono'>@{e(account['username'])}</p>
                  </div>
                </div>
                <div class='user-card-badges'>
                  <span class='soft-badge'>{e(role_label)}</span>
                  <span class='soft-badge {'success-badge' if access_open else ''}'>{e(access_label)}</span>
                </div>
              </div>
              <div class='user-card-meta'>
                <div><span>Создан</span><strong>{e(format_dt(account['created_at']))}</strong></div>
                <div><span>Последний вход</span><strong>{e(format_dt(account['last_login_at']))}</strong></div>
                <div><span>Доступ выдан</span><strong>{e(format_dt(account['approved_at']))}</strong></div>
                <div><span>Выдал</span><strong>{e(account['approved_by_username'] or '—')}</strong></div>
              </div>
              <div class='user-card-foot'>
                <p class='user-card-note'>{e(note_text)}</p>
                <div class='user-card-actions'>{action_html}</div>
              </div>
            </article>
            """)
        body = f"""
        <section class='hero-card users-hero'>
          <div class='hero-copy'>
            <span class='eyebrow'>Управление доступом</span>
            <h2>Пользователи CRM</h2>
            <p class='muted'>Первый зарегистрированный аккаунт становится главным администратором. Здесь ГА выдаёт или закрывает доступ к панели всем последующим пользователям.</p>
          </div>
          <div class='hero-actions users-hero-metrics'>
            <div class='hero-stat-chip'><span>Всего аккаунтов</span><strong>{len(accounts)}</strong></div>
            <div class='hero-stat-chip'><span>Доступ открыт</span><strong>{approved_count}</strong></div>
            <div class='hero-stat-chip'><span>Ожидают</span><strong>{pending_count}</strong></div>
          </div>
        </section>
        <section class='users-grid-clean'>{''.join(cards) or "<div class='empty-box'>Пока нет пользователей.</div>"}</section>
        """
        self.html_response(self.base_layout("Пользователи", body, active_tab="users"))

    def render_players(self, parsed) -> None:
        q = self.parse_query(parsed)
        query = q.get("q", "")
        sort = q.get("sort", "last_seen_desc")
        page = int(q.get("page", "1") or "1")
        only_profiled = q.get("profiled") == "1"
        only_banned = q.get("banned") == "1"
        data = self.repo.list_players(query=query, page=page, sort=sort, only_profiled=only_profiled, only_banned=only_banned)
        current_count = len(data["items"])
        current_profiled = sum(1 for row in data["items"] if row["profile_count"])
        current_banned = sum(1 for row in data["items"] if row["is_hdid_banned"] or row["is_ip_banned"])
        rows_html = "".join(
            f"""
            <tr>
              <td>
                <div class='identity-cell'>
                  <a class='table-link mono-link player-preview-link' href='/players/{quote(row['hdid'])}' data-preview-url='/players/{quote(row['hdid'])}/preview'>{e(row['hdid'])}</a>
                  <button class='copy-button compact-button' type='button' data-copy='{e(row['hdid'])}'>Скопировать</button>
                </div>
              </td>
              <td>
                <div class='cell-stack'>
                  <strong>{e(row['last_ooc_name'] or '—')}</strong>
                  <span>{e(row['last_ic_name'] or row['last_char_name'] or '—')}</span>
                </div>
              </td>
              <td>{e(row['last_char_name'] or '—')}</td>
              <td><span class='mono subtle'>{e(row['last_ip'] or '—')}</span></td>
              <td>{format_dt(row['last_seen'])}</td>
              <td>{row['connect_count']}</td>
              <td>{row['ip_count']}</td>
              <td><span class='pill {'pill-danger' if row['is_hdid_banned'] or row['is_ip_banned'] else 'pill-ok'}'>{'Есть' if row['is_hdid_banned'] or row['is_ip_banned'] else 'Нет'}</span></td>
              <td>{row['profile_count'] or '—'}</td>
            </tr>
            """
            for row in data["items"]
        ) or "<tr><td colspan='9' class='empty'>Ничего не найдено.</td></tr>"
        profiled_checked = "checked" if only_profiled else ""
        banned_checked = "checked" if only_banned else ""
        export_href = f"/players/export.csv?q={quote(query)}&sort={quote(sort)}&profiled={'1' if only_profiled else '0'}&banned={'1' if only_banned else '0'}"
        body = f"""
        <section class='hero-card'>
          <div class='hero-copy'>
            <div class='eyebrow'>Вкладка 1 · реестр</div>
            <h2>Игроки и HDID из server storage</h2>
            <p class='muted'>Список собирается из <code>storage/db.sqlite3</code> по HDID и показывает последние OOC/IC-данные, IP, активность и уже созданные внутренние профили.</p>
          </div>
          <div class='hero-actions'>
            <a class='ghost-button' href='{export_href}'>CSV экспорт</a>
          </div>
        </section>

        <section class='metric-grid'>
          <article class='metric-card'>
            <span>Найдено по фильтрам</span>
            <strong>{data['total']}</strong>
            <small>всего записей по текущему запросу</small>
          </article>
          <article class='metric-card'>
            <span>На этой странице</span>
            <strong>{current_count}</strong>
            <small>страница {data['page']} из {data['pages']}</small>
          </article>
          <article class='metric-card'>
            <span>С профилями</span>
            <strong>{current_profiled}</strong>
            <small>на текущем листе</small>
          </article>
          <article class='metric-card'>
            <span>С санкциями</span>
            <strong>{current_banned}</strong>
            <small>HDID/IP бан среди результатов</small>
          </article>
        </section>

        <section class='surface search-surface'>
          <div class='section-heading'>
            <div>
              <h3>Поиск и фильтры</h3>
              <p class='muted'>Ищет по HDID, OOC, IC, персонажам, IP, Discord, тегам и заметкам из привязанных профилей.</p>
            </div>
          </div>
          <form class='toolbar' method='get' action='/players'>
            <input class='search-input' type='search' name='q' value='{e(query)}' placeholder='HDID, OOC, IC, персонаж, IP, Discord, теги, заметки...'>
            <select class='select-input' name='sort'>
              {self.sort_options(sort, SORT_SQL, {
                    'last_seen_desc': 'Сначала последние входы',
                    'last_seen_asc': 'Сначала старые входы',
                    'hdid_asc': 'HDID A → Z',
                    'hdid_desc': 'HDID Z → A',
                    'connect_desc': 'Больше входов сверху',
                    'connect_asc': 'Меньше входов сверху',
                })}
            </select>
            <label class='check'><input type='checkbox' name='profiled' value='1' {profiled_checked}> Только с профилем</label>
            <label class='check'><input type='checkbox' name='banned' value='1' {banned_checked}> Только забаненные</label>
            <button class='primary-button' type='submit'>Найти</button>
          </form>
        </section>

        <section class='surface'>
          <div class='section-heading section-heading-spread'>
            <div>
              <h3>Список игроков</h3>
              <p class='muted'>Открывайте карточку игрока, чтобы посмотреть связи, историю подключений и быстро создать профиль.</p>
            </div>
            <div class='badge-row'>
              <span class='soft-badge'>HDID-центричная модель</span>
              <span class='soft-badge'>Живой поиск</span>
              <span class='soft-badge'>Связка с CRM</span>
            </div>
          </div>
          <div class='table-wrap'>
            <table>
              <thead>
                <tr>
                  <th>HDID</th>
                  <th>Игрок</th>
                  <th>Персонаж</th>
                  <th>Последний IP</th>
                  <th>Последний вход</th>
                  <th>Подключения</th>
                  <th>IP</th>
                  <th>Бан</th>
                  <th>Профили</th>
                </tr>
              </thead>
              <tbody>{rows_html}</tbody>
            </table>
          </div>
          {self.pagination('/players', query, data['page'], data['pages'], extra={
                'sort': sort,
                'profiled': '1' if only_profiled else '0',
                'banned': '1' if only_banned else '0',
            })}
        </section>
        """
        self.html_response(self.base_layout("Игроки", body, active_tab="players"))

    def render_players_csv(self, parsed) -> None:
        q = self.parse_query(parsed)
        query = q.get("q", "")
        sort = q.get("sort", "last_seen_desc")
        only_profiled = q.get("profiled") == "1"
        only_banned = q.get("banned") == "1"
        csv_payload = self.repo.export_players_csv(query=query, sort=sort, only_profiled=only_profiled, only_banned=only_banned)
        self.text_response(csv_payload, content_type="text/csv; charset=utf-8", extra_headers={
            "Content-Disposition": "attachment; filename=players_export.csv"
        })

    def render_pending_player_access_requests(self, hdid: str, rows: List[sqlite3.Row], next_url: str, compact: bool = False) -> str:
        if not rows:
            return ""
        cards = "".join(
            f"""
            <div class='approval-card'>
              <div>
                <strong>{e(row['requester_display_name'])}</strong>
                <div class='approval-meta'>@{e(row['requester_username'])} · {e(format_dt(row['created_at']))}</div>
              </div>
              <div class='approval-actions'>
                <form method='post' action='/players/access-request/resolve'>
                  <input type='hidden' name='request_id' value='{row['id']}'>
                  <input type='hidden' name='decision' value='approve'>
                  <input type='hidden' name='next' value='{e(next_url)}'>
                  <button class='primary-button' type='submit'>Разрешить вход</button>
                </form>
                <form method='post' action='/players/access-request/resolve'>
                  <input type='hidden' name='request_id' value='{row['id']}'>
                  <input type='hidden' name='decision' value='deny'>
                  <input type='hidden' name='next' value='{e(next_url)}'>
                  <button class='ghost-button danger-ghost' type='submit'>Отклонить</button>
                </form>
              </div>
            </div>
            """
            for row in rows
        )
        extra_class = ' compact' if compact else ''
        return f"""
        <div class='approval-panel{extra_class}'>
          <div class='approval-panel-head'>
            <div>
              <h4>Ожидают аццепта ГА</h4>
              <p class='muted'>Разовые запросы на вход в карточку этого игрока.</p>
            </div>
            <span class='pill'>{len(rows)}</span>
          </div>
          <div class='approval-list'>{cards}</div>
        </div>
        """

    def render_player_access_request_cta(self, hdid: str, next_url: str, request_row: Optional[sqlite3.Row]) -> str:
        status = str(request_row['status']) if request_row else ''
        if status == 'pending':
            return """
            <div class='preview-form'>
              <button class='primary-button full-width' type='button' disabled>Запрос отправлен ГА</button>
            </div>
            """
        label = 'Запросить повторный аццепт' if status == 'denied' else 'Запросить аццепт ГА'
        return f"""
        <form method='post' action='/players/access-request' class='preview-form'>
          <input type='hidden' name='hdid' value='{e(hdid)}'>
          <input type='hidden' name='next' value='{e(next_url)}'>
          <button class='primary-button full-width' type='submit'>{label}</button>
        </form>
        """

    def render_player_request_note(self, request_row: Optional[sqlite3.Row]) -> str:
        if not request_row:
            return ''
        status = str(request_row['status'])
        if status == 'pending':
            return "<p class='muted preview-note'>Запрос уже отправлен главному администратору. После подтверждения кнопка входа станет доступна на один раз.</p>"
        if status == 'denied':
            resolver = normalize_text(request_row['resolver_display_name'] or request_row['resolver_username'] or 'ГА')
            return f"<p class='muted preview-note'>Предыдущий запрос был отклонён: {e(resolver)}. Можно отправить повторный запрос.</p>"
        if status == 'approved':
            resolver = normalize_text(request_row['resolver_display_name'] or request_row['resolver_username'] or 'ГА')
            return f"<p class='muted preview-note'>Разовый вход уже одобрен: {e(resolver)}. Можно открыть карточку.</p>"
        return ''

    def render_player_preview_fragment(self, hdid: str) -> None:
        user = self.current_user()
        player = self.repo.get_player(hdid)
        if not player:
            self.html_response("<div class='preview-error'>Игрок не найден.</div>", status=404)
            return
        primary_profile = self.repo.get_primary_profile_for_hdid(hdid)
        access_rule = self.repo.get_player_access_rule(hdid)
        requires_accept = bool(access_rule and int(access_rule['requires_ga_accept'] or 0) == 1)
        can_start = self.repo.can_open_player_card(user, hdid)
        is_superadmin = self.is_superadmin(user)
        request_row = None
        if user and requires_accept and not is_superadmin:
            request_row = self.repo.get_latest_player_access_request(hdid, int(user['id']))
        pending_requests = self.repo.get_pending_player_access_requests(hdid) if requires_accept and is_superadmin else []
        lock_note = ''
        if requires_accept:
            owner = normalize_text((access_rule['set_by_display_name'] if access_rule else '') or (access_rule['set_by_username'] if access_rule else '') or 'главным администратором')
            lock_note = f"Доступ в карточку открыт только по аццепту ГА. Ограничение установил: {e(owner)}."
        elif is_superadmin:
            lock_note = 'Главный администратор может включить обязательный аццепт для входа в эту карточку.'
        if can_start:
            start_button = (
                f"<form method='post' action='/players/session/start' class='preview-form'>"
                f"<input type='hidden' name='hdid' value='{e(hdid)}'>"
                f"<input type='hidden' name='next' value='/players/{quote(hdid)}?open=1'>"
                f"<button class='primary-button full-width' type='submit'>Начать сессию</button>"
                f"</form>"
            )
        elif requires_accept and not is_superadmin:
            start_button = self.render_player_access_request_cta(hdid, f'/players/{quote(hdid)}', request_row)
        else:
            start_button = (
                f"<div class='preview-form'>"
                f"<button class='primary-button full-width' type='button' disabled>Вход недоступен</button>"
                f"</div>"
            )
        accept_button = ''
        if is_superadmin:
            accept_button = f"""
            <form method='post' action='/players/access-rule' class='preview-form'>
              <input type='hidden' name='hdid' value='{e(hdid)}'>
              <input type='hidden' name='requires_accept' value='{'0' if requires_accept else '1'}'>
              <input type='hidden' name='next' value='/players/{quote(hdid)}'>
              <button class='ghost-button full-width' type='submit'>{'Снять вход по аццепту' if requires_accept else 'Вход по аццепту ГА'}</button>
            </form>
            """
        profile_line = ''
        if primary_profile:
            profile_line = f"<div class='preview-meta'><span>Профиль CRM</span><strong>{e(primary_profile['title'])}</strong></div>"
        sanction = 'Есть санкции' if player['is_hdid_banned'] or player['is_ip_banned'] else 'Санкций нет'
        sanction_cls = 'pill-danger' if player['is_hdid_banned'] or player['is_ip_banned'] else 'pill-ok'
        request_note = self.render_player_request_note(request_row)
        ga_requests_html = self.render_pending_player_access_requests(hdid, pending_requests, f'/players/{quote(hdid)}', compact=True)
        body = f"""
        <div class='preview-card'>
          <div class='preview-head'>
            <div>
              <span class='eyebrow'>Краткая сводка игрока</span>
              <h3>{e(player['last_ooc_name'] or player['last_char_name'] or player['hdid'])}</h3>
            </div>
            <button class='dialog-close' type='button' data-close-preview aria-label='Закрыть'>×</button>
          </div>
          <div class='preview-hdid mono'>{e(player['hdid'])}</div>
          <div class='preview-grid'>
            <div class='preview-meta'><span>Последний вход</span><strong>{e(format_dt(player['last_seen']))}</strong></div>
            <div class='preview-meta'><span>Последний IP</span><strong>{e(player['last_ip'] or '—')}</strong></div>
            <div class='preview-meta'><span>Персонаж</span><strong>{e(player['last_char_name'] or player['last_ic_name'] or '—')}</strong></div>
            <div class='preview-meta'><span>Подключения</span><strong>{player['connect_count']}</strong></div>
            {profile_line}
            <div class='preview-meta'><span>Статус</span><strong><span class='pill {sanction_cls}'>{sanction}</span></strong></div>
          </div>
          <p class='muted preview-note'>{lock_note or 'Перед открытием карточки можно быстро оценить игрока и начать рабочую сессию.'}</p>
          {request_note}
          {ga_requests_html}
          <div class='preview-actions'>
            {start_button}
            {accept_button}
          </div>
        </div>
        """
        self.html_response(body)

    def render_player_preview_page(self, parsed, hdid: str) -> None:
        player = self.repo.get_player(hdid)
        if not player:
            self.not_found()
            return
        preview_html = f"<section class='surface preview-page-shell'>{self.repo.get_player(hdid) and ''}</section>"
        user = self.current_user()
        primary_profile = self.repo.get_primary_profile_for_hdid(hdid)
        access_rule = self.repo.get_player_access_rule(hdid)
        requires_accept = bool(access_rule and int(access_rule['requires_ga_accept'] or 0) == 1)
        can_start = self.repo.can_open_player_card(user, hdid)
        body = self.player_preview_page_body(player, primary_profile, access_rule, can_start, requires_accept)
        self.html_response(self.base_layout(f'Предпросмотр {hdid}', body, active_tab='players'))

    def player_preview_page_body(self, player: sqlite3.Row, primary_profile: Optional[sqlite3.Row], access_rule: Optional[sqlite3.Row], can_start: bool, requires_accept: bool) -> str:
        user = self.current_user()
        hdid = player['hdid']
        owner = normalize_text((access_rule['set_by_display_name'] if access_rule else '') or (access_rule['set_by_username'] if access_rule else '') or 'главным администратором')
        note = f'Вход в карточку открыт только по аццепту ГА. Ограничение установил: {owner}.' if requires_accept else 'Перед входом в карточку можно быстро оценить краткую сводку и уже затем начать рабочую сессию.'
        profile_line = f"<div class='preview-meta'><span>Профиль CRM</span><strong>{e(primary_profile['title'])}</strong></div>" if primary_profile else ''
        sanction = 'Есть санкции' if player['is_hdid_banned'] or player['is_ip_banned'] else 'Санкций нет'
        sanction_cls = 'pill-danger' if player['is_hdid_banned'] or player['is_ip_banned'] else 'pill-ok'
        is_superadmin = self.is_superadmin(user)
        request_row = None
        if user and requires_accept and not is_superadmin:
            request_row = self.repo.get_latest_player_access_request(hdid, int(user['id']))
        pending_requests = self.repo.get_pending_player_access_requests(hdid) if requires_accept and is_superadmin else []
        if can_start:
            start_button = f"<form method='post' action='/players/session/start' class='preview-form'><input type='hidden' name='hdid' value='{e(hdid)}'><input type='hidden' name='next' value='/players/{quote(hdid)}?open=1'><button class='primary-button' type='submit'>Начать сессию</button></form>"
        elif requires_accept and not is_superadmin:
            start_button = self.render_player_access_request_cta(hdid, f'/players/{quote(hdid)}', request_row)
        else:
            start_button = "<button class='primary-button' type='button' disabled>Вход недоступен</button>"
        accept_button = ''
        if is_superadmin:
            accept_button = f"<form method='post' action='/players/access-rule' class='preview-form'><input type='hidden' name='hdid' value='{e(hdid)}'><input type='hidden' name='requires_accept' value='{'0' if requires_accept else '1'}'><input type='hidden' name='next' value='/players/{quote(hdid)}'><button class='ghost-button' type='submit'>{'Снять вход по аццепту' if requires_accept else 'Вход по аццепту ГА'}</button></form>"
        request_note = self.render_player_request_note(request_row)
        ga_requests_html = self.render_pending_player_access_requests(hdid, pending_requests, f'/players/{quote(hdid)}')
        return f"""
        <section class='hero-card preview-page-hero'>
          <div class='hero-copy'>
            <span class='eyebrow'>Предпросмотр карточки игрока</span>
            <h2>{e(player['last_ooc_name'] or player['last_char_name'] or player['hdid'])}</h2>
            <p class='muted'>{e(note)}</p>
          </div>
          <div class='hero-actions'>
            <a class='ghost-button' href='/players'>← К списку игроков</a>
          </div>
        </section>
        <section class='surface preview-page-shell'>
          <div class='preview-card preview-card-page'>
            <div class='preview-hdid mono'>{e(hdid)}</div>
            <div class='preview-grid'>
              <div class='preview-meta'><span>Последний вход</span><strong>{e(format_dt(player['last_seen']))}</strong></div>
              <div class='preview-meta'><span>Первый вход</span><strong>{e(format_dt(player['first_seen']))}</strong></div>
              <div class='preview-meta'><span>Последний IP</span><strong>{e(player['last_ip'] or '—')}</strong></div>
              <div class='preview-meta'><span>OOC</span><strong>{e(player['last_ooc_name'] or '—')}</strong></div>
              <div class='preview-meta'><span>Персонаж</span><strong>{e(player['last_char_name'] or player['last_ic_name'] or '—')}</strong></div>
              <div class='preview-meta'><span>Подключения</span><strong>{player['connect_count']}</strong></div>
              {profile_line}
              <div class='preview-meta'><span>Статус</span><strong><span class='pill {sanction_cls}'>{sanction}</span></strong></div>
            </div>
            {request_note}
            {ga_requests_html}
            <div class='preview-actions preview-actions-page'>
              {start_button}
              {accept_button}
            </div>
          </div>
        </section>
        """


    def render_player_detail(self, parsed, hdid: str) -> None:
        player = self.repo.get_player(hdid)
        if not player:
            self.not_found()
            return
        user = self.current_user()
        if not self.repo.can_open_player_card(user, hdid):
            self.redirect(f"/players/{quote(hdid)}")
            return
        if user:
            try:
                self.repo.touch_player_card_session(hdid, int(user['id']))
            except sqlite3.OperationalError:
                pass

        q = self.parse_query(parsed)
        section = normalize_text(q.get('section', 'summary')).lower() or 'summary'
        if section not in {'summary', 'logs', 'connections', 'sessions'}:
            section = 'summary'
        log_filter = q.get('log_filter', 'all')
        log_query = q.get('log_q', '')
        try:
            log_page = max(1, int(q.get('log_page', '1') or '1'))
        except ValueError:
            log_page = 1
        logs_all = q.get('logs_all') == '1'

        profiles = self.repo.get_player_profiles(hdid)
        primary_profile = self.repo.get_primary_profile_for_hdid(hdid)
        access_rule = self.repo.get_player_access_rule(hdid)
        bans = self.repo.get_player_bans(hdid)
        session_count = self.repo.count_player_card_sessions(hdid)

        primary_name = player['last_ooc_name'] or player['last_char_name'] or player['hdid']
        avatar_text = (primary_name[:2] if len(primary_name) >= 2 else primary_name[:1] or '?').upper()
        is_locked = bool(access_rule and int(access_rule['requires_ga_accept'] or 0) == 1)
        is_superadmin = self.is_superadmin(user)
        request_row = self.repo.get_latest_player_access_request(hdid, int(user['id'])) if user and is_locked and not is_superadmin else None
        pending_requests = self.repo.get_pending_player_access_requests(hdid) if is_locked and is_superadmin else []

        player_hdid_q = quote(player['hdid'])
        player_url = f"/players/{player_hdid_q}"
        player_open_url = f"{player_url}?open=1"

        def section_url(name: str) -> str:
            return f"{player_open_url}&section={quote(name)}#session-sections"

        def section_tile(name: str, label: str, note: str, value: str) -> str:
            cls = 'session-tile active' if section == name else 'session-tile'
            return f"""
            <a class='{cls}' href='{section_url(name)}'>
              <span class='session-tile-label'>{e(label)}</span>
              <strong>{e(value)}</strong>
              <small>{e(note)}</small>
            </a>
            """

        sanction_badge = "<span class='pill pill-danger hero-status-badge'>Есть санкции</span>" if bans or player['is_hdid_banned'] or player['is_ip_banned'] else "<span class='pill pill-ok hero-status-badge'>Санкций нет</span>"
        access_badge = "<span class='pill'>Вход по аццепту ГА</span>" if is_locked else "<span class='pill pill-ok'>Свободный вход</span>"

        if primary_profile:
            action_button = f"<a class='primary-button full-width' href='/profiles/{primary_profile['id']}'>Открыть профиль игрока</a>"
            action_hint = "Для этого HDID уже есть профиль. Открывается существующая карточка, а не создание дубля."
            profile_heading = "Профиль игрока"
            profile_blurb = "У этого HDID уже есть привязанная CRM-карточка."
        else:
            prefill_title = player['last_ooc_name'] or player['last_char_name'] or player['hdid']
            action_button = f"<a class='primary-button full-width' href='/profiles/new?hdid={quote(player['hdid'])}&title={quote(prefill_title)}&ooc_name={quote(player['last_ooc_name'] or '')}'>Создать профиль игрока</a>"
            action_hint = "Для этого HDID ещё нет профиля — можно создать первую карточку прямо отсюда."
            profile_heading = "Профиль игрока"
            profile_blurb = "Ручная карточка администрации, привязанная к этому HDID."

        profile_cards = "".join(
            f"""
            <a class='linked-card' href='/profiles/{row['id']}'>
              <div class='linked-card-head'>
                <strong>{e(row['title'])}</strong>
                <span class='pill'>{'primary' if row['is_primary'] else 'linked'}</span>
              </div>
              <div class='linked-card-line'>{e(row['ooc_name'] or 'без OOC')} · {e(row['discord'] or 'без Discord')}</div>
              <div class='linked-card-line'>risk: {e(row['risk_level'])}</div>
            </a>
            """
            for row in profiles
        ) or "<div class='empty-box'>Профиль ещё не создан.</div>"

        access_request_panel = ''
        if is_locked and is_superadmin:
            access_request_panel = (
                f"<article class='surface profile-side-surface'>{self.render_pending_player_access_requests(hdid, pending_requests, section_url('summary'))}</article>"
                if pending_requests
                else "<article class='surface profile-side-surface'><div class='section-heading'><div><h3>Аццепт ГА</h3><p class='muted'>Для этого игрока включён разовый вход по подтверждению, но новых запросов сейчас нет.</p></div></div></article>"
            )
        elif is_locked and request_row:
            denied_cta = self.render_player_access_request_cta(hdid, section_url('summary'), request_row) if str(request_row['status']) == 'denied' else ''
            access_request_panel = f"<article class='surface profile-side-surface'><div class='section-heading'><div><h3>Статус запроса</h3><p class='muted'>Разовый вход в карточку при включённом аццепте ГА.</p></div></div>{self.render_player_request_note(request_row)}{denied_cta}</article>"

        tiles_html = "".join([
            section_tile('summary', 'Сводка по игроку', 'идентификаторы, связи, профиль', 'основное'),
            section_tile('logs', 'Серверные логи', 'чат, действия, модерация', '20+ строк'),
            section_tile('connections', 'История подключений', 'последние входы по кэшу CRM', str(player['connect_count'])),
            section_tile('sessions', 'История входов в сессию', 'кто открывал карточку игрока', str(session_count)),
        ])

        section_heading = ''
        section_content = ''

        if section == 'summary':
            ban_rows = ''
            if bans:
                ban_rows = "".join(
                    f"""
                    <div class='ban-card'>
                      <div class='linked-card-head'>
                        <strong>{'Бан по HDID' if row['scope'] == 'hdid' else 'Бан по IP'}</strong>
                        <span class='pill pill-danger'>ban #{row['ban_id']}</span>
                      </div>
                      <div class='ban-meta'>
                        <span>Дата: {format_dt(row['ban_date'])}</span>
                        <span>Кто выдал: {e(row['banned_by_name'])}</span>
                        <span>Цель: {e(row['target'])}</span>
                      </div>
                      <p>{e(row['reason'])}</p>
                    </div>
                    """
                    for row in bans[:4]
                )
            section_heading = """
            <div class='section-heading section-heading-spread'>
              <div>
                <h3>Сводка по игроку</h3>
                <p class='muted'>Быстрый разбор личности, связей и текущего админского контекста по этому HDID.</p>
              </div>
              <span class='pill'>Раздел сессии</span>
            </div>
            """
            section_content = f"""
            <div class='content-grid detail-layout tile-section-layout'>
              <div class='content-stack'>
                <article class='surface'>
                  <div class='section-heading'>
                    <div>
                      <h3>Сводка и идентификаторы</h3>
                      <p class='muted'>Основные данные по активности, именам и текущему состоянию игрока.</p>
                    </div>
                  </div>
                  <div class='info-grid'>
                    <div class='info-card'><span>HDID</span><strong>{e(player['hdid'])}</strong></div>
                    <div class='info-card'><span>Первый вход</span><strong>{format_dt(player['first_seen'])}</strong></div>
                    <div class='info-card'><span>Последний вход</span><strong>{format_dt(player['last_seen'])}</strong></div>
                    <div class='info-card'><span>Последний IP</span><strong>{e(player['last_ip'] or '—')}</strong></div>
                    <div class='info-card'><span>Последний OOC</span><strong>{e(player['last_ooc_name'] or '—')}</strong></div>
                    <div class='info-card'><span>Последний IC</span><strong>{e(player['last_ic_name'] or '—')}</strong></div>
                    <div class='info-card'><span>Последний персонаж</span><strong>{e(player['last_char_name'] or '—')}</strong></div>
                    <div class='info-card'><span>Последний хаб</span><strong>{e(player['last_hub_name'] or '—')}</strong></div>
                  </div>
                </article>

                <article class='surface'>
                  <div class='section-heading'>
                    <div>
                      <h3>Имена, хабы и IP</h3>
                      <p class='muted'>Агрегированные связи, которые помогают быстро понять, кто перед вами.</p>
                    </div>
                  </div>
                  <div class='chips-section'>
                    <div class='chips-block'>
                      <h4>OOC-имена</h4>
                      {self.render_chips(player['ooc_names_text'])}
                    </div>
                    <div class='chips-block'>
                      <h4>IC-имена</h4>
                      {self.render_chips(player['ic_names_text'])}
                    </div>
                    <div class='chips-block'>
                      <h4>Персонажи</h4>
                      {self.render_chips(player['char_names_text'])}
                    </div>
                    <div class='chips-block'>
                      <h4>Хабы</h4>
                      {self.render_chips(player['hub_names_text'])}
                    </div>
                    <div class='chips-block'>
                      <h4>IP-адреса</h4>
                      {self.render_chips(player['ip_addresses_text'])}
                    </div>
                  </div>
                </article>

                {('<article class="surface ban-surface"><div class="section-heading"><div><h3>Санкции по игроку</h3><p class="muted">Показываются найденные баны из storage/db.sqlite3: причина, дата и кто выдал.</p></div></div><div class="ban-stack">' + ban_rows + '</div></article>') if ban_rows else ''}
              </div>
              <aside class='content-stack side-stack'>
                <article class='surface profile-side-surface'>
                  <div class='section-heading'>
                    <div>
                      <h3>{profile_heading}</h3>
                      <p class='muted'>{profile_blurb}</p>
                    </div>
                  </div>
                  <div class='linked-stack'>{profile_cards}</div>
                </article>
                <article class='surface profile-side-surface'>
                  <div class='section-heading'>
                    <div>
                      <h3>Быстрые действия</h3>
                      <p class='muted'>{e(action_hint)}</p>
                    </div>
                  </div>
                  <div class='action-stack'>
                    {action_button}
                    <a class='ghost-button full-width' href='/players/{quote(player['hdid'])}'>Открыть предпросмотр</a>
                    <a class='ghost-button full-width' href='/players'>Перейти к поиску игроков</a>
                  </div>
                </article>
                {access_request_panel}
              </aside>
            </div>
            """
        elif section == 'logs':
            logs_data = self.repo.list_player_logs(hdid, log_filter=log_filter, query=log_query, page=log_page if logs_all else 1, page_size=LOGS_PAGE_SIZE)
            logs = logs_data['items']
            log_rows = "".join(
                f"""
                <article class='log-item compact-log-item'>
                  <div class='log-item-top'>
                    <span class='pill log-pill-{e(row['category'])}'>{e(row['category'])}</span>
                    <span class='mono subtle'>{format_dt(row['event_time'])}</span>
                  </div>
                  <div class='log-item-head'>
                    <strong>{e(row['event_type'])}</strong>
                    <span class='subtle'>IPID {row['ipid']} · {e(row['ip'] or '—')}</span>
                  </div>
                  <p>{e(row['message'] or '—')}</p>
                  <div class='log-meta'>
                    <span>OOC: {e(row['ooc_name'] or '—')}</span>
                    <span>Персонаж: {e(row['char_name'] or '—')}</span>
                    <span>Хаб: {e(row['hub_name'] or '—')}</span>
                  </div>
                </article>
                """
                for row in logs
            ) or "<div class='empty-box'>Логи по выбранному фильтру не найдены.</div>"
            log_base = f"{player_open_url}&section=logs&logs_all=1&log_filter={quote(log_filter)}&log_q={quote(log_query)}"
            log_pagination = ''
            if logs_all and logs_data['pages'] > 1:
                links = []
                for candidate in range(max(1, logs_data['page'] - 2), min(logs_data['pages'], logs_data['page'] + 2) + 1):
                    cls = 'pagination-link active' if candidate == logs_data['page'] else 'pagination-link'
                    links.append(f"<a class='{cls}' href='{log_base}&log_page={candidate}#session-content'>{candidate}</a>")
                log_pagination = "<div class='pagination'>" + ''.join(links) + "</div>"
            toggle_logs_href = f"{log_base}#session-content" if not logs_all else f"{player_open_url}&section=logs#session-content"
            toggle_logs_label = 'Просмотр всех логов' if not logs_all else 'Только последние 20'
            section_heading = f"""
            <div class='section-heading section-heading-spread'>
              <div>
                <h3>Серверные логи</h3>
                <p class='muted'>Постраничный просмотр CRM-кэша логов без перегруза страницы и сервера. Сейчас в кэше: {logs_data['total']} строк.</p>
              </div>
              <span class='pill'>{logs_data['total']} записей</span>
            </div>
            """
            section_content = f"""
            <article class='surface tile-section-surface logs-surface compact-logs-surface' id='session-content'>
              <form class='toolbar toolbar-logs toolbar-logs-compact' method='get' action='/players/{quote(player['hdid'])}'>
                <input type='hidden' name='open' value='1'>
                <input type='hidden' name='section' value='logs'>
                <input type='hidden' name='logs_all' value='{'1' if logs_all else '0'}'>
                <input class='search-input' type='search' name='log_q' value='{e(log_query)}' placeholder='Поиск по тексту, типу события, OOC, персонажу, IP...'>
                <select class='select-input' name='log_filter'>
                  {self.sort_options(log_filter, {'all':'all','chat':'chat','action':'action','moderation':'moderation','system':'system','other':'other'}, {'all':'все события','chat':'чат','action':'действия','moderation':'модерация','system':'система','other':'прочее'})}
                </select>
                <button class='ghost-button' type='submit'>Применить</button>
                <a class='ghost-button' href='{toggle_logs_href}'>{toggle_logs_label}</a>
              </form>
              <div class='log-stack compact-log-stack session-log-stack'>{log_rows}</div>
              {log_pagination}
            </article>
            """
        elif section == 'connections':
            connections = self.repo.get_player_connections(hdid, limit=60)
            connections_html = "".join(
                f"""
                <tr>
                  <td>{format_dt(row['event_time'])}</td>
                  <td>{e(row['ip_address'] or '—')}</td>
                  <td>{row['ipid']}</td>
                  <td><span class='pill {'pill-danger' if row['failed'] else 'pill-ok'}'>{'ошибка' if row['failed'] else 'успех'}</span></td>
                </tr>
                """
                for row in connections
            ) or "<tr><td colspan='4' class='empty'>Быстрый кэш подключений пока пуст или ещё прогревается после запуска.</td></tr>"
            section_heading = """
            <div class='section-heading section-heading-spread'>
              <div>
                <h3>История подключений</h3>
                <p class='muted'>Последние подключения игрока из быстрого CRM-кэша. Время уже переведено в МСК.</p>
              </div>
              <span class='pill'>последние 60</span>
            </div>
            """
            section_content = f"""
            <article class='surface tile-section-surface' id='session-content'>
              <div class='table-wrap compact'>
                <table>
                  <thead><tr><th>Время</th><th>IP</th><th>IPID</th><th>Статус</th></tr></thead>
                  <tbody>{connections_html}</tbody>
                </table>
              </div>
            </article>
            """
        else:
            session_history = self.repo.get_player_card_sessions(hdid, limit=80)
            session_rows = "".join(
                f"""
                <tr>
                  <td>{format_dt(row['started_at'])}</td>
                  <td>{e(row['display_name'])}</td>
                  <td>{'ГА' if str(row['role']) == 'superadmin' else 'Пользователь'}</td>
                  <td>{e(row['request_ip'] or '—')}</td>
                </tr>
                """
                for row in session_history
            ) or "<tr><td colspan='4' class='empty'>История сессий пока пуста.</td></tr>"
            section_heading = """
            <div class='section-heading section-heading-spread'>
              <div>
                <h3>История входов в сессию</h3>
                <p class='muted'>Кто и когда открывал карточку игрока через рабочую сессию администрации.</p>
              </div>
              <span class='pill'>последние 80</span>
            </div>
            """
            section_content = f"""
            <article class='surface tile-section-surface' id='session-content'>
              <div class='table-wrap compact'>
                <table>
                  <thead><tr><th>Время</th><th>Кто заходил</th><th>Роль</th><th>IP</th></tr></thead>
                  <tbody>{session_rows}</tbody>
                </table>
              </div>
            </article>
            """

        body = f"""
        <div class='player-session-root' data-player-session-root data-hdid='{e(player['hdid'])}' data-player-url='{player_url}' data-player-open-url='{player_open_url}' data-ping-url='/players/session/ping' data-end-url='/players/session/end'>
        <section class='player-hero surface'>
          <div class='player-hero-main'>
            <div class='player-avatar'>{e(avatar_text)}</div>
            <div class='player-hero-copy'>
              <div class='eyebrow'>Карточка игрока · время в МСК</div>
              <h2>{e(primary_name)}</h2>
              <div class='hero-inline hero-inline-balanced'>
                <span class='mono hero-mono'>{e(player['hdid'])}</span>
                <button class='copy-button compact-button hero-copy-button' type='button' data-copy='{e(player['hdid'])}'>Копировать HDID</button>
                {sanction_badge}
                {access_badge}
              </div>
              <p class='muted'>Сессия игрока теперь разбита по разделам: сверху краткое меню-плитка, ниже — только выбранный блок информации.</p>
            </div>
          </div>
          <div class='hero-actions-column'>
            <button class='ghost-button full-width' type='button' id='end-player-session'>Завершить сессию</button>
            <a class='ghost-button full-width' href='/players/{quote(player['hdid'])}'>Открыть предпросмотр</a>
            <a class='ghost-button full-width' href='/players'>← Назад к списку</a>
          </div>
        </section>

        <section class='metric-grid detail-metrics'>
          <article class='metric-card'>
            <span>Подключения</span>
            <strong>{player['connect_count']}</strong>
            <small>за всё время в кэше</small>
          </article>
          <article class='metric-card'>
            <span>Ошибки входа</span>
            <strong>{player['failed_count']}</strong>
            <small>неуспешные коннекты</small>
          </article>
          <article class='metric-card'>
            <span>IP-адресов</span>
            <strong>{player['ip_count']}</strong>
            <small>уникальные IP для HDID</small>
          </article>
          <article class='metric-card'>
            <span>Сессий карточки</span>
            <strong>{session_count}</strong>
            <small>входов администрации</small>
          </article>
        </section>

        <section class='surface session-hub' id='session-sections'>
          <div class='section-heading section-heading-spread'>
            <div>
              <h3>Разделы рабочей сессии</h3>
              <p class='muted'>Плиточное меню упрощает навигацию и показывает только нужный раздел вместо перегруженной карточки.</p>
            </div>
            <span class='pill'>Активен: {e({'summary':'Сводка по игроку','logs':'Серверные логи','connections':'История подключений','sessions':'История входов в сессию'}[section])}</span>
          </div>
          <div class='session-tiles'>
            {tiles_html}
          </div>
        </section>

        <section class='session-section-stack'>
          {section_heading}
          {section_content}
        </section>

        <script>
          (function () {{
            var root = document.querySelector('[data-player-session-root]');
            if (!root) return;
            var hdid = root.getAttribute('data-hdid') || '';
            var pingUrl = root.getAttribute('data-ping-url') || '/players/session/ping';
            var endUrl = root.getAttribute('data-end-url') || '/players/session/end';
            var playerUrl = root.getAttribute('data-player-url') || '';
            var playerOpenUrl = root.getAttribute('data-player-open-url') || '';
            var keepAliveTimer = null;
            var leavingHandled = false;
            var stayOnCard = false;
            var navigatingAway = false;
            var bypassSubmitGuard = false;

            function encodeBody(reason) {{
              var params = new URLSearchParams();
              params.set('hdid', hdid);
              if (reason) params.set('reason', reason);
              return params;
            }}

            function post(url, reason, preferBeacon) {{
              if (!hdid || (leavingHandled && url === endUrl)) return Promise.resolve(false);
              var body = encodeBody(reason);
              if (preferBeacon && navigator.sendBeacon) {{
                try {{ navigator.sendBeacon(url, body); return Promise.resolve(true); }} catch (error) {{}}
              }}
              try {{
                return fetch(url, {{
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: {{ 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }},
                  body: body.toString(),
                  keepalive: true
                }}).then(function () {{ return true; }}).catch(function () {{ return false; }});
              }} catch (error) {{
                return Promise.resolve(false);
              }}
            }}

            function ping() {{
              return post(pingUrl, '', false);
            }}

            function finish(reason, preferBeacon) {{
              if (leavingHandled) return Promise.resolve(true);
              leavingHandled = true;
              if (keepAliveTimer) window.clearInterval(keepAliveTimer);
              return post(endUrl, reason || 'left_card', !!preferBeacon);
            }}

            function isSameCardUrl(url) {{
              if (!url || url.origin !== window.location.origin) return false;
              return url.pathname === playerUrl;
            }}

            ping();
            keepAliveTimer = window.setInterval(function () {{
              if (!leavingHandled && !navigatingAway) ping();
            }}, 20000);
            document.addEventListener('visibilitychange', function () {{
              if (document.visibilityState === 'visible' && !leavingHandled && !navigatingAway) ping();
            }});

            document.addEventListener('click', function (event) {{
              var endButton = event.target.closest('#end-player-session');
              if (endButton) {{
                event.preventDefault();
                if (navigatingAway) return;
                navigatingAway = true;
                finish('manual_end', false).finally(function () {{
                  window.location.href = playerUrl;
                }});
                return;
              }}
              var link = event.target.closest('a[href]');
              if (!link) return;
              if (link.target === '_blank' || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
              var href = link.getAttribute('href') || '';
              if (!href || href[0] === '#' || href.startsWith('javascript:')) return;
              var url;
              try {{ url = new URL(link.href, window.location.origin); }} catch (error) {{ return; }}
              if (isSameCardUrl(url)) {{
                stayOnCard = true;
                return;
              }}
              event.preventDefault();
              if (navigatingAway) return;
              navigatingAway = true;
              finish('nav_click', false).finally(function () {{
                window.location.href = url.href;
              }});
            }}, true);

            document.addEventListener('submit', function (event) {{
              if (bypassSubmitGuard) return;
              var form = event.target;
              if (!form || !(form instanceof HTMLFormElement)) return;
              var action = form.getAttribute('action') || window.location.href;
              var url;
              try {{ url = new URL(action, window.location.origin); }} catch (error) {{ return; }}
              if (url.pathname === pingUrl || url.pathname === endUrl) return;
              var sameCardForm = url.pathname === playerUrl && (function () {{
                var field = form.querySelector('input[name="open"]');
                return field && field.value === '1';
              }})();
              if (sameCardForm) {{
                stayOnCard = true;
                return;
              }}
              event.preventDefault();
              if (navigatingAway) return;
              navigatingAway = true;
              finish('form_nav', false).finally(function () {{
                bypassSubmitGuard = true;
                form.submit();
              }});
            }}, true);

            window.addEventListener('pagehide', function () {{
              if (!stayOnCard && !leavingHandled) finish('pagehide', true);
            }});
            window.addEventListener('beforeunload', function () {{
              if (!stayOnCard && !leavingHandled) finish('unload', true);
            }});
          }})();
        </script>
        </div>
        """
        self.html_response(self.base_layout(f"Игрок {hdid}", body, active_tab='players'))


    def render_profiles(self, parsed) -> None:
        q = self.parse_query(parsed)
        query = q.get("q", "")
        sort = q.get("sort", "updated_desc")
        page = int(q.get("page", "1") or "1")
        data = self.repo.list_profiles(query=query, page=page, sort=sort)
        with_notes = sum(1 for row in data['items'] if normalize_text(row['notes'] or ''))
        multi_hdid = sum(1 for row in data['items'] if row['hdid_count'] > 1)
        cards = "".join(
            f"""
            <a class='profile-tile' href='/profiles/{row['id']}'>
              <div class='profile-tile-head'>
                <div>
                  <strong>{e(row['title'])}</strong>
                  <div class='profile-subtitle'>{e(row['ooc_name'] or 'без OOC')} · {e(row['discord'] or 'без Discord')}</div>
                </div>
                <span class='pill'>{e(row['status'])}</span>
              </div>
              <div class='tile-badges'>
                <span class='soft-badge'>risk: {e(row['risk_level'])}</span>
                <span class='soft-badge'>HDID: {row['hdid_count']}</span>
              </div>
              <div class='profile-block'>
                <span class='profile-label'>Привязанные HDID</span>
                <p>{e(row['hdids_text'] or 'HDID пока не привязан')}</p>
              </div>
              <div class='profile-block'>
                <span class='profile-label'>Теги</span>
                <p>{e(preview_text(row['tags'] or '', 96) or '—')}</p>
              </div>
              <div class='profile-block'>
                <span class='profile-label'>Заметка</span>
                <p>{e(preview_text(row['notes'] or '', 140) or 'Без заметок')}</p>
              </div>
              <div class='profile-footer'>Обновлён: {format_dt(row['updated_at'])}</div>
            </a>
            """
            for row in data['items']
        ) or "<div class='empty-box'>Профилей пока нет.</div>"
        body = f"""
        <section class='hero-card'>
          <div class='hero-copy'>
            <div class='eyebrow'>Вкладка 2 · CRM</div>
            <h2>Профили, которые создают админы</h2>
            <p class='muted'>Здесь собираются ручные карточки по игрокам: OOC, Discord, связанные HDID, статус, риск, теги и внутренние заметки.</p>
          </div>
          <div class='hero-actions'>
            <a class='primary-button' href='/profiles/new'>Новый профиль</a>
          </div>
        </section>

        <section class='metric-grid'>
          <article class='metric-card'>
            <span>Найдено профилей</span>
            <strong>{data['total']}</strong>
            <small>по текущему фильтру</small>
          </article>
          <article class='metric-card'>
            <span>На этой странице</span>
            <strong>{len(data['items'])}</strong>
            <small>страница {data['page']} из {data['pages']}</small>
          </article>
          <article class='metric-card'>
            <span>С заметками</span>
            <strong>{with_notes}</strong>
            <small>на текущем листе</small>
          </article>
          <article class='metric-card'>
            <span>Мульти-HDID</span>
            <strong>{multi_hdid}</strong>
            <small>профили с несколькими HDID</small>
          </article>
        </section>

        <section class='surface search-surface'>
          <div class='section-heading'>
            <div>
              <h3>Поиск по профилям</h3>
              <p class='muted'>Ищет по названию, OOC, Discord, HDID, статусу, risk level, тегам и заметкам.</p>
            </div>
          </div>
          <form class='toolbar toolbar-profiles' method='get' action='/profiles'>
            <input class='search-input' type='search' name='q' value='{e(query)}' placeholder='Название, OOC, Discord, HDID, теги, заметки...'>
            <select class='select-input' name='sort'>
              {self.sort_options(sort, PROFILE_SORT_SQL, {
                    'updated_desc': 'Сначала недавно обновлённые',
                    'created_desc': 'Сначала недавно созданные',
                    'title_asc': 'Название A → Z',
                })}
            </select>
            <button class='primary-button' type='submit'>Найти</button>
          </form>
        </section>

        <section class='profiles-grid'>
          {cards}
        </section>
        {self.pagination('/profiles', query, data['page'], data['pages'], extra={'sort': sort})}
        """
        self.html_response(self.base_layout("Профили", body, active_tab="profiles"))


    def render_profile_form(self, parsed, profile_id: Optional[int]) -> None:
        q = self.parse_query(parsed)
        requested_hdid = normalize_text(q.get('hdid', ''))
        if not profile_id and requested_hdid:
            existing_profile = self.repo.get_primary_profile_for_hdid(requested_hdid)
            if existing_profile:
                self.redirect(f"/profiles/{existing_profile['id']}")
                return
        profile = self.repo.get_profile(profile_id) if profile_id else None
        hdid_rows = self.repo.get_profile_hdids(profile_id) if profile_id else []
        hdids_text = "\n".join(row['hdid'] for row in hdid_rows) if hdid_rows else normalize_text(q.get('hdid', ''))
        title = profile['title'] if profile else normalize_text(q.get('title', ''))
        ooc_name = profile['ooc_name'] if profile else normalize_text(q.get('ooc_name', ''))
        discord = profile['discord'] if profile else ''
        status = profile['status'] if profile else 'new'
        risk_level = profile['risk_level'] if profile else 'medium'
        tags = profile['tags'] if profile else ''
        notes = profile['notes'] if profile else ''
        linked_players_html = "".join(
            f"""
            <a class='linked-card player-preview-link' href='/players/{quote(row['hdid'])}' data-preview-url='/players/{quote(row['hdid'])}/preview'>
              <div class='linked-card-head'>
                <strong>{e(row['hdid'])}</strong>
                <span class='pill'>{'primary' if row['is_primary'] else 'linked'}</span>
              </div>
              <div class='linked-card-line'>{e(row['last_ooc_name'] or '—')} · {format_dt(row['last_seen'])}</div>
              <div class='linked-card-line'>{e(row['last_ip'] or '—')}</div>
            </a>
            """
            for row in hdid_rows
        ) or "<div class='empty-box'>HDID ещё не привязаны.</div>"
        delete_block = (
            f"""
            <form method='post' action='/profiles/delete' onsubmit="return confirm('Удалить профиль?');">
              <input type='hidden' name='id' value='{profile['id']}'>
              <button class='danger-button full-width' type='submit'>Удалить профиль</button>
            </form>
            """
            if profile else "<div class='empty-box'>Профиль ещё не создан. Сначала сохраните карточку.</div>"
        )
        body = f"""
        <section class='hero-card'>
          <div class='hero-copy'>
            <div class='eyebrow'>Карточка профиля</div>
            <h2>{e(profile['title']) if profile else 'Новый профиль игрока'}</h2>
            <p class='muted'>Аккуратная форма для OOC-имени, Discord, статуса, риска, тегов, заметок и привязки одного или нескольких HDID.</p>
          </div>
          <div class='hero-actions'>
            <a class='ghost-button' href='/profiles'>← Назад к профилям</a>
          </div>
        </section>

        <section class='content-grid form-layout'>
          <article class='surface form-surface'>
            <div class='section-heading'>
              <div>
                <h3>Основные данные</h3>
                <p class='muted'>Заполните карточку и сохраните её. Первый HDID в списке будет считаться основным.</p>
              </div>
            </div>
            <form class='profile-form' method='post' action='/profiles/save'>
              <input type='hidden' name='id' value='{profile['id'] if profile else ''}'>
              <label>
                <span>Название профиля</span>
                <input type='text' name='title' value='{e(title)}' placeholder='Например: JustAGhost / основной профиль'>
              </label>
              <div class='form-row'>
                <label>
                  <span>OOC-имя</span>
                  <input type='text' name='ooc_name' value='{e(ooc_name)}' placeholder='Основное OOC-имя игрока'>
                </label>
                <label>
                  <span>Discord</span>
                  <input type='text' name='discord' value='{e(discord)}' placeholder='username, tag или ссылка'>
                </label>
              </div>
              <div class='form-row'>
                <label>
                  <span>Статус</span>
                  <select name='status'>
                    {self.simple_options(status, ['new', 'watchlist', 'verified', 'cleared', 'banned'])}
                  </select>
                </label>
                <label>
                  <span>Risk level</span>
                  <select name='risk_level'>
                    {self.simple_options(risk_level, ['low', 'medium', 'high', 'critical'])}
                  </select>
                </label>
              </div>
              <label>
                <span>Теги</span>
                <input type='text' name='tags' value='{e(tags)}' placeholder='raid, alt, conflict, verified, discord-linked'>
              </label>
              <label>
                <span>Привязанные HDID</span>
                <textarea name='hdids' rows='9' placeholder='Один HDID на строку'>{e(hdids_text)}</textarea>
              </label>
              <label>
                <span>Заметки</span>
                <textarea name='notes' rows='12' placeholder='Внутренние заметки для администрации'>{e(notes)}</textarea>
              </label>
              <div class='button-row'>
                <button class='primary-button' type='submit'>Сохранить профиль</button>
                <a class='ghost-button' href='/profiles'>Отмена</a>
              </div>
            </form>
          </article>

          <aside class='content-stack side-stack'>
            <article class='surface'>
              <div class='section-heading'>
                <div>
                  <h3>Связанные HDID</h3>
                  <p class='muted'>После сохранения здесь видны живые данные по связанным игрокам.</p>
                </div>
              </div>
              <div class='linked-stack'>{linked_players_html}</div>
            </article>
            <article class='surface'>
              <div class='section-heading'>
                <div>
                  <h3>Памятка по карточке</h3>
                  <p class='muted'>Что стоит держать в профиле под рукой.</p>
                </div>
              </div>
              <div class='hint-list'>
                <div class='hint-line'><strong>OOC</strong><span>основное имя или устойчивый ник</span></div>
                <div class='hint-line'><strong>Discord</strong><span>тег, username или ссылка на аккаунт</span></div>
                <div class='hint-line'><strong>HDID</strong><span>один или несколько идентификаторов игрока</span></div>
                <div class='hint-line'><strong>Заметки</strong><span>внутренний контекст для администрации</span></div>
              </div>
            </article>
            <article class='surface danger-surface'>
              <div class='section-heading'>
                <div>
                  <h3>Действия с профилем</h3>
                  <p class='muted'>Удаление доступно только у уже сохранённой карточки.</p>
                </div>
              </div>
              {delete_block}
            </article>
          </aside>
        </section>
        """
        self.html_response(self.base_layout("Профиль", body, active_tab="profiles"))



    def sort_options(self, current: str, mapping: dict, labels: dict) -> str:
        return "".join(
            f"<option value='{e(key)}' {'selected' if key == current else ''}>{e(labels.get(key, key))}</option>"
            for key in mapping.keys()
        )

    def simple_options(self, current: str, options: Sequence[str]) -> str:
        return "".join(
            f"<option value='{e(option)}' {'selected' if option == current else ''}>{e(option)}</option>"
            for option in options
        )


    def render_chips(self, pipe_values: Optional[str]) -> str:
        values = [normalize_text(item) for item in (pipe_values or "").split("|") if normalize_text(item)]
        return "<div class='chips'>" + ("".join(f"<span class='chip'>{e(item)}</span>" for item in values) or "<span class='muted'>Нет данных</span>") + "</div>"

    def pagination(self, base_path: str, query: str, page: int, pages: int, extra: Optional[dict] = None) -> str:
        extra = extra or {}
        if pages <= 1:
            return ""
        links = []
        for candidate in range(max(1, page - 2), min(pages, page + 2) + 1):
            params = {"page": str(candidate)}
            if query:
                params["q"] = query
            for key, value in extra.items():
                if value not in {None, "", "0"}:
                    params[key] = value
            qs = "&".join(f"{quote(str(k))}={quote(str(v))}" for k, v in params.items())
            cls = "pagination-link active" if candidate == page else "pagination-link"
            links.append(f"<a class='{cls}' href='{base_path}?{qs}'>{candidate}</a>")
        return "<div class='pagination'>" + "".join(links) + "</div>"

    def serve_static(self, path: str) -> None:
        rel = path.replace("/static/", "", 1)
        file_path = (STATIC_DIR / rel).resolve()
        if not str(file_path).startswith(str(STATIC_DIR.resolve())) or not file_path.exists() or not file_path.is_file():
            self.not_found()
            return
        content_type = "text/plain; charset=utf-8"
        if file_path.suffix == ".css":
            content_type = "text/css; charset=utf-8"
        elif file_path.suffix == ".js":
            content_type = "application/javascript; charset=utf-8"
        elif file_path.suffix == ".png":
            content_type = "image/png"
        data = file_path.read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(data)

    def html_response(self, content: str, status: int = 200, extra_headers: Optional[dict] = None) -> None:
        self.text_response(content, status=status, content_type="text/html; charset=utf-8", extra_headers=extra_headers)

    def text_response(self, content: str, status: int = 200, content_type: str = "text/plain; charset=utf-8", extra_headers: Optional[dict] = None) -> None:
        data = content.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        if extra_headers:
            for key, value in extra_headers.items():
                self.send_header(key, value)
        self.end_headers()
        self.wfile.write(data)

    def redirect(self, location: str, extra_headers: Optional[dict] = None) -> None:
        self.send_response(303)
        self.send_header("Location", location)
        if extra_headers:
            for key, value in extra_headers.items():
                self.send_header(key, value)
        self.end_headers()

    def not_found(self) -> None:
        self.html_response(self.base_layout("404", "<section class='panel'><h2>404</h2><p>Страница не найдена.</p></section>"), status=404)

    def log_message(self, fmt: str, *args) -> None:
        sys.stdout.write("%s - - [%s] %s\n" % (self.address_string(), self.log_date_time_string(), fmt % args))


class CRMHTTPServer(ThreadingHTTPServer):
    def __init__(self, server_address, handler_cls, repo: CRMRepository, secret_key: str):
        super().__init__(server_address, handler_cls)
        self.repo = repo
        self.secret_key = secret_key


def main() -> None:
    parser = argparse.ArgumentParser(description="FoC Admin CRM")
    parser.add_argument("--source-db", default=str(DEFAULT_SOURCE_DB), help="Path to source storage/db.sqlite3")
    parser.add_argument("--crm-db", default=str(DEFAULT_CRM_DB), help="Path to CRM database/cache file")
    parser.add_argument("--host", default=os.environ.get("CRM_HOST", "127.0.0.1"))
    parser.add_argument("--port", default=int(os.environ.get("CRM_PORT", "8080")), type=int)
    parser.add_argument("--force-sync", action="store_true", help="Force rebuild of player cache on start")
    args = parser.parse_args()

    source_db = Path(args.source_db).resolve()
    if not source_db.exists():
        raise SystemExit(f"Source database not found: {source_db}")

    repo = CRMRepository(source_db=source_db, crm_db=Path(args.crm_db).resolve())
    repo.ensure_cache(force=args.force_sync)

    secret_key = os.environ.get("CRM_SECRET_KEY") or secrets.token_hex(32)
    server = CRMHTTPServer((args.host, args.port), CRMRequestHandler, repo, secret_key)
    print(f"{APP_TITLE} running on http://{args.host}:{args.port}")
    print(f"Source DB: {source_db}")
    print(f"CRM DB: {Path(args.crm_db).resolve()}")
    print("Account auth: enabled")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nStopped.")


if __name__ == "__main__":
    main()
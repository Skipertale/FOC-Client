import json
import os
import time
import pymysql
import pymysql.cursors
from typing import Optional

DB_CONFIG = {
    "host": os.environ.get("MYSQL_HOST", "localhost"),
    "user": "aofoc_user",
    "password": "ZeTTaSl0W!",
    "database": "aofoc",
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
    "connect_timeout": 3,
    "read_timeout": 5,
    "write_timeout": 5,
}

_RETRY_AFTER = 60  # секунд не пробовать после неудачи

CREATE_TABLE_SQL = """
CREATE TABLE IF NOT EXISTS pending_approvals (
  id VARCHAR(64) PRIMARY KEY,
  type ENUM('wl_join','gm_request','login_approval') NOT NULL,
  data JSON NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  resolved_by VARCHAR(128) NULL,
  resolved_on_site TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
"""


class MySQLHelper:
    def __init__(self):
        self._conn = None
        self._dead_until = 0.0

    def _is_dead(self):
        return time.time() < self._dead_until

    def _mark_dead(self):
        self._dead_until = time.time() + _RETRY_AFTER
        if self._conn:
            try:
                self._conn.close()
            except Exception:
                pass
            self._conn = None

    def _mark_alive(self):
        self._dead_until = 0.0

    def connect(self):
        if self._conn is None:
            if self._is_dead():
                raise ConnectionError("MySQL temporarily unavailable, skipping")
            try:
                self._conn = pymysql.connect(**DB_CONFIG)
            except Exception:
                self._mark_dead()
                raise
            with self._conn.cursor() as cur:
                cur.execute(CREATE_TABLE_SQL)
            self._conn.commit()
            self._mark_alive()
        return self._conn

    def ensure_connection(self):
        if self._is_dead():
            raise ConnectionError("MySQL temporarily unavailable, skipping")
        try:
            self._conn.ping(reconnect=True)
        except Exception:
            self._conn = None
            self.connect()

    def close(self):
        if self._conn:
            self._conn.close()
            self._conn = None

    def store_pending(self, request_id: str, req_type: str, data: dict):
        self.ensure_connection()
        with self._conn.cursor() as cur:
            cur.execute(
                "INSERT IGNORE INTO pending_approvals (id, type, data) VALUES (%s, %s, %s)",
                (request_id, req_type, json.dumps(data, ensure_ascii=False)),
            )
        self._conn.commit()

    def get_pending(self) -> list:
        self.ensure_connection()
        with self._conn.cursor() as cur:
            cur.execute(
                "SELECT id, type, data, created_at FROM pending_approvals WHERE status = 'pending' ORDER BY created_at ASC"
            )
            rows = cur.fetchall()
        for r in rows:
            r["data"] = json.loads(r["data"])
        return rows

    def get_by_id(self, request_id: str) -> Optional[dict]:
        self.ensure_connection()
        with self._conn.cursor() as cur:
            cur.execute(
                "SELECT id, type, data, status, resolved_by FROM pending_approvals WHERE id = %s",
                (request_id,),
            )
            row = cur.fetchone()
        if row:
            row["data"] = json.loads(row["data"])
        return row

    def get_resolved_on_site(self) -> list:
        self.ensure_connection()
        with self._conn.cursor() as cur:
            cur.execute(
                "SELECT id, type, data, status, resolved_by FROM pending_approvals WHERE status != 'pending' AND resolved_on_site = 1"
            )
            rows = cur.fetchall()
        for r in rows:
            r["data"] = json.loads(r["data"])
        return rows

    def mark_synced(self, request_id: str):
        self.ensure_connection()
        with self._conn.cursor() as cur:
            cur.execute(
                "UPDATE pending_approvals SET resolved_on_site = 2 WHERE id = %s",
                (request_id,),
            )
        self._conn.commit()

    def resolve_on_site(self, request_id: str, status: str, resolved_by: str):
        self.ensure_connection()
        with self._conn.cursor() as cur:
            cur.execute(
                "UPDATE pending_approvals SET status = %s, resolved_at = NOW(), resolved_by = %s, resolved_on_site = 1 WHERE id = %s AND status = 'pending'",
                (status, resolved_by, request_id),
            )
        self._conn.commit()


mysql_db = MySQLHelper()

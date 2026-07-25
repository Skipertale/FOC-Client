CREATE TABLE IF NOT EXISTS player_access_codes (
    code TEXT PRIMARY KEY,
    hdid TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    created_by TEXT NOT NULL DEFAULT ''
);

PRAGMA user_version = 9;

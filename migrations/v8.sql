CREATE TABLE IF NOT EXISTS webpanel_accounts(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL COLLATE NOCASE UNIQUE,
    display_name TEXT NOT NULL DEFAULT '',
    password_salt TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS webpanel_sessions(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    FOREIGN KEY (account_id) REFERENCES webpanel_accounts(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_wp_sessions_token ON webpanel_sessions(token);
CREATE INDEX IF NOT EXISTS idx_wp_sessions_account ON webpanel_sessions(account_id);

PRAGMA user_version = 8;

PRAGMA foreign_keys = OFF;

CREATE TABLE IF NOT EXISTS achievement_defs(
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NOT NULL,
    icon TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'other',
    criteria_type TEXT NOT NULL,
    criteria_value INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS player_achievements(
    rowid INTEGER PRIMARY KEY AUTOINCREMENT,
    achievement_id TEXT NOT NULL,
    ipid INTEGER NOT NULL,
    unlocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(achievement_id, ipid),
    FOREIGN KEY (achievement_id) REFERENCES achievement_defs(id) ON DELETE CASCADE,
    FOREIGN KEY (ipid) REFERENCES ipids(ipid) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS player_stats(
    ipid INTEGER PRIMARY KEY,
    ic_messages INTEGER DEFAULT 0,
    ooc_messages INTEGER DEFAULT 0,
    playtime_seconds INTEGER DEFAULT 0,
    logins INTEGER DEFAULT 0,
    areas_visited TEXT DEFAULT '[]',
    char_switches INTEGER DEFAULT 0,
    modcalls INTEGER DEFAULT 0,
    kicks INTEGER DEFAULT 0,
    last_connect DATETIME,
    FOREIGN KEY (ipid) REFERENCES ipids(ipid) ON DELETE CASCADE
);

INSERT OR IGNORE INTO achievement_defs(id, name, description, icon, category, criteria_type, criteria_value) VALUES
('first_ic', 'Первые слова', 'Отправить первое IC-сообщение', 'first_ic.png', 'social', 'ic_messages', 1),
('chatterbox', 'Болтун', 'Отправить 100 IC-сообщений', 'chatterbox.png', 'social', 'ic_messages', 100),
('loquacious', 'Говорун', 'Отправить 1 000 IC-сообщений', 'loquacious.png', 'social', 'ic_messages', 1000),
('gift_of_gab', 'Красноречие', 'Отправить 10 000 IC-сообщений', 'gift_of_gab.png', 'social', 'ic_messages', 10000),
('first_ooc', 'Привет!', 'Отправить первое OOC-сообщение', 'first_ooc.png', 'social', 'ooc_messages', 1),
('socialite', 'Общительный', 'Отправить 500 OOC-сообщений', 'socialite.png', 'social', 'ooc_messages', 500),
('welcome_back', 'С возвращением', 'Залогиниться 10 раз', 'welcome_back.png', 'social', 'logins', 10),
('regular', 'Завсегдатай', 'Залогиниться 50 раз', 'regular.png', 'social', 'logins', 50),
('veteran', 'Ветеран', 'Залогиниться 200 раз', 'veteran.png', 'social', 'logins', 200),
('one_hour', 'Час', 'Наиграть 1 час', 'one_hour.png', 'social', 'playtime', 3600),
('day_player', 'День', 'Наиграть 24 часа', 'day_player.png', 'social', 'playtime', 86400),
('week_player', 'Неделя', 'Наиграть 168 часов', 'week_player.png', 'social', 'playtime', 604800),
('wanderer', 'Странник', 'Посетить 5 разных комнат', 'wanderer.png', 'explorer', 'areas_visited', 5),
('explorer', 'Исследователь', 'Посетить 20 разных комнат', 'explorer.png', 'explorer', 'areas_visited', 20),
('cartographer', 'Картограф', 'Посетить все комнаты', 'cartographer.png', 'explorer', 'areas_visited', -1),
('stylist', 'Стилист', 'Сменить персонажа 5 раз', 'stylist.png', 'explorer', 'char_switches', 5),
('fashionista', 'Модник', 'Сменить персонажа 50 раз', 'fashionista.png', 'explorer', 'char_switches', 50),
('peacekeeper', 'Миротворец', 'Вызвать модератора 5 раз', 'peacekeeper.png', 'mod', 'modcalls', 5),
('enforcer', 'Энфорсер', 'Кикнуть игроков 10 раз', 'enforcer.png', 'mod', 'kicks', 10);

PRAGMA foreign_keys = ON;

PRAGMA user_version = 5;

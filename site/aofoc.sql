-- phpMyAdmin SQL Dump
-- version 4.9.5deb2
-- https://www.phpmyadmin.net/
--
-- Хост: localhost:3306
-- Время создания: Июн 06 2026 г., 13:32
-- Версия сервера: 8.0.42-0ubuntu0.20.04.1
-- Версия PHP: 7.4.3-4ubuntu2.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `aofoc`
--

-- --------------------------------------------------------

--
-- Структура таблицы `achievements`
--

CREATE TABLE `achievements` (
  `id` int UNSIGNED NOT NULL,
  `code` varchar(64) NOT NULL,
  `title` varchar(191) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `achievements`
--

INSERT INTO `achievements` (`id`, `code`, `title`, `description`) VALUES
(1, 'first_case', 'Первый кейс', 'Участвовал в своём первом кейсе на сервере.'),
(2, 'night_session', 'Ночная смена', 'Сыграл в игре, которая началась после 23:00.'),
(3, 'gif_master', 'GIF-мастер', 'Отметился яркими репликами и гифками в процессе.'),
(4, 'host_case', 'Мастер дела', 'Провёл собственный кейс как ведущий.'),
(5, 'game_master', 'Игровой мастер', 'Провёл собственную игру.'),
(6, '1_year', 'Давно с нами', 'Пробыл на сервере более одного года.'),
(7, '5_year', 'Выслуга лет', 'Пробыл на сервере более пяти лет.'),
(8, 'guru_content', 'Гуру контента', 'Создал своего первого персонажа, которого впоследствии добавили в основной контент сервера.'),
(9, 'content_maker', 'Контент-мейкер', 'Вы занимаетесь контентом для сервера и обладаете специальной ролью контент-мейкера.'),
(10, 'ronpa', 'ЫЫЫЫЫ РОНПА', 'Впервые принял участие в импровизационной игре по мотивам Danganronpa'),
(11, 'zsy_beginner', 'Первый куб в зале суда', 'Принял участие в первой игровой сессии Зал Суда Удачи (ЗСУ)'),
(12, 'dnd_beginner', 'Вкусил удачи', 'Принять участие в первой игровой сессии НРИ с элементами кубиков.');

-- --------------------------------------------------------

--
-- Структура таблицы `bot_config`
--

CREATE TABLE `bot_config` (
  `id` tinyint UNSIGNED NOT NULL,
  `bot_user_id` int UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `bot_config`
--

INSERT INTO `bot_config` (`id`, `bot_user_id`) VALUES
(1, 2);

-- --------------------------------------------------------

--
-- Структура таблицы `games`
--

CREATE TABLE `games` (
  `id` int UNSIGNED NOT NULL,
  `owner_user_id` int UNSIGNED DEFAULT NULL,
  `title` varchar(191) NOT NULL,
  `description` text,
  `game_type` enum('case','minigame','event') NOT NULL DEFAULT 'case',
  `status` enum('upcoming','active','finished','cancelled') NOT NULL DEFAULT 'upcoming',
  `starts_at` datetime DEFAULT NULL,
  `external_link` varchar(255) DEFAULT NULL,
  `max_players` int DEFAULT NULL,
  `signups_open` tinyint(1) NOT NULL DEFAULT '1',
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `games`
--

INSERT INTO `games` (`id`, `owner_user_id`, `title`, `description`, `game_type`, `status`, `starts_at`, `external_link`, `max_players`, `signups_open`, `is_featured`, `created_at`) VALUES
(11, NULL, 'Девочки с пушками', NULL, 'event', 'active', NULL, NULL, 3, 0, 1, '2025-11-16 18:04:04'),
(12, 1, 'Знамя Креста: Красный Марш', NULL, 'event', 'active', NULL, NULL, 2, 0, 1, '2025-11-16 18:12:49');

-- --------------------------------------------------------

--
-- Структура таблицы `game_dates`
--

CREATE TABLE `game_dates` (
  `id` int UNSIGNED NOT NULL,
  `game_id` int UNSIGNED NOT NULL,
  `starts_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `game_dates`
--

INSERT INTO `game_dates` (`id`, `game_id`, `starts_at`) VALUES
(12, 11, '2025-12-31 16:00:00');

-- --------------------------------------------------------

--
-- Структура таблицы `news`
--

CREATE TABLE `news` (
  `id` int UNSIGNED NOT NULL,
  `author_user_id` int UNSIGNED DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `type` enum('news','update') NOT NULL DEFAULT 'news',
  `download_link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `nickname` varchar(64) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `contact` varchar(191) DEFAULT NULL,
  `account_role` enum('player','admin','bot') NOT NULL DEFAULT 'player',
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `role_default` varchar(32) DEFAULT 'Адвокат',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_banned` tinyint(1) NOT NULL DEFAULT '0',
  `ban_cases` tinyint(1) NOT NULL DEFAULT '0',
  `ban_minigames` tinyint(1) NOT NULL DEFAULT '0',
  `ban_events` tinyint(1) NOT NULL DEFAULT '0',
  `email_token` varchar(64) DEFAULT NULL,
  `is_email_confirmed` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `nickname`, `email`, `password_hash`, `contact`, `account_role`, `is_verified`, `role_default`, `created_at`, `is_banned`, `ban_cases`, `ban_minigames`, `ban_events`, `email_token`, `is_email_confirmed`) VALUES
(1, 'Skipertale', 'suhorukov.cs@mail.ru', '$2y$10$n.v3ywKXT04p4X8.y0zcguRJSAT5ChirNwIbCII4fSU9xsu1rSYJS', '@dexterst', 'admin', 1, 'Ведущий', '2025-11-16 12:54:47', 0, 0, 0, 0, NULL, 1),
(2, 'Жена ГА', 'test@mail.ru', '$2y$10$a1Wq6YleQsSXl98Sty9bWOu4P3DsFksbJypf3xt1dmehUhSvJj1H.', NULL, 'bot', 1, 'Адвокат', '2025-11-16 14:44:25', 0, 0, 0, 0, NULL, 0),
(3, 'Test', 'test222@mail.ru', '$2y$10$qxdN8QtA8h/EbbFPLqk3NOOqylD0FULLzoLcBTam6J5T8mxFu4GvO', NULL, 'player', 0, 'Адвокат', '2025-11-18 00:07:41', 0, 0, 0, 0, NULL, 0),
(4, 'FN', 'nik_gamula@mail.ru', '$2y$10$/XAfPnjsN9UtCbeFGy21XOTH7g3A7eggP4hGyV1aYgSvJGltVftgW', 'n1ba1', 'player', 1, 'Адвокат', '2025-12-30 22:40:38', 1, 0, 0, 0, NULL, 1),
(5, 'Danil', 'danilangelovskij@gmail.com', '$2y$10$sWt/koJP4ujLT1UfHsF8uu4TT9RvrhoskUGfdDaLh0gVYmtVqJ1DK', 'xx_danilas_play_xx', 'player', 1, 'Адвокат', '2025-12-31 09:49:59', 0, 0, 0, 0, NULL, 1),
(6, 'Vendetta', 'ivan-shkiper008@mail.ru', '$2y$10$6xpPJkMkPVzgoRmUvDBpfOoNO/Lbxj4AQ7tQSVCwqxc8LLgBfKjTu', '__vfovendetta__', 'player', 1, 'Адвокат', '2025-12-31 13:03:37', 0, 0, 0, 0, NULL, 0),
(7, 'Правдин', 'belovezer@gmail.com', '$2y$10$pJzebYjkagWXcRvmZ.ThD.5/FulZX/m0BVzVFjTGcTzGeuasiWdvO', '@belowe_zer0', 'player', 1, 'Адвокат', '2026-01-01 03:58:21', 0, 0, 0, 0, NULL, 1),
(8, 'D3ST', 'destroyinworld@gmail.com', '$2y$10$JtDuCoPQSERtJ8M.dBWRJe/p7tDIN3NeE6PCypGKGuY9p/fwAWGtC', 'Dis: d3st0', 'player', 1, 'Ведущий', '2026-01-01 11:36:52', 0, 0, 0, 0, NULL, 1),
(9, 'луиз', 'pikalola999@gmail.com', '$2y$10$upy9r/3Gfu/EzLEgRq6toOyvPnB88SWSsEkhv9fD4xJWb3D6KEoFi', 'hopelessluiz дис', 'player', 0, 'Свидетель', '2026-05-13 17:39:20', 0, 0, 0, 0, NULL, 1);

-- --------------------------------------------------------

--
-- Структура таблицы `user_achievements`
--

CREATE TABLE `user_achievements` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `achievement_id` int UNSIGNED NOT NULL,
  `granted_by` int DEFAULT NULL,
  `note` int DEFAULT NULL,
  `earned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `user_achievements`
--

INSERT INTO `user_achievements` (`id`, `user_id`, `achievement_id`, `granted_by`, `note`, `earned_at`) VALUES
(1, 1, 6, NULL, NULL, '2025-11-16 17:57:32'),
(2, 1, 1, NULL, NULL, '2025-11-16 17:58:17'),
(3, 1, 2, NULL, NULL, '2025-11-16 17:58:20'),
(4, 1, 3, NULL, NULL, '2025-11-16 17:58:27'),
(5, 1, 4, NULL, NULL, '2025-11-16 17:58:39'),
(6, 1, 5, NULL, NULL, '2025-11-16 17:58:51'),
(7, 1, 7, NULL, NULL, '2025-11-16 17:59:02'),
(8, 1, 8, NULL, NULL, '2025-11-16 17:59:06'),
(9, 1, 9, NULL, NULL, '2025-11-16 17:59:11'),
(10, 1, 10, NULL, NULL, '2025-11-16 17:59:15'),
(11, 1, 11, NULL, NULL, '2025-11-16 17:59:18'),
(12, 1, 12, NULL, NULL, '2025-11-16 17:59:20'),
(13, 7, 1, 1, NULL, '2026-01-01 09:27:19'),
(14, 7, 3, 1, NULL, '2026-01-01 09:27:32'),
(15, 7, 6, 1, NULL, '2026-01-01 09:27:44'),
(16, 7, 7, 1, NULL, '2026-01-01 09:27:54'),
(17, 7, 8, 1, NULL, '2026-01-01 09:28:03'),
(18, 7, 9, 1, NULL, '2026-01-01 09:28:10'),
(19, 7, 10, 1, NULL, '2026-01-01 09:28:14'),
(20, 7, 11, 1, NULL, '2026-01-01 09:28:22'),
(21, 7, 12, 1, NULL, '2026-01-01 09:28:28'),
(22, 7, 2, 1, NULL, '2026-01-01 11:35:45'),
(23, 8, 1, 1, NULL, '2026-01-01 11:38:12'),
(24, 8, 2, 1, NULL, '2026-01-01 11:38:15'),
(25, 8, 3, 1, NULL, '2026-01-01 11:38:19'),
(26, 8, 4, 1, NULL, '2026-01-01 11:38:24'),
(27, 8, 5, 1, NULL, '2026-01-01 11:38:27'),
(28, 8, 6, 1, NULL, '2026-01-01 11:38:31'),
(29, 8, 7, 1, NULL, '2026-01-01 11:38:34'),
(30, 8, 11, 1, NULL, '2026-01-01 11:38:40'),
(31, 8, 12, 1, NULL, '2026-01-01 11:38:43'),
(32, 6, 1, 1, NULL, '2026-01-01 11:50:34'),
(33, 6, 2, 1, NULL, '2026-01-01 11:50:39'),
(34, 6, 3, 1, NULL, '2026-01-01 11:50:42'),
(35, 6, 5, 1, NULL, '2026-01-01 11:50:53'),
(36, 6, 6, 1, NULL, '2026-01-01 11:51:01'),
(37, 6, 7, 1, NULL, '2026-01-01 11:51:06'),
(38, 6, 10, 1, NULL, '2026-01-01 11:51:17'),
(39, 6, 11, 1, NULL, '2026-01-01 11:51:24'),
(40, 6, 12, 1, NULL, '2026-01-01 11:51:28');

-- --------------------------------------------------------

--
-- Структура таблицы `user_games`
--

CREATE TABLE `user_games` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `game_id` int UNSIGNED NOT NULL,
  `role` varchar(64) DEFAULT NULL,
  `status` enum('signed','pending','cancelled','finished') NOT NULL DEFAULT 'signed',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `user_games`
--

INSERT INTO `user_games` (`id`, `user_id`, `game_id`, `role`, `status`, `created_at`, `updated_at`) VALUES
(14, 1, 11, 'Основной каст | Игрок 1', 'signed', '2025-12-31 13:01:16', '2025-12-31 13:02:12');

-- --------------------------------------------------------

--
-- Структура таблицы `user_notifications`
--

CREATE TABLE `user_notifications` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `kind` enum('notification','message') NOT NULL DEFAULT 'notification',
  `title` varchar(191) NOT NULL,
  `body` text,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by_user_id` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `user_notifications`
--

INSERT INTO `user_notifications` (`id`, `user_id`, `kind`, `title`, `body`, `is_read`, `created_at`, `created_by_user_id`) VALUES
(1, 3, 'message', 'Добро пожаловать на Ярмарку противоречий!', 'Привет-привет!~ 💜\nЯ — бот-информатор и личная помощница главного админа.\nБуду приносить тебе важные новости о играх, наборах и всякие милые напоминания.\n\nЗаглядывай в колокольчик в правом верхнем углу — там появляются мои сообщения и уведомления.\nА ещё не забудь настроить профиль и указать удобные роли, чтобы мастерам было проще звать тебя в игры. ✨', 1, '2025-11-18 00:07:41', 2),
(2, 1, 'message', 'Приветствие', 'Привет-привет!~ 💜\r\nЭто тестовое уведомление от меня.\r\nНадеюсь, всё пройдёт чётенько.', 1, '2025-11-19 18:36:26', 2),
(3, 3, 'message', 'Приветствие', 'Привет-привет!~ 💜\r\nЭто тестовое уведомление от меня.\r\nНадеюсь, всё пройдёт чётенько.', 1, '2025-11-19 18:36:26', 2),
(4, 1, 'message', 'Заявка принята!', 'Ведущий одобрил твою заявку на участие в игре «Тест». Не опаздывай!', 1, '2025-11-19 23:12:34', 2),
(5, 1, 'message', 'Заявка принята!', 'Ведущий одобрил твою заявку на участие в игре «Тест». Не опаздывай!', 1, '2025-11-20 21:36:29', 2),
(6, 4, 'message', 'Добро пожаловать на Ярмарку противоречий!', 'Привет-привет!~ 💜\nЯ — бот-информатор и личная помощница главного админа.\nБуду приносить тебе важные новости о играх, наборах и всякие милые напоминания.\n\nЗаглядывай в колокольчик в правом верхнем углу — там появляются мои сообщения и уведомления.\nА ещё не забудь настроить профиль и указать удобные роли, чтобы мастерам было проще звать тебя в игры. ✨', 1, '2025-12-30 22:40:38', 2),
(7, 5, 'message', 'Добро пожаловать на Ярмарку противоречий!', 'Привет-привет!~ 💜\nЯ — бот-информатор и личная помощница главного админа.\nБуду приносить тебе важные новости о играх, наборах и всякие милые напоминания.\n\nЗаглядывай в колокольчик в правом верхнем углу — там появляются мои сообщения и уведомления.\nА ещё не забудь настроить профиль и указать удобные роли, чтобы мастерам было проще звать тебя в игры. ✨', 1, '2025-12-31 09:49:59', 2),
(8, 1, 'message', 'Заявка принята!', 'Ведущий одобрил твою заявку на участие в игре «Девочки с пушками». Не опаздывай!', 1, '2025-12-31 13:01:43', 2),
(9, 6, 'message', 'Добро пожаловать на Ярмарку противоречий!', 'Привет-привет!~ 💜\nЯ — бот-информатор и личная помощница главного админа.\nБуду приносить тебе важные новости о играх, наборах и всякие милые напоминания.\n\nЗаглядывай в колокольчик в правом верхнем углу — там появляются мои сообщения и уведомления.\nА ещё не забудь настроить профиль и указать удобные роли, чтобы мастерам было проще звать тебя в игры. ✨', 1, '2025-12-31 13:03:37', 2),
(10, 7, 'message', 'Добро пожаловать на Ярмарку противоречий!', 'Привет-привет!~ 💜\nЯ — бот-информатор и личная помощница главного админа.\nБуду приносить тебе важные новости о играх, наборах и всякие милые напоминания.\n\nЗаглядывай в колокольчик в правом верхнем углу — там появляются мои сообщения и уведомления.\nА ещё не забудь настроить профиль и указать удобные роли, чтобы мастерам было проще звать тебя в игры. ✨', 1, '2026-01-01 03:58:21', 2),
(11, 8, 'message', 'Добро пожаловать на Ярмарку противоречий!', 'Привет-привет!~ 💜\nЯ — бот-информатор и личная помощница главного админа.\nБуду приносить тебе важные новости о играх, наборах и всякие милые напоминания.\n\nЗаглядывай в колокольчик в правом верхнем углу — там появляются мои сообщения и уведомления.\nА ещё не забудь настроить профиль и указать удобные роли, чтобы мастерам было проще звать тебя в игры. ✨', 1, '2026-01-01 11:36:52', 2),
(12, 9, 'message', 'Добро пожаловать на Ярмарку противоречий!', 'Привет-привет!~ 💜\nЯ — бот-информатор и личная помощница главного админа.\nБуду приносить тебе важные новости о играх, наборах и всякие милые напоминания.\n\nЗаглядывай в колокольчик в правом верхнем углу — там появляются мои сообщения и уведомления.\nА ещё не забудь настроить профиль и указать удобные роли, чтобы мастерам было проще звать тебя в игры. ✨', 1, '2026-05-13 17:39:20', 2);

-- --------------------------------------------------------

--
-- Структура таблицы `user_settings`
--

CREATE TABLE `user_settings` (
  `user_id` int UNSIGNED NOT NULL,
  `timezone` varchar(64) DEFAULT NULL,
  `preferred_roles` varchar(255) DEFAULT NULL,
  `preferred_time` enum('any','evening','night','weekend') DEFAULT 'any',
  `notify_new_games` tinyint(1) DEFAULT '1',
  `notify_taken` tinyint(1) DEFAULT '1',
  `notify_before_game` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `user_settings`
--

INSERT INTO `user_settings` (`user_id`, `timezone`, `preferred_roles`, `preferred_time`, `notify_new_games`, `notify_taken`, `notify_before_game`) VALUES
(1, 'МСК', 'Адвокат,Прокурор,Судья,Ведущий', 'weekend', 1, 1, 1),
(4, 'МСК', 'Адвокат,Ведущий', 'weekend', 1, 1, 1),
(7, 'UTC+5:00', 'Адвокат,Присяжный', 'any', 1, 1, 1),
(8, NULL, NULL, 'any', 1, 0, 1),
(9, NULL, 'Адвокат,Присяжный,Свидетель', 'any', 0, 1, 0);

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `achievements`
--
ALTER TABLE `achievements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Индексы таблицы `bot_config`
--
ALTER TABLE `bot_config`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bot_user_id` (`bot_user_id`);

--
-- Индексы таблицы `games`
--
ALTER TABLE `games`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_games_owner_user` (`owner_user_id`);

--
-- Индексы таблицы `game_dates`
--
ALTER TABLE `game_dates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_game_dates_game` (`game_id`);

--
-- Индексы таблицы `news`
--
ALTER TABLE `news`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_news_author` (`author_user_id`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Индексы таблицы `user_achievements`
--
ALTER TABLE `user_achievements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_user_ach_user` (`user_id`),
  ADD KEY `fk_user_ach_ach` (`achievement_id`);

--
-- Индексы таблицы `user_games`
--
ALTER TABLE `user_games`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_user_games_user` (`user_id`),
  ADD KEY `fk_user_games_game` (`game_id`);

--
-- Индексы таблицы `user_notifications`
--
ALTER TABLE `user_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `un_user_id` (`user_id`),
  ADD KEY `un_kind_read` (`kind`,`is_read`),
  ADD KEY `un_created_by` (`created_by_user_id`);

--
-- Индексы таблицы `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `achievements`
--
ALTER TABLE `achievements`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT для таблицы `games`
--
ALTER TABLE `games`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT для таблицы `game_dates`
--
ALTER TABLE `game_dates`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT для таблицы `news`
--
ALTER TABLE `news`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT для таблицы `user_achievements`
--
ALTER TABLE `user_achievements`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT для таблицы `user_games`
--
ALTER TABLE `user_games`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT для таблицы `user_notifications`
--
ALTER TABLE `user_notifications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `bot_config`
--
ALTER TABLE `bot_config`
  ADD CONSTRAINT `bot_config_user_fk` FOREIGN KEY (`bot_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `games`
--
ALTER TABLE `games`
  ADD CONSTRAINT `fk_games_owner_user` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `game_dates`
--
ALTER TABLE `game_dates`
  ADD CONSTRAINT `fk_game_dates_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `news`
--
ALTER TABLE `news`
  ADD CONSTRAINT `fk_news_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `user_achievements`
--
ALTER TABLE `user_achievements`
  ADD CONSTRAINT `fk_user_ach_ach` FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_ach_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `user_games`
--
ALTER TABLE `user_games`
  ADD CONSTRAINT `fk_user_games_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_games_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `user_notifications`
--
ALTER TABLE `user_notifications`
  ADD CONSTRAINT `un_created_by_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `un_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `user_settings`
--
ALTER TABLE `user_settings`
  ADD CONSTRAINT `fk_user_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

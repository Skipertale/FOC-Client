<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/notifications.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
error_reporting(E_ALL);

// --- Создание таблицы pending_approvals (если ещё нет) ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_approvals (
      id VARCHAR(64) PRIMARY KEY,
      type ENUM('wl_join','gm_request','login_approval') NOT NULL,
      data JSON NOT NULL,
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      resolved_at TIMESTAMP NULL,
      resolved_by VARCHAR(128) NULL,
      resolved_on_site TINYINT(1) DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
} catch (PDOException $e) {
    // игнорируем, если таблица уже есть
}

// --- Проверка авторизации ---
if (empty($_SESSION['user_id'])) {
    header('Location: account.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, nickname, email, account_role FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => (int)$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

$role = isset($currentUser['account_role']) ? $currentUser['account_role'] : 'player';
if (!$currentUser || $role !== 'admin') {
    http_response_code(403);
    echo 'Доступ запрещён.';
    exit;
}

// --- Обработка POST (PRG) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        // Подтверждение / отклонение запросов из pending_approvals
        if ($action === 'approve_pending' || $action === 'reject_pending') {
            $reqId = isset($_POST['request_id']) ? $_POST['request_id'] : '';
            if ($reqId !== '') {
                $status = $action === 'approve_pending' ? 'approved' : 'rejected';
                $resolvedBy = $currentUser['nickname'];
                $st = $pdo->prepare("UPDATE pending_approvals SET status = :status, resolved_at = NOW(), resolved_by = :by, resolved_on_site = 1 WHERE id = :id AND status = 'pending'");
                $st->execute(['status' => $status, 'by' => $resolvedBy, 'id' => $reqId]);
                $_SESSION['admin_message'] = 'Запрос ' . ($status === 'approved' ? 'одобрен' : 'отклонён') . '.';
                // Уведомить бота через HTTP (мгновенная синхронизация)
                $webhookPayload = json_encode(['request_id' => $reqId]);
                $opts = [
                    'http' => [
                        'method'  => 'POST',
                        'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($webhookPayload),
                        'content' => $webhookPayload,
                        'timeout' => 2,
                    ],
                ];
                $ctx = stream_context_create($opts);
                @file_get_contents('http://127.0.0.1:12345/approve', false, $ctx);
            }
        // Смена системной роли пользователя
        } elseif ($action === 'change_role') {
            $userId   = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $newRole  = isset($_POST['new_role']) ? $_POST['new_role'] : 'player';
            $allowed  = ['player', 'admin', 'bot'];

            if ($userId > 0 && in_array($newRole, $allowed, true)) {
                // Себя нельзя лишить админки (в том числе сделать ботом)
                if ($userId === (int)$currentUser['id'] && $newRole !== 'admin') {
                    $_SESSION['admin_error'] = 'Нельзя снять права администратора с самого себя.';
                } else {
                    $st = $pdo->prepare('UPDATE users SET account_role = :role WHERE id = :id');
                    $st->execute([
                        'role' => $newRole,
                        'id'   => $userId,
                    ]);
                    $_SESSION['admin_message'] = 'Роль пользователя обновлена.';
                }
            } else {
                $_SESSION['admin_error'] = 'Некорректные данные при смене роли.';
            }

        // Назначить бота-информатора
        } elseif ($action === 'set_bot_informator') {
            $botUserId = (int)($_POST['bot_user_id'] ?? 0);

            if ($botUserId <= 0) {
                $_SESSION['admin_error'] = 'Некорректный пользователь для назначения ботом.';
            } elseif ($botUserId === (int)$currentUser['id']) {
                $_SESSION['admin_error'] = 'Не стоит назначать самого себя ботом. Создай отдельный аккаунт под бота.';
            } else {
                // Проверяем, что пользователь существует
                $st = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
                $st->execute(['id' => $botUserId]);
                $existsId = $st->fetchColumn();

                if (!$existsId) {
                    $_SESSION['admin_error'] = 'Пользователь с таким ID не найден.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        // Все текущие "боты" превращаются обратно в игроков, кроме выбранного
                        $st = $pdo->prepare("
                            UPDATE users
                            SET account_role = 'player'
                            WHERE account_role = 'bot' AND id <> :new_id
                        ");
                        $st->execute(['new_id' => $botUserId]);

                        // Выбранный пользователь становится ботом
                        $st = $pdo->prepare("
                            UPDATE users
                            SET account_role = 'bot'
                            WHERE id = :id
                        ");
                        $st->execute(['id' => $botUserId]);

                        // Обновляем конфиг бота (основной бот-информатор)
                        $st = $pdo->prepare('
                            INSERT INTO bot_config (id, bot_user_id)
                            VALUES (1, :bot_id)
                            ON DUPLICATE KEY UPDATE bot_user_id = VALUES(bot_user_id)
                        ');
                        $st->execute(['bot_id' => $botUserId]);

                        $pdo->commit();
                        $_SESSION['admin_message'] = 'Бот-информатор успешно обновлён.';
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $_SESSION['admin_error'] = 'Не удалось обновить бота-информатора: ' . $e->getMessage();
                    }
                }
            }

        // Обновление флагов пользователя (верификация, баны)
        } elseif ($action === 'update_user_flags') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                $_SESSION['admin_error'] = 'Некорректный пользователь для обновления.';
            } else {
                $isVerified   = isset($_POST['is_verified']) ? 1 : 0;
                $isBanned     = isset($_POST['is_banned']) ? 1 : 0;
                $banCases     = isset($_POST['ban_cases']) ? 1 : 0;
                $banMinigames = isset($_POST['ban_minigames']) ? 1 : 0;
                $banEvents    = isset($_POST['ban_events']) ? 1 : 0;

                $st = $pdo->prepare('
                    UPDATE users
                    SET is_verified   = :is_verified,
                        is_banned     = :is_banned,
                        ban_cases     = :ban_cases,
                        ban_minigames = :ban_minigames,
                        ban_events    = :ban_events
                    WHERE id = :id
                    LIMIT 1
                ');
                $st->execute([
                    'is_verified'   => $isVerified,
                    'is_banned'     => $isBanned,
                    'ban_cases'     => $banCases,
                    'ban_minigames' => $banMinigames,
                    'ban_events'    => $banEvents,
                    'id'            => $userId,
                ]);

                $_SESSION['admin_message'] = 'Настройки пользователя обновлены.';
            }

        // Создать новую ачивку
        } elseif ($action === 'create_achievement') {
            $code  = isset($_POST['code']) ? trim($_POST['code']) : '';
            $title = isset($_POST['title']) ? trim($_POST['title']) : '';
            $desc  = isset($_POST['description']) ? trim($_POST['description']) : '';

            if ($code === '' || $title === '') {
                $_SESSION['admin_error'] = 'Код и название ачивки не могут быть пустыми.';
            } else {
                $st = $pdo->prepare('
                    INSERT INTO achievements (code, title, description)
                    VALUES (:code, :title, :description)
                ');
                $st->execute([
                    'code'        => $code,
                    'title'       => $title,
                    'description' => $desc !== '' ? $desc : null,
                ]);
                $_SESSION['admin_message'] = 'Ачивка добавлена в справочник.';
            }

        // Выдать ачивку пользователю
        } elseif ($action === 'grant_achievement') {
            $userId        = (int)($_POST['user_id'] ?? 0);
            $achievementId = (int)($_POST['achievement_id'] ?? 0);
            $note          = trim($_POST['note'] ?? '');

            if ($userId <= 0 || $achievementId <= 0) {
                $_SESSION['admin_error'] = 'Некорректные данные для выдачи ачивки.';
            } else {
                $st = $pdo->prepare('
                    INSERT INTO user_achievements (user_id, achievement_id, granted_by, note)
                    VALUES (:user_id, :achievement_id, :granted_by, :note)
                ');
                $st->execute([
                    'user_id'       => $userId,
                    'achievement_id'=> $achievementId,
                    'granted_by'    => (int)$currentUser['id'],
                    'note'          => $note !== '' ? $note : null,
                ]);
                $_SESSION['admin_message'] = 'Ачивка выдана пользователю.';
            }

        // Создать новую игру
        } elseif ($action === 'create_game') {
            $title       = trim($_POST['title'] ?? '');
            $gameType    = $_POST['game_type'] ?? 'case';
            $status      = $_POST['status'] ?? 'upcoming';
            $startsAtStr = trim($_POST['starts_at'] ?? '');
            $external    = trim($_POST['external_link'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $maxPlayers  = trim($_POST['max_players'] ?? '');
            $signupsOpen = isset($_POST['signups_open']) ? 1 : 0;

            // новый параметр — ведущий/владелец игры
            $ownerRaw = trim($_POST['owner_user_id'] ?? '');
            $ownerId  = null;
            if ($ownerRaw !== '' && ctype_digit($ownerRaw)) {
                $ownerId = (int)$ownerRaw;
            }

            $allowedTypes  = ['case', 'minigame', 'event'];
            $allowedStatus = ['upcoming', 'active', 'finished', 'cancelled'];

            if ($title === '') {
                $_SESSION['admin_error'] = 'Название игры не может быть пустым.';
            } else {
                if (!in_array($gameType, $allowedTypes, true)) {
                    $gameType = 'case';
                }
                if (!in_array($status, $allowedStatus, true)) {
                    $status = 'upcoming';
                }

                $startsAt = null;
                if ($startsAtStr !== '') {
                    $dt = DateTime::createFromFormat('Y-m-d H:i', $startsAtStr);
                    if (!$dt) {
                        $dt = DateTime::createFromFormat('d.m.Y H:i', $startsAtStr);
                    }
                    if ($dt) {
                        $startsAt = $dt->format('Y-m-d H:i:s');
                    }
                }

                $maxPlayersVal = null;
                if ($maxPlayers !== '' && ctype_digit($maxPlayers) && (int)$maxPlayers > 0) {
                    $maxPlayersVal = (int)$maxPlayers;
                }

                $st = $pdo->prepare('
                    INSERT INTO games (
                      owner_user_id,
                      title,
                      description,
                      game_type,
                      status,
                      starts_at,
                      external_link,
                      max_players,
                      signups_open,
                      is_featured
                    )
                    VALUES (
                      :owner_user_id,
                      :title,
                      :description,
                      :game_type,
                      :status,
                      :starts_at,
                      :external_link,
                      :max_players,
                      :signups_open,
                      0
                    )
                ');
                $st->execute([
                    'owner_user_id'=> $ownerId,
                    'title'        => $title,
                    'description'  => $description !== '' ? $description : null,
                    'game_type'    => $gameType,
                    'status'       => $status,
                    'starts_at'    => $startsAt,
                    'external_link'=> $external !== '' ? $external : null,
                    'max_players'  => $maxPlayersVal,
                    'signups_open' => $signupsOpen,
                ]);

                $gameId = (int)$pdo->lastInsertId();

                // Если указана дата — добавляем в game_dates
                if ($startsAt !== null) {
                    $st = $pdo->prepare('
                        INSERT INTO game_dates (game_id, starts_at)
                        VALUES (:gid, :starts_at)
                    ');
                    $st->execute([
                        'gid'       => $gameId,
                        'starts_at' => $startsAt,
                    ]);
                }

                $_SESSION['admin_message'] = 'Игра успешно создана.';
            }

        // Обновление игры
        } elseif ($action === 'update_game') {
            $gameId      = (int)($_POST['game_id'] ?? 0);
            $title       = trim($_POST['title'] ?? '');
            $gameType    = $_POST['game_type'] ?? 'case';
            $status      = $_POST['status'] ?? 'upcoming';
            $startsAtStr = trim($_POST['starts_at'] ?? '');
            $external    = trim($_POST['external_link'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $maxPlayers  = trim($_POST['max_players'] ?? '');
            $signupsOpen = isset($_POST['signups_open']) ? 1 : 0;
            $isFeatured  = isset($_POST['is_featured']) ? 1 : 0;

            $ownerRaw = trim($_POST['owner_user_id'] ?? '');
            $ownerId  = null;
            if ($ownerRaw !== '' && ctype_digit($ownerRaw)) {
                $ownerId = (int)$ownerRaw;
            }

            if ($gameId <= 0) {
                $_SESSION['admin_error'] = 'Некорректный ID игры при обновлении.';
            } else {
                $allowedTypes  = ['case', 'minigame', 'event'];
                $allowedStatus = ['upcoming', 'active', 'finished', 'cancelled'];

                if (!in_array($gameType, $allowedTypes, true)) {
                    $gameType = 'case';
                }
                if (!in_array($status, $allowedStatus, true)) {
                    $status = 'upcoming';
                }

                $startsAt = null;
                if ($startsAtStr !== '') {
                    $dt = DateTime::createFromFormat('Y-m-d H:i', $startsAtStr);
                    if (!$dt) {
                        $dt = DateTime::createFromFormat('d.m.Y H:i', $startsAtStr);
                    }
                    if ($dt) {
                        $startsAt = $dt->format('Y-m-d H:i:s');
                    }
                }

                $maxPlayersVal = null;
                if ($maxPlayers !== '' && ctype_digit($maxPlayers) && (int)$maxPlayers > 0) {
                    $maxPlayersVal = (int)$maxPlayers;
                }

                $st = $pdo->prepare('
                    UPDATE games
                    SET owner_user_id = :owner_user_id,
                        title         = :title,
                        description   = :description,
                        game_type     = :game_type,
                        status        = :status,
                        starts_at     = :starts_at,
                        external_link = :external_link,
                        max_players   = :max_players,
                        signups_open  = :signups_open,
                        is_featured   = :is_featured
                    WHERE id = :id
                    LIMIT 1
                ');
                $st->execute([
                    'owner_user_id'=> $ownerId,
                    'title'        => $title !== '' ? $title : 'Игра без названия',
                    'description'  => $description !== '' ? $description : null,
                    'game_type'    => $gameType,
                    'status'       => $status,
                    'starts_at'    => $startsAt,
                    'external_link'=> $external !== '' ? $external : null,
                    'max_players'  => $maxPlayersVal,
                    'signups_open' => $signupsOpen,
                    'is_featured'  => $isFeatured,
                    'id'           => $gameId,
                ]);

                $_SESSION['admin_message'] = 'Игра обновлена.';
            }

        // Добавить дополнительную дату
        } elseif ($action === 'add_game_date') {
            $gameId      = (int)($_POST['game_id'] ?? 0);
            $startsAtStr = trim($_POST['new_date'] ?? '');

            if ($gameId <= 0 || $startsAtStr === '') {
                $_SESSION['admin_error'] = 'Некорректные данные для добавления даты.';
            } else {
                $dt = DateTime::createFromFormat('Y-m-d H:i', $startsAtStr);
                if (!$dt) {
                    $dt = DateTime::createFromFormat('d.m.Y H:i', $startsAtStr);
                }
                if (!$dt) {
                    $_SESSION['admin_error'] = 'Неверный формат даты. Примеры: 2025-11-16 21:00 или 16.11.2025 21:00.';
                } else {
                    $st = $pdo->prepare('
                        INSERT INTO game_dates (game_id, starts_at)
                        VALUES (:gid, :starts_at)
                    ');
                    $st->execute([
                        'gid'       => $gameId,
                        'starts_at' => $dt->format('Y-m-d H:i:s'),
                    ]);
                    $_SESSION['admin_message'] = 'Дата добавлена.';
                }
            }

        // Удалить дату
        } elseif ($action === 'delete_game_date') {
            $dateId = (int)($_POST['date_id'] ?? 0);
            if ($dateId <= 0) {
                $_SESSION['admin_error'] = 'Некорректный ID даты.';
            } else {
                $st = $pdo->prepare('DELETE FROM game_dates WHERE id = :id LIMIT 1');
                $st->execute(['id' => $dateId]);
                $_SESSION['admin_message'] = 'Дата удалена.';
            }

        // Обновить роль игрока в игре
        } elseif ($action === 'update_player_role') {
            $ugId    = (int)($_POST['ug_id'] ?? 0);
            $newRole = trim($_POST['role'] ?? '');

            if ($ugId <= 0 || $newRole === '') {
                $_SESSION['admin_error'] = 'Некорректные данные для изменения роли.';
            } else {
                $st = $pdo->prepare('
                    UPDATE user_games
                    SET role = :role
                    WHERE id = :id
                    LIMIT 1
                ');
                $st->execute([
                    'role' => $newRole,
                    'id'   => $ugId,
                ]);
                $_SESSION['admin_message'] = 'Роль игрока обновлена.';
            }

        // Удалить запись игрока
        } elseif ($action === 'delete_player_signup') {
            $ugId = (int)($_POST['ug_id'] ?? 0);
            if ($ugId <= 0) {
                $_SESSION['admin_error'] = 'Некорректный ID записи игрока.';
            } else {
                $st = $pdo->prepare('DELETE FROM user_games WHERE id = :id LIMIT 1');
                $st->execute(['id' => $ugId]);
                $_SESSION['admin_message'] = 'Игрок удалён из записи.';
            }

        // Удалить игру
        } elseif ($action === 'delete_game') {
            $gameId = (int)($_POST['game_id'] ?? 0);
            if ($gameId <= 0) {
                $_SESSION['admin_error'] = 'Некорректный ID игры для удаления.';
            } else {
                // user_games и game_dates удалятся каскадом, если FK так настроены
                $st = $pdo->prepare('DELETE FROM games WHERE id = :id LIMIT 1');
                $st->execute(['id' => $gameId]);
                $_SESSION['admin_message'] = 'Игра удалена.';
            }

        // --- НОВОСТИ: Создание ---
        } elseif ($action === 'create_news') {
            $title   = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $type    = $_POST['type'] ?? 'news'; // news или update
            $dlLink  = trim($_POST['download_link'] ?? '');

            if ($title === '' || $content === '') {
                $_SESSION['admin_error'] = 'Заголовок и текст новости обязательны.';
            } else {
                $st = $pdo->prepare('
                    INSERT INTO news (author_user_id, title, content, type, download_link)
                    VALUES (:uid, :title, :content, :type, :dl)
                ');
                $st->execute([
                    'uid'     => $currentUser['id'],
                    'title'   => $title,
                    'content' => $content,
                    'type'    => $type,
                    'dl'      => $dlLink !== '' ? $dlLink : null,
                ]);
                $_SESSION['admin_message'] = 'Новость успешно опубликована.';
                header('Location: admin.php#admin-news'); // Редирект на вкладку новостей
                exit;
            }
        }

        // --- НОВОСТИ: Удаление ---
        if ($action === 'delete_news') {
            $newsId = (int)($_POST['news_id'] ?? 0);
            if ($newsId > 0) {
                $st = $pdo->prepare('DELETE FROM news WHERE id = :id LIMIT 1');
                $st->execute(['id' => $newsId]);
                $_SESSION['admin_message'] = 'Новость удалена.';
            }
            header('Location: admin.php#admin-news');
            exit;
        }

        // ============================================
        // НОВАЯ ФУНКЦИОНАЛЬНОСТЬ: МАССОВАЯ РАССЫЛКА
        // ============================================
        if ($action === 'send_broadcast_message') {
            $title            = trim($_POST['title'] ?? '');
            $body             = trim($_POST['body'] ?? '');
            $onlyVerified     = isset($_POST['only_verified']);
            
            // Получаем ID бота
            try {
                $botUserId = get_bot_user_id($pdo);
            } catch (Throwable $e) {
                // Если get_bot_user_id упала, сообщаем об этом
                throw new Exception('Не удалось найти системного пользователя-бота. Назначьте его в настройках администратора.');
            }
            
            if ($botUserId === null) {
                throw new Exception('Не удалось найти системного пользователя-бота. Назначьте его в настройках администратора.');
            }
            if ($title === '' || $body === '') {
                throw new Exception('Заголовок и текст сообщения не могут быть пустыми.');
            }
        
            // 1. Получаем список всех пользователей, кроме самого бота
            $sql = 'SELECT id FROM users WHERE account_role != "bot"';
            
            if ($onlyVerified) {
                $sql .= ' AND is_verified = 1';
            }
            
            $stmt = $pdo->query($sql); // В этом запросе нет внешних параметров
            $targetUserIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
            if (empty($targetUserIds)) {
                throw new Exception('Не найдено ни одного пользователя для рассылки.');
            }
            
            $count = 0;
            
            // 2. Отправляем уведомление каждому пользователю
            // Отправляем как 'message' (сообщения от бота/админа)
            foreach ($targetUserIds as $targetUserId) {
                send_notification(
                    $pdo, 
                    (int)$targetUserId, 
                    $title, 
                    $body, 
                    'message',
                    $botUserId // ID отправителя
                );
                $count++;
            }
        
            // Успех, редирект для очистки POST и вывода сообщения
            $_SESSION['admin_message'] = "Сообщение успешно отправлено $count пользователям.";
            
            // Для корректного редиректа на вкладку, используем хэш
            header('Location: admin.php#admin-notifications');
            exit;
        }

    } catch (PDOException $e) {
        $_SESSION['admin_error'] = 'Ошибка БД: ' . $e->getMessage();
        // В случае ошибки, редирект на текущую вкладку
        if ($action === 'send_broadcast_message') {
             header('Location: admin.php#admin-notifications');
        } else {
             header('Location: admin.php');
        }
        exit;
    } catch (Throwable $e) {
        $_SESSION['admin_error'] = $e->getMessage();
        if ($action === 'send_broadcast_message') {
             header('Location: admin.php#admin-notifications');
        } else {
             header('Location: admin.php');
        }
        exit;
    }

    header('Location: admin.php');
    exit;
}

// --- Сообщения после редиректа ---
$adminMessage = isset($_SESSION['admin_message']) ? $_SESSION['admin_message'] : null;
$adminError   = isset($_SESSION['admin_error']) ? $_SESSION['admin_error'] : null;
unset($_SESSION['admin_message'], $_SESSION['admin_error']);

// --- Данные для вкладок ---

// Пользователи
$users = [];
try {
    $st = $pdo->query('
        SELECT
          u.id,
          u.nickname,
          u.email,
          u.account_role,
          u.role_default,
          u.is_verified,
          u.is_banned,
          u.ban_cases,
          u.ban_minigames,
          u.ban_events,
          COUNT(ug.id) AS games_count
        FROM users u
        LEFT JOIN user_games ug ON ug.user_id = u.id
        GROUP BY u.id
        ORDER BY u.id ASC
    ');
    $users = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
    $botUserId  = null;
    $botUserRow = null;
}

try {
    // notifications.php: сначала смотрит bot_config, потом fallback по роли 'bot'
    $botUserId = get_bot_user_id($pdo);
} catch (Throwable $e) {
    $botUserId = null;
}

if ($botUserId !== null) {
    // Пробуем найти в уже загруженном списке пользователей
    foreach ($users as $u) {
        if ((int)$u['id'] === (int)$botUserId) {
            $botUserRow = $u;
            break;
        }
    }

    // Если не нашли (теоретически, но на всякий), подтягиваем напрямую
    if ($botUserRow === null && $botUserId > 0) {
        $st = $pdo->prepare('SELECT id, nickname, email FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $botUserId]);
        $botUserRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// Справочник ачивок
$allAchievements       = [];
$achievementsLoadError = null;

try {
    $st = $pdo->query('SELECT id, code, title, description FROM achievements ORDER BY id ASC');
    $allAchievements = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $allAchievements       = [];
    $achievementsLoadError = $e->getMessage();
}

// Ачивки по пользователям
$achievementsByUser = [];
try {
    $st = $pdo->query('
        SELECT
          ua.id,
          ua.user_id,
          ua.achievement_id,
          ua.granted_at,
          ua.note,
          a.title,
          a.code
        FROM user_achievements ua
        JOIN achievements a ON a.id = ua.achievement_id
        ORDER BY ua.granted_at DESC
    ');
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $uid = (int)$row['user_id'];
        if (!isset($achievementsByUser[$uid])) {
            $achievementsByUser[$uid] = [];
        }
        $achievementsByUser[$uid][] = $row;
    }
} catch (PDOException $e) {
    $achievementsByUser = [];
}

// Игры
$games = [];
try {
    $st = $pdo->query('
        SELECT
          g.id,
          g.owner_user_id,
          g.title,
          g.description,
          g.game_type,
          g.status,
          g.starts_at,
          g.external_link,
          g.max_players,
          g.signups_open,
          g.is_featured,
          (
            SELECT COUNT(*)
            FROM user_games ug
            WHERE ug.game_id = g.id AND ug.status IN (\'signed\', \'pending\')
          ) AS signed_count
        FROM games g
        ORDER BY g.starts_at IS NULL, g.starts_at DESC, g.id DESC
    ');
    $games = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $games = [];
}

// Даты и игроки по играм
$gameDates   = [];
$gamePlayers = [];

foreach ($games as $g) {
    $gid = (int)$g['id'];

    // Даты
    try {
        $st = $pdo->prepare('
            SELECT id, starts_at
            FROM game_dates
            WHERE game_id = :gid
            ORDER BY starts_at ASC
        ');
        $st->execute(['gid' => $gid]);
        $gameDates[$gid] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $gameDates[$gid] = [];
    }

    // Игроки
    try {
        $st = $pdo->prepare('
            SELECT
              ug.id AS ug_id,
              ug.role,
              ug.status,
              u.nickname,
              u.email
            FROM user_games ug
            JOIN users u ON u.id = ug.user_id
            WHERE ug.game_id = :gid
            ORDER BY u.nickname ASC
        ');
        $st->execute(['gid' => $gid]);
        $gamePlayers[$gid] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $gamePlayers[$gid] = [];
    }
}

// Для активации вкладки по умолчанию / после редиректа
$activeTab = 'admin-users'; // По умолчанию
if (isset($_SERVER['HTTP_REFERER'])) {
    $urlParts = parse_url($_SERVER['HTTP_REFERER']);
    if (isset($urlParts['fragment'])) {
        $activeTab = $urlParts['fragment'];
    }
}
if (isset($_GET['tab'])) {
    $activeTab = 'admin-' . trim($_GET['tab']);
}

// Новости
$allNews = [];
try {
    $st = $pdo->query('
        SELECT n.*, u.nickname as author_name 
        FROM news n 
        LEFT JOIN users u ON u.id = n.author_user_id 
        ORDER BY n.created_at DESC
    ');
    $allNews = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $allNews = [];
}

// Подтверждения (pending_approvals)
$pendingApprovals = [];
try {
    $st = $pdo->query("SELECT pa.*, u.nickname as requester_nick FROM pending_approvals pa LEFT JOIN users u ON JSON_UNQUOTE(JSON_EXTRACT(pa.data, '$.client_id')) = u.id WHERE pa.status = 'pending' ORDER BY pa.created_at ASC");
    $pendingApprovals = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pendingApprovals = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="refresh" content="15">
  <title>Админ-панель — Fair of Contradictions</title>

  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="account.css" />
  <style>
    .admin-main {
      margin-top: 28px;
      padding: 0 22px 24px;
    }

    .admin-header-block {
      margin-bottom: 16px;
      padding: 16px 18px;
      border-radius: 22px;
      background: radial-gradient(circle at top left, rgba(56, 189, 248, 0.22), transparent 60%),
                  rgba(9, 2, 28, 0.96);
      border: 1px solid rgba(191, 219, 254, 0.45);
      box-shadow: 0 16px 40px rgba(0, 0, 0, 0.75);
    }

    .admin-title {
      font-size: 20px;
      font-weight: 800;
      margin-bottom: 4px;
    }

    .admin-subtitle {
      font-size: 13px;
      color: #dbeafe;
    }

    .admin-flash {
      margin-bottom: 12px;
      font-size: 13px;
      border-radius: 12px;
      padding: 8px 10px;
    }

    .admin-flash-ok {
      background: radial-gradient(circle at 0 0, rgba(22, 163, 74, 0.32), transparent 60%),
                  rgba(5, 24, 15, 0.96);
      border: 1px solid rgba(74, 222, 128, 0.9);
      color: #bbf7d0;
    }

    .admin-flash-err {
      background: radial-gradient(circle at 0 0, rgba(239, 68, 68, 0.32), transparent 60%),
                  rgba(30, 7, 18, 0.96);
      border: 1px solid rgba(248, 113, 113, 0.9);
      color: #fecaca;
      white-space: pre-line;
    }

    .admin-tabs {
      display: flex;
      gap: 10px;
      margin-bottom: 14px;
    }

    .admin-tab {
      padding: 7px 14px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      background: rgba(7, 2, 22, 0.9);
      color: #d8c6ff;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition:
        background 0.18s ease-out,
        color 0.18s ease-out,
        box-shadow 0.18s ease-out,
        transform 0.18s ease-out;
    }

    .admin-tab.active {
      background: radial-gradient(circle at 0 0, rgba(59, 130, 246, 0.3), transparent 60%),
                  rgba(15, 23, 42, 0.98);
      color: #e0f2fe;
      box-shadow: 0 10px 28px rgba(59, 130, 246, 0.4);
      transform: translateY(-1px);
    }

    .admin-section {
      display: none;
    }

    .admin-section.active {
      display: block;
    }

    .admin-card {
      padding: 14px 16px;
      border-radius: 18px;
      background: rgba(13, 3, 34, 0.96);
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: 0 16px 38px rgba(0, 0, 0, 0.8);
      margin-bottom: 14px;
    }

    .admin-card-title {
      font-size: 16px;
      margin-bottom: 6px;
    }

    .admin-card-sub {
      font-size: 13px;
      color: #d8c6ff;
      margin-bottom: 10px;
    }

    .admin-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    .admin-table th,
    .admin-table td {
      padding: 6px 8px;
      border-bottom: 1px solid rgba(148, 163, 184, 0.35);
      text-align: left;
    }

    .admin-table th {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #9ca3af;
    }

    .admin-table td {
      color: #e5e7eb;
    }

    .admin-role-badge {
      display: inline-flex;
      align-items: center;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
      border: 1px solid rgba(148, 163, 184, 0.8);
      background: rgba(15, 23, 42, 0.9);
      color: #e5e7eb;
    }

    .admin-role-badge-admin {
      border-color: rgba(248, 113, 113, 0.9);
      background: radial-gradient(circle at 0 0, rgba(248, 113, 113, 0.3), transparent 60%),
                  rgba(30, 7, 68, 0.9);
      color: #fee2e2;
    }

    .admin-flag-pill {
      display: inline-flex;
      align-items: center;
      padding: 1px 6px;
      border-radius: 999px;
      font-size: 11px;
      border: 1px solid rgba(248, 113, 113, 0.9);
      background: rgba(127, 29, 29, 0.8);
      color: #fecaca;
      margin-right: 4px;
      margin-bottom: 2px;
    }

    /* новый зелёный флаг для подтверждённых */
    .admin-flag-pill-positive {
      display: inline-flex;
      align-items: center;
      padding: 1px 6px;
      border-radius: 999px;
      font-size: 11px;
      border: 1px solid rgba(52, 211, 153, 0.9);
      background: rgba(6, 95, 70, 0.85);
      color: #bbf7d0;
      margin-right: 4px;
      margin-bottom: 2px;
    }

    .admin-action-btn {
      padding: 4px 8px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.25);
      background: rgba(15, 23, 42, 0.9);
      color: #e5e7eb;
      font-size: 11px;
      cursor: pointer;
      transition:
        background 0.15s ease-out,
        transform 0.15s ease-out,
        box-shadow 0.15s ease-out;
    }

    .admin-action-btn:hover {
      background: rgba(59, 130, 246, 0.2);
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.8);
      transform: translateY(-1px);
    }

    .admin-action-btn-danger {
      border-color: rgba(248, 113, 113, 0.9);
      color: #fecaca;
    }

    .admin-form-inline {
      display: inline-block;
      margin: 0;
    }

    .admin-form-row {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 8px;
    }

    .admin-form-row .input-field {
      flex: 1 1 200px;
      margin-bottom: 0;
    }

    .admin-form-row-short {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 8px;
    }

    .admin-form-row-short .input-field {
      flex: 1 1 180px;
      margin-bottom: 0;
    }

    .admin-small-hint {
      font-size: 11px;
      color: #9ca3af;
      margin-top: 2px;
    }

    .admin-game-pill {
      display: inline-flex;
      padding: 1px 7px;
      border-radius: 999px;
      border: 1px solid rgba(148, 163, 184, 0.8);
      font-size: 11px;
      color: #e5e7eb;
      background: rgba(15, 23, 42, 0.9);
    }

    .admin-game-pill-type {
      margin-right: 4px;
    }

    .admin-game-pill-status-upcoming {
      border-color: rgba(250, 204, 21, 0.9);
      color: #fef9c3;
    }

    .admin-game-pill-status-active {
      border-color: rgba(52, 211, 153, 0.9);
      color: #bbf7d0;
    }

    .admin-game-pill-status-finished {
      border-color: rgba(148, 163, 184, 0.9);
      color: #e5e7eb;
    }

    .admin-game-pill-status-cancelled {
      border-color: rgba(248, 113, 113, 0.9);
      color: #fecaca;
    }

    .admin-game-pill-featured {
      border-color: rgba(244, 114, 182, 0.9);
      color: #f9a8d4;
    }

    /* Модалки, блоки и т.д. — как было */
    .admin-modal {
      position: fixed;
      inset: 0;
      background: rgba(3, 0, 12, 0.78);
      backdrop-filter: blur(10px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 999;
    }

    .admin-modal.open {
      display: flex;
    }

    .admin-modal-inner {
      width: 100%;
      max-width: 780px;
      max-height: 90vh;
      background: rgba(10, 3, 30, 0.98);
      border-radius: 22px;
      border: 1px solid rgba(255, 255, 255, 0.16);
      box-shadow: 0 26px 80px rgba(0, 0, 0, 0.9);
      padding: 14px 16px 14px;
      overflow-y: auto;
    }

    .admin-modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 8px;
    }

    .admin-modal-title {
      font-size: 15px;
      font-weight: 700;
    }

    .admin-modal-subtitle {
      font-size: 12px;
      color: #d8c6ff;
      margin-bottom: 8px;
    }

    .admin-modal-close {
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.22);
      background: rgba(7, 2, 22, 0.9);
      color: #e5e7eb;
      width: 26px;
      height: 26px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 14px;
    }

    .admin-modal-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
      gap: 12px;
    }

    .admin-modal-block {
      padding: 10px 12px;
      border-radius: 16px;
      background: rgba(8, 2, 24, 0.96);
      border: 1px solid rgba(255, 255, 255, 0.14);
      font-size: 12px;
    }

    .admin-modal-block-title {
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 6px;
    }

    .admin-modal-footer {
      margin-top: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      font-size: 11px;
    }

    .admin-modal-footer-left {
      color: #9ca3af;
    }

    .admin-modal-footer-right {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .admin-modal-date-row,
    .admin-modal-player-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 6px;
      padding: 4px 0;
      border-bottom: 1px dashed rgba(148, 163, 184, 0.25);
    }

    .admin-modal-date-row:last-child,
    .admin-modal-player-row:last-child {
      border-bottom: none;
    }

    .admin-modal-player-main {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .admin-modal-player-name {
      font-weight: 600;
    }

    .admin-modal-player-email {
      font-size: 11px;
      color: #9ca3af;
    }

    .admin-modal-player-actions {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }

    .admin-player-role-input,
    .admin-ach-select {
      font-size: 11px;
      border-radius: 999px;
      padding: 3px 8px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      background: rgba(7, 2, 22, 0.9);
      color: #e5e7eb;
      min-width: 120px;
    }

    .admin-ach-note-input {
      font-size: 11px;
      border-radius: 999px;
      padding: 3px 8px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      background: rgba(7, 2, 22, 0.9);
      color: #e5e7eb;
      min-width: 160px;
    }

    .admin-ach-item {
      font-size: 12px;
      padding: 3px 0;
      border-bottom: 1px dashed rgba(148, 163, 184, 0.3);
    }

    .admin-ach-item:last-child {
      border-bottom: none;
    }

    .admin-ach-item-title {
      font-weight: 600;
      color: #e0f2fe;
    }

    .admin-ach-item-meta {
      font-size: 11px;
      color: #9ca3af;
    }

    .admin-search-row {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      margin-bottom: 10px;
    }

    .admin-search-input {
      border-radius: 999px;
      padding: 5px 10px;
      border: 1px solid rgba(148, 163, 184, 0.6);
      background: rgba(15, 23, 42, 0.9);
      color: #e5e7eb;
      font-size: 13px;
      min-width: 200px;
    }

    .admin-ach-list {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 8px;
      margin-bottom: 10px;
    }

    .admin-ach-list-item {
      padding: 8px 10px;
      border-radius: 12px;
      background: rgba(15, 23, 42, 0.95);
      border: 1px solid rgba(148, 163, 184, 0.45);
      font-size: 12px;
    }

    .admin-ach-list-title {
      font-weight: 600;
      margin-bottom: 2px;
      color: #e0f2fe;
    }

    .admin-ach-list-code {
      font-size: 10px;
      color: #9ca3af;
      text-transform: lowercase;
    }

    @media (max-width: 900px) {
      .admin-main {
        padding: 0 16px 20px;
      }

      .admin-form-row,
      .admin-form-row-short {
        flex-direction: column;
      }

      .admin-modal-grid {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  </style>
</head>
<body>
  <header class="lk-header">
    <div class="lk-logo">
      <div class="lk-logo-circle">AO</div>
      <div class="lk-logo-text-block">
        <div class="lk-logo-title">Fair of Contradictions</div>
        <div class="lk-logo-sub">Админ-панель</div>
      </div>
    </div>

    <nav class="lk-nav">
      <button class="lk-nav-btn" type="button" onclick="window.location.href='index.php'">На главную</button>
      <button class="lk-nav-btn" type="button" onclick="window.location.href='account.php'">Профиль</button>
      <button class="lk-nav-btn lk-nav-btn-active" type="button" onclick="window.location.href='admin.php'">Админ-панель</button>
      <button class="lk-nav-btn" type="button" onclick="window.location.href='logout.php'">Выйти</button>
    </nav>
  </header>

  <main class="admin-main">
    <section class="admin-header-block">
      <div class="admin-title">Панель администратора</div>
      <div class="admin-subtitle">
        Управление пользователями, ролями, ограничениями и играми сервера Fair of Contradictions.
      </div>
    </section>

    <?php if ($adminMessage): ?>
      <div class="admin-flash admin-flash-ok">
        <?= htmlspecialchars($adminMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($adminError): ?>
      <div class="admin-flash admin-flash-err">
        <?= htmlspecialchars($adminError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <section class="admin-tabs">
      <button class="admin-tab <?= $activeTab === 'admin-users' ? 'active' : '' ?>" type="button" data-section="admin-users">Пользователи</button>
      <button class="admin-tab <?= $activeTab === 'admin-games' ? 'active' : '' ?>" type="button" data-section="admin-games">Игры</button>
      <button class="admin-tab <?= $activeTab === 'admin-notifications' ? 'active' : '' ?>" type="button" data-section="admin-notifications">Уведомления</button>
      <button class="admin-tab <?= $activeTab === 'admin-news' ? 'active' : '' ?>" type="button" data-section="admin-news">Новости</button>
      <button class="admin-tab <?= $activeTab === 'admin-approvals' ? 'active' : '' ?>" type="button" data-section="admin-approvals">Подтверждения</button>
    </section>

    <section class="admin-section <?= $activeTab === 'admin-users' ? 'active' : '' ?>" id="admin-users">
      <article class="admin-card">
        <div class="admin-card-title">Пользователи</div>
        <div class="admin-card-sub">
          Системные роли, ограничения на участие в играх и выдача ачивок.
        </div>

        <div class="admin-search-row">
          <input
            type="text"
            id="userSearchInput"
            class="admin-search-input"
            placeholder="Поиск по нику или email..."
          />
          <span class="admin-small-hint">
            Фильтрация происходит на клиенте — введи часть ника или почты.
          </span>
        </div>

        <?php if (!$users): ?>
          <p class="muted-text">Пользователей пока нет.</p>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="admin-table" id="usersTable">
              <thead>
                <tr>
                  <th>#</th>
                  <th>ID</th>
                  <th>Ник</th>
                  <th>Email</th>
                  <th>Роль (система)</th>
                  <th>Ограничения</th>
                  <th>Игр</th>
                  <th>Действия</th>
                </tr>
              </thead>
              <tbody>
              <?php $rowNum = 1; ?>
              <?php foreach ($users as $u): ?>
                <?php
                  $uid = (int)$u['id'];
                  $isVerified = (int)($u['is_verified'] ?? 0) === 1;
                  $isBanned   = (int)($u['is_banned'] ?? 0) === 1;
                  $banCases   = (int)($u['ban_cases'] ?? 0) === 1;
                  $banMini    = (int)($u['ban_minigames'] ?? 0) === 1;
                  $banEvents  = (int)($u['ban_events'] ?? 0) === 1;
                ?>
                <tr data-user-row>
                  <td><?= $rowNum++ ?></td>
                  <td><?= $uid ?></td>
                  <td data-user-nick><?= htmlspecialchars($u['nickname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td data-user-email><?= htmlspecialchars($u['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td>
                    <?php
                      $sysRole = $u['account_role'] ?? 'player';
                    ?>
                    <?php if ($sysRole === 'admin'): ?>
                      <span class="admin-role-badge admin-role-badge-admin">Администратор</span>
                    <?php elseif ($sysRole === 'bot'): ?>
                      <span class="admin-role-badge admin-role-badge-admin">Бот-информатор</span>
                    <?php else: ?>
                      <span class="admin-role-badge">Игрок</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($isVerified): ?>
                      <span class="admin-flag-pill-positive">Подтверждён</span>
                    <?php else: ?>
                      <span class="admin-flag-pill">Не подтверждён</span>
                    <?php endif; ?>

                    <?php if ($isBanned): ?>
                      <span class="admin-flag-pill">Аккаунт заблокирован</span>
                    <?php endif; ?>
                    <?php if ($banCases): ?>
                      <span class="admin-flag-pill">Нет кейсов</span>
                    <?php endif; ?>
                    <?php if ($banMini): ?>
                      <span class="admin-flag-pill">Нет мини-игр</span>
                    <?php endif; ?>
                    <?php if ($banEvents): ?>
                      <span class="admin-flag-pill">Нет ивентов</span>
                    <?php endif; ?>

                    <?php if (!$isBanned && !$banCases && !$banMini && !$banEvents): ?>
                      <?php if ($isVerified): ?>
                        <span class="admin-small-hint">Без ограничений</span>
                      <?php else: ?>
                        <span class="admin-small-hint">Дополнительных ограничений нет</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td><?= (int)$u['games_count'] ?></td>
                  <td>
                    <?php
                      $sysRole = $u['account_role'] ?? 'player';
                    ?>
                    <?php if ((int)$u['id'] !== (int)$currentUser['id']): ?>
                      <?php if ($sysRole === 'admin'): ?>
                        <form class="admin-form-inline" action="admin.php" method="post">
                          <input type="hidden" name="action" value="change_role" />
                          <input type="hidden" name="user_id" value="<?= $uid ?>" />
                          <input type="hidden" name="new_role" value="player" />
                          <button type="submit" class="admin-action-btn">Сделать игроком</button>
                        </form>
                      <?php elseif ($sysRole === 'player'): ?>
                        <form class="admin-form-inline" action="admin.php" method="post">
                          <input type="hidden" name="action" value="change_role" />
                          <input type="hidden" name="user_id" value="<?= $uid ?>" />
                          <input type="hidden" name="new_role" value="admin" />
                          <button type="submit" class="admin-action-btn">Сделать админом</button>
                        </form>
                      <?php elseif ($sysRole === 'bot'): ?>
                        <span class="admin-small-hint">Роль «Бот» меняется в блоке ниже.</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="admin-small-hint">Это вы</span>
                    <?php endif; ?>

                    <button
                      type="button"
                      class="admin-action-btn"
                      onclick="openUserModal(<?= $uid ?>)"
                      style="margin-left:4px;"
                    >
                      Управление
                    </button>
                  </td>
                </tr>
                <div class="admin-modal" id="admin-user-modal-<?= $uid ?>">
                  <div class="admin-modal-inner">
                    <div class="admin-modal-header">
                      <div>
                        <div class="admin-modal-title">
                          Пользователь #<?= $uid ?> — <?= htmlspecialchars($u['nickname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </div>
                        <div class="admin-modal-subtitle">
                          Редактирование ограничений, просмотр статистики и выдача ачивок.
                        </div>
                      </div>
                      <button type="button" class="admin-modal-close" onclick="closeUserModal(<?= $uid ?>)">✕</button>
                    </div>

                    <div class="admin-modal-grid">
                      <div class="admin-modal-block">
                        <div class="admin-modal-block-title">Ограничения доступа</div>
                        <p class="admin-small-hint" style="margin-bottom:6px;">
                          Email: <?= htmlspecialchars($u['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
                          Системная роль:
                          <?php if (($u['account_role'] ?? 'player') === 'admin'): ?>
                            Администратор
                          <?php else: ?>
                            Игрок
                          <?php endif; ?><br>
                          Сыграно игр (записей): <?= (int)$u['games_count'] ?>
                        </p>

                        <form action="admin.php" method="post" style="margin-top:4px;">
                          <input type="hidden" name="action" value="update_user_flags" />
                          <input type="hidden" name="user_id" value="<?= $uid ?>" />

                          <div class="input-field">
                            <label class="checkbox-label">
                              <input type="checkbox" name="is_verified" <?= $isVerified ? 'checked' : '' ?> />
                              <span>Аккаунт подтверждён (контакты проверены в Discord)</span>
                            </label>
                          </div>

                          <div class="input-field">
                            <label class="checkbox-label">
                              <input type="checkbox" name="is_banned" <?= $isBanned ? 'checked' : '' ?> />
                              <span>Аккаунт заблокирован (полный запрет участия в играх)</span>
                            </label>
                          </div>

                          <div class="input-field">
                            <label class="checkbox-label">
                              <input type="checkbox" name="ban_cases" <?= $banCases ? 'checked' : '' ?> />
                              <span>Запрет записи на кейсы</span>
                            </label>
                          </div>

                          <div class="input-field">
                            <label class="checkbox-label">
                              <input type="checkbox" name="ban_minigames" <?= $banMini ? 'checked' : '' ?> />
                              <span>Запрет записи на мини-игры</span>
                            </label>
                          </div>

                          <div class="input-field">
                            <label class="checkbox-label">
                              <input type="checkbox" name="ban_events" <?= $banEvents ? 'checked' : '' ?> />
                              <span>Запрет записи на ивенты</span>
                            </label>
                          </div>

                          <button type="submit" class="admin-action-btn" style="margin-top:8px;">
                            Сохранить ограничения
                          </button>
                        </form>
                      </div>

                      <div class="admin-modal-block">
                        <div class="admin-modal-block-title">Ачивки пользователя</div>

                        <div style="margin-bottom:6px; max-height:180px; overflow:auto;">
                          <?php
                            $userAchs = $achievementsByUser[$uid] ?? [];
                          ?>
                          <?php if (!$userAchs): ?>
                            <div class="admin-small-hint">Ачивок пока нет.</div>
                          <?php else: ?>
                            <?php foreach ($userAchs as $ach): ?>
                              <div class="admin-ach-item">
                                <div class="admin-ach-item-title">
                                  <?= htmlspecialchars($ach['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                  <span style="opacity:.7; font-size:10px;">
                                    (<?= htmlspecialchars($ach['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                                  </span>
                                </div>
                                <div class="admin-ach-item-meta">
                                  Выдана: <?= htmlspecialchars($ach['granted_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                  <?php if (!empty($ach['note'])): ?>
                                    · заметка: <?= htmlspecialchars($ach['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                  <?php endif; ?>
                                </div>
                              </div>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </div>

                        <hr style="border:none;border-top:1px solid rgba(148,163,184,0.35);margin:8px 0;" />

                        <form action="admin.php" method="post">
                          <input type="hidden" name="action" value="grant_achievement" />
                          <input type="hidden" name="user_id" value="<?= $uid ?>" />

                          <div class="input-field">
                            <label>Выдать ачивку</label>
                            <select name="achievement_id" class="admin-ach-select">
                              <?php if (!$allAchievements): ?>
                                <option value="">Нет ачивок в справочнике</option>
                              <?php else: ?>
                                <?php foreach ($allAchievements as $ach): ?>
                                  <option value="<?= (int)$ach['id'] ?>">
                                    <?= htmlspecialchars($ach['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    (<?= htmlspecialchars($ach['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                                  </option>
                                <?php endforeach; ?>
                              <?php endif; ?>
                            </select>
                          </div>

                          <div class="input-field" style="margin-top:6px;">
                            <label>Заметка (опционально)</label>
                            <input
                              type="text"
                              name="note"
                              class="admin-ach-note-input"
                              placeholder="Например: за первый успешно проведённый кейс"
                            />
                          </div>

                          <button
                            type="submit"
                            class="admin-action-btn"
                            style="margin-top:8px;"
                            <?= !$allAchievements ? 'disabled' : '' ?>
                          >
                            Выдать ачивку
                          </button>
                        </form>
                      </div>
                    </div>

                    <div class="admin-modal-footer">
                      <div class="admin-modal-footer-left">
                        ID <?= $uid ?> · управление доступом к играм и ачивками.
                      </div>
                      <div class="admin-modal-footer-right">
                        <button type="button" class="admin-action-btn" onclick="closeUserModal(<?= $uid ?>)">Закрыть</button>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </article>

      <article class="admin-card">
        <div class="admin-card-title">Бот-информатор</div>
        <div class="admin-card-sub">
          Пользователь, от имени которого приходят системные сообщения и уведомления в ЛК.
          Рекомендуется создать отдельный милый аккаунт-девочку и указать его здесь.
        </div>

        <div style="margin-bottom:8px;">
          <?php if ($botUserRow): ?>
            <div class="admin-small-hint">
              Текущий бот: <strong>
                #<?= (int)$botUserRow['id'] ?>
                — <?= htmlspecialchars($botUserRow['nickname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </strong>
              (<?= htmlspecialchars($botUserRow['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
            </div>
          <?php else: ?>
            <div class="admin-small-hint">
              Бот ещё не выбран. Сообщения при регистрации и уведомления в ЛК отправляться не будут, пока не назначен бот.
            </div>
          <?php endif; ?>
        </div>

        <form action="admin.php" method="post" class="admin-form-inline" style="display:flex;flex-wrap:wrap;gap:8px;">
          <input type="hidden" name="action" value="set_bot_informator" />

          <div class="input-field" style="flex:1 1 260px;">
            <label>Выбрать пользователя как бота-информатора</label>
            <select name="bot_user_id" style="width:100%; padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,0.25); background:rgba(9,2,26,0.9); color:#fff; font-size:13px;">
              <option value="0">— выбрать пользователя —</option>
              <?php foreach ($users as $u): ?>
                <option
                  value="<?= (int)$u['id'] ?>"
                  <?= ($botUserId !== null && (int)$botUserId === (int)$u['id']) ? 'selected' : '' ?>
                >
                  #<?= (int)$u['id'] ?> —
                  <?= htmlspecialchars($u['nickname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  (<?= htmlspecialchars($u['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="input-field" style="align-self:flex-end;">
            <button type="submit" class="submit-btn">Сохранить бота-информатора</button>
            <div class="admin-small-hint">
              При смене бота прежний пользователь теряет роль «bot» и становится обычным игроком.
            </div>
          </div>
        </form>
      </article>

      <article class="admin-card">
        <div class="admin-card-title">Справочник ачивок</div>
        <div class="admin-card-sub">
          Здесь создаются ачивки, которые затем можно выдавать пользователям.
        </div>

        <?php if (!empty($achievementsLoadError)): ?>
          <div class="admin-flash admin-flash-err" style="margin-top:8px;">
            Ошибка при загрузке справочника ачивок:
            <?= htmlspecialchars($achievementsLoadError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </div>
        <?php elseif ($allAchievements): ?>
          <div class="admin-ach-list">
            <?php foreach ($allAchievements as $ach): ?>
              <div class="admin-ach-list-item">
                <div class="admin-ach-list-title">
                  <?= htmlspecialchars($ach['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
                <div class="admin-ach-list-code">
                  Код: <?= htmlspecialchars($ach['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
                <?php if (!empty($ach['description'])): ?>
                  <div class="admin-small-hint" style="margin-top:4px;">
                    <?= nl2br(htmlspecialchars($ach['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="admin-small-hint">
            Пока нет ни одной ачивки. Создай первую с помощью формы ниже.
          </p>
        <?php endif; ?>

        <form action="admin.php" method="post" style="margin-top:8px;">
          <input type="hidden" name="action" value="create_achievement" />

          <div class="admin-form-row">
            <div class="input-field">
              <label>Код (латиницей, уникальный)</label>
              <input type="text" name="code" placeholder="first_case_win" />
            </div>
            <div class="input-field">
              <label>Название ачивки</label>
              <input type="text" name="title" placeholder="Первый выигранный кейс" />
            </div>
          </div>

          <div class="input-field">
            <label>Описание (опционально)</label>
            <textarea
              name="description"
              rows="2"
              style="resize: vertical; padding: 8px 10px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.22); background: rgba(9,2,26,0.88); color: #fff; font-size: 13px;"
            ></textarea>
          </div>

          <button type="submit" class="submit-btn">Создать ачивку</button>
        </form>
      </article>
    </section>

    <section class="admin-section <?= $activeTab === 'admin-games' ? 'active' : '' ?>" id="admin-games">
      <article class="admin-card">
        <div class="admin-card-title">Создать игру / кейс</div>
        <div class="admin-card-sub">
          Игры отсюда попадают в календарь и могут быть помечены как «Активные» для главной.
        </div>

        <form action="admin.php" method="post">
          <input type="hidden" name="action" value="create_game" />

          <div class="admin-form-row">
            <div class="input-field">
              <label for="g-title">Название</label>
              <input id="g-title" name="title" type="text" required />
            </div>
            <div class="input-field">
              <label for="g-type">Тип</label>
              <select id="g-type" name="game_type">
                <option value="case">Кейс</option>
                <option value="minigame">Мини-игра</option>
                <option value="event">Ивент</option>
              </select>
            </div>
          </div>

          <div class="admin-form-row-short">
            <div class="input-field">
              <label for="g-status">Статус</label>
              <select id="g-status" name="status">
                <option value="upcoming">Анонс / набор</option>
                <option value="active">Идёт сейчас</option>
                <option value="finished">Завершено</option>
                <option value="cancelled">Отменено</option>
              </select>
            </div>
            <div class="input-field">
              <label for="g-starts">Дата и время (первая)</label>
              <input
                id="g-starts"
                name="starts_at"
                type="text"
                placeholder="2025-11-16 21:00 или 16.11.2025 21:00"
              />
              <div class="admin-small-hint">Первая дата также попадёт в календарь.</div>
            </div>
          </div>

          <div class="admin-form-row-short">
            <div class="input-field">
              <label for="g-max">Максимум игроков (опционально)</label>
              <input
                id="g-max"
                name="max_players"
                type="number"
                min="1"
                step="1"
                placeholder="например, 6"
              />
              <div class="admin-small-hint">Оставь пустым, если лимит не нужен.</div>
            </div>
            <div class="input-field">
              <label>Набор открыт</label>
              <label class="checkbox-label">
                <input type="checkbox" name="signups_open" checked />
                <span>Разрешить записываться через календарь</span>
              </label>
            </div>
          </div>

          <div class="input-field">
            <label for="g-owner">Ведущий / ответственный игрок (опционально)</label>
            <select id="g-owner" name="owner_user_id">
              <option value="">— не указан —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>">
                  #<?= (int)$u['id'] ?> —
                  <?= htmlspecialchars($u['nickname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  (<?= htmlspecialchars($u['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="admin-small-hint">
              Этот игрок увидит игру в своём личном кабинете и сможет ею управлять.
            </div>
          </div>

          <div class="input-field">
            <label for="g-link">Ссылка с описанием / правилами</label>
            <input id="g-link" name="external_link" type="text" placeholder="https://..." />
          </div>

          <div class="input-field">
            <label for="g-desc">Краткое описание (опционально)</label>
            <textarea
              id="g-desc"
              name="description"
              rows="3"
              style="resize: vertical; padding: 8px 10px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.22); background: rgba(9,2,26,0.88); color: #fff; font-size: 13px;"
            ></textarea>
          </div>

          <button class="submit-btn" type="submit">Создать игру</button>
        </form>
      </article>

      <article class="admin-card">
        <div class="admin-card-title">Список игр</div>
        <div class="admin-card-sub">
          Управление играми, датами, набором и активными играми на главной. Список игроков — внутри каждой игры.
        </div>

        <?php if (!$games): ?>
          <p class="muted-text">Игр пока нет.</p>
        <?php else: ?>
          <div style="overflow-x:auto;">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>ID</th>
                  <th>Название</th>
                  <th>Тип / статус</th>
                  <th>Первая дата</th>
                  <th>Набор</th>
                  <th>Главная</th>
                  <th>Игроков</th>
                  <th>Ведущий</th>
                  <th>Управление</th>
                </tr>
              </thead>
              <tbody>
                <?php $rowNum = 1; ?>
                <?php foreach ($games as $g): ?>
                  <?php
                    $gid = (int)$g['id'];

                    $typeLabel = 'Игра';
                    if ($g['game_type'] === 'case') {
                        $typeLabel = 'Кейс';
                    } elseif ($g['game_type'] === 'minigame') {
                        $typeLabel = 'Мини-игра';
                    } elseif ($g['game_type'] === 'event') {
                        $typeLabel = 'Ивент';
                    }

                    $statusClass = 'admin-game-pill-status-upcoming';
                    $statusLabel = 'Анонс / набор';

                    if ($g['status'] === 'active') {
                        $statusClass = 'admin-game-pill-status-active';
                        $statusLabel = 'Идёт сейчас';
                    } elseif ($g['status'] === 'finished') {
                        $statusClass = 'admin-game-pill-status-finished';
                        $statusLabel = 'Завершено';
                    } elseif ($g['status'] === 'cancelled') {
                        $statusClass = 'admin-game-pill-status-cancelled';
                        $statusLabel = 'Отменено';
                    }

                    $dateText = '—';
                    if (!empty($g['starts_at'])) {
                        $dt = new DateTime($g['starts_at']);
                        $dateText = $dt->format('d.m.Y H:i');
                    }

                    $signedCount = (int)($g['signed_count'] ?? 0);
                    $maxPlayers  = $g['max_players'] !== null ? (int)$g['max_players'] : null;
                    $signupsOpen = (int)$g['signups_open'] === 1;
                    $isFeatured  = (int)$g['is_featured'] === 1;

                    // ищем ник ведущего, если есть
                    $ownerName = null;
                    if (!empty($g['owner_user_id'])) {
                        foreach ($users as $uOwner) {
                            if ((int)$uOwner['id'] === (int)$g['owner_user_id']) {
                                $ownerName = $uOwner['nickname'];
                                break;
                            }
                        }
                    }
                  ?>
                  <tr>
                    <td><?= $rowNum++ ?></td>
                    <td><?= $gid ?></td>
                    <td><?= htmlspecialchars($g['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td>
                      <span class="admin-game-pill admin-game-pill-type">
                        <?= htmlspecialchars($typeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </span>
                      <span class="admin-game-pill <?= $statusClass ?>">
                        <?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars($dateText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td>
                      <?php if ($signupsOpen): ?>
                        <span class="admin-game-pill admin-game-pill-status-active">
                          <?= $signedCount ?> /
                          <?= $maxPlayers !== null ? $maxPlayers : '∞' ?>
                        </span>
                      <?php else: ?>
                        <span class="admin-game-pill admin-game-pill-status-cancelled">
                          набор закрыт
                        </span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($isFeatured): ?>
                        <span class="admin-game-pill admin-game-pill-featured">Активная</span>
                      <?php else: ?>
                        <span class="admin-small-hint">—</span>
                      <?php endif; ?>
                    </td>
                    <td><?= $signedCount ?></td>
                    <td>
                      <?php if ($ownerName): ?>
                        <span class="admin-small-hint">
                          <?= htmlspecialchars($ownerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </span>
                      <?php else: ?>
                        <span class="admin-small-hint">—</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <button
                        type="button"
                        class="admin-action-btn"
                        onclick="openGameModal(<?= $gid ?>)"
                      >
                        Управление
                      </button>
                    </td>
                  </tr>

                  <div class="admin-modal" id="admin-modal-<?= $gid ?>">
                    <div class="admin-modal-inner">
                      <div class="admin-modal-header">
                        <div>
                          <div class="admin-modal-title">
                            Игра #<?= $gid ?> — <?= htmlspecialchars($g['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                          </div>
                          <div class="admin-modal-subtitle">
                            Полное управление: тип, статус, даты, набор, активность на главной и записанные игроки.
                          </div>
                        </div>
                        <button
                          type="button"
                          class="admin-modal-close"
                          onclick="closeGameModal(<?= $gid ?>)"
                        >✕</button>
                      </div>

                      <div class="admin-modal-grid">
                        <div class="admin-modal-block">
                          <div class="admin-modal-block-title">Общие настройки</div>

                          <form action="admin.php" method="post">
                            <input type="hidden" name="action" value="update_game" />
                            <input type="hidden" name="game_id" value="<?= $gid ?>" />

                            <div class="input-field">
                              <label>Название</label>
                              <input
                                type="text"
                                name="title"
                                value="<?= htmlspecialchars($g['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                              />
                            </div>

                            <div class="admin-form-row-short">
                              <div class="input-field">
                                <label>Тип</label>
                                <select name="game_type">
                                  <option value="case"     <?= $g['game_type'] === 'case' ? 'selected' : '' ?>>Кейс</option>
                                  <option value="minigame" <?= $g['game_type'] === 'minigame' ? 'selected' : '' ?>>Мини-игра</option>
                                  <option value="event"    <?= $g['game_type'] === 'event' ? 'selected' : '' ?>>Ивент</option>
                                </select>
                              </div>
                              <div class="input-field">
                                <label>Статус</label>
                                <select name="status">
                                  <option value="upcoming" <?= $g['status'] === 'upcoming' ? 'selected' : '' ?>>Анонс / набор</option>
                                  <option value="active"   <?= $g['status'] === 'active'   ? 'selected' : '' ?>>Идёт сейчас</option>
                                  <option value="finished" <?= $g['status'] === 'finished' ? 'selected' : '' ?>>Завершено</option>
                                  <option value="cancelled"<?= $g['status'] === 'cancelled'? 'selected' : '' ?>>Отменено</option>
                                </select>
                              </div>
                            </div>

                            <div class="admin-form-row-short">
                              <div class="input-field">
                                <label>Первая дата (для списка / главной)</label>
                                <input
                                  type="text"
                                  name="starts_at"
                                  value="<?= !empty($g['starts_at']) ? (new DateTime($g['starts_at']))->format('Y-m-d H:i') : '' ?>"
                                  placeholder="2025-11-16 21:00 или 16.11.2025 21:00"
                                />
                              </div>
                              <div class="input-field">
                                <label>Максимум игроков</label>
                                <input
                                  type="number"
                                  name="max_players"
                                  min="1"
                                  step="1"
                                  value="<?= $g['max_players'] !== null ? (int)$g['max_players'] : '' ?>"
                                  placeholder="∞"
                                />
                                <div class="admin-small-hint">Пусто — без лимита.</div>
                              </div>
                            </div>

                            <div class="admin-form-row-short">
                              <div class="input-field">
                                <label class="checkbox-label">
                                  <input type="checkbox" name="signups_open" <?= $signupsOpen ? 'checked' : '' ?> />
                                  <span>Набор открыт через календарь</span>
                                </label>
                              </div>
                              <div class="input-field">
                                <label class="checkbox-label">
                                  <input type="checkbox" name="is_featured" <?= $isFeatured ? 'checked' : '' ?> />
                                  <span>Показывать в «Активных играх» на главной</span>
                                </label>
                              </div>
                            </div>

                            <div class="input-field">
                              <label>Ведущий / ответственный</label>
                              <select name="owner_user_id">
                                <option value="">— не указан —</option>
                                <?php foreach ($users as $uOwner): ?>
                                  <option
                                    value="<?= (int)$uOwner['id'] ?>"
                                    <?= (int)$g['owner_user_id'] === (int)$uOwner['id'] ? 'selected' : '' ?>
                                  >
                                    #<?= (int)$uOwner['id'] ?> —
                                    <?= htmlspecialchars($uOwner['nickname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                  </option>
                                <?php endforeach; ?>
                              </select>
                              <div class="admin-small-hint">
                                Ведущий увидит игру в своём личном кабинете и сможет ею управлять.
                              </div>
                            </div>

                            <div class="input-field">
                              <label>Ссылка с описанием / правилами</label>
                              <input
                                type="text"
                                name="external_link"
                                value="<?= htmlspecialchars($g['external_link'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                placeholder="https://..."
                              />
                            </div>

                            <div class="input-field">
                              <label>Описание</label>
                              <textarea
                                name="description"
                                rows="3"
                                style="resize: vertical; padding: 8px 10px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.22); background: rgba(9,2,26,0.88); color: #fff; font-size: 13px;"
                              ><?= htmlspecialchars($g['description'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                            </div>

                            <button type="submit" class="submit-btn">Сохранить изменения</button>
                          </form>
                        </div>

                        <div class="admin-modal-block">
                          <div class="admin-modal-block-title">Даты и игроки</div>

                          <div style="margin-bottom: 8px;">
                            <strong style="font-size:12px;">Даты игры в календаре</strong>
                            <div class="admin-small-hint">
                              Эти даты попадают в визуальный календарь на сайте.
                            </div>

                            <div style="margin-top:4px;">
                              <?php if (empty($gameDates[$gid])): ?>
                                <div class="admin-small-hint">Пока нет дополнительных дат. Можно добавить ниже.</div>
                              <?php else: ?>
                                <?php foreach ($gameDates[$gid] as $d): ?>
                                  <?php $dt = new DateTime($d['starts_at']); ?>
                                  <div class="admin-modal-date-row">
                                    <span><?= $dt->format('d.m.Y H:i') ?></span>
                                    <form
                                      action="admin.php"
                                      method="post"
                                      class="admin-form-inline"
                                      onsubmit="return confirm('Удалить эту дату?');"
                                    >
                                      <input type="hidden" name="action" value="delete_game_date" />
                                      <input type="hidden" name="date_id" value="<?= (int)$d['id'] ?>" />
                                      <button type="submit" class="admin-action-btn admin-action-btn-danger">
                                        Удалить
                                      </button>
                                    </form>
                                  </div>
                                <?php endforeach; ?>
                              <?php endif; ?>
                            </div>

                            <form action="admin.php" method="post" style="margin-top:6px;">
                              <input type="hidden" name="action" value="add_game_date" />
                              <input type="hidden" name="game_id" value="<?= $gid ?>" />
                              <div class="admin-form-row-short">
                                <div class="input-field">
                                  <label>Новая дата</label>
                                  <input
                                    type="text"
                                    name="new_date"
                                    placeholder="2025-11-16 21:00 или 16.11.2025 21:00"
                                  />
                                </div>
                                <div class="input-field" style="display:flex; align-items:flex-end;">
                                  <button type="submit" class="admin-action-btn">Добавить дату</button>
                                </div>
                              </div>
                            </form>
                          </div>

                          <hr style="border:none;border-top:1px solid rgba(148,163,184,0.35);margin:8px 0;" />

                          <div>
                            <strong style="font-size:12px;">Записанные игроки</strong>
                            <div class="admin-small-hint">
                              Можно изменить роль в игре или удалить запись.
                            </div>

                            <div style="margin-top:4px; max-height:200px; overflow:auto;">
                              <?php if (empty($gamePlayers[$gid])): ?>
                                <div class="admin-small-hint">На эту игру пока никто не записался.</div>
                              <?php else: ?>
                                <?php foreach ($gamePlayers[$gid] as $p): ?>
                                  <div class="admin-modal-player-row">
                                    <div class="admin-modal-player-main">
                                      <span class="admin-modal-player-name">
                                        <?= htmlspecialchars($p['nickname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                      </span>
                                      <span class="admin-modal-player-email">
                                        <?= htmlspecialchars($p['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        · статус: <?= htmlspecialchars($p['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                      </span>
                                    </div>
                                    <div class="admin-modal-player-actions">
                                      <form action="admin.php" method="post" class="admin-form-inline">
                                        <input type="hidden" name="action" value="update_player_role" />
                                        <input type="hidden" name="ug_id" value="<?= (int)$p['ug_id'] ?>" />
                                        <input
                                          type="text"
                                          name="role"
                                          class="admin-player-role-input"
                                          value="<?= htmlspecialchars($p['role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                        />
                                        <button type="submit" class="admin-action-btn">Сохранить роль</button>
                                      </form>
                                      <form
                                        action="admin.php"
                                        method="post"
                                        class="admin-form-inline"
                                        onsubmit="return confirm('Удалить этого игрока из игры?');"
                                      >
                                        <input type="hidden" name="action" value="delete_player_signup" />
                                        <input type="hidden" name="ug_id" value="<?= (int)$p['ug_id'] ?>" />
                                        <button type="submit" class="admin-action-btn admin-action-btn-danger">
                                          Удалить
                                        </button>
                                      </form>
                                    </div>
                                  </div>
                                <?php endforeach; ?>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="admin-modal-footer">
                        <div class="admin-modal-footer-left">
                          Создана игра ID <?= $gid ?>. Все изменения применяются сразу после сохранения.
                        </div>
                        <div class="admin-modal-footer-right">
                          <form
                            action="admin.php"
                            method="post"
                            onsubmit="return confirm('Точно удалить игру и все записи игроков?');"
                          >
                            <input type="hidden" name="action" value="delete_game" />
                            <input type="hidden" name="game_id" value="<?= $gid ?>" />
                            <button type="submit" class="admin-action-btn admin-action-btn-danger">
                              Удалить игру
                            </button>
                          </form>
                          <button
                            type="button"
                            class="admin-action-btn"
                            onclick="closeGameModal(<?= $gid ?>)"
                          >
                            Закрыть
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </article>
    </section>

<section class="admin-section <?= $activeTab === 'admin-news' ? 'active' : '' ?>" id="admin-news">
      <article class="admin-card">
        <div class="admin-card-title">Опубликовать новость</div>
        <div class="admin-card-sub">
          "Обновление" отличается визуально и позволяет прикрепить ссылку на скачивание.
        </div>

        <form action="admin.php" method="post">
          <input type="hidden" name="action" value="create_news" />

          <div class="admin-form-row">
            <div class="input-field">
              <label>Заголовок</label>
              <input type="text" name="title" required placeholder="Например: Обновление клиента v2.0">
            </div>
            <div class="input-field">
                <label>Тип публикации</label>
                <select name="type" id="newsTypeSelect" onchange="toggleDlLink()">
                    <option value="news">Обычная новость</option>
                    <option value="update">Техническое обновление</option>
                </select>
            </div>
          </div>

          <div class="input-field" id="dlLinkField" style="display:none;">
              <label>Ссылка на скачивание (Google Drive / Yandex Disk)</label>
              <input type="text" name="download_link" placeholder="https://...">
              <div class="admin-small-hint">Появится кнопка "Скачать" внутри поста.</div>
          </div>

          <div class="input-field">
            <label>Текст новости</label>
            <textarea name="content" rows="6" required style="resize: vertical; padding: 8px 10px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.22); background: rgba(9,2,26,0.88); color: #fff; font-size: 13px;"></textarea>
            <div class="admin-small-hint">Поддерживает HTML теги: &lt;b&gt;, &lt;i&gt;, &lt;br&gt;, &lt;ul&gt;, &lt;li&gt;.</div>
          </div>

          <button class="submit-btn" type="submit">Опубликовать</button>
        </form>
      </article>

      <article class="admin-card">
        <div class="admin-card-title">История публикаций</div>
        <?php if (!$allNews): ?>
            <p class="muted-text">Новостей пока нет.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Заголовок</th>
                            <th>Тип</th>
                            <th>Автор</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allNews as $n): ?>
                            <tr>
                                <td><?= (new DateTime($n['created_at']))->format('d.m.Y') ?></td>
                                <td>
                                    <a href="news.php?id=<?= $n['id'] ?>" target="_blank" style="color:#fff; text-decoration:underline;">
                                        <?= htmlspecialchars($n['title']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($n['type'] === 'update'): ?>
                                        <span class="admin-flag-pill-positive">Обновление</span>
                                    <?php else: ?>
                                        <span class="admin-flag-pill" style="border-color:#a855f7; background:#581c87; color:#d8b4fe;">Новости</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($n['author_name'] ?? 'Неизвестно') ?></td>
                                <td>
                                    <form action="admin.php" method="post" onsubmit="return confirm('Удалить эту новость?');">
                                        <input type="hidden" name="action" value="delete_news">
                                        <input type="hidden" name="news_id" value="<?= $n['id'] ?>">
                                        <button type="submit" class="admin-action-btn admin-action-btn-danger">Удалить</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
      </article>
    </section>

    <script>
        function toggleDlLink() {
            const type = document.getElementById('newsTypeSelect').value;
            const field = document.getElementById('dlLinkField');
            if (type === 'update') {
                field.style.display = 'block';
            } else {
                field.style.display = 'none';
            }
        }
    </script>

    <section class="admin-section <?= $activeTab === 'admin-notifications' ? 'active' : '' ?>" id="admin-notifications">
        <article class="admin-card">
            <div class="admin-card-title">Массовая рассылка уведомлений</div>
            <div class="admin-card-sub">
                Это сообщение будет отправлено от лица **Бота-Информатора** (ID: <?= (int)$botUserId ?>) 
                в раздел **"Сообщения"** всем зарегистрированным пользователям.
            </div>

            <?php if ($botUserId === null): ?>
                <div class="admin-flash admin-flash-err" style="margin-bottom:14px;">
                    <strong style="font-weight:700;">КРИТИЧНАЯ ОШИБКА:</strong>
                    Не удалось определить ID Бота-Информатора. Назначьте системного бота в разделе "Пользователи" ниже,
                    иначе массовая рассылка и приветственные сообщения работать не будут.
                </div>
            <?php endif; ?>

            <form method="POST" action="admin.php" style="margin-top: 10px;">
                <input type="hidden" name="action" value="send_broadcast_message">
                
                <div class="input-field">
                    <label for="notif-title">Заголовок сообщения (до 191 симв.)</label>
                    <input type="text" id="notif-title" name="title" required placeholder="Например: Важное обновление">
                </div>

                <div class="input-field">
                    <label for="notif-body">Текст сообщения (поддерживает перенос строки)</label>
                    <textarea 
                      id="notif-body" 
                      name="body" 
                      rows="8" 
                      required 
                      placeholder="Текст рассылки... (можно использовать &#10; для переноса строки)"
                      style="resize: vertical; padding: 8px 10px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.22); background: rgba(9,2,26,0.88); color: #fff; font-size: 13px;"
                    ></textarea>
                </div>

                <div class="input-field" style="margin-bottom:14px;">
                    <label class="checkbox-label">
                        <input type="checkbox" name="only_verified" value="1">
                        <span>Отправить **только** верифицированным пользователям.</span>
                    </label>
                </div>

                <button 
                    type="submit" 
                    class="submit-btn"
                    style="width: 100%;" 
                    onclick="return confirm('Вы уверены, что хотите отправить это сообщение ВСЕМ пользователям? Это действие нельзя отменить.');"
                    <?= $botUserId === null ? 'disabled' : '' ?>
                >
                    Отправить всем пользователям
                </button>
            </form>
        </article>
    </section>

    <section class="admin-section <?= $activeTab === 'admin-approvals' ? 'active' : '' ?>" id="admin-approvals">
      <article class="admin-card" style="max-width:100%;">
        <div class="admin-card-title">Ожидающие подтверждения</div>
        <div class="admin-card-sub">
          Запросы на добавление в вайт-лист, выдачу GM и подключение к серверу.
          Подтверждение через сайт синхронизируется с Discord-ботом (~1 сек).
        </div>

        <?php if (empty($pendingApprovals)): ?>
          <div style="text-align:center;padding:36px 0;opacity:0.5;">
            <div style="font-size:48px;margin-bottom:10px;">&#10003;</div>
            <p class="muted-text" style="font-size:15px;">Нет ожидающих запросов.</p>
          </div>
        <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:12px;margin-top:6px;">
          <?php foreach ($pendingApprovals as $pa):
            $paData = json_decode($pa['data'], true);
            $typeColors = [
              'wl_join' => ['bg' => 'rgba(251,191,36,0.15)', 'border' => 'rgba(251,191,36,0.4)', 'text' => '#fbbf24', 'icon' => '&#128274;'],
              'gm_request' => ['bg' => 'rgba(168,85,247,0.15)', 'border' => 'rgba(168,85,247,0.4)', 'text' => '#c084fc', 'icon' => '&#9879;'],
              'login_approval' => ['bg' => 'rgba(59,130,246,0.15)', 'border' => 'rgba(59,130,246,0.4)', 'text' => '#60a5fa', 'icon' => '&#128279;'],
            ];
            $tc = $typeColors[$pa['type']] ?? ['bg'=>'rgba(255,255,255,0.06)','border'=>'rgba(255,255,255,0.15)','text'=>'#ccc','icon'=>'&#63;'];
            $typeLabels = [
              'wl_join' => 'Вайт-лист',
              'gm_request' => 'GM-доступ',
              'login_approval' => 'Подключение',
            ];
            $typeLabel = $typeLabels[$pa['type']] ?? $pa['type'];
          ?>
            <div style="background:<?= $tc['bg'] ?>;border:1px solid <?= $tc['border'] ?>;border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
              <div style="font-size:24px;flex-shrink:0;"><?= $tc['icon'] ?></div>
              <div style="flex:1;min-width:160px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                  <span style="background:<?= $tc['text'] ?>20;color:<?= $tc['text'] ?>;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:0.3px;"><?= htmlspecialchars($typeLabel) ?></span>
                  <span style="font-size:11px;color:rgba(255,255,255,0.35);"><?= htmlspecialchars($pa['created_at']) ?></span>
                </div>
                <div style="font-size:12px;color:rgba(255,255,255,0.7);line-height:1.5;word-break:break-word;">
                <?php if ($pa['type'] === 'wl_join'): ?>
                  <span style="color:rgba(255,255,255,0.45);">HDID:</span> <code style="font-size:11px;"><?= htmlspecialchars($paData['hdid'] ?? '—') ?></code>
                  <span style="color:rgba(255,255,255,0.45);margin-left:10px;">IPID:</span> <code style="font-size:11px;"><?= htmlspecialchars($paData['ipid'] ?? '—') ?></code>
                  <span style="color:rgba(255,255,255,0.45);margin-left:10px;">IP:</span> <code style="font-size:11px;"><?= htmlspecialchars($paData['ip'] ?? '—') ?></code>
                <?php elseif ($pa['type'] === 'gm_request'): ?>
                  <span style="color:rgba(255,255,255,0.45);">Игрок:</span> <strong><?= htmlspecialchars($paData['client_name'] ?? '—') ?></strong>
                  <span style="color:rgba(255,255,255,0.45);margin-left:10px;">Команда:</span> <code style="font-size:11px;"><?= htmlspecialchars($paData['cmd'] ?? '—') ?></code>
                  <?php if (!empty($paData['arg'])): ?>
                    <span style="color:rgba(255,255,255,0.45);margin-left:10px;">Арг:</span> <code style="font-size:11px;"><?= htmlspecialchars($paData['arg']) ?></code>
                  <?php endif; ?>
                <?php elseif ($pa['type'] === 'login_approval'): ?>
                  <span style="color:rgba(255,255,255,0.45);">Client ID:</span> <code><?= htmlspecialchars($paData['client_id'] ?? '—') ?></code>
                <?php endif; ?>
                </div>
              </div>
              <div style="display:flex;gap:8px;flex-shrink:0;">
                <form method="POST" action="admin.php#admin-approvals" style="margin:0;">
                  <input type="hidden" name="action" value="approve_pending">
                  <input type="hidden" name="request_id" value="<?= htmlspecialchars($pa['id']) ?>">
                  <button type="submit" onclick="return confirm('Одобрить запрос?')" style="background:rgba(34,197,94,0.2);border:1px solid rgba(34,197,94,0.5);color:#4ade80;padding:6px 16px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;transition:0.15s;" onmouseover="this.style.background='rgba(34,197,94,0.35)'" onmouseout="this.style.background='rgba(34,197,94,0.2)'">&#10003; Одобрить</button>
                </form>
                <form method="POST" action="admin.php#admin-approvals" style="margin:0;">
                  <input type="hidden" name="action" value="reject_pending">
                  <input type="hidden" name="request_id" value="<?= htmlspecialchars($pa['id']) ?>">
                  <button type="submit" onclick="return confirm('Отклонить запрос?')" style="background:rgba(239,68,68,0.2);border:1px solid rgba(239,68,68,0.5);color:#f87171;padding:6px 16px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;transition:0.15s;" onmouseover="this.style.background='rgba(239,68,68,0.35)'" onmouseout="this.style.background='rgba(239,68,68,0.2)'">&#10007; Отклонить</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    </section>
  </main>

  <footer class="lk-footer">
    <div class="lk-footer-inner">
      <span>© 2025 Fair of Contradictions / Ярмарка противоречий. Админ-панель.</span>
    </div>
  </footer>

  <script>
    // Находим активную вкладку из хэша URL
    function getActiveTabFromHash() {
        const hash = window.location.hash;
        if (hash) {
            // Удаляем символ # и возвращаем имя секции
            return hash.substring(1);
        }
        return 'admin-users'; // По умолчанию
    }
    
    // Переключение вкладок админки
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.admin-tabs .admin-tab');
        const sections = document.querySelectorAll('.admin-section');
        
        function setTab(targetId) {
            tabs.forEach(t => t.classList.remove('active'));
            sections.forEach(s => s.classList.remove('active'));

            const activeTabButton = document.querySelector(`.admin-tab[data-section="${targetId}"]`);
            const activeSection = document.getElementById(targetId);

            if (activeTabButton) activeTabButton.classList.add('active');
            if (activeSection) activeSection.classList.add('active');
            
            // Обновляем хэш без скролла
            if (window.location.hash !== '#' + targetId) {
                window.history.replaceState(null, null, '#' + targetId);
            }
        }

        // Обработчик клика
        tabs.forEach(tab => {
            tab.addEventListener('click', function () {
                setTab(tab.dataset.section);
            });
        });

        // Активируем вкладку при загрузке страницы, учитывая редирект с хэшем
        setTab(getActiveTabFromHash());

        // Обработчик события изменения хэша (если пользователь переходит по ссылке)
        window.addEventListener('hashchange', function() {
            setTab(getActiveTabFromHash());
        });

    });

    // Поиск по пользователям
    (function() {
      const input = document.getElementById('userSearchInput');
      const table = document.getElementById('usersTable');
      if (!input || !table) return;

      input.addEventListener('input', function() {
        const q = input.value.toLowerCase();
        table.querySelectorAll('tbody tr[data-user-row]').forEach(function(row) {
          const nick  = (row.querySelector('[data-user-nick]')?.textContent || '').toLowerCase();
          const email = (row.querySelector('[data-user-email]')?.textContent || '').toLowerCase();
          if (!q || nick.includes(q) || email.includes(q)) {
            row.style.display = '';
          } else {
            row.style.display = 'none';
          }
        });
      });
    })();

    // Открыть / закрыть модалку игры
    function openGameModal(id) {
      const modal = document.getElementById('admin-modal-' + id);
      if (modal) modal.classList.add('open');
    }

    function closeGameModal(id) {
      const modal = document.getElementById('admin-modal-' + id);
      if (modal) modal.classList.remove('open');
    }

    // Открыть / закрыть модалку пользователя
    function openUserModal(id) {
      const modal = document.getElementById('admin-user-modal-' + id);
      if (modal) modal.classList.add('open');
    }

    function closeUserModal(id) {
      const modal = document.getElementById('admin-user-modal-' + id);
      if (modal) modal.classList.remove('open');
    }

    // Закрытие модалок по клику на фон
    document.addEventListener('click', function (e) {
      if (e.target.classList && e.target.classList.contains('admin-modal')) {
        e.target.classList.remove('open');
      }
    });

    // Esc закрывает любые открытые модалки
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        document.querySelectorAll('.admin-modal.open').forEach(function(m) {
          m.classList.remove('open');
        });
      }
    });
  </script>
</body>
</html>
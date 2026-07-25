<?php
// notifications.php — вспомогательные функции для бота и уведомлений

/**
 * Безопасное экранирование.
 */
function h($v) {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Возвращает ID пользователя, который сейчас является ботом-информатором.
 * 1) Смотрим в bot_config.id=1
 * 2) Если там пусто — берём первого пользователя с account_role='bot'
 * 3) Если и такого нет — null
 */
function get_bot_user_id($pdo)
{
    try {
        $stmt = $pdo->query('SELECT bot_user_id FROM bot_config WHERE id = 1 LIMIT 1');
        $botId = $stmt->fetchColumn();
        if (!empty($botId)) {
            return (int)$botId;
        }
    } catch (PDOException $e) {
        // молча игнорируем, просто попробуем fallback
    }

    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE account_role = 'bot' ORDER BY id ASC LIMIT 1");
        $botId = $stmt->fetchColumn();
        if (!empty($botId)) {
            return (int)$botId;
        }
    } catch (PDOException $e) {
        // тоже игнорируем
    }

    return null;
}

/**
 * Создать запись в user_notifications.
 *
 * $kind: 'notification' или 'message'
 * $fromUserId: кто отправил (для бота — ID бота)
 */
function send_notification(
    $pdo,
    $userId,
    $title,
    $body,
    $kind = 'notification',
    $fromUserId = null
) {
    $kind = in_array($kind, ['notification', 'message'], true) ? $kind : 'notification';

    $stmt = $pdo->prepare('
        INSERT INTO user_notifications (user_id, kind, title, body, is_read, created_by_user_id)
        VALUES (:user_id, :kind, :title, :body, 0, :from_id)
    ');
    $stmt->execute([
        'user_id' => $userId,
        'kind'    => $kind,
        'title'   => $title,
        'body'    => $body,
        'from_id' => $fromUserId,
    ]);
}

/**
 * Отправить именно сообщение от бота (kind = 'message').
 * Если бот не сконфигурирован — ничего не делает.
 */
function send_bot_message($pdo, $userId, $title, $body)
{
    $botId = get_bot_user_id($pdo);
    if ($botId === null) {
        return;
    }
    send_notification($pdo, $userId, $title, $body, 'message', $botId);
}

/**
 * Получить последние уведомления/сообщения пользователя.
 *
 * $kind: 'notification' или 'message'
 */
function fetch_user_notifications($pdo, $userId, $kind = 'notification', $limit = 20)
{
    $kind = in_array($kind, ['notification', 'message'], true) ? $kind : 'notification';

    $stmt = $pdo->prepare('
        SELECT
          n.id,
          n.kind,
          n.title,
          n.body,
          n.is_read,
          n.created_at,
          n.created_by_user_id,
          u.nickname AS from_nickname
        FROM user_notifications n
        LEFT JOIN users u ON u.id = n.created_by_user_id
        WHERE n.user_id = :user_id
          AND n.kind = :kind
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':kind', $kind, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Подсчитать количество непрочитанных для кружочка-колокольчика.
 * Возвращает массив вида:
 * ['notification' => 3, 'message' => 1]
 */
function count_unread_notifications($pdo, $userId)
{
    $stmt = $pdo->prepare('
        SELECT kind, COUNT(*) AS cnt
        FROM user_notifications
        WHERE user_id = :user_id AND is_read = 0
        GROUP BY kind
    ');
    $stmt->execute(['user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [
        'notification' => 0,
        'message'      => 0,
    ];

    foreach ($rows as $row) {
        $kind = $row['kind'];
        if (isset($result[$kind])) {
            $result[$kind] = (int)$row['cnt'];
        }
    }

    return $result;
}

/**
 * Пометить все уведомления определённого типа как прочитанные.
 * $kind можно передать null — тогда отметятся все.
 */
function mark_notifications_read($pdo, $userId, $kind = null)
{
    if ($kind !== null && !in_array($kind, ['notification', 'message'], true)) {
        $kind = null;
    }

    if ($kind === null) {
        $stmt = $pdo->prepare('
            UPDATE user_notifications
            SET is_read = 1
            WHERE user_id = :user_id AND is_read = 0
        ');
        $stmt->execute(['user_id' => $userId]);
    } else {
        $stmt = $pdo->prepare('
            UPDATE user_notifications
            SET is_read = 1
            WHERE user_id = :user_id AND kind = :kind AND is_read = 0
        ');
        $stmt->execute([
            'user_id' => $userId,
            'kind'    => $kind,
        ]);
    }
}
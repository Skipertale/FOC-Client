<?php
// notifications_mark_read.php — AJAX-эндпоинт для пометки уведомлений прочитанными
session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'auth';
    exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/notifications.php';

$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'method';
    exit;
}

// Если передан all=1 — помечаем все (и сообщения, и уведомления)
$all = isset($_POST['all']) ? (int)$_POST['all'] : 0;

try {
    if ($all === 1) {
        // всё прочитано
        mark_notifications_read($pdo, $userId, null);
    } else {
        // можно по kind
        $kind = $_POST['kind'] ?? null;
        if ($kind !== null && !in_array($kind, ['notification', 'message'], true)) {
            $kind = null;
        }
        mark_notifications_read($pdo, $userId, $kind);
    }

    echo 'ok';
} catch (PDOException $e) {
    http_response_code(500);
    echo 'error';
}
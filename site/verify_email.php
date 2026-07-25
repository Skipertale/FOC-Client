<?php
// verify_email.php
session_start();
require __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';

if (!$token) {
    $_SESSION['settings_error'] = 'Неверная ссылка подтверждения.';
    header('Location: account.php');
    exit;
}

// Ищем пользователя с таким токеном
$stmt = $pdo->prepare('SELECT id FROM users WHERE email_token = :token LIMIT 1');
$stmt->execute(['token' => $token]);
$userId = $stmt->fetchColumn();

if ($userId) {
    // Подтверждаем ТОЛЬКО почту (is_email_confirmed), админскую верификацию (is_verified) не трогаем
    $update = $pdo->prepare('
        UPDATE users 
        SET is_email_confirmed = 1, 
            email_token = NULL 
        WHERE id = :id
    ');
    $update->execute(['id' => $userId]);

    $_SESSION['settings_success'] = 'Почта успешно подтверждена! Теперь дождись проверки аккаунта администратором.';
} else {
    $_SESSION['settings_error'] = 'Ссылка устарела или некорректна.';
}

header('Location: account.php#settings');
exit;
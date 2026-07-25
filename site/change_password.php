<?php
// change_password.php
session_start();
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: account.php');
    exit;
}

if (empty($_SESSION['user_id'])) {
    header('Location: account.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

$oldPassword      = $_POST['old_password'] ?? '';
$newPassword      = $_POST['new_password'] ?? '';
$newPasswordAgain = $_POST['new_password_again'] ?? '';

$errors = [];

if ($newPassword === '' || $newPasswordAgain === '') {
    $errors[] = 'Новый пароль и его подтверждение не могут быть пустыми.';
} elseif ($newPassword !== $newPasswordAgain) {
    $errors[] = 'Новый пароль и подтверждение не совпадают.';
} elseif (strlen($newPassword) < 8) {
    $errors[] = 'Новый пароль должен быть не короче 8 символов.';
}

try {
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $userId]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $errors[] = 'Пользователь не найден.';
    } else {
        $currentHash = $user['password_hash'] ?? null;
        if (!$currentHash || !password_verify($oldPassword, $currentHash)) {
            $errors[] = 'Текущий пароль указан неверно.';
        }
    }

    if ($errors) {
        $_SESSION['password_error'] = implode("\n", $errors);
        header('Location: account.php');
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $st = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id LIMIT 1');
    $st->execute([
        'hash' => $newHash,
        'id'   => $userId,
    ]);

    $_SESSION['password_success'] = 'Пароль успешно изменён.';
} catch (PDOException $e) {
    $_SESSION['password_error'] = 'Ошибка БД при смене пароля: ' . $e->getMessage();
}

header('Location: account.php');
exit;

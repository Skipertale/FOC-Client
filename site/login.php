<?php
// login.php
session_start();
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: account.php');
    exit;
}

// Поддерживаем обе формы:
// - старая:  name="identifier"
// - новая:   name="email"
$identifier = trim($_POST['identifier'] ?? ($_POST['email'] ?? ''));
$password   = $_POST['password'] ?? '';

if ($identifier === '' || $password === '') {
    $_SESSION['auth_error'] = 'Введите логин/email и пароль.';
    $_SESSION['auth_error_type'] = 'login';
    header('Location: account.php');
    exit;
}

try {
    // Ищем пользователя либо по email, либо по никнейму
    $stmt = $pdo->prepare(
        'SELECT * FROM users
         WHERE email = :email OR nickname = :nickname
         LIMIT 1'
    );

    $stmt->execute([
        'email'    => $identifier,
        'nickname' => $identifier,
    ]);

    $user = $stmt->fetch();

    // Не нашли пользователя или пароль не подошёл
    if (!$user || !password_verify($password, $user['password_hash'])) {
        $_SESSION['auth_error'] = 'Неверный логин/email или пароль.';
        $_SESSION['auth_error_type'] = 'login';
        header('Location: account.php');
        exit;
    }

    // Успешный вход
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['nickname'] = $user['nickname'];

    header('Location: account.php');
    exit;

} catch (PDOException $e) {
    // В интерфейс отдаём только общее сообщение
    $_SESSION['auth_error'] = 'Ошибка при работе с базой данных. Попробуйте позже.';
    $_SESSION['auth_error_type'] = 'login';
    header('Location: account.php');
    exit;
}

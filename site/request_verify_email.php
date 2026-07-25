<?php
// request_verify_email.php
session_start();
require __DIR__ . '/db.php';
// Подключаем наш файл отправки почты (PHPMailer), который мы создали ранее
require __DIR__ . '/send_mail.php'; 

if (empty($_SESSION['user_id'])) {
    header('Location: account.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// 1. Получаем текущий email и статус ПОЧТЫ
$stmt = $pdo->prepare('SELECT email, is_email_confirmed FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

// 2. Проверяем именно is_email_confirmed
if (!$user || (int)$user['is_email_confirmed'] === 1) {
    $_SESSION['settings_error'] = 'Почта уже подтверждена или аккаунт не найден.';
    header('Location: account.php#settings');
    exit;
}

// Генерируем токен
$token = bin2hex(random_bytes(16));

// Сохраняем токен в БД
$stmt = $pdo->prepare('UPDATE users SET email_token = :token WHERE id = :id');
$stmt->execute(['token' => $token, 'id' => $userId]);

// Формируем ссылку
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$domain = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
$domain = rtrim($domain, '/\\');
$link = $domain . '/verify_email.php?token=' . $token;

// Контент письма
$subject = 'Подтверждение почты — Fair of Contradictions';
$messageHtml = "
    <div style='font-family: sans-serif; background: #f4f4f9; padding: 20px;'>
        <div style='max-width: 500px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 10px;'>
            <h2 style='color: #333;'>Привет!</h2>
            <p>Ты (или кто-то, знающий твою почту) запросил подтверждение аккаунта на сервере <b>Fair of Contradictions</b>.</p>
            <p>Чтобы подтвердить почту и сделать первый шаг к доступу на сервер, нажми на кнопку:</p>
            <p style='text-align: center;'>
                <a href='{$link}' style='display: inline-block; background-color: #b277ff; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 24px; font-weight: bold;'>Подтвердить email</a>
            </p>
            <p style='color: #666; font-size: 12px;'>Или перейди по ссылке: <br> <a href='{$link}'>{$link}</a></p>
            <hr style='border: 0; border-top: 1px solid #eee;'>
            <small style='color: #999;'>Если это не ты, просто проигнорируй это письмо.</small>
        </div>
    </div>
";

// Отправка через PHPMailer (функция из send_mail.php)
$result = sendEmail($user['email'], $subject, $messageHtml);

if ($result['success']) {
    $_SESSION['settings_success'] = 'Письмо с подтверждением отправлено на ' . htmlspecialchars($user['email']);
} else {
    // Ошибку почтовика выводим для отладки
    $_SESSION['settings_error'] = 'Не удалось отправить письмо. Ошибка: ' . $result['message'];
}

header('Location: account.php#settings');
exit;
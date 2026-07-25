<?php
// register.php
session_start();
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: account.php');
    exit;
}

$nickname   = trim($_POST['nickname'] ?? '');
$email      = trim($_POST['email'] ?? '');
$password   = $_POST['password'] ?? '';
$password2  = $_POST['password_confirm'] ?? '';
$contact    = trim($_POST['contact'] ?? '');
$rules      = isset($_POST['rules']);

// Валидация
if ($nickname === '' || $email === '' || $password === '' || $password2 === '') {
    $_SESSION['auth_error'] = 'Заполни все обязательные поля.';
    $_SESSION['auth_error_type'] = 'register';
    header('Location: account.php');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['auth_error'] = 'Некорректный email.';
    $_SESSION['auth_error_type'] = 'register';
    header('Location: account.php');
    exit;
}

if ($password !== $password2) {
    $_SESSION['auth_error'] = 'Пароли не совпадают.';
    $_SESSION['auth_error_type'] = 'register';
    header('Location: account.php');
    exit;
}

if (mb_strlen($password) < 6) {
    $_SESSION['auth_error'] = 'Пароль должен быть не короче 6 символов.';
    $_SESSION['auth_error_type'] = 'register';
    header('Location: account.php');
    exit;
}

if (!$rules) {
    $_SESSION['auth_error'] = 'Нужно согласиться с правилами сервера.';
    $_SESSION['auth_error_type'] = 'register';
    header('Location: account.php');
    exit;
}

try {
    // Проверяем уникальность email / никнейма
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email OR nickname = :nickname');
    $st->execute([
        'email'    => $email,
        'nickname' => $nickname,
    ]);
    $exists = (int)$st->fetchColumn();

    if ($exists > 0) {
        $_SESSION['auth_error'] = 'Аккаунт с таким email или никнеймом уже существует.';
        $_SESSION['auth_error_type'] = 'register';
        header('Location: account.php');
        exit;
    }

    // Хэш пароля
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // Создаём пользователя
    $st = $pdo->prepare('
        INSERT INTO users (nickname, email, password_hash, contact)
        VALUES (:nickname, :email, :password_hash, :contact)
    ');
    $st->execute([
        'nickname'      => $nickname,
        'email'         => $email,
        'password_hash' => $hash,
        'contact'       => $contact !== '' ? $contact : null,
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    $_SESSION['user_id']  = $newUserId;
    $_SESSION['nickname'] = $nickname;

    // Сообщение в ЛК после регистрации
    $_SESSION['settings_success'] =
        'Аккаунт создан. После того как администратор подтвердит данные (через Discord), '
        . 'ты сможешь записываться на игры.';

    // ============================
    // Приветственное сообщение от бота (без фатала, если что-то не так)
    // ============================
    try {
        // Подключаем notifications.php только если он существует
        $notificationsPath = __DIR__ . '/notifications.php';
        if (file_exists($notificationsPath)) {
            require_once $notificationsPath;
        }

        // Проверяем, есть ли функция, прежде чем вызывать
        if (function_exists('send_bot_message')) {
            $title = 'Добро пожаловать на Ярмарку противоречий!';

            $body = "Привет-привет!~ 💜\n"
                  . "Я — бот-информатор и личная помощница главного админа.\n"
                  . "Буду приносить тебе важные новости о играх, наборах и всякие милые напоминания.\n\n"
                  . "Заглядывай в колокольчик в правом верхнем углу — там появляются мои сообщения и уведомления.\n"
                  . "А ещё не забудь настроить профиль и указать удобные роли, чтобы мастерам было проще звать тебя в игры. ✨";

            // Отправляем личное сообщение от бота новому пользователю
            send_bot_message($pdo, $newUserId, $title, $body);
        }
    } catch (Throwable $e) {
        // Любая ошибка бота просто игнорируется, чтобы не ломать регистрацию
    }

    header('Location: account.php');
    exit;

} catch (PDOException $e) {
    $_SESSION['auth_error'] = 'Ошибка при работе с базой данных. Попробуй позже.';
    $_SESSION['auth_error_type'] = 'register';
    header('Location: account.php');
    exit;
}

<?php
// settings.php — сохранение настроек пользователя
session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: account.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

$timezone       = trim($_POST['timezone'] ?? '');
$preferredTime  = $_POST['preferred_time'] ?? 'any';
$preferredRoles = $_POST['preferred_roles'] ?? [];
$roleDefault    = trim($_POST['role_default'] ?? '');

$notifyNew    = isset($_POST['notify_new_games']) ? 1 : 0;
$notifyTaken  = isset($_POST['notify_taken']) ? 1 : 0;
$notifyBefore = isset($_POST['notify_before_game']) ? 1 : 0;

// Строка ролей через запятую
$preferredRolesClean = [];
foreach ($preferredRoles as $r) {
    $r = trim($r);
    if ($r !== '') {
        $preferredRolesClean[] = $r;
    }
}
$preferredRolesStr = implode(',', $preferredRolesClean);

// Валидация удобного времени
$allowedTimes = ['any','evening','night','weekend'];
if (!in_array($preferredTime, $allowedTimes, true)) {
    $preferredTime = 'any';
}

// Валидация роли по умолчанию
$allowedRoles = ['Адвокат', 'Прокурор', 'Судья', 'Присяжный', 'Свидетель', 'Ведущий'];
if (!in_array($roleDefault, $allowedRoles, true)) {
    $roleDefault = 'Адвокат';
}

try {
    // user_settings
    $stmt = $pdo->prepare('
        INSERT INTO user_settings (user_id, timezone, preferred_roles, preferred_time, notify_new_games, notify_taken, notify_before_game)
        VALUES (:user_id, :timezone, :preferred_roles, :preferred_time, :n1, :n2, :n3)
        ON DUPLICATE KEY UPDATE
          timezone = VALUES(timezone),
          preferred_roles = VALUES(preferred_roles),
          preferred_time = VALUES(preferred_time),
          notify_new_games = VALUES(notify_new_games),
          notify_taken = VALUES(notify_taken),
          notify_before_game = VALUES(notify_before_game)
    ');
    $stmt->execute([
        'user_id'         => $userId,
        'timezone'        => $timezone !== '' ? $timezone : null,
        'preferred_roles' => $preferredRolesStr !== '' ? $preferredRolesStr : null,
        'preferred_time'  => $preferredTime,
        'n1'              => $notifyNew,
        'n2'              => $notifyTaken,
        'n3'              => $notifyBefore,
    ]);

    // роль по умолчанию в users
    $stmt2 = $pdo->prepare('UPDATE users SET role_default = :role_default WHERE id = :id');
    $stmt2->execute([
        'role_default' => $roleDefault,
        'id'           => $userId,
    ]);

    $_SESSION['settings_success'] = 'Настройки и роль по умолчанию успешно сохранены.';
} catch (PDOException $e) {
    $_SESSION['settings_success'] = 'Не удалось сохранить настройки. Попробуйте позже.';
}

header('Location: account.php');
exit;

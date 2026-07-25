<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Загружаем текущие данные
$stmt = $pdo->prepare("SELECT nickname, contact, role_default FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("Пользователь не найден.");
}

// Обработка формы
$success = null;
$error = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nickname = trim($_POST['nickname']);
    $contact = trim($_POST['contact']);
    $role_default = trim($_POST['role_default']);

    if (strlen($nickname) < 2) {
        $error = "Никнейм должен быть не короче 2 символов.";
    } else {
        $update = $pdo->prepare("
            UPDATE users 
            SET nickname = ?, contact = ?, role_default = ?
            WHERE id = ?
        ");
        $update->execute([$nickname, $contact, $role_default, $user_id]);

        $success = "Профиль успешно обновлён!";

        // Обновляем локальные данные для формы
        $user['nickname'] = $nickname;
        $user['contact'] = $contact;
        $user['role_default'] = $role_default;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Редактирование профиля</title>
    <link rel="stylesheet" href="account.css">
</head>
<body>

<div class="auth-container" style="margin-top:40px; max-width:600px;">

    <div class="auth-header">
        <div class="auth-title">Редактирование профиля</div>
        <div class="auth-subtitle">Измени свои данные и сохрани изменения</div>
    </div>

    <?php if ($error): ?>
        <div class="auth-error">
            <div class="auth-error-title">Ошибка</div>
            <div class="auth-error-text"><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" class="auth-form">

        <div class="input-field">
            <label>Никнейм</label>
            <input type="text" name="nickname" value="<?= htmlspecialchars($user['nickname']) ?>" required>
        </div>

        <div class="input-field">
            <label>Контакт (опционально)</label>
            <input type="text" name="contact" value="<?= htmlspecialchars($user['contact']) ?>">
        </div>

        <div class="input-field">
            <label>Роль по умолчанию</label>
            <select name="role_default">
                <option value="Адвокат" <?= $user['role_default']=='Адвокат'?'selected':'' ?>>Адвокат</option>
                <option value="Прокурор" <?= $user['role_default']=='Прокурор'?'selected':'' ?>>Прокурор</option>
                <option value="Судья" <?= $user['role_default']=='Судья'?'selected':'' ?>>Судья</option>
                <option value="Ведущий" <?= $user['role_default']=='Ведущий'?'selected':'' ?>>Ведущий</option>
            </select>
        </div>

        <button class="submit-btn" type="submit">Сохранить</button>
    </form>

    <div style="margin-top:14px;">
        <a href="account.php" class="auth-link">← Назад в профиль</a>
    </div>
</div>

</body>
</html>

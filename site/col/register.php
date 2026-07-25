<?php
// register.php - CREATION OF NEW PERSONNEL RECORD
session_start();
require_once 'config/db.php';

$errorMsg = '';
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    // 1. Простейшая валидация
    if (empty($username) || empty($password)) {
        $errorMsg = "ОШИБКА: ЗАПОЛНИТЕ ВСЕ ПОЛЯ";
    } elseif ($password !== $confirm) {
        $errorMsg = "ОШИБКА: ПАРОЛИ НЕ СОВПАДАЮТ";
    } elseif (strlen($username) < 3) {
        $errorMsg = "ОШИБКА: ПОЗЫВНОЙ СЛИШКОМ КОРОТКИЙ";
    } else {
        // 2. Проверка, занят ли ник
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->rowCount() > 0) {
            $errorMsg = "ОШИБКА: АГЕНТ С ТАКИМ ПОЗЫВНЫМ УЖЕ СУЩЕСТВУЕТ";
        } else {
            // 3. Регистрация
            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            try {
                $pdo->beginTransaction();

                // Создаем юзера
                $sqlUser = "INSERT INTO users (username, password_hash) VALUES (?, ?)";
                $stmtUser = $pdo->prepare($sqlUser);
                $stmtUser->execute([$username, $hash]);
                $newUserId = $pdo->lastInsertId();

                // Создаем досье (рейтинг)
                $sqlDossier = "INSERT INTO dossier (user_id) VALUES (?)";
                $stmtDossier = $pdo->prepare($sqlDossier);
                $stmtDossier->execute([$newUserId]);

                $pdo->commit();
                $successMsg = "РЕГИСТРАЦИЯ УСПЕШНА. ПЕРЕНАПРАВЛЕНИЕ...";
                header("refresh:2;url=login.php"); // Авто-переход на логин через 2 сек
            } catch (Exception $e) {
                $pdo->rollBack();
                $errorMsg = "СБОЙ СИСТЕМЫ: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>РЕГИСТРАЦИЯ НОВОГО АГЕНТА</title>
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #050a0e; --primary: #00ffcc; --alert: #ff3333; --dim: rgba(0, 255, 204, 0.3); font-family: 'Share Tech Mono', monospace; }
        body { background: var(--bg); color: var(--primary); display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; overflow: hidden; }
        
        .crt-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06)); background-size: 100% 2px, 3px 100%; pointer-events: none; z-index: 999; }
        
        .login-box { border: 1px solid var(--primary); padding: 40px; width: 400px; background: rgba(0, 20, 20, 0.9); box-shadow: 0 0 20px var(--dim); position: relative; }
        .login-box::before { content: "NEW_USER_PROTOCOL"; position: absolute; top: -10px; left: 20px; background: var(--bg); padding: 0 10px; color: var(--dim); }

        h2 { text-align: center; margin-bottom: 30px; letter-spacing: 2px; text-transform: uppercase; }
        
        .input-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; color: var(--dim); font-size: 0.8rem; }
        input { width: 100%; background: transparent; border: none; border-bottom: 1px solid var(--primary); color: var(--primary); font-family: inherit; font-size: 1.2rem; padding: 5px 0; outline: none; }
        input:focus { box-shadow: 0 5px 5px -5px var(--primary); }

        .btn { width: 100%; padding: 15px; background: transparent; border: 1px solid var(--primary); color: var(--primary); font-family: inherit; font-size: 1.2rem; cursor: pointer; transition: 0.3s; margin-top: 10px; text-transform: uppercase; }
        .btn:hover { background: var(--primary); color: #000; }
        
        .alert { color: var(--alert); text-align: center; margin-bottom: 15px; border: 1px dashed var(--alert); padding: 10px; }
        .success { color: var(--primary); text-align: center; margin-bottom: 15px; border: 1px dashed var(--primary); padding: 10px; }
        
        .footer-link { display: block; text-align: center; margin-top: 20px; color: var(--dim); text-decoration: none; font-size: 0.9rem; }
        .footer-link:hover { color: var(--primary); text-decoration: underline; }
    </style>
</head>
<body>
    <div class="crt-overlay"></div>
    
    <div class="login-box">
        <h2>ЛИЧНОЕ ДЕЛО</h2>
        
        <?php if($errorMsg): ?>
            <div class="alert">> <?php echo $errorMsg; ?></div>
        <?php endif; ?>
        <?php if($successMsg): ?>
            <div class="success">> <?php echo $successMsg; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <label>ПОЗЫВНОЙ (LOGIN)</label>
                <input type="text" name="username" required autocomplete="off">
            </div>
            
            <div class="input-group">
                <label>КЛЮЧ ДОСТУПА (PASSWORD)</label>
                <input type="password" name="password" required>
            </div>

            <div class="input-group">
                <label>ПОДТВЕРЖДЕНИЕ КЛЮЧА</label>
                <input type="password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn">СОЗДАТЬ ЗАПИСЬ</button>
        </form>
        
        <a href="login.php" class="footer-link">[ УЖЕ ЕСТЬ ДОСТУП? ВОЙТИ ]</a>
        <a href="index.php" class="footer-link">[ ВЕРНУТЬСЯ В ГЛАВНОЕ МЕНЮ ]</a>
    </div>
</body>
</html>
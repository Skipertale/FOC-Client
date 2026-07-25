<?php
session_start();

// Пароли для доступа
$correct_password = "wpoegkewpogkwepgowkegpwoekgwpeokg";
$master_password = "GAEba"; // Мастер-пароль

// Если форма отправлена
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $password = $_POST["password"] ?? "";

    if ($password === $correct_password || $password === $master_password) {
        $_SESSION["authenticated"] = true;
        header("Location: hub3.php"); // Перенаправление на панель
        exit;
    } else {
        $error = "Неверный пароль!";
    }
}

// Если пользователь уже авторизован
if (isset($_SESSION["authenticated"]) && $_SESSION["authenticated"] === true) {
    header("Location: hub3.php"); // Перенаправление на панель
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Авторизация</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #121212;
            color: #e0e0e0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            background-color: #1e1e2f;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.5);
            width: 300px;
        }
        .login-container h1 {
            color: #bb86fc;
            margin-bottom: 20px;
        }
        .login-container input {
            padding: 10px;
            margin: 10px 0;
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #555;
            border-radius: 5px;
            background-color: #292941;
            color: #e0e0e0;
        }
        .login-container button,
        .login-container a {
            display: inline-block;
            padding: 10px;
            margin-top: 10px;
            width: 100%;
            background-color: #3a3a4f;
            color: #bb86fc;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            text-align: center;
            box-sizing: border-box;
        }
        .login-container button:hover,
        .login-container a:hover {
            background-color: #4a4a6f;
        }
        .error {
            color: #ff0000;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Вход</h1>
        <form method="POST">
            <input type="password" name="password" placeholder="Введите пароль" required>
            <button type="submit">Войти</button>
        </form>
        <?php if (isset($error)): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <a href="https://aofoc.ru/hubs/hubs.php">Вернуться к хабам</a>
    </div>
</body>
</html>

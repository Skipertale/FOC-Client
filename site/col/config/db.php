<?php
// config/db.php - Конфигурация подключения к СЕРВЕРУ COL

$host = 'localhost';
$db   = 'col_database'; // Имя твоей базы данных (которую создал в шаге 1)
$user = 'zsu_user';         // Твой логин от MySQL (обычно root на локалке)
$pass = 'SeCreT15!';             // Твой пароль от MySQL (обычно пустой на локалке)
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Выбрасывать ошибки
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Данные в виде ассоциативного массива
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Реальные подготовленные запросы
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Если подключение не удалось, выводим ошибку в стиле терминала
    die('<div style="background:black; color:red; font-family:monospace; padding:20px;">
         CRITICAL ERROR: DATABASE CONNECTION FAILED.<br>
         TERMINATING SESSION...<br>
         ERROR CODE: ' . (int)$e->getCode() . '
         </div>');
}
?>
<?php
require_once 'config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['access_level'] < 5) {
    die(json_encode(['error' => 'Access Denied']));
}

if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM dossier WHERE user_id = ?");
    $stmt->execute([$_GET['id']]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($data);
}
?>
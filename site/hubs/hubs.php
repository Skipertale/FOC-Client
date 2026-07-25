<?php
session_start();

// Подключение к базе данных
try {
    $pdo = new PDO("mysql:host=localhost;dbname=gmmaster_panel", "gm_user", "ZeTTaSl0W!");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Получение правил для указанного хаба
$rules = [];
if (isset($_GET['hub_id'])) {
    $hub_id = intval($_GET['hub_id']);

    try {
        $stmt = $pdo->prepare("SELECT rule FROM rules WHERE hub_id = ?");
        $stmt->execute([$hub_id]);
        $rules = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        die("Ошибка при получении правил: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Хабы</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .hub-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
            margin-top: 20px;
        }

        .hub-panel {
            background-color: #1e1e2f;
            padding: 20px;
            border-radius: 8px;
            width: 300px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.5);
        }

        .hub-panel h2 {
            color: #bb86fc;
            margin-bottom: 10px;
        }

        .hub-panel button {
            background-color: #3a3a4f;
            color: #bb86fc;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 10px 0;
        }

        .hub-panel button:hover {
            background-color: #4a4a6f;
        }

        .hub-panel .owner {
            margin-top: 10px;
            color: #e0e0e0;
            font-size: 14px;
            background-color: #292941;
            padding: 5px;
            border-radius: 5px;
        }

        .rules-container {
            margin-top: 20px;
            padding: 15px;
            background-color: #2e2e3e;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.5);
            color: #e0e0e0;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .rules-container h2 {
            color: #bb86fc;
            margin-bottom: 10px;
            font-size: 18px;
        }

        .rule {
            background: #292941;
            padding: 8px;
            margin: 5px 0;
            border-radius: 5px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <header>
        <h1>Хабы</h1>
    </header>
    <div class="hub-container">
        <div class="hub-panel">
            <h2>Хаб 1</h2>
            <button onclick="location.href='hub1/hub1.php'">Личный кабинет</button>
            <button onclick="location.href='hubs.php?hub_id=1'">Правила</button>
            <div class="owner">D3ST</div>
        </div>
        <div class="hub-panel">
            <h2>Хаб 2</h2>
            <button onclick="location.href='hub2/hub2.php'">Личный кабинет</button>
            <button onclick="location.href='hubs.php?hub_id=2'">Правила</button>
            <div class="owner">Sugar</div>
        </div>
        <div class="hub-panel">
            <h2>Хаб 3</h2>
            <button onclick="location.href='hub3/hub3.php'">Личный кабинет</button>
            <button onclick="location.href='hubs.php?hub_id=3'">Правила</button>
            <div class="owner">Владельца нет</div>
        </div>
        <div class="hub-panel">
            <h2>Хаб 4</h2>
            <button onclick="location.href='hub4/hub4.php'">Личный кабинет</button>
            <button onclick="location.href='hubs.php?hub_id=4'">Правила</button>
            <div class="owner">Danil</div>
        </div>
        <div class="hub-panel">
            <h2>Хаб 5</h2>
            <button onclick="location.href='hub5/hub5.php'">Личный кабинет</button>
            <button onclick="location.href='hubs.php?hub_id=5'">Правила</button>
            <div class="owner">Владельца нет</div>
        </div>
        <div class="hub-panel">
            <h2>Хаб 6</h2>
            <button onclick="location.href='hub6/hub6.php'">Личный кабинет</button>
            <button onclick="location.href='hubs.php?hub_id=6'">Правила</button>
            <div class="owner">Владельца нет</div>
        </div>
    </div>

    <?php if (isset($_GET['hub_id'])): ?>
        <div id="rules-container" class="rules-container">
            <h2>Правила для Хаб <?= htmlspecialchars($_GET['hub_id']) ?></h2>
            <div id="rules-list">
                <?php if (!empty($rules)): ?>
                    <?php foreach ($rules as $index => $rule): ?>
                        <div class="rule"><?= htmlspecialchars(($index + 1) . ". " . $rule) ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="rule">Правил нет</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>

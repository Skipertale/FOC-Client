<?php
session_start();

// Проверка авторизации
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: loginh3.php");
    exit;
}

// Подключение к базе данных
try {
    $pdo = new PDO("mysql:host=localhost;dbname=gmmaster_panel", "gm_user", "ZeTTaSl0W!");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Обработка добавления в бан-лист
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ban'])) {
    $ban_id = $_POST['id'];
    $name = $_POST['name'];
    $date = $_POST['date'];
    $reason = $_POST['reason'];

    try {
        $stmt = $pdo->prepare("INSERT INTO bans (ban_id, name, date, reason) VALUES (?, ?, ?, ?)");
        $stmt->execute([$ban_id, $name, $date, $reason]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (PDOException $e) {
        die("Ошибка при добавлении в бан-лист: " . $e->getMessage());
    }
}

// Обработка удаления из бан-листа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ban'])) {
    $ban_id = $_POST['delete_ban'];

    try {
        $stmt = $pdo->prepare("DELETE FROM bans WHERE ban_id = ?");
        $stmt->execute([$ban_id]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (PDOException $e) {
        die("Ошибка при удалении из бан-листа: " . $e->getMessage());
    }
}

// Обработка добавления правила
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rule'])) {
    $rule = $_POST['rule'];
    $hub_id = $_POST['hub_id'] ?? 3; // Указываем hub_id = 2 для хаба 2

    try {
        $stmt = $pdo->prepare("INSERT INTO rules (rule, hub_id) VALUES (?, ?)");
        $stmt->execute([$rule, $hub_id]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (PDOException $e) {
        die("Ошибка при добавлении правила: " . $e->getMessage());
    }
}

// Обработка удаления правила
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rule'])) {
    $rule_id = $_POST['delete_rule'];

    try {
        $stmt = $pdo->prepare("DELETE FROM rules WHERE id = ?");
        $stmt->execute([$rule_id]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (PDOException $e) {
        die("Ошибка при удалении правила: " . $e->getMessage());
    }
}

// Обработка заявок
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_application'])) {
    $title = $_POST['title'];
    $link = $_POST['link'];
    $hub_id = 3; // Указываем хаб 2
    $defaultStatus = 'В ожидании';

    try {
        $stmt = $pdo->prepare("INSERT INTO applications (title, link, status, hub_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$title, $link, $defaultStatus, $hub_id]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (PDOException $e) {
        die("Ошибка при добавлении заявки: " . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $application_id = $_POST['app_id'];
    $new_status = $_POST['new_status'];
    $comment = $_POST['comment'] ?? "";

    try {
        $stmt = $pdo->prepare("UPDATE applications SET status = ?, comment = ? WHERE id = ?");
        $stmt->execute([$new_status, $comment, $application_id]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (PDOException $e) {
        die("Ошибка при обновлении заявки: " . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_application'])) {
    $application_id = $_POST['delete_application'];

    try {
        // Удаляем запись
        $stmt = $pdo->prepare("DELETE FROM applications WHERE id = ?");
        $stmt->execute([$application_id]);

        // Перенумеровываем строки
        $pdo->exec("SET @num := 0");
        $pdo->exec("UPDATE applications SET id = (@num := @num + 1)");

        // Сбрасываем автоинкремент
        $pdo->exec("ALTER TABLE applications AUTO_INCREMENT = 1");

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (PDOException $e) {
        die("Ошибка при удалении заявки: " . $e->getMessage());
    }
}



// Получение данных из базы данных
try {
    $rules = $pdo->prepare("SELECT * FROM rules WHERE hub_id = 3"); // Фильтрация правил для хаба 1
    $rules->execute();
    $rules = $rules->fetchAll(PDO::FETCH_ASSOC);
    $applications = $pdo->prepare("SELECT * FROM applications WHERE hub_id = 3"); // Заявки для хаба 1
    $applications->execute();
    $applications = $applications->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Ошибка при получении данных: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель владельца HUB</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header>
        <h1>Панель владельца HUB</h1>
        <nav>
            <a href="logouth3.php" class="logout">Выйти</a>
        </nav>
    </header>

    <div class="container">
        <div id="dashboard" class="section">
            <h2>Информация о ваших закреплениях за хаб</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Хаб</th>
                        <th>Закреплён с</th>
                        <th>Количество овнеров</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Hub 3</td>
                        <td>11.01.2025</td>
                        <td>1</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="banlist" class="section">
            <h2>Бан-лист</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID Бана</th>
                        <th>Имя</th>
                        <th>Дата Бана</th>
                        <th>Причина</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($banlist as $ban): ?>
                        <tr>
                            <td><?= htmlspecialchars($ban['ban_id']) ?></td>
                            <td><?= htmlspecialchars($ban['name']) ?></td>
                            <td><?= htmlspecialchars($ban['date']) ?></td>
                            <td><?= htmlspecialchars($ban['reason']) ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="delete_ban" value="<?= $ban['ban_id'] ?>">
                                    <button type="submit">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="POST" id="banlist-form" class="form-inline">
                <input type="text" name="id" placeholder="ID Бана">
                <input type="text" name="name" placeholder="Имя">
                <input type="date" name="date">
                <input type="text" name="reason" placeholder="Причина">
                <button type="submit" name="add_ban">Добавить</button>
            </form>
        </div>

        <div id="rules" class="section">
            <h2>Правила</h2>
            <div class="rules-list">
                <?php foreach ($rules as $rule): ?>
                    <div class="rule">
                        <span><?= htmlspecialchars($rule['rule']) ?></span>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="delete_rule" value="<?= $rule['id'] ?>">
                            <button type="submit">Удалить</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="POST" id="rules-form">
                <input type="text" name="rule" placeholder="Добавить правило">
                <button type="submit" name="add_rule">Добавить</button>
            </form>
        </div>

        <div id="applications" class="section">
            <h2>Ввод заявки на согласование правил</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>№</th>
                        <th>Название заявки</th>
                        <th>Ссылка</th>
                        <th>Статус</th>
                        <th>Комментарий</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($applications as $app): ?>
                        <tr>
                            <td><?= htmlspecialchars($app['id']) ?></td>
                            <td><?= htmlspecialchars($app['title']) ?></td>
                            <td><a href="<?= htmlspecialchars($app['link']) ?>" target="_blank">Ссылка</a></td>
                            <td><?= htmlspecialchars($app['status']) ?></td>
                            <td><?= htmlspecialchars($app['comment']) ?></td>
                            <td>
                                <button onclick="openCommentModal(<?= $app['id'] ?>, 'Согласовано')">Согласовать</button>
                                <button onclick="openCommentModal(<?= $app['id'] ?>, 'На доработку')">На доработку</button>
                                <button onclick="openCommentModal(<?= $app['id'] ?>, 'Отклонено')">Отклонить</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="delete_application" value="<?= $app['id'] ?>">
                                    <button type="submit">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="POST" id="applications-form">
                <input type="text" name="title" placeholder="Название заявки">
                <input type="text" name="link" placeholder="Ссылка на Google-документ">
                <button type="submit" name="add_application">Добавить</button>
            </form>
        </div>
    </div>

    <div id="comment-modal" class="modal" style="display:none;">
        <div class="modal-content">
            <h3>Комментарий</h3>
            <form method="POST">
                <input type="hidden" id="modal-app-id" name="app_id">
                <input type="hidden" id="modal-new-status" name="new_status">
                <textarea name="comment" id="modal-comment" placeholder="Введите комментарий"></textarea>
                <button type="submit" name="update_status">Отправить</button>
            </form>
            <button onclick="closeCommentModal()">Закрыть</button>
        </div>
    </div>

    <footer class="footer">
        &copy; 2025 Панель владельца HUB // Fair of Contradictions. Все права защищены.
    </footer>

    <script>
        function openApplicationModal() {
            document.getElementById("application-modal").style.display = "flex";
        }

        function closeApplicationModal() {
            document.getElementById("application-modal").style.display = "none";
        }

        function openCommentModal(appId, newStatus) {
            document.getElementById("modal-app-id").value = appId;
            document.getElementById("modal-new-status").value = newStatus;
            document.getElementById("comment-modal").style.display = "flex";
        }

        function closeCommentModal() {
            document.getElementById("comment-modal").style.display = "none";
        }
    </script>
</body>
</html>

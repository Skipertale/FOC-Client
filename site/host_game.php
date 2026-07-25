<?php
// host_game.php — управление конкретной игрой ведущим
session_start();

// Подключаем файлы. Используем require_once, чтобы избежать ошибок повторного включения
require_once __DIR__ . '/db.php';

// Проверяем наличие файла уведомлений перед подключением
if (file_exists(__DIR__ . '/notifications.php')) {
    require_once __DIR__ . '/notifications.php';
}

// --- Проверка авторизации ---
if (empty($_SESSION['user_id'])) {
    header('Location: account.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    // Тянем инфу о пользователе (для шапки)
    $stmt = $pdo->prepare('SELECT id, nickname, email, account_role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: account.php');
        exit;
    }

    $isAdmin = ($user['account_role'] ?? 'player') === 'admin';

    // ID игры из GET
    $gameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
    if ($gameId <= 0) {
        header('Location: account.php');
        exit;
    }

    // Вспомогательная функция экранирования (определяем если её нет)
    if (!function_exists('h')) {
        function h($v) {
            return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    // Загружаем игру и проверяем, что текущий пользователь — её ведущий
    $stmt = $pdo->prepare('
        SELECT
          g.*,
          (
            SELECT COUNT(*)
            FROM user_games ug
            WHERE ug.game_id = g.id AND ug.status = "signed"
          ) AS signed_count
        FROM games g
        WHERE g.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    // Проверка прав
    if (!$game || (int)($game['owner_user_id'] ?? 0) !== $userId) {
        // Если это не ведущий, кидаем на главную или показываем ошибку
        die('У вас нет прав управлять этой игрой.');
    }

    // --- Обработка POST-действий ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // 1. Обновление настроек игры
        if ($action === 'update_game_host') {
            $title       = trim($_POST['title'] ?? '');
            $startsAtStr = trim($_POST['starts_at'] ?? '');
            $external    = trim($_POST['external_link'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $maxPlayers  = trim($_POST['max_players'] ?? '');
            $signupsOpen = isset($_POST['signups_open']) ? 1 : 0;
            $status      = $_POST['status'] ?? $game['status'];

            $allowedStatus = ['upcoming', 'active', 'finished'];
            if (!in_array($status, $allowedStatus, true)) {
                $status = $game['status'];
            }

            if ($title === '') {
                $_SESSION['host_error'] = 'Название игры не может быть пустым.';
            } else {
                $startsAt = null;
                if ($startsAtStr !== '') {
                    $dt = DateTime::createFromFormat('Y-m-d H:i', $startsAtStr);
                    if (!$dt) $dt = DateTime::createFromFormat('d.m.Y H:i', $startsAtStr);
                    if ($dt) $startsAt = $dt->format('Y-m-d H:i:s');
                }

                $maxPlayersVal = null;
                if ($maxPlayers !== '' && ctype_digit($maxPlayers) && (int)$maxPlayers > 0) {
                    $maxPlayersVal = (int)$maxPlayers;
                }

                $stmt = $pdo->prepare('
                    UPDATE games
                    SET title         = :title,
                        status        = :status,
                        starts_at     = :starts_at,
                        external_link = :external_link,
                        max_players   = :max_players,
                        signups_open  = :signups_open,
                        description   = :description
                    WHERE id = :id AND owner_user_id = :owner_id
                    LIMIT 1
                ');
                $stmt->execute([
                    'title'        => $title,
                    'status'       => $status,
                    'starts_at'    => $startsAt,
                    'external_link'=> $external !== '' ? $external : null,
                    'max_players'  => $maxPlayersVal,
                    'signups_open' => $signupsOpen,
                    'description'  => $description !== '' ? $description : null,
                    'id'           => $gameId,
                    'owner_id'     => $userId,
                ]);

                $_SESSION['host_message'] = 'Изменения игры сохранены.';
            }

        // 2. ОДОБРЕНИЕ ЗАЯВКИ
        } elseif ($action === 'approve_player') {
            $ugId = (int)($_POST['ug_id'] ?? 0);
            
            // Проверяем заявку
            $check = $pdo->prepare('SELECT user_id FROM user_games WHERE id = :id AND game_id = :gid AND status = "pending"');
            $check->execute(['id' => $ugId, 'gid' => $gameId]);
            $playerId = $check->fetchColumn();

            if ($playerId) {
                $stmt = $pdo->prepare('UPDATE user_games SET status = "signed" WHERE id = :id');
                $stmt->execute(['id' => $ugId]);

                // Уведомление (безопасный вызов)
                if (function_exists('send_bot_message')) {
                    send_bot_message(
                        $pdo,
                        (int)$playerId,
                        'Заявка принята!',
                        'Ведущий одобрил твою заявку на участие в игре «' . $game['title'] . '». Не опаздывай!'
                    );
                }

                $_SESSION['host_message'] = 'Игрок принят в основной состав.';
            } else {
                $_SESSION['host_error'] = 'Заявка не найдена или уже обработана.';
            }

        // 3. Изменение роли
        } elseif ($action === 'update_player_role') {
            $ugId    = (int)($_POST['ug_id'] ?? 0);
            $newRole = trim($_POST['role'] ?? '');

            if ($ugId > 0 && $newRole !== '') {
                $stmt = $pdo->prepare('UPDATE user_games SET role = :role WHERE id = :id AND game_id = :gid LIMIT 1');
                $stmt->execute(['role' => $newRole, 'id' => $ugId, 'gid'  => $gameId]);
                $_SESSION['host_message'] = 'Роль игрока обновлена.';
            }

        // 4. Удаление игрока
        } elseif ($action === 'remove_player') {
            $ugId = (int)($_POST['ug_id'] ?? 0);
            if ($ugId > 0) {
                $stmt = $pdo->prepare('DELETE FROM user_games WHERE id = :id AND game_id = :gid LIMIT 1');
                $stmt->execute(['id' => $ugId, 'gid' => $gameId]);
                $_SESSION['host_message'] = 'Игрок удалён (или заявка отклонена).';
            }

        // 5. Добавление даты
        } elseif ($action === 'add_game_date') {
            $newDateStr = trim($_POST['new_date'] ?? '');
            $dt = DateTime::createFromFormat('Y-m-d H:i', $newDateStr);
            if (!$dt) $dt = DateTime::createFromFormat('d.m.Y H:i', $newDateStr);
            
            if ($dt) {
                $stmt = $pdo->prepare('INSERT INTO game_dates (game_id, starts_at) VALUES (:gid, :starts_at)');
                $stmt->execute(['gid' => $gameId, 'starts_at' => $dt->format('Y-m-d H:i:s')]);
                $_SESSION['host_message'] = 'Дата добавлена.';
            } else {
                $_SESSION['host_error'] = 'Неверный формат даты.';
            }

        // 6. Удаление даты
        } elseif ($action === 'delete_game_date') {
            $dateId = (int)($_POST['date_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM game_dates WHERE id = :id AND game_id = :gid LIMIT 1');
            $stmt->execute(['id' => $dateId, 'gid' => $gameId]);
            $_SESSION['host_message'] = 'Дата удалена.';
        }

        // Редирект после POST (PRG pattern)
        header('Location: host_game.php?game_id=' . $gameId);
        exit;
    }

} catch (Throwable $e) {
    // Ловим ВСЕ ошибки (и PHP, и SQL) чтобы не было белого экрана
    $_SESSION['host_error'] = 'Системная ошибка: ' . $e->getMessage();
}

// --- Подготовка данных для отображения (после POST) ---

// Флеш-сообщения
$hostMessage = $_SESSION['host_message'] ?? null;
$hostError   = $_SESSION['host_error'] ?? null;
unset($_SESSION['host_message'], $_SESSION['host_error']);

// Если была фатальная ошибка до загрузки игры, прерываем
if (!isset($game) || !$game) {
    // Попытка восстановиться или вывести ошибку
    if ($hostError) echo "<div style='color:red; padding:20px;'>$hostError</div>";
    else echo "Ошибка загрузки игры.";
    exit;
}

// Загружаем списки игроков
$signedPlayers = [];
$pendingPlayers = [];

try {
    $stmt = $pdo->prepare('
        SELECT
          ug.id AS ug_id,
          ug.role,
          ug.status,
          u.nickname,
          u.email
        FROM user_games ug
        JOIN users u ON u.id = ug.user_id
        WHERE ug.game_id = :gid
        ORDER BY ug.created_at ASC
    ');
    $stmt->execute(['gid' => $gameId]);
    $allPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allPlayers as $p) {
        if ($p['status'] === 'pending') {
            $pendingPlayers[] = $p;
        } elseif ($p['status'] === 'signed') {
            $signedPlayers[] = $p;
        }
    }
} catch (PDOException $e) {
    // Игнорируем ошибку выборки игроков, просто списки будут пустыми
}

// Загружаем даты
$extraDates = [];
try {
    $stmt = $pdo->prepare('SELECT id, starts_at FROM game_dates WHERE game_id = :gid ORDER BY starts_at ASC');
    $stmt->execute(['gid' => $gameId]);
    $extraDates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Форматирование для шаблона
$status      = $game['status'] ?? 'upcoming';
$signedCount = (int)($game['signed_count'] ?? 0);
$maxPlayers  = $game['max_players'] !== null ? (int)$game['max_players'] : null;

$dateText = 'Время не указано';
if (!empty($game['starts_at'])) {
    $dt = new DateTime($game['starts_at']);
    $dateText = $dt->format('d.m.Y H:i');
}

$statusLabel = 'Анонс / набор';
$statusClass = 'pill-yellow';
if ($status === 'active') { $statusLabel = 'Идёт сейчас'; $statusClass = 'pill-green'; }
elseif ($status === 'finished') { $statusLabel = 'Завершено'; $statusClass = 'pill-gray'; }

$typeLabel = 'Игра';
if ($game['game_type'] === 'case') $typeLabel = 'Кейс';
elseif ($game['game_type'] === 'minigame') $typeLabel = 'Мини-игра';
elseif ($game['game_type'] === 'event') $typeLabel = 'Ивент';

?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Управление игрой — <?= h($game['title']) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="account.css" />
</head>
<body>
  <header class="lk-header">
    <div class="lk-logo">
      <div class="lk-logo-circle">AO</div>
      <div class="lk-logo-text-block">
        <div class="lk-logo-title">Fair of Contradictions</div>
        <div class="lk-logo-sub">Панель ведущего</div>
      </div>
    </div>
    <nav class="lk-nav">
      <button class="lk-nav-btn" onclick="window.location.href='index.php'">Главная</button>
      <?php if ($isAdmin): ?>
         <button class="lk-nav-btn" onclick="window.location.href='admin.php'">Админ-панель</button>
      <?php endif; ?>
      <button class="lk-nav-btn" onclick="window.location.href='account.php'">Профиль</button>
      <button class="lk-nav-btn" onclick="window.location.href='logout.php'">Выйти</button>
    </nav>
  </header>

  <main class="profile-main">
    <?php if ($hostError): ?>
      <section class="auth-error" style="margin-bottom:14px;">
        <div class="auth-error-title">Ошибка</div>
        <div class="auth-error-text"><?= h($hostError) ?></div>
      </section>
    <?php endif; ?>

    <?php if ($hostMessage): ?>
      <section class="alert-success" style="margin-bottom:14px;">
        <?= h($hostMessage) ?>
      </section>
    <?php endif; ?>

    <section class="games-list" style="margin-bottom:18px;">
      <article class="game-card">
        <header class="game-card-header">
          <div>
            <div class="game-card-title"><?= h($game['title']) ?></div>
            <div class="game-card-meta"><?= h($typeLabel) ?> · Ведущий: Ты · <?= h($dateText) ?></div>
          </div>
          <div class="game-card-tags">
            <span class="pill <?= $statusClass ?>"><?= h($statusLabel) ?></span>
            <span class="pill pill-outline">
              Игроков: <?= $signedCount ?><?= $maxPlayers !== null ? ' / ' . $maxPlayers : ' / ∞' ?>
            </span>
          </div>
        </header>
        
        <?php if (!empty($game['description'])): ?>
           <p class="game-card-text"><?= nl2br(h($game['description'])) ?></p>
        <?php endif; ?>
        
        <footer class="game-card-footer">
           <?php if (!empty($game['external_link'])): ?>
             <button class="game-card-btn" onclick="window.open('<?= h($game['external_link']) ?>', '_blank')">Правила</button>
           <?php endif; ?>
           <button class="game-card-btn game-card-btn-ghost" onclick="window.location.href='account.php'">
             ← Назад в кабинет
           </button>
        </footer>
      </article>
    </section>

    <section class="settings-grid">
      
      <article class="settings-card settings-card-wide" style="border: 1px solid rgba(250, 204, 21, 0.5);">
        <h2 class="settings-title" style="color: #fef08a;">Входящие заявки (<?= count($pendingPlayers) ?>)</h2>
        <p class="settings-text" style="color: #fef9c3;">
           Эти игроки подали заявку, но ещё не приняты в игру. Одобри или отклони их.
        </p>

        <?php if (!$pendingPlayers): ?>
          <p class="muted-text">Новых заявок пока нет.</p>
        <?php else: ?>
          <div style="display:flex; flex-direction:column; gap:8px; margin-top:6px;">
            <?php foreach ($pendingPlayers as $p): ?>
              <div style="border-radius:12px; border:1px solid rgba(250, 204, 21, 0.3); padding:8px 12px; background:rgba(30, 20, 5, 0.6); display:flex; flex-direction:column; gap:4px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                  <div>
                    <div style="font-size:14px; font-weight:700; color: #fff;">
                      <?= h($p['nickname']) ?>
                    </div>
                    <div style="font-size:11px; color:#e5e7eb;">
                       Желаемая роль: <?= h($p['role']) ?>
                    </div>
                  </div>
                  
                  <div style="display:flex; gap:6px;">
                      <form action="host_game.php?game_id=<?= $gameId ?>" method="post" style="margin:0;">
                          <input type="hidden" name="action" value="approve_player">
                          <input type="hidden" name="ug_id" value="<?= (int)$p['ug_id'] ?>">
                          <button type="submit" class="submit-btn" style="padding:6px 12px; font-size:11px; background:#15803d; min-width:auto; border:none;">
                              Принять
                          </button>
                      </form>

                      <form action="host_game.php?game_id=<?= $gameId ?>" method="post" style="margin:0;" onsubmit="return confirm('Отклонить заявку этого игрока?');">
                          <input type="hidden" name="action" value="remove_player">
                          <input type="hidden" name="ug_id" value="<?= (int)$p['ug_id'] ?>">
                          <button type="submit" class="submit-btn" style="padding:6px 12px; font-size:11px; background:#7f1d1d; min-width:auto; border:none;">
                              Отклонить
                          </button>
                      </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>

      <article class="settings-card">
        <h2 class="settings-title">Настройки</h2>
        <form action="host_game.php?game_id=<?= $gameId ?>" method="post" class="settings-form">
          <input type="hidden" name="action" value="update_game_host" />
          <div class="input-field">
            <label>Название</label>
            <input type="text" name="title" value="<?= h($game['title']) ?>" required />
          </div>
          <div class="input-field">
            <label>Дата и время</label>
            <input type="text" name="starts_at" value="<?= !empty($game['starts_at']) ? (new DateTime($game['starts_at']))->format('Y-m-d H:i') : '' ?>" placeholder="ДД.ММ.ГГГГ ЧЧ:ММ" />
          </div>
          <div class="input-field">
             <label>Статус</label>
             <select name="status">
                <option value="upcoming" <?= $status==='upcoming'?'selected':'' ?>>Анонс / Набор</option>
                <option value="active" <?= $status==='active'?'selected':'' ?>>Идёт сейчас</option>
                <option value="finished" <?= $status==='finished'?'selected':'' ?>>Завершено</option>
             </select>
          </div>
          <div class="input-field">
            <label class="checkbox-label">
              <input type="checkbox" name="signups_open" <?= (int)$game['signups_open'] === 1 ? 'checked' : '' ?> />
              <span>Набор открыт в календаре</span>
            </label>
          </div>
          <button class="submit-btn" type="submit">Сохранить</button>
        </form>
      </article>

      <article class="settings-card">
        <h2 class="settings-title">Основной состав (<?= count($signedPlayers) ?>)</h2>
        <?php if (!$signedPlayers): ?>
          <p class="muted-text">Игроков пока нет.</p>
        <?php else: ?>
          <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
            <?php foreach ($signedPlayers as $p): ?>
              <div style="background:rgba(255,255,255,0.05); padding:8px; border-radius:10px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                   <span style="font-weight:600;"><?= h($p['nickname']) ?></span>
                   <form action="host_game.php?game_id=<?= $gameId ?>" method="post" style="margin:0;" onsubmit="return confirm('Удалить игрока?');">
                     <input type="hidden" name="action" value="remove_player">
                     <input type="hidden" name="ug_id" value="<?= $p['ug_id'] ?>">
                     <button type="submit" style="background:none; border:none; color:#f87171; cursor:pointer; font-size:14px;">✕</button>
                   </form>
                </div>
                <form action="host_game.php?game_id=<?= $gameId ?>" method="post" style="display:flex; gap:4px;">
                   <input type="hidden" name="action" value="update_player_role">
                   <input type="hidden" name="ug_id" value="<?= $p['ug_id'] ?>">
                   <input type="text" name="role" value="<?= h($p['role']) ?>" style="width:100%; padding:4px; font-size:11px; border-radius:4px; border:none;">
                   <button type="submit" style="font-size:10px; cursor:pointer; border-radius:4px; border:none; background:#4b5563; color:#fff; padding:0 6px;">OK</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>

      <article class="settings-card">
          <h2 class="settings-title">Даты игры</h2>
          <p class="settings-text">
            Дополнительные даты проведения.
          </p>

          <?php if (!$extraDates): ?>
            <p class="muted-text">Доп. дат нет.</p>
          <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:6px; margin-top:6px;">
              <?php foreach ($extraDates as $d): ?>
                <?php $dtRow = new DateTime($d['starts_at']); ?>
                <div class="overview-row" style="align-items:center;">
                  <span><?= $dtRow->format('d.m.Y H:i') ?></span>
                  <form action="host_game.php?game_id=<?= $gameId ?>" method="post" style="margin:0;" onsubmit="return confirm('Удалить эту дату?');">
                    <input type="hidden" name="action" value="delete_game_date" />
                    <input type="hidden" name="date_id" value="<?= (int)$d['id'] ?>" />
                    <button type="submit" class="submit-btn" style="padding:4px 9px; font-size:11px; min-width:0; background:#7f1d1d;">✕</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form action="host_game.php?game_id=<?= $gameId ?>" method="post" style="margin-top:10px;">
            <input type="hidden" name="action" value="add_game_date" />
            <div class="input-field" style="margin-bottom:8px;">
              <label for="new-date">Новая дата</label>
              <input id="new-date" type="text" name="new_date" placeholder="ДД.ММ.ГГГГ ЧЧ:ММ" />
            </div>
            <button type="submit" class="submit-btn" style="width:100%;">Добавить дату</button>
          </form>
      </article>

    </section>
  </main>
  <footer class="lk-footer">
     <div class="lk-footer-inner">© 2025 Fair of Contradictions</div>
  </footer>
</body>
</html>
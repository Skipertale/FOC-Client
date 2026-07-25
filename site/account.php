<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/notifications.php';

// Если пользователь залогинен — достаём его данные
$user = null;
$userSettings = null;
$userGames = [];
$hostGames = [];
$totalGames = 0;
$totalCases = 0;
$roleStats = [];
$allAchievements = [];
$unlockedAchievements = [];

// Для колокольчика
$notifUnread = [
    'notification' => 0,
    'message'      => 0,
];
$userMessages = [];
$userSystemNotifications = [];

if (!empty($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];

    // Пользователь (+ account_role + флаги банов + верификация админом + подтверждение почты)
    $stmt = $pdo->prepare('
        SELECT
          id,
          nickname,
          email,
          contact,
          role_default,
          account_role,
          is_verified,        -- Верификация админом
          is_email_confirmed, -- Подтверждение почты
          is_banned,
          ban_cases,
          ban_minigames,
          ban_events
        FROM users
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_unset();
        session_destroy();
    } else {
        // Настройки пользователя
        $st = $pdo->prepare('SELECT * FROM user_settings WHERE user_id = :id LIMIT 1');
        $st->execute(['id' => $userId]);
        $userSettings = $st->fetch(PDO::FETCH_ASSOC);

        // Игры пользователя
        $st = $pdo->prepare('
            SELECT
              g.id,
              g.title,
              g.description,
              g.game_type,
              g.status AS game_status,
              g.starts_at,
              g.external_link,
              ug.role,
              ug.status AS user_status
            FROM user_games ug
            INNER JOIN games g ON g.id = ug.game_id
            WHERE ug.user_id = :id
            ORDER BY
              g.starts_at IS NULL,
              g.starts_at DESC,
              ug.id DESC
        ');
        $st->execute(['id' => $userId]);
        $userGames = $st->fetchAll(PDO::FETCH_ASSOC);

        // Игры, где пользователь назначен ведущим / владельцем
        try {
            $st = $pdo->prepare('
                SELECT
                  g.id,
                  g.title,
                  g.description,
                  g.game_type,
                  g.status,
                  g.starts_at,
                  g.external_link,
                  g.max_players,
                  g.signups_open,
                  (
                    SELECT COUNT(*)
                    FROM user_games ug
                    WHERE ug.game_id = g.id AND ug.status IN ("signed","pending")
                  ) AS signed_count
                FROM games g
                WHERE g.owner_user_id = :id
                ORDER BY
                  g.starts_at IS NULL,
                  g.starts_at DESC,
                  g.id DESC
            ');
            $st->execute(['id' => $userId]);
            $hostGames = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $hostGames = [];
        }

        // Статистика по играм
        $totalGames = count($userGames);
        foreach ($userGames as $g) {
            if ($g['game_type'] === 'case') {
                $totalCases++;
                $roleName = $g['role'] ?: 'Другое';
                if (!isset($roleStats[$roleName])) {
                    $roleStats[$roleName] = 0;
                }
                $roleStats[$roleName]++;
            }
        }

        // Ачивки (все)
        try {
            $st = $pdo->query('SELECT id, code, title, description FROM achievements ORDER BY id ASC');
            $allAchievements = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $allAchievements = [];
        }

        // Ачивки пользователя
        if ($allAchievements) {
            try {
                $st = $pdo->prepare('SELECT achievement_id FROM user_achievements WHERE user_id = :id');
                $st->execute(['id' => $userId]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $unlockedAchievements[] = (int)$r['achievement_id'];
                }
            } catch (PDOException $e) {
                $unlockedAchievements = [];
            }
        }

        // Уведомления / сообщения для колокольчика
        try {
            $notifUnread = count_unread_notifications($pdo, $userId);
            $userMessages = fetch_user_notifications($pdo, $userId, 'message', 20);
            $userSystemNotifications = fetch_user_notifications($pdo, $userId, 'notification', 20);
        } catch (PDOException $e) {
            $notifUnread = ['notification' => 0, 'message' => 0];
            $userMessages = [];
            $userSystemNotifications = [];
        }
    }
}

// Сообщения (flash)
$auth_error       = $_SESSION['auth_error'] ?? null;
$auth_error_type  = $_SESSION['auth_error_type'] ?? 'login';
$settings_success = $_SESSION['settings_success'] ?? null;
$settings_error   = $_SESSION['settings_error'] ?? null;

// Перехват сообщений от смены пароля
if (isset($_SESSION['password_success'])) {
    $settings_success = $_SESSION['password_success'];
    unset($_SESSION['password_success']);
}
if (isset($_SESSION['password_error'])) {
    $settings_error = $_SESSION['password_error'];
    unset($_SESSION['password_error']);
}

unset(
    $_SESSION['auth_error'],
    $_SESSION['auth_error_type'],
    $_SESSION['settings_success'],
    $_SESSION['settings_error']
);

$isAdmin = ($user && ($user['account_role'] ?? 'player') === 'admin');

$totalUnreadForBell = $notifUnread['notification'] + $notifUnread['message'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Личный кабинет — Fair of Contradictions</title>

  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="account.css" />

  <style>
    .notif-bell-btn {
      position: relative;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      background: rgba(10, 3, 35, 0.95);
      cursor: pointer;
      padding: 0;
      margin-left: 6px;
      order: 99;
    }
    .notif-bell-btn .bell-icon {
      font-size: 16px;
      line-height: 1;
    }
    .notif-bell-badge {
      position: absolute;
      top: -4px;
      right: -4px;
      min-width: 16px;
      height: 16px;
      border-radius: 999px;
      background: #e25568;
      color: #fff;
      font-size: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 4px;
      box-shadow: 0 0 0 1px rgba(10, 3, 35, 0.95);
    }

    .notif-panel {
      position: fixed;
      top: 76px;
      right: 24px;
      width: 360px;
      max-height: 480px;
      background:
        radial-gradient(circle at 0 0, rgba(255, 122, 217, 0.22), transparent 55%),
        radial-gradient(circle at 100% 0, rgba(130, 95, 255, 0.28), transparent 55%),
        rgba(9, 2, 26, 0.98);
      border-radius: 18px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: 0 18px 55px rgba(0, 0, 0, 0.85);
      z-index: 2000;
      display: flex;
      flex-direction: column;
      overflow: hidden;

      opacity: 0;
      transform: translateY(-10px) scale(0.97);
      pointer-events: none;
      transition:
        opacity 0.18s ease-out,
        transform 0.18s ease-out;
    }

    .notif-panel-open {
      opacity: 1;
      transform: translateY(0) scale(1);
      pointer-events: auto;
    }

    .notif-panel::before {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      pointer-events: none;
      border-top: 1px solid rgba(255, 122, 217, 0.65);
      border-bottom: 1px solid rgba(130, 95, 255, 0.45);
      opacity: 0.7;
    }
    .notif-panel-header {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding: 10px 12px 6px 12px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    }

    .notif-panel-header-left {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .notif-panel-avatar {
      width: 32px;
      height: 32px;
      border-radius: 999px;
      overflow: hidden;
      flex-shrink: 0;
      box-shadow:
        0 0 0 1px rgba(255, 255, 255, 0.22),
        0 0 18px rgba(255, 122, 217, 0.55);
    }

    .notif-panel-avatar img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .notif-panel-text {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .notif-panel-subtitle {
      font-size: 10px;
      color: rgba(255, 255, 255, 0.6);
    }
    .notif-panel-title {
      font-size: 13px;
      font-weight: 600;
    }
    .notif-tabs {
      display: flex;
      gap: 6px;
    }
    .notif-tab-btn {
      flex: 1;
      border-radius: 999px;
      border: none;
      padding: 5px 0;
      font-size: 11px;
      cursor: pointer;
      background: transparent;
      color: rgba(255, 255, 255, 0.65);
    }
    .notif-tab-btn-active {
      background: rgba(130, 95, 255, 0.32);
      color: #ffffff;
    }
    .notif-panel-body {
      padding: 8px 10px 10px;
      overflow-y: auto;
    }
    .notif-list-empty {
      font-size: 12px;
      color: rgba(255, 255, 255, 0.55);
      padding: 4px 2px;
    }
    .notif-item {
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, 0.16);
      padding: 7px 9px;
      margin-bottom: 6px;
      background: rgba(7, 1, 24, 0.9);
    }
    .notif-item-unread {
      border-color: rgba(161, 132, 255, 0.95);
      background: rgba(19, 7, 58, 0.95);
    }
    .notif-item-title {
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 2px;
    }
    .notif-item-body {
      font-size: 11px;
      color: rgba(255, 255, 255, 0.78);
    }
    .notif-item-meta {
      margin-top: 4px;
      font-size: 10px;
      color: rgba(255, 255, 255, 0.6);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
    }

    .notif-item-from {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .notif-item-avatar {
      width: 22px;
      height: 22px;
      border-radius: 999px;
      overflow: hidden;
      flex-shrink: 0;
      box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.18);
    }

    .notif-item-avatar img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .notif-item-date {
      white-space: nowrap;
    }
  </style>
</head>
<body>
  <header class="lk-header">
    <div class="lk-logo">
      <div class="lk-logo-circle">AO</div>
      <div class="lk-logo-text-block">
        <div class="lk-logo-title">Fair of Contradictions</div>
        <div class="lk-logo-sub">Ярмарка противоречий — личный кабинет</div>
      </div>
    </div>

    <nav class="lk-nav">
      <button class="lk-nav-btn" type="button" onclick="window.location.href='index.php'">На главную</button>

      <?php if ($isAdmin): ?>
        <button class="lk-nav-btn" type="button" onclick="window.location.href='admin.php'">
          Админ-панель
        </button>
      <?php endif; ?>

      <?php if ($user): ?>
        <button
          class="notif-bell-btn"
          type="button"
          id="notifBell"
          title="Сообщения и уведомления"
        >
          <span class="bell-icon">🔔</span>
          <?php if ($totalUnreadForBell > 0): ?>
            <span class="notif-bell-badge" id="notifBellBadge">
              <?= $totalUnreadForBell ?>
            </span>
          <?php endif; ?>
        </button>

        <button class="lk-nav-btn lk-nav-btn-active" type="button" onclick="window.location.href='account.php'">
          Профиль
        </button>
        <button class="lk-nav-btn" type="button" onclick="window.location.href='logout.php'">
          Выйти
        </button>
      <?php else: ?>
        <button class="lk-nav-btn lk-nav-btn-active" type="button" onclick="window.location.href='account.php'">
          Войти / Регистрация
        </button>
      <?php endif; ?>
    </nav>
  </header>

  <?php if ($user): ?>
    <section class="notif-panel" id="notifPanel">
      <header class="notif-panel-header">
        <div class="notif-panel-header-left">
          <div class="notif-panel-avatar">
            <img src="bot-avatar.png" alt="Бот-Информатор">
          </div>
          <div class="notif-panel-text">
            <div class="notif-panel-title">Бот-Информатор</div>
            <div class="notif-panel-subtitle">Центр уведомлений</div>
          </div>
        </div>

        <div class="notif-tabs">
          <button
            class="notif-tab-btn notif-tab-btn-active"
            type="button"
            data-kind="message"
            id="notifTabMessagesBtn"
          >
            Сообщения<?= $notifUnread['message'] ? ' (' . (int)$notifUnread['message'] . ')' : '' ?>
          </button>
          <button
            class="notif-tab-btn"
            type="button"
            data-kind="notification"
            id="notifTabNotificationsBtn"
          >
            Уведомления<?= $notifUnread['notification'] ? ' (' . (int)$notifUnread['notification'] . ')' : '' ?>
          </button>
        </div>
      </header>

      <div class="notif-panel-body">
        <div id="notifMessagesTab">
          <?php if (!$userMessages): ?>
            <div class="notif-list-empty">
              Пока нет сообщений. Как только милая бот-девочка что-нибудь расскажет — увидишь это здесь ❤️
            </div>
          <?php else: ?>
            <?php foreach ($userMessages as $m): ?>
              <?php
                $unread = (int)$m['is_read'] === 0;
                $from   = $m['from_nickname'] ?: 'Бот-Информатор';
                $created = $m['created_at'] ? (new DateTime($m['created_at']))->format('d.m.Y H:i') : '';
              ?>
              <article class="notif-item <?= $unread ? 'notif-item-unread' : '' ?>">
                <div class="notif-item-title">
                  <?= htmlspecialchars($m['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
                <?php if (!empty($m['body'])): ?>
                  <div class="notif-item-body">
                    <?= nl2br(htmlspecialchars($m['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
                  </div>
                <?php endif; ?>
                <div class="notif-item-meta">
                  <div class="notif-item-from">
                    <?php if ($from === 'Бот-Информатор'): ?>
                      <span class="notif-item-avatar">
                        <img src="bot-avatar.png" alt="Бот-Информатор">
                      </span>
                    <?php endif; ?>
                    <span>
                      От: <?= htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </span>
                  </div>
                  <span class="notif-item-date">
                    <?= htmlspecialchars($created, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </span>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div id="notifNotificationsTab" style="display:none;">
          <?php if (!$userSystemNotifications): ?>
            <div class="notif-list-empty">
              Пока нет уведомлений. Здесь будут появляться напоминания о записях на игры и другие важные события.
            </div>
          <?php else: ?>
            <?php foreach ($userSystemNotifications as $n): ?>
              <?php
                $unread = (int)$n['is_read'] === 0;
                $from   = $n['from_nickname'] ?: 'Система';
                $created = $n['created_at'] ? (new DateTime($n['created_at']))->format('d.m.Y H:i') : '';
              ?>
              <article class="notif-item <?= $unread ? 'notif-item-unread' : '' ?>">
                <div class="notif-item-title">
                  <?= htmlspecialchars($n['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
                <?php if (!empty($n['body'])): ?>
                  <div class="notif-item-body">
                    <?= nl2br(htmlspecialchars($n['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
                  </div>
                <?php endif; ?>
                <div class="notif-item-meta">
                  <span>Источник: <?= htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span><?= htmlspecialchars($created, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!$user): ?>
    <?php
      $activeTab = ($auth_error_type === 'register') ? 'register' : 'login';
    ?>
    <section class="auth-container" id="authBox">
      <div class="auth-header">
        <h1 class="auth-title">Вход в Ярмарку противоречий</h1>
        <p class="auth-subtitle">
          Авторизуйся или создай аккаунт, чтобы следить за своими кейсами, мини-играми и прогрессом на сервере.
        </p>
      </div>

      <?php if ($auth_error): ?>
        <div class="auth-error">
          <div class="auth-error-title">Упс, что-то пошло не так</div>
          <div class="auth-error-text">
            <?= htmlspecialchars($auth_error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="auth-tabs">
        <button
          class="auth-tab <?= $activeTab === 'login' ? 'active' : '' ?>"
          type="button"
          data-tab="login"
        >
          Вход
        </button>
        <button
          class="auth-tab <?= $activeTab === 'register' ? 'active' : '' ?>"
          type="button"
          data-tab="register"
        >
          Регистрация
        </button>
      </div>

      <div class="auth-content <?= $activeTab === 'login' ? 'active' : '' ?>" id="login">
        <form class="auth-form" action="login.php" method="post">
          <div class="input-field">
            <label for="login-identifier">Email или логин</label>
            <input id="login-identifier" name="identifier" type="text" required />
          </div>
          <div class="input-field">
            <label for="login-password">Пароль</label>
            <input id="login-password" name="password" type="password" required />
          </div>
          <div class="auth-extra-row">
            <label class="checkbox-label">
              <input type="checkbox" name="remember" />
              <span>Запомнить меня</span>
            </label>
            <a href="#" class="auth-link">Забыли пароль?</a>
          </div>
          <button class="submit-btn" type="submit">Войти</button>
        </form>
      </div>

      <div class="auth-content <?= $activeTab === 'register' ? 'active' : '' ?>" id="register">
        <form class="auth-form" action="register.php" method="post">
          <div class="input-field">
            <label for="reg-nickname">Никнейм (в AO)</label>
            <input id="reg-nickname" name="nickname" type="text" required />
          </div>
          <div class="input-field">
            <label for="reg-email">Email</label>
            <input id="reg-email" name="email" type="email" required />
          </div>
          <div class="input-field">
            <label for="reg-password">Пароль</label>
            <input id="reg-password" name="password" type="password" required />
          </div>
          <div class="input-field">
            <label for="reg-password2">Повтор пароля</label>
            <input id="reg-password2" name="password_confirm" type="password" required />
          </div>
          <div class="input-field">
            <label for="reg-contact">Discord / VK (по желанию)</label>
            <input id="reg-contact" name="contact" type="text" placeholder="например, discord: yourtag" />
          </div>
          <label class="checkbox-label checkbox-label-full">
            <input type="checkbox" name="rules" required />
            <span>С правилами сервера ознакомлен и согласен</span>
          </label>
          <button class="submit-btn" type="submit">Создать аккаунт</button>
        </form>
      </div>
    </section>

  <?php else: ?>
    <main class="profile-main" id="profileArea">

      <?php
        $isAdminVerified = (int)($user['is_verified'] ?? 0);
        $isEmailConfirmed = (int)($user['is_email_confirmed'] ?? 0);
        
        $isBannedGlobal = (int)($user['is_banned'] ?? 0);
        $banCases       = (int)($user['ban_cases'] ?? 0);
        $banMinigames   = (int)($user['ban_minigames'] ?? 0);
        $banEvents      = (int)($user['ban_events'] ?? 0);
        $hasAnyBan      = $isBannedGlobal || $banCases || $banMinigames || $banEvents;
      ?>

      <?php if ($isEmailConfirmed !== 1): ?>
        <section class="auth-error" style="margin-bottom:14px;">
          <div class="auth-error-title">Почта не подтверждена</div>
          <div class="auth-error-text">
            Чтобы завершить настройку аккаунта и получить доступ к записи на игры, подтверди email в настройках ниже.
          </div>
        </section>
      <?php endif; ?>

      <?php if ($isEmailConfirmed === 1 && $isAdminVerified !== 1): ?>
        <section class="auth-error" style="margin-bottom:14px; border-color: rgba(250, 204, 21, 0.8); background: radial-gradient(circle at 0 0, rgba(250, 204, 21, 0.15), transparent 60%), rgba(30, 20, 5, 0.96);">
          <div class="auth-error-title" style="color: #fef08a;">Ожидание проверки</div>
          <div class="auth-error-text" style="color: #fef9c3;">
            Почта подтверждена! Теперь твой аккаунт должен проверить администратор.
            Свяжись с админом в Discord/VK, чтобы ускорить процесс. Пока проверка не пройдена, запись на игры ограничена.
          </div>
        </section>
      <?php endif; ?>

      <?php if ($hasAnyBan): ?>
        <section class="auth-error" style="margin-bottom:14px;">
          <div class="auth-error-title">
            <?= $isBannedGlobal ? 'Аккаунт заблокирован' : 'На аккаунт наложены ограничения' ?>
          </div>
          <div class="auth-error-text">
            <?php
              if ($isBannedGlobal) {
                  echo 'Ты не можешь участвовать в играх до снятия блокировки администрацией.';
              }

              $parts = [];
              if ($banCases)     { $parts[] = 'кейсы'; }
              if ($banMinigames){ $parts[] = 'мини-игры'; }
              if ($banEvents)    { $parts[] = 'ивенты'; }

              if ($parts) {
                  echo ($isBannedGlobal ? '<br>' : '');
                  echo 'Ограничен доступ к типам игр: '
                      . htmlspecialchars(implode(', ', $parts), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                      . '.';
              }
            ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($settings_error): ?>
        <section class="auth-error">
          <div class="auth-error-title">Ошибка</div>
          <div class="auth-error-text">
            <?= htmlspecialchars($settings_error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($settings_success): ?>
        <section class="alert-success">
          <?= htmlspecialchars($settings_success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </section>
      <?php endif; ?>

      <section class="profile-header">
        <article class="profile-card">
          <div class="profile-avatar">
            <?php
              $avatarFsPath  = __DIR__ . '/uploads/avatars/' . (int)$user['id'] . '.png';
              $avatarWebPath = 'uploads/avatars/' . (int)$user['id'] . '.png';
              if (file_exists($avatarFsPath)):
            ?>
              <img src="<?= htmlspecialchars($avatarWebPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?v=<?= time() ?>" alt="Аватар">
            <?php
              else:
                $initial = mb_substr($user['nickname'], 0, 1, 'UTF-8');
                $initialUp = mb_strtoupper($initial, 'UTF-8');
            ?>
              <?= htmlspecialchars($initialUp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            <?php endif; ?>
          </div>
          <div class="profile-name-row">
            <div class="profile-name">
              <?= htmlspecialchars($user['nickname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
            <div class="profile-account-role">
              <?php if ($isAdmin): ?>
                <span class="role-chip role-chip-admin">Администратор</span>
              <?php else: ?>
                <span class="role-chip">Игрок</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="profile-sub">
            <?= htmlspecialchars($user['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            <?php if (!empty($user['contact'])): ?>
              · <?= htmlspecialchars($user['contact'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            <?php else: ?>
              · Контакты не указаны
            <?php endif; ?>
          </div>
          <button class="edit-btn" id="openEditModal">Редактировать</button>
        </article>

        <article class="profile-highlight">
          <h2 class="profile-highlight-title">Добро пожаловать в личный кабинет</h2>
          <p class="profile-highlight-text">
            Здесь собраны твои кейсы, мини-игры и прогресс на сервере Fair of Contradictions.
            Игры и статистика подгружаются из базы данных.
          </p>
          <ul class="profile-highlight-list">
            <li>Отслеживай участие и роли в делах и мини-играх.</li>
            <li>Настраивай удобное время, роли и уведомления под свой режим.</li>
            <li>Собирай достижения и делись прогрессом с другими.</li>
          </ul>
        </article>
      </section>

      <?php
        $prefRolesStr = $userSettings['preferred_roles'] ?? '';
        $prefRolesArr = array_filter(array_map('trim', explode(',', $prefRolesStr)));
        $tzDisplay    = $userSettings['timezone'] ?? 'не указан';
      ?>
      <section class="profile-overview">
        <div class="overview-grid">
          <article class="overview-card">
            <div class="overview-label">Общие цифры</div>
            <div class="overview-title">
              <?= $totalGames ?> игр · <?= $totalCases ?> кейсов
            </div>
            <div class="overview-row">
              <span>Типы:</span>
              <span>кейсы, мини-игры, ивенты</span>
            </div>
            <div class="overview-row">
              <span>Роли:</span>
              <span>см. распределение ниже</span>
            </div>
          </article>

          <article class="overview-card">
            <div class="overview-label">Статус</div>
            <div class="overview-title">
              <?= $totalGames > 0 ? 'Активный участник' : 'Начинающий' ?>
            </div>
            <div class="overview-row">
              <span>Текущие наборы:</span>
              <span>во вкладке «Мои игры»</span>
            </div>
            <div class="overview-row">
              <span>Достижения:</span>
              <span><?= $unlockedAchievements ? count($unlockedAchievements) . ' открыто' : 'пока нет' ?></span>
            </div>
          </article>

          <article class="overview-card">
            <div class="overview-label">Профиль</div>
            <div class="overview-title">
              <?= htmlspecialchars($user['role_default'] ?? 'Игрок', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>

            <div class="overview-row overview-row-stack">
              <span class="overview-row-label">Часовой пояс</span>
              <span class="overview-row-value"><?= htmlspecialchars($tzDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>

            <div class="overview-row overview-row-stack">
              <span class="overview-row-label">Предпочтительные роли</span>
              <span class="overview-row-value">
                <?php if (!$prefRolesArr): ?>
                  не указаны
                <?php else: ?>
                  <?php foreach ($prefRolesArr as $r): ?>
                    <span class="pref-role-chip"><?= htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </span>
            </div>
          </article>
        </div>
      </section>

      <section class="profile-tabs">
        <button class="p-tab active" type="button" data-section="stats">Статистика</button>
        <button class="p-tab" type="button" data-section="games">Мои игры</button>
        <button class="p-tab" type="button" data-section="host">Игры, которые я веду</button>
        <button class="p-tab" type="button" data-section="settings">Настройки</button>
      </section>

      <section class="p-section active" id="stats">
        <article class="stats-card">
          <header class="stats-header">
            <h2 class="stats-title">Общая статистика по кейсам</h2>
            <p class="stats-subtitle">
              Распределение сыгранных кейсов по ролям. В дальнейшем сюда можно будет добавить детализацию по типам дел и мастерам.
            </p>
          </header>

          <div class="stats-breakdown">
            <div class="stats-breakdown-title">Роли в кейсах</div>
            <ul class="stats-breakdown-list">
              <?php if ($totalCases === 0): ?>
                <li><span>Пока нет сыгранных кейсов</span><span>–</span></li>
              <?php else: ?>
                <?php foreach ($roleStats as $roleName => $count): ?>
                  <li>
                    <span><?= htmlspecialchars($roleName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <span><?= (int)$count ?></span>
                  </li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>
          </div>

          <?php if ($allAchievements): ?>
            <div class="achievements-block">
              <div class="achievements-header">
                <div class="achievements-title">Достижения</div>
                <div class="achievements-subtitle">
                  За участие в играх и особые условия можно получать ачивки. Разблокированные подсвечиваются ярче.
                </div>
              </div>
              <div class="achievements-grid">
                <?php foreach ($allAchievements as $ach): ?>
                  <?php
                    $unlocked = in_array((int)$ach['id'], $unlockedAchievements, true);
                    $achClass = $unlocked ? 'achievement-card achievement-card-unlocked' : 'achievement-card';
                  ?>
                  <article class="<?= $achClass ?>">
                    <div class="achievement-icon">
                      <?= $unlocked ? '★' : '✦' ?>
                    </div>
                    <div class="achievement-body">
                      <div class="achievement-title">
                        <?= htmlspecialchars($ach['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </div>
                      <div class="achievement-desc">
                        <?= htmlspecialchars($ach['description'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </div>
                    </div>
                    <div class="achievement-status">
                      <?= $unlocked ? 'Открыто' : 'Не получено' ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </article>
      </section>

      <section class="p-section" id="games">
        <section class="games-header">
          <div>
            <h2 class="games-title">Мои игры</h2>
            <p class="games-subtitle">
              Список всех дел, мини-игр и ивентов, в которых ты участвуешь или участвовал.
              Фильтр ниже работает по статусу участия и роли.
            </p>
          </div>
          <div class="games-filters">
            <div class="games-toggle">
              <button class="games-toggle-btn games-toggle-btn-active" type="button" data-filter-status="all">Все</button>
              <button class="games-toggle-btn" type="button" data-filter-status="active">Активные</button>
              <button class="games-toggle-btn" type="button" data-filter-status="finished">Завершённые</button>
            </div>
            <select class="games-filter-select" id="roleFilter">
              <option value="all">Все роли</option>
              <option value="Адвокат">Адвокат</option>
              <option value="Прокурор">Прокурор</option>
              <option value="Судья">Судья</option>
              <option value="Присяжный">Присяжный</option>
              <option value="Свидетель">Свидетель</option>
              <option value="Ведущий">Ведущий</option>
            </select>
          </div>
        </section>

        <section class="games-list" id="gamesList">
          <?php if (!$userGames): ?>
            <p class="muted-text">Пока что у тебя нет ни одной записанной игры. Как только ты запишешься на дело или мини-игру, они появятся здесь.</p>
          <?php else: ?>
            <?php foreach ($userGames as $g): ?>
              <?php
                $roleName   = $g['role'] ?: 'Игрок';
                $gameType   = $g['game_type'];      // case / minigame / event
                $gameStatus = $g['game_status'];    // upcoming / active / finished / cancelled
                $userStatus = $g['user_status'];    // signed / pending / cancelled / finished

                // Человеческий статус
                $statusLabel = 'Статус не указан';
                $statusClass = 'pill-gray';

                if ($userStatus === 'signed') {
                    $statusLabel = 'Записан';
                    $statusClass = 'pill-green';
                } elseif ($userStatus === 'pending') {
                    $statusLabel = 'Ожидает подтверждения';
                    $statusClass = 'pill-yellow';
                } elseif ($userStatus === 'finished') {
                    $statusLabel = 'Завершено';
                    $statusClass = 'pill-gray';
                } elseif ($userStatus === 'cancelled') {
                    $statusLabel = 'Отменено';
                    $statusClass = 'pill-gray';
                }

                // Тип игры текстом
                $typeLabel = 'Игра';
                if ($gameType === 'case') {
                    $typeLabel = 'Кейс';
                } elseif ($gameType === 'minigame') {
                    $typeLabel = 'Мини-игра';
                } elseif ($gameType === 'event') {
                    $typeLabel = 'Ивент';
                }

                // Дата/время
                $dateText = 'Время не указано';
                if (!empty($g['starts_at'])) {
                    $dt = new DateTime($g['starts_at']);
                    $dateText = $dt->format('d.m.Y H:i');
                }
              ?>
              <article
                class="game-card"
                data-status="<?= htmlspecialchars($userStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-role="<?= htmlspecialchars($roleName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-type="<?= htmlspecialchars($gameType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              >
                <header class="game-card-header">
                  <div>
                    <div class="game-card-title">
                      <?= htmlspecialchars($g['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                    <div class="game-card-meta">
                      <?= htmlspecialchars($typeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      ·
                      <?= htmlspecialchars($dateText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                  </div>
                  <div class="game-card-tags">
                    <span class="pill <?= $statusClass ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <span class="pill pill-outline"><?= htmlspecialchars($roleName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </div>
                </header>
                <?php if (!empty($g['description'])): ?>
                  <p class="game-card-text">
                    <?= nl2br(htmlspecialchars($g['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
                  </p>
                <?php endif; ?>
                <footer class="game-card-footer">
                  <?php if (!empty($g['external_link'])): ?>
                    <button class="game-card-btn" type="button" onclick="window.open('<?= htmlspecialchars($g['external_link'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>','_blank')">
                      Описание / правила
                    </button>
                  <?php endif; ?>
                  <?php if (in_array($userStatus, ['signed','pending'], true)): ?>
                    <button class="game-card-btn game-card-btn-ghost" type="button">
                      Отменить участие (будет позже)
                    </button>
                  <?php endif; ?>
                </footer>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>
      </section>

      <section class="p-section" id="host">
        <section class="games-header">
          <div>
            <h2 class="games-title">Игры, которые я веду</h2>
            <p class="games-subtitle">
              Здесь отображаются игры, где администратор назначил тебя ведущим.
              Показаны только активные и предстоящие игры.
            </p>
          </div>
        </section>

        <section class="games-list">
          <?php
            $hasActiveHostGames = false;
            if ($hostGames) {
                foreach ($hostGames as $g) {
                    if (in_array($g['status'], ['upcoming','active'], true)) {
                        $hasActiveHostGames = true;
                        break;
                    }
                }
            }
          ?>

          <?php if (!$hostGames || !$hasActiveHostGames): ?>
            <p class="muted-text">
              Сейчас у тебя нет активных игр, где ты назначен ведущим.
              Когда администратор привяжет к тебе игру, она появится здесь.
            </p>
          <?php else: ?>
            <?php foreach ($hostGames as $g): ?>
              <?php
                if (!in_array($g['status'], ['upcoming','active'], true)) {
                    continue;
                }

                $gameType = $g['game_type'];
                $typeLabel = 'Игра';
                if ($gameType === 'case') {
                    $typeLabel = 'Кейс';
                } elseif ($gameType === 'minigame') {
                    $typeLabel = 'Мини-игра';
                } elseif ($gameType === 'event') {
                    $typeLabel = 'Ивент';
                }

                $dateText = 'Время не указано';
                if (!empty($g['starts_at'])) {
                    $dt = new DateTime($g['starts_at']);
                    $dateText = $dt->format('d.m.Y H:i');
                }

                $statusLabel = 'Анонс / набор';
                $statusClass = 'pill-yellow';
                if ($g['status'] === 'active') {
                    $statusLabel = 'Идёт сейчас';
                    $statusClass = 'pill-green';
                }

                $signedCount = (int)($g['signed_count'] ?? 0);
                $maxPlayers  = $g['max_players'] !== null ? (int)$g['max_players'] : null;
              ?>
              <article class="game-card">
                <header class="game-card-header">
                  <div>
                    <div class="game-card-title">
                      <?= htmlspecialchars($g['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                    <div class="game-card-meta">
                      <?= htmlspecialchars($typeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      ·
                      <?= htmlspecialchars($dateText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                  </div>
                  <div class="game-card-tags">
                    <span class="pill <?= $statusClass ?>">
                      <?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </span>
                    <span class="pill pill-outline">
                      Записано: <?= $signedCount ?><?= $maxPlayers !== null ? ' / ' . $maxPlayers : ' / ∞' ?>
                    </span>
                  </div>
                </header>

                <?php if (!empty($g['description'])): ?>
                  <p class="game-card-text">
                    <?= nl2br(htmlspecialchars($g['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
                  </p>
                <?php endif; ?>

                <footer class="game-card-footer">
                  <?php if (!empty($g['external_link'])): ?>
                    <button
                      class="game-card-btn"
                      type="button"
                      onclick="window.open('<?= htmlspecialchars($g['external_link'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>','_blank')"
                    >
                      Описание / правила
                    </button>
                  <?php endif; ?>
                  <button
                    class="game-card-btn game-card-btn-ghost"
                    type="button"
                    onclick="window.location.href='host_game.php?game_id=<?= (int)$g['id'] ?>'"
                  >
                    Управление игрой
                  </button>
                </footer>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>
      </section>

      <section class="p-section" id="settings">
        <section class="settings-grid">
          <article class="settings-card settings-card-wide">
            <h2 class="settings-title">Игровые настройки и уведомления</h2>
            <p class="settings-text">
              Эти параметры будут учитываться мастерами при наборах и помогут серверу подстраиваться под твой режим.
            </p>
            <form class="settings-form" action="settings.php" method="post">
              <div class="settings-row">
                <div class="input-field">
                  <label for="timezone">Часовой пояс</label>
                  <input
                    id="timezone"
                    name="timezone"
                    type="text"
                    placeholder="например, UTC+3 / МСК"
                    value="<?= htmlspecialchars($userSettings['timezone'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  />
                </div>
                <div class="input-field">
                  <label for="preferred_time">Удобное время игр</label>
                  <?php $pt = $userSettings['preferred_time'] ?? 'any'; ?>
                  <select id="preferred_time" name="preferred_time">
                    <option value="any"      <?= $pt === 'any' ? 'selected' : '' ?>>Не важно</option>
                    <option value="evening"  <?= $pt === 'evening' ? 'selected' : '' ?>>Вечер</option>
                    <option value="night"    <?= $pt === 'night' ? 'selected' : '' ?>>Ночь</option>
                    <option value="weekend"  <?= $pt === 'weekend' ? 'selected' : '' ?>>Выходные</option>
                  </select>
                </div>
              </div>

              <?php
                $prefRolesStr = $userSettings['preferred_roles'] ?? '';
                $prefRolesArr = array_filter(array_map('trim', explode(',', $prefRolesStr)));
                $isPref = function (string $role) use ($prefRolesArr): bool {
                    return in_array($role, $prefRolesArr, true);
                };

                $allRoles = ['Адвокат', 'Прокурор', 'Судья', 'Присяжный', 'Свидетель', 'Ведущий'];
                $currentDefaultRole = $user['role_default'] ?? 'Адвокат';
              ?>

              <div class="settings-row">
                <div class="settings-column">
                  <div class="settings-subtitle">Предпочтительные роли</div>
                  <div class="settings-roles">
                    <?php foreach ($allRoles as $r): ?>
                      <label class="checkbox-label">
                        <input
                          type="checkbox"
                          name="preferred_roles[]"
                          value="<?= htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                          <?= $isPref($r) ? 'checked' : '' ?>
                        />
                        <span><?= htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="settings-column">
                  <div class="settings-subtitle">Роль по умолчанию</div>
                  <div class="input-field input-field-narrow">
                    <label for="role_default">Роль, с которой ты чаще всего играешь</label>
                    <select id="role_default" name="role_default">
                      <?php foreach ($allRoles as $r): ?>
                        <option
                          value="<?= htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                          <?= $currentDefaultRole === $r ? 'selected' : '' ?>
                        >
                          <?= htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="settings-subtitle settings-subtitle-offset">Уведомления</div>
                  <?php
                    $notifyNew    = (int)($userSettings['notify_new_games'] ?? 1);
                    $notifyTaken  = (int)($userSettings['notify_taken'] ?? 1);
                    $notifyBefore = (int)($userSettings['notify_before_game'] ?? 1);
                  ?>
                  <label class="checkbox-label checkbox-label-block">
                    <input type="checkbox" name="notify_new_games" <?= $notifyNew ? 'checked' : '' ?> />
                    <span>Новые дела и мини-игры на сервере</span>
                  </label>
                  <label class="checkbox-label checkbox-label-block">
                    <input type="checkbox" name="notify_taken" <?= $notifyTaken ? 'checked' : '' ?> />
                    <span>Когда тебя берут в состав игры</span>
                  </label>
                  <label class="checkbox-label checkbox-label-block">
                    <input type="checkbox" name="notify_before_game" <?= $notifyBefore ? 'checked' : '' ?> />
                    <span>Напоминание перед началом игры</span>
                  </label>
                </div>
              </div>

              <button class="submit-btn" type="submit">Сохранить настройки</button>
            </form>
          </article>

          <article class="settings-card">
            <h2 class="settings-title">Безопасность аккаунта</h2>
            
            <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                <div class="settings-subtitle">Электронная почта</div>
                
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                    <div style="font-size:14px; color: #fff; background: rgba(255,255,255,0.1); padding: 6px 12px; border-radius: 8px;">
                        <?= htmlspecialchars($user['email']) ?>
                    </div>
                    
                    <?php if ($isEmailConfirmed === 1): ?>
                        <span class="pill pill-green">✓ Подтверждена</span>
                    <?php else: ?>
                        <span class="pill pill-yellow">! Не подтверждена</span>
                    <?php endif; ?>
                </div>

                <?php if ($isEmailConfirmed === 0): ?>
                    <p class="settings-text">
                        Подтверди почту, это первый шаг к активации аккаунта.
                    </p>
                    <form action="request_verify_email.php" method="post">
                        <button class="submit-btn" style="font-size: 12px; padding: 8px 16px; width: auto;">
                            Отправить письмо с подтверждением
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div>
                <div class="settings-subtitle">Смена пароля</div>
                <form action="change_password.php" method="post" class="settings-form">
                    <div class="input-field">
                        <label for="pass-old">Текущий пароль</label>
                        <input type="password" id="pass-old" name="old_password" required placeholder="••••••">
                    </div>
                    
                    <div class="settings-row">
                        <div class="input-field" style="flex:1;">
                            <label for="pass-new">Новый пароль</label>
                            <input type="password" id="pass-new" name="new_password" required placeholder="Минимум 8 символов">
                        </div>
                        <div class="input-field" style="flex:1;">
                            <label for="pass-confirm">Повторите новый</label>
                            <input type="password" id="pass-confirm" name="new_password_again" required placeholder="••••••">
                        </div>
                    </div>

                    <button class="submit-btn" type="submit">Обновить пароль</button>
                </form>
            </div>
          </article>
        </section>
      </section>
    </main>

    <div class="modal-overlay" id="editModal">
      <div class="modal-window">
        <button type="button" class="modal-close" id="closeEditModal">&times;</button>

        <h2 class="modal-title">Редактирование профиля</h2>

        <form id="editProfileForm" enctype="multipart/form-data">
          <div class="input-field">
            <label for="edit-nickname">Никнейм</label>
            <input
              id="edit-nickname"
              type="text"
              name="nickname"
              value="<?= htmlspecialchars($user['nickname'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              required
            >
          </div>

          <div class="input-field">
            <label for="edit-contact">Контакты</label>
            <input
              id="edit-contact"
              type="text"
              name="contact"
              value="<?= htmlspecialchars($user['contact'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            >
          </div>

          <div class="input-field">
            <label for="edit-avatar">Аватар (PNG/JPG)</label>
            <input
              id="edit-avatar"
              type="file"
              name="avatar"
              accept="image/png,image/jpeg"
            >
          </div>

          <button class="submit-btn" type="submit">Сохранить</button>
        </form>

        <div id="editProfileResult" style="margin-top:10px;"></div>
      </div>
    </div>
  <?php endif; ?>

  <footer class="lk-footer">
    <div class="lk-footer-inner">
      <span>© 2025 Fair of Contradictions / Ярмарка противоречий. Фанатский проект.</span>
    </div>
  </footer>

  <script>
    // Переключение вкладок Вход / Регистрация
    document.querySelectorAll('.auth-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.auth-tab').forEach(function (t) {
          t.classList.remove('active');
        });
        tab.classList.add('active');

        document.querySelectorAll('.auth-content').forEach(function (c) {
          c.classList.remove('active');
        });
        document.getElementById(tab.dataset.tab).classList.add('active');
      });
    });

    // Переключение секций ЛК
    document.querySelectorAll('.p-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.p-tab').forEach(function (t) {
          t.classList.remove('active');
        });
        tab.classList.add('active');

        document.querySelectorAll('.p-section').forEach(function (s) {
          s.classList.remove('active');
        });
        document.getElementById(tab.dataset.section).classList.add('active');
      });
    });

    // Фильтрация "Мои игры" по статусу и роли
    (function () {
      const gamesList   = document.getElementById('gamesList');
      if (!gamesList) return;

      const statusButtons = document.querySelectorAll('.games-toggle-btn');
      const roleSelect    = document.getElementById('roleFilter');

      let currentStatus = 'all';

      function applyFilters() {
        const roleValue = roleSelect ? roleSelect.value : 'all';
        const cards = gamesList.querySelectorAll('.game-card');

        cards.forEach(card => {
          const cardStatus = card.getAttribute('data-status'); // signed / pending / finished / cancelled
          const cardRole   = card.getAttribute('data-role') || '';

          let statusOk = true;
          if (currentStatus === 'active') {
            statusOk = (cardStatus === 'signed' || cardStatus === 'pending');
          } else if (currentStatus === 'finished') {
            statusOk = (cardStatus === 'finished');
          }

          let roleOk = true;
          if (roleValue !== 'all') {
            roleOk = (cardRole === roleValue);
          }

          if (statusOk && roleOk) {
            card.style.display = '';
          } else {
            card.style.display = 'none';
          }
        });
      }

      statusButtons.forEach(btn => {
        btn.addEventListener('click', () => {
          statusButtons.forEach(b => b.classList.remove('games-toggle-btn-active'));
          btn.classList.add('games-toggle-btn-active');
          currentStatus = btn.getAttribute('data-filter-status') || 'all';
          applyFilters();
        });
      });

      if (roleSelect) {
        roleSelect.addEventListener('change', applyFilters);
      }
    })();

    // Модальное окно редактирования профиля + AJAX
    (function () {
      const openBtn   = document.getElementById('openEditModal');
      const modal     = document.getElementById('editModal');
      const closeBtn  = document.getElementById('closeEditModal');
      const form      = document.getElementById('editProfileForm');
      const resultBox = document.getElementById('editProfileResult');

      if (!openBtn || !modal) return;

      openBtn.addEventListener('click', function () {
        modal.style.display = 'flex';
      });

      if (closeBtn) {
        closeBtn.addEventListener('click', function () {
          modal.style.display = 'none';
        });
      }

      modal.addEventListener('click', function (e) {
        if (e.target === modal) {
          modal.style.display = 'none';
        }
      });

      if (form) {
        form.addEventListener('submit', async function (e) {
          e.preventDefault();

          const formData = new FormData(form);

          try {
            const response = await fetch('update_profile_ajax.php', {
              method: 'POST',
              body: formData
            });

            const html = await response.text();
            if (resultBox) {
              resultBox.innerHTML = html;
            }

            if (html.includes('Профиль успешно обновлён') || html.includes('Успешно')) {
              setTimeout(function () {
                window.location.reload();
              }, 800);
            }
          } catch (err) {
            if (resultBox) {
              resultBox.innerHTML =
                '<div class="auth-error"><div class="auth-error-title">Ошибка</div><div class="auth-error-text">Не удалось сохранить изменения. Попробуй ещё раз.</div></div>';
            }
          }
        });
      }
    })();

    // Колокольчик и панель уведомлений
    (function () {
      const bell      = document.getElementById('notifBell');
      const panel     = document.getElementById('notifPanel');
      const badge     = document.getElementById('notifBellBadge');
      const tabMsgBtn = document.getElementById('notifTabMessagesBtn');
      const tabNotBtn = document.getElementById('notifTabNotificationsBtn');
      const tabMsg    = document.getElementById('notifMessagesTab');
      const tabNot    = document.getElementById('notifNotificationsTab');

      if (!bell || !panel) return;

      let isOpen = false;
      let markedReadOnce = false;

      function openPanel() {
        if (isOpen) return;
        panel.classList.add('notif-panel-open');
        isOpen = true;

        // Первый раз открыли — помечаем всё прочитанным
        if (!markedReadOnce) {
          markedReadOnce = true;
          fetch('notifications_mark_read.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'all=1'
          }).catch(() => {});
          document.querySelectorAll('.notif-item-unread').forEach(function (el) {
            el.classList.remove('notif-item-unread');
          });
          if (badge) {
            badge.style.display = 'none';
          }
        }
      }

      function closePanel() {
        if (!isOpen) return;
        panel.classList.remove('notif-panel-open');
        isOpen = false;
      }

      bell.addEventListener('click', function (e) {
        e.stopPropagation();
        if (isOpen) {
          closePanel();
        } else {
          openPanel();
        }
      });

      document.addEventListener('click', function (e) {
        if (!isOpen) return;
        if (!panel.contains(e.target) && e.target !== bell) {
          closePanel();
        }
      });

      function setTab(kind) {
        if (kind === 'notification') {
          tabMsgBtn.classList.remove('notif-tab-btn-active');
          tabNotBtn.classList.add('notif-tab-btn-active');
          tabMsg.style.display = 'none';
          tabNot.style.display = 'block';
        } else {
          tabNotBtn.classList.remove('notif-tab-btn-active');
          tabMsgBtn.classList.add('notif-tab-btn-active');
          tabMsg.style.display = 'block';
          tabNot.style.display = 'none';
        }
      }

      tabMsgBtn && tabMsgBtn.addEventListener('click', function () { setTab('message'); });
      tabNotBtn && tabNotBtn.addEventListener('click', function () { setTab('notification'); });
    })();
  </script>
</body>
</html>
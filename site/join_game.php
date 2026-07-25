<?php
session_start();
require __DIR__ . '/db.php';

// --- Проверка авторизации ---
if (empty($_SESSION['user_id'])) {
    $_SESSION['auth_error'] = 'Чтобы записаться на игру, войдите в аккаунт.';
    $_SESSION['auth_error_type'] = 'login';
    header('Location: account.php');
    exit;
}

$userId     = (int)$_SESSION['user_id'];
$gameId     = isset($_POST['game_id']) ? (int)$_POST['game_id'] : 0;
$postedRole = isset($_POST['role']) ? trim($_POST['role']) : '';

if ($gameId <= 0) {
    $_SESSION['settings_error'] = 'Некорректная игра для записи.';
    header('Location: account.php#games');
    exit;
}

try {
    // --- 1. Тянем игру ---
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
            WHERE ug.game_id = g.id AND ug.status = \'signed\' -- Считаем только уже принятых для лимита
          ) AS signed_count
        FROM games g
        WHERE g.id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $gameId]);
    $game = $st->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        $_SESSION['settings_error'] = 'Игра не найдена.';
        header('Location: account.php#games');
        exit;
    }

    $gameType = $game['game_type']; // case / minigame / event

    // --- 2. Тянем пользователя + флаги верификации/банов ---
    $st = $pdo->prepare('
        SELECT
          role_default,
          is_verified,
          is_email_confirmed,
          is_banned,
          ban_cases,
          ban_minigames,
          ban_events
        FROM users
        WHERE id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $userId]);
    $userRow = $st->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        $_SESSION['settings_error'] = 'Пользователь не найден. Попробуйте войти заново.';
        session_unset();
        session_destroy();
        header('Location: account.php');
        exit;
    }

    $isVerified   = (int)($userRow['is_verified'] ?? 0);
    $isEmailConfirmed = (int)($userRow['is_email_confirmed'] ?? 0);
    $isBanned     = (int)($userRow['is_banned'] ?? 0);
    $banCases     = (int)($userRow['ban_cases'] ?? 0);
    $banMinigames = (int)($userRow['ban_minigames'] ?? 0);
    $banEvents    = (int)($userRow['ban_events'] ?? 0);

    // --- 3. Проверка: ПОЧТА + АДМИН-ВЕРИФИКАЦИЯ ---
    if ($isEmailConfirmed !== 1) {
        $_SESSION['settings_error'] = 'Сначала нужно подтвердить почту в настройках аккаунта.';
        header('Location: account.php#settings');
        exit;
    }
    
    if ($isVerified !== 1) {
        $_SESSION['settings_error'] =
            'Твой аккаунт ещё не проверен администратором. '
            . 'Свяжись с админом в Discord для подтверждения доступа к играм.';
        header('Location: account.php#games');
        exit;
    }

    // --- 4. Глобальный бан ---
    if ($isBanned === 1) {
        $_SESSION['settings_error'] =
            'Твой аккаунт заблокирован. Участие в играх запрещено.';
        header('Location: account.php#games');
        exit;
    }

    // --- 5. Бан по типу игры ---
    if ($gameType === 'case' && $banCases === 1) {
        $_SESSION['settings_error'] = 'На твой аккаунт установлен запрет записи на кейсы.';
        header('Location: account.php#games');
        exit;
    }

    if ($gameType === 'minigame' && $banMinigames === 1) {
        $_SESSION['settings_error'] = 'На твой аккаунт установлен запрет записи на мини-игры.';
        header('Location: account.php#games');
        exit;
    }

    if ($gameType === 'event' && $banEvents === 1) {
        $_SESSION['settings_error'] = 'На твой аккаунт установлен запрет записи на ивенты.';
        header('Location: account.php#games');
        exit;
    }

    // --- 6. Проверка набора ---
    if ((int)$game['signups_open'] !== 1 || !in_array($game['status'], ['upcoming', 'active'], true)) {
        $_SESSION['settings_error'] = 'Набор на эту игру закрыт.';
        header('Location: account.php#games');
        exit;
    }

    // --- 7. Проверка лимита игроков ---
    // ВАЖНО: Мы проверяем лимит только по 'signed' (принятым). 
    // Но можно проверять и (signed + pending), чтобы не набралось миллион заявок.
    // Пока оставим проверку только по уже принятым, чтобы заявки можно было кидать сверх лимита (очередь).
    $maxPlayers  = $game['max_players'] !== null ? (int)$game['max_players'] : null;
    $signedCount = (int)$game['signed_count'];

    if ($maxPlayers !== null && $signedCount >= $maxPlayers) {
        $_SESSION['settings_error'] = 'Места в основном составе закончились (лимит достигнут).';
        header('Location: account.php#games');
        exit;
    }

    // --- 8. Роль ---
    $roleDefault = !empty($userRow['role_default']) ? $userRow['role_default'] : 'Игрок';
    $finalRole = 'Игрок';

    if ($gameType === 'case') {
        $allowedRoles = ['Адвокат', 'Прокурор', 'Судья', 'Присяжный', 'Следователь', 'Свидетель', 'Игрок'];
        if ($postedRole !== '' && in_array($postedRole, $allowedRoles, true)) {
            $finalRole = $postedRole;
        } else {
            $finalRole = $roleDefault !== '' ? $roleDefault : 'Игрок';
        }
    }

    // --- 9. Запись (или обновление) ---
    $st = $pdo->prepare('SELECT id, status FROM user_games WHERE user_id = :uid AND game_id = :gid LIMIT 1');
    $st->execute(['uid' => $userId, 'gid' => $gameId]);
    $ug = $st->fetch(PDO::FETCH_ASSOC);

    // !!! ГЛАВНОЕ ИЗМЕНЕНИЕ: Статус теперь всегда 'pending' при подаче заявки !!!
    $newStatus = 'pending';

    if ($ug) {
        // Если уже записан и принят — не трогаем, просто обновляем роль
        if ($ug['status'] === 'signed') {
             $_SESSION['settings_success'] = 'Вы уже приняты в эту игру. Роль обновлена.';
             // Не меняем статус, только роль
             $st = $pdo->prepare('UPDATE user_games SET role = :role WHERE id = :id');
             $st->execute(['role' => $finalRole, 'id' => $ug['id']]);
        } else {
             // Если был отменен или уже в ожидании — обновляем на pending
             $st = $pdo->prepare('UPDATE user_games SET status = :status, role = :role WHERE id = :id');
             $st->execute([
                 'status' => $newStatus,
                 'role'   => $finalRole,
                 'id'     => $ug['id'],
             ]);
             $_SESSION['settings_success'] = 'Заявка на участие отправлена ведущему.';
        }
    } else {
        // Новая запись
        $st = $pdo->prepare('
            INSERT INTO user_games (user_id, game_id, role, status)
            VALUES (:uid, :gid, :role, :status)
        ');
        $st->execute([
            'uid'    => $userId,
            'gid'    => $gameId,
            'role'   => $finalRole,
            'status' => $newStatus,
        ]);
        
        $_SESSION['settings_success'] = 
            'Заявка на участие в игре «' . $game['title'] . '» успешно отправлена. ' .
            'Ожидайте подтверждения от ведущего.';
    }

} catch (PDOException $e) {
    $_SESSION['settings_error'] = 'Не удалось записаться на игру. Попробуйте позже.';
}

header('Location: account.php#games');
exit;
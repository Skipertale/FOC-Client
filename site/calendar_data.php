<?php
session_start();
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

// ---- Параметры года/месяца ----
$year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}
if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}

// начало и конец месяца
$start = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month));
$end   = clone $start;
$end->modify('last day of this month')->setTime(23, 59, 59);

$gamesByDay = [];

try {
    // --- 1. Все даты из game_dates в этом месяце ---
    $sqlDates = '
        SELECT
          g.id              AS game_id,
          g.title,
          g.description,
          g.game_type,
          g.status,
          g.external_link,
          g.max_players,
          g.signups_open,
          d.starts_at       AS occurs_at,
          (
            SELECT COUNT(*)
            FROM user_games ug
            WHERE ug.game_id = g.id AND ug.status IN (\'signed\', \'pending\')
          ) AS signed_count
        FROM game_dates d
        JOIN games g ON g.id = d.game_id
        WHERE d.starts_at BETWEEN :start AND :end
        ORDER BY d.starts_at ASC
    ';

    $stmt1 = $pdo->prepare($sqlDates);
    $stmt1->execute([
        'start' => $start->format('Y-m-d H:i:s'),
        'end'   => $end->format('Y-m-d H:i:s'),
    ]);
    $rows1 = $stmt1->fetchAll();

    foreach ($rows1 as $g) {
        if (empty($g['occurs_at'])) {
            continue;
        }
        $dt  = new DateTime($g['occurs_at']);
        $day = (int)$dt->format('j');

        $gamesByDay[$day][] = [
            'id'           => (int)$g['game_id'],
            'title'        => $g['title'],
            'description'  => $g['description'],
            'game_type'    => $g['game_type'],
            'status'       => $g['status'],
            'starts_at'    => $dt->format('Y-m-d H:i'),
            'external_link'=> $g['external_link'],
            'max_players'  => $g['max_players'] !== null ? (int)$g['max_players'] : null,
            'signed_count' => (int)($g['signed_count'] ?? 0),
            'signups_open' => (int)$g['signups_open'] === 1,
        ];
    }

    // --- 2. Игры без записей в game_dates, но с заполненным games.starts_at в этом месяце ---
    $sqlNoDates = '
        SELECT
          g.id              AS game_id,
          g.title,
          g.description,
          g.game_type,
          g.status,
          g.external_link,
          g.max_players,
          g.signups_open,
          g.starts_at       AS occurs_at,
          (
            SELECT COUNT(*)
            FROM user_games ug
            WHERE ug.game_id = g.id AND ug.status IN (\'signed\', \'pending\')
          ) AS signed_count
        FROM games g
        WHERE g.starts_at BETWEEN :start AND :end
          AND NOT EXISTS (
            SELECT 1 FROM game_dates gd WHERE gd.game_id = g.id
          )
        ORDER BY g.starts_at ASC
    ';

    $stmt2 = $pdo->prepare($sqlNoDates);
    $stmt2->execute([
        'start' => $start->format('Y-m-d H:i:s'),
        'end'   => $end->format('Y-m-d H:i:s'),
    ]);
    $rows2 = $stmt2->fetchAll();

    foreach ($rows2 as $g) {
        if (empty($g['occurs_at'])) {
            continue;
        }
        $dt  = new DateTime($g['occurs_at']);
        $day = (int)$dt->format('j');

        $gamesByDay[$day][] = [
            'id'           => (int)$g['game_id'],
            'title'        => $g['title'],
            'description'  => $g['description'],
            'game_type'    => $g['game_type'],
            'status'       => $g['status'],
            'starts_at'    => $dt->format('Y-m-d H:i'),
            'external_link'=> $g['external_link'],
            'max_players'  => $g['max_players'] !== null ? (int)$g['max_players'] : null,
            'signed_count' => (int)($g['signed_count'] ?? 0),
            'signups_open' => (int)$g['signups_open'] === 1,
        ];
    }

    // Можно при желании отсортировать игры внутри дня по времени
    foreach ($gamesByDay as $day => &$list) {
        usort($list, function ($a, $b) {
            return strcmp($a['starts_at'], $b['starts_at']);
        });
    }
    unset($list);

    echo json_encode([
        'year'  => $year,
        'month' => $month,
        'games' => $gamesByDay,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'DB error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

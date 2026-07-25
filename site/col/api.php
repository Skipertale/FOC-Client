<?php
// api.php - TOTAL COMPLETED VERSION WITH QUEST SYSTEM & MAKER SUPPORT
session_start();

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['status' => 'error', 'message' => 'Not authenticated'], 401);
    }
}

function requireAdmin() {
    if (!isset($_SESSION['access_level']) || $_SESSION['access_level'] < 5) {
        jsonResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
    }
}

function getActiveQuestId($pdo) {
    $stmt = $pdo->query("SELECT id FROM quest_projects WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : 0;
}


require_once 'config/db.php';
require_once 'config/knowledge_base.php';
header('Content-Type: application/json');

// Функция записи лога
function writeLog($pdo, $type, $desc, $targetId = null) {
    $userId = $_SESSION['user_id'] ?? 0;
    $sql = "INSERT INTO logs (user_id, action_type, description, target_id, ip_address) VALUES (?, ?, ?, ?, ?)";
    $pdo->prepare($sql)->execute([$userId, $type, $desc, $targetId, $_SERVER['REMOTE_ADDR']]);
}

// 0. LOGIN
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $pin = $_POST['pin'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE pin = ? LIMIT 1");
    $stmt->execute([$pin]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['access_level'] = $user['access_level'] ?? 0;
        echo json_encode(['status' => 'success', 'user' => $user]);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Неверный PIN']);
    }
    exit;
}

// 1. STATUS CHECK
if (isset($_GET['action']) && $_GET['action'] === 'check_status') {
    if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'guest']); exit; }
    $stmt = $pdo->prepare("SELECT access_level FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $lvl = $stmt->fetchColumn();
    if ($lvl == 0) { session_destroy(); echo json_encode(['status' => 'banned']); } 
    else { echo json_encode(['status' => 'ok']); }
    exit;
}

// --- KNOWLEDGE BASE (PUBLIC LISTS) ---
if (isset($_GET['action']) && $_GET['action'] === 'kb_list_rules') {
    requireLogin();
    kbEnsureSchema($pdo);
    $stmt = $pdo->query("SELECT id, title, category, sort_order, body_html FROM kb_rules WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['body_html'] = kbSanitizeHtml((string)$r['body_html']); }
    jsonResponse(['status' => 'success', 'rules' => $rows]);
}

if (isset($_GET['action']) && $_GET['action'] === 'kb_list_abilities') {
    requireLogin();
    kbEnsureSchema($pdo);
    $stmt = $pdo->query("SELECT id, name, ability_type, cost, cooldown, tags, sort_order, description_html FROM kb_abilities WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['description_html'] = kbSanitizeHtml((string)$r['description_html']); }
    jsonResponse(['status' => 'success', 'abilities' => $rows]);
}

// --- KNOWLEDGE BASE (ADMIN LISTS) ---
if (isset($_GET['action']) && $_GET['action'] === 'kb_list_rules_admin') {
    requireAdmin();
    kbEnsureSchema($pdo);
    $stmt = $pdo->query("SELECT id, title, category, sort_order, is_active, body_html FROM kb_rules ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['status' => 'success', 'rules' => $rows]);
}

if (isset($_GET['action']) && $_GET['action'] === 'kb_list_abilities_admin') {
    requireAdmin();
    kbEnsureSchema($pdo);
    $stmt = $pdo->query("SELECT id, name, ability_type, cost, cooldown, tags, sort_order, is_active, description_html FROM kb_abilities ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['status' => 'success', 'abilities' => $rows]);
}

// --- АКТИВНЫЙ КВЕСТ (ДЛЯ ЯРЛЫКА "КВЕСТ") ---
if (isset($_GET['action']) && $_GET['action'] === 'get_active_quest') {
    requireLogin();
    $qid = getActiveQuestId($pdo);
    if (!$qid) jsonResponse(['status' => 'no_active']);

    $stmt = $pdo->prepare("SELECT id, title FROM quest_projects WHERE id = ? LIMIT 1");
    $stmt->execute([$qid]);
    $proj = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT scene_key FROM quests WHERE quest_id = ? AND is_start = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$qid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) jsonResponse(['status' => 'no_start', 'quest_id' => $qid, 'title' => $proj ? $proj['title'] : '']);
    jsonResponse(['status' => 'ok', 'quest_id' => $qid, 'title' => $proj ? $proj['title'] : '', 'start_key' => $row['scene_key']]);
}

// --- ПОЛУЧЕНИЕ СЦЕНЫ КВЕСТА (ДЛЯ ПЛЕЕРА) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_quest_scene') {
    requireLogin();

    $key = $_GET['key'] ?? 'start';
    $pid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
    if (!$pid) $pid = getActiveQuestId($pdo);
    if (!$pid) jsonResponse(['status' => 'no_active']);

    // Если пришёл id вместо key
    if (isset($_GET['by']) && $_GET['by'] === 'id') {
        $stmt = $pdo->prepare("SELECT * FROM quests WHERE quest_id = ? AND id = ? LIMIT 1");
        $stmt->execute([$pid, (int)$key]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM quests WHERE quest_id = ? AND scene_key = ? LIMIT 1");
        $stmt->execute([$pid, $key]);
    }

    $scene = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$scene) jsonResponse(['status' => 'error', 'message' => 'Scene not found']);

    // Авто-next (если choices пустые)
    $stmt = $pdo->prepare("SELECT scene_key FROM quests WHERE quest_id = ? AND id > ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$pid, (int)$scene['id']]);
    $next = $stmt->fetch(PDO::FETCH_ASSOC);
    $scene['next_scene_key'] = $next ? $next['scene_key'] : null;

    jsonResponse(['status' => 'success', 'data' => $scene]);
}
// --- ПОЛУЧЕНИЕ ВСЕХ СЦЕН (ДЛЯ МЕЙКЕРА В АДМИНКЕ) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_all_quest_scenes') {
    if (!isset($_SESSION['access_level']) || $_SESSION['access_level'] < 5) {
        echo json_encode(['status' => 'error', 'msg' => 'Access Denied']); exit;
    }
    $stmt = $pdo->query("SELECT * FROM quests ORDER BY id DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ПРОВЕРКА АВТОРИЗАЦИИ ДЛЯ ВСЕХ ОСТАЛЬНЫХ ДЕЙСТВИЙ (POST)
if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error', 'msg' => 'AUTH REQUIRED']); exit; }
$currentUserId = $_SESSION['user_id'];
$currentUserLvl = $_SESSION['access_level'] ?? 0;
$adminName = $pdo->query("SELECT username FROM users WHERE id=$currentUserId")->fetchColumn();


// --- ПРОЕКТЫ КВЕСТОВ (ДЛЯ QUEST_MAKER) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_quest_projects') {
    requireAdmin();
    jsonResponse($pdo->query("SELECT * FROM quest_projects ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC));
}

if (isset($_GET['action']) && $_GET['action'] === 'get_quest_scenes_by_id') {
    requireAdmin();
    $pid = (int)($_GET['pid'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM quests WHERE quest_id = ? ORDER BY id ASC");
    $stmt->execute([$pid]);
    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- KNOWLEDGE BASE (ADMIN CRUD) ---
    if ($action === 'kb_save_rule') {
        requireAdmin();
        kbEnsureSchema($pdo);
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $sort = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        $body = (string)($_POST['body_html'] ?? '');
        if ($title === '') { jsonResponse(['status'=>'error','message'=>'Missing title'], 400); }

        if ($id > 0) {
            $st = $pdo->prepare("UPDATE kb_rules SET title=?, category=?, sort_order=?, is_active=?, body_html=? WHERE id=?");
            $st->execute([$title, ($category===''?null:$category), $sort, $active, $body, $id]);
            writeLog($pdo, 'KB_RULE_EDIT', "Правило обновлено: #$id $title", $id);
            jsonResponse(['status'=>'success','id'=>$id]);
        } else {
            $st = $pdo->prepare("INSERT INTO kb_rules (title, category, sort_order, is_active, body_html) VALUES (?,?,?,?,?)");
            $st->execute([$title, ($category===''?null:$category), $sort, $active, $body]);
            $newId = (int)$pdo->lastInsertId();
            writeLog($pdo, 'KB_RULE_CREATE', "Правило создано: #$newId $title", $newId);
            jsonResponse(['status'=>'success','id'=>$newId]);
        }
    }

    if ($action === 'kb_delete_rule') {
        requireAdmin();
        kbEnsureSchema($pdo);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { jsonResponse(['status'=>'error','message'=>'Missing id'], 400); }
        $title = $pdo->prepare("SELECT title FROM kb_rules WHERE id=?");
        $title->execute([$id]);
        $t = (string)($title->fetchColumn() ?? '');
        $pdo->prepare("DELETE FROM kb_rules WHERE id=?")->execute([$id]);
        writeLog($pdo, 'KB_RULE_DELETE', "Правило удалено: #$id $t", $id);
        jsonResponse(['status'=>'success']);
    }

    if ($action === 'kb_save_ability') {
        requireAdmin();
        kbEnsureSchema($pdo);
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $type = trim((string)($_POST['ability_type'] ?? ''));
        $cost = trim((string)($_POST['cost'] ?? ''));
        $cd = trim((string)($_POST['cooldown'] ?? ''));
        $tags = trim((string)($_POST['tags'] ?? ''));
        $sort = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        $desc = (string)($_POST['description_html'] ?? '');
        if ($name === '') { jsonResponse(['status'=>'error','message'=>'Missing name'], 400); }

        if ($id > 0) {
            $st = $pdo->prepare("UPDATE kb_abilities SET name=?, ability_type=?, cost=?, cooldown=?, tags=?, sort_order=?, is_active=?, description_html=? WHERE id=?");
            $st->execute([$name, ($type===''?null:$type), ($cost===''?null:$cost), ($cd===''?null:$cd), ($tags===''?null:$tags), $sort, $active, $desc, $id]);
            writeLog($pdo, 'KB_ABILITY_EDIT', "Способность обновлена: #$id $name", $id);
            jsonResponse(['status'=>'success','id'=>$id]);
        } else {
            $st = $pdo->prepare("INSERT INTO kb_abilities (name, ability_type, cost, cooldown, tags, sort_order, is_active, description_html) VALUES (?,?,?,?,?,?,?,?)");
            $st->execute([$name, ($type===''?null:$type), ($cost===''?null:$cost), ($cd===''?null:$cd), ($tags===''?null:$tags), $sort, $active, $desc]);
            $newId = (int)$pdo->lastInsertId();
            writeLog($pdo, 'KB_ABILITY_CREATE', "Способность создана: #$newId $name", $newId);
            jsonResponse(['status'=>'success','id'=>$newId]);
        }
    }

    if ($action === 'kb_delete_ability') {
        requireAdmin();
        kbEnsureSchema($pdo);
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { jsonResponse(['status'=>'error','message'=>'Missing id'], 400); }
        $name = $pdo->prepare("SELECT name FROM kb_abilities WHERE id=?");
        $name->execute([$id]);
        $n = (string)($name->fetchColumn() ?? '');
        $pdo->prepare("DELETE FROM kb_abilities WHERE id=?")->execute([$id]);
        writeLog($pdo, 'KB_ABILITY_DELETE', "Способность удалена: #$id $n", $id);
        jsonResponse(['status'=>'success']);
    }

    // 2. SET PIN
    if ($action === 'set_pin') {
        if (strlen($_POST['pin']) < 4) { echo json_encode(['status'=>'error']); exit; }
        $pdo->prepare("UPDATE dossier SET admin_pin = ? WHERE user_id = ?")->execute([$_POST['pin'], $currentUserId]);
        echo json_encode(['status' => 'success']); exit;
    }

    // 3. CHECK PIN
    if ($action === 'check_pin') {
        $stmt = $pdo->prepare("SELECT admin_pin FROM dossier WHERE user_id = ?");
        $stmt->execute([$currentUserId]);
        $real = $stmt->fetchColumn();
        echo json_encode(['status' => ($real === $_POST['pin']) ? 'success' : 'error']); exit;
    }

    // 4. SAVE SELF PROFILE
    if ($action === 'save_self_profile') {
        $tid = (int)$_POST['target_id'];
        if ($currentUserId != $tid && $currentUserLvl < 5) { echo json_encode(['status'=>'error']); exit; }
        $pdo->prepare("UPDATE dossier SET fav_abilities=?, quote=?, extra_info=?, fav_char=? WHERE user_id=?")
            ->execute([$_POST['fav_abilities'], $_POST['quote'], $_POST['extra_info'], $_POST['fav_char'], $tid]);
        echo json_encode(['status' => 'success']); exit;
    }

    // 5. SAVE USER (ADMIN) - С ДЕТАЛЬНЫМ ЛОГИРОВАНИЕМ
    if ($action === 'save_user') {
        if ($currentUserLvl < 5) { echo json_encode(['status'=>'error']); exit; }
        $tid = (int)$_POST['target_id'];
        $old = $pdo->query("SELECT * FROM dossier WHERE user_id=$tid")->fetch(PDO::FETCH_ASSOC);
        
        $fields = ['title', 'rating', 'active_abilities', 'def_wins', 'def_losses', 'pros_wins', 'pros_losses', 'co_wins', 'co_losses', 'wit_wins', 'wit_losses', 'judge_g', 'judge_ng', 'detective_count', 'fav_char', 'fav_abilities', 'quote', 'extra_info'];
        $diff = [];
        foreach($fields as $f) {
            $newV = $_POST[$f] ?? '';
            if(isset($_POST[$f]) && trim((string)$old[$f]) !== trim((string)$newV)) {
                $diff[] = "[$f]: '" . ($old[$f] ?? '0') . "' -> '$newV'";
            }
        }
        
        $pdo->prepare("UPDATE dossier SET title=?, rating=?, active_abilities=?, def_wins=?, def_losses=?, pros_wins=?, pros_losses=?, co_wins=?, co_losses=?, wit_wins=?, wit_losses=?, judge_g=?, judge_ng=?, detective_count=?, fav_char=?, fav_abilities=?, quote=?, extra_info=? WHERE user_id=?")
            ->execute([$_POST['title'], $_POST['rating'], $_POST['active_abilities'], $_POST['def_wins'], $_POST['def_losses'], $_POST['pros_wins'], $_POST['pros_losses'], $_POST['co_wins'], $_POST['co_losses'], $_POST['wit_wins'], $_POST['wit_losses'], $_POST['judge_g'], $_POST['judge_ng'], $_POST['detective_count'], $_POST['fav_char'], $_POST['fav_abilities'], $_POST['quote'], $_POST['extra_info'], $tid]);
        
        writeLog($pdo, 'ADMIN_EDIT', empty($diff) ? "Без изменений" : implode(" || ", $diff), $tid);
        echo json_encode(['status' => 'success']); exit;
    }

    // --- СОХРАНЕНИЕ СЦЕНЫ КВЕСТА (ДЛЯ МЕЙКЕРА) ---
    if ($action === 'save_quest_scene') {
        if ($currentUserLvl < 5) exit;

        $quest_id = (int)($_POST['quest_id'] ?? 0);
        $key = trim($_POST['scene_key'] ?? '');
        if (!$quest_id || $key === '') { echo json_encode(['status'=>'error','message'=>'Missing quest_id or scene_key']); exit; }

        $name = $_POST['char_name'] ?? '';
        $text = $_POST['dialogue_text'] ?? '';
        $bg = $_POST['bg_url'] ?? '';
        $sprite = $_POST['sprite_url'] ?? '';
        $music = $_POST['music_url'] ?? '';
        $choices = $_POST['choices'] ?? '[]';
        $is_start = isset($_POST['is_start']) ? 1 : 0;

        if ($is_start) {
            $st = $pdo->prepare("UPDATE quests SET is_start = 0 WHERE quest_id = ?");
            $st->execute([$quest_id]);
        }

        // Upsert по (quest_id, scene_key) без требований к UNIQUE в БД
        $st = $pdo->prepare("SELECT id FROM quests WHERE quest_id = ? AND scene_key = ? LIMIT 1");
        $st->execute([$quest_id, $key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $sql = "UPDATE quests SET char_name=?, dialogue_text=?, bg_url=?, sprite_url=?, music_url=?, choices=?, is_start=? WHERE id=?";
            $pdo->prepare($sql)->execute([$name, $text, $bg, $sprite, $music, $choices, $is_start, (int)$row['id']]);
        } else {
            $sql = "INSERT INTO quests (quest_id, scene_key, char_name, dialogue_text, bg_url, sprite_url, music_url, choices, is_start) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$quest_id, $key, $name, $text, $bg, $sprite, $music, $choices, $is_start]);
        }

        writeLog($pdo, 'QUEST_EDIT', "Обновлена сцена квеста [$quest_id]::$key");
        echo json_encode(['status' => 'success']); exit;
    }

// 6. UPDATE ACCESS
    if ($action === 'update_access') {
        if ($currentUserLvl < 5) exit;
        $lvl = (int)$_POST['access_level']; $uid = (int)$_POST['target_id']; $reason = $_POST['ban_reason'] ?? '';
        $pdo->prepare("UPDATE users SET access_level = ? WHERE id = ?")->execute([$lvl, $uid]);
        if ($lvl == 0) $pdo->prepare("UPDATE dossier SET ban_reason = ?, banned_by = ? WHERE user_id = ?")->execute([$reason, $adminName, $uid]);
        else $pdo->prepare("UPDATE dossier SET ban_reason = NULL, banned_by = NULL WHERE user_id = ?")->execute([$uid]);
        writeLog($pdo, 'ACCESS', "New Level: $lvl | Reason: $reason", $uid);
        echo json_encode(['status' => 'success']); exit;
    }

    // 7. CREATE GAME
    if ($action === 'create_game') {
        $mode = $_POST['mode']; $format = $_POST['format'];
        $ults = (isset($_POST['ultimates']) && $_POST['ultimates'] === 'true') ? 1 : 0; 
        $bans = ($mode === 'ranked' || (isset($_POST['bans']) && $_POST['bans'] === 'true')) ? 1 : 0;
        $sql = "INSERT INTO games (creator_id, judge_id, mode, format, ultimates, bans, created_at, status, def_str, def_cd, pros_str, pros_cd, def2_str, def2_cd, pros2_str, pros2_cd) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'preparation', 1, 1, 1, 1, 1, 1, 1, 1)";
        $pdo->prepare($sql)->execute([$currentUserId, $currentUserId, $mode, $format, $ults, $bans]);
        echo json_encode(['status' => 'success', 'game_id' => $pdo->lastInsertId()]); exit;
    }

    // 8. JOIN SLOT
    if ($action === 'join_slot') {
        $gid = (int)$_POST['game_id']; $slot = $_POST['slot'];
        $sqlClean = "UPDATE games SET def_id=NULLIF(def_id,?), def2_id=NULLIF(def2_id,?), pros_id=NULLIF(pros_id,?), pros2_id=NULLIF(pros2_id,?), wit_id=NULLIF(wit_id,?), det_id=NULLIF(det_id,?) WHERE id=?";
        $pdo->prepare($sqlClean)->execute([$currentUserId,$currentUserId,$currentUserId,$currentUserId,$currentUserId,$currentUserId,$gid]);
        $pdo->prepare("UPDATE games SET $slot = ? WHERE id = ?")->execute([$currentUserId, $gid]);
        echo json_encode(['status' => 'success']); exit;
    }

    // 9. LEAVE SLOT
    if ($action === 'leave_slot') {
        $gid = (int)$_POST['game_id'];
        $sqlClean = "UPDATE games SET def_id=NULLIF(def_id,?), def2_id=NULLIF(def2_id,?), pros_id=NULLIF(pros_id,?), pros2_id=NULLIF(pros2_id,?), wit_id=NULLIF(wit_id,?), det_id=NULLIF(det_id,?) WHERE id=?";
        $pdo->prepare($sqlClean)->execute([$currentUserId,$currentUserId,$currentUserId,$currentUserId,$currentUserId,$currentUserId,$gid]);
        echo json_encode(['status' => 'success']); exit;
    }

    // 10. KICK SLOT
    if ($action === 'kick_slot') {
        $gid = (int)$_POST['game_id']; $slot = $_POST['slot'];
        $pdo->prepare("UPDATE games SET $slot = NULL WHERE id = ?")->execute([$gid]);
        echo json_encode(['status' => 'success']); exit;
    }

    // 11. CHANGE GAME STATUS
    if ($action === 'change_game_status') {
        $gid = (int)$_POST['game_id']; $status = $_POST['status'];
        $pdo->prepare("UPDATE games SET status = ? WHERE id = ?")->execute([$status, $gid]);
        echo json_encode(['status' => 'success']); exit;
    }

    // 12. DELETE GAME
    if ($action === 'delete_game') {
        $gid = (int)$_POST['game_id'];
        $pdo->prepare("DELETE FROM games WHERE id = ?")->execute([$gid]);
        writeLog($pdo, 'GAME_DELETE', "Game #$gid removed", $gid);
        echo json_encode(['status' => 'success']); exit;
    }

    // 13. UPDATE LOBBY SKILLS
    if ($action === 'update_lobby_skills') {
        $gid = (int)$_POST['game_id']; $role = $_POST['role']; $text = $_POST['abilities'];
        $map = ['def'=>'def_abilities','def2'=>'def2_abilities','pros'=>'pros_abilities','pros2'=>'pros2_abilities'];
        if(isset($map[$role])) {
            $col = $map[$role];
            $pdo->prepare("UPDATE games SET $col = ? WHERE id = ?")->execute([$text, $gid]);
        }
        echo json_encode(['status' => 'success']); exit;
    }

    // 14. UPDATE LOBBY STAT
    if ($action === 'update_lobby_stat') {
        $gid = (int)$_POST['game_id']; $col = $_POST['col']; $val = (int)$_POST['val'];
        $oldV = $pdo->query("SELECT $col FROM games WHERE id=$gid")->fetchColumn();
        $pdo->prepare("UPDATE games SET $col = ? WHERE id = ?")->execute([$val, $gid]);
        writeLog($pdo, 'GAME_STAT', "LOBBY #$gid | $col: $oldV -> $val", $gid);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_quest_project') {
        $pdo->prepare("INSERT INTO quest_projects (title) VALUES (?)")->execute([$_POST['title']]);
        echo json_encode(['status'=>'success']); exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggle_quest_status') {
        $pdo->prepare("UPDATE quest_projects SET is_active = ? WHERE id = ?")->execute([$_POST['status'], $_POST['id']]);
        echo json_encode(['status'=>'success']); exit;
    }
}
?>
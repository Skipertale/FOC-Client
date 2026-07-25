<?php
// quest_api.php — stable API using existing tables only: quest_projects + quests (+ quest_project_meta, quest_scene_meta if present)
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

function out(array $obj, int $code = 200): void {
    if (!array_key_exists('status', $obj) && array_key_exists('success', $obj)) {
        $obj['status'] = $obj['success'] ? 'success' : 'error';
    }
    http_response_code($code);
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function is_admin(): bool {
    return isset($_SESSION['access_level']) && (int)$_SESSION['access_level'] >= 5;
}

function req_int(string $k, int $d=0): int {
    $v = $_POST[$k] ?? $_GET[$k] ?? null;
    if ($v === null || $v === '') return $d;
    return (int)$v;
}
function req_str(string $k, string $d=''): string {
    $v = $_POST[$k] ?? $_GET[$k] ?? null;
    if ($v === null) return $d;
    return (string)$v;
}

function table_cols(PDO $pdo, string $table): array {
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}
function has_col(array $cols, string $name): bool {
    return in_array($name, $cols, true);
}

// Always respond JSON, even on fatal-like errors
set_exception_handler(function(Throwable $e){
    out(['success'=>false,'error'=>'exception','message'=>$e->getMessage()], 500);
});

$action = req_str('action', '');
if ($action === '') out(['success'=>false,'error'=>'no_action'], 400);

// --- READ endpoints (auth optional unless you want to lock it down) ---
if ($action === 'get_quest_projects') {
    $st = $pdo->query("SELECT id, title, is_active, created_at FROM quest_projects ORDER BY id DESC");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // keep legacy format: array (your admin_tools already умеет)
    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'get_quest_scenes_by_id') {
    $pid = req_int('id', 0);
    if ($pid <= 0) $pid = req_int('pid', 0);
    if ($pid <= 0) $pid = req_int('quest_id', 0);
    if ($pid <= 0) out(['success'=>false,'error'=>'bad_project_id'], 400);

    $st = $pdo->prepare("SELECT * FROM quests WHERE (quest_id = ? OR scene_key LIKE CONCAT('q', ?, '::%')) ORDER BY id ASC");
    $st->execute([$pid, $pid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // normalize legacy keys: q<pid>::key -> key (UI and player use plain keys)
    foreach ($rows as &$r) {
        if (isset($r['scene_key']) && preg_match('/^q'.$pid.'::(.+)$/', (string)$r['scene_key'], $m)) {
            $r['scene_key'] = $m[1];
        }
    }
    unset($r);

    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'get_quest_scene') {
    // Player endpoint
    $previewProject = req_int('preview_project', 0);
    $previewKey = trim(req_str('preview_scene', ''));
    $project = null;

    if ($previewProject > 0) {
        $st = $pdo->prepare("SELECT id, title, is_active FROM quest_projects WHERE id=? LIMIT 1");
        $st->execute([$previewProject]);
        $project = $st->fetch(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->query("SELECT id, title, is_active FROM quest_projects WHERE is_active=1 ORDER BY id DESC LIMIT 1");
        $project = $st->fetch(PDO::FETCH_ASSOC);
    }

    if (!$project) out(['success'=>false,'error'=>'no_active_project'], 200);

    $pid = (int)$project['id'];
    $scene = null;

    // normalize preview key legacy prefix
    if ($previewKey !== '' && preg_match('/^q'.$pid.'::(.+)$/', $previewKey, $m)) {
        $previewKey = $m[1];
    }

    if ($previewKey !== '') {
        $st = $pdo->prepare("SELECT * FROM quests WHERE quest_id=? AND scene_key=? ORDER BY id ASC LIMIT 1");
        $st->execute([$pid, $previewKey]);
        $scene = $st->fetch(PDO::FETCH_ASSOC);
        if (!$scene) {
            // legacy fallback
            $st = $pdo->prepare("SELECT * FROM quests WHERE scene_key=? ORDER BY id ASC LIMIT 1");
            $st->execute(['q'.$pid.'::'.$previewKey]);
            $scene = $st->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        $st = $pdo->prepare("SELECT * FROM quests WHERE quest_id=? AND is_start=1 ORDER BY id ASC LIMIT 1");
        $st->execute([$pid]);
        $scene = $st->fetch(PDO::FETCH_ASSOC);

        if (!$scene) {
            // first scene by id
            $st = $pdo->prepare("SELECT * FROM quests WHERE quest_id=? ORDER BY id ASC LIMIT 1");
            $st->execute([$pid]);
            $scene = $st->fetch(PDO::FETCH_ASSOC);
        }
        if (!$scene) {
            // legacy fallback start-like keys
            $st = $pdo->prepare("SELECT * FROM quests WHERE scene_key IN (?,?) ORDER BY id ASC LIMIT 1");
            $st->execute(['q'.$pid.'::start', 'start']);
            $scene = $st->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$scene) out(['success'=>false,'error'=>'no_scene_in_project'], 200);

    // normalize legacy key in response
    if (isset($scene['scene_key']) && preg_match('/^q'.$pid.'::(.+)$/', (string)$scene['scene_key'], $m)) {
        $scene['scene_key'] = $m[1];
    }

    // compute next scene_key (by id) within this project
    $nextKey = null;
    $st = $pdo->prepare("SELECT scene_key, quest_id FROM quests WHERE (quest_id=? OR scene_key LIKE CONCAT('q', ?, '::%')) AND id>? ORDER BY id ASC LIMIT 1");
    $st->execute([$pid, $pid, (int)$scene['id']]);
    $rowNext = $st->fetch(PDO::FETCH_ASSOC);
    $nk = $rowNext ? $rowNext['scene_key'] : null;
    if ($nk) {
        $nk = (string)$nk;
        if (preg_match('/^q'.$pid.'::(.+)$/', $nk, $m)) $nk = $m[1];
        $nextKey = $nk;
    }

    out(['success'=>true,'project'=>$project,'scene'=>$scene,'next_scene_key'=>$nextKey], 200);
}

// --- WRITE endpoints (admin only) ---
if (!is_admin()) {
    out(['success'=>false,'error'=>'forbidden'], 403);
}

if ($action === 'toggle_quest_status') {
    $id = req_int('id', 0);
    if ($id <= 0) out(['success'=>false,'error'=>'bad_id'], 400);
    $status = req_int('status', 0) ? 1 : 0;
    $st = $pdo->prepare("UPDATE quest_projects SET is_active=? WHERE id=?");
    $st->execute([$status, $id]);
    out(['success'=>true]);
}

if ($action === 'save_quest_project') {
    $id = req_int('id', 0);
    $title = trim(req_str('title', ''));
    $desc  = req_str('description', '');
    if ($title === '') out(['success'=>false,'error'=>'no_title'], 400);

    if ($id > 0) {
        $st = $pdo->prepare("UPDATE quest_projects SET title=?, description=? WHERE id=?");
        $st->execute([$title, $desc, $id]);
        out(['success'=>true,'id'=>$id]);
    } else {
        $st = $pdo->prepare("INSERT INTO quest_projects (title, description) VALUES (?,?)");
        $st->execute([$title, $desc]);
        out(['success'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }
}

if ($action === 'delete_quest_project' || $action === 'delete_project' || $action === 'remove_quest_project') {
    $pid = req_int('id', 0);
    if ($pid <= 0) $pid = req_int('project_id', 0);
    if ($pid <= 0) out(['success'=>false,'error'=>'bad_id'], 400);

    $pdo->beginTransaction();
    try {
        // delete scenes in project (and legacy namespaced)
        $st = $pdo->prepare("DELETE FROM quests WHERE (quest_id=? OR scene_key LIKE CONCAT('q', ?, '::%'))");
        $st->execute([$pid, $pid]);

        // delete meta if exists
        $st = $pdo->prepare("DELETE FROM quest_project_meta WHERE project_id=?");
        $st->execute([$pid]);

        // delete project
        $st = $pdo->prepare("DELETE FROM quest_projects WHERE id=?");
        $st->execute([$pid]);

        $pdo->commit();
        out(['success'=>true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        out(['success'=>false,'error'=>'delete_failed','message'=>$e->getMessage()], 500);
    }
}

if ($action === 'delete_quest_scene') {
    $id = req_int('id', 0);
    if ($id <= 0) out(['success'=>false,'error'=>'bad_id'], 400);
    $st = $pdo->prepare("DELETE FROM quests WHERE id=?");
    $st->execute([$id]);
    out(['success'=>true]);
}

if ($action === 'save_quest_scene') {
    $cols = table_cols($pdo, 'quests');

    $id  = req_int('id', 0);
    if ($id <= 0) $id = req_int('scene_id', 0);

    $qid = req_int('quest_id', 0);
    if ($qid <= 0) $qid = req_int('pid', 0);
    if ($qid <= 0) $qid = req_int('project_id', 0);
    if ($qid <= 0) out(['success'=>false,'error'=>'bad_project_id'], 400);

    $scene_key = trim(req_str('scene_key', ''));
    if ($scene_key === '') out(['success'=>false,'error'=>'no_scene_key'], 400);

    // normalize legacy prefix
    if (preg_match('/^q'.$qid.'::(.+)$/', $scene_key, $m)) $scene_key = $m[1];

    // If id missing, update by unique key (quest_id + scene_key)
    if ($id <= 0) {
        $st = $pdo->prepare("SELECT id FROM quests WHERE quest_id=? AND scene_key=? LIMIT 1");
        $st->execute([$qid, $scene_key]);
        $found = $st->fetchColumn();
        if ($found) $id = (int)$found;
    }

    // validate choices JSON (allow empty)
    $choices_raw = trim(req_str('choices', '[]'));
    if ($choices_raw === '') $choices_raw = '[]';
    $choices = json_decode($choices_raw, true);
    if ($choices === null && $choices_raw !== 'null' && $choices_raw !== '[]') {
        out(['success'=>false,'error'=>'bad_choices_json'], 400);
    }
    if (!is_array($choices)) $choices = [];
    $choices_json = json_encode($choices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $is_start = req_int('is_start', 0) ? 1 : 0;

    // build fields map according to existing columns
    $fields = [];
    $set = function(string $col, $val) use (&$fields, $cols) {
        if (has_col($cols, $col)) $fields[$col] = $val;
    };

    $set('quest_id', $qid);
    $set('scene_key', $scene_key);
    $set('char_name', req_str('char_name',''));
    $set('dialogue_text', req_str('dialogue_text',''));
    $set('bg_url', req_str('bg_url',''));
    $set('sprite_url', req_str('sprite_url',''));
    $set('music_url', req_str('music_url',''));
    $set('choices', $choices_json);
    $set('is_start', $is_start);

    // extended fields (exist in your dump)
    $set('sprite_pos', req_str('sprite_pos','center'));
    $set('sprite2_url', req_str('sprite2_url',''));
    $set('sprite2_pos', req_str('sprite2_pos','right'));
    $set('active_speaker', req_int('active_speaker',0));
    $set('transition', req_str('transition','none'));
    $set('transition_time', max(0, req_int('transition_time',700)));
    $set('sfx_url', req_str('sfx_url',''));
    $set('popup_url', req_str('popup_url',''));
    $set('popup_duration', max(0, req_int('popup_duration',1200)));
    $set('popup_next_key', req_str('popup_next_key',''));
    // popup sfx field exists? If not, ignore safely.
    $set('popup_sfx_url', req_str('popup_sfx_url',''));

    // normalize empty strings to NULL for url-ish fields
    foreach (['char_name','bg_url','sprite_url','music_url','sfx_url','popup_url','popup_next_key','sprite2_url','popup_sfx_url'] as $k) {
        if (isset($fields[$k]) && is_string($fields[$k]) && trim($fields[$k]) === '') $fields[$k] = null;
    }

    if ($is_start === 1) {
        // ensure only one start per project
        $st = $pdo->prepare("UPDATE quests SET is_start=0 WHERE quest_id=?");
        $st->execute([$qid]);
    }

    if ($id > 0) {
        $vals = [];
        $parts = [];
        foreach ($fields as $c=>$v) { $parts[] = "`$c`=?"; $vals[]=$v; }
        $vals[] = $id;
        $st = $pdo->prepare("UPDATE quests SET ".implode(',', $parts)." WHERE id=?");
        $st->execute($vals);
        out(['success'=>true,'id'=>$id]);
    } else {
        $cols_sql = implode(',', array_map(fn($c)=>"`$c`", array_keys($fields)));
        $ph = implode(',', array_fill(0, count($fields), '?'));
        $vals = array_values($fields);
        $st = $pdo->prepare("INSERT INTO quests ($cols_sql) VALUES ($ph)");
        try {
            $st->execute($vals);
        } catch (PDOException $e) {
            // duplicate key (quest_id + scene_key)
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                out(['success'=>false,'error'=>'duplicate_scene_key','message'=>'Ключ сцены уже существует в этом проекте'], 409);
            }
            throw $e;
        }
        out(['success'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }
}

if ($action === 'save_quest_project_meta' || $action === 'save_project_meta') {
    // Emotions packs
    $pid = req_int('project_id', 0);
    if ($pid <= 0) out(['success'=>false,'error'=>'bad_project_id'], 400);

    $p1_pattern = trim(req_str('p1_pattern',''));
    $p1_emotions = trim(req_str('p1_emotions',''));
    $p2_pattern = trim(req_str('p2_pattern',''));
    $p2_emotions = trim(req_str('p2_emotions',''));

    $st = $pdo->prepare("INSERT INTO quest_project_meta (project_id, p1_pattern, p1_emotions, p2_pattern, p2_emotions)
                         VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE p1_pattern=VALUES(p1_pattern), p1_emotions=VALUES(p1_emotions),
                                                 p2_pattern=VALUES(p2_pattern), p2_emotions=VALUES(p2_emotions)");
    $st->execute([$pid, $p1_pattern ?: null, $p1_emotions ?: null, $p2_pattern ?: null, $p2_emotions ?: null]);
    out(['success'=>true]);
}

out(['success'=>false,'error'=>'unknown_action'], 400);

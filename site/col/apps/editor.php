<?php
// apps/editor.php
require_once '../config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['access_level'] < 5) exit('ACCESS DENIED');

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT u.id, u.username, u.access_level, d.* FROM users u JOIN dossier d ON u.id = d.user_id WHERE u.id = ?");
$stmt->execute([$id]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) exit('<div style="padding:20px; color:red">ОШИБКА: ДОСЬЕ НЕ НАЙДЕНО</div>');
?>

<form class="ed-container" onsubmit="saveUser(event)">
    <input type="hidden" name="action" value="save_user">
    <input type="hidden" name="target_id" value="<?php echo $u['user_id']; ?>">
    
    <div class="ed-section">
        <div class="ed-legend">ЛИЧНЫЕ ДАННЫЕ</div>
        <div class="ed-grid-2">
            <div class="f-group"><label class="f-lbl">ТИТУЛ</label><input class="f-inp" name="title" value="<?php echo htmlspecialchars($u['title']); ?>"></div>
            <div class="f-group"><label class="f-lbl">РЕЙТИНГ</label><input class="f-inp" name="rating" type="number" value="<?php echo $u['rating']; ?>" style="color:var(--primary)"></div>
        </div>
    </div>

    <div class="ed-section">
        <div class="ed-legend">БОЕВАЯ СВОДКА</div>
        <div class="ed-grid-2">
            <div class="f-group"><label class="f-lbl">ЗАЩИТА (W / L)</label>
                <div class="stat-row"><input class="f-inp" name="def_wins" value="<?php echo $u['def_wins']; ?>"><input class="f-inp" name="def_losses" value="<?php echo $u['def_losses']; ?>" style="color:var(--alert)"></div>
            </div>
            <div class="f-group"><label class="f-lbl">ОБВИНЕНИЕ (W / L)</label>
                <div class="stat-row"><input class="f-inp" name="pros_wins" value="<?php echo $u['pros_wins']; ?>"><input class="f-inp" name="pros_losses" value="<?php echo $u['pros_losses']; ?>" style="color:var(--alert)"></div>
            </div>
            <div class="f-group"><label class="f-lbl">ПОМОЩНИК (W / L)</label>
                <div class="stat-row"><input class="f-inp" name="co_wins" value="<?php echo $u['co_wins']; ?>"><input class="f-inp" name="co_losses" value="<?php echo $u['co_losses']; ?>" style="color:var(--alert)"></div>
            </div>
            <div class="f-group"><label class="f-lbl">СВИДЕТЕЛЬ (W / L)</label>
                <div class="stat-row"><input class="f-inp" name="wit_wins" value="<?php echo $u['wit_wins']; ?>"><input class="f-inp" name="wit_losses" value="<?php echo $u['wit_losses']; ?>" style="color:var(--alert)"></div>
            </div>
            <div class="f-group"><label class="f-lbl">СУДЬЯ (G / NG)</label>
                <div class="stat-row"><input class="f-inp" name="judge_g" value="<?php echo $u['judge_g']; ?>"><input class="f-inp" name="judge_ng" value="<?php echo $u['judge_ng']; ?>"></div>
            </div>
            <div class="f-group"><label class="f-lbl">ДЕТЕКТИВ (ВСЕГО)</label>
                <input class="f-inp" name="detective_count" value="<?php echo $u['detective_count']; ?>">
            </div>
        </div>
    </div>

    <div class="ed-section">
        <div class="ed-legend">ДОПОЛНИТЕЛЬНО</div>
        <div class="f-group"><label class="f-lbl">АКТИВНЫЕ НАВЫКИ (ВИДИТ ВСЕ)</label>
            <textarea class="f-inp" name="active_abilities" rows="3"><?php echo htmlspecialchars($u['active_abilities']); ?></textarea>
        </div>
    </div>

    <button class="btn-act">СОХРАНИТЬ ИЗМЕНЕНИЯ</button>
</form>
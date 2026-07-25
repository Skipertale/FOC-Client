<?php
// apps/admin_logs.php — ФИНАЛЬНАЯ ВЕРСИЯ
require_once '../config/db.php';
session_start();

// Проверка прав доступа (уровень 5)
if (($_SESSION['access_level'] ?? 0) < 5) {
    exit('<div style="color:#ff3333; padding:20px; font-family:monospace;">ACCESS_DENIED: ADMINISTRATIVE_LEVEL_REQUIRED</div>');
}

$filter = $_GET['filter'] ?? 'ALL';

// Подготовка SQL запроса с фильтрацией
$sql = "SELECT l.*, u.username FROM logs l JOIN users u ON l.user_id = u.id ";
if ($filter !== 'ALL') {
    $sql .= " WHERE l.action_type = " . $pdo->quote($filter);
}
$sql .= " ORDER BY l.created_at DESC LIMIT 150";

try {
    $logs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $types = $pdo->query("SELECT DISTINCT action_type FROM logs")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    exit('<div style="color:red;">DB_ERROR: ' . $e->getMessage() . '</div>');
}
?>

<div class="logs-sys-wrapper" style="height:100%; display:flex; flex-direction:column; background:#020406; font-family:'Share Tech Mono', monospace; color:#00ffcc; border:1px solid #004444;">
    
    <div class="logs-top-bar" style="padding:10px; background:#000; border-bottom:2px solid #00ffcc; display:flex; justify-content:space-between; align-items:center;">
        <div class="f-side" style="display:flex; align-items:center; gap:10px;">
            <i class="fas fa-terminal" style="font-size:0.9rem;"></i>
            <select class="log-filter-select" style="background:#000; color:#00ffcc; border:1px solid #00ffcc; padding:5px; font-family:inherit; outline:none;">
                <option value="ALL">ПОКАЗАТЬ ВСЁ</option>
                <?php foreach($types as $t): ?>
                    <option value="<?php echo $t; ?>" <?php echo $filter == $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
            
            <button class="logs-btn" style="background:#00ffcc; color:#000; border:none; padding:6px 15px; cursor:pointer; font-weight:bold; font-family:inherit;" 
                onclick="var filterVal=this.previousElementSibling.value; var winCont=this.closest('.win-content'); fetch('apps/admin_logs.php?filter='+filterVal).then(r=>r.text()).then(html=>{winCont.innerHTML=html;});">
                <i class="fas fa-sync-alt"></i> EXEC_FILTER
            </button>
        </div>
        <div class="logs-path" style="font-size:0.75rem; color:#008888; letter-spacing:1px;">
            SYSTEM/KERN/LOGS/<?php echo $filter; ?>
        </div>
    </div>

    <div class="logs-table-container" style="flex:1; overflow-y:auto; background: repeating-linear-gradient(0deg, rgba(0,255,204,0.02) 0px, rgba(0,255,204,0.02) 1px, transparent 1px, transparent 2px);">
        <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead style="background:#000; color:#008888; position:sticky; top:0; z-index:10; border-bottom:1px solid #004444;">
                <tr>
                    <th style="padding:12px; text-align:left; font-size:0.7rem;">ВРЕМЯ</th>
                    <th style="padding:12px; text-align:left; font-size:0.7rem;">ОПЕРАТОР</th>
                    <th style="padding:12px; text-align:left; font-size:0.7rem;">ТИП</th>
                    <th style="padding:12px; text-align:left; font-size:0.7rem;">СОДЕРЖАНИЕ (КЛИК ДЛЯ ДЕТАЛЕЙ)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($logs as $l): ?>
                <tr style="border-bottom:1px solid #001a1a; cursor:pointer; transition:background 0.2s;" 
                    onmouseover="this.style.background='rgba(0,255,204,0.05)'" 
                    onmouseout="this.style.background='transparent'"
                    onclick="var rawData='<?php echo addslashes($l['description']); ?>'; var formatted=rawData.split(' || ').join('<br>» '); var type='<?php echo $l['action_type']; ?>'; var user='<?php echo $l['username']; ?>'; var winContent='<div style=\'background:#000; color:#00ffcc; padding:20px; font-family:monospace; height:100%; overflow-y:auto; line-height:1.6;\'><div style=\'border-bottom:1px solid #00ffcc; padding-bottom:10px; margin-bottom:15px; color:#fff; font-weight:bold; font-size:1.1rem;\'>[REPORT_DUMP] '+type+' // BY '+user+'</div><div style=\'color:#eee;\'>» '+formatted+'</div><div style=\'margin-top:20px; border-top:1px solid #004444; padding-top:10px; font-size:0.7rem; color:#008888; text-align:right;\'>[SYSTEM_END_OF_FILE]</div></div>'; if(window.mkWin) { window.mkWin('DETAILS: '+type, 'fas fa-info-circle', winContent); } else { alert(rawData.split(' || ').join('\n')); }">
                    
                    <td style="padding:12px; color:#008888; white-space:nowrap;"><?php echo date('H:i:s d.m.y', strtotime($l['created_at'])); ?></td>
                    <td style="padding:12px; color:#fff; font-weight:bold;">@<?php echo htmlspecialchars($l['username']); ?></td>
                    <td style="padding:12px;">
                        <span style="border:1px solid #00ffcc; padding:2px 6px; border-radius:2px; font-size:0.65rem; color:#00ffcc; 
                            <?php 
                            if($l['action_type'] == 'ADMIN_EDIT') echo 'border-color:#f1c40f; color:#f1c40f;';
                            if($l['action_type'] == 'GAME_DELETE') echo 'border-color:#ff3333; color:#ff3333;';
                            ?>">
                            <?php echo $l['action_type']; ?>
                        </span>
                    </td>
                    <td style="padding:12px; color:#aaa; font-style:italic;">
                        <?php echo htmlspecialchars(mb_strimwidth($l['description'], 0, 75, "...")); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
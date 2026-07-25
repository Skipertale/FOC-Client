<?php
// apps/game_lobby.php - FULL RESTORED VERSION WITH PROTECTION & CENTERED ERROR
require_once '../config/db.php';
session_start();

$gid = $_GET['id'];
$me = $_SESSION['user_id'];

// Загрузка
$sql = "SELECT g.*, 
        u_jud.username as judge_name,
        u_def.username as def_name, u_def2.username as def2_name,
        u_pros.username as pros_name, u_pros2.username as pros2_name,
        u_wit.username as wit_name, u_det.username as det_name
        FROM games g
        LEFT JOIN users u_jud ON g.judge_id = u_jud.id
        LEFT JOIN users u_def ON g.def_id = u_def.id
        LEFT JOIN users u_def2 ON g.def2_id = u_def2.id
        LEFT JOIN users u_pros ON g.pros_id = u_pros.id
        LEFT JOIN users u_pros2 ON g.pros2_id = u_pros2.id
        LEFT JOIN users u_wit ON g.wit_id = u_wit.id
        LEFT JOIN users u_det ON g.det_id = u_det.id
        WHERE g.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$gid]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

// --- КРАСИВЫЙ ЭКРАН ОШИБКИ (FIXED LAYOUT & CENTERED) ---
if(!$game) exit('
<div class="lobby-grid error-state">
    <div class="scanlines"></div>
    <div class="noise"></div>
    <div class="bg-particles"></div>
    
    <div class="err-box">
        <div class="err-content">
            <div class="err-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="err-title">СВЯЗЬ ПОТЕРЯНА</div>
            <div class="err-desc">Лобби было удалено организатором<br>или срок действия сессии истёк.</div>
            <div class="err-meta">ERROR_CODE: LOBBY_404 // DISCONNECTED</div>
        </div>
    </div>
</div>
<style>
    @import url("https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css");
    @import url("https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap");
    
    .lobby-grid.error-state {
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        width: 100%; height: 100%;
        margin: 0; padding: 0;
        display: flex; 
        align-items: center; 
        justify-content: center; 
        background-color: #050505;
        background-image: 
            linear-gradient(rgba(231, 76, 60, 0.1) 1px, transparent 1px),
            linear-gradient(90deg, rgba(231, 76, 60, 0.1) 1px, transparent 1px);
        background-size: 30px 30px;
        color: #fff; 
        font-family: "Share Tech Mono", monospace;
        overflow: hidden;
        z-index: 1000;
    }
    
    .bg-particles {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        background-image: radial-gradient(rgba(231, 76, 60, 0.4) 1px, transparent 1px);
        background-size: 60px 60px;
        opacity: 0.5;
        animation: pulseBg 4s ease-in-out infinite;
        z-index: 1;
    }

    .noise {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        background: repeating-radial-gradient(#000 0 0.0001%, #111 0 0.0002%) 50% 0/2500px 2500px;
        opacity: 0.05; pointer-events: none; z-index: 2;
    }

    .scanlines {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        background: linear-gradient(to bottom, rgba(255,255,255,0), rgba(255,255,255,0) 50%, rgba(0,0,0,0.2) 50%, rgba(0,0,0,0.2));
        background-size: 100% 4px; z-index: 3; pointer-events: none;
    }
    
    .err-box {
        position: relative; z-index: 10;
        width: 400px; max-width: 90%; padding: 40px 20px;
        background: rgba(15, 5, 5, 0.95);
        border: 1px solid #e74c3c;
        box-shadow: 0 0 40px rgba(231, 76, 60, 0.15);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        text-align: center;
        animation: boxPulse 4s infinite ease-in-out;
    }
    
    .err-box::before { content: ""; position: absolute; top: -1px; left: -1px; width: 10px; height: 10px; border-top: 3px solid #fff; border-left: 3px solid #fff; }
    .err-box::after { content: ""; position: absolute; bottom: -1px; right: -1px; width: 10px; height: 10px; border-bottom: 3px solid #fff; border-right: 3px solid #fff; }
    .err-icon { font-size: 3.5rem; color: #e74c3c; margin-bottom: 15px; }
    .err-title { font-size: 1.4rem; color: #e74c3c; font-weight: bold; margin-bottom: 10px; letter-spacing: 2px; }
    .err-desc { color: #ccc; font-size: 0.9rem; line-height: 1.4; margin-bottom: 20px; }
    .err-meta { color: #555; font-size: 0.7rem; border-top: 1px solid rgba(231, 76, 60, 0.2); padding-top: 10px; width: 100%; }
    
    @keyframes boxPulse { 0%, 100% { box-shadow: 0 0 20px rgba(231, 76, 60, 0.1); border-color: #e74c3c; } 50% { box-shadow: 0 0 40px rgba(231, 76, 60, 0.3); border-color: #ff6b6b; } }
    @keyframes pulseBg { 0%, 100% { opacity: 0.3; } 50% { opacity: 0.6; } }
</style>
');

// Статусы
$isRanked = ($game['mode'] === 'ranked');
$isHost = ($game['judge_id'] == $me);
$isActive = ($game['status'] === 'active');
$isFinished = ($game['status'] === 'finished');
$isPaused = ($game['is_paused'] == 1);
$is2v2 = ($game['format'] === '2v2');

// Моя роль
$myRoleSlot = null; 
if($game['def_id'] == $me || $game['def2_id'] == $me) $myRoleSlot = 'def';
elseif($game['pros_id'] == $me || $game['pros2_id'] == $me) $myRoleSlot = 'pros';
elseif($game['wit_id'] == $me) $myRoleSlot = 'wit';
elseif($game['det_id'] == $me) $myRoleSlot = 'det';

// Готовность
$readyToStart = false;
if ($is2v2) {
    if ($game['def_id'] && $game['def2_id'] && $game['pros_id'] && $game['pros2_id']) $readyToStart = true;
} else {
    if ($game['def_id'] && $game['pros_id']) $readyToStart = true;
}

// Helper RENDER
function renderCard($roleType, $roleKey, $nameKey, $title, $game, $me, $isActive, $isFinished, $myRoleSlot, $isHost, $gid) {
    $playerId = $game[$roleKey];
    $playerName = $game[$nameKey];
    $colPrefix = str_replace('_id', '', $roleKey);
    $colorClass = ($roleType == 'def') ? 'def-card' : 'pros-card';
    $icon = ($roleType == 'def') ? 'fa-shield-alt' : 'fa-gavel';
    
    // ПРАВКИ ЗАЩИТЫ: Блокируем, если активна или закончена
    $canEdit = ($isHost || ($playerId == $me)) && !$isActive && !$isFinished;
    $canLeave = ($playerId == $me) && !$isActive && !$isFinished;
    
    $cardState = $playerId ? 'occupied' : 'empty';
    
    $html = '<div class="player-card '.$colorClass.' '.$cardState.'">';
    
    // HEADER
    $html .= '<div class="pc-header">';
        $html .= '<div class="role-label '.$roleType.'"><i class="fas '.$icon.'"></i> '.$title.'</div>';
        if ($playerId) {
            $html .= '<div class="p-name">'.htmlspecialchars($playerName).'</div>';
            if ($canLeave) {
                $html .= '<button class="btn-xs leave" onclick="leaveSlot('.$gid.')">X</button>';
            }
        } else {
            $html .= '<div class="p-name placeholder">СВОБОДНО</div>';
            if (!$myRoleSlot && !$isHost && !$isActive && !$isFinished) {
                $html .= '<button class="btn-join" onclick="joinSlot('.$gid.', \''.$roleKey.'\')">ЗАНЯТЬ</button>';
            }
        }
    $html .= '</div>';

    // STATS
    $colStr = $colPrefix.'_str';
    $colCd = $colPrefix.'_cd';
    
    $html .= '<div class="stats-row">';
        if ($isHost && !$isActive && !$isFinished) {
            $html .= '<div class="stat-box"><span class="s-lbl">СИЛА</span><input type="number" class="s-input" value="'.$game[$colStr].'" onchange="saveStat('.$gid.', \''.$colStr.'\', this.value)"></div>';
            $html .= '<div class="stat-box"><span class="s-lbl">КД</span><input type="number" class="s-input" value="'.$game[$colCd].'" onchange="saveStat('.$gid.', \''.$colCd.'\', this.value)"></div>';
        } else {
            $html .= '<div class="stat-box"><span class="s-lbl">СИЛА</span><span class="s-val">'.$game[$colStr].'</span></div>';
            $html .= '<div class="stat-box"><span class="s-lbl">КД</span><span class="s-val">'.$game[$colCd].'</span></div>';
        }
    $html .= '</div>';

    // ABILITIES
    $colAbils = $colPrefix.'_abilities';
    $abText = $game[$colAbils];

    $html .= '<div class="pc-body">';
    $html .= '<div class="ab-label">СПОСОБНОСТИ</div>';
    if ($canEdit) {
        $html .= '<textarea class="ab-input" placeholder="..." onblur="saveAbils('.$gid.', \''.$colPrefix.'\', this)">'.htmlspecialchars($abText).'</textarea>';
    } else {
        $html .= '<div class="ab-view">'.htmlspecialchars($abText).'</div>';
    }
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}
?>

<div class="lobby-grid">
    
    <div class="header-area">
        <?php if($isFinished): ?>
             <div class="lh-status finished">/// АРХИВ ///</div>
        <?php elseif($isPaused): ?>
             <div class="lh-status paused">/// ПАУЗА ///</div>
        <?php elseif($isActive): ?>
             <div class="lh-status active">/// СЕССИЯ ИДЁТ ///</div>
        <?php else: ?>
             <div class="lh-status">/// ПОДГОТОВКА ///</div>
        <?php endif; ?>

        <div class="lh-meta">
            <span class="badge"><?php echo $game['format']; ?></span>
            <span class="sep">|</span>
            <span class="badge"><?php echo $isRanked ? 'RANKED' : 'NORMAL'; ?></span>
            <span class="sep">|</span>
            <span>ULT: <?php echo $game['ultimates']?'ON':'OFF'; ?></span>
        </div>
    </div>

    <div class="judge-area">
        <div class="judge-card">
            <div class="avatar-box judge"><i class="fas fa-balance-scale"></i></div>
            <div class="player-name judge-name"><?php echo htmlspecialchars($game['judge_name']); ?></div>
        </div>
    </div>

    <div class="def-area">
        <?php 
            echo renderCard('def', 'def_id', 'def_name', 'ЗАЩИТА 1', $game, $me, $isActive, $isFinished, $myRoleSlot, $isHost, $gid);
            if ($is2v2) echo renderCard('def', 'def2_id', 'def2_name', 'ЗАЩИТА 2', $game, $me, $isActive, $isFinished, $myRoleSlot, $isHost, $gid);
        ?>
    </div>

    <div class="vs-area"><div class="vs-text">VS</div></div>

    <div class="pros-area">
         <?php 
            echo renderCard('pros', 'pros_id', 'pros_name', 'ОБВИНЕНИЕ 1', $game, $me, $isActive, $isFinished, $myRoleSlot, $isHost, $gid);
            if ($is2v2) echo renderCard('pros', 'pros2_id', 'pros2_name', 'ОБВИНЕНИЕ 2', $game, $me, $isActive, $isFinished, $myRoleSlot, $isHost, $gid);
        ?>
    </div>

    <div class="extras-area">
        <div class="extra-card">
            <div class="ex-header">СВИДЕТЕЛЬ</div>
            <?php if($game['wit_id']): ?>
                <div class="ex-content active">
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($game['wit_name']); ?>
                    <?php if(($game['wit_id'] == $me || $isHost) && !$isActive && !$isFinished): ?>
                        <i class="fas fa-times del-icon" onclick="kickSlot(<?php echo $gid; ?>, 'wit_id')"></i>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php if(!$myRoleSlot && !$isHost && !$isActive && !$isFinished): ?>
                    <button class="btn-join-wide" onclick="joinSlot(<?php echo $gid; ?>, 'wit_id')">ВСТУПИТЬ</button>
                <?php else: ?>
                    <div class="ex-content dim">ПУСТО</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="extra-card">
            <div class="ex-header">ДЕТЕКТИВ</div>
            <?php if($game['det_id']): ?>
                <div class="ex-content active">
                    <i class="fas fa-search"></i> <?php echo htmlspecialchars($game['det_name']); ?>
                    <?php if(($game['det_id'] == $me || $isHost) && !$isActive && !$isFinished): ?>
                        <i class="fas fa-times del-icon" onclick="kickSlot(<?php echo $gid; ?>, 'det_id')"></i>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php if(!$myRoleSlot && !$isHost && !$isActive && !$isFinished): ?>
                    <button class="btn-join-wide" onclick="joinSlot(<?php echo $gid; ?>, 'det_id')">ВСТУПИТЬ</button>
                <?php else: ?>
                    <div class="ex-content dim">ПУСТО</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer-area">
        <?php if($isFinished): ?>
            <div class="status-message finished">ИГРА ЗАВЕРШЕНА</div>
        <?php elseif($isHost): ?>
            <div class="host-controls">
                
                <button class="main-btn delete" onclick="confirmAction('УДАЛИТЬ ЛОББИ?', function(){ deleteLobby(<?php echo $gid; ?>); })">УДАЛИТЬ</button>

                <?php if(!$isActive): ?>
                    <button class="main-btn <?php echo $readyToStart ? 'ready' : 'disabled'; ?>" 
                            onclick="<?php echo $readyToStart ? "changeStatus($gid, 'active')" : ''; ?>">
                        НАЧАТЬ ЗАСЕДАНИЕ
                    </button>
                <?php else: ?>
                    <button class="main-btn pause" onclick="togglePause(<?php echo $gid; ?>)">
                        <?php echo $isPaused ? 'ПРОДОЛЖИТЬ' : 'ПАУЗА'; ?>
                    </button>
                    <button class="main-btn stop" onclick="confirmAction('ЗАВЕРШИТЬ ИГРУ НАВСЕГДА?', function(){ changeStatus(<?php echo $gid; ?>, 'finished'); })">ЗАВЕРШИТЬ</button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="status-message">
                <?php 
                    if ($isPaused) echo 'СУДЬЯ ПОСТАВИЛ ПАУЗУ';
                    elseif ($isActive) echo 'ИДЕТ ЗАСЕДАНИЕ';
                    else echo 'ОЖИДАНИЕ ИГРОКОВ...'; 
                ?>
            </div>
        <?php endif; ?>
    </div>
    
    <div id="custom-modal" class="c-modal-overlay">
        <div class="c-modal-box">
            <div class="c-modal-title"><i class="fas fa-exclamation-triangle"></i> ВНИМАНИЕ</div>
            <div class="c-modal-text" id="c-modal-text">Текст вопроса?</div>
            <div class="c-modal-actions">
                <button class="c-btn cancel" onclick="closeConfirm()">ОТМЕНА</button>
                <button class="c-btn confirm" id="c-btn-confirm">ПОДТВЕРДИТЬ</button>
            </div>
        </div>
    </div>

</div>

<img src="x" style="display:none" onerror="
(function(el){
    const win = el.closest('.window');
    
    // API CALLS
    win.joinSlot = function(gid, role) { apiCall(gid, 'join_slot', {slot: role}); };
    win.leaveSlot = function(gid) { apiCall(gid, 'leave_slot', {}); };
    win.kickSlot = function(gid, slotName) { apiCall(gid, 'kick_slot', {slot: slotName}); }; 
    
    win.changeStatus = function(gid, status) { 
        if(status==='active' && window.sfx) window.sfx('boot');
        apiCall(gid, 'change_game_status', {status: status}); 
    };

    win.togglePause = function(gid) { apiCall(gid, 'toggle_pause', {}); };
    win.deleteLobby = function(gid) { apiCall(gid, 'delete_game', {}, false); };
    
    win.saveAbils = function(gid, role, el) { apiCall(gid, 'update_lobby_skills', {role: role, abilities: el.value}, false); };
    win.saveStat = function(gid, col, val) { apiCall(gid, 'update_lobby_stat', {col: col, val: val}, false); };

    function apiCall(gid, act, data, reload=true) {
        const fd = new FormData();
        fd.append('action', act);
        fd.append('game_id', gid);
        for(let k in data) fd.append(k, data[k]);
        
        fetch('api.php', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(d=>{
            if(d.status === 'success') {
                if(reload) reloadLobby();
            } else {
                alert(d.msg || 'Ошибка');
            }
        });
    }

    // MODAL LOGIC
    let pendingAction = null;
    
    win.confirmAction = function(text, callback) {
        const modal = win.querySelector('#custom-modal');
        const textField = win.querySelector('#c-modal-text');
        const confirmBtn = win.querySelector('#c-btn-confirm');
        
        if(!modal) return;
        
        textField.textContent = text;
        pendingAction = callback;
        
        confirmBtn.onclick = function() {
            if(pendingAction) pendingAction();
            modal.style.display = 'none';
            pendingAction = null;
        };
        
        modal.style.display = 'flex';
    };

    win.closeConfirm = function() {
        const modal = win.querySelector('#custom-modal');
        if(modal) modal.style.display = 'none';
        pendingAction = null;
    };

    // RELOAD LOGIC
    function reloadLobby() {
        if(!document.contains(win)) return;
        
        const modal = win.querySelector('#custom-modal');
        if(modal && modal.style.display === 'flex') return;

        const activeEl = document.activeElement;
        const isInteracting = activeEl && (activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'INPUT') && win.contains(activeEl);
        
        if(!isInteracting) {
            fetch('apps/game_lobby.php?id=<?php echo $gid; ?>')
            .then(r => r.text())
            .then(html => { win.querySelector('.win-content').innerHTML = html; });
        }
    }

    if(!win.refreshInterval) {
        win.refreshInterval = setInterval(reloadLobby, 3000);
        const obs = new MutationObserver((list) => {
            if(!document.contains(win)) { clearInterval(win.refreshInterval); obs.disconnect(); }
        });
        obs.observe(document.body, {childList:true, subtree:true});
    }
    
    // Exports
    window.joinSlot = win.joinSlot;
    window.leaveSlot = win.leaveSlot;
    window.kickSlot = win.kickSlot;
    window.changeStatus = win.changeStatus;
    window.togglePause = win.togglePause;
    window.deleteLobby = win.deleteLobby;
    window.saveAbils = win.saveAbils;
    window.saveStat = win.saveStat;
    window.confirmAction = win.confirmAction;
    window.closeConfirm = win.closeConfirm;

})(this);">

<style>
    /* --- MAIN STYLES --- */
    .lobby-grid {
        --c-bg: #0b0f12;
        --c-panel: rgba(255, 255, 255, 0.03);
        --c-def: #3498db;
        --c-pros: #e74c3c;
        --c-judge: #f1c40f;
        --c-text: #eee;
        
        height: 100%;
        display: grid;
        grid-template-columns: 1fr 100px 1fr;
        grid-template-rows: auto auto 1fr auto auto;
        gap: 10px;
        padding: 15px;
        background: #0b0f12;
        color: var(--c-text);
        font-family: 'Share Tech Mono', monospace;
        overflow-y: auto;
        position: relative; 
    }

    /* HEADER */
    .header-area { grid-column: 1 / -1; text-align: center; border-bottom: 1px solid #333; padding-bottom: 5px; }
    .lh-status { font-weight: bold; color: #666; letter-spacing: 2px; }
    .lh-status.active { color: #2ecc71; text-shadow: 0 0 10px rgba(46,204,113,0.3); }
    .lh-status.paused { color: #f39c12; animation: blink 1s infinite; }
    .lh-status.finished { color: #e74c3c; }
    .lh-meta { font-size: 0.8rem; color: #555; margin-top: 3px; }

    /* JUDGE */
    .judge-area { grid-column: 2; display: flex; flex-direction: column; align-items: center; }
    .judge-card .avatar-box { font-size: 1.2rem; color: var(--c-judge); margin-bottom: 2px; text-align:center;}
    .judge-name { font-size: 0.9rem; color: var(--c-judge); text-align: center;}

    /* MAIN CARDS AREA */
    .def-area { grid-column: 1; grid-row: 3; display: flex; flex-direction: column; gap: 10px; }
    .pros-area { grid-column: 3; grid-row: 3; display: flex; flex-direction: column; gap: 10px; }
    
    .player-card { flex: 1; background: var(--c-panel); border: 1px solid #333; display: flex; flex-direction: column; }
    .def-card { border-left: 3px solid var(--c-def); }
    .pros-card { border-right: 3px solid var(--c-pros); }
    .pc-header { padding: 8px; background: rgba(0,0,0,0.3); display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #222;}
    .role-label { font-weight: bold; font-size: 0.85rem; }
    .def .role-label { color: var(--c-def); }
    .pros .role-label { color: var(--c-pros); }
    .p-name { font-size: 1rem; margin-left: 10px; }
    
    /* STATS & ABILS */
    .stats-row { display: flex; gap: 10px; padding: 5px 8px; background: rgba(0,0,0,0.2); border-bottom: 1px solid #222; }
    .stat-box { display: flex; align-items: center; gap: 5px; font-size: 0.8rem; }
    .s-lbl { color: #666; font-size: 0.7rem; }
    .s-val { color: #fff; font-weight: bold; }
    .s-input { width: 40px; background: #111; border: 1px solid #444; color: #fff; text-align: center; }

    .pc-body { flex: 1; padding: 10px; display: flex; flex-direction: column; min-height: 100px; }
    .ab-label { font-size: 0.7rem; color: #444; margin-bottom: 5px; }
    .ab-input { flex: 1; width: 100%; background: transparent; border: 1px solid #333; color: #ddd; padding: 5px; resize: none; font-size: 0.9rem; line-height: 1.2; }
    .ab-view { flex: 1; font-size: 0.9rem; color: #ccc; line-height: 1.25; white-space: pre-wrap; margin: 0; padding: 0; }

    /* VS & EXTRAS */
    .vs-area { grid-column: 2; grid-row: 3; display: flex; align-items: center; justify-content: center; }
    .vs-text { font-size: 2.5rem; font-weight: 900; color: #333; font-style: italic; opacity: 0.3; }
    .extras-area { grid-column: 1 / -1; display: flex; gap: 20px; justify-content: center; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #222; }
    .extra-card { display: flex; flex-direction: column; align-items: center; width: 140px; }
    .ex-header { font-size: 0.7rem; color: #555; }
    .ex-content { border: 1px solid #333; width: 100%; padding: 5px; text-align: center; font-size: 0.8rem; background: var(--c-panel); display: flex; align-items: center; justify-content: center; gap: 5px; }
    .ex-content.active { border-color: #555; color: #fff; }
    .del-icon { color: var(--c-pros); cursor: pointer; margin-left: auto; }

    /* FOOTER & BUTTONS */
    .footer-area { grid-column: 1 / -1; text-align: center; margin-top: 10px; }
    .host-controls { display: flex; gap: 15px; justify-content: center; align-items: center; }
    
    .main-btn { 
        background: #ccc; border: none; padding: 10px 20px; font-weight: bold; cursor: pointer; 
        clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
        transition: all 0.2s;
    }
    .main-btn:hover { filter: brightness(1.1); }
    .main-btn.stop { background: var(--c-pros); color: #fff; }
    .main-btn.pause { background: #f39c12; color: #000; }
    
    .main-btn.delete { 
        background: transparent; color: #e74c3c; border: 2px solid #e74c3c;
        clip-path: none; border-radius: 4px; 
    }
    .main-btn.delete:hover { background: #e74c3c; color: #fff; box-shadow: 0 0 15px rgba(231, 76, 60, 0.5); }
    .main-btn.disabled { background: #333; color: #555; cursor: not-allowed; opacity: 0.5; }
    .main-btn.ready { background: #2ecc71; color: #000; }

    .status-message { font-size: 1.1rem; color: #666; animation: blink 2s infinite; }
    .status-message.finished { color: #e74c3c; animation: none; font-weight: bold; }
    
    /* --- STYLISH MODAL --- */
    .c-modal-overlay {
        position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.85); backdrop-filter: blur(3px);
        z-index: 999; display: none;
        align-items: center; justify-content: center;
    }
    
    .c-modal-box {
        background: linear-gradient(135deg, #0b0f12 0%, #1a1f24 100%);
        border: 1px solid var(--c-pros);
        box-shadow: 0 0 0 2px rgba(0,0,0,0.5), 0 0 30px rgba(231, 76, 60, 0.2);
        width: 350px; padding: 25px; text-align: center;
        animation: popIn 0.2s ease-out;
        position: relative;
    }
    .c-modal-box::before { content: ""; position: absolute; top: -1px; left: -1px; width: 10px; height: 10px; border-top: 2px solid #fff; border-left: 2px solid #fff; }
    .c-modal-box::after { content: ""; position: absolute; bottom: -1px; right: -1px; width: 10px; height: 10px; border-bottom: 2px solid #fff; border-right: 2px solid #fff; }
    
    .c-modal-title { color: var(--c-pros); font-weight: bold; font-size: 1.3rem; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid rgba(231, 76, 60, 0.3); display: inline-block; padding-bottom: 5px; }
    .c-modal-text { color: #ddd; margin-bottom: 25px; font-size: 1.1rem; }
    .c-modal-actions { display: flex; gap: 15px; justify-content: center; }
    
    .c-btn { padding: 10px 25px; font-weight: bold; cursor: pointer; font-family: inherit; border-radius: 2px; transition: all 0.2s ease; }
    .c-btn.cancel { background: transparent; border: 1px solid #555; color: #888; }
    .c-btn.cancel:hover { background: #333; color: #ddd; border-color: #777; }
    .c-btn.confirm { background: var(--c-pros); color: #fff; border: 1px solid var(--c-pros); }
    .c-btn.confirm:hover { background: #ff5733; box-shadow: 0 0 15px var(--c-pros); }

    @keyframes popIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    @keyframes blink { 50% { opacity: 0.5; } }
</style>
<?php require_once '../config/db.php'; ?>
<div class="g-list-wrap">
    <div class="gl-head">АКТИВНЫЕ СЕССИИ</div>
    
    <div class="gl-grid-container">
        <div class="gl-row header">
            <div class="col-id">ID</div>
            <div class="col-mode">РЕЖИМ</div>
            <div class="col-host">СУДЬЯ (HOST)</div>
            <div class="col-fmt">ФОРМАТ</div>
            <div class="col-st">СТАТУС</div>
        </div>
        
        <div class="gl-body">
            <?php
            // Показываем Lobby и Active
            $games = $pdo->query("SELECT g.*, u.username FROM games g JOIN users u ON g.judge_id = u.id WHERE g.status != 'finished' ORDER BY g.id DESC")->fetchAll();
            
            if(count($games) == 0): ?>
                <div style="padding:20px; text-align:center; color:#555;">НЕТ АКТИВНЫХ ИГР</div>
            <?php endif;

            foreach($games as $g): 
                $isRanked = ($g['mode'] === 'ranked');
                $isActive = ($g['status'] === 'active');
            ?>
            <div class="gl-row item" onclick="openApp('apps/game_lobby.php?id=<?php echo $g['id']; ?>', 'ЛОББИ #<?php echo $g['id']; ?>', 'fas fa-gavel')">
                <div class="col-id"><?php echo str_pad($g['id'], 3, '0', STR_PAD_LEFT); ?></div>
                <div class="col-mode" style="color:<?php echo $isRanked?'var(--alert)':'var(--primary)'; ?>">
                    <?php echo $isRanked ? 'RANKED' : 'NORMAL'; ?>
                </div>
                <div class="col-host" style="color:#fff; font-weight:bold;">
                    <?php echo htmlspecialchars($g['username']); ?>
                </div>
                <div class="col-fmt"><?php echo $g['format']; ?></div>
                <div class="col-st">
                    <span class="badge <?php echo $isActive?'active':''; ?>"><?php echo strtoupper($g['status']); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <style>
        .g-list-wrap { padding: 20px; height: 100%; display: flex; flex-direction: column; color: #fff; font-family: 'Share Tech Mono', monospace; }
        .gl-head { font-size: 1.2rem; color: var(--primary); border-bottom: 1px solid #333; padding-bottom: 10px; margin-bottom: 10px; letter-spacing: 2px; }
        
        .gl-grid-container { display: flex; flex-direction: column; height: 100%; }
        
        /* GRID SYSTEM */
        .gl-row { display: grid; grid-template-columns: 60px 100px 1fr 80px 100px; align-items: center; padding: 10px; border-bottom: 1px solid #222; }
        .header { color: #666; font-size: 0.7rem; text-transform: uppercase; border-bottom: 1px solid var(--primary); font-weight: bold; }
        
        .gl-body { overflow-y: auto; flex: 1; }
        .item { cursor: pointer; transition: 0.2s; font-size: 0.9rem; }
        .item:hover { background: rgba(0,255,204,0.1); padding-left: 15px; border-left: 3px solid var(--primary); }
        
        .badge { background: #333; color: #fff; padding: 2px 6px; font-size: 0.7rem; border-radius: 3px; }
        .badge.active { background: var(--alert); color: #000; font-weight: bold; animation: pulse 2s infinite; }

        @keyframes pulse { 50% { opacity: 0.5; } }
        
        /* Scrollbar */
        .gl-body::-webkit-scrollbar { width: 5px; }
        .gl-body::-webkit-scrollbar-thumb { background: #333; }
        .gl-body::-webkit-scrollbar-thumb:hover { background: var(--primary); }
    </style>
</div>
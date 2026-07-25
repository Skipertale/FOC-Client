<?php
// apps/profile_view.php [STRICT MODE]
require_once '../config/db.php';
session_start();

$targetId = $_GET['id'] ?? $_SESSION['user_id'];
$mode = $_GET['mode'] ?? 'view';

$isAdmin = ($_SESSION['access_level'] ?? 0) >= 5;
$isOwner = ($targetId == $_SESSION['user_id']);
$canEdit = $isAdmin || $isOwner; // Редактировать может владелец или админ

// Если режим edit, но прав нет — сброс на view
if ($mode === 'edit' && !$canEdit) $mode = 'view';

$stmt = $pdo->prepare("SELECT u.id, u.username, u.access_level, d.* FROM users u JOIN dossier d ON u.id = d.user_id WHERE u.id = ?");
$stmt->execute([$targetId]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) exit('<div style="padding:20px;color:red">ERROR LOADING DATA</div>');

function getPct($a, $b) { $t=$a+$b; return ($t>0)?round(($a/$t)*100).'%':'0%'; }
?>

<<?php echo ($mode==='edit') ? 'form' : 'div'; ?> class="profile-layout-full" onsubmit="saveProfile(event, '<?php echo $isAdmin ? 'save_user' : 'save_self_profile'; ?>')">
    
    <?php if($mode==='edit'): ?>
        <input type="hidden" name="target_id" value="<?php echo $p['user_id']; ?>">
    <?php endif; ?>

    <div class="ui-header">
        <div class="ui-left"><span class="term-badge">&lt;&lt; DATABASE</span></div>
        <div class="ui-center">
            <?php if($mode === 'view'): ?>
                STATUS: READ ONLY
            <?php else: ?>
                <span style="color:var(--primary); animation:blink 1s infinite;">>> EDITING MODE ACTIVE <<</span>
            <?php endif; ?>
        </div>
        <div class="ui-right">
            <?php if($mode === 'view' && $canEdit): ?>
                <button type="button" class="btn-mode" onclick="reloadProfile('edit')">
                    <i class="fas fa-pen"></i> РЕДАКТИРОВАТЬ
                </button>
            <?php elseif($mode === 'edit'): ?>
                <button type="button" class="btn-mode cancel" onclick="reloadProfile('view')">
                    <i class="fas fa-times"></i> ОТМЕНА
                </button>
            <?php endif; ?>
            <button type="button" class="btn-kill" onclick="closeWin(this.closest('.window').id)">X</button>
        </div>
    </div>

    <div class="p-body">
        <div class="col-l">
            <div class="avatar-box">
                <div class="glow-ring"></div>
                <i class="fas fa-user-secret"></i>
            </div>
            
            <div class="u-nick"><?php echo htmlspecialchars($p['username']); ?></div>
            <div class="u-uid">ID: <?php echo str_pad($p['id'], 4, '0', STR_PAD_LEFT); ?></div>

            <div class="meta-row">
                <div class="m-lbl">ТИТУЛ / РАНГ</div>
                <?php if($mode === 'edit' && $isAdmin): ?>
                    <input type="text" name="title" class="ghost-inp" value="<?php echo htmlspecialchars($p['title']); ?>">
                <?php else: ?>
                    <div class="m-val"><?php echo $p['title']?:'---'; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="meta-row">
                <div class="m-lbl">ЛЮБИМЫЙ ПЕРСОНАЖ</div>
                <?php if($mode === 'edit'): ?>
                    <input type="text" name="fav_char" class="ghost-inp cyan" value="<?php echo htmlspecialchars($p['fav_char']); ?>">
                <?php else: ?>
                    <div class="m-val cyan"><?php echo $p['fav_char']?:'---'; ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-r">
            
            <div class="row-stats">
                <div class="stats-matrix">
                    <div class="stat-cell">
                        <div class="st-h"><i class="fas fa-shield-alt"></i> ЗАЩИТА</div>
                        <div class="st-v">
                            <?php if($mode === 'edit' && $isAdmin): ?>
                                <input type="number" name="def_wins" class="tiny-inp" value="<?php echo $p['def_wins']; ?>"> W / <input type="number" name="def_losses" class="tiny-inp" value="<?php echo $p['def_losses']; ?>"> L
                            <?php else: ?>
                                W:<?php echo $p['def_wins']; ?> / L:<?php echo $p['def_losses']; ?>
                            <?php endif; ?>
                        </div>
                        <div class="st-b"><div class="st-f" style="width:<?php echo getPct($p['def_wins'], $p['def_losses']); ?>"></div></div>
                    </div>
                    
                    <div class="stat-cell">
                        <div class="st-h"><i class="fas fa-gavel"></i> ОБВИНЕНИЕ</div>
                        <div class="st-v">
                            <?php if($mode === 'edit' && $isAdmin): ?>
                                <input type="number" name="pros_wins" class="tiny-inp" value="<?php echo $p['pros_wins']; ?>"> W / <input type="number" name="pros_losses" class="tiny-inp" value="<?php echo $p['pros_losses']; ?>"> L
                            <?php else: ?>
                                W:<?php echo $p['pros_wins']; ?> / L:<?php echo $p['pros_losses']; ?>
                            <?php endif; ?>
                        </div>
                        <div class="st-b"><div class="st-f" style="width:<?php echo getPct($p['pros_wins'], $p['pros_losses']); ?>"></div></div>
                    </div>

                    <div class="stat-cell">
                        <div class="st-h"><i class="fas fa-handshake"></i> ПОМОЩНИК</div>
                        <div class="st-v">
                            <?php if($mode === 'edit' && $isAdmin): ?>
                                <input type="number" name="co_wins" class="tiny-inp" value="<?php echo $p['co_wins']; ?>"> W / <input type="number" name="co_losses" class="tiny-inp" value="<?php echo $p['co_losses']; ?>"> L
                            <?php else: ?>
                                W:<?php echo $p['co_wins']; ?> / L:<?php echo $p['co_losses']; ?>
                            <?php endif; ?>
                        </div>
                        <div class="st-b"><div class="st-f" style="width:<?php echo getPct($p['co_wins'], $p['co_losses']); ?>"></div></div>
                    </div>

                    <div class="stat-cell">
                        <div class="st-h"><i class="fas fa-eye"></i> СВИДЕТЕЛЬ</div>
                        <div class="st-v">
                            <?php if($mode === 'edit' && $isAdmin): ?>
                                <input type="number" name="wit_wins" class="tiny-inp" value="<?php echo $p['wit_wins']; ?>"> W / <input type="number" name="wit_losses" class="tiny-inp" value="<?php echo $p['wit_losses']; ?>"> L
                            <?php else: ?>
                                W:<?php echo $p['wit_wins']; ?> / L:<?php echo $p['wit_losses']; ?>
                            <?php endif; ?>
                        </div>
                        <div class="st-b"><div class="st-f" style="width:<?php echo getPct($p['wit_wins'], $p['wit_losses']); ?>"></div></div>
                    </div>

                    <div class="stat-cell">
                        <div class="st-h"><i class="fas fa-balance-scale"></i> СУДЬЯ</div>
                        <div class="st-v">
                            <?php if($mode === 'edit' && $isAdmin): ?>
                                <input type="number" name="judge_g" class="tiny-inp" value="<?php echo $p['judge_g']; ?>"> G / <input type="number" name="judge_ng" class="tiny-inp" value="<?php echo $p['judge_ng']; ?>"> NG
                            <?php else: ?>
                                G:<?php echo $p['judge_g']; ?> / NG:<?php echo $p['judge_ng']; ?>
                            <?php endif; ?>
                        </div>
                        <div class="st-b bg-red"><div class="st-f red" style="width:<?php echo getPct($p['judge_ng'], $p['judge_g']); ?>"></div></div>
                    </div>

                    <div class="stat-cell">
                        <div class="st-h"><i class="fas fa-search"></i> ДЕТЕКТИВ</div>
                        <div class="st-v center">
                            <?php if($mode === 'edit' && $isAdmin): ?>
                                ИГР: <input type="number" name="detective_count" class="tiny-inp" value="<?php echo $p['detective_count']; ?>">
                            <?php else: ?>
                                ИГР: <?php echo $p['detective_count']; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="danger-box">
                    <div class="d-lbl">УРОВЕНЬ УГРОЗЫ</div>
                    <?php if($mode === 'edit' && $isAdmin): ?>
                        <input type="number" name="rating" class="big-rating-inp" value="<?php echo $p['rating']; ?>">
                    <?php else: ?>
                        <div class="d-val"><?php echo $p['rating']; ?></div>
                    <?php endif; ?>
                    <div class="d-sub">ОЧКИ</div>
                </div>
            </div>

            <div class="info-matrix">
                
                <div class="info-cell">
                    <div class="ic-h">
                        <i class="fas fa-bolt"></i> АКТИВНЫЕ СПОСОБНОСТИ 
                        <?php if($mode==='edit' && !$isAdmin) echo '<i class="fas fa-lock" style="float:right; color:#555;"></i>'; ?>
                    </div>
                    
                    <?php if($mode === 'edit' && $isAdmin): ?>
                        <textarea name="active_abilities" class="area-inp" placeholder=">> Введите навыки..."><?php echo htmlspecialchars($p['active_abilities']); ?></textarea>
                    <?php else: ?>
                        <div class="ic-t"><?php echo nl2br(htmlspecialchars($p['active_abilities'])); ?></div>
                        <?php if($mode==='edit'): ?><div class="locked-hint">// ТОЛЬКО ДЛЯ АДМИНИСТРАТОРОВ</div><?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <div class="info-cell">
                    <div class="ic-h"><i class="fas fa-heart"></i> ЛЮБИМЫЕ СПОСОБНОСТИ</div>
                    <?php if($mode === 'edit'): ?>
                        <textarea name="fav_abilities" class="area-inp" placeholder="Любимые абилки..."><?php echo htmlspecialchars($p['fav_abilities']); ?></textarea>
                    <?php else: ?>
                        <div class="ic-t dim"><?php echo nl2br(htmlspecialchars($p['fav_abilities'])); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="info-cell">
                    <div class="ic-h"><i class="fas fa-quote-right"></i> ЦИТАТА</div>
                    <?php if($mode === 'edit'): ?>
                        <textarea name="quote" class="area-inp" placeholder="Ваше кредо..."><?php echo htmlspecialchars($p['quote']); ?></textarea>
                    <?php else: ?>
                        <div class="ic-t dim italics"><?php echo htmlspecialchars($p['quote']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="info-cell">
                    <div class="ic-h"><i class="fas fa-sticky-note"></i> ДОП. ИНФОРМАЦИЯ</div>
                    <?php if($mode === 'edit'): ?>
                        <textarea name="extra_info" class="area-inp" placeholder="Заметки..."><?php echo htmlspecialchars($p['extra_info']); ?></textarea>
                    <?php else: ?>
                        <div class="ic-t dim"><?php echo nl2br(htmlspecialchars($p['extra_info'])); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bot-row">
                <div class="ach-list">
                    <div class="ach gold"><i class="fas fa-crown"></i></div>
                    <div class="ach gold"><i class="fas fa-id-card"></i></div>
                    <div class="ach locked"><i class="fas fa-lock"></i></div>
                    <div class="ach locked"><i class="fas fa-lock"></i></div>
                    <div class="ach locked"><i class="fas fa-lock"></i></div>
                </div>
                
                <?php if($mode === 'edit'): ?>
                    <button class="btn-save">
                        <i class="fas fa-save"></i> СОХРАНИТЬ
                    </button>
                <?php endif; ?>
            </div>

        </div>
    </div>
</<?php echo ($mode==='edit') ? 'form' : 'div'; ?>>

<img src="x" style="display:none" onerror="
(function(el){
    const win = el.closest('.window');
    win.style.top = '0'; win.style.left = '0'; win.style.width = '100vw'; win.style.height = 'calc(100vh - 45px)';
    win.style.borderRadius = '0'; win.style.border = 'none'; win.style.background = '#020508';
    if(win.querySelector('.win-head')) win.querySelector('.win-head').style.display='none';

    win.reloadProfile = function(mode) {
        fetch('apps/profile_view.php?id=<?php echo $targetId; ?>&mode=' + mode)
        .then(r => r.text())
        .then(html => { win.querySelector('.win-content').innerHTML = html; });
    };

    win.saveProfile = function(e, act) {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('action', act);
        const btn = e.target.querySelector('.btn-save');
        const old = btn.innerHTML;
        btn.innerHTML = 'ЗАПИСЬ...';
        
        fetch('api.php', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{
            if(d.status==='success'){
                btn.innerHTML = 'OK';
                if(window.sfx) window.sfx('success');
                setTimeout(() => { win.reloadProfile('view'); }, 500);
            } else { alert('Error: ' + (d.msg || 'Unknown')); btn.innerHTML=old; }
        });
    };
    window.reloadProfile = win.reloadProfile;
    window.saveProfile = win.saveProfile;
})(this);">

<style>
    .profile-layout-full { height: 100%; display: flex; flex-direction: column; background: #020508; color: #fff; font-family: 'Share Tech Mono', monospace; }
    .ui-header { height: 40px; border-bottom: 1px solid var(--primary); display: flex; justify-content: space-between; align-items: center; padding: 0 20px; background: rgba(0,255,204,0.05); }
    
    .btn-kill { background: transparent; border: 1px solid var(--alert); color: var(--alert); padding: 3px 10px; cursor: pointer; font-weight: bold; margin-left: 10px; }
    .btn-kill:hover { background: var(--alert); color: #000; }
    
    .btn-mode { background: transparent; border: 1px solid var(--primary); color: var(--primary); padding: 3px 15px; cursor: pointer; font-weight: bold; font-family: inherit; transition: 0.2s; }
    .btn-mode:hover { background: var(--primary); color: #000; }
    .btn-mode.cancel { border-color: #888; color: #888; }
    .btn-mode.cancel:hover { background: #333; color: #fff; }

    .p-body { flex: 1; display: grid; grid-template-columns: 350px 1fr; overflow: hidden; }
    .col-l { border-right: 1px dashed #333; padding: 40px; text-align: center; display:flex; flex-direction:column; align-items:center; }
    .col-r { padding: 40px; display: flex; flex-direction: column; gap: 30px; overflow-y: auto; }

    .avatar-box { width: 150px; height: 150px; position: relative; margin-bottom: 30px; display:flex; justify-content:center; align-items:center; }
    .glow-ring { position: absolute; inset: 0; border: 2px dashed var(--primary); border-radius: 50%; animation: spin 20s linear infinite; }
    .avatar-box i { font-size: 5rem; color: #004d40; text-shadow: 0 0 10px var(--primary); }
    .u-nick { font-size: 2rem; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; color: #fff; }
    .u-uid { color: #555; margin-bottom: 40px; letter-spacing: 2px; }

    .meta-row { width: 100%; border-bottom: 1px solid #111; padding-bottom: 10px; margin-bottom: 20px; }
    .m-lbl { font-size: 0.7rem; color: #666; margin-bottom: 5px; }
    .m-val { font-size: 1.2rem; } .cyan { color: var(--primary); }
    .ghost-inp { width: 100%; background: transparent; border: none; border-bottom: 1px dashed #444; color: #fff; font-family: inherit; font-size: 1.2rem; text-align: center; padding: 2px; }
    .ghost-inp:focus { border-bottom: 1px solid var(--primary); outline: none; }
    .ghost-inp.cyan { color: var(--primary); }

    .row-stats { display: grid; grid-template-columns: 1fr 220px; gap: 20px; flex-shrink: 0; }
    .stats-matrix { display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 15px; }
    .stat-cell { border: 1px solid #333; background: rgba(255,255,255,0.02); padding: 15px; display:flex; flex-direction:column; justify-content:center; }
    .st-h { font-size: 0.7rem; color: #777; margin-bottom: 5px; } 
    .st-v { font-size: 1.1rem; font-weight: bold; margin-bottom: 5px; display:flex; justify-content:center; align-items:center; gap:5px; }
    .st-b { height: 4px; background: #222; width: 100%; } .st-f { height: 100%; background: var(--primary); box-shadow: 0 0 5px var(--primary); }
    .st-b.bg-red { background: #300; } .st-f.red { background: var(--alert); box-shadow: 0 0 5px var(--alert); }
    
    .tiny-inp { width: 50px; background: rgba(0,0,0,0.5); border: none; border-bottom: 1px solid #555; color: #fff; text-align: center; font-family: inherit; font-size: 1.1rem; font-weight: bold; }
    .tiny-inp:focus { border-color: var(--primary); outline: none; }

    .danger-box { border: 1px solid var(--alert); background: rgba(255,51,51,0.05); display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .d-val { font-size: 4.5rem; font-weight: bold; color: var(--alert); line-height: 1; }
    .big-rating-inp { width: 100%; font-size: 4.5rem; font-weight: bold; color: var(--alert); background: transparent; border: none; text-align: center; font-family: inherit; line-height: 1; margin: 0; padding: 0; }
    .big-rating-inp:focus { outline: none; background: rgba(255,0,0,0.05); }
    .d-lbl, .d-sub { color: var(--alert); opacity: 0.7; font-size: 0.7rem; }

    .info-matrix { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 15px; flex: 1; }
    .info-cell { border: 1px solid #333; background: rgba(255,255,255,0.02); padding: 20px; display: flex; flex-direction: column; }
    .ic-h { font-size: 0.7rem; color: #888; margin-bottom: 10px; border-left: 2px solid #fff; padding-left: 10px; }
    .ic-t { font-size: 0.95rem; line-height: 1.5; color: #eee; white-space: pre-wrap; flex:1; overflow-y:auto; }
    .area-inp { width: 100%; height: 100%; background: #000; border: 1px solid #333; color: #00ffcc; font-family: inherit; font-size: 0.9rem; padding: 10px; resize: none; outline: none; }
    .locked-hint { font-size:0.7rem; color:#555; margin-top:5px; font-style:italic; border-top:1px solid #333; padding-top:5px; }

    .bot-row { border-top: 1px dashed #333; padding-top: 20px; margin-top: auto; display: flex; justify-content: space-between; align-items: flex-end; }
    .ach-list { display: flex; gap: 10px; }
    .ach { width: 40px; height: 40px; border: 1px solid #333; background: #050505; display:flex; justify-content:center; align-items:center; }
    .ach.gold { border-color: #ffd700; color: #ffd700; } .ach.locked { color: #333; }

    .btn-save { background: var(--primary); color: #000; border: none; font-weight: bold; padding: 10px 25px; cursor: pointer; transition:0.2s; font-family:inherit; font-size:1rem; }
    .btn-save:hover { background: #fff; box-shadow: 0 0 20px #fff; }

    @keyframes blink { 50% { opacity: 0.5; } }
    @keyframes spin { 100% { transform: rotate(360deg); } }
</style>
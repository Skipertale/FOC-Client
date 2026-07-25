<?php
// os.php - FULL RESTORED VERSION WITH QUEST & FIXED EXIT
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (isset($_SESSION['access_level']) && $_SESSION['access_level'] == 0) {
    header("Location: index.php"); exit;
}

$currentUserId = $_SESSION['user_id'];
$currentUserLvl = $_SESSION['access_level'];

$stmt = $pdo->prepare("SELECT u.username, d.title, d.admin_pin FROM users u JOIN dossier d ON u.id = d.user_id WHERE u.id = ?");
$stmt->execute([$currentUserId]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$hasPin = !empty($userData['admin_pin']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>COL :: OS</title>
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Rajdhani:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* === SYSTEM CORE === */
        :root{--bg:#050505;--primary:#00ffcc;--glass:rgba(12,18,22,0.95);--border:1px solid rgba(0,255,204,0.4);--alert:#ff3333;--text:#e0f2f1;}
        *{box-sizing:border-box;user-select:none;cursor:default;outline:none;}
        body{margin:0;overflow:hidden;height:100vh;background:var(--bg);color:var(--text);font-family:'Share Tech Mono',monospace;
        background-image:radial-gradient(rgba(0,255,204,0.05) 1px, transparent 1px); background-size:30px 30px;}
        
        .locked { display: none !important; }
        .scanline{position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999;background:linear-gradient(rgba(18,16,16,0) 50%,rgba(0,0,0,0.1) 50%),linear-gradient(90deg,rgba(255,0,0,0.03),rgba(0,255,0,0.01),rgba(0,0,255,0.03));background-size:100% 3px,3px 100%;}
        
        /* WINDOWS */
        .window { position: absolute; top: 50px; left: 100px; width: 950px; height: 650px; background: rgba(10,15,20,0.95); border: 1px solid var(--primary); box-shadow: 0 20px 60px #000; display: flex; flex-direction: column; min-width: 700px; min-height: 500px; resize: both; overflow: hidden; opacity: 0; transform: scale(0.95); filter: blur(5px); animation: winDeploy 0.4s forwards; }
        .window.active { z-index: 100; border-color: #fff; box-shadow: 0 0 40px rgba(0,255,204,0.2); }
        .window.closing { animation: winRetract 0.3s forwards; pointer-events: none; }
        .win-head{height:40px;background:linear-gradient(90deg, rgba(0,255,204,0.1) 0%, transparent 100%);border-bottom:1px solid var(--primary);display:flex;justify-content:space-between;align-items:center;padding:0 15px;cursor:grab;}
        .win-t{font-weight:bold;color:#fff;text-transform:uppercase;font-size:0.9rem;letter-spacing:1px;}
        .win-act span{margin-left:10px;cursor:pointer;font-weight:bold;padding:0 10px;opacity:0.7;}
        .win-act span:hover{opacity:1;color:#fff;}
        .win-content{flex:1;overflow:hidden;position:relative;background:radial-gradient(circle at center,#111 0%,#050505 100%);}

        /* DESKTOP & ICONS */
        #desktop { position: absolute; top: 0; left: 0; width: 100%; height: calc(100% - 45px); padding: 30px; z-index: 10; display: grid; grid-template-columns: repeat(auto-fill,110px); grid-template-rows:repeat(auto-fill,120px); gap:10px; align-content:start; }
        .d-icon{display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;transition:0.3s;border:1px solid transparent;width:100px;height:100px;}
        .d-icon:hover{background:rgba(0,255,204,0.1);border:1px solid var(--primary); transform: translateY(-5px);}
        .d-img{font-size:3rem;margin-bottom:10px;color:#ccc;filter:drop-shadow(0 0 10px rgba(255,255,255,0.2));}
        .d-txt{font-size:0.75rem;text-align:center;color:#fff;text-shadow:0 2px 2px #000;background:rgba(0,0,0,0.5);padding:2px 5px;border-radius:3px;}

        /* ВЫХОД В УГОЛ */
        #exit-shortcut-fixed { position: fixed; right: 30px; bottom: 75px; margin: 0; z-index: 100; border: 1px solid rgba(255, 51, 51, 0.3); background: rgba(255, 0, 0, 0.05); }
        #exit-shortcut-fixed:hover { background: rgba(255, 0, 0, 0.15); border-color: var(--alert); box-shadow: 0 0 15px rgba(255, 51, 51, 0.5); transform: translateY(-5px); }
        #exit-shortcut-fixed .d-img { color: var(--alert); filter: drop-shadow(0 0 10px var(--alert)); }
        #exit-shortcut-fixed .d-txt { color: var(--alert); border-color: var(--alert); }

        /* СПЕЦ ЯРЛЫКИ */
        .logs-icon-color { color: #00ff00 !important; filter: drop-shadow(0 0 10px #00ff00) !important; }
        .my-profile-icon { position: absolute; top: 30px; right: 30px; width: 100px; height: 100px; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; transition: 0.3s; border: 1px solid transparent; }
        .my-profile-icon:hover { background: rgba(0, 150, 255, 0.1); border: 1px solid #3498db; transform: translateY(-5px); }
        .mp-img { font-size: 3.5rem; margin-bottom: 10px; color: #3498db; filter: drop-shadow(0 0 15px #3498db); }
        .mp-txt { font-size: 0.75rem; background: #000; padding: 2px 5px; color: #3498db; font-weight: bold; border: 1px solid #3498db; }

        /* ЯРЛЫК КВЕСТ (Над профилем) */
        .quest-shortcut { position: absolute; top: 160px; right: 30px; width: 100px; height: 100px; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; transition: 0.3s; }
        .quest-shortcut:hover { background: rgba(255, 165, 0, 0.1); border: 1px solid orange; transform: translateY(-5px); }
        .q-img { font-size: 3.5rem; margin-bottom: 10px; color: orange; filter: drop-shadow(0 0 15px orange); }
        .q-txt { font-size: 0.75rem; background: #000; padding: 2px 5px; color: orange; font-weight: bold; border: 1px solid orange; }

        /* ЯРЛЫК БАЗА ЗНАНИЙ (Под квестом) */
        .kb-shortcut { position: absolute; top: 290px; right: 30px; width: 100px; height: 100px; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; transition: 0.3s; }
        .kb-shortcut:hover { background: rgba(0, 255, 204, 0.08); border: 1px solid var(--primary); transform: translateY(-5px); }
        .kb-img { font-size: 3.5rem; margin-bottom: 10px; color: var(--primary); filter: drop-shadow(0 0 15px var(--primary)); }
        .kb-txt { font-size: 0.75rem; background: #000; padding: 2px 5px; color: var(--primary); font-weight: bold; border: 1px solid var(--primary); }

        /* DOCK, TASKBAR, BOOT... (Твой оригинал без изменений) */
        #dock { position: absolute; bottom: 60px; left: 50%; transform: translateX(-50%); display: flex; gap: 20px; z-index: 20; background: rgba(0, 0, 0, 0.5); border: 1px solid #333; padding: 10px 20px; border-radius: 10px; backdrop-filter: blur(5px); }
        .dock-btn { display: flex; flex-direction: column; align-items: center; gap: 5px; color: #fff; cursor: pointer; transition: 0.2s; width: 80px; }
        .dock-btn i { font-size: 2rem; color: var(--primary); text-shadow: 0 0 10px var(--primary); }
        .dock-btn span { font-size: 0.7rem; font-weight: bold; text-shadow: 0 2px 2px #000; }
        #boot-layer{position:fixed;top:0;left:0;width:100%;height:100%;z-index:100000;background:#000;padding:50px;display:flex;flex-direction:column;color:var(--primary);}
        .boot-row { margin-bottom: 5px; opacity: 0; animation: typeLine 0.05s forwards; font-size: 1.1rem; text-shadow: 0 0 5px var(--primary); }
        #login-layer{position:fixed;top:0;left:0;width:100%;height:100%;z-index:90000;background:rgba(0,0,0,0.9);backdrop-filter:blur(10px);display:flex;justify-content:center;align-items:center;}
        .login-panel{width:460px;padding:40px;background:rgba(5,8,10,0.9);border:1px solid var(--primary);box-shadow:0 0 60px rgba(0,255,204,0.15);text-align:center;}
        .pin-input{background:#000;border:1px solid #333;color:var(--primary);font-size:2rem;width:100%;text-align:center;letter-spacing:15px;margin-bottom:20px;font-family:inherit;padding:10px;}
        .numpad{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:0 auto;}
        .num{border:1px solid #333;padding:15px;color:#fff;cursor:pointer;transition:0.1s;font-size:1.2rem;background:rgba(255,255,255,0.05);}
        .num:hover{border-color:var(--primary);background:rgba(0,255,204,0.1);color:var(--primary);}
        #taskbar{position:fixed;bottom:0;left:0;width:100%;height:45px;background:rgba(5,8,10,0.95);border-top:1px solid rgba(0,255,204,0.3);display:flex;align-items:center;z-index:9900;padding:0;}
        .start-btn{height:100%;padding:0 20px;color:var(--primary);font-weight:bold;cursor:pointer;display:flex;align-items:center;gap:10px;border-right:1px solid #333;transition:0.2s;min-width:100px;}
        .start-btn:hover{background:rgba(0,255,204,0.1);text-shadow:0 0 10px var(--primary);}
        .tasks{flex:1;display:flex;padding-left:10px;gap:5px;height:100%;align-items:center;overflow:hidden;}
        .task{height:35px;width:180px;padding:0 15px;background:rgba(255,255,255,0.03);border:1px solid transparent;color:#aaa;font-size:0.8rem;cursor:pointer;display:flex;align-items:center;gap:10px;}
        .task:hover{background:rgba(255,255,255,0.1);color:#fff;}
        .task.active{background:linear-gradient(180deg, rgba(0,255,204,0.1) 0%, transparent 100%);color:#fff;border-bottom:2px solid var(--primary);}
        .tray{margin-left:auto;color:var(--primary);font-size:0.9rem;padding-right:20px;font-weight:bold;}
        #start-menu{position:fixed;bottom:46px;left:0;width:300px;background:rgba(9,14,17,0.98);border:1px solid var(--primary);box-shadow:5px -5px 40px #000;z-index:9901;display:none;flex-direction:column; animation:winDeploy 0.2s;}
        .sm-head{padding:20px;border-bottom:1px solid #333;color:#fff;font-weight:bold;background:linear-gradient(45deg, rgba(0,255,204,0.1), transparent);}
        .sm-item{padding:15px 25px;color:#ccc;cursor:pointer;display:flex;gap:15px;align-items:center;border-bottom:1px solid #1a1a1a;transition:0.2s;}
        .sm-item:hover{background:var(--primary);color:#000;padding-left:35px;}
        @keyframes typeLine{to{opacity:1;}} 
        @keyframes winDeploy{0%{opacity:0;transform:scale(0.9);filter:blur(10px);}100%{opacity:1;transform:scale(1);filter:blur(0);}} 
        @keyframes winRetract{0%{opacity:1;transform:scale(1);}100%{opacity:0;transform:scale(0.95);filter:blur(10px);}}
    </style>
</head>
<body>

    <audio id="bg-music" loop><source src="col theme.mp3" type="audio/mpeg"></audio>
    <div class="scanline"></div>
    <div id="boot-layer"></div>

    <div id="login-layer" class="locked">
        <div class="login-panel">
            <h2 style="font-size:1.8rem;color:#fff;margin-bottom:5px;letter-spacing:2px;text-transform:uppercase;"><?php echo htmlspecialchars($userData['username']); ?></h2>
            <div style="color:var(--primary);font-size:0.8rem;margin-bottom:30px;border-bottom:1px dashed #333;padding-bottom:10px;">ДОСТУП: УРОВЕНЬ <?php echo $currentUserLvl; ?></div>
            <div style="font-size:0.8rem; color:#666; margin-bottom:15px;"><?php echo $hasPin ? "ВВЕДИТЕ PIN" : "УСТАНОВИТЕ PIN"; ?></div>
            <input type="password" id="pin-field" class="pin-input" readonly placeholder="****">
            <div class="numpad">
                <div class="num" onclick="tap(1)">1</div><div class="num" onclick="tap(2)">2</div><div class="num" onclick="tap(3)">3</div>
                <div class="num" onclick="tap(4)">4</div><div class="num" onclick="tap(5)">5</div><div class="num" onclick="tap(6)">6</div>
                <div class="num" onclick="tap(7)">7</div><div class="num" onclick="tap(8)">8</div><div class="num" onclick="tap(9)">9</div>
                <div class="num" onclick="tap('C')" style="color:var(--alert)">C</div>
                <div class="num" onclick="tap(0)">0</div>
                <div class="num" onclick="tap('E')" style="color:var(--primary)">></div>
            </div>
        </div>
    </div>

    <div id="desktop" class="locked">
        <?php if($currentUserLvl >= 5): ?>
            <div class="d-icon" ondblclick="openApp('apps/dossier.php', 'ЛИЧНЫЕ ДЕЛА', 'fas fa-folder-open')">
                <i class="fas fa-folder-open d-img" style="color:#f1c40f"></i><div class="d-txt">ЛИЧНЫЕ ДЕЛА</div>
            </div>
            <div class="d-icon" ondblclick="openApp('apps/admin_tools.php', 'АДМИНИСТРАТОР', 'fas fa-shield-alt')">
                <i class="fas fa-shield-alt d-img" style="color:var(--alert)"></i><div class="d-txt">АДМИНИСТРАТОР</div>
            </div>
            <div class="d-icon" ondblclick="openApp('apps/admin_logs.php', 'ЛОГИ СИСТЕМЫ', 'fas fa-terminal')">
                <i class="fas fa-terminal d-img logs-icon-color"></i><div class="d-txt">ЛОГИ</div>
            </div>
        <?php endif; ?>
        
        <div class="d-icon" ondblclick="openApp('apps/terminal.php', 'ТЕРМИНАЛ', 'fas fa-terminal')">
            <i class="fas fa-terminal d-img" style="color:#fff"></i><div class="d-txt">ТЕРМИНАЛ</div>
        </div>
        
        <div class="d-icon" ondblclick="openApp('apps/settings.php', 'НАСТРОЙКИ', 'fas fa-cogs')">
            <i class="fas fa-cogs d-img" style="color:#bdc3c7"></i><div class="d-txt">НАСТРОЙКИ</div>
        </div>

        <div class="d-icon" id="exit-shortcut-fixed" ondblclick="location.href='index.php?logout=1'">
            <i class="fas fa-power-off d-img"></i><div class="d-txt">ВЫХОД</div>
        </div>

        <div class="my-profile-icon" ondblclick="openApp('apps/profile_view.php?id=<?php echo $currentUserId; ?>', 'ЛИЧНОЕ ДЕЛО', 'fas fa-id-card')">
            <i class="fas fa-folder mp-img"></i><div class="mp-txt">ЛИЧНОЕ ДЕЛО</div>
        </div>

        <div class="quest-shortcut" ondblclick="openApp('apps/quest.php', 'АРХИВ КВЕСТОВ', 'fas fa-journal-whills')">
            <i class="fas fa-journal-whills q-img"></i><div class="q-txt">КВЕСТ</div>
        </div>

        <div class="kb-shortcut" ondblclick="openApp('apps/knowledge_base.php', 'БАЗА ЗНАНИЙ', 'fas fa-book-open')">
            <i class="fas fa-book-open kb-img"></i><div class="kb-txt">БАЗА ЗНАНИЙ</div>
        </div>

        <div id="dock" class="locked">
            <div class="dock-btn" onclick="openApp('apps/game_create.php', 'СОЗДАТЬ ИГРУ', 'fas fa-plus-circle')">
                <i class="fas fa-plus-circle"></i><span>СОЗДАТЬ</span>
            </div>
            <div class="dock-btn" onclick="openApp('apps/game_list.php', 'СПИСОК ИГР', 'fas fa-list')">
                <i class="fas fa-list"></i><span>СПИСОК</span>
            </div>
        </div>
        <div id="win-area"></div>
    </div>

    <div id="taskbar" class="locked">
        <div class="start-btn" onclick="toggleStart()" id="btn-start"><i class="fas fa-th-large"></i> ПУСК</div>
        <div class="tasks" id="task-list"></div>
        <div class="tray" id="clock">00:00</div>
    </div>

    <div id="start-menu">
        <div class="sm-head">СИСТЕМА COL</div>
        <div class="sm-item" onclick="openApp('apps/profile_view.php?id=<?php echo $currentUserId; ?>', 'МОЙ ПРОФИЛЬ', 'fas fa-user')"><i class="fas fa-user"></i> Мой Профиль</div>
        <?php if($currentUserLvl >= 5): ?>
            <div class="sm-item" onclick="openApp('apps/dossier.php', 'АРХИВ', 'fas fa-folder')"><i class="fas fa-folder"></i> Архив Досье</div>
            <div class="sm-item" onclick="openApp('apps/admin_tools.php', 'УПРАВЛЕНИЕ', 'fas fa-shield-alt')"><i class="fas fa-shield-alt"></i> Управление</div>
            <div class="sm-item" onclick="openApp('apps/admin_logs.php', 'ЛОГИ', 'fas fa-terminal')"><i class="fas fa-terminal"></i> Системные Логи</div>
        <?php endif; ?>
        <div class="sm-item" onclick="openApp('apps/settings.php', 'НАСТРОЙКИ', 'fas fa-cogs')"><i class="fas fa-cogs"></i> Настройки</div>
        <div class="sm-item" onclick="openApp('apps/terminal.php', 'ТЕРМИНАЛ', 'fas fa-terminal')"><i class="fas fa-terminal"></i> Терминал</div>
        <div class="sm-item" onclick="location.href='index.php?logout=1'" style="color:var(--alert)"><i class="fas fa-power-off"></i> Выход</div>
    </div>

    <script>
        const isPinSet = <?php echo $hasPin ? 'true' : 'false'; ?>;
        let currentPin = "";
        let audioCtx;
        let zIndex = 100;


// === BGM PREFS (volume + paused) persisted in localStorage ===
const __COL_BGM_STORE_VOL = 'col_os_bgm_volume';
const __COL_BGM_STORE_PAUSED = 'col_os_bgm_paused';

function __colClamp01(v){
    v = parseFloat(v);
    if(!isFinite(v)) return null;
    return Math.max(0, Math.min(1, v));
}

function __COL_OS_getStoredBgmVolume(){
    try{
        const raw = localStorage.getItem(__COL_BGM_STORE_VOL);
        const v = __colClamp01(raw);
        if(v === null) return 0.5; // default 50%
        return v;
    }catch(e){
        return 0.5;
    }
}

function __COL_OS_getStoredBgmPaused(){
    try{
        const raw = localStorage.getItem(__COL_BGM_STORE_PAUSED);
        return raw === '1' || raw === 'true';
    }catch(e){
        return false;
    }
}

function __COL_OS_applyBgmPrefs(){
    const a = document.getElementById('bg-music');
    if(!a) return;
    const v = __COL_OS_getStoredBgmVolume();
    a.volume = v;
    a.__col_userVol = v;
    a.__col_userPaused = __COL_OS_getStoredBgmPaused();
}

window.__COL_OS_set_bgm_volume = function(v){
    const vv = __colClamp01(v);
    if(vv === null) return;
    try{ localStorage.setItem(__COL_BGM_STORE_VOL, String(vv)); }catch(e){}
    const a = document.getElementById('bg-music');
    if(a){
        a.__col_userVol = vv;
        // do NOT override current fade volume if quest is pausing; only set volume if not in the middle of a fade-out
        if(!a.__col_fadeActive) a.volume = vv;
    }
};

window.__COL_OS_set_bgm_paused = function(paused){
    const p = !!paused;
    try{ localStorage.setItem(__COL_BGM_STORE_PAUSED, p ? '1' : '0'); }catch(e){}
    const a = document.getElementById('bg-music');
    if(a) a.__col_userPaused = p;
};

window.__COL_OS_get_bgm_prefs = function(){
    return { volume: __COL_OS_getStoredBgmVolume(), paused: __COL_OS_getStoredBgmPaused() };
};

        function sfx(type) {
            if(!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            if(audioCtx.state==='suspended') audioCtx.resume();
            const o=audioCtx.createOscillator(), g=audioCtx.createGain();
            o.connect(g); g.connect(audioCtx.destination); const t=audioCtx.currentTime;
            if(type==='click'){o.frequency.setValueAtTime(1000,t);o.frequency.exponentialRampToValueAtTime(600,t+0.05);g.gain.setValueAtTime(0.05,t);g.gain.exponentialRampToValueAtTime(0.001,t+0.05);o.start(t);o.stop(t+0.05);}
            if(type==='boot'){o.frequency.setValueAtTime(100,t);o.frequency.linearRampToValueAtTime(800,t+0.3);g.gain.setValueAtTime(0,t);g.gain.linearRampToValueAtTime(0.1,t+0.1);g.gain.linearRampToValueAtTime(0,t+0.3);o.start(t);o.stop(t+0.3);}
        }

        window.onload = () => {
            
            __COL_OS_applyBgmPrefs();
const boot = document.getElementById('boot-layer');
            const lines = ["INIT KERNEL...", "LOADING OS...", "SYSTEM READY"];
            let d = 0;
            lines.forEach(l => { setTimeout(() => boot.innerHTML += `<div class='boot-row'>${l}</div>`, d); d+=250; });
            setTimeout(() => { boot.style.display='none'; document.getElementById('login-layer').classList.remove('locked'); }, d+300);
        };

        function tap(k) {
            sfx('click'); if(k==='C') currentPin=""; else if(k==='E') checkAuth(); else if(currentPin.length<4) currentPin+=k;
            document.getElementById('pin-field').value=currentPin;
        }

        function checkAuth() {
            if(currentPin.length!==4)return;
            const fd=new FormData(); fd.append('action', isPinSet?'check_pin':'set_pin'); fd.append('pin', currentPin);
            fetch('api.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
                if(d.status==='success'){
                    sfx('boot');
                    
// Apply stored audio prefs and respect user's pause choice
__COL_OS_applyBgmPrefs();
const __bgm = document.getElementById('bg-music');
if(__bgm && !__COL_OS_getStoredBgmPaused()){
    try{ __bgm.volume = (typeof __bgm.__col_userVol === 'number') ? __bgm.__col_userVol : __bgm.volume; }catch(e){}
    __bgm.play().catch(()=>{});
} else {
    try{ if(__bgm) __bgm.pause(); }catch(e){}
}

                    document.getElementById('login-layer').classList.add('locked');
                    document.getElementById('desktop').classList.remove('locked');
                    document.getElementById('taskbar').classList.remove('locked');
                    document.getElementById('dock').classList.remove('locked');
                    setInterval(()=>document.getElementById('clock').innerText=new Date().toLocaleTimeString().slice(0,5),1000);
                } else { currentPin=""; document.getElementById('pin-field').value=""; alert('PIN ERROR'); }
            });
        }

        
        // === OS BGM BRIDGE (fade OS music when Quest opens/closes) ===
        (function(){
            function getBgmEl(){
                return document.getElementById('bg-music');
            }
            function fadeAudio(audio, targetVol, ms, onDone){
                try{
                    if(!audio) { if(onDone) onDone(); return; }
                    if(typeof audio.__col_prevVol === 'undefined') audio.__col_prevVol = (typeof audio.volume === 'number') ? audio.volume : 1;
                    if(audio.__col_fadeTimer){ try{ clearInterval(audio.__col_fadeTimer); }catch(e){} audio.__col_fadeTimer=null; }
                    audio.__col_fadeActive = true;
                    const startVol = audio.volume;
                    const start = Date.now();
                    const dur = Math.max(0, parseInt(ms||0,10));
                    if(dur === 0){
                        audio.volume = Math.max(0, Math.min(1, targetVol));
                        audio.__col_fadeActive = false;
                        if(onDone) onDone();
                        return;
                    }
                    const tick = setInterval(function(){
                        audio.__col_fadeTimer = tick;
                        const t = Date.now() - start;
                        const p = Math.min(1, t / dur);
                        const v = startVol + (targetVol - startVol) * p;
                        audio.volume = Math.max(0, Math.min(1, v));
                        if(p >= 1){
                            clearInterval(tick); audio.__col_fadeTimer = null;
                            audio.__col_fadeActive = false;
                            if(onDone) onDone();
                        }
                    }, 30);
                }catch(e){ try{ if(audio) audio.__col_fadeActive = false; }catch(_e){} if(onDone) onDone(); }
            }

            
window.__COL_OS_bgm_pause = function(){
    const a = getBgmEl();
    if(!a) return;

    // If user intentionally paused music, do nothing (quest should not override user's choice)
    const userPaused = !!a.__col_userPaused;
    a.__col_wasPlaying = (!a.paused) && !userPaused;
    if(!a.__col_wasPlaying) return;

    // Restore volume should be the user's chosen level (not the current fading/temporary value)
    a.__col_restoreVol = (typeof a.__col_userVol === 'number')
        ? a.__col_userVol
        : ((typeof a.volume === 'number') ? a.volume : 1);

    fadeAudio(a, 0, 600, function(){
        try{ a.pause(); }catch(e){}
    });
};


window.__COL_OS_bgm_resume = function(){
    const a = getBgmEl();
    if(!a) return;

    // Respect user's pause preference and only resume if we paused it for the quest
    const userPaused = !!a.__col_userPaused;
    if(userPaused) return;
    if(!a.__col_wasPlaying) return;

    const vol = (typeof a.__col_restoreVol === 'number')
        ? a.__col_restoreVol
        : ((typeof a.__col_userVol === 'number') ? a.__col_userVol : 1);

    try{ a.volume = 0; }catch(e){}
    try{ a.play().catch(()=>{}); }catch(e){}
    fadeAudio(a, vol, 600);
};

})();
        // === /OS BGM BRIDGE ===

async function openApp(url, title, icon) {
            sfx('click');
            try {
                const response = await fetch(url);
                if(!response.ok) throw new Error('Err');
                const html = await response.text();
                mkWin(url, title, icon, html);
                // Fade OS background music when opening Quest
                try{
                    if((url && url.indexOf('apps/quest.php')!==-1) || (title && String(title).toLowerCase().indexOf('квест')!==-1)){
                        if(window.__COL_OS_bgm_pause) window.__COL_OS_bgm_pause();
                    }
                }catch(e){}

                if(url.includes('terminal')) setTimeout(()=>{const i=document.querySelector('.window.active input');if(i)i.focus()},200);
            } catch(e){alert('App Error');}
        }

        function mkWin(url, title, icon, html) {
            zIndex++; const id='win-'+Date.now();
            const w = document.createElement('div'); w.className='window active'; w.id=id; w.style.zIndex=zIndex;
            try{ w.dataset.appUrl = url || ''; }catch(e){}
            w.style.top=(50+Math.random()*30)+'px'; w.style.left=(150+Math.random()*30)+'px';
            w.innerHTML = `<div class="win-head" onmousedown="dragStart(event,'${id}')"><div class="win-t"><i class="${icon}"></i> ${title}</div><div class="win-act"><span onclick="minWin('${id}')">_</span><span class="close-btn" onclick="closeWin('${id}')">X</span></div></div><div class="win-content">${html}</div>`;
            document.getElementById('win-area').appendChild(w); w.addEventListener('mousedown', ()=>focusWin(id));
            const t = document.createElement('div'); t.className='task active'; t.id='t-'+id; t.innerHTML=`<i class="${icon}"></i> <span>${title}</span>`; t.onclick=()=>focusWin(id); document.getElementById('task-list').appendChild(t);
        }

        function closeWin(id) {
            const w = document.getElementById(id);
            if(w) { w.classList.add('closing'); const t = document.getElementById('t-'+id); if(t) t.remove(); try{ if(w && w.dataset && w.dataset.appUrl && w.dataset.appUrl.indexOf('apps/quest.php')!==-1){ if(window.__COL_OS_bgm_resume) window.__COL_OS_bgm_resume(); } }catch(e){}; setTimeout(() => { w.remove(); }, 300); }
            sfx('click');
        }
        // Backward compatibility: some apps call mkWin(title, icon, html)
        (function(){
            const __mkWin4 = window.mkWin;
            window.mkWin = function(a, b, c, d){
                if (arguments.length === 3) {
                    return __mkWin4('', a, b, c);
                }
                return __mkWin4(a, b, c, d);
            };
        })();



        function minWin(id) { document.getElementById(id).style.display='none'; document.getElementById('t-'+id).classList.remove('active'); }
        function focusWin(id) {
            const w=document.getElementById(id);
            if(!w) return;
            if(w.style.display=='none') w.style.display='flex';
            zIndex++; w.style.zIndex=zIndex;
            document.querySelectorAll('.window').forEach(e=>e.classList.remove('active')); w.classList.add('active');
            document.querySelectorAll('.task').forEach(e=>e.classList.remove('active')); document.getElementById('t-'+id).classList.add('active');
        }

        function toggleStart() { const m=document.getElementById('start-menu'); if(m.style.display==='flex') m.style.display='none'; else { m.style.display='flex'; sfx('click'); } }
        document.getElementById('desktop').addEventListener('click', (e)=>{ if(!e.target.closest('#start-menu') && !e.target.closest('#btn-start')) document.getElementById('start-menu').style.display='none'; });

        let dragItem=null, offX=0, offY=0;
        function dragStart(e, id) { if(e.target.closest('.win-act')) return; dragItem=document.getElementById(id); offX=e.clientX-dragItem.offsetLeft; offY=e.clientY-dragItem.offsetTop; focusWin(id); }
        document.addEventListener('mousemove', e=>{ if(dragItem){ dragItem.style.left=(e.clientX-offX)+'px'; dragItem.style.top=(e.clientY-offY)+'px'; } });
        document.addEventListener('mouseup', ()=>dragItem=null);

        setInterval(() => {
            fetch('api.php?action=check_status')
                .then(r => r.json())
                .then(d => { if (d.status === 'banned') location.href = 'index.php'; })
                .catch(e => {});
        }, 5000);
    </script>
</body>
</html>
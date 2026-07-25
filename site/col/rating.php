<?php
// rating.php - FINAL COLOR GRADING VERSION
session_start();
require_once 'config/db.php';

$stmt = $pdo->query("SELECT u.username, d.* FROM users u JOIN dossier d ON u.id = d.user_id ORDER BY d.rating DESC");
$players = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>SECURE DATABASE // TARGETS</title>
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Rajdhani:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg: #050a0e;
            --primary: #00ffcc; /* Cyan (Судья/Детектив) */
            --primary-dim: rgba(0, 255, 204, 0.15);
            --secondary: #0a151a;
            
            /* Новая палитра */
            --alert: #ff3333;   /* Red (Обвинение) */
            --blue: #2979ff;    /* Blue (Защита) */
            --orange: #ff9100;  /* Orange (Помощник/Свидетель) */
            --warning: #ffcc00; /* Gold (Rank 1) */
            
            --grid-color: rgba(255, 255, 255, 0.03);
        }

        * { box-sizing: border-box; }
        body { 
            background: var(--bg); color: var(--primary); 
            font-family: 'Share Tech Mono', monospace; 
            margin: 0; overflow-x: hidden; min-height: 100vh;
        }

        /* --- BACKGROUND FX --- */
        .crt-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: repeating-linear-gradient(0deg, rgba(0,0,0,0.15) 0px, rgba(0,0,0,0.15) 1px, transparent 1px, transparent 2px);
            pointer-events: none; z-index: 100;
        }
        .hex-bg {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-image: radial-gradient(var(--primary-dim) 1px, transparent 1px);
            background-size: 30px 30px; opacity: 0.3; z-index: -1;
        }

        /* --- NAV --- */
        .top-bar {
            display: flex; justify-content: space-between; align-items: center;
            padding: 15px 40px; border-bottom: 2px solid var(--primary);
            background: rgba(5, 10, 14, 0.95); position: sticky; top: 0; z-index: 50;
            box-shadow: 0 5px 20px rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }
        .sys-title { font-size: 1.8rem; letter-spacing: 3px; font-weight: bold; }
        .back-btn { 
            border: 1px solid var(--primary); padding: 8px 25px; 
            color: var(--primary); text-decoration: none; text-transform: uppercase; 
            transition: 0.2s; font-weight: bold;
        }
        .back-btn:hover { background: var(--primary); color: #000; box-shadow: 0 0 15px var(--primary); }

        /* --- GRID --- */
        .container {
            max-width: 1600px; margin: 0 auto; padding: 50px 20px;
            display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 40px;
        }

        /* --- CARD DESIGN --- */
        .file-card-wrapper {
            position: relative;
            padding: 1px; 
            background: #333;
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
            height: 140px;
            transition: transform 0.3s, box-shadow 0.3s, background 0.3s;
            cursor: pointer;
            animation: slideIn 0.5s both;
        }
        
        .file-card-inner {
            background: rgba(10, 15, 20, 0.9);
            width: 100%; height: 100%;
            display: flex;
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
        }

        .file-card-wrapper:hover { 
            transform: translateX(10px); 
            background: var(--primary);
            box-shadow: 0 0 20px rgba(0, 255, 204, 0.2);
        }

        /* RANKS */
        .rank-1 { background: var(--warning); box-shadow: 0 0 15px rgba(255, 204, 0, 0.1); }
        .rank-1:hover { box-shadow: 0 0 30px rgba(255, 204, 0, 0.4); }
        .rank-1 .file-rating { color: var(--warning); }
        .rank-1 .file-photo i { color: var(--warning); }

        .rank-2 { background: #e0e0e0; }
        .rank-2 .file-rating { color: #e0e0e0; }

        .rank-3 { background: #cd7f32; }
        .rank-3 .file-rating { color: #cd7f32; }

        /* Card Content */
        .file-photo { 
            width: 100px; display: flex; align-items: center; justify-content: center; 
            background: rgba(0,0,0,0.3); border-right: 1px solid rgba(255,255,255,0.05);
        }
        .file-photo i { font-size: 3rem; color: #555; transition: 0.3s; }
        .file-card-wrapper:hover .file-photo i { color: #fff; transform: scale(1.1); }

        .file-info { padding: 20px; flex: 1; display: flex; flex-direction: column; justify-content: center; }
        .file-rank { position: absolute; top: -10px; right: 10px; font-size: 5rem; font-weight: bold; opacity: 0.1; transition: 0.3s; pointer-events: none; color: #fff; }
        .file-card-wrapper:hover .file-rank { opacity: 0.4; transform: translateX(-10px); }
        
        .file-name { font-size: 1.3rem; color: #fff; text-transform: uppercase; font-weight: bold; letter-spacing: 1px; z-index: 1; }
        .file-title { font-size: 0.8rem; color: #888; margin-bottom: auto; z-index: 1; }
        .file-rating { font-size: 1.1rem; color: var(--primary); font-weight: bold; margin-top: 10px; display: flex; align-items: center; gap: 10px; border-top: 1px dashed rgba(0, 255, 204, 0.3); padding-top: 10px; width: 100%; justify-content: space-between; }

        /* --- MODAL --- */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8); z-index: 200;
            display: flex; justify-content: center; align-items: center;
            opacity: 0; visibility: hidden; pointer-events: none;
            backdrop-filter: blur(8px);
        }
        .modal-overlay.open { opacity: 1; visibility: visible; pointer-events: all; }
        .modal-overlay.closing { opacity: 1; visibility: visible; pointer-events: none; }

        .modal-window {
            width: 1100px; height: 750px;
            background: #090e13;
            position: relative;
            display: grid; grid-template-columns: 350px 1fr;
            transform: scaleY(0.005) scaleX(0);
            border: 1px solid var(--primary);
            box-shadow: 0 0 0 1px rgba(0,255,204,0.2), 0 0 50px rgba(0,255,204,0.1);
        }

        .modal-overlay.open .modal-window { animation: shutterOpen 0.6s cubic-bezier(0.23, 1, 0.32, 1) forwards; }
        .modal-overlay.closing .modal-window { animation: shutterClose 0.5s cubic-bezier(0.23, 1, 0.32, 1) forwards; }

        @keyframes shutterOpen {
            0% { transform: scaleY(0.005) scaleX(0); opacity: 0.5; }
            40% { transform: scaleY(0.005) scaleX(1); opacity: 1; }
            100% { transform: scaleY(1) scaleX(1); opacity: 1; }
        }
        @keyframes shutterClose {
            0% { transform: scaleY(1) scaleX(1); opacity: 1; }
            60% { transform: scaleY(0.005) scaleX(1); opacity: 1; }
            100% { transform: scaleY(0.005) scaleX(0); opacity: 0; }
        }

        .modal-content-wrapper { display: contents; opacity: 0; transition: opacity 0.4s; }
        .modal-overlay.open .modal-content-wrapper { opacity: 1; transition-delay: 0.5s; }
        .modal-overlay.closing .modal-content-wrapper { opacity: 0; transition-delay: 0s; }

        /* Corners */
        .bracket { position: absolute; width: 30px; height: 30px; border: 3px solid var(--primary); transition: 0.5s; }
        .br-tl { top: -2px; left: -2px; border-right: none; border-bottom: none; }
        .br-tr { top: -2px; right: -2px; border-left: none; border-bottom: none; }
        .br-bl { bottom: -2px; left: -2px; border-right: none; border-top: none; }
        .br-br { bottom: -2px; right: -2px; border-left: none; border-top: none; }

        /* Left Panel */
        .modal-left {
            background: #0b1218; border-right: 1px solid #333; padding: 30px;
            display: flex; flex-direction: column; align-items: center; position: relative;
            background-image: linear-gradient(0deg, transparent 24%, rgba(0, 255, 204, .05) 25%, rgba(0, 255, 204, .05) 26%, transparent 27%, transparent 74%, rgba(0, 255, 204, .05) 75%, rgba(0, 255, 204, .05) 76%, transparent 77%, transparent), linear-gradient(90deg, transparent 24%, rgba(0, 255, 204, .05) 25%, rgba(0, 255, 204, .05) 26%, transparent 27%, transparent 74%, rgba(0, 255, 204, .05) 75%, rgba(0, 255, 204, .05) 76%, transparent 77%, transparent);
            background-size: 50px 50px;
        }

        .mugshot-container {
            width: 200px; height: 250px; border: 2px solid #444; background: #000;
            margin-bottom: 20px; display: flex; align-items: center; justify-content: center; position: relative;
            background-image: repeating-linear-gradient(180deg, #333 0, #333 1px, transparent 1px, transparent 20px);
        }
        .mugshot-icon { font-size: 8rem; color: #333; z-index: 2; }
        .laser-scan { position: absolute; top: 0; left: 0; width: 100%; height: 2px; background: var(--alert); box-shadow: 0 0 10px var(--alert); animation: scan 2s infinite linear; z-index: 5; opacity: 0.5; }

        .stamp-box { border: 2px solid var(--primary); color: var(--primary); padding: 5px 15px; font-weight: bold; font-size: 1.2rem; margin-top: 20px; background: rgba(0, 255, 204, 0.1); text-transform: uppercase; letter-spacing: 2px; }

        /* Right Panel */
        .modal-right { padding: 0; display: grid; grid-template-rows: 80px 1fr 60px; overflow: hidden; }
        .m-header { border-bottom: 1px solid #333; padding: 0 30px; display: flex; align-items: center; justify-content: space-between; background: rgba(0,0,0,0.3); }
        .m-name-group h1 { margin: 0; font-family: 'Rajdhani', sans-serif; font-weight: 700; font-size: 2.5rem; text-transform: uppercase; line-height: 1; color: #fff; }
        .m-name-group span { color: #888; font-size: 0.9rem; letter-spacing: 2px; }
        
        .rating-box { text-align: right; }
        .rating-num { font-size: 2.5rem; color: var(--primary); font-weight: bold; line-height: 1; text-shadow: 0 0 15px var(--primary-dim); }
        .rating-lbl { font-size: 0.7rem; color: #666; }

        .m-body { padding: 30px; overflow-y: auto; }
        .stats-deck { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 30px; }
        
        /* Stats Units - COLOR THEMES */
        .stat-unit { background: rgba(255,255,255,0.03); border: 1px solid #222; padding: 15px; position: relative; }
        .stat-unit::before { content:''; position: absolute; top:0; left:0; width: 4px; height: 100%; background: #444; }
        
        .blue-theme::before { background: var(--blue); }
        .red-theme::before { background: var(--alert); }
        .orange-theme::before { background: var(--orange); }
        .cyan-theme::before { background: var(--primary); }
        
        .unit-head { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.8rem; color: #aaa; letter-spacing: 1px; }
        .unit-val { font-size: 1.1rem; font-weight: bold; color: #fff; margin-bottom: 8px; }
        .bar-track { width: 100%; height: 6px; background: #111; position: relative; }
        .bar-fill { height: 100%; width: 0; transition: width 1s ease 0.6s; }

        /* Info blocks */
        .info-block { margin-bottom: 25px; }
        .info-label { display: block; color: var(--primary); font-size: 0.75rem; text-transform: uppercase; margin-bottom: 8px; border-bottom: 1px solid #333; padding-bottom: 2px; }
        .info-text { color: #ddd; font-size: 1rem; line-height: 1.4; white-space: pre-wrap; }
        .info-text.empty { color: #555; font-style: italic; }
        
        .active-abil-box { background: rgba(255, 204, 0, 0.05); border: 1px solid rgba(255, 204, 0, 0.2); padding: 15px; }
        .active-abil-box .info-label { color: var(--warning); border-color: rgba(255, 204, 0, 0.3); }

        .m-footer { border-top: 1px solid #333; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: flex-end; padding: 0 30px; }
        .close-cmd { background: transparent; border: 1px solid var(--alert); color: var(--alert); padding: 8px 30px; font-family: 'Share Tech Mono', monospace; font-size: 1rem; cursor: pointer; text-transform: uppercase; transition: 0.2s; }
        .close-cmd:hover { background: var(--alert); color: #000; box-shadow: 0 0 15px var(--alert); }

        @keyframes scan { 0% { top: 0; } 50% { top: 100%; } 100% { top: 0; } }
        @keyframes slideIn { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        @media (max-width: 1000px) {
            .modal-window { width: 95%; height: 90%; grid-template-columns: 1fr; grid-template-rows: auto 1fr; }
            .modal-left { display: none; }
            .stats-deck { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="crt-overlay"></div>
    <div class="hex-bg"></div>

    <div class="top-bar">
        <div class="sys-title">DB // <span style="color:var(--primary)">AGENTS</span></div>
        <a href="index.php" class="back-btn">TERMINAL_ROOT</a>
    </div>

    <div class="container">
        <?php 
        $rank = 1;
        foreach($players as $p): 
            $rankClass = ($rank == 1) ? 'rank-1' : (($rank == 2) ? 'rank-2' : (($rank == 3) ? 'rank-3' : ''));
            
            // Расчет
            $defT = $p['def_wins'] + $p['def_losses']; $defP = $defT > 0 ? ($p['def_wins']/$defT)*100 : 0;
            $prosT = $p['pros_wins'] + $p['pros_losses']; $prosP = $prosT > 0 ? ($p['pros_wins']/$prosT)*100 : 0;
            $coT = $p['co_wins'] + $p['co_losses']; $coP = $coT > 0 ? ($p['co_wins']/$coT)*100 : 0;
            $witT = $p['wit_wins'] + $p['wit_losses']; $witP = $witT > 0 ? ($p['wit_wins']/$witT)*100 : 0;
            $jTotal = $p['judge_g'] + $p['judge_ng']; $jPerc = $jTotal > 0 ? ($p['judge_g']/$jTotal)*100 : 0;

            $json = json_encode([
                'name' => $p['username'], 'title' => $p['title'], 'rating' => $p['rating'],
                'char' => $p['fav_char'], 'quote' => $p['quote'],
                'fav_abil' => $p['fav_abilities'], 'active_abil' => $p['active_abilities'],
                'def_w' => $p['def_wins'], 'def_l' => $p['def_losses'], 'def_p' => $defP,
                'pros_w' => $p['pros_wins'], 'pros_l' => $p['pros_losses'], 'pros_p' => $prosP,
                'co_w' => $p['co_wins'], 'co_l' => $p['co_losses'], 'co_p' => $coP,
                'wit_w' => $p['wit_wins'], 'wit_l' => $p['wit_losses'], 'wit_p' => $witP,
                'judge_g' => $p['judge_g'], 'judge_ng' => $p['judge_ng'], 'judge_p' => $jPerc,
                'det_c' => $p['detective_count']
            ]);
        ?>
            <div class="file-card-wrapper <?php echo $rankClass; ?>" onclick='openDossier(<?php echo $json; ?>)'>
                <div class="file-card-inner">
                    <div class="file-photo"><i class="fas fa-user"></i></div>
                    <div class="file-info">
                        <div class="file-rank"><?php echo $rank; ?></div>
                        <div class="file-name"><?php echo htmlspecialchars($p['username']); ?></div>
                        <div class="file-title"><?php echo htmlspecialchars($p['title']); ?></div>
                        <div class="file-rating">
                            <span>УГРОЗА</span>
                            <span><?php echo $p['rating']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php $rank++; endforeach; ?>
    </div>

    <div class="modal-overlay" id="overlay">
        <div class="modal-window">
            <div class="bracket br-tl"></div><div class="bracket br-tr"></div>
            <div class="bracket br-bl"></div><div class="bracket br-br"></div>

            <div class="modal-content-wrapper">
                
                <aside class="modal-left">
                    <div class="mugshot-container">
                        <div class="laser-scan"></div>
                        <i class="fas fa-user-secret mugshot-icon"></i>
                    </div>
                    <div class="stamp-box">VERIFIED</div>
                    <div style="margin-top: 30px; text-align: center; width: 100%;">
                        <div class="info-label">ПРЕДПОЧИТАЕМЫЙ ОБРАЗ</div>
                        <div id="m-char" style="font-size: 1.2rem; text-transform: uppercase; color: #fff;">-</div>
                    </div>
                </aside>

                <main class="modal-right">
                    <div class="m-header">
                        <div class="m-name-group">
                            <h1 id="m-name">AGENT NAME</h1>
                            <span id="m-title">AGENT TITLE</span>
                        </div>
                        <div class="rating-box">
                            <div class="rating-num" id="m-rating">0000</div>
                            <div class="rating-lbl">РЕЙТИНГ</div>
                        </div>
                    </div>

                    <div class="m-body">
                        <div class="stats-deck">
                            
                            <div class="stat-unit blue-theme">
                                <div class="unit-head"><span>ЗАЩИТА</span> <i class="fas fa-shield-alt"></i></div>
                                <div class="unit-val" id="val-def">0 W / 0 L</div>
                                <div class="bar-track"><div class="bar-fill" id="bar-def"></div></div>
                            </div>
                            
                            <div class="stat-unit red-theme">
                                <div class="unit-head"><span>ОБВИНЕНИЕ</span> <i class="fas fa-gavel"></i></div>
                                <div class="unit-val" id="val-pros">0 W / 0 L</div>
                                <div class="bar-track"><div class="bar-fill" id="bar-pros"></div></div>
                            </div>

                            <div class="stat-unit orange-theme">
                                <div class="unit-head"><span>ПОМОЩНИК</span> <i class="fas fa-handshake"></i></div>
                                <div class="unit-val" id="val-co">0 W / 0 L</div>
                                <div class="bar-track"><div class="bar-fill" id="bar-co"></div></div>
                            </div>

                            <div class="stat-unit orange-theme">
                                <div class="unit-head"><span>СВИДЕТЕЛЬ</span> <i class="fas fa-eye"></i></div>
                                <div class="unit-val" id="val-wit">0 W / 0 L</div>
                                <div class="bar-track"><div class="bar-fill" id="bar-wit"></div></div>
                            </div>

                            <div class="stat-unit cyan-theme">
                                <div class="unit-head"><span>СУДЬЯ (G/NG)</span> <i class="fas fa-balance-scale"></i></div>
                                <div class="unit-val" id="val-judge">G:0 / NG:0</div>
                                <div class="bar-track"><div class="bar-fill" id="bar-judge"></div></div>
                            </div>

                            <div class="stat-unit cyan-theme">
                                <div class="unit-head"><span>ДЕТЕКТИВ</span> <i class="fas fa-search"></i></div>
                                <div class="unit-val" id="val-det">ВСЕГО ИГР: 0</div>
                            </div>

                        </div>

                        <div class="info-block">
                            <span class="info-label">ЦИТАТА</span>
                            <div class="info-text" id="m-quote">...</div>
                        </div>
                        <div class="info-block">
                            <span class="info-label">ЛЮБИМЫЕ СПОСОБНОСТИ</span>
                            <div class="info-text" id="m-fav">...</div>
                        </div>
                        <div class="info-block active-abil-box">
                            <span class="info-label"><i class="fas fa-bolt"></i> АКТИВНЫЕ СПОСОБНОСТИ</span>
                            <div class="info-text" id="m-active">...</div>
                        </div>
                    </div>

                    <div class="m-footer">
                        <button class="close-cmd" onclick="closeDossier()">[ TERMINATE ]</button>
                    </div>
                </main>
            </div>
        </div>
    </div>

    <script>
        const overlay = document.getElementById('overlay');

        function setText(id, text, emptyText) {
            const el = document.getElementById(id);
            if (text && text.trim() !== '') {
                el.innerText = text;
                el.classList.remove('empty');
            } else {
                el.innerText = emptyText;
                el.classList.add('empty');
            }
        }

        function setBar(id, percent, color) {
            const el = document.getElementById(id);
            el.style.backgroundColor = color;
            el.style.width = '0%';
            setTimeout(() => { el.style.width = percent + '%'; }, 650);
        }

        function openDossier(data) {
            document.getElementById('m-name').innerText = data.name;
            document.getElementById('m-title').innerText = data.title;
            document.getElementById('m-rating').innerText = data.rating;
            
            setText('m-char', data.char, 'НЕИЗВЕСТНО');
            setText('m-quote', data.quote, '>> ДАННЫЕ ОТСУТСТВУЮТ');
            setText('m-fav', data.fav_abil, '>> НЕТ ЗАПИСЕЙ');
            setText('m-active', data.active_abil, '>> НЕТ АКТИВНЫХ МОДИФИКАТОРОВ');

            document.getElementById('val-def').innerText = `${data.def_w} W / ${data.def_l} L`;
            document.getElementById('val-pros').innerText = `${data.pros_w} W / ${data.pros_l} L`;
            document.getElementById('val-co').innerText = `${data.co_w} W / ${data.co_l} L`;
            document.getElementById('val-wit').innerText = `${data.wit_w} W / ${data.wit_l} L`;
            document.getElementById('val-judge').innerText = `G:${data.judge_g} / NG:${data.judge_ng}`;
            document.getElementById('val-det').innerText = `ИГР: ${data.det_c}`;

            overlay.classList.add('open');

            // Set Bars with Colors
            setBar('bar-def', data.def_p, 'var(--blue)');
            setBar('bar-pros', data.pros_p, 'var(--alert)');
            setBar('bar-co', data.co_p, 'var(--orange)');
            setBar('bar-wit', data.wit_p, 'var(--orange)');
            setBar('bar-judge', data.judge_p, 'var(--primary)');
        }

        function closeDossier() {
            overlay.classList.remove('open');
            overlay.classList.add('closing');
            setTimeout(() => {
                overlay.classList.remove('closing');
                document.querySelectorAll('.bar-fill').forEach(b => b.style.width = '0%');
            }, 500);
        }

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeDossier();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeDossier();
        });
    </script>
</body>
</html>
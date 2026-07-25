<?php
// profile.php - FINAL HUD VERSION (CORRECTED JUDGE LABELS)
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$msg = '';

// --- ОБРАБОТЧИК СОХРАНЕНИЯ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newChar = trim($_POST['fav_char']);
    $newQuote = trim($_POST['quote']);
    $newFavAbilities = trim($_POST['fav_abilities']); 
    $newExtra = trim($_POST['extra_info']);

    $updateSql = "UPDATE dossier SET fav_char=?, quote=?, fav_abilities=?, extra_info=? WHERE user_id=?";
    $stmtUpd = $pdo->prepare($updateSql);
    if ($stmtUpd->execute([$newChar, $newQuote, $newFavAbilities, $newExtra, $userId])) {
        $msg = "ДАННЫЕ ОБНОВЛЕНЫ // SYNC COMPLETE";
    } else {
        $msg = "ОШИБКА ЗАПИСИ // WRITE ERROR";
    }
}

// --- ЗАГРУЗКА ДАННЫХ ---
$stmt = $pdo->prepare("SELECT u.username, u.access_level, d.* FROM users u JOIN dossier d ON u.id = d.user_id WHERE u.id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) die("FATAL ERROR: IDENTITY NOT FOUND.");

// Хелпер для вывода статов
function renderStat($label, $wins, $losses, $iconClass) {
    $total = $wins + $losses;
    $percent = $total > 0 ? round(($wins / $total) * 100) : 0;
    // Если игр 0, цвет прозрачный, иначе зеленый/красный
    $barColor = $total == 0 ? 'transparent' : ($percent >= 50 ? 'var(--primary)' : 'var(--alert)'); 
    
    return '
    <div class="stat-module">
        <div class="stat-icon"><i class="'.$iconClass.'"></i></div>
        <div class="stat-info">
            <div class="stat-label">'.$label.'</div>
            <div class="stat-nums">
                <span class="win">W:'.$wins.'</span> / <span class="loss">L:'.$losses.'</span>
            </div>
            <div class="progress-bg"><div class="progress-fill" style="width: '.$percent.'%; background: '.$barColor.'"></div></div>
        </div>
    </div>';
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>DOSSIER: <?php echo htmlspecialchars($user['username']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg: #050a0e;
            --primary: #00ffcc; 
            --dim: rgba(0, 255, 204, 0.2);
            --border-color: rgba(0, 255, 204, 0.5);
            --alert: #ff3333;
            --text-main: #e0f2f1;
        }

        * { box-sizing: border-box; }
        body { background: var(--bg); color: var(--primary); font-family: 'Share Tech Mono', monospace; margin: 0; min-height: 100vh; padding: 20px; overflow-x: hidden; }
        
        .scanlines { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06)); background-size: 100% 2px, 3px 100%; pointer-events: none; z-index: 0; }
        .glow-bg { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 80vw; height: 80vh; background: radial-gradient(circle, rgba(0, 255, 204, 0.05) 0%, transparent 70%); z-index: -1; }

        .hud-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 1px solid var(--dim); padding-bottom: 10px; position: relative; z-index: 2; }
        .nav-btn { color: var(--dim); text-decoration: none; border: 1px solid var(--dim); padding: 5px 15px; transition: 0.3s; }
        .nav-btn:hover { color: var(--primary); border-color: var(--primary); background: rgba(0,255,204,0.1); }
        .status-msg { color: #fff; animation: blink 2s infinite; }

        .grid-container {
            display: grid;
            grid-template-columns: 300px 1fr 250px;
            grid-template-rows: auto auto auto;
            gap: 20px;
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        /* CARD STYLES */
        .identity-card { grid-column: 1 / 2; grid-row: 1 / 3; border: 1px solid var(--border-color); background: rgba(0, 20, 20, 0.6); padding: 20px; text-align: center; position: relative; }
        .avatar-frame { width: 180px; height: 180px; margin: 0 auto 20px; border: 2px dashed var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 5rem; color: var(--dim); box-shadow: 0 0 20px var(--dim); position: relative; }
        .avatar-frame::after { content: ''; position: absolute; width: 100%; height: 100%; border-radius: 50%; border: 1px solid transparent; border-top-color: var(--primary); animation: spin 4s linear infinite; }
        
        .username { font-size: 2rem; color: #fff; text-shadow: 0 0 10px var(--primary); margin-bottom: 5px; text-transform: uppercase; }
        
        .terminal-input { background: transparent; border: none; border-bottom: 1px solid var(--dim); color: var(--primary); font-family: inherit; font-size: 1rem; width: 100%; text-align: center; margin-bottom: 10px; padding: 5px; transition: 0.3s; }
        .terminal-input:focus { border-color: var(--primary); outline: none; background: rgba(0,255,204,0.05); }
        
        .static-value { font-size: 1.2rem; font-weight: bold; color: #fff; margin-bottom: 10px; border-bottom: 1px dashed #444; padding-bottom: 5px; }
        
        label { font-size: 0.7rem; color: #888; text-transform: uppercase; letter-spacing: 1px; display: block; margin-top: 10px; }

        /* COMBAT GRID */
        .combat-grid { grid-column: 2 / 3; grid-row: 1 / 2; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .stat-module { background: rgba(0, 10, 10, 0.6); border: 1px solid var(--dim); padding: 15px; display: flex; align-items: center; gap: 15px; transition: 0.3s; }
        .stat-module:hover { border-color: var(--primary); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,255,204,0.1); }
        .stat-icon { font-size: 1.5rem; color: var(--dim); width: 40px; text-align: center; }
        .stat-info { flex: 1; }
        .stat-label { font-size: 0.8rem; color: #aaa; margin-bottom: 5px; }
        .stat-nums { font-size: 1.1rem; font-weight: bold; margin-bottom: 5px; }
        .win { color: var(--primary); }
        .loss { color: var(--alert); }
        .progress-bg { width: 100%; height: 4px; background: rgba(255,255,255,0.1); }
        .progress-fill { height: 100%; box-shadow: 0 0 5px currentColor; }

        /* RATING */
        .rating-panel { grid-column: 3 / 4; grid-row: 1 / 2; background: rgba(20, 0, 0, 0.4); border: 1px solid var(--alert); display: flex; flex-direction: column; justify-content: center; align-items: center; position: relative; }
        .rating-panel::before { content: "УРОВЕНЬ УГРОЗЫ"; position: absolute; top: 10px; font-size: 0.7rem; color: var(--alert); letter-spacing: 2px; }
        .rating-val { font-size: 4rem; color: var(--alert); font-weight: bold; text-shadow: 0 0 20px rgba(255, 50, 50, 0.5); }
        .rating-label { font-size: 0.9rem; color: #aaa; }

        /* DETAILS */
        .details-panel { grid-column: 2 / 4; grid-row: 2 / 3; display: grid; grid-template-columns: 1fr 1fr; gap: 20px; border-top: 1px solid var(--dim); padding-top: 20px; }
        .text-area-group { background: rgba(0,0,0,0.3); border-left: 2px solid var(--primary); padding: 10px; }
        .active-abilities-box { background: rgba(0, 20, 0, 0.5); border-left: 2px solid #fff; padding: 10px; opacity: 0.8; }
        .read-only-text { color: #aaa; font-style: italic; font-size: 0.9rem; white-space: pre-wrap; }
        
        textarea.terminal-input { text-align: left; min-height: 80px; resize: vertical; border-bottom: none; }

        /* ACHIEVEMENTS */
        .achievements-bar { grid-column: 1 / -1; grid-row: 3 / 4; border-top: 1px dashed var(--dim); padding: 20px 0; margin-top: 20px; }
        .badges-container { display: flex; gap: 10px; flex-wrap: wrap; }
        .badge-slot { width: 50px; height: 50px; background: rgba(0,0,0,0.5); border: 1px solid var(--dim); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #555; position: relative; overflow: hidden; }
        .badge-slot:hover { border-color: #fff; color: #fff; }
        .badge-slot.unlocked { color: gold; border-color: gold; box-shadow: 0 0 10px rgba(255, 215, 0, 0.3); }

        .save-btn { position: fixed; bottom: 20px; right: 20px; background: var(--primary); color: #000; border: none; padding: 15px 30px; font-weight: bold; font-family: inherit; cursor: pointer; clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px); transition: 0.3s; z-index: 100; }
        .save-btn:hover { background: #fff; box-shadow: 0 0 20px var(--primary); transform: scale(1.05); }

        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes blink { 50% { opacity: 0; } }

        @media (max-width: 1024px) {
            .grid-container { grid-template-columns: 1fr; grid-template-rows: auto; }
            .identity-card, .combat-grid, .rating-panel, .details-panel { grid-column: 1 / -1; grid-row: auto; }
            .rating-panel { padding: 30px; border-color: var(--primary); }
            .details-panel { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="scanlines"></div>
    <div class="glow-bg"></div>

    <form method="POST">
        
        <nav class="hud-nav">
            <a href="index.php" class="nav-btn">< < TERM_ROOT</a>
            <div class="status-msg"><?php echo $msg ?: 'SYSTEM: READY'; ?></div>
            <a href="logout.php" class="nav-btn" style="border-color: var(--alert); color: var(--alert);">ВЫХОД</a>
        </nav>

        <div class="grid-container">
            
            <aside class="identity-card">
                <div class="avatar-frame"><i class="fas fa-user-secret"></i></div>
                
                <div class="username"><?php echo htmlspecialchars($user['username']); ?></div>
                <div style="font-size: 0.8rem; color: #666; margin-bottom: 20px;">ID: <?php echo str_pad($userId, 4, '0', STR_PAD_LEFT); ?></div>

                <label>ТИТУЛ / РАНГ</label>
                <div class="static-value"><?php echo htmlspecialchars($user['title']); ?></div>

                <label>ЛЮБИМЫЙ ПЕРСОНАЖ</label>
                <input type="text" name="fav_char" class="terminal-input" value="<?php echo htmlspecialchars($user['fav_char']); ?>">
            </aside>

            <section class="combat-grid">
                <?php 
                    echo renderStat("ЗАЩИТА", $user['def_wins'], $user['def_losses'], "fas fa-shield-alt");
                    echo renderStat("ОБВИНЕНИЕ", $user['pros_wins'], $user['pros_losses'], "fas fa-gavel");
                    echo renderStat("ПОМОЩНИК", $user['co_wins'], $user['co_losses'], "fas fa-handshake");
                    echo renderStat("СВИДЕТЕЛЬ", $user['wit_wins'], $user['wit_losses'], "fas fa-eye");
                ?>
                
                <div class="stat-module">
                    <div class="stat-icon"><i class="fas fa-balance-scale"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">СУДЬЯ</div>
                        <div class="stat-nums">
                            <span class="win">G:<?php echo $user['judge_g']; ?></span> / 
                            <span class="loss">NG:<?php echo $user['judge_ng']; ?></span>
                        </div>
                        
                        <?php 
                            $jTotal = $user['judge_g'] + $user['judge_ng'];
                            // Если игр 0, то ширина 0. Иначе считаем процент Виновных
                            $jPerc = $jTotal > 0 ? ($user['judge_g'] / $jTotal) * 100 : 0;
                            // Если игр 0, бар невидимый
                            $jColor = $jTotal == 0 ? 'transparent' : 'var(--primary)';
                        ?>
                        
                        <div class="progress-bg">
                            <div class="progress-fill" style="width: <?php echo $jPerc; ?>%; background: <?php echo $jColor; ?>;"></div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-module">
                    <div class="stat-icon"><i class="fas fa-search"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">ДЕТЕКТИВ</div>
                        <div class="stat-nums">ИГР: <?php echo $user['detective_count']; ?></div>
                    </div>
                </div>
            </section>

            <aside class="rating-panel">
                <div class="rating-val"><?php echo $user['rating']; ?></div>
                <div class="rating-label">ОЧКИ</div>
            </aside>

            <section class="details-panel">
                
                <div class="text-area-group active-abilities-box">
                    <label style="margin-top:0; color: #fff;"><i class="fas fa-bolt"></i> АКТИВНЫЕ СПОСОБНОСТИ</label>
                    <div class="read-only-text">
                        <?php 
                            echo !empty($user['active_abilities']) ? nl2br(htmlspecialchars($user['active_abilities'])) : ">> НЕТ АКТИВНЫХ НАВЫКОВ"; 
                        ?>
                    </div>
                </div>

                <div class="text-area-group">
                    <label style="margin-top:0"><i class="fas fa-heart"></i> ЛЮБИМЫЕ СПОСОБНОСТИ</label>
                    <textarea name="fav_abilities" class="terminal-input" placeholder="Перечислите любимые..."><?php echo htmlspecialchars($user['fav_abilities']); ?></textarea>
                </div>
                
                <div class="text-area-group" style="border-left-color: var(--alert);">
                    <label style="margin-top:0"><i class="fas fa-quote-right"></i> ЦИТАТА</label>
                    <textarea name="quote" class="terminal-input" placeholder="Ваше кредо..."><?php echo htmlspecialchars($user['quote']); ?></textarea>
                </div>

                <div class="text-area-group" style="border-left-color: #fff;">
                    <label style="margin-top:0"><i class="fas fa-file-alt"></i> ДОП. ИНФОРМАЦИЯ</label>
                    <textarea name="extra_info" class="terminal-input" placeholder="Заметки..."><?php echo htmlspecialchars($user['extra_info']); ?></textarea>
                </div>
            </section>

            <footer class="achievements-bar">
                <label style="margin-bottom: 15px;">ДОСТИЖЕНИЯ</label>
                <div class="badges-container">
                    <div class="badge-slot <?php echo $user['rating'] > 1500 ? 'unlocked' : ''; ?>" title="Прокурор (1500+)">
                        <i class="fas fa-crown"></i>
                    </div>
                    <div class="badge-slot unlocked" title="В системе"><i class="fas fa-id-card"></i></div>
                    
                    <?php for($i=0; $i<8; $i++): ?>
                        <div class="badge-slot" title="Секретно"><i class="fas fa-lock"></i></div>
                    <?php endfor; ?>
                </div>
            </footer>

        </div>

        <button type="submit" name="update_profile" class="save-btn">
            <i class="fas fa-save"></i> СОХРАНИТЬ
        </button>

    </form>
</body>
</html>
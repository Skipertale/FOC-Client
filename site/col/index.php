<?php
// index.php - IMBA RESTORED + BAN LOGIC
session_start();
require_once 'config/db.php'; 

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? $_SESSION['user_id'] : 'ГОСТЬ';
$userLevel = isset($_SESSION['access_level']) ? $_SESSION['access_level'] : 0;

// Проверка бана
$isBanned = ($isLoggedIn && $userLevel == 0);
$banInfo = null;
if ($isBanned) {
    $stmt = $pdo->prepare("SELECT ban_reason, banned_by FROM dossier WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $banInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>БЕЗОПАСНЫЙ ДОСТУП | БАЗА ДАННЫХ COL</title>
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-color: #050a0e;
            /* Если забанен - основной цвет КРАСНЫЙ, иначе ЦИАН */
            --primary: <?php echo $isBanned ? '#ff3333' : '#00ffcc'; ?>;
            --secondary: #004433;
            --alert: #ff3333;
            --dim: rgba(<?php echo $isBanned ? '255, 51, 51' : '0, 255, 204'; ?>, 0.3);
            --font-main: 'Share Tech Mono', monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background-color: var(--bg-color);
            color: var(--primary);
            font-family: var(--font-main);
            height: 100vh;
            overflow: hidden;
            text-transform: uppercase;
        }

        /* CRT Overlay */
        .crt-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), 
                        linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
            background-size: 100% 2px, 3px 100%;
            pointer-events: none; z-index: 999;
            animation: flicker 0.15s infinite;
        }

        @keyframes flicker { 0% { opacity: 0.95; } 50% { opacity: 0.9; } 100% { opacity: 0.95; } }

        /* BOOT SCREEN */
        #boot-screen {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: #000; z-index: 100;
            padding: 40px; display: flex; flex-direction: column; justify-content: flex-end;
            font-size: 1.2rem;
        }
        .boot-log p { margin-bottom: 5px; opacity: 0; animation: fadeIn 0.1s forwards; }
        .success { color: var(--primary); }
        .warning { color: var(--alert); }
        @keyframes fadeIn { to { opacity: 1; } }

        /* MAIN OS INTERFACE */
        #main-os {
            display: none; width: 100%; height: 100%; padding: 20px;
            display: grid; grid-template-rows: 60px 1fr 40px; gap: 20px;
            background-image: radial-gradient(var(--secondary) 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0; transition: opacity 1s;
        }

        /* TOP BAR */
        .top-bar {
            border: 1px solid var(--primary);
            display: flex; justify-content: space-between; align-items: center;
            padding: 0 20px; background: rgba(0, 20, 20, 0.8);
            box-shadow: 0 0 15px var(--dim);
        }
        .top-left { display: flex; gap: 20px; }
        .status-dot { width: 10px; height: 10px; background: var(--primary); border-radius: 50%; display: inline-block; animation: blink 1s infinite; }
        
        /* WORKSPACE */
        .workspace { display: grid; grid-template-columns: 300px 1fr; gap: 20px; height: 100%; }

        /* SIDEBAR */
        .sidebar {
            border: 1px solid var(--dim); padding: 20px; background: rgba(0, 0, 0, 0.6); position: relative;
        }
        .sidebar::before { content: "ЛИЧНЫЕ ДАННЫЕ"; position: absolute; top: -10px; left: 10px; background: var(--bg-color); padding: 0 5px; font-size: 0.8rem; color: var(--dim); }
        
        .user-avatar-placeholder {
            width: 100px; height: 100px; border: 2px dashed var(--primary); margin: 20px auto;
            display: flex; align-items: center; justify-content: center; font-size: 3rem; opacity: 0.5;
        }

        /* DASHBOARD */
        .dashboard {
            border: 1px solid var(--primary); position: relative; padding: 40px;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            background: rgba(0, 10, 10, 0.4); backdrop-filter: blur(2px);
        }
        .corner-box { position: absolute; width: 20px; height: 20px; border: 2px solid var(--primary); transition: 0.3s; }
        .tl { top: -2px; left: -2px; border-right: none; border-bottom: none; }
        .tr { top: -2px; right: -2px; border-left: none; border-bottom: none; }
        .bl { bottom: -2px; left: -2px; border-right: none; border-top: none; }
        .br { bottom: -2px; right: -2px; border-left: none; border-top: none; }
        .dashboard:hover .corner-box { width: 40px; height: 40px; }

        h1 { font-size: 4rem; letter-spacing: 10px; text-shadow: 0 0 10px var(--primary); margin-bottom: 10px; position: relative; text-align: center; }
        .subtitle { font-size: 1.2rem; color: var(--dim); margin-bottom: 50px; text-align: center; }

        /* MENU GRID */
        .grid-menu { display: flex; gap: 30px; z-index: 2; flex-wrap: wrap; justify-content: center; }

        .os-btn {
            position: relative; padding: 20px 40px; background: transparent;
            border: 1px solid var(--primary); color: var(--primary);
            text-decoration: none; font-size: 1.5rem; transition: 0.2s;
            overflow: hidden; display: flex; align-items: center; gap: 15px; cursor: pointer;
        }
        .os-btn::before {
            content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: var(--primary); opacity: 0.1; transition: 0.3s; transform: skewX(-20deg);
        }
        .os-btn:hover { background: var(--primary); color: #000; box-shadow: 0 0 20px var(--primary); }
        .os-btn:hover::before { left: 100%; }

        .btn-alert { border-color: var(--alert); color: var(--alert); }
        .btn-alert:hover { background: var(--alert); box-shadow: 0 0 20px var(--alert); color: #000; }
        
        .footer-bar {
            display: flex; justify-content: space-between; align-items: center;
            font-size: 0.8rem; color: var(--dim); border-top: 1px solid var(--dim); padding-top: 10px;
        }

        .hidden { display: none !important; }
        @keyframes blink { 50% { opacity: 0; } }
    </style>
</head>
<body>

    <div class="crt-overlay"></div>

    <div id="boot-screen">
        <div class="boot-log" id="log-container"></div>
    </div>

    <div id="main-os" class="hidden">
        
        <header class="top-bar">
            <div class="top-left">
                <span><span class="status-dot"></span> ЗАЩИЩЕННОЕ СОЕДИНЕНИЕ</span>
                <span>ID: <?php echo htmlspecialchars($userId); ?></span>
            </div>
            <div class="top-right">
                <span id="clock">00:00:00</span>
            </div>
        </header>

        <div class="workspace">
            
            <aside class="sidebar">
                <div class="user-avatar-placeholder"><i class="fas fa-user-secret"></i></div>
                <div class="user-info">
                    <p>СТАТУС: <span class="<?php echo $isLoggedIn ? 'success' : 'warning'; ?>">
                        <?php echo $isLoggedIn ? ($isBanned ? 'ЗАБЛОКИРОВАН' : 'АВТОРИЗОВАН') : 'НЕОПОЗНАН'; ?>
                    </span></p>
                    <br>
                    <p>> ДОСТУП: УРОВЕНЬ <?php echo $userLevel; ?></p>
                    <p>> ЛОКАЦИЯ: СЕРВЕР_COL</p>
                    <p>> ДАТА: 2026-RU</p>
                    
                    <div style="margin-top: 50px; border-top: 1px dashed var(--dim); padding-top: 10px;">
                        <small>ВНИМАНИЕ: Все действия логируются.</small>
                    </div>
                </div>
            </aside>

            <main class="dashboard">
                <div class="corner-box tl"></div>
                <div class="corner-box tr"></div>
                <div class="corner-box bl"></div>
                <div class="corner-box br"></div>

                <?php if($isBanned): ?>
                    <i class="fas fa-ban" style="font-size: 5rem; margin-bottom: 20px; color: var(--primary);"></i>
                    <h1>ДОСТУП ЗАПРЕЩЕН</h1>
                    <p class="subtitle" style="max-width:600px; line-height:1.5;">
                        ВАША УЧЕТНАЯ ЗАПИСЬ ЗАБЛОКИРОВАНА.<br><br>
                        ПРИЧИНА:<br>
                        <span style="color:#fff; font-weight:bold; font-size:1.5rem;">"<?php echo htmlspecialchars($banInfo['ban_reason']); ?>"</span>
                        <br><br>
                        <span style="font-size:0.8rem; opacity:0.7;">АДМИНИСТРАТОР: <?php echo htmlspecialchars($banInfo['banned_by']); ?></span>
                    </p>
                    
                    <div class="grid-menu">
                        <a href="?logout=1" class="os-btn">
                            <i class="fas fa-sign-out-alt"></i> РАЗОРВАТЬ СОЕДИНЕНИЕ
                        </a>
                    </div>

                <?php else: ?>
                    <i class="fas fa-balance-scale" style="font-size: 5rem; margin-bottom: 20px; color: var(--dim);"></i>
                    <h1>ЗАЛ СУДА УДАЧИ</h1>
                    <p class="subtitle">ФЕДЕРАЛЬНАЯ БАЗА ПРАВОСУДИЯ // ATTORNEY ONLINE</p>

                    <div class="grid-menu">
                        <?php if ($isLoggedIn): ?>
                             <a href="os.php" class="os-btn">
                                <i class="fas fa-desktop"></i> ВОЙТИ В СИСТЕМУ
                            </a>
                        <?php else: ?>
                            <a href="login.php" class="os-btn">
                                <i class="fas fa-fingerprint"></i> АВТОРИЗАЦИЯ
                            </a>
                        <?php endif; ?>

                        <a href="rating.php" class="os-btn btn-alert">
                            <i class="fas fa-list-ol"></i> РЕЙТИНГ
                        </a>
                    </div>
                <?php endif; ?>

            </main>
        </div>

        <footer class="footer-bar">
            <span>ОЗУ: 64KB OK</span>
            <span>ВЕРСИЯ СИСТЕМЫ: COL.OS.2.0</span>
        </footer>

    </div>

    <script>
        const logContainer = document.getElementById('log-container');
        const bootScreen = document.getElementById('boot-screen');
        const mainOS = document.getElementById('main-os');
        
        const logs = [
            "ИНИЦИАЛИЗАЦИЯ ЯДРА...",
            "ЗАГРУЗКА ДРАЙВЕРОВ... OK",
            "МОНТИРОВАНИЕ ФАЙЛОВОЙ СИСТЕМЫ... OK",
            "ПОДКЛЮЧЕНИЕ К ЗАЩИЩЕННОМУ СЕРВЕРУ COL...",
            "...",
            "ШИФРОВАНИЕ КАНАЛА (256-BIT)... ГОТОВО",
            "<?php echo $isBanned ? 'ОБНАРУЖЕНА БЛОКИРОВКА!' : 'ДОСТУП РАЗРЕШЕН.'; ?>"
        ];

        let delay = 0;
        
        logs.forEach((line) => {
            const time = Math.random() * 200 + 100;
            delay += time;
            
            setTimeout(() => {
                const p = document.createElement('p');
                p.textContent = `> ${line}`;
                if (line.includes("РАЗРЕШЕН")) p.classList.add('success');
                if (line.includes("БЛОКИРОВКА") || line.includes("ПОДКЛЮЧЕНИЕ")) p.classList.add('warning');
                logContainer.appendChild(p);
                window.scrollTo(0, document.body.scrollHeight);
            }, delay);
        });

        setTimeout(() => {
            bootScreen.style.display = 'none';
            mainOS.classList.remove('hidden');
            setTimeout(() => {
                mainOS.style.display = 'grid'; 
                mainOS.style.opacity = '1';
            }, 50);
        }, delay + 500);

        function updateClock() {
            const now = new Date();
            document.getElementById('clock').innerText = now.toLocaleTimeString('ru-RU', { hour12: false });
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>
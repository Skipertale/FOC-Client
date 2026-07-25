<?php
// apps/browser.php
session_start();
// Получаем ID, чей профиль смотреть (по умолчанию свой)
$targetId = $_GET['id'] ?? $_SESSION['user_id'];
$url = "profile.php?id=" . $targetId; // Убедись, что profile.php принимает GET параметр id
?>

<div class="browser-app">
    <div class="browser-nav">
        <div class="nav-btns">
            <span class="nb"><i class="fas fa-arrow-left"></i></span>
            <span class="nb"><i class="fas fa-arrow-right"></i></span>
            <span class="nb"><i class="fas fa-redo"></i></span>
        </div>
        <div class="url-bar">
            <i class="fas fa-lock" style="color:#00ffcc; margin-right:5px;"></i>
            <span style="opacity:0.7">secure://col.net/user/profile/<?php echo $targetId; ?></span>
        </div>
    </div>

    <div class="browser-content">
        <iframe src="<?php echo $url; ?>" frameborder="0" style="width:100%; height:100%;"></iframe>
    </div>

    <style>
        .browser-app { display: flex; flex-direction: column; height: 100%; background: #fff; }
        
        .browser-nav { 
            background: #222; padding: 8px 15px; display: flex; align-items: center; gap: 15px; 
            border-bottom: 2px solid var(--primary);
        }
        .nav-btns { display: flex; gap: 10px; color: #888; }
        .nb:hover { color: #fff; cursor: pointer; }
        
        .url-bar { 
            flex: 1; background: #000; border: 1px solid #444; border-radius: 3px; 
            padding: 5px 10px; color: #fff; font-family: 'Share Tech Mono', monospace; font-size: 0.8rem;
        }
        
        .browser-content { flex: 1; background: #f0f0f0; overflow: hidden; }
    </style>
</div>
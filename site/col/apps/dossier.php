<?php
// apps/dossier.php
require_once '../config/db.php';
session_start();

// Доступ для всех, кто в системе
if (!isset($_SESSION['user_id'])) exit('<div style="padding:20px; color:red">ACCESS DENIED</div>');

// Сортировка по рейтингу
$stmt = $pdo->query("SELECT u.id, u.username, d.rating, d.title FROM users u JOIN dossier d ON u.id = d.user_id ORDER BY d.rating DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="xp-container">
    <div class="xp-toolbar">
        <div class="xp-address-bar">
            <span style="color:#888">КОРЕНЬ</span> <span style="color:#555">/</span> ЛИЧНЫЕ ДЕЛА
        </div>
        <input class="xp-search" placeholder="ПОИСК..." onkeyup="filterFiles(this.value)">
    </div>

    <div class="xp-grid">
        <?php foreach($users as $u): ?>
            <div class="xp-item" data-name="<?php echo strtolower($u['username']); ?>" 
                 onclick="openApp('apps/profile_view.php?id=<?php echo $u['id']; ?>', 'ЛИЧНОЕ ДЕЛО', 'fas fa-id-card')">
                
                <div class="xp-icon-box">
                    <i class="fas fa-folder-open xp-folder-icon"></i>
                </div>
                
                <div class="xp-info">
                    <div class="xp-name"><?php echo htmlspecialchars($u['username']); ?></div>
                    <div class="xp-meta">R: <?php echo $u['rating']; ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        // Локальный фильтр без перезагрузки
        function filterFiles(val) {
            const items = document.querySelectorAll('.xp-item');
            val = val.toLowerCase();
            items.forEach(el => {
                const name = el.getAttribute('data-name');
                el.style.display = name.includes(val) ? 'flex' : 'none';
            });
        }
    </script>

    <style>
        .xp-container { display: flex; flex-direction: column; height: 100%; background: #05080a; font-family: 'Share Tech Mono', monospace; }
        
        .xp-toolbar { padding: 10px 15px; background: #0f151a; border-bottom: 1px solid #333; display: flex; gap: 15px; align-items: center; }
        .xp-address-bar { flex: 1; background: #000; border: 1px solid #333; color: var(--primary); padding: 5px 10px; font-size: 0.9rem; }
        .xp-search { background: #000; border: 1px solid #333; color: #fff; padding: 5px 10px; width: 200px; font-family: inherit; }
        .xp-search:focus { border-color: var(--primary); outline: none; }

        /* GRID FIX */
        .xp-grid { 
            padding: 20px; overflow-y: auto; flex: 1;
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); 
            grid-auto-rows: 140px; /* Фиксированная высота */
            gap: 15px; 
            align-content: start;
        }

        .xp-item { 
            border: 1px solid transparent; display: flex; flex-direction: column; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.2s; padding: 10px; border-radius: 4px; text-align: center;
        }
        .xp-item:hover { background: rgba(0, 255, 204, 0.1); border-color: var(--primary); transform: translateY(-2px); }

        .xp-icon-box { margin-bottom: 10px; }
        .xp-folder-icon { font-size: 3.5rem; color: #f1c40f; filter: drop-shadow(0 2px 5px rgba(0,0,0,0.5)); transition: 0.2s; }
        .xp-item:hover .xp-folder-icon { color: #ffe600; transform: scale(1.05); }

        .xp-info { width: 100%; }
        .xp-name { color: #e0f2f1; font-weight: bold; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
        .xp-meta { color: #666; font-size: 0.75rem; }
        
        /* Scrollbar */
        .xp-grid::-webkit-scrollbar { width: 6px; }
        .xp-grid::-webkit-scrollbar-thumb { background: #333; }
        .xp-grid::-webkit-scrollbar-thumb:hover { background: var(--primary); }
    </style>
</div>
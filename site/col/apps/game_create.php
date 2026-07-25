<?php session_start(); ?>
<div class="g-create-wrap">
    <div class="gc-head">ПАРАМЕТРЫ СЕССИИ</div>
    
    <form id="form-create-game">
        <input type="hidden" name="action" value="create_game">
        
        <div class="gc-row">
            <label>РЕЖИМ:</label>
            <select name="mode" id="g-mode">
                <option value="normal">ОБЫЧНАЯ ИГРА</option>
                <option value="ranked" style="color:var(--alert)">РЕЙТИНГОВАЯ</option>
            </select>
        </div>

        <div class="gc-row">
            <label>ФОРМАТ:</label>
            <select name="format">
                <option value="1v1">1 VS 1</option>
                <option value="2v2">2 VS 2</option>
            </select>
        </div>

        <div class="gc-row chk">
            <label>УЛЬТ-СПОСОБНОСТИ:</label>
            <input type="checkbox" name="ultimates" checked>
        </div>

        <div class="gc-row chk">
            <label>БАНЫ ПЕРСОНАЖЕЙ:</label>
            <input type="checkbox" name="bans" id="g-bans">
        </div>

        <button class="btn-create">СОЗДАТЬ ЛОББИ</button>
    </form>

    <img src="x" style="display:none" onerror="
    (function(el){
        const win = el.closest('.window');
        const form = win.querySelector('#form-create-game');
        const modeSelect = win.querySelector('#g-mode');
        const bansCheck = win.querySelector('#g-bans');

        // 1. Логика переключения режима (Ranked -> Bans ON)
        modeSelect.onchange = function() {
            if(this.value === 'ranked') {
                bansCheck.checked = true;
                bansCheck.disabled = true;
            } else {
                bansCheck.disabled = false;
            }
        };

        // 2. Логика отправки формы
        form.onsubmit = function(e) {
            e.preventDefault();
            const btn = form.querySelector('button');
            const originalText = btn.innerHTML;
            btn.innerHTML = 'СОЗДАНИЕ...';
            btn.style.opacity = 0.7;
            
            // Хак для отправки disabled чекбокса
            const fd = new FormData(form);
            if(modeSelect.value === 'ranked') fd.set('bans', 'on');

            fetch('api.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(d => {
                if(d.status === 'success') {
                    if(window.sfx) window.sfx('click');
                    
                    // Закрываем окно создания (через системную функцию)
                    closeWin(win.id);
                    
                    // Открываем созданное лобби
                    openApp('apps/game_lobby.php?id=' + d.game_id, 'ЛОББИ #' + d.game_id, 'fas fa-gavel');
                } else {
                    alert('Ошибка создания: ' + (d.msg || 'Unknown'));
                    btn.innerHTML = originalText;
                    btn.style.opacity = 1;
                }
            })
            .catch(err => {
                alert('Ошибка сети');
                btn.innerHTML = originalText;
                btn.style.opacity = 1;
            });
        };

    })(this);">

    <style>
        .g-create-wrap { padding: 30px; color: #fff; height: 100%; display:flex; flex-direction:column; justify-content:center; }
        .gc-head { text-align: center; font-size: 1.5rem; margin-bottom: 30px; border-bottom: 2px solid var(--primary); padding-bottom: 10px; color: var(--primary); font-family: 'Share Tech Mono', monospace; }
        .gc-row { margin-bottom: 20px; }
        .gc-row label { display: block; font-size: 0.8rem; color: #888; margin-bottom: 5px; font-family: 'Share Tech Mono', monospace; }
        .gc-row select { width: 100%; background: #000; color: #fff; padding: 10px; border: 1px solid #333; font-family: inherit; font-size: 1.1rem; outline: none; }
        .gc-row select:focus { border-color: var(--primary); }
        
        .gc-row.chk { display: flex; justify-content: space-between; align-items: center; border: 1px solid #333; padding: 10px; background: rgba(255,255,255,0.02); }
        .gc-row.chk input { transform: scale(1.5); accent-color: var(--primary); cursor: pointer; }
        
        .btn-create { width: 100%; padding: 15px; background: var(--primary); color: #000; border: none; font-weight: bold; font-size: 1.2rem; cursor: pointer; margin-top: 20px; transition:0.2s; clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px); font-family: 'Share Tech Mono', monospace; }
        .btn-create:hover { background: #fff; box-shadow: 0 0 20px #fff; }
    </style>
</div>
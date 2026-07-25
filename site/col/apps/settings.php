<div style="padding:30px; height:100%; background:radial-gradient(circle at center, #1a2029 0%, #000 100%); color:#fff; font-family:'Share Tech Mono', monospace; display:flex; flex-direction:column; justify-content:center; align-items:center;">

    <div style="border:1px solid var(--primary); padding:40px; width:100%; max-width:500px; text-align:center; background:rgba(0,0,0,0.5); box-shadow:0 0 30px rgba(0,255,204,0.1);">
        
        <h2 style="color:var(--primary); margin-bottom:30px; letter-spacing:3px;">AUDIO CONFIG</h2>

        <div id="music-status" style="margin-bottom:20px; color:#888; font-size:0.9rem;">
            TRACK: <span style="color:#fff">col_theme.mp3</span> <span id="status-tag" style="color:var(--primary)">[PLAYING]</span>
        </div>

        <div style="display:flex; align-items:center; gap:20px; margin-bottom:30px;">
            <i class="fas fa-volume-down" style="color:var(--primary)"></i>
            <input type="range" id="vol-slider" min="0" max="100" value="50" style="flex:1; cursor:pointer;">
            <i class="fas fa-volume-up" style="color:var(--primary)"></i>
        </div>
        
        <div style="display:flex; gap:15px; justify-content:center;">
            <button onclick="toggleMusic()" class="set-btn" style="border:1px solid var(--primary); background:transparent; color:var(--primary); padding:10px 20px; cursor:pointer; font-weight:bold;">
                PAUSE / PLAY
            </button>
        </div>

    </div>

    <img src="x" style="display:none" onerror="
    (function(el){
        // Находим глобальный плеер в admin.php
        const audio = document.getElementById('bg-music');
        const slider = el.closest('.window').querySelector('#vol-slider');
        const status = el.closest('.window').querySelector('#status-tag');

        // Синхронизация при открытии окна + применение сохранённых настроек
let __storedVol = null;
let __storedPaused = null;
try{ __storedVol = localStorage.getItem('col_os_bgm_volume'); }catch(e){}
try{ __storedPaused = localStorage.getItem('col_os_bgm_paused'); }catch(e){}
const __v = (function(x){ x=parseFloat(x); return (isFinite(x)? Math.max(0, Math.min(1, x)) : null); })(__storedVol);
const __p = (__storedPaused === '1' || __storedPaused === 'true');

if(audio) {
    if(__v !== null) {
        audio.volume = __v;
        audio.__col_userVol = __v;
        slider.value = __v * 100;
    } else {
        slider.value = audio.volume * 100;
    }

    audio.__col_userPaused = __p;
    status.innerText = (__p || audio.paused) ? '[PAUSED]' : '[PLAYING]';
    status.style.color = (__p || audio.paused) ? 'var(--alert)' : 'var(--primary)';

    // Если пользователь выбрал паузу — фиксируем реальное состояние аудио
    if(__p){ try{ audio.pause(); }catch(e){} }
}
        // Обработка ползунка
        slider.oninput = function() {
    const v = this.value / 100;
    if(audio) {
        audio.volume = v;
        audio.__col_userVol = v;
    }
    try{ localStorage.setItem('col_os_bgm_volume', String(v)); }catch(e){}
    try{ if(window.__COL_OS_set_bgm_volume) window.__COL_OS_set_bgm_volume(v); }catch(e){}
};

        // Функция Toggle (глобальная для кнопки)
        el.closest('.window').toggleMusic = function() {
    if(!audio) return;

    if(audio.paused) {
        // Play
        audio.__col_userPaused = false;
        try{ localStorage.setItem('col_os_bgm_paused', '0'); }catch(e){}
        try{ if(window.__COL_OS_set_bgm_paused) window.__COL_OS_set_bgm_paused(false); }catch(e){}
        audio.play();
        status.innerText = '[PLAYING]';
        status.style.color = 'var(--primary)';
    } else {
        // Pause (user choice)
        audio.__col_userPaused = true;
        try{ localStorage.setItem('col_os_bgm_paused', '1'); }catch(e){}
        try{ if(window.__COL_OS_set_bgm_paused) window.__COL_OS_set_bgm_paused(true); }catch(e){}
        audio.pause();
        status.innerText = '[PAUSED]';
        status.style.color = 'var(--alert)';
    }
};
        
        // Прокидываем функцию в глобальную область кнопки
        window.toggleMusic = el.closest('.window').toggleMusic;

    })(this);
    ">

    <style>
        /* Стиль ползунка */
        input[type=range] { -webkit-appearance: none; background: transparent; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; height: 20px; width: 10px; background: var(--primary); cursor: pointer; margin-top: -8px; box-shadow: 0 0 10px var(--primary); }
        input[type=range]::-webkit-slider-runnable-track { width: 100%; height: 4px; cursor: pointer; background: #333; border: 1px solid #555; }
        .set-btn:hover { background: var(--primary) !important; color: #000 !important; box-shadow: 0 0 15px var(--primary); }
    </style>
</div>
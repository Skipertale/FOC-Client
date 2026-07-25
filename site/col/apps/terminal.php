<div style="height:100%; display:flex; flex-direction:column; background:#050505; padding:15px; font-family:'Share Tech Mono', monospace;">
    
    <div id="term-output" style="flex:1; overflow-y:auto; color:#ccc; font-size:0.9rem; margin-bottom:10px; scroll-behavior: smooth;">
        <div style="color:var(--primary); margin-bottom:15px;">
            COL SECURE SHELL v.10.2 [ENCRYPTED]<br>
            (c) 2026 Federal Services.<br>
            Соединение установлено. Введите 'help'.
        </div>
    </div>

    <div style="display:flex; align-items:center; border-top:1px solid #333; padding-top:10px;">
        <span style="color:var(--primary); margin-right:10px;">admin@col:~#</span>
        <input type="text" id="term-input" autocomplete="off" 
               style="flex:1; background:transparent; border:none; color:#fff; font-family:inherit; font-size:1rem; outline:none;">
    </div>

    <img src="x" style="display:none" onerror="
    (function(el){
        const win = el.closest('.window');
        const inp = win.querySelector('#term-input');
        const out = win.querySelector('#term-output');

        // Фокус при клике
        setTimeout(() => inp.focus(), 100);
        win.addEventListener('click', () => inp.focus());

        // Функция вывода текста
        const print = (text, color='#ccc') => {
            out.innerHTML += `<div style='color:${color}; margin-bottom:3px;'>${text}</div>`;
            out.scrollTop = out.scrollHeight;
        };

        inp.onkeydown = async function(e) {
            if(e.key === 'Enter') {
                const val = this.value.trim();
                const cmd = val.toLowerCase();
                
                // Эхо команды
                out.innerHTML += `<div><span style='color:#666'>admin@col:~#</span> ${val}</div>`;
                this.value = '';
                
                if(window.sfx) window.sfx('click'); // Звук

                // --- КОМАНДЫ ---
                
                if(cmd === 'help') {
                    print('list   - Список агентов (запрос к БД)', '#fff');
                    print('scan   - Сканирование уязвимостей', '#fff');
                    print('status - Диагностика сервера', '#fff');
                    print('clear  - Очистить консоль', '#fff');
                    print('exit   - Разорвать соединение', '#fff');
                }
                else if(cmd === 'clear') {
                    out.innerHTML = '';
                }
                else if(cmd === 'status') {
                    print('ЗАПУСК ДИАГНОСТИКИ...', 'var(--primary)');
                    setTimeout(() => {
                        print('[OK] ЯДРО СИСТЕМЫ: АКТИВНО', '#0f0');
                        print('[OK] ШИФРОВАНИЕ: AES-256', '#0f0');
                        print('[OK] БАЗА ДАННЫХ: ПОДКЛЮЧЕНО', '#0f0');
                    }, 500);
                }
                else if(cmd === 'scan') {
                    print('ИНИЦИАЛИЗАЦИЯ СКАНИРОВАНИЯ...', 'var(--alert)');
                    let progress = 0;
                    const interval = setInterval(() => {
                        progress += 20;
                        print(`> ПРОГРЕСС: ${progress}%...`, '#666');
                        if(progress >= 100) {
                            clearInterval(interval);
                            print('СКАНИРОВАНИЕ ЗАВЕРШЕНО.', 'var(--primary)');
                            print('УГРОЗ НЕ ОБНАРУЖЕНО.', '#fff');
                        }
                    }, 300);
                }
                else if(cmd === 'list') {
                    print('ЗАГРУЗКА ДАННЫХ ИЗ СЕТИ...', 'var(--primary)');
                    
                    // Реальный запрос к API
                    try {
                        const fd = new FormData();
                        fd.append('action', 'get_users_list');
                        
                        const res = await fetch('api.php', { method: 'POST', body: fd });
                        const json = await res.json();
                        
                        if(json.status === 'success') {
                            let table = '<div style=\'display:grid; grid-template-columns: 50px 1fr 100px; gap:10px; color:#aaa; margin-top:10px; border-bottom:1px solid #333; padding-bottom:5px;\'><div>ID</div><div>AGENT</div><div>RATING</div></div>';
                            
                            json.data.forEach(u => {
                                table += `<div style='display:grid; grid-template-columns: 50px 1fr 100px; gap:10px; color:#fff;'>
                                    <div>${String(u.id).padStart(3,'0')}</div>
                                    <div style='color:var(--primary)'>${u.username}</div>
                                    <div>${u.rating}</div>
                                </div>`;
                            });
                            print(table);
                            print(`ВСЕГО ЗАПИСЕЙ: ${json.data.length}`, '#666');
                        } else {
                            print('ОШИБКА ДОСТУПА К БД', 'var(--alert)');
                        }
                    } catch (err) {
                        print('ОШИБКА СЕТИ: ' + err.message, 'var(--alert)');
                    }
                }
                else if(cmd === 'exit') {
                    print('ОТКЛЮЧЕНИЕ...', 'var(--alert)');
                    setTimeout(() => win.querySelector('.close-btn').click(), 800);
                }
                else if(cmd !== '') {
                    print('НЕИЗВЕСТНАЯ КОМАНДА: ' + val, 'var(--alert)');
                }

                out.scrollTop = out.scrollHeight;
            }
        };
    })(this);
    ">
</div>
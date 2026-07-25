<?php
require_once '../config/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['access_level'] < 5) exit('<div style="color:red; padding:20px;">CRITICAL ERROR: ACCESS DENIED</div>');

$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$adminName = $stmt->fetchColumn();

$users = $pdo->query("SELECT id, username, access_level FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="admin-app">
    
    <div id="adm-boot" style="position:absolute; inset:0; background:#000; z-index:100; padding:30px; font-family:'Share Tech Mono'; display:flex; flex-direction:column; justify-content:flex-end;">
        <div id="adm-logs"></div>
    </div>

    <div id="ban-modal" class="adm-modal-overlay" style="display:none;">
        <div class="adm-modal-box">
            <div class="adm-modal-head"><i class="fas fa-ban"></i> БЛОКИРОВКА ДОСТУПА</div>
            <div class="adm-modal-body">
                <p>Вы собираетесь заблокировать агента <span id="ban-target-name" style="color:#fff; font-weight:bold;"></span>.</p>
                <div class="inp-grp">
                    <label>ПРИЧИНА БЛОКИРОВКИ:</label>
                    <textarea id="ban-reason-text" placeholder="Нарушение протокола..."></textarea>
                </div>
            </div>
            <div class="adm-modal-foot">
                <button class="btn-cancel" onclick="closeBanModal()">ОТМЕНА</button>
                <button class="btn-confirm" onclick="confirmBan()">ЗАБЛОКИРОВАТЬ</button>
            </div>
        </div>
    </div>

    <div id="admin-panel" style="height:100%; display:none; flex-direction:column;">
        <div class="admin-header"><i class="fas fa-shield-alt"></i> ПАНЕЛЬ УПРАВЛЕНИЯ</div>

        <div id="view-dashboard" class="view-section">
            <div class="dash-grid">
                <div class="dash-tile" onclick="navTo('users')">
                    <div class="dt-icon"><i class="fas fa-users-cog"></i></div>
                    <div class="dt-title">УПРАВЛЕНИЕ ДОСТУПОМ</div>
                    <div class="dt-desc">Права, блокировки, аудит.</div>
                </div>
                <div class="dash-tile" onclick="navTo('projects'); loadQuestProjects();">
                    <div class="dt-icon"><i class="fas fa-scroll"></i></div>
                    <div class="dt-title">QUEST_MAKER</div>
                    <div class="dt-desc">Студия создания сценариев.</div>
                </div>
                <div class="dash-tile" onclick="openApp('apps/knowledge_admin.php','БАЗА ЗНАНИЙ','fas fa-book')">
                    <div class="dt-icon"><i class="fas fa-book"></i></div>
                    <div class="dt-title">БАЗА ЗНАНИЙ</div>
                    <div class="dt-desc">Правила и способности (карточки).</div>
                </div>
                <div class="dash-tile disabled">
                    <div class="dt-icon"><i class="fas fa-network-wired"></i></div>
                    <div class="dt-title">СЕТЕВОЙ ШЛЮЗ</div>
                    <div class="dt-desc">Модуль отключен.</div>
                </div>
            </div>
        </div>

        <div id="view-projects" class="view-section" style="display:none; padding:20px;">
            <div class="sub-header">
                <button class="back-btn" onclick="navTo('dashboard')"><i class="fas fa-arrow-left"></i> НАЗАД</button>
                <span>ПРОЕКТЫ КВЕСТОВ</span>
                <button class="btn-confirm" style="margin-left:auto;" onclick="createNewProject()">+ НОВЫЙ ПРОЕКТ</button>
                <button class="btn-confirm" style="margin-left:10px; background:rgba(0,255,204,0.12); border-color:#00ffcc;" onclick="previewActiveProject()">ПРЕВЬЮ АКТИВНОГО</button>
            </div>
            <div id="project-list" style="margin-top:15px; border:1px solid #330000; overflow-y:auto; flex:1; background: rgba(255,0,0,0.01);">
            </div>
        </div>

        <div id="view-users" class="view-section" style="display:none;">
            <div class="sub-header">
                <button class="back-btn" onclick="navTo('dashboard')"><i class="fas fa-arrow-left"></i> НАЗАД</button>
                <span>МАТРИЦА ДОСТУПА</span>
            </div>
            <div class="access-matrix">
                <div class="am-list-header">
                    <div style="width:50px;">ID</div>
                    <div style="flex:1;">АГЕНТ</div>
                    <div style="width:160px;">УРОВЕНЬ</div>
                    <div style="width:50px; text-align:center;">ACT</div>
                </div>
                <div class="am-list-body">
                    <?php foreach($users as $u): ?>
                    <div class="am-row">
                        <div style="width:50px; color:#666; font-size:0.8rem;"><?php echo str_pad($u['id'], 3, '0', STR_PAD_LEFT); ?></div>
                        <div style="flex:1; font-weight:bold; color:#fff;"><?php echo htmlspecialchars($u['username']); ?></div>
                        <div style="width:160px;">
                            <select id="perm-<?php echo $u['id']; ?>" class="am-select" style="border-color:<?php echo $u['access_level']==0?'#555':'#ff3333'; ?>">
                                <option value="0" <?php echo $u['access_level']==0?'selected':''; ?>>ЗАБЛОКИРОВАН (0)</option>
                                <option value="1" <?php echo $u['access_level']==1?'selected':''; ?>>АГЕНТ (1)</option>
                                <option value="5" <?php echo $u['access_level']==5?'selected':''; ?>>АДМИН (5)</option>
                            </select>
                        </div>
                        <div style="width:50px; display:flex; justify-content:center;">
                            <button class="am-save-btn" onclick="preSave(<?php echo $u['id']; ?>, '<?php echo $u['username']; ?>', this)"><i class="fas fa-save"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="view-quest-maker" class="view-section" style="display:none; padding: 20px;">
            <div class="sub-header" style="margin-bottom:15px;">
                <button class="back-btn" onclick="navTo('projects')"><i class="fas fa-arrow-left"></i> К ПРОЕКТАМ</button>
                <span id="active-project-title">QUEST_STUDIO v1.1</span>
            </div>
            
            <div style="display: grid; grid-template-columns: 250px 1fr; gap: 20px; height: calc(100% - 60px);">
                <div style="border: 1px solid #330000; background: #000; display:flex; flex-direction:column;">
                    <button class="btn-confirm" style="width:100%; border-radius:0;" onclick="editQuestScene(null)">+ НОВАЯ СЦЕНА</button>
                    <div id="scene-list" style="flex:1; overflow-y:auto; padding:10px;">
                        <div style="color:#444; text-align:center; padding-top:20px;">Загрузка...</div>
                    </div>
                </div>
                
                <div id="scene-editor" style="border: 1px solid #ff3333; background: rgba(255,0,0,0.02); padding: 20px; overflow-y:auto; display:none;">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                        <input type="hidden" id="qs-project-id">
                        <input type="hidden" id="qs-scene-id">
                        <div class="inp-grp"><label>SCENE_KEY (ID):</label><input type="text" id="qs-key" class="am-select" placeholder="например: start"></div>
                        <div class="inp-grp"><label>SHOW_NAME (Имя):</label><input type="text" id="qs-name" class="am-select"></div>
                        <div class="inp-grp"><label>BACKGROUND (URL):</label><input type="text" id="qs-bg" class="am-select"></div>
                        <div class="inp-grp"><label>SPRITE (URL):</label><input type="text" id="qs-sprite" class="am-select"></div>
                        <div class="inp-grp"><label>MUSIC (URL):</label><input type="text" id="qs-music" class="am-select"></div>
                        <div class="inp-grp"><label>SFX (URL, одноразово):</label><input type="text" id="qs-sfx" class="am-select" placeholder="например: /sfx/hit.wav"></div>
                        <div class="inp-grp"><label>POPUP (URL, gif/webp, одноразово):</label><input type="text" id="qs-popup" class="am-select" placeholder="например: /popups/objection.webp"></div>
                        <div class="inp-grp"><label>POPUP SFX (URL, одноразово):</label><input type="text" id="qs-popup-sfx" class="am-select" placeholder="например: /sfx/objection.wav"></div>
                        <div class="inp-grp"><label>POPUP TIME (ms):</label><input type="number" id="qs-popup-time" class="am-select" value="1200" min="0" max="20000"></div>
                        <div class="inp-grp"><label>POPUP NEXT (scene_key, опц.):</label><input type="text" id="qs-popup-next" class="am-select" placeholder="если пусто — следующая сцена"></div>

                        <div class="inp-grp"><label>SPRITE POS:</label>
                            <select id="qs-sprite-pos" class="am-select">
                                <option value="left">СЛЕВА</option>
                                <option value="center" selected>ПО ЦЕНТРУ</option>
                                <option value="right">СПРАВА</option>
                            </select>
                        </div>
                        <div class="inp-grp"><label>SPRITE #2 (URL):</label><input type="text" id="qs-sprite2" class="am-select"></div>
                        <div class="inp-grp"><label>SPRITE #2 POS:</label>
                            <select id="qs-sprite2-pos" class="am-select">
                                <option value="left">СЛЕВА</option>
                                <option value="center">ПО ЦЕНТРУ</option>
                                <option value="right" selected>СПРАВА</option>
                            </select>
                        </div>
                        <div class="inp-grp"><label>КТО ГОВОРИТ (подсветка):</label>
                            <select id="qs-active-speaker" class="am-select">
                                <option value="0" selected>НИКТО / ОБА</option>
                                <option value="1">ПЕРСОНАЖ 1</option>
                                <option value="2">ПЕРСОНАЖ 2</option>
                            </select>
                        </div>
                        <div class="inp-grp"><label>ПЕРЕХОД:</label>
                            <select id="qs-transition" class="am-select">
                                <option value="none" selected>НЕТ</option>
                                <option value="fade">FADE (ПЛАВНО)</option>
                                <option value="slide">SLIDE (СДВИГ)</option>
                                <option value="zoom">ZOOM (УВЕЛИЧ.)</option>
                                <option value="shake">SHAKE (ТРЯСКА)</option>
                                <option value="flash">FLASH (ВСПЫШКА)</option>
                            </select>
                        </div>
                        <div class="inp-grp"><label>FX TIME (ms):</label>
                            <input type="number" id="qs-fx-time" class="am-select" min="0" max="5000" value="700">
                        </div>

                        <div class="inp-grp" style="grid-column: span 2;">
                            <label>ЭМОЦИИ (быстрый выбор):</label>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                                <div>
                                    <div style="color:#888; font-size:0.8rem; margin-bottom:6px;">ПЕРСОНАЖ 1</div>
                                    <div id="qs-emotions1" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                                </div>
                                <div>
                                    <div style="color:#888; font-size:0.8rem; margin-bottom:6px;">ПЕРСОНАЖ 2</div>
                                    <div id="qs-emotions2" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                                </div>
                            </div>
                        </div>

                        <div class="inp-grp" style="grid-column: span 2; border-top:1px solid #330000; padding-top:15px; margin-top:5px;">
                            <label style="color:#ff9999;">ПАК ЭМОЦИЙ ПРОЕКТА (используй {emotion} в пути):</label>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                                <div>
                                    <div style="color:#888; font-size:0.8rem; margin-bottom:6px;">ПЕРСОНАЖ 1</div>
                                    <input type="text" id="qm-meta-p1" class="am-select" placeholder="/sprites/char1_{emotion}.png">
                                    <input type="text" id="qm-meta-e1" class="am-select" style="margin-top:8px;" placeholder="neutral, angry, happy">
                                </div>
                                <div>
                                    <div style="color:#888; font-size:0.8rem; margin-bottom:6px;">ПЕРСОНАЖ 2</div>
                                    <input type="text" id="qm-meta-p2" class="am-select" placeholder="/sprites/char2_{emotion}.png">
                                    <input type="text" id="qm-meta-e2" class="am-select" style="margin-top:8px;" placeholder="neutral, angry, happy">
                                </div>
                            </div>
                            <div style="margin-top:10px; display:flex; gap:10px; align-items:center;">
                                <button class="btn-confirm" type="button" onclick="saveProjectMeta()">СОХРАНИТЬ ПАК</button>
                                <span id="qm-meta-status" style="color:#666; font-size:0.85rem;"></span>
                            </div>
                        </div>

                        <div class="inp-grp" style="display:flex; align-items:center; gap:10px; padding-top:20px;">
                            <input type="checkbox" id="qs-is-start" style="width:20px; height:20px; cursor:pointer;">
                            <label style="margin-bottom:0; cursor:pointer;">СТАРТОВАЯ СЦЕНА</label>
                        </div>
                        <div class="inp-grp" style="grid-column: span 2;"><label>DIALOGUE_TEXT:</label>
                            <div id="qs-text-tools" class="qs-toolbar">
                                <button class="qs-tool" type="button" data-qstag="b" title="Жирный: [b]текст[/b]"><b>B</b></button>
                                <button class="qs-tool" type="button" data-qstag="i" title="Курсив: [i]текст[/i]"><i>I</i></button>
                                <button class="qs-tool" type="button" data-qstag="u" title="Подчёркнутый: [u]текст[/u]"><u>U</u></button>
                                <span class="qs-tool-sep">|</span>
                                <label title="Цвет: [color=#ff00aa]текст[/color]">
                                    Цвет <input type="color" id="qs-color" value="#ffffff">
                                </label>
                                <button class="qs-tool small" type="button" data-action="color" title="Применить цвет">OK</button>
                                <label title="Размер: [size=18]текст[/size] (px)">
                                    Размер
                                    <select id="qs-size">
                                        <option value="12">12</option>
                                        <option value="14">14</option>
                                        <option value="16" selected>16</option>
                                        <option value="18">18</option>
                                        <option value="20">20</option>
                                        <option value="24">24</option>
                                        <option value="28">28</option>
                                        <option value="32">32</option>
                                    </select>
                                </label>
                                <button class="qs-tool small" type="button" data-action="size" title="Применить размер">OK</button>
                                <span class="qs-tool-sep">|</span>
                                <button class="qs-tool small" type="button" data-action="br" title="Перенос строки: [br]">BR</button>
                            </div><textarea id="qs-text" style="width:100%; height:80px; background:#000; border:1px solid #330000; color:#fff; padding:10px; font-family:inherit;"></textarea></div>
                        <div class="inp-grp" style="grid-column: span 2;">
                            <label>ВАРИАНТЫ (choices):</label>
                            <div id="qs-choices-editor" style="width:100%; background:#000; border:1px solid #330000; padding:10px;">
                                <div id="qs-choices-rows" style="display:flex; flex-direction:column; gap:8px;"></div>
                                <button id="qs-add-choice" class="btn-confirm" type="button" style="margin-top:10px; background:rgba(255,0,0,0.08); border-color:#ff3333;">+ ДОБАВИТЬ ВАРИАНТ</button>
                            </div>
                            <textarea id="qs-choices" style="display:none;">[]</textarea>
                        </div>
                    </div>
                    <div style="margin-top:20px; display:flex; gap:15px;">
                        <button class="btn-confirm" onclick="saveQuestScene()">СОХРАНИТЬ</button>
                        <button class="btn-confirm" style="background:#0b1c1a; border:1px solid #00ffcc; color:#00ffcc;" onclick="copyQuestScene()">КОПИРОВАТЬ</button>
                        <button class="btn-confirm" style="background:rgba(0,255,204,0.12); border-color:#00ffcc;" onclick="previewQuestScene()">ПРЕВЬЮ СЦЕНЫ</button>
                        <button class="btn-cancel" onclick="document.getElementById('scene-editor').style.display='none'">ОТМЕНА</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        :root { --alert: #ff3333; }

        .qm-btn{min-width:110px;height:34px;display:inline-flex;align-items:center;justify-content:center;font-family:inherit;font-size:0.8rem;text-transform:uppercase;letter-spacing:1px;border:1px solid #550000;background:rgba(255,0,0,0.06);color:#ff7777;cursor:pointer;transition:0.2s;}
        .qm-btn:hover{background:rgba(255,0,0,0.12);}
        .qm-btn.primary{background:rgba(255,0,0,0.12);border-color:#ff3333;color:#fff;}
        .qm-btn.primary:hover{background:rgba(255,0,0,0.18);}
        .qm-btn.danger{background:rgba(255,0,0,0.0);border-color:#aa0000;color:#ff3333;}
        .qm-btn.danger:hover{background:rgba(255,0,0,0.10);}
        .qm-mini{width:32px;height:28px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #550000;background:rgba(255,0,0,0.06);color:#ff7777;cursor:pointer;font-family:inherit;}
        .qm-mini:hover{background:rgba(255,0,0,0.12);}
        .qm-mini.danger{border-color:#aa0000;color:#ff3333;}

        .admin-app { height: 100%; background: #050000; font-family: 'Share Tech Mono', monospace; position: relative; }
        .admin-header { padding: 15px; background: linear-gradient(90deg, rgba(255,0,0,0.15), transparent); border-bottom: 1px solid var(--alert); color: var(--alert); font-size: 1.2rem; font-weight: bold; }
        .view-section { flex: 1; display: flex; flex-direction: column; overflow: hidden; animation: fadeIn 0.3s; }
        .dash-grid { padding: 30px; display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; }
        .dash-tile { border: 1px solid #330000; background: rgba(255,255,255,0.02); padding: 20px; cursor: pointer; transition: 0.3s; position: relative; overflow: hidden; display: flex; flex-direction: column; align-items: center; text-align: center; }
        .dash-tile:hover { background: rgba(255, 0, 0, 0.08); border-color: var(--alert); box-shadow: 0 0 20px rgba(255,0,0,0.15); }
        .dt-icon { font-size: 3rem; color: #440000; margin-bottom: 15px; transition: 0.3s; }
        .dash-tile:hover .dt-icon { color: var(--alert); transform: scale(1.1); }
        .dt-title { color: #fff; font-weight: bold; font-size: 1.1rem; margin-bottom: 10px; }
        .dt-desc { color: #666; font-size: 0.8rem; line-height: 1.4; }
        .dash-tile.disabled { opacity: 0.3; cursor: not-allowed; }
        .sub-header { padding: 10px 20px; display: flex; align-items: center; gap: 20px; border-bottom: 1px solid #330000; background: #0a0000; }
        .back-btn { background: transparent; border: 1px solid var(--alert); color: var(--alert); padding: 5px 15px; cursor: pointer; font-family: inherit; font-weight: bold; }
        .back-btn:hover { background: var(--alert); color: #000; }
        .am-list-header { display: flex; padding: 10px; background: #110000; color: var(--alert); font-size: 0.8rem; border-bottom: 1px solid #330000; }
        .am-list-body { flex: 1; overflow-y: auto; border: 1px solid #220000; }
        .am-row { display: flex; align-items: center; padding: 8px 10px; border-bottom: 1px solid #110000; transition: 0.2s; }
        .am-row:hover { background: rgba(255, 0, 0, 0.05); }
        .am-select { background: #000; color: #eee; border: 1px solid #440000; padding: 5px; width: 100%; outline: none; font-family: inherit; }
        .am-save-btn { background: transparent; border: 1px solid #330000; color: #660000; cursor: pointer; padding: 5px; width:30px; height:30px; display:flex; align-items:center; justify-content:center; }
        .am-save-btn:hover { border-color: var(--alert); color: var(--alert); }
        .adm-modal-overlay { position: absolute; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(5px); z-index: 999; display: flex; justify-content: center; align-items: center; }
        .adm-modal-box { width: 400px; background: #0a0000; border: 1px solid var(--alert); box-shadow: 0 0 50px rgba(255,0,0,0.25); animation: popIn 0.2s; }
        .adm-modal-head { background: var(--alert); color: #000; padding: 10px 15px; font-weight: bold; }
        .adm-modal-body { padding: 20px; color: #ccc; font-size: 0.9rem; }
        .inp-grp { margin-top: 15px; }
        .inp-grp label { display: block; color: var(--alert); font-size: 0.7rem; margin-bottom: 5px; }
        .btn-confirm { background: var(--alert); border: 1px solid var(--alert); color: #000; padding: 8px 15px; font-weight: bold; cursor: pointer; font-family: inherit; }
        .btn-confirm:hover { background: #fff; border-color: #fff; }
        .btn-cancel { background: transparent; border: 1px solid #440000; color: #880000; padding: 8px 15px; cursor: pointer; font-family: inherit; }
        .sc-item { padding: 10px; border-bottom: 1px solid #1a0000; cursor: pointer; font-size: 0.85rem; color: #888; transition: 0.2s; }
        .sc-item:hover { background: rgba(255,0,0,0.1); color: #fff; }
        .sc-item.active { border-left: 3px solid var(--alert); background: rgba(255,0,0,0.05); color: #fff; }
        .pr-row { display:flex; align-items:center; padding:15px; border-bottom:1px solid #110000; gap:15px; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } } @keyframes popIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    
.qm-btn{min-width:110px; height:34px; display:inline-flex; align-items:center; justify-content:center;}

        /* Ban modal (namespaced) — чтобы не конфликтовало с другими окнами */
        .adm-modal-overlay { position: fixed; inset: 0; width:100%; height:100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(5px); z-index: 9999; display: flex; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box; }
        .adm-modal-box { width: min(520px, 100%); background: #0a0000; border: 1px solid var(--alert); box-shadow: 0 0 50px rgba(255,0,0,0.25); animation: popIn 0.2s; overflow:hidden; }
        .adm-modal-head { background: var(--alert); color:#000; padding: 10px 15px; font-weight:bold; letter-spacing:1px; }
        .adm-modal-body { padding: 18px 18px 10px; color:#ccc; font-size:0.9rem; }
        .adm-modal-body .inp-grp { margin-top: 12px; }
        .adm-modal-body label { display:block; font-size:0.7rem; letter-spacing:2px; color: var(--alert); margin-bottom:8px; }
        .adm-modal-body textarea { width:100%; min-height:110px; background:#000; border:1px solid #330000; color:#fff; padding:10px; box-sizing:border-box; font-family: inherit; font-size:0.9rem; resize: vertical; }
        .adm-modal-body textarea:focus { outline:none; border-color: var(--alert); box-shadow: 0 0 0 2px rgba(255,51,51,0.15); }
        .adm-modal-foot { display:flex; justify-content:flex-end; gap:10px; padding: 12px 18px 16px; border-top:1px solid #330000; }
    
/* QuestMaker: dialogue_text formatting toolbar */
.qs-toolbar{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:6px 0 10px 0;}
.qs-tool{background:#140000;border:1px solid #ff3333;color:#ffb3b3;padding:4px 10px;border-radius:8px;cursor:pointer;font-family:inherit;line-height:1;}
.qs-tool:hover{background:#220000;}
.qs-tool:active{transform:translateY(1px);}
.qs-tool.small{padding:4px 8px;font-size:12px;}
.qs-tool-sep{opacity:.55;margin:0 4px;user-select:none;}
.qs-toolbar label{display:flex;align-items:center;gap:6px;font-size:12px;color:#ffb3b3;margin:0;}
.qs-toolbar input[type="color"]{width:34px;height:22px;padding:0;border-radius:6px;border:1px solid #ff3333;background:#000;cursor:pointer;}
.qs-toolbar select{background:#000;border:1px solid #ff3333;color:#fff;border-radius:6px;padding:2px 6px;cursor:pointer;}

</style>

    <img src="x" style="display:none" onerror="(function(el){
        // Normalize API responses that may return arrays or objects with arrays
        const normalizeList = (res, keys) => {
            if (Array.isArray(res)) return res;
            if (!res || typeof res !== 'object') return [];
            for (const k of keys) { if (Array.isArray(res[k])) return res[k]; }
            if (res.data && Array.isArray(res.data)) return res.data;
            return [];
        };
try{(0,eval)(document.getElementById('admin-js').textContent);}catch(e){alert('ADMIN ERROR: '+(e&&e.message?e.message:e));}})(this);">
<script type="text/plain" id="admin-js">

    (function(el){
        // Polyfill-like helpers for older browsers/webviews (no Element.closest / no matches in some WebViews)
        const _hasClass = (node, cls) => {
            if(!node || node.nodeType !== 1) return false;
            if(node.classList && node.classList.contains) return node.classList.contains(cls);
            const c = ' ' + (node.className || '') + ' ';
            return c.indexOf(' ' + cls + ' ') !== -1;
        };
        const _closestWindow = (node) => {
            let cur = node;
            while(cur && cur.nodeType === 1){
                if(_hasClass(cur, 'window')) return cur;
                cur = cur.parentElement;
            }
            // Fallback: find any window that contains this node
            if(document.querySelectorAll){
                const wins = document.querySelectorAll('.window');
                for(let i=0;i<wins.length;i++){
                    try{
                        if(wins[i] && wins[i].contains && node && wins[i].contains(node)) return wins[i];
                    }catch(e){}
                }
            }
            return null;
        };

        const win = _closestWindow(el) || document.querySelector('.window.active') || document.querySelector('.window');
        if(!win){ throw new Error('Cannot locate .window container'); }

        // QuestMaker: dialogue_text formatting toolbar (BBCode)
        const _qsInitTextTools = () => {
            if (win._qsToolsInited) return;
            const ta = win.querySelector('#qs-text');
            const bar = win.querySelector('#qs-text-tools');
            if (!ta || !bar) return;

            const _insert = (ins) => {
                const start = ta.selectionStart ?? 0;
                const end = ta.selectionEnd ?? 0;
                const v = ta.value ?? '';
                ta.value = v.slice(0, start) + ins + v.slice(end);
                const caret = start + ins.length;
                ta.focus();
                ta.selectionStart = ta.selectionEnd = caret;
            };

            const _wrap = (open, close) => {
                const start = ta.selectionStart ?? 0;
                const end = ta.selectionEnd ?? 0;
                const v = ta.value ?? '';
                if (start === end) {
                    ta.value = v.slice(0, start) + open + close + v.slice(end);
                    const caret = start + open.length;
                    ta.focus();
                    ta.selectionStart = ta.selectionEnd = caret;
                    return;
                }
                const selected = v.slice(start, end);
                const out = open + selected + close;
                ta.value = v.slice(0, start) + out + v.slice(end);
                ta.focus();
                ta.selectionStart = start;
                ta.selectionEnd = start + out.length;
            };

            bar.addEventListener('click', (e) => {
                const btn = e.target.closest('button');
                if (!btn) return;

                const tag = btn.getAttribute('data-qstag');
                const act = btn.getAttribute('data-action');
                if (!tag && !act) return;

                e.preventDefault();

                if (tag) {
                    _wrap('[' + tag + ']', '[/' + tag + ']');
                    return;
                }
                if (act === 'color') {
                    const colorEl = win.querySelector('#qs-color');
                    const c = (colorEl && colorEl.value) ? colorEl.value : '#ffffff';
                    _wrap('[color=' + c + ']', '[/color]');
                    return;
                }
                if (act === 'size') {
                    const sizeEl = win.querySelector('#qs-size');
                    const s = (sizeEl && sizeEl.value) ? sizeEl.value : '16';
                    _wrap('[size=' + s + ']', '[/size]');
                    return;
                }
                if (act === 'br') {
                    _insert('[br]');
                    return;
                }
            });

            win._qsToolsInited = true;
        };

        const modal = win.querySelector('#ban-modal');
        const panel = win.querySelector('#admin-panel');
        const boot = win.querySelector('#adm-boot');
        const logs = win.querySelector('#adm-logs');
        let pendingBan = null;

        const lines = ['INIT_SECURE_CHANNEL...', 'SYNCING_ACCESS_MATRIX...', 'CORE_READY'];
        let d = 0;
        lines.forEach(l => {
            setTimeout(() => { logs.innerHTML += `<div style='color:#ff3333; margin-bottom:5px'>> ${l}</div>`; }, d);
            d += 300;
        });
        setTimeout(() => { boot.style.display = 'none'; panel.style.display = 'flex'; }, d + 200);

        win.navTo = function(view) {
            win.querySelectorAll('.view-section').forEach(v => v.style.display = 'none');
            win.querySelector('#view-' + view).style.display = 'flex';
        };

        // PROJECTS LOGIC
        win.loadQuestProjects = function() {
            const list = win.querySelector('#project-list');
            list.innerHTML = "<div style='padding:20px; color:#440000'>LOADING_RECORDS...</div>";
            fetch('quest_api.php?action=get_quest_projects', {credentials:'include'})
              .then(r=>r.json())
              .then(res=>{
                const data = Array.isArray(res) ? res : (res.scenes || res.data || res.projects || res.rows || []);
                list.innerHTML = '';
                if(!data.length){
                  list.innerHTML = "<div style='padding:20px; color:#444; text-align:center;'>ПРОЕКТЫ НЕ НАЙДЕНЫ</div>";
                  return;
                }
                data.forEach(p=>{
                    const row = document.createElement('div');
                    row.className = 'pr-row';

                    const left = document.createElement('div');
                    left.style.flex = '1';

                    const title = document.createElement('div');
                    title.style.fontWeight = 'bold';
                    title.style.color = '#fff';
                    title.textContent = p.title;

                    const idLine = document.createElement('div');
                    idLine.style.color = '#666';
                    idLine.style.fontSize = '0.75rem';
                    idLine.textContent = "ID: " + p.id;

                    left.appendChild(title);
                    left.appendChild(idLine);

                    const st = document.createElement('div');
                    st.style.color = (p.is_active == 1 ? '#0f0' : '#f00');
                    st.style.fontWeight = 'bold';
                    st.style.fontSize = '0.8rem';
                    st.style.minWidth = '90px';
                    st.style.textAlign = 'center';
                    st.textContent = (p.is_active == 1 ? 'АКТИВЕН' : 'ВЫКЛ');

                    const btnScenes = document.createElement('button');
                    btnScenes.className = 'qm-btn primary';
                    btnScenes.textContent = 'СЦЕНЫ';
                    btnScenes.addEventListener('click', (ev)=>{ if(ev) ev.stopPropagation(); win.openProject(p.id, p.title); });

                    const btnStatus = document.createElement('button');
                    btnStatus.className = 'qm-btn';
                    btnStatus.textContent = (p.is_active == 1 ? 'ВЫКЛ' : 'ВКЛ');
                    btnStatus.addEventListener('click', (ev)=>{ if(ev) ev.stopPropagation(); win.toggleProject(p.id, p.is_active); });

                    const btnPreview = document.createElement('button');
                    btnPreview.className = 'qm-btn';
                    btnPreview.textContent = 'ПРЕВЬЮ';
                    btnPreview.addEventListener('click', (ev)=>{ if(ev) ev.stopPropagation(); win.previewProject(p.id); });

                    const btnDel = document.createElement('button');
                    btnDel.className = 'qm-btn danger';
                    btnDel.textContent = 'УДАЛИТЬ';
                    btnDel.addEventListener('click', (ev)=>{ if(ev) ev.stopPropagation(); win.deleteProject(p.id, p.title); });

                    row.append(left, st, btnScenes, btnStatus, btnPreview, btnDel);
                    list.appendChild(row);
                });
              })
              .catch(e=>{
                list.innerHTML = "<div style='padding:20px; color:#ff3333'>ОШИБКА ЗАГРУЗКИ ПРОЕКТОВ</div>";
              });
        };

        win.createNewProject = function() {
            const title = prompt('Название проекта:');
            if(!title) return;
            const fd = new FormData();
            fd.append('action', 'save_quest_project');
            fd.append('title', title);
            fetch('quest_api.php', { method: 'POST', body: fd, credentials: 'include' })
              .then(r=>r.json())
              .then(()=>win.loadQuestProjects());
        };

        win.openProject = function(id, title) {
            win.querySelector('#qs-project-id').value = id;
            win.querySelector('#active-project-title').innerText = title;
            win.navTo('quest-maker');
            win.loadQuestScenes(id);
        };

        win.toggleProject = function(id, cur) {
            const fd = new FormData();
            fd.append('action', 'toggle_quest_status');
            fd.append('id', id);
            fd.append('status', (cur == 1 ? 0 : 1));
            fetch('quest_api.php', { method: 'POST', body: fd, credentials: 'include' })
              .then(r=>r.json())
              .then(()=>win.loadQuestProjects());
        };

        // SCENES LOGIC
        win.loadQuestScenes = function(pid) {
            const list = win.querySelector('#scene-list');
            list.innerHTML = "<div style='color:#444; text-align:center; padding-top:20px;'>Загрузка...</div>";

            fetch('quest_api.php?action=get_quest_scenes_by_id&pid=' + encodeURIComponent(pid), {credentials:'include'})
              .then(r => r.text().then(t => ({ ok:r.ok, status:r.status, text:t })))
              .then(({ok, status, text}) => {
                let res;
                try { res = JSON.parse(text); } catch(e) {
                  throw new Error('API вернул не JSON (HTTP '+status+'): ' + text.slice(0,200));
                }

                const scenes = Array.isArray(res) ? res : (res.scenes || res.data || []);
                const orphans = (!Array.isArray(res) && res.orphans) ? res.orphans : [];

                // cache keys for COPY feature
                win._qmSceneKeySet = new Set();
                try {
                    [...(scenes||[]), ...(orphans||[])].forEach(s => {
                        const k = (s && s.scene_key) ? String(s.scene_key).trim() : '';
                        if (k) win._qmSceneKeySet.add(k);
                    });
                } catch(e) {}


                list.innerHTML = '';

                if ((!scenes || !scenes.length) && (!orphans || !orphans.length)) {
                  list.innerHTML = "<div style='color:#444; text-align:center; padding-top:20px;'>Сцены не найдены</div>";
                  return;
                }

                const renderSceneRow = (s, isOrphan=false) => {
                    const item = document.createElement('div');
                    item.className = 'scene-item';
                    item.style.display='flex';
                    item.style.alignItems='center';
                    item.style.gap='8px';

                    const key = (s.scene_key || '').toString();
                    const name = (s.char_name || '').toString();

                    const left = document.createElement('div');
                    left.style.flex='1';
                    left.innerHTML = "<div style='color:#fff; font-weight:bold; font-size:0.85rem'>" + key + (s.is_start==1 ? " <span style='color:#0f0'>(START)</span>" : "") + "</div>"
                                  + "<div style='color:#888; font-size:0.75rem'>" + (name ? ("[" + name + "]") : "") + "</div>";
                    item.appendChild(left);

                    const btnPrev = document.createElement('button');
                    btnPrev.className='qm-mini';
                    btnPrev.textContent='▶';
                    btnPrev.title='Превью сцены';
                    btnPrev.addEventListener('click', (e)=>{ e.stopPropagation(); win.previewScene(pid, key); });

                    const btnDel = document.createElement('button');
                    btnDel.className='qm-mini danger';
                    btnDel.textContent='✖';
                    btnDel.title='Удалить сцену';
                    btnDel.addEventListener('click', (e)=>{ e.stopPropagation(); win.deleteScene(pid, s.id, key); });

                    item.appendChild(btnPrev);

                    if (isOrphan) {
                        const btnAttach = document.createElement('button');
                        btnAttach.className='qm-mini';
                        btnAttach.textContent='ПРИКРЕПИТЬ';
                        btnAttach.title='Прикрепить сцену к этому проекту';
                        btnAttach.addEventListener('click', (e)=>{
                            e.stopPropagation();
                            if(!confirm("Прикрепить сцену " + key + " к проекту #" + pid + "?")) return;
                            // Update only quest_id (API принимает update по id + scene_key)
                            const fd = new FormData();
                            fd.append('action','save_quest_scene');
                            fd.append('id', s.id);
                            fd.append('quest_id', pid);
                            fd.append('scene_key', key || ('scene_' + s.id));
                            fd.append('char_name', s.char_name || '');
                            fd.append('dialogue_text', s.dialogue_text || '');
                            fd.append('bg_url', s.bg_url || '');
                            fd.append('sprite_url', s.sprite_url || '');
                            fd.append('sprite_pos', s.sprite_pos || 'center');
                            fd.append('sprite2_url', s.sprite2_url || '');
                            fd.append('sprite2_pos', s.sprite2_pos || 'right');
                            fd.append('active_speaker', (s.active_speaker!=null ? s.active_speaker : 0));
                            fd.append('transition', s.transition || 'none');
                            fd.append('transition_time', (s.transition_time!=null ? s.transition_time : 700));
                            fd.append('music_url', s.music_url || '');
                            fd.append('sfx_url', s.sfx_url || '');
                            fd.append('popup_url', s.popup_url || '');
                            fd.append('popup_sfx_url', s.popup_sfx_url || '');
                            fd.append('popup_duration', (s.popup_duration!=null ? s.popup_duration : 1200));
                            fd.append('popup_next_key', s.popup_next_key || '');
                            fd.append('choices', s.choices || '[]');
                            fd.append('is_start', (s.is_start==1?1:0));
                            fetch('quest_api.php', { method: 'POST', body: fd, credentials: 'include' })
                              .then(r=>r.json())
                              .then(d=>{
                                if(!d || d.success!==true) throw new Error((d && (d.message||d.error)) || 'attach_failed');
                                win.loadQuestScenes(pid);
                              })
                              .catch(err=>alert('ОШИБКА ПРИКРЕПЛЕНИЯ: ' + (err.message || err)));
                        });
                        item.appendChild(btnAttach);
                    } else {
                        item.appendChild(btnDel);
                        item.addEventListener('click', ()=>win.editQuestScene(s));
                    }

                    return item;
                };

                // --- PAGINATION (max 10 scenes per page) ---
                const PAGE_SIZE = 10;

                // If we just created / copied a scene, jump to the page where it is
                if (win._qmFocusSceneKey) {
                    const fk = String(win._qmFocusSceneKey);
                    const idxFocus = (scenes || []).findIndex(ss => String((ss && ss.scene_key) ? ss.scene_key : '') === fk);
                    if (idxFocus >= 0) {
                        win._qmScenePage = Math.floor(idxFocus / PAGE_SIZE) + 1;
                    }
                    win._qmFocusSceneKey = null;
                }

                // Main scenes pager state
                if (win._qmScenePage == null) win._qmScenePage = 1;
                const totalMain = scenes.length;
                const totalMainPages = Math.max(1, Math.ceil(totalMain / PAGE_SIZE));
                if (win._qmScenePage > totalMainPages) win._qmScenePage = totalMainPages;
                if (win._qmScenePage < 1) win._qmScenePage = 1;

                const renderPager = (page, totalPages, totalItems, onChange) => {
                    const bar = document.createElement('div');
                    bar.style.display = 'flex';
                    bar.style.alignItems = 'center';
                    bar.style.justifyContent = 'center';
                    bar.style.gap = '8px';
                    bar.style.margin = '6px 0 10px';

                    const mkBtn = (txt, disabled, handler) => {
                        const b = document.createElement('button');
                        b.className = 'qm-mini';
                        b.textContent = txt;
                        b.disabled = !!disabled;
                        b.style.minWidth = '34px';
                        b.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            handler();
                        });
                        return b;
                    };

                    const prev = mkBtn('←', page <= 1, () => onChange(page - 1));
                    const next = mkBtn('→', page >= totalPages, () => onChange(page + 1));

                    const info = document.createElement('div');
                    info.style.color = 'rgba(255,255,255,0.65)';
                    info.style.fontSize = '12px';
                    info.textContent = 'СТР. ' + page + '/' + totalPages + ' • СЦЕН: ' + totalItems;

                    bar.appendChild(prev);
                    bar.appendChild(info);
                    bar.appendChild(next);
                    return bar;
                };

                const mainPager = renderPager(win._qmScenePage, totalMainPages, totalMain, (p) => {
                    win._qmScenePage = p;
                    win.loadQuestScenes(pid);
                });
                list.appendChild(mainPager);

                const startIdx = (win._qmScenePage - 1) * PAGE_SIZE;
                scenes.slice(startIdx, startIdx + PAGE_SIZE).forEach(s => list.appendChild(renderSceneRow(s, false)));

                // Orphans (collapsible + pagination)
                if (orphans && orphans.length) {
                    if (win._qmOrphanPage == null) win._qmOrphanPage = 1;
                    const totalOr = orphans.length;
                    const totalOrPages = Math.max(1, Math.ceil(totalOr / PAGE_SIZE));
                    if (win._qmOrphanPage > totalOrPages) win._qmOrphanPage = totalOrPages;
                    if (win._qmOrphanPage < 1) win._qmOrphanPage = 1;

                    const sepWrap = document.createElement('div');
                    sepWrap.style.margin = '12px 0 6px';
                    sepWrap.style.padding = '6px 8px';
                    sepWrap.style.border = '1px dashed rgba(255,255,255,0.15)';
                    sepWrap.style.color = '#ffcc66';
                    sepWrap.style.display = 'flex';
                    sepWrap.style.alignItems = 'center';
                    sepWrap.style.justifyContent = 'space-between';
                    sepWrap.style.gap = '8px';

                    const sepText = document.createElement('div');
                    sepText.textContent = 'НЕПРИВЯЗАННЫЕ СЦЕНЫ (quest_id = NULL): ' + totalOr;
                    sepWrap.appendChild(sepText);

                    const toggleBtn = document.createElement('button');
                    toggleBtn.className = 'qm-mini';
                    toggleBtn.textContent = win._qmOrphansOpen ? 'Скрыть' : 'Показать';
                    toggleBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        win._qmOrphansOpen = !win._qmOrphansOpen;
                        win.loadQuestScenes(pid);
                    });
                    sepWrap.appendChild(toggleBtn);
                    list.appendChild(sepWrap);

                    if (win._qmOrphansOpen) {
                        const opPager = renderPager(win._qmOrphanPage, totalOrPages, totalOr, (p) => {
                            win._qmOrphanPage = p;
                            win.loadQuestScenes(pid);
                        });
                        list.appendChild(opPager);

                        const os = (win._qmOrphanPage - 1) * PAGE_SIZE;
                        orphans.slice(os, os + PAGE_SIZE).forEach(s => list.appendChild(renderSceneRow(s, true)));
                    }
                }
              })
              .catch((err)=>{
                console.error(err);
                list.innerHTML = "<div style='color:#f66; text-align:center; padding-top:20px;'>Ошибка загрузки сцен</div>";
              });
        };

        win.editQuestScene = function(s) {
            if (win._initChoicesEditor) win._initChoicesEditor();
            win.querySelector('#scene-editor').style.display = 'block';
            _qsInitTextTools();
            win.querySelector('#qs-scene-id').value = s ? (s.id || '') : '';
            win.querySelector('#qs-key').value = s ? s.scene_key : '';
            win.querySelector('#qs-name').value = s ? (s.char_name || '') : '';
            win.querySelector('#qs-bg').value = s ? (s.bg_url || '') : '';
            win.querySelector('#qs-sprite').value = s ? (s.sprite_url || '') : '';
            win.querySelector('#qs-music').value = s ? (s.music_url || '') : '';
            win.querySelector('#qs-sfx').value = s ? (s.sfx_url || '') : '';
            win.querySelector('#qs-popup').value = s ? (s.popup_url || '') : '';
            win.querySelector('#qs-popup-sfx').value = s ? (s.popup_sfx_url || '') : '';
            win.querySelector('#qs-popup-time').value = s && (s.popup_duration !== undefined && s.popup_duration !== null) ? String(s.popup_duration) : '1200';
            win.querySelector('#qs-popup-next').value = s ? (s.popup_next_key || '') : '';
            win.querySelector('#qs-text').value = s ? (s.dialogue_text || '') : '';
            win.querySelector('#qs-choices').value = s ? (s.choices || '[]') : '[]';
            win.querySelector('#qs-is-start').checked = s && s.is_start == 1;

            // new fields
            win.querySelector('#qs-sprite-pos').value = s && s.sprite_pos ? s.sprite_pos : 'center';
            win.querySelector('#qs-sprite2').value = s ? (s.sprite2_url || '') : '';
            win.querySelector('#qs-sprite2-pos').value = s && s.sprite2_pos ? s.sprite2_pos : 'right';
            win.querySelector('#qs-active-speaker').value = s && (s.active_speaker !== undefined) ? String(s.active_speaker) : '0';
            win.querySelector('#qs-transition').value = s && s.transition ? s.transition : 'none';
            win.querySelector('#qs-fx-time').value = s && (s.transition_time !== undefined && s.transition_time !== null) ? String(s.transition_time) : '700';
            if (win._renderChoicesFromTextarea) win._renderChoicesFromTextarea();
            if (win._applyMetaToEmotionButtons) win._applyMetaToEmotionButtons();
        };

        // === QuestMaker helpers (safe DOM + robust quest_api) ===
        const _qm$ = (sel) => win.querySelector(sel);
        const _qmVal = (sel, def = '') => {
            const el = _qm$(sel);
            if (!el) return def;
            if (el.type === 'checkbox') return el.checked ? '1' : '0';
            const v = (el.value !== undefined && el.value !== null) ? String(el.value) : '';
            return v;
        };
        const _qmValTrim = (sel, def = '') => _qmVal(sel, def).trim();

        async function _qmPostQuestApi(fd) {
            // FormData can be single-use in some browsers; clone for retries
            const pairs = Array.from(fd.entries());
            const clone = () => {
                const f = new FormData();
                for (const [k, v] of pairs) f.append(k, v);
                return f;
            };

            const urls = ['quest_api.php', '../quest_api.php', 'apps/quest_api.php'];
            let lastErr = null;

            for (const url of urls) {
                try {
                    const r = await fetch(url, { method: 'POST', body: clone(), credentials: 'include' });
                    const txt = await r.text();

                    let json;
                    try {
                        json = JSON.parse(txt);
                    } catch (e) {
                        // Not JSON (likely 404 HTML) — try next candidate
                        lastErr = new Error(`API не JSON (HTTP ${r.status}) по ${url}: ${txt.slice(0, 160)}`);
                        continue;
                    }

                    json.__http_status = r.status;
                    json.__url = url;
                    return json;
                } catch (e) {
                    lastErr = e;
                }
            }
            throw lastErr || new Error('quest_api недоступен');
        }

        win.saveQuestScene = async function() {
            const pid = _qmValTrim('#qs-project-id');
            if (!pid) { alert('Сначала открой проект (СЦЕНЫ).'); return; }

            const fd = new FormData();
            fd.append('action', 'save_quest_scene');
            fd.append('quest_id', pid);

            const sid = _qmValTrim('#qs-scene-id');
            if (sid) fd.append('id', sid);

            const sceneKey = _qmValTrim('#qs-key');
            if (!sceneKey) { alert('SCENE_KEY пустой.'); return; }
            if (sceneKey.length > 50) { alert('SCENE_KEY слишком длинный (макс 50).'); return; }

            fd.append('scene_key', sceneKey);
            fd.append('char_name', _qmVal('#qs-name'));
            fd.append('bg_url', _qmVal('#qs-bg'));
            fd.append('sprite_url', _qmVal('#qs-sprite'));
            fd.append('sprite_pos', _qmValTrim('#qs-sprite-pos', 'center'));

            // optional fields (may be absent in UI)
            fd.append('sprite2_url', _qmVal('#qs-sprite2'));
            fd.append('sprite2_pos', _qmValTrim('#qs-sprite2-pos', 'right'));
            fd.append('active_speaker', _qmValTrim('#qs-active-speaker', '0'));

            // transitions
            fd.append('transition', _qmValTrim('#qs-transition', 'none'));
            const tt = _qmValTrim('#qs-fx-time');
            if (tt !== '') fd.append('transition_time', tt);

            fd.append('music_url', _qmVal('#qs-music'));
            const mv = _qmValTrim('#qs-music-vol'); // optional
            if (mv !== '') fd.append('music_volume', mv);

            fd.append('sfx_url', _qmVal('#qs-sfx'));
            fd.append('popup_url', _qmVal('#qs-popup'));
            fd.append('popup_sfx_url', _qmVal('#qs-popup-sfx'));

            const pd = _qmValTrim('#qs-popup-time');
            if (pd !== '') fd.append('popup_duration', pd);

            fd.append('popup_next_key', _qmValTrim('#qs-popup-next'));
            fd.append('dialogue_text', _qmVal('#qs-text'));
            fd.append('choices', _qmVal('#qs-choices'));

            if (_qm$('#qs-is-start') && _qm$('#qs-is-start').checked) fd.append('is_start', '1');

            try {
                const d = await _qmPostQuestApi(fd);
                if (d && (d.status === 'success' || d.success === true)) {
                    alert('СЦЕНА СОХРАНЕНА');
                    win.loadQuestScenes(pid);
                } else {
                    alert('ОШИБКА: ' + ((d && (d.message || d.error)) ? (d.message || d.error) : 'unknown'));
                }
            } catch (e) {
                alert('ОШИБКА API: ' + (e && e.message ? e.message : e));
            }
        };

        win.copyQuestScene = async function() {
            const pid = _qmValTrim('#qs-project-id');
            if (!pid) { alert('Сначала открой проект (СЦЕНЫ).'); return; }

            const origKey = _qmValTrim('#qs-key');
            if (!origKey) { alert('SCENE_KEY пустой — нечего копировать.'); return; }

            const keySet = (win._qmSceneKeySet instanceof Set) ? win._qmSceneKeySet : new Set();

            const base = origKey + '_copy';
            let cand = base;
            let n = 2;
            while (keySet.has(cand) && n < 10000) { cand = base + '_' + (n++); }

            const entered = prompt('Новый SCENE_KEY для копии:', cand);
            if (entered === null) return;

            const newKey = (entered || '').trim();
            if (!newKey) { alert('Пустой SCENE_KEY.'); return; }
            if (newKey.length > 50) { alert('SCENE_KEY слишком длинный (макс 50).'); return; }
            if (keySet.has(newKey)) { alert('Такой SCENE_KEY уже существует в этом проекте.'); return; }

            const fd = new FormData();
            fd.append('action', 'save_quest_scene');
            fd.append('quest_id', pid);
            // IMPORTANT: do not send id — create new scene
            fd.append('scene_key', newKey);

            // copy all fields from current editor (best-effort; optional inputs may not exist)
            fd.append('char_name', _qmVal('#qs-name'));
            fd.append('bg_url', _qmVal('#qs-bg'));
            fd.append('sprite_url', _qmVal('#qs-sprite'));
            fd.append('sprite_pos', _qmValTrim('#qs-sprite-pos', 'center'));

            fd.append('sprite2_url', _qmVal('#qs-sprite2'));
            fd.append('sprite2_pos', _qmValTrim('#qs-sprite2-pos', 'right'));
            fd.append('active_speaker', _qmValTrim('#qs-active-speaker', '0'));

            // transitions
            fd.append('transition', _qmValTrim('#qs-transition', 'none'));
            const tt = _qmValTrim('#qs-fx-time');
            if (tt !== '') fd.append('transition_time', tt);

            fd.append('music_url', _qmVal('#qs-music'));
            const mv = _qmValTrim('#qs-music-vol');
            if (mv !== '') fd.append('music_volume', mv);

            fd.append('sfx_url', _qmVal('#qs-sfx'));
            fd.append('popup_url', _qmVal('#qs-popup'));
            fd.append('popup_sfx_url', _qmVal('#qs-popup-sfx'));

            const pd = _qmValTrim('#qs-popup-time');
            if (pd !== '') fd.append('popup_duration', pd);

            fd.append('popup_next_key', _qmValTrim('#qs-popup-next'));

            fd.append('dialogue_text', _qmVal('#qs-text'));
            fd.append('choices', _qmVal('#qs-choices'));

            // if original scene was start, copy should NOT automatically become start
            // (uncomment next line if you actually want to keep it)
            // if (_qm$('#qs-is-start') && _qm$('#qs-is-start').checked) fd.append('is_start', '1');

            try {
                const d = await _qmPostQuestApi(fd);
                if (d && (d.status === 'success' || d.success === true)) {
                    const newId = (d.id || d.scene_id || d.insert_id || '');
                    win.querySelector('#qs-scene-id').value = newId ? String(newId) : '';
                    win.querySelector('#qs-key').value = newKey;

                    try { keySet.add(newKey); win._qmSceneKeySet = keySet; } catch(e) {}

                    alert('СЦЕНА СКОПИРОВАНА');

                    // Focus the new scene in the list/pagination (if the list supports it)
                    win._qmFocusSceneKey = newKey;
                    win.loadQuestScenes(pid);
                } else {
                    alert('ОШИБКА: ' + ((d && (d.message || d.error)) ? (d.message || d.error) : 'unknown'));
                }
            } catch (e) {
                alert('ОШИБКА API: ' + (e && e.message ? e.message : e));
            }
        };


        // CORE ADMIN LOGIC
        win.preSave = function(uid, name, btn) {
            const select = win.querySelector('#perm-' + uid);
            if (select.value == 0) {
                win.querySelector('#ban-target-name').innerText = name;
                win.querySelector('#ban-reason-text').value = '';
                pendingBan = { uid: uid, btn: btn };
                modal.style.display = 'flex';
            } else { updatePerms(uid, select.value, '', btn); }
        };

        function updatePerms(uid, lvl, reason, btn) {
            const icon = btn.querySelector('i');
            icon.className = 'fas fa-spinner fa-spin';
            const fd = new FormData();
            fd.append('action', 'update_access');
            fd.append('target_id', uid);
            fd.append('access_level', lvl);
            fd.append('ban_reason', reason);
            fetch('api.php', { method: 'POST', body: fd, credentials: 'include' }).then(r => r.json()).then(d => {
                if (d && (d.status === 'success' || d.success === true)) {
                    icon.className = 'fas fa-check';
                } else {
                    icon.className = 'fas fa-exclamation-triangle';
                    alert('ОШИБКА СОХРАНЕНИЯ ДОСТУПА: ' + ((d && (d.message||d.error)) ? (d.message||d.error) : 'unknown'));
                }
                setTimeout(() => { icon.className = 'fas fa-save'; }, 1200);
            });
        }

        win.confirmBan = function() {
            updatePerms(pendingBan.uid, 0, win.querySelector('#ban-reason-text').value, pendingBan.btn);
            modal.style.display = 'none';
        };
        win.closeBanModal = function() { modal.style.display = 'none'; };

        
        
        // --- CHOICES EDITOR (no manual JSON) ---
        win._choicesBound = false;

        win._syncChoicesJson = function() {
            const rows = win.querySelectorAll('.qm-choice-row');
            const out = [];
            for (let i=0;i<rows.length;i++){
                const r = rows[i];
                const t = r.querySelector('.qm-choice-text').value.trim();
                const n = r.querySelector('.qm-choice-next').value.trim();
                if (!t && !n) continue;
                out.push({ text: t || '...', next: n });
            }
            win.querySelector('#qs-choices').value = JSON.stringify(out);
        };

        win._makeChoiceRow = function(val) {
            const row = document.createElement('div');
            row.className = 'qm-choice-row';
            row.style.display = 'flex';
            row.style.gap = '8px';
            row.style.alignItems = 'center';

            const inpText = document.createElement('input');
            inpText.type = 'text';
            inpText.className = 'am-select qm-choice-text';
            inpText.placeholder = 'Текст варианта';
            inpText.style.flex = '2';
            inpText.value = (val && val.text) ? String(val.text) : '';

            const inpNext = document.createElement('input');
            inpNext.type = 'text';
            inpNext.className = 'am-select qm-choice-next';
            inpNext.placeholder = 'scene_key куда';
            inpNext.style.flex = '1';
            inpNext.value = (val && (val.next || val.to || val.key || val.scene_key)) ? String(val.next || val.to || val.key || val.scene_key) : '';

            const btnDel = document.createElement('button');
            btnDel.type = 'button';
            btnDel.className = 'qm-mini danger';
            btnDel.textContent = '✖';
            btnDel.title = 'Удалить вариант';
            btnDel.addEventListener('click', ()=>{ row.remove(); win._syncChoicesJson(); });

            inpText.addEventListener('input', win._syncChoicesJson);
            inpNext.addEventListener('input', win._syncChoicesJson);

            row.appendChild(inpText);
            row.appendChild(inpNext);
            row.appendChild(btnDel);
            return row;
        };

        win._renderChoicesFromTextarea = function() {
            const rowsWrap = win.querySelector('#qs-choices-rows');
            if (!rowsWrap) return;
            rowsWrap.innerHTML = '';
            let arr = [];
            try {
                const raw = win.querySelector('#qs-choices').value || '[]';
                arr = JSON.parse(raw);
                if (!Array.isArray(arr)) arr = [];
            } catch(e) {
                arr = [];
            }
            if (!arr.length) {
                // one empty row by default
                rowsWrap.appendChild(win._makeChoiceRow({text:'',next:''}));
            } else {
                arr.forEach(v=>rowsWrap.appendChild(win._makeChoiceRow(v)));
            }
            win._syncChoicesJson();
        };

        win._initChoicesEditor = function() {
            if (win._choicesBound) return;
            win._choicesBound = true;
            const btn = win.querySelector('#qs-add-choice');
            if (btn) {
                btn.addEventListener('click', ()=>{
                    const rowsWrap = win.querySelector('#qs-choices-rows');
                    rowsWrap.appendChild(win._makeChoiceRow({text:'',next:''}));
                    win._syncChoicesJson();
                });
            }
        };

        // --- PROJECT META (emotion packs) ---
        win._metaCache = {};
        win.loadProjectMeta = function(pid) {
            if (!pid) return;
            fetch('quest_api.php?action=get_project_meta&pid=' + encodeURIComponent(pid), {credentials:'include'})
              .then(r=>r.json())
              .then(res=>{
                const m = (res && (res.data || res.meta || res.project_meta)) || {};
                win._metaCache[pid] = m;
                const p1 = win.querySelector('#qm-meta-p1'); const e1 = win.querySelector('#qm-meta-e1');
                const p2 = win.querySelector('#qm-meta-p2'); const e2 = win.querySelector('#qm-meta-e2');
                if (p1) p1.value = m.p1_pattern || '';
                if (e1) e1.value = m.p1_emotions || '';
                if (p2) p2.value = m.p2_pattern || '';
                if (e2) e2.value = m.p2_emotions || '';
                if (win._applyMetaToEmotionButtons) win._applyMetaToEmotionButtons();
              })
              .catch(()=>{});
        };

        win._applyMetaToEmotionButtons = function() {
            const pid = win.querySelector('#qs-project-id') ? win.querySelector('#qs-project-id').value : '';
            if (!pid) return;
            const meta = win._metaCache[pid] || {};
            const wrap1 = win.querySelector('#qs-emotions1');
            const wrap2 = win.querySelector('#qs-emotions2');
            if (!wrap1 || !wrap2) return;

            const makeBtns = (wrap, pattern, list, targetInputId) => {
                wrap.innerHTML = '';
                pattern = (pattern || '').trim();
                const emotions = (list || '').split(',').map(s=>s.trim()).filter(Boolean);
                if (!pattern || !emotions.length) {
                    wrap.innerHTML = "<span style='color:#555; font-size:0.8rem;'>нет пакета</span>";
                    return;
                }
                emotions.forEach(em=>{
                    const b = document.createElement('button');
                    b.type='button';
                    b.className='qm-mini';
                    b.textContent = em.toUpperCase();
                    b.addEventListener('click', ()=>{
                        const inp = win.querySelector('#'+targetInputId);
                        if (!inp) return;
                        inp.value = pattern.replace('{emotion}', em);
                    });
                    wrap.appendChild(b);
                });
            };

            makeBtns(wrap1, meta.p1_pattern, meta.p1_emotions, 'qs-sprite');
            makeBtns(wrap2, meta.p2_pattern, meta.p2_emotions, 'qs-sprite2');
        };

        win.saveProjectMeta = function() {
            const pid = win.querySelector('#qs-project-id').value;
            if (!pid) return;
            const fd = new FormData();
            fd.append('action', 'save_project_meta');
            fd.append('pid', pid);
            fd.append('p1_pattern', win.querySelector('#qm-meta-p1').value || '');
            fd.append('p1_emotions', win.querySelector('#qm-meta-e1').value || '');
            fd.append('p2_pattern', win.querySelector('#qm-meta-p2').value || '');
            fd.append('p2_emotions', win.querySelector('#qm-meta-e2').value || '');
            const st = win.querySelector('#qm-meta-status');
            if (st) st.textContent = 'сохранение...';
            fetch('quest_api.php', { method: 'POST', body: fd, credentials: 'include' })
              .then(r=>r.json())
              .then(res=>{
                if (st) st.textContent = (res.status==='success') ? 'OK' : ('ошибка: ' + (res.message||''));
                win.loadProjectMeta(pid);
              })
              .catch(()=>{ if (st) st.textContent='ошибка сети'; });
        };

// --- QUEST MAKER EXTRA ---
        win.previewProject = function(pid, sceneId) {
            // preview whole project or a specific scene (admin only)
            const url = sceneId ? ('apps/quest.php?preview_project=' + encodeURIComponent(pid) + '&preview_scene_id=' + encodeURIComponent(sceneId))
                                : ('apps/quest.php?preview_project=' + encodeURIComponent(pid));
            if (typeof openApp === 'function') {
                openApp(url, 'ПРЕВЬЮ КВЕСТА', 'fas fa-scroll');
            } else {
                window.open(url, '_blank');
            }
        };

        win.previewActiveProject = function() {
            const pid = win.querySelector('#qs-project-id').value;
            if(!pid) { alert('Сначала открой проект (СЦЕНЫ).'); return; }
            win.previewProject(pid);
        };

        window.previewQuestScene = function() {
            const pid = win.querySelector('#qs-project-id').value;
            const sid = win.querySelector('#qs-scene-id').value;
            if(!pid || !sid) { alert('Открой сцену для превью.'); return; }
            win.previewProject(pid, sid);
        };

        win.deleteProject = function(id, title) {
            if(!confirm('Удалить проект "' + title + '" и все его сцены?')) return;
            const fd = new FormData();
            fd.append('action','delete_quest_project');
            fd.append('id', id);
            fetch('quest_api.php', { method: 'POST', body: fd, credentials: 'include' })
              .then(r=>r.json())
              .then(d=>{
                if(d.status==='success' || d.success===true) win.loadQuestProjects();
                else alert('ОШИБКА: ' + (d.message||d.error||'unknown'));
              })
              .catch(e=>alert('ОШИБКА УДАЛЕНИЯ: ' + e));
        };

        win.deleteScene = function(pid, id, key) {
            if(!confirm('Удалить сцену "' + key + '"?')) return;
            const fd = new FormData();
            fd.append('action','delete_quest_scene');
            fd.append('quest_id', pid);
            fd.append('id', id);
            fetch('quest_api.php', { method: 'POST', body: fd, credentials: 'include' })
              .then(r=>r.json())
              .then(d=>{
                if (d && (d.status==='success' || d.success===true)) win.loadQuestScenes(pid);
                else alert('ОШИБКА: ' + (d.message||'unknown'));
              });
        };

        window.navTo = win.navTo;
        window.loadQuestProjects = win.loadQuestProjects;
        window.createNewProject = win.createNewProject;
        window.openProject = win.openProject;
        window.toggleProject = win.toggleProject;
        window.loadQuestScenes = win.loadQuestScenes;
        window.editQuestScene = win.editQuestScene;
        window.saveQuestScene = win.saveQuestScene;
        window.copyQuestScene = win.copyQuestScene;
        window.previewActiveProject = win.previewActiveProject;
        window.saveProjectMeta = win.saveProjectMeta;
        window.previewProject = win.previewProject;
        window.deleteProject = win.deleteProject;
        window.deleteScene = win.deleteScene;
        window.preSave = win.preSave;
        window.confirmBan = win.confirmBan;
        window.closeBanModal = win.closeBanModal;
    })(this);</script>
</div>
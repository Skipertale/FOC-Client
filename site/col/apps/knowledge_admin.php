<?php
require_once '../config/db.php';
require_once '../config/knowledge_base.php';
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['access_level'] ?? 0) < 5) {
    exit('<div style="color:red; padding:20px; font-family:Share Tech Mono, monospace;">CRITICAL ERROR: ACCESS DENIED</div>');
}

kbEnsureSchema($pdo);
?>

<div class="kbm-app">
  <input class="kbm-tab" type="radio" name="kbm-tab" id="kbm-rules" checked>
  <input class="kbm-tab" type="radio" name="kbm-tab" id="kbm-abilities">

  <div class="kbm-head">
    <div class="kbm-title">
      <div class="kbm-badge"><i class="fas fa-book"></i></div>
      <div>
        <div class="kbm-h1">KB_EDITOR</div>
        <div class="kbm-sub">Правила и способности — карточками, с HTML-форматированием</div>
      </div>
    </div>
    <div class="kbm-tabs">
      <label class="kbm-tabbtn" for="kbm-rules"><i class="fas fa-scroll"></i> ПРАВИЛА</label>
      <label class="kbm-tabbtn" for="kbm-abilities"><i class="fas fa-bolt"></i> СПОСОБНОСТИ</label>
    </div>
  </div>

  <div class="kbm-shell">
    <!-- RULES PANEL -->
    <section class="kbm-panel rules">
      <div class="kbm-left">
        <div class="kbm-left-head">
          <input id="kbm-rules-search" class="kbm-search" placeholder="поиск правил...">
          <button class="kbm-btn primary" onclick="KBM.newRule()"><i class="fas fa-plus"></i></button>
        </div>
        <div id="kbm-rules-list" class="kbm-list"></div>
      </div>

      <div class="kbm-right">
        <div class="kbm-form-head">
          <div class="kbm-form-title"><i class="fas fa-pen"></i> РЕДАКТОР ПРАВИЛА</div>
          <div class="kbm-actions">
            <button class="kbm-btn" onclick="KBM.saveRule()"><i class="fas fa-save"></i> Сохранить</button>
            <button class="kbm-btn danger" onclick="KBM.deleteRule()"><i class="fas fa-trash"></i></button>
          </div>
        </div>

        <div class="kbm-form">
          <input type="hidden" id="kbm-rule-id" value="0">
          <div class="kbm-grid">
            <div class="kbm-field">
              <label>Заголовок</label>
              <input id="kbm-rule-title" class="kbm-input" placeholder="Напр: Протокол режима">
            </div>
            <div class="kbm-field">
              <label>Категория / CHIP</label>
              <input id="kbm-rule-category" class="kbm-input" placeholder="Напр: CORE / SOC / LAW">
            </div>
            <div class="kbm-field">
              <label>Сортировка</label>
              <input id="kbm-rule-sort" class="kbm-input" type="number" value="0">
            </div>
            <div class="kbm-field">
              <label>Статус</label>
              <select id="kbm-rule-active" class="kbm-input">
                <option value="1" selected>АКТИВНО</option>
                <option value="0">СКРЫТО</option>
              </select>
            </div>
          </div>

          <div class="kbm-split">
            <div class="kbm-field">
              <label>HTML контент (можно таблицы/списки/blockquote)</label>
              <div class="kbm-toolbar">
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('H2')">H2</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('P')">P</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('LIST')">LIST</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('CHECK')">CHECK</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('QUOTE')">QUOTE</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('NOTE')">NOTE</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('ALERT')">ALERT</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('FRAME_C')">FRAME</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('FRAME_A')">FRAME+</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('SPOILER')">SPOILER</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('COL2')">2-COL</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('KPI')">KPI</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('TIME')">TIME</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('TAGS')">TAGS</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('TABLE')">TABLE+</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('DIV')">DIV</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('CODE')">CODE</button>
              </div>
              <textarea id="kbm-rule-body" class="kbm-text" placeholder="Вставь HTML..." onfocus="KBM.setBody(this.id)" oninput="KBM.previewRule()"></textarea>
            </div>
            <div class="kbm-field">
              <label>Превью (в стиле KB)</label>
              <div id="kbm-rule-preview" class="kbm-preview"></div>
            </div>
          </div>

          <div class="kbm-hint">
            <b>Фишка:</b> ты можешь делать “плашки” прямо через HTML: <code>&lt;div class=&quot;kb-note&quot;&gt;...&lt;/div&gt;</code> — и они в KB будут выглядеть как надо.
          </div>
        </div>
      </div>
    </section>

    <!-- ABILITIES PANEL -->
    <section class="kbm-panel abilities">
      <div class="kbm-left">
        <div class="kbm-left-head">
          <input id="kbm-abilities-search" class="kbm-search" placeholder="поиск способностей...">
          <button class="kbm-btn primary" onclick="KBM.newAbility()"><i class="fas fa-plus"></i></button>
        </div>
        <div id="kbm-abilities-list" class="kbm-list"></div>
      </div>

      <div class="kbm-right">
        <div class="kbm-form-head">
          <div class="kbm-form-title"><i class="fas fa-pen"></i> РЕДАКТОР СПОСОБНОСТИ</div>
          <div class="kbm-actions">
            <button class="kbm-btn" onclick="KBM.saveAbility()"><i class="fas fa-save"></i> Сохранить</button>
            <button class="kbm-btn danger" onclick="KBM.deleteAbility()"><i class="fas fa-trash"></i></button>
          </div>
        </div>

        <div class="kbm-form">
          <input type="hidden" id="kbm-ability-id" value="0">
          <div class="kbm-grid">
            <div class="kbm-field">
              <label>Название</label>
              <input id="kbm-ability-name" class="kbm-input" placeholder="Напр: Сканирование">
            </div>
            <div class="kbm-field">
              <label>Тип / CLASS</label>
              <input id="kbm-ability-type" class="kbm-input" placeholder="Напр: ACTIVE / PASSIVE">
            </div>
            <div class="kbm-field">
              <label>Стоимость</label>
              <input id="kbm-ability-cost" class="kbm-input" placeholder="Напр: 2 ОЗ">
            </div>
            <div class="kbm-field">
              <label>Откат</label>
              <input id="kbm-ability-cd" class="kbm-input" placeholder="Напр: 2 сцены">
            </div>
            <div class="kbm-field">
              <label>Теги</label>
              <input id="kbm-ability-tags" class="kbm-input" placeholder="Напр: stealth, scan, control">
            </div>
            <div class="kbm-field">
              <label>Сортировка</label>
              <input id="kbm-ability-sort" class="kbm-input" type="number" value="0">
            </div>
            <div class="kbm-field">
              <label>Статус</label>
              <select id="kbm-ability-active" class="kbm-input">
                <option value="1" selected>АКТИВНО</option>
                <option value="0">СКРЫТО</option>
              </select>
            </div>
            <div></div>
          </div>

          <div class="kbm-split">
            <div class="kbm-field">
              <label>HTML описание (можно таблицы/списки/blockquote)</label>
              <div class="kbm-toolbar">
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('H2')">H2</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('P')">P</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('LIST')">LIST</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('CHECK')">CHECK</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('QUOTE')">QUOTE</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('NOTE')">NOTE</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('ALERT')">ALERT</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('FRAME_C')">FRAME</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('FRAME_A')">FRAME+</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('SPOILER')">SPOILER</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('COL2')">2-COL</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('KPI')">KPI</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('TIME')">TIME</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('TAGS')">TAGS</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('TABLE')">TABLE+</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('DIV')">DIV</button>
                <button class="kbm-tool" type="button" onclick="KBM.insTpl('CODE')">CODE</button>
              </div>
              <textarea id="kbm-ability-desc" class="kbm-text" placeholder="Вставь HTML..." onfocus="KBM.setBody(this.id)" oninput="KBM.previewAbility()"></textarea>
            </div>
            <div class="kbm-field">
              <label>Превью</label>
              <div id="kbm-ability-preview" class="kbm-preview"></div>
            </div>
          </div>

          <div class="kbm-hint">
            <b>Подсказка:</b> скрипты и on* атрибуты будут вырезаны на выводе. Можно использовать <code>class</code>/<code>style</code>.
          </div>
        </div>
      </div>
    </section>
  </div>
</div>

<style>
  .kbm-app, .kbm-app *{ box-sizing:border-box; }
  .kbm-app{ height:100%; display:flex; flex-direction:column; font-family:'Share Tech Mono', monospace; color:rgba(255,255,255,0.92); }

  .kbm-head{
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding:12px; border:1px solid rgba(0,255,204,0.22); border-radius:14px;
    background: rgba(0,0,0,0.30);
    box-shadow: 0 0 22px rgba(0,255,204,0.10);
  }
  .kbm-title{ display:flex; align-items:center; gap:12px; }
  .kbm-badge{ width:42px;height:42px;border-radius:14px; display:grid;place-items:center;
    border:1px solid rgba(0,255,204,0.30); background: rgba(0,255,204,0.10);
    box-shadow:0 0 18px rgba(0,255,204,0.12);
  }
  .kbm-h1{ font-size:1.05rem; letter-spacing:1px; text-transform:uppercase; color:#fff; }
  .kbm-sub{ font-size:0.78rem; color:rgba(255,255,255,0.65); }

  .kbm-tabs{ display:flex; gap:8px; }
  .kbm-tab{ position:absolute; opacity:0; pointer-events:none; }
  .kbm-tabbtn{
    display:inline-flex; align-items:center; gap:8px;
    padding:9px 12px; border-radius:12px; cursor:pointer; user-select:none;
    border:1px solid rgba(0,255,204,0.22); background: rgba(0,0,0,0.24);
    color:rgba(255,255,255,0.82); transition:0.18s;
    text-transform:uppercase; letter-spacing:0.6px; font-size:0.85rem;
  }
  .kbm-tabbtn i{ color: rgba(0,255,204,0.85); text-shadow:0 0 10px rgba(0,255,204,0.25); }
  .kbm-tabbtn:hover{ transform:translateY(-1px); background: rgba(0,255,204,0.08); border-color: rgba(0,255,204,0.42); }
  #kbm-rules:checked ~ .kbm-head .kbm-tabbtn[for="kbm-rules"],
  #kbm-abilities:checked ~ .kbm-head .kbm-tabbtn[for="kbm-abilities"]{
    background: rgba(0,255,204,0.12); border-color: rgba(0,255,204,0.55); color:#fff;
    box-shadow:0 0 16px rgba(0,255,204,0.12);
  }

  .kbm-shell{ flex:1; min-height:0; margin-top:12px; border:1px solid rgba(0,255,204,0.18); border-radius:16px; background: rgba(0,0,0,0.26); overflow:hidden; }
  .kbm-panel{ height:100%; display:none; }
  #kbm-rules:checked ~ .kbm-shell .kbm-panel.rules{ display:flex; }
  #kbm-abilities:checked ~ .kbm-shell .kbm-panel.abilities{ display:flex; }

  .kbm-left{ width:360px; max-width:44%; border-right:1px solid rgba(0,255,204,0.14); background: rgba(0,0,0,0.18); display:flex; flex-direction:column; }
  .kbm-left-head{ display:flex; gap:8px; padding:12px; border-bottom:1px solid rgba(0,255,204,0.12); }
  .kbm-search{ flex:1; padding:10px 12px; border-radius:12px; border:1px solid rgba(0,255,204,0.18); background: rgba(0,0,0,0.40); color:#fff; outline:none; }
  .kbm-search:focus{ border-color: rgba(0,255,204,0.50); box-shadow:0 0 0 2px rgba(0,255,204,0.10); }
  .kbm-list{ flex:1; overflow:auto; padding:12px; display:flex; flex-direction:column; gap:10px; }

  .kbm-item{
    border-radius:14px; border:1px solid rgba(0,255,204,0.18); background: rgba(0,0,0,0.22);
    padding:10px 12px; cursor:pointer; position:relative; overflow:hidden; transition:0.18s;
  }
  .kbm-item::before{ content:""; position:absolute; inset:0; background: radial-gradient(480px 140px at 10% 0%, rgba(0,255,204,0.14), transparent 55%); opacity:0.9; pointer-events:none; }
  .kbm-item:hover{ transform: translateY(-1px); border-color: rgba(0,255,204,0.45); background: rgba(0,255,204,0.06); }
  .kbm-item.active{ border-color: rgba(255,140,0,0.55); box-shadow:0 0 18px rgba(255,140,0,0.10); }
  .kbm-item .t{ font-weight:bold; color:#fff; letter-spacing:0.6px; text-transform:uppercase; }
  .kbm-item .m{ margin-top:4px; font-size:0.78rem; color: rgba(255,255,255,0.65); display:flex; gap:8px; flex-wrap:wrap; }
  .kbm-chip{ padding:2px 8px; border-radius:999px; border:1px solid rgba(0,255,204,0.22); background: rgba(0,255,204,0.08); font-size:0.72rem; color: rgba(255,255,255,0.82); }
  .kbm-chip.off{ border-color: rgba(255,255,255,0.16); background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.65); }

  .kbm-right{ flex:1; min-width:0; display:flex; flex-direction:column; }
  .kbm-form-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px; border-bottom:1px solid rgba(0,255,204,0.12); }
  .kbm-form-title{ font-weight:bold; letter-spacing:0.8px; text-transform:uppercase; color:#fff; }
  .kbm-actions{ display:flex; gap:10px; }

  .kbm-btn{ display:inline-flex; align-items:center; gap:8px; padding:9px 12px; border-radius:12px;
    border:1px solid rgba(0,255,204,0.22); background: rgba(0,0,0,0.30); color: rgba(255,255,255,0.88);
    cursor:pointer; transition:0.18s; font-family:inherit;
  }
  .kbm-btn:hover{ transform: translateY(-1px); background: rgba(0,255,204,0.08); border-color: rgba(0,255,204,0.45); }
  .kbm-btn.primary{ width:40px; justify-content:center; padding:0; }
  .kbm-btn.danger{ border-color: rgba(255,140,0,0.35); background: rgba(255,140,0,0.08); }
  .kbm-btn.danger:hover{ border-color: rgba(255,140,0,0.65); background: rgba(255,140,0,0.12); }

  .kbm-form{ flex:1; min-height:0; overflow:auto; padding:12px; }
  .kbm-grid{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
  .kbm-field label{ display:block; font-size:0.76rem; color: rgba(255,255,255,0.62); margin-bottom:6px; text-transform:uppercase; letter-spacing:0.6px; }
  .kbm-input{ width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(0,255,204,0.18); background: rgba(0,0,0,0.40); color:#fff; outline:none; }
  .kbm-input:focus{ border-color: rgba(0,255,204,0.50); box-shadow:0 0 0 2px rgba(0,255,204,0.10); }
  .kbm-split{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px; }
  .kbm-text{ width:100%; min-height:220px; resize:vertical; padding:12px; border-radius:14px; border:1px solid rgba(0,255,204,0.16); background: rgba(0,0,0,0.40); color:#fff; outline:none; font-family:inherit; }
  .kbm-toolbar{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; }
  .kbm-tool{ padding:6px 10px; border-radius:10px; border:1px solid rgba(0,255,204,0.18); background: rgba(0,0,0,0.28); color:#fff; cursor:pointer; font-family:inherit; font-size:12px; }
  .kbm-tool:hover{ border-color: rgba(0,255,204,0.45); background: rgba(0,255,204,0.08); }
  .kbm-preview{ min-height:220px; border-radius:14px; border:1px solid rgba(0,255,204,0.16); background: rgba(0,0,0,0.26); padding:12px; overflow:auto; }
  .kbm-hint{ margin-top:12px; padding:10px 12px; border-radius:14px; border:1px solid rgba(0,255,204,0.16); background: rgba(0,255,204,0.06); color: rgba(255,255,255,0.80); font-size:0.82rem; }

  /* --- Pretty KB formatting preview (subset of apps/knowledge_base.php) --- */
  .kbm-preview p{ margin:8px 0; line-height:1.45; color: rgba(255,255,255,0.82); }
  .kbm-preview ul, .kbm-preview ol{ margin:8px 0 8px 18px; padding:0; }
  .kbm-preview li{ margin:6px 0; }
  .kbm-preview code{ padding:2px 6px; border-radius:8px; border:1px solid rgba(0,255,204,0.18); background: rgba(0,0,0,0.25); color: rgba(255,255,255,0.92); }
  .kbm-preview pre{ padding:10px 12px; border-radius:14px; border:1px solid rgba(0,255,204,0.16); background: rgba(0,0,0,0.28); overflow:auto; }

  .kbm-preview .kb-note{
    border-radius:14px; border:1px solid rgba(0,255,204,0.26);
    background: rgba(0,255,204,0.06); padding:10px 12px;
  }
  .kbm-preview .kb-callout{
    border-radius:14px; border:1px solid rgba(255,140,0,0.28);
    background: rgba(255,140,0,0.06); padding:10px 12px;
  }
  .kbm-preview .small{ font-size:0.78rem; color: rgba(255,255,255,0.68); margin-top:6px; }

  .kbm-preview .kb-divider{ height:1px; background: rgba(0,255,204,0.16); margin:12px 0; }

  .kbm-preview .kb-table{ width:100%; border-collapse:separate; border-spacing:0; overflow:hidden; border-radius:14px;
    border:1px solid rgba(0,255,204,0.22); background: rgba(0,0,0,0.18);
  }
  .kbm-preview .kb-table th, .kbm-preview .kb-table td{ padding:10px 10px; border-bottom:1px solid rgba(0,255,204,0.12); font-size:0.82rem; }
  .kbm-preview .kb-table th{ text-align:left; color:#fff; background: rgba(0,255,204,0.10); letter-spacing:0.6px; }
  .kbm-preview .kb-table tr:nth-child(even) td{ background: rgba(255,255,255,0.03); }
  .kbm-preview .kb-table tr:last-child td{ border-bottom:none; }

  .kbm-preview .kb-badges{ display:flex; gap:8px; flex-wrap:wrap; }
  .kbm-preview .kb-badge{ padding:3px 8px; border-radius:999px; border:1px solid rgba(0,255,204,0.25); background: rgba(0,255,204,0.08);
    font-size:0.72rem; color: rgba(255,255,255,0.86); letter-spacing:0.6px;
  }

  .kbm-preview .kb-frame{ position:relative; border-radius:16px; border:1px solid rgba(0,255,204,0.26); background: rgba(0,0,0,0.20); overflow:hidden; }
  .kbm-preview .kb-frame::before{ content:""; position:absolute; inset:0; background: radial-gradient(620px 220px at 12% 0%, rgba(0,255,204,0.18), transparent 55%); pointer-events:none; }
  .kbm-preview .kb-frame.orange{ border-color: rgba(255,140,0,0.30); }
  .kbm-preview .kb-frame.orange::before{ background: radial-gradient(620px 220px at 12% 0%, rgba(255,140,0,0.18), transparent 55%); }
  .kbm-preview .kb-frame.purple{ border-color: rgba(155,89,182,0.30); }
  .kbm-preview .kb-frame.purple::before{ background: radial-gradient(620px 220px at 12% 0%, rgba(155,89,182,0.18), transparent 55%); }
  .kbm-preview .kb-frame-title{ padding:10px 12px; border-bottom:1px solid rgba(0,255,204,0.14); text-transform:uppercase; letter-spacing:0.8px; font-weight:bold; color:#fff; }
  .kbm-preview .kb-frame-body{ padding:10px 12px; }
  .kbm-preview .kb-mini{ font-size:0.74rem; color: rgba(255,255,255,0.62); font-weight:normal; }

  .kbm-preview .kb-cols{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }

  .kbm-preview .kb-spoiler{ border-radius:14px; border:1px solid rgba(0,255,204,0.18); background: rgba(0,0,0,0.20); overflow:hidden; }
  .kbm-preview .kb-spoiler > summary{ cursor:pointer; user-select:none; padding:10px 12px; list-style:none; color:#fff; font-weight:bold; text-transform:uppercase; letter-spacing:0.6px; }
  .kbm-preview .kb-spoiler > summary::-webkit-details-marker{ display:none; }
  .kbm-preview .kb-spoiler-body{ padding:10px 12px; border-top:1px solid rgba(0,255,204,0.12); }

  .kbm-preview .kb-kpis{ display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px; }
  .kbm-preview .kb-kpi{ border-radius:14px; border:1px solid rgba(0,255,204,0.18); background: rgba(0,0,0,0.20); padding:10px 12px; }
  .kbm-preview .kb-kpi .k{ font-size:0.72rem; color: rgba(255,255,255,0.62); letter-spacing:0.8px; text-transform:uppercase; }
  .kbm-preview .kb-kpi .v{ font-size:1.05rem; color:#fff; margin-top:6px; letter-spacing:0.6px; }
  .kbm-preview .kb-kpi .d{ margin-top:6px; color: rgba(255,255,255,0.72); font-size:0.80rem; }

  .kbm-preview .kb-timeline{ display:flex; flex-direction:column; gap:10px; }
  .kbm-preview .kb-tl-item{ display:grid; grid-template-columns: 16px 1fr; gap:10px; align-items:start; }
  .kbm-preview .kb-tl-dot{ width:12px; height:12px; border-radius:999px; margin-top:3px;
    background: rgba(0,255,204,0.75); box-shadow:0 0 14px rgba(0,255,204,0.22);
  }
  .kbm-preview .kb-tl-content{ border-radius:14px; border:1px solid rgba(0,255,204,0.18); background: rgba(0,0,0,0.20); padding:10px 12px; }

  /* scroll */
  .kbm-list::-webkit-scrollbar, .kbm-form::-webkit-scrollbar, .kbm-preview::-webkit-scrollbar{ width:10px; }
  .kbm-list::-webkit-scrollbar-track, .kbm-form::-webkit-scrollbar-track, .kbm-preview::-webkit-scrollbar-track{ background: rgba(0,0,0,0.25); }
  .kbm-list::-webkit-scrollbar-thumb, .kbm-form::-webkit-scrollbar-thumb, .kbm-preview::-webkit-scrollbar-thumb{ background: rgba(0,255,204,0.20); border:1px solid rgba(0,255,204,0.30); border-radius:10px; }
  .kbm-list::-webkit-scrollbar-thumb:hover, .kbm-form::-webkit-scrollbar-thumb:hover, .kbm-preview::-webkit-scrollbar-thumb:hover{ background: rgba(0,255,204,0.28); }

  /* Responsive */
  @media (max-width: 980px){
    .kbm-left{ width: 320px; }
    .kbm-split{ grid-template-columns: 1fr; }
  }


/* ---------- KB RICH CONTENT (preview + inserted templates) ---------- */
.kbm-app .kb-small{ font-size:12px; opacity:.75; margin-top:6px; }

.kbm-app .kb-note,
.kbm-app .kb-alert{
  border:1px solid rgba(0,255,204,.45);
  background: linear-gradient(180deg, rgba(0,255,204,.10), rgba(0,0,0,.35));
  border-radius:12px;
  padding:12px 12px;
  box-shadow: 0 0 0 1px rgba(0,0,0,.6) inset, 0 8px 24px rgba(0,0,0,.35);
  position:relative;
}
.kbm-app .kb-alert{
  border-color: rgba(255,140,0,.55);
  background: linear-gradient(180deg, rgba(255,140,0,.12), rgba(0,0,0,.35));
}
.kbm-app .kb-note-title,
.kbm-app .kb-alert-title{
  font-weight:700;
  letter-spacing:.18em;
  font-size:12px;
  opacity:.9;
  margin-bottom:6px;
}

.kbm-app .kb-frame{
  border-radius:14px;
  padding:14px 14px 12px;
  border:1px solid rgba(0,255,204,.55);
  background:
    radial-gradient(600px 240px at 15% 0%, rgba(0,255,204,.12), transparent 60%),
    linear-gradient(180deg, rgba(0,0,0,.25), rgba(0,0,0,.55));
  box-shadow: 0 0 0 1px rgba(0,0,0,.65) inset, 0 12px 30px rgba(0,0,0,.45);
  position:relative;
}
.kbm-app .kb-frame.cyan{ border-color: rgba(0,255,204,.65); }
.kbm-app .kb-frame.amber{
  border-color: rgba(255,140,0,.65);
  background:
    radial-gradient(600px 240px at 15% 0%, rgba(255,140,0,.14), transparent 60%),
    linear-gradient(180deg, rgba(0,0,0,.25), rgba(0,0,0,.55));
}
.kbm-app .kb-frame-title{
  font-weight:800;
  letter-spacing:.22em;
  font-size:12px;
  opacity:.95;
  margin-bottom:8px;
  display:flex;
  align-items:center;
  gap:10px;
}
.kbm-app .kb-frame-title::after{
  content:"";
  flex:1;
  height:1px;
  background: linear-gradient(90deg, rgba(255,255,255,.18), transparent);
}

.kbm-app .kb-divider{
  height:1px;
  background: linear-gradient(90deg, transparent, rgba(0,255,204,.45), transparent);
  margin:14px 0;
  position:relative;
}
.kbm-app .kb-divider::after{
  content:"";
  position:absolute; left:50%; top:-2px;
  width:10px; height:5px; transform:translateX(-50%);
  background: rgba(0,255,204,.45);
  filter: blur(.2px);
  border-radius:99px;
}

.kbm-app .kb-badges{ display:flex; flex-wrap:wrap; gap:8px; }
.kbm-app .kb-badge{
  padding:4px 10px;
  border-radius:999px;
  border:1px solid rgba(0,255,204,.45);
  background: rgba(0,0,0,.35);
  font-size:12px;
  letter-spacing:.06em;
}
.kbm-app .kb-badge.primary{ border-color: rgba(0,255,204,.7); }
.kbm-app .kb-badge.warn{ border-color: rgba(255,140,0,.75); }

.kbm-app .kb-cols{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media (max-width: 860px){ .kbm-app .kb-cols{ grid-template-columns:1fr; } }

.kbm-app .kb-kpis{ display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; }
@media (max-width: 860px){ .kbm-app .kb-kpis{ grid-template-columns:1fr; } }
.kbm-app .kb-kpi{
  border-radius:12px;
  padding:10px 12px;
  border:1px solid rgba(0,255,204,.45);
  background: rgba(0,0,0,.35);
  box-shadow: 0 0 0 1px rgba(0,0,0,.6) inset;
}
.kbm-app .kb-kpi-k{ font-size:12px; opacity:.75; letter-spacing:.14em; }
.kbm-app .kb-kpi-v{ font-size:16px; font-weight:800; margin-top:2px; }

.kbm-app .kb-timeline{ display:flex; flex-direction:column; gap:12px; }
.kbm-app .kb-tl-item{ display:flex; gap:12px; align-items:flex-start; }
.kbm-app .kb-tl-dot{
  width:10px; height:10px; border-radius:99px;
  background: rgba(0,255,204,.85);
  box-shadow: 0 0 14px rgba(0,255,204,.35);
  margin-top:4px;
}
.kbm-app .kb-tl-body{
  flex:1;
  border-left:1px solid rgba(0,255,204,.25);
  padding-left:12px;
}

.kbm-app .kb-quote{
  display:flex;
  gap:12px;
  border-radius:14px;
  padding:14px 14px;
  border:1px solid rgba(160,120,255,.45);
  background:
    radial-gradient(700px 260px at 0% 0%, rgba(160,120,255,.12), transparent 60%),
    rgba(0,0,0,.35);
}
.kbm-app .kb-quote-mark{
  font-size:28px;
  line-height:1;
  opacity:.85;
}
.kbm-app .kb-quote-text{ font-size:15px; }
.kbm-app .kb-quote-from{ margin-top:6px; font-size:12px; opacity:.7; letter-spacing:.12em; }

.kbm-app .kb-check{ list-style:none; padding-left:0; margin:0; }
.kbm-app .kb-check li{
  padding-left:22px;
  position:relative;
  margin:8px 0;
}
.kbm-app .kb-check li::before{
  content:"✔";
  position:absolute; left:0; top:0;
  color: rgba(0,255,204,.9);
  text-shadow: 0 0 12px rgba(0,255,204,.35);
}

.kbm-app table,
.kbm-app .kb-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
  overflow:hidden;
  border-radius:14px;
  border:1px solid rgba(0,255,204,.35);
  background: rgba(0,0,0,.28);
  box-shadow: 0 0 0 1px rgba(0,0,0,.65) inset;
}
.kbm-app table th, .kbm-app table td,
.kbm-app .kb-table th, .kbm-app .kb-table td{
  padding:10px 12px;
  border-bottom:1px solid rgba(0,255,204,.18);
  border-right:1px solid rgba(0,255,204,.12);
  vertical-align:top;
}
.kbm-app table th:last-child, .kbm-app table td:last-child,
.kbm-app .kb-table th:last-child, .kbm-app .kb-table td:last-child{ border-right:none; }
.kbm-app table tr:last-child td,
.kbm-app .kb-table tr:last-child td{ border-bottom:none; }

.kbm-app table thead th,
.kbm-app .kb-table thead th{
  font-size:12px;
  letter-spacing:.18em;
  opacity:.9;
  background: linear-gradient(180deg, rgba(0,255,204,.12), rgba(0,0,0,.25));
}
.kbm-app table tbody tr:hover td,
.kbm-app .kb-table tbody tr:hover td{ background: rgba(0,255,204,.06); }

.kbm-app .kb-code{
  border-radius:14px;
  border:1px solid rgba(0,255,204,.35);
  background: rgba(0,0,0,.45);
  padding:12px 12px;
  overflow:auto;
}
.kbm-app .kb-code code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:12px; }

/* toolbar: wrap + nicer */
.kbm-toolbar{ display:flex; flex-wrap:wrap; gap:8px; }
.kbm-tool{ white-space:nowrap; }

</style>

<!-- запускаем JS внутри окна (как в admin_tools.php) -->
<img src="x" style="display:none" onerror="(function(el){try{window.__KBM_ANCHOR = el; (0,eval)(document.getElementById('kbm-js').textContent);}catch(e){alert('KB_EDITOR ERROR: '+(e&&e.message?e.message:e));}})(this);">
<script type="text/plain" id="kbm-js">
  (function(anchor){
    // Scoped selectors (чтобы несколько окон не конфликтовали)
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
      if(document.querySelectorAll){
        const wins = document.querySelectorAll('.window');
        for(let i=0;i<wins.length;i++){
          try{ if(wins[i] && wins[i].contains && node && wins[i].contains(node)) return wins[i]; }catch(e){}
        }
      }
      return null;
    };

    const win = _closestWindow(anchor) || document.querySelector('.window.active') || document.querySelector('.window');
    if (!win) throw new Error('Cannot locate .window container');

    const api = 'api.php';
    const q = (s)=>win.querySelector(s);
    const g = (id)=>win.querySelector('#'+id);

    const stripDanger = (html) => {
      if (!html) return '';
      html = String(html).replace(/<\s*(script|style)[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi, '');
      html = html.replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
      html = html.replace(/\s(href|src)\s*=\s*("|')\s*javascript:[^\2]*\2/gi, '');
      return html;
    };

    const state = {
      rules: [],
      abilities: [],
      activeRuleId: 0,
      activeAbilityId: 0,
      activeBodyId: 'kbm-rule-body',
    };

    const post = async (data) => {
      const form = new URLSearchParams();
      Object.keys(data).forEach(k => form.append(k, data[k]));
      const r = await fetch(api, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form });
      return await r.json();
    };
    const get = async (url) => (await fetch(url)).json();

    const renderRules = () => {
      const list = q('#kbm-rules-list');
      const term = (q('#kbm-rules-search').value || '').trim().toLowerCase();
      list.innerHTML = '';
      const items = state.rules.filter(r => {
        const hay = ((r.title||'') + ' ' + (r.category||'')).toLowerCase();
        return term === '' || hay.includes(term);
      });
      if (!items.length){
        list.innerHTML = '<div style="color:#777; padding:10px; text-align:center;">Ничего не найдено</div>';
        return;
      }
      items.forEach(r => {
        const el = document.createElement('div');
        el.className = 'kbm-item' + (state.activeRuleId === Number(r.id) ? ' active' : '');
        const cat = (r.category && String(r.category).trim()) ? r.category : 'RULE';
        el.innerHTML = `<div class="t">${escapeHtml(r.title||'')}</div>
                        <div class="m">
                          <span class="kbm-chip">${escapeHtml(cat)}</span>
                          <span class="kbm-chip ${Number(r.is_active)===1?'':'off'}">${Number(r.is_active)===1?'ACTIVE':'HIDDEN'}</span>
                          <span class="kbm-chip off">#${r.id}</span>
                        </div>`;
        el.onclick = () => KBM.openRule(r.id);
        list.appendChild(el);
      });
    };

    const renderAbilities = () => {
      const list = q('#kbm-abilities-list');
      const term = (q('#kbm-abilities-search').value || '').trim().toLowerCase();
      list.innerHTML = '';
      const items = state.abilities.filter(a => {
        const hay = ((a.name||'') + ' ' + (a.ability_type||'') + ' ' + (a.tags||'')).toLowerCase();
        return term === '' || hay.includes(term);
      });
      if (!items.length){
        list.innerHTML = '<div style="color:#777; padding:10px; text-align:center;">Ничего не найдено</div>';
        return;
      }
      items.forEach(a => {
        const el = document.createElement('div');
        el.className = 'kbm-item' + (state.activeAbilityId === Number(a.id) ? ' active' : '');
        const t = (a.ability_type && String(a.ability_type).trim()) ? a.ability_type : 'ABILITY';
        const tagLine = [a.cooldown ? ('КД: ' + a.cooldown) : '', a.cost ? ('COST: ' + a.cost) : ''].filter(Boolean).join(' · ');
        el.innerHTML = `<div class="t">${escapeHtml(a.name||'')}</div>
                        <div class="m">
                          <span class="kbm-chip">${escapeHtml(t)}</span>
                          <span class="kbm-chip ${Number(a.is_active)===1?'':'off'}">${Number(a.is_active)===1?'ACTIVE':'HIDDEN'}</span>
                          <span class="kbm-chip off">${escapeHtml(tagLine || ('#'+a.id))}</span>
                        </div>`;
        el.onclick = () => KBM.openAbility(a.id);
        list.appendChild(el);
      });
    };

    const escapeHtml = (s) => String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));

    const loadAll = async () => {
      try{
        const rr = await get(api + '?action=kb_list_rules_admin');
        const aa = await get(api + '?action=kb_list_abilities_admin');
        state.rules = (rr && rr.rules) ? rr.rules : [];
        state.abilities = (aa && aa.abilities) ? aa.abilities : [];
        renderRules();
        renderAbilities();
        if (state.rules.length && !state.activeRuleId) KBM.openRule(state.rules[0].id);
        if (state.abilities.length && !state.activeAbilityId) KBM.openAbility(state.abilities[0].id);
      }catch(e){
        alert('KB_EDITOR: Не удалось загрузить данные');
      }
    };

    
    // --- Templates (safe keys → rich HTML blocks) ---
    const TPL = {
      H2: `<h2>РАЗДЕЛ</h2>
<p>Описание раздела...</p>`,

      P: `<p><b>Заголовок</b> — текст...</p>`,

      LIST: `<ul>
  <li>Пункт 1</li>
  <li>Пункт 2</li>
  <li>Пункт 3</li>
</ul>`,

      CHECK: `<ul class="kb-check">
  <li><b>Условие:</b> ...</li>
  <li><b>Ограничение:</b> ...</li>
  <li><b>Последствие:</b> ...</li>
</ul>`,

      QUOTE: `<blockquote class="kb-quote">
  <div class="kb-quote-mark">“</div>
  <div>
    <div class="kb-quote-text">Цитата / выдержка из протокола...</div>
    <div class="kb-quote-from">— источник / примечание</div>
  </div>
</blockquote>`,

      NOTE: `<div class="kb-note">
  <div class="kb-note-title">NOTE</div>
  <div>Короткая заметка / уточнение...</div>
</div>`,

      ALERT: `<div class="kb-alert">
  <div class="kb-alert-title">ALERT</div>
  <div>Важное предупреждение / санкции / условия...</div>
</div>`,

      FRAME_C: `<div class="kb-frame cyan">
  <div class="kb-frame-title">ПРОТОКОЛ</div>
  <p>Текст протокола...</p>
  <div class="kb-small">подпись / деталь</div>
</div>`,

      FRAME_A: `<div class="kb-frame amber">
  <div class="kb-frame-title">ПРАВИЛО / ШТРАФ</div>
  <p>Описание действия и последствий...</p>
  <div class="kb-small">ограничения / исключения</div>
</div>`,

      SPOILER: `<details class="kb-spoiler">
  <summary>Раскрыть детали</summary>
  <div class="kb-spoiler-body">
    <p>Скрытый текст...</p>
  </div>
</details>`,

      COL2: `<div class="kb-cols">
  <div class="kb-col">
    <div class="kb-note"><b>Канал A:</b> текст...</div>
  </div>
  <div class="kb-col">
    <div class="kb-frame cyan"><b>Канал B:</b> текст...</div>
  </div>
</div>`,

      KPI: `<div class="kb-kpis">
  <div class="kb-kpi"><div class="kb-kpi-k">Стоимость</div><div class="kb-kpi-v">2 ОЗ</div></div>
  <div class="kb-kpi"><div class="kb-kpi-k">Длительность</div><div class="kb-kpi-v">1 сцена</div></div>
  <div class="kb-kpi"><div class="kb-kpi-k">Откат</div><div class="kb-kpi-v">2 сцены</div></div>
</div>`,

      TIME: `<div class="kb-timeline">
  <div class="kb-tl-item"><div class="kb-tl-dot"></div><div class="kb-tl-body"><b>Шаг 1</b><div class="kb-small">описание...</div></div></div>
  <div class="kb-tl-item"><div class="kb-tl-dot"></div><div class="kb-tl-body"><b>Шаг 2</b><div class="kb-small">описание...</div></div></div>
  <div class="kb-tl-item"><div class="kb-tl-dot"></div><div class="kb-tl-body"><b>Шаг 3</b><div class="kb-small">описание...</div></div></div>
</div>`,

      TAGS: `<div class="kb-badges">
  <span class="kb-badge primary">основное</span>
  <span class="kb-badge">ограничение</span>
  <span class="kb-badge warn">риск</span>
</div>`,

      TABLE: `<table class="kb-table">
  <thead>
    <tr><th>СИТУАЦИЯ</th><th>ПРАВИЛО</th><th>ШТРАФ</th></tr>
  </thead>
  <tbody>
    <tr><td>Текст A</td><td>Текст B</td><td>Текст C</td></tr>
    <tr><td>...</td><td>...</td><td>...</td></tr>
  </tbody>
</table>`,

      DIV: `<div class="kb-divider"></div>`,

      CODE: `<pre class="kb-code"><code>// заметки / команды / формулы
// ...
</code></pre>`,
    };
window.KBM = {
      setBody: (id) => { try{ state.activeBodyId = id; }catch(e){} },

      ins: (id, snippet) => {
        const ta = win.querySelector('#'+id);
        if (!ta) return;
        const v = ta.value || '';
        const s = (typeof ta.selectionStart === 'number') ? ta.selectionStart : v.length;
        const e = (typeof ta.selectionEnd === 'number') ? ta.selectionEnd : v.length;
        // setRangeText keeps undo stack nicely when available
        if (typeof ta.setRangeText === 'function') {
          ta.setRangeText(String(snippet), s, e, 'end');
        } else {
          ta.value = v.slice(0, s) + snippet + v.slice(e);
          try{ ta.selectionStart = ta.selectionEnd = s + String(snippet).length; }catch(ex){}
        }
        ta.focus();
        if (id === 'kbm-rule-body') KBM.previewRule();
        if (id === 'kbm-ability-desc') KBM.previewAbility();
      },

      insActive: (snippet) => {
        const id = (state && state.activeBodyId) ? state.activeBodyId : 'kbm-rule-body';
        KBM.ins(id, snippet);
      },

      insTpl: (key) => {
        const k = String(key||'').trim();
        const html = (TPL && Object.prototype.hasOwnProperty.call(TPL, k)) ? TPL[k] : '';
        if (!html) return;
        KBM.insActive(html + "\n");
      },


      // RULES
      newRule: () => {
        state.activeRuleId = 0;
        g('kbm-rule-id').value = 0;
        g('kbm-rule-title').value = '';
        g('kbm-rule-category').value = '';
        g('kbm-rule-sort').value = 0;
        g('kbm-rule-active').value = 1;
        g('kbm-rule-body').value = '';
        KBM.previewRule();
        renderRules();
      },

      openRule: (id) => {
        id = Number(id)||0;
        state.activeRuleId = id;
        const r = state.rules.find(x=>Number(x.id)===id);
        if (!r) return;
        g('kbm-rule-id').value = r.id;
        g('kbm-rule-title').value = r.title || '';
        g('kbm-rule-category').value = r.category || '';
        g('kbm-rule-sort').value = r.sort_order || 0;
        g('kbm-rule-active').value = (Number(r.is_active)===1 ? 1 : 0);
        g('kbm-rule-body').value = r.body_html || '';
        KBM.previewRule();
        renderRules();
      },

      previewRule: () => {
        const html = stripDanger(g('kbm-rule-body').value || '');
        g('kbm-rule-preview').innerHTML = html || '<div style="color:#666;">(пусто)</div>';
      },

      saveRule: async () => {
        const id = Number(g('kbm-rule-id').value||0);
        const payload = {
          action: 'kb_save_rule',
          id,
          title: g('kbm-rule-title').value || '',
          category: g('kbm-rule-category').value || '',
          sort_order: g('kbm-rule-sort').value || 0,
          is_active: g('kbm-rule-active').value || 1,
          body_html: g('kbm-rule-body').value || ''
        };
        const res = await post(payload);
        if (!res || res.status !== 'success') return alert((res && (res.message||res.msg)) ? (res.message||res.msg) : 'Ошибка сохранения');
        state.activeRuleId = Number(res.id)||0;
        await loadAll();
        KBM.openRule(state.activeRuleId);
      },

      deleteRule: async () => {
        const id = Number(g('kbm-rule-id').value||0);
        if (!id) return;
        if (!confirm('Удалить правило #' + id + '?')) return;
        const res = await post({action:'kb_delete_rule', id});
        if (!res || res.status !== 'success') return alert('Ошибка удаления');
        state.activeRuleId = 0;
        await loadAll();
        KBM.newRule();
      },

      // ABILITIES
      newAbility: () => {
        state.activeAbilityId = 0;
        g('kbm-ability-id').value = 0;
        g('kbm-ability-name').value = '';
        g('kbm-ability-type').value = '';
        g('kbm-ability-cost').value = '';
        g('kbm-ability-cd').value = '';
        g('kbm-ability-tags').value = '';
        g('kbm-ability-sort').value = 0;
        g('kbm-ability-active').value = 1;
        g('kbm-ability-desc').value = '';
        KBM.previewAbility();
        renderAbilities();
      },

      openAbility: (id) => {
        id = Number(id)||0;
        state.activeAbilityId = id;
        const a = state.abilities.find(x=>Number(x.id)===id);
        if (!a) return;
        g('kbm-ability-id').value = a.id;
        g('kbm-ability-name').value = a.name || '';
        g('kbm-ability-type').value = a.ability_type || '';
        g('kbm-ability-cost').value = a.cost || '';
        g('kbm-ability-cd').value = a.cooldown || '';
        g('kbm-ability-tags').value = a.tags || '';
        g('kbm-ability-sort').value = a.sort_order || 0;
        g('kbm-ability-active').value = (Number(a.is_active)===1 ? 1 : 0);
        g('kbm-ability-desc').value = a.description_html || '';
        KBM.previewAbility();
        renderAbilities();
      },

      previewAbility: () => {
        const html = stripDanger(g('kbm-ability-desc').value || '');
        g('kbm-ability-preview').innerHTML = html || '<div style="color:#666;">(пусто)</div>';
      },

      saveAbility: async () => {
        const id = Number(g('kbm-ability-id').value||0);
        const payload = {
          action: 'kb_save_ability',
          id,
          name: g('kbm-ability-name').value || '',
          ability_type: g('kbm-ability-type').value || '',
          cost: g('kbm-ability-cost').value || '',
          cooldown: g('kbm-ability-cd').value || '',
          tags: g('kbm-ability-tags').value || '',
          sort_order: g('kbm-ability-sort').value || 0,
          is_active: g('kbm-ability-active').value || 1,
          description_html: g('kbm-ability-desc').value || ''
        };
        const res = await post(payload);
        if (!res || res.status !== 'success') return alert((res && (res.message||res.msg)) ? (res.message||res.msg) : 'Ошибка сохранения');
        state.activeAbilityId = Number(res.id)||0;
        await loadAll();
        KBM.openAbility(state.activeAbilityId);
      },

      deleteAbility: async () => {
        const id = Number(g('kbm-ability-id').value||0);
        if (!id) return;
        if (!confirm('Удалить способность #' + id + '?')) return;
        const res = await post({action:'kb_delete_ability', id});
        if (!res || res.status !== 'success') return alert('Ошибка удаления');
        state.activeAbilityId = 0;
        await loadAll();
        KBM.newAbility();
      }
    };

    // Search listeners
    q('#kbm-rules-search').addEventListener('input', renderRules);
    q('#kbm-abilities-search').addEventListener('input', renderAbilities);

    // initial
    loadAll();
    KBM.previewRule();
    KBM.previewAbility();
  })(window.__KBM_ANCHOR);
</script>

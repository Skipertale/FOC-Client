<?php
// apps/knowledge_base.php
// Knowledge Base — правила/способности.
// ВАЖНО: приложение подгружается в окно OS как HTML-фрагмент.
// Поэтому JS запускаем через onerror-хак (как в админке), чтобы не зависеть
// от innerHTML.

require_once '../config/db.php';
require_once '../config/knowledge_base.php';
session_start();

if (!isset($_SESSION['user_id'])) {
  exit('<div style="color:#ff6666; padding:18px; font-family:Share Tech Mono, monospace;">AUTH REQUIRED</div>');
}

// Схема БД (на всякий случай)
kbEnsureSchema($pdo);

$isAdmin = (int)($_SESSION['access_level'] ?? 0) >= 5;
?>

<style>
/* ВАЖНО: всё стилизуем ТОЛЬКО внутри .kb-app, чтобы не ломать рабочий стол */
.kb-app, .kb-app * { box-sizing: border-box; }

.kb-app{
  height:100%;
  display:flex;
  flex-direction:column;
  font-family:'Share Tech Mono', monospace;
  color: rgba(255,255,255,0.92);
  position:relative;
  overflow:hidden;
}

/* Фон/шум/сканлайны внутри окна */
.kb-app::before{
  content:"";
  position:absolute; inset:0;
  background:
    radial-gradient(900px 520px at 18% -10%, rgba(0,255,204,0.18), transparent 62%),
    radial-gradient(900px 520px at 110% 10%, rgba(255,140,0,0.10), transparent 55%),
    linear-gradient(180deg, rgba(0,0,0,0.28), rgba(0,0,0,0.60));
  pointer-events:none;
}
.kb-app::after{
  content:"";
  position:absolute; inset:-2px;
  background:
    repeating-linear-gradient(
      to bottom,
      rgba(255,255,255,0.045),
      rgba(255,255,255,0.045) 1px,
      rgba(0,0,0,0) 3px,
      rgba(0,0,0,0) 6px
    );
  mix-blend-mode: overlay;
  opacity:0.28;
  pointer-events:none;
}

.kb-shell{
  position:relative;
  height:100%;
  display:flex;
  flex-direction:column;
  gap:12px;
  padding:14px;
}

/* Верхняя панель */
.kb-top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:12px 12px;
  border-radius:14px;
  border:1px solid rgba(0,255,204,0.25);
  background: rgba(0,0,0,0.32);
  box-shadow: 0 0 26px rgba(0,255,204,0.10);
  backdrop-filter: blur(6px);
}

.kb-title{
  display:flex; align-items:center; gap:10px;
  min-width:0;
}
.kb-badge{
  width:38px;height:38px;
  border-radius:12px;
  display:grid;place-items:center;
  border:1px solid rgba(0,255,204,0.28);
  background: rgba(0,255,204,0.10);
  box-shadow: 0 0 22px rgba(0,255,204,0.14);
}
.kb-badge i{ color: rgba(0,255,204,0.92); text-shadow: 0 0 10px rgba(0,255,204,0.35); }

.kb-title h1{
  margin:0;
  font-size:1.05rem;
  letter-spacing:1px;
  text-transform:uppercase;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.kb-sub{
  margin-top:2px;
  font-size:0.78rem;
  color: rgba(255,255,255,0.62);
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}

.kb-rightbar{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

/* Таб-панель */
.kb-tabs{ display:flex; gap:8px; align-items:center; }

.kb-tab-input{ position:absolute; opacity:0; pointer-events:none; }
.kb-tab{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:9px 12px;
  border-radius:12px;
  border:1px solid rgba(0,255,204,0.22);
  background: rgba(0,0,0,0.25);
  color: rgba(255,255,255,0.82);
  cursor:pointer;
  transition: 0.18s ease;
  user-select:none;
  font-size:0.85rem;
  letter-spacing:0.6px;
  text-transform:uppercase;
}
.kb-tab i{ color: rgba(0,255,204,0.80); text-shadow: 0 0 10px rgba(0,255,204,0.25); }
.kb-tab:hover{
  transform: translateY(-1px);
  background: rgba(0,255,204,0.08);
  border-color: rgba(0,255,204,0.40);
}

#kb-tab-rules:checked ~ .kb-shell .kb-tab[for="kb-tab-rules"],
#kb-tab-abilities:checked ~ .kb-shell .kb-tab[for="kb-tab-abilities"]{
  background: rgba(0,255,204,0.10);
  border-color: rgba(0,255,204,0.55);
  box-shadow: 0 0 18px rgba(0,255,204,0.12);
  color:#fff;
}

.kb-body{
  flex:1;
  min-height:0;
  border-radius:16px;
  border:1px solid rgba(0,255,204,0.18);
  background: rgba(0,0,0,0.28);
  overflow:auto;
  padding:14px;
  box-shadow: inset 0 0 30px rgba(0,0,0,0.35);
}

/* Скролл */
.kb-body::-webkit-scrollbar{ width:10px; }
.kb-body::-webkit-scrollbar-track{ background: rgba(0,0,0,0.25); border-left:1px solid rgba(0,255,204,0.10); }
.kb-body::-webkit-scrollbar-thumb{ background: rgba(0,255,204,0.20); border:1px solid rgba(0,255,204,0.30); border-radius:10px; }
.kb-body::-webkit-scrollbar-thumb:hover{ background: rgba(0,255,204,0.28); }

/* Панели */
.kb-panel{ display:none; animation: kbFade 0.18s ease; }
@keyframes kbFade{ from{ opacity:0; transform: translateY(2px);} to{ opacity:1; transform: translateY(0);} }

#kb-tab-rules:checked ~ .kb-shell .kb-panel.rules{ display:block; }
#kb-tab-abilities:checked ~ .kb-shell .kb-panel.abilities{ display:block; }

/* Кнопки/тумблеры */
.kb-btn{
  display:inline-flex; align-items:center; gap:8px;
  padding:8px 10px; border-radius:12px;
  border:1px solid rgba(0,255,204,0.22);
  background: rgba(0,0,0,0.24);
  color: rgba(255,255,255,0.88);
  cursor:pointer;
  font-family:inherit;
  transition:0.18s;
  text-transform:uppercase;
  letter-spacing:0.6px;
  font-size:0.78rem;
}
.kb-btn:hover{ transform: translateY(-1px); background: rgba(0,255,204,0.08); border-color: rgba(0,255,204,0.45); }
.kb-pill{ display:inline-flex; align-items:center; gap:8px; padding:8px 10px; border-radius:12px; border:1px solid rgba(255,255,255,0.14); background: rgba(255,255,255,0.05); font-size:0.78rem; color: rgba(255,255,255,0.80); }
.kb-pill input{ accent-color: #00ffcc; }

/* Плашки */
.kb-grid{ display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:12px; }

.kb-card{
  border-radius:16px;
  border:1px solid rgba(0,255,204,0.22);
  background: rgba(0,0,0,0.22);
  padding:12px 12px 12px;
  position:relative;
  overflow:hidden;
  box-shadow: 0 0 18px rgba(0,255,204,0.08);
}
.kb-card::before{
  content:"";
  position:absolute; inset:0;
  background:
    radial-gradient(520px 190px at 14% 0%, rgba(0,255,204,0.16), transparent 58%),
    radial-gradient(520px 190px at 110% 10%, rgba(155,89,182,0.12), transparent 52%);
  opacity:0.95;
  pointer-events:none;
}
.kb-card::after{
  content:"";
  position:absolute; inset:0;
  background: linear-gradient(135deg, rgba(0,255,204,0.08), transparent 45%);
  opacity:0.25;
  pointer-events:none;
}
.kb-card:hover{ border-color: rgba(0,255,204,0.45); background: rgba(0,255,204,0.06); transform: translateY(-1px); transition: 0.18s ease; }

.kb-card h2{
  margin:0 0 10px 0;
  font-size:0.95rem;
  text-transform:uppercase;
  letter-spacing:0.9px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  position:relative;
}

.kb-chip{
  padding:3px 8px;
  border-radius:999px;
  border:1px solid rgba(0,255,204,0.25);
  background: rgba(0,255,204,0.08);
  color: rgba(255,255,255,0.86);
  font-size:0.72rem;
  letter-spacing:0.6px;
  white-space:nowrap;
}
.kb-chip.off{ border-color: rgba(255,255,255,0.14); background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.65); }

.kb-empty{
  padding:12px;
  border-radius:16px;
  border:1px dashed rgba(0,255,204,0.28);
  background: rgba(0,0,0,0.18);
  color: rgba(255,255,255,0.70);
}

/* --- RICH CONTENT: базовый html из админки --- */
.kb-rich{ margin-top:10px; position:relative; }
.kb-rich p{ margin:8px 0; line-height:1.45; color: rgba(255,255,255,0.82); }
.kb-rich ul, .kb-rich ol{ margin:8px 0 8px 18px; padding:0; }
.kb-rich li{ margin:6px 0; }
.kb-rich h1,.kb-rich h2,.kb-rich h3{ margin:10px 0 8px; color:#fff; letter-spacing:0.6px; }
.kb-rich blockquote{
  margin:10px 0;
  padding:10px 12px;
  border-radius:14px;
  border:1px solid rgba(255,140,0,0.26);
  background: rgba(255,140,0,0.06);
  color: rgba(255,255,255,0.84);
}
.kb-rich code{ padding:2px 6px; border-radius:8px; border:1px solid rgba(0,255,204,0.18); background: rgba(0,0,0,0.25); color: rgba(255,255,255,0.92); }
.kb-rich pre{ padding:10px 12px; border-radius:14px; border:1px solid rgba(0,255,204,0.16); background: rgba(0,0,0,0.28); overflow:auto; }
.kb-rich a{ color: rgba(0,255,204,0.92); text-decoration:none; border-bottom:1px dashed rgba(0,255,204,0.45); }
.kb-rich a:hover{ border-bottom-color: rgba(0,255,204,0.75); }

/* Таблицы — НЕ калич, а cyber-таблица */
.kb-rich table, .kb-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
  overflow:hidden;
  border-radius:14px;
  border:1px solid rgba(0,255,204,0.22);
  background: rgba(0,0,0,0.20);
  box-shadow: 0 0 22px rgba(0,255,204,0.08);
}
.kb-rich th, .kb-rich td, .kb-table th, .kb-table td{
  padding:10px 10px;
  border-bottom:1px solid rgba(0,255,204,0.14);
  font-size:0.82rem;
  vertical-align:top;
}
.kb-rich th, .kb-table th{
  text-align:left;
  color:#fff;
  background:
    linear-gradient(180deg, rgba(0,255,204,0.16), rgba(0,255,204,0.06));
  letter-spacing:0.7px;
  text-transform:uppercase;
}
.kb-rich tr:nth-child(even) td, .kb-table tr:nth-child(even) td{ background: rgba(255,255,255,0.03); }
.kb-rich tr:hover td, .kb-table tr:hover td{ background: rgba(0,255,204,0.05); }
.kb-rich tr:last-child td, .kb-table tr:last-child td{ border-bottom:none; }

/* --- КАСТОМНЫЕ БЛОКИ (разъёб-пак) --- */

/* рамка-кассета */
.kb-frame{
  position:relative;
  border-radius:16px;
  padding:12px 12px;
  border:1px solid rgba(0,255,204,0.26);
  background: rgba(0,0,0,0.20);
  box-shadow: 0 0 22px rgba(0,255,204,0.10);
  overflow:hidden;
}
.kb-frame::before{
  content:"";
  position:absolute; inset:0;
  background: radial-gradient(620px 220px at 12% 0%, rgba(0,255,204,0.18), transparent 55%);
  opacity:0.95; pointer-events:none;
}
.kb-frame::after{
  content:"";
  position:absolute;
  inset:10px;
  border-radius:12px;
  border:1px dashed rgba(0,255,204,0.22);
  opacity:0.7;
  pointer-events:none;
}
.kb-frame.orange{ border-color: rgba(255,140,0,0.30); box-shadow: 0 0 22px rgba(255,140,0,0.10); }
.kb-frame.orange::before{ background: radial-gradient(620px 220px at 12% 0%, rgba(255,140,0,0.18), transparent 55%); }
.kb-frame.purple{ border-color: rgba(155,89,182,0.30); box-shadow: 0 0 22px rgba(155,89,182,0.10); }
.kb-frame.purple::before{ background: radial-gradient(620px 220px at 12% 0%, rgba(155,89,182,0.18), transparent 55%); }
.kb-frame .kb-frame-title{
  display:flex; align-items:center; justify-content:space-between; gap:10px;
  font-weight:bold;
  color:#fff;
  letter-spacing:0.8px;
  text-transform:uppercase;
  margin-bottom:8px;
}
.kb-frame .kb-frame-title .kb-mini{ font-size:0.74rem; color: rgba(255,255,255,0.62); font-weight:normal; text-transform:none; }

/* callout / note / danger */
.kb-callout, .kb-note, .kb-danger{
  border-radius:14px;
  padding:10px 12px;
  position:relative;
  overflow:hidden;
}
.kb-callout{ border:1px solid rgba(255,140,0,0.28); background: rgba(255,140,0,0.06); }
.kb-note{ border:1px solid rgba(0,255,204,0.26); background: rgba(0,255,204,0.06); }
.kb-danger{ border:1px solid rgba(255,0,90,0.26); background: rgba(255,0,90,0.06); }
.kb-callout b, .kb-note b, .kb-danger b{ color:#fff; }

/* бейджи */
.kb-badges{ display:flex; gap:8px; flex-wrap:wrap; margin:8px 0; }
.kb-badge{
  padding:4px 10px; border-radius:999px;
  border:1px solid rgba(255,255,255,0.16);
  background: rgba(255,255,255,0.06);
  font-size:0.74rem;
  color: rgba(255,255,255,0.84);
}
.kb-badge.cyan{ border-color: rgba(0,255,204,0.30); background: rgba(0,255,204,0.10); }
.kb-badge.orange{ border-color: rgba(255,140,0,0.30); background: rgba(255,140,0,0.10); }
.kb-badge.purple{ border-color: rgba(155,89,182,0.30); background: rgba(155,89,182,0.10); }

/* две колонки */
.kb-cols{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
@media (max-width: 860px){ .kb-cols{ grid-template-columns: 1fr; } }
.kb-col{ border-radius:14px; border:1px solid rgba(0,255,204,0.16); background: rgba(0,0,0,0.18); padding:10px 12px; }

/* KPI плитки */
.kb-kpis{ display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:10px; }
.kb-kpi{
  border-radius:16px;
  border:1px solid rgba(0,255,204,0.18);
  background: rgba(0,0,0,0.18);
  padding:10px 12px;
  position:relative;
  overflow:hidden;
}
.kb-kpi::before{ content:""; position:absolute; inset:0; background: radial-gradient(420px 160px at 10% 0%, rgba(0,255,204,0.14), transparent 60%); opacity:0.9; pointer-events:none; }
.kb-kpi .k{ font-size:0.72rem; color: rgba(255,255,255,0.60); text-transform:uppercase; letter-spacing:0.7px; }
.kb-kpi .v{ font-size:1.05rem; margin-top:6px; color:#fff; letter-spacing:0.8px; }

/* timeline */
.kb-timeline{ display:flex; flex-direction:column; gap:10px; }
.kb-tl-item{ display:flex; gap:10px; }
.kb-tl-dot{ width:12px; height:12px; border-radius:50%; margin-top:6px; background: rgba(0,255,204,0.92); box-shadow:0 0 14px rgba(0,255,204,0.25); flex:0 0 auto; }
.kb-tl-content{ flex:1; border-radius:14px; border:1px solid rgba(0,255,204,0.16); background: rgba(0,0,0,0.18); padding:10px 12px; }
.kb-tl-content .small{ margin-top:6px; font-size:0.78rem; color: rgba(255,255,255,0.70); }

/* spoiler */
.kb-spoiler{ border-radius:14px; border:1px solid rgba(0,255,204,0.18); background: rgba(0,0,0,0.18); overflow:hidden; }
.kb-spoiler summary{ cursor:pointer; padding:10px 12px; list-style:none; user-select:none; color:#fff; text-transform:uppercase; letter-spacing:0.7px; }
.kb-spoiler summary::-webkit-details-marker{ display:none; }
.kb-spoiler .kb-spoiler-body{ padding:10px 12px; border-top:1px solid rgba(0,255,204,0.12); }

/* --- Способности (карточка) --- */
.kb-ability{ display:flex; flex-direction:column; gap:10px; }
.kb-ability .head{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.kb-ability .name{ font-size:0.95rem; text-transform:uppercase; letter-spacing:0.8px; color:#fff; }
.kb-ability .meta{ display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
.kb-tag{ padding:3px 8px; border-radius:999px; border:1px solid rgba(255,255,255,0.14); background: rgba(255,255,255,0.06); font-size:0.72rem; color: rgba(255,255,255,0.78); }
.kb-tag.primary{ border-color: rgba(0,255,204,0.30); background: rgba(0,255,204,0.10); color: rgba(255,255,255,0.86); }
.kb-tag.warn{ border-color: rgba(255,140,0,0.30); background: rgba(255,140,0,0.10); color: rgba(255,255,255,0.86); }

.kb-mini-table{
  width:100%;
  border-radius:12px;
  border:1px solid rgba(0,255,204,0.18);
  background: rgba(0,0,0,0.18);
  overflow:hidden;
  border-collapse:separate;
  border-spacing:0;
}
.kb-mini-table td{ padding:8px 10px; border-bottom:1px solid rgba(0,255,204,0.10); font-size:0.78rem; }
.kb-mini-table tr:last-child td{ border-bottom:none; }
.kb-mini-table td:first-child{ color: rgba(255,255,255,0.62); width:34%; }
.kb-mini-table td:last-child{ color: rgba(255,255,255,0.88); }



/* --- extra rich blocks (added) --- */
.kb-alert{
  border:1px solid rgba(255,140,0,.55);
  background: linear-gradient(180deg, rgba(255,140,0,.12), rgba(0,0,0,.35));
  border-radius:14px;
  padding:14px 14px 12px;
  box-shadow: 0 0 0 1px rgba(0,0,0,.65) inset, 0 12px 30px rgba(0,0,0,.45);
}
.kb-alert-title{
  font-weight:800;
  letter-spacing:.22em;
  font-size:12px;
  opacity:.95;
  margin-bottom:8px;
  display:flex;
  align-items:center;
  gap:10px;
}
.kb-alert-title::after{
  content:"";
  flex:1;
  height:1px;
  background: linear-gradient(90deg, rgba(255,255,255,.18), transparent);
}

.kb-quote{
  display:flex;
  gap:12px;
  border-radius:14px;
  padding:14px 14px;
  border:1px solid rgba(160,120,255,.45);
  background:
    radial-gradient(700px 260px at 0% 0%, rgba(160,120,255,.12), transparent 60%),
    rgba(0,0,0,.35);
}
.kb-quote-mark{ font-size:28px; line-height:1; opacity:.85; }
.kb-quote-text{ font-size:15px; }
.kb-quote-from{ margin-top:6px; font-size:12px; opacity:.7; letter-spacing:.12em; }

.kb-check{ list-style:none; padding-left:0; margin:0; }
.kb-check li{
  padding-left:22px;
  position:relative;
  margin:8px 0;
}
.kb-check li::before{
  content:"✔";
  position:absolute; left:0; top:0;
  color: rgba(0,255,204,.9);
  text-shadow: 0 0 12px rgba(0,255,204,.35);
}

/* Make plain tables look like kb-table */
.kb-rich table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
  overflow:hidden;
  border-radius:14px;
  border:1px solid rgba(0,255,204,.35);
  background: rgba(0,0,0,.28);
  box-shadow: 0 0 0 1px rgba(0,0,0,.65) inset;
}
.kb-rich table th, .kb-rich table td{
  padding:10px 12px;
  border-bottom:1px solid rgba(0,255,204,.18);
  border-right:1px solid rgba(0,255,204,.12);
  vertical-align:top;
}
.kb-rich table th:last-child, .kb-rich table td:last-child{ border-right:none; }
.kb-rich table tr:last-child td{ border-bottom:none; }
.kb-rich table thead th{
  font-size:12px;
  letter-spacing:.18em;
  opacity:.9;
  background: linear-gradient(180deg, rgba(0,255,204,.12), rgba(0,0,0,.25));
}
.kb-rich table tbody tr:hover td{ background: rgba(0,255,204,.06); }

.kb-code{
  border-radius:14px;
  border:1px solid rgba(0,255,204,.35);
  background: rgba(0,0,0,.45);
  padding:12px 12px;
  overflow:auto;
}
.kb-code code{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:12px; }

</style>

<!-- CSS-табы без JS: гарантированно работают внутри окна -->
<input class="kb-tab-input" type="radio" name="kb-tab" id="kb-tab-rules" checked>
<input class="kb-tab-input" type="radio" name="kb-tab" id="kb-tab-abilities">

<div class="kb-app">
  <div class="kb-shell">

    <div class="kb-top">
      <div class="kb-title">
        <div class="kb-badge"><i class="fas fa-book-open"></i></div>
        <div style="min-width:0;">
          <h1>БАЗА ЗНАНИЙ</h1>
          <div class="kb-sub">Форматированные справочные материалы режима</div>
        </div>
      </div>

      <div class="kb-rightbar">
        <div class="kb-tabs">
          <label class="kb-tab" for="kb-tab-rules" title="Общие правила режима">
            <i class="fas fa-scroll"></i> ПРАВИЛА
          </label>
          <label class="kb-tab" for="kb-tab-abilities" title="Список способностей">
            <i class="fas fa-bolt"></i> СПОСОБНОСТИ
          </label>
        </div>

        <?php if ($isAdmin): ?>
          <span class="kb-pill" title="Показывать и скрытые карточки (HIDDEN)">
            <input id="kb-show-hidden" type="checkbox"> СКРЫТЫЕ
          </span>
        <?php endif; ?>

        <button class="kb-btn" type="button" onclick="KB.reload()"><i class="fas fa-sync"></i> ОБНОВИТЬ</button>
      </div>
    </div>

    <div class="kb-body">
      <section class="kb-panel rules">
        <div id="kb-rules" class="kb-grid"></div>
      </section>

      <section class="kb-panel abilities">
        <div id="kb-abilities" class="kb-grid"></div>
      </section>
    </div>

  </div>
</div>

<!-- запускаем JS внутри окна (как в админке) -->
<img src="x" style="display:none" onerror="(function(el){try{window.__KB_ANCHOR = el; (0,eval)(document.getElementById('kb-js').textContent);}catch(e){alert('KB ERROR: '+(e&&e.message?e.message:e));}})(this);">
<script type="text/plain" id="kb-js">
(function(anchor){
  const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;

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

  const escapeHtml = (s) => String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));

  const stripDanger = (html) => {
    if (!html) return '';
    html = String(html)
      .replace(/<\s*(script|style)[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi, '')
      .replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
      .replace(/\s(href|src)\s*=\s*("|')\s*javascript:[^\2]*\2/gi, '');
    return html;
  };

  const postProcessRich = (root) => {
    if (!root) return;
    // Сделаем таблицы по умолчанию кибер-таблицами
    const tables = root.querySelectorAll('table');
    tables.forEach(t => {
      if (!t.classList.contains('kb-table')) t.classList.add('kb-table');
    });
    // Пара полезных автоклассов
    const bqs = root.querySelectorAll('blockquote');
    bqs.forEach(bq => bq.classList.add('kb-callout'));
  };

  const renderEmptyCard = (title, hint) => {
    return `
      <div class="kb-card">
        <h2>${escapeHtml(title)} <span class="kb-chip off">EMPTY</span></h2>
        <div class="kb-empty">${hint}</div>
      </div>
    `;
  };

  const renderRules = (rules) => {
    const root = q('#kb-rules');
    root.innerHTML = '';
    if (!rules || !rules.length) {
      root.innerHTML = renderEmptyCard(
        'ПРАВИЛА НЕ НАЙДЕНЫ',
        'Добавь правила через админку: <b>ADMIN_TOOLS → БАЗА ЗНАНИЙ</b>. Затем нажми <b>ОБНОВИТЬ</b>.'
      );
      return;
    }

    rules.forEach(r => {
      const cat = (r.category && String(r.category).trim()) ? r.category : 'RULE';
      const statusChip = (IS_ADMIN && Number(r.is_active) !== 1)
        ? '<span class="kb-chip off">HIDDEN</span>'
        : '';

      const el = document.createElement('div');
      el.className = 'kb-card';
      el.innerHTML = `
        <h2>${escapeHtml(r.title||'')} <span style="display:flex; gap:8px; align-items:center;">${statusChip}<span class="kb-chip">${escapeHtml(cat)}</span></span></h2>
        <div class="kb-rich">${stripDanger(r.body_html||'')}</div>
      `;
      root.appendChild(el);
      postProcessRich(el.querySelector('.kb-rich'));
    });
  };

  const renderAbilities = (abilities) => {
    const root = q('#kb-abilities');
    root.innerHTML = '';
    if (!abilities || !abilities.length) {
      root.innerHTML = renderEmptyCard(
        'СПОСОБНОСТИ НЕ НАЙДЕНЫ',
        'Добавь способности через админку: <b>ADMIN_TOOLS → БАЗА ЗНАНИЙ</b>. Затем нажми <b>ОБНОВИТЬ</b>.'
      );
      return;
    }

    abilities.forEach(a => {
      const meta = [];
      if (a.ability_type) meta.push(`<span class="kb-tag primary">${escapeHtml(a.ability_type)}</span>`);
      if (a.cooldown) meta.push(`<span class="kb-tag warn">КД: ${escapeHtml(a.cooldown)}</span>`);

      const tags = (a.tags ? String(a.tags) : '').split(',').map(x => x.trim()).filter(Boolean);
      tags.forEach(t => meta.push(`<span class="kb-tag">${escapeHtml(t)}</span>`));

      const statusChip = (IS_ADMIN && Number(a.is_active) !== 1)
        ? '<span class="kb-chip off">HIDDEN</span>'
        : '';

      const el = document.createElement('div');
      el.className = 'kb-card';
      el.innerHTML = `
        <div class="kb-ability">
          <div class="head">
            <div class="name">${escapeHtml(a.name||'')}</div>
            <div class="meta">${statusChip}${meta.join('')}</div>
          </div>
          ${(a.cost||a.cooldown) ? `
            <table class="kb-mini-table">
              ${a.cost ? `<tr><td>Стоимость</td><td>${escapeHtml(a.cost)}</td></tr>` : ''}
              ${a.cooldown ? `<tr><td>Перезарядка</td><td>${escapeHtml(a.cooldown)}</td></tr>` : ''}
            </table>
          ` : ''}
          <div class="kb-rich">${stripDanger(a.description_html||'')}</div>
        </div>
      `;
      root.appendChild(el);
      postProcessRich(el.querySelector('.kb-rich'));
    });
  };

  const getJson = async (url) => {
    const u = url + (url.indexOf('?')>=0 ? '&' : '?') + 't=' + Date.now();
    const r = await fetch(u, { credentials: 'same-origin', cache: 'no-store' });
    return await r.json();
  };

  const loadAll = async () => {
    const showHidden = !!(IS_ADMIN && q('#kb-show-hidden') && q('#kb-show-hidden').checked);
    try {
      let rr, aa;
      if (IS_ADMIN && showHidden) {
        rr = await getJson(api + '?action=kb_list_rules_admin');
        aa = await getJson(api + '?action=kb_list_abilities_admin');
      } else {
        rr = await getJson(api + '?action=kb_list_rules');
        aa = await getJson(api + '?action=kb_list_abilities');
      }

      const rules = rr && rr.rules ? rr.rules : [];
      const abilities = aa && aa.abilities ? aa.abilities : [];

      // Если admin+showHidden=false, но админ-листы вернули is_active — не фильтруем, потому что мы не просим admin endpoint.
      // Для обычного endpoint сервер уже фильтрует.

      renderRules(rules);
      renderAbilities(abilities);
    } catch(e) {
      // Показать диагностическую карточку
      const r = q('#kb-rules');
      const a = q('#kb-abilities');
      const msg = `
        <div class="kb-card">
          <h2>ОШИБКА ЗАГРУЗКИ <span class="kb-chip off">API</span></h2>
          <div class="kb-danger"><b>Не удалось загрузить данные базы знаний.</b><br>Попробуй нажать <b>ОБНОВИТЬ</b>.<br><span style="color:rgba(255,255,255,0.7)">Причина: ${escapeHtml(e && e.message ? e.message : String(e))}</span></div>
        </div>
      `;
      if (r) r.innerHTML = msg;
      if (a) a.innerHTML = msg;
    }
  };

  window.KB = {
    reload: loadAll
  };

  // listeners
  if (IS_ADMIN && q('#kb-show-hidden')) {
    q('#kb-show-hidden').addEventListener('change', loadAll);
  }

  loadAll();
})(window.__KB_ANCHOR);
</script>

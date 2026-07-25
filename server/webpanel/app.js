// ─── Utils ─────────────────────────────────────────────────
function esc(s) { if (!s) return ''; return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ─── Toast ─────────────────────────────────────────────────
const tc = document.createElement('div'); tc.className = 'toast-container'; document.body.appendChild(tc);
function showToast(msg, type = 'info', dur = 5000) {
  const e = document.createElement('div'); e.className = `toast ${type}`; e.innerHTML = msg;
  e.onclick = () => { e.classList.add('removing'); setTimeout(() => e.remove(), 350); };
  tc.appendChild(e); if (dur > 0) setTimeout(() => { if (e.parentNode) { e.classList.add('removing'); setTimeout(() => e.remove(), 350); } }, dur);
}

// ─── Modal ─────────────────────────────────────────────────
function openModal(id) { const m = document.getElementById(id); if (m) { m.classList.add('open'); m.querySelector('.modal')?.focus(); } }
function closeModal(id) { const m = document.getElementById(id); if (m) m.classList.remove('open'); }
function closeAllModals() { document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open')); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAllModals(); });

function switchTab(modalId, tab) {
  const m = document.getElementById(modalId); if (!m) return;
  m.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
  m.querySelectorAll('.tab-content').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
}

// ─── API ───────────────────────────────────────────────────
async function api(url, opts = {}) {
  const r = await fetch(url, { headers: { 'Content-Type': 'application/json', ...opts.headers }, ...opts });
  const d = await r.json(); if (!r.ok) throw new Error(d.error || r.statusText); return d;
}

// ─── Dashboard ─────────────────────────────────────────────
async function loadDashboard() {
  try {
    const d = await api('/api/stats');
    setStat('statPlayers', d.player_count);
    setStat('statClients', d.total_clients);
    setStat('statAreas', d.areas.length);
    setStat('statMods', d.mods.length);
    if (d.uptime) { const h = Math.floor(d.uptime/3600), m = Math.floor((d.uptime%3600)/60); setStat('statUptime', `${h}ч ${m}м`); }
    const bars = document.getElementById('graphBars');
    if (bars) {
      const mx = Math.max(...d.areas.map(a => a.players), 1);
      bars.innerHTML = d.areas.map(a => `<div class="bar" style="height:${Math.max((a.players/mx)*100,3)}%" title="${a.name}: ${a.players}"><div class="bar-lbl">${a.name.length>6?a.name.slice(0,6)+'…':a.name}</div></div>`).join('');
    }
    const tbl = document.getElementById('areasTable');
    if (tbl) tbl.innerHTML = d.areas.map(a => `<tr><td><span class="tag tag-cyan">${a.hub}</span></td><td>${a.name}</td><td><strong>${a.players}</strong></td><td style="color:var(--cyan);font-size:0.8rem">${a.music||'—'}</td></tr>`).join('');
  } catch (e) { showToast('Статистика: ' + e.message, 'error'); }
  loadApprovals();
}
function setStat(id, val) { const e = document.getElementById(id); if (e) e.textContent = val; }

// ─── Approvals Widget ─────────────────────────────────────
async function loadApprovals() {
  const el = document.getElementById('approvalsList'); if (!el) return;
  try {
    const d = await api('/api/approvals');
    if (!d.items||!d.items.length) { el.innerHTML = '<div class="text-muted" style="padding:10px;text-align:center">Нет pending-запросов</div>'; return; }
    let h = '';
    for (const req of d.items) {
      const typeLabel = req.type==='wl_join'?'Вайт-лист':req.type==='gm_request'?'GM':'Вход';
      h += `<div style="padding:10px;border-radius:8px;background:rgba(255,255,255,0.025);margin-bottom:6px;border-left:3px solid var(--gold)">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
          <div>
            <span class="tag tag-gold" style="font-size:0.6rem;padding:0 6px">${typeLabel}</span>
            <span style="font-size:0.75rem;color:var(--text);margin-left:6px">${esc(req.info)}</span>
          </div>
          <div style="display:flex;gap:4px;flex-shrink:0">
            <button class="btn btn-xs btn-success" onclick="resolveApproval('${req.type}','${req.id.replace(/'/g,"\\'")}','approve')">✓</button>
            <button class="btn btn-xs btn-danger" onclick="resolveApproval('${req.type}','${req.id.replace(/'/g,"\\'")}','reject')">✗</button>
          </div>
        </div>
      </div>`;
    }
    el.innerHTML = h;
  } catch(e) { el.innerHTML = '<div class="text-muted" style="padding:10px">Ошибка</div>'; }
}

async function resolveApproval(type, id, action) {
  try {
    const d = await api(`/api/approvals/${type}/${id}/${action}`, { method:'POST' });
    showToast(d.message||'Готово', 'success');
    loadApprovals();
  } catch(e) { showToast(e.message, 'error'); }
}

// ─── Leaderboard ───────────────────────────────────────────
async function loadLeaderboard() {
  try {
    const d = await api('/api/leaderboard');
    const el = document.getElementById('lbList'); if (!el) return;
    if (!d.leaders||!d.leaders.length) { el.innerHTML = '<div class="text-muted" style="padding:16px;text-align:center">Нет данных</div>'; return; }
    el.innerHTML = d.leaders.slice(0,15).map(p => {
      const rc = p.rank===1?'gold':p.rank===2?'silver':'normal';
      return `<div class="lb-row"><div class="lb-r ${rc}">${p.rank}</div><div class="lb-n">${p.name}</div><div class="lb-v"><div class="num">${p.play_time_hr}ч</div><div class="sub">${p.messages||0}</div></div></div>`;
    }).join('');
  } catch(e) { /* ignore */ }
}

// ─── Room Map ──────────────────────────────────────────────
async function loadRoomMap() {
  try {
    const d = await api('/api/room-map');
    const el = document.getElementById('roomMap'); if (!el) return;
    let h = '';
    for (const hub of d.hubs) {
      h += `<div class="mb-2"><span class="tag tag-cyan" style="margin-bottom:4px">${hub.name}</span><div class="rmap">`;
      for (const a of hub.areas) {
        h += `<div class="rnode" onclick="openRoomEditor(${a.id},${hub.id},'${a.name.replace(/'/g,"\\'")}','${(a.music||'').replace(/'/g,"\\'")}','${(a.background||'').replace(/'/g,"\\'")}')"><div class="rn-ind ${a.players?'on':'off'}"></div><div class="rn-name">${a.name}</div><div class="rn-cnt">${a.players}</div></div>`;
      }
      h += `</div></div>`;
    }
    el.innerHTML = h;
  } catch(e) { /* ignore */ }
}

// ─── Room Editor Modal ─────────────────────────────────────
let _areaId = null;
let _areaHubId = -1;
function openRoomEditor(id, hubId, name, music, bg) {
  _areaId = id;
  _areaHubId = hubId;
  document.getElementById('editAreaName').textContent = name;
  document.getElementById('editMusic').value = music||'';
  document.getElementById('editBg').value = bg||'';
  document.getElementById('editMusicPrev').textContent = music||'—';
  document.getElementById('editBgPrev').textContent = bg||'—';
  openModal('roomEditorModal');
}
function previewEdit() {
  document.getElementById('editMusicPrev').textContent = document.getElementById('editMusic').value||'—';
  document.getElementById('editBgPrev').textContent = document.getElementById('editBg').value||'—';
}
async function saveEdit() {
  try {
    await api('/api/area/update', { method:'POST', body: JSON.stringify({ area_id:_areaId, hub_id:_areaHubId, music:document.getElementById('editMusic').value, background:document.getElementById('editBg').value }) });
    showToast('Зона обновлена', 'success');
    closeModal('roomEditorModal'); loadRoomMap(); loadDashboard(); if (document.body.dataset.page==='rooms') loadRooms();
  } catch(e) { showToast(e.message, 'error'); }
}

// ─── Full Room Editor Page ────────────────────────────────
let _editingAreaId = null;
async function loadRooms() {
  try {
    const d = await api('/api/room-map');
    const el = document.getElementById('roomsContainer'); if (!el) return;
    let h = '';
    for (const hub of d.hubs) {
      h += `<div class="panel mb-3"><div class="panel-hd"><h3><span class="accent">◆</span> ${hub.name}</h3><div class="header-actions"><button class="btn btn-sm btn-ghost" onclick="renameHub(${hub.id},'${hub.name.replace(/'/g,"\\'")}')">Переим.</button><button class="btn btn-sm btn-danger" onclick="deleteHub(${hub.id})">Удалить</button></div></div><div class="panel-bd"><div class="rmap-ed">`;
      for (const a of hub.areas) {
        h += `<div class="rnode-ed" onclick="openAreaEditor(${hub.id},${a.id},'${a.name.replace(/'/g,"\\'")}','${(a.music||'').replace(/'/g,"\\'")}','${(a.background||'').replace(/'/g,"\\'")}')"><div class="rned-name">${a.name}</div><div class="rned-players">${a.players} игр.</div></div>`;
      }
      h += `<div class="rnode-ed add" onclick="addArea(${hub.id})"><div class="rned-name" style="color:var(--cyan)">+</div><div class="rned-players">Добавить зону</div></div>`;
      h += `</div></div></div>`;
    }
    el.innerHTML = h;
  } catch(e) { showToast('Ошибка загрузки комнат: '+e.message, 'error'); }
}

const PREF_NAMES = {
  showname_changes_allowed: "Смена showname", shouts_allowed: "Крики",
  jukebox: "Jukebox", non_int_pres_only: "Только non-int пресеты",
  blankposting_allowed: "Бланкпостинг", blankposting_forced: "Принуд. бланк",
  hide_clients: "Скрыть клиентов", music_autoplay: "Авто-музыка",
  replace_music: "Замена музыки", client_music: "Клиентская музыка",
  can_dj: "DJ", hidden: "Скрытая", can_whisper: "Шёпот",
  can_wtce: "WTCE", can_spectate: "Спектатор", can_getarea: "GetArea",
  can_cross_swords: "Cross Swords", can_scrum_debate: "Scrum Debate",
  can_panic_talk_action: "Panic Talk", bg_lock: "Блок фона",
  force_sneak: "Принуд. скрыт", present_reveals_evidence: "Предъявление улик",
  overlay_lock: "Блок оверлея", locking_allowed: "Блокировка",
  iniswap_allowed: "Iniswap", locked: "Закрыта", muted: "Замучена",
  can_change_status: "Смена статуса", dark: "Темнота",
  passing_msg: "IC при входе", use_backgrounds_yaml: "Bg YAML",
  can_cm: "CM",
};

function openAreaEditor(hubId, areaId, name, music, bg) {
  _editingAreaId = areaId;
  document.getElementById('editAreaHubId').value = hubId;
  document.getElementById('editAreaId').value = areaId;
  document.getElementById('editAreaName').textContent = name;
  document.getElementById('editAName').value = name;
  document.getElementById('editAMusic').value = music||'';
  document.getElementById('editABg').value = bg||'';
  document.getElementById('editADesc').value = '';
  document.getElementById('editAStatus').value = 'IDLE';
  // Load current data via room-map API
  (async()=>{
    try {
      const s = await api('/api/room-map');
      const targetHub = s.hubs.find(h => h.id === hubId);
      if (targetHub) {
        const a = targetHub.areas.find(x => x.id === areaId);
        if (a) {
          document.getElementById('editADesc').value = a.desc||'';
          if (a.status) document.getElementById('editAStatus').value = a.status;
        }
      }
    } catch(e){}
  })();
  // Load prefs
  (async()=>{
    try {
      const p = await api(`/api/area/${areaId}/prefs?hub_id=${hubId}`);
      const el = document.getElementById('prefList');
      if (!el) return;
      const prefs = p.prefs||{};
      groupAndRenderPrefs(el, prefs);
    } catch(e) { /* ignore */ }
  })();
  // Switch to basic tab
  const m = document.getElementById('areaEditorModal');
  if (m) {
    m.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === 'area-basic'));
    m.querySelectorAll('.tab-content').forEach(t => t.classList.toggle('active', t.dataset.tab === 'area-basic'));
  }
  openModal('areaEditorModal');
}

function groupAndRenderPrefs(el, prefs) {
  const groups = {
    'Общение': ['showname_changes_allowed','shouts_allowed','blankposting_allowed','blankposting_forced','can_whisper','passing_msg'],
    'Музыка': ['jukebox','music_autoplay','replace_music','client_music','can_dj'],
    'Видимость': ['hide_clients','hidden','non_int_pres_only','can_spectate','can_getarea'],
    'Мини-игры': ['can_cross_swords','can_scrum_debate','can_panic_talk_action','can_wtce'],
    'Безопасность': ['bg_lock','overlay_lock','locking_allowed','locked','muted','dark','force_sneak','iniswap_allowed'],
    'Прочее': ['can_change_status','use_backgrounds_yaml','can_cm','present_reveals_evidence','can_battle','hide_players'],
  };
  let html = '';
  for (const [group, keys] of Object.entries(groups)) {
    const items = keys.filter(k => k in prefs);
    if (!items.length) continue;
    html += `<div class="pref-group">${group}</div><div class="pref-items">`;
    for (const k of items) {
      const checked = prefs[k] ? 'checked' : '';
      html += `<label class="pref-chip${checked?' on':''}"><input type="checkbox" class="pref-cb" value="${k}" ${checked}><span>${PREF_NAMES[k]||k}</span></label>`;
    }
    html += '</div>';
  }
  el.innerHTML = html;
  // Update chip style on change
  el.querySelectorAll('.pref-cb').forEach(cb => {
    cb.onchange = () => {
      cb.parentElement.classList.toggle('on', cb.checked);
    };
  });
}

async function saveAreaFull() {
  const areaId = parseInt(document.getElementById('editAreaId').value);
  const hubId = parseInt(document.getElementById('editAreaHubId').value);
  const newName = document.getElementById('editAName').value.trim();
  const music = document.getElementById('editAMusic').value.trim();
  const bg = document.getElementById('editABg').value.trim();
  const desc = document.getElementById('editADesc').value.trim();
  const status = document.getElementById('editAStatus').value;
  const changes = { area_id: areaId, hub_id: hubId };
  if (music) changes.music = music;
  if (bg) changes.background = bg;
  if (desc) changes.desc = desc;
  if (status) changes.status = status;
  try {
    if (newName) {
      await api('/api/area/rename', { method:'POST', body: JSON.stringify({ area_id: areaId, hub_id: hubId, name: newName }) });
    }
    await api('/api/area/bulk-update', { method:'POST', body: JSON.stringify(changes) });
    // Save prefs
    const prefs = {};
    document.querySelectorAll('.pref-cb').forEach(cb => { prefs[cb.value] = cb.checked; });
    if (Object.keys(prefs).length) {
      await api(`/api/area/${areaId}/prefs`, { method:'POST', body: JSON.stringify({ hub_id: hubId, prefs }) });
    }
    showToast('Зона обновлена', 'success');
    closeModal('areaEditorModal');
    loadRooms();
  } catch(e) { showToast(e.message, 'error'); }
}

async function renameHub(hubId, curName) {
  const name = prompt('Новое название хаба:', curName);
  if (!name||name===curName) return;
  try {
    await api('/api/hub/rename', { method:'POST', body: JSON.stringify({ hub_id: hubId, name }) });
    showToast('Хаб переименован', 'success');
    loadRooms();
  } catch(e) { showToast(e.message, 'error'); }
}

async function deleteHub(hubId) {
  if (hubId===0) { showToast('Нельзя удалить первый хаб', 'warning'); return; }
  if (!confirm('Удалить хаб? Все игроки будут перемещены в первый хаб.')) return;
  try {
    await api('/api/hub/delete', { method:'POST', body: JSON.stringify({ hub_id: hubId }) });
    showToast('Хаб удалён', 'success');
    loadRooms();
  } catch(e) { showToast(e.message, 'error'); }
}

async function addArea(hubId) {
  const name = prompt('Название новой зоны:');
  if (!name) return;
  try {
    await api('/api/area/create', { method:'POST', body: JSON.stringify({ hub_id: hubId, name }) });
    showToast('Зона создана', 'success');
    loadRooms();
  } catch(e) { showToast(e.message, 'error'); }
}

async function addHub() {
  const name = prompt('Название нового хаба:');
  if (!name) return;
  try {
    await api('/api/hub/create', { method:'POST', body: JSON.stringify({ name }) });
    showToast('Хаб создан', 'success');
    loadRooms();
  } catch(e) { showToast(e.message, 'error'); }
}

async function deleteArea(areaId, hubId) {
  if (!confirm('Удалить зону?')) return;
  try {
    await api('/api/area/delete', { method:'POST', body: JSON.stringify({ area_id: areaId, hub_id: hubId }) });
    showToast('Зона удалена', 'success');
    loadRooms();
  } catch(e) { showToast(e.message, 'error'); }
}

// ─── Bans ─────────────────────────────────────────────────
async function loadBans() {
  try {
    const d = await api('/api/bans');
    const tbl = document.getElementById('bansTable'); if (!tbl) return;
    if (!d.bans||!d.bans.length) { tbl.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px">Нет банов</td></tr>'; return; }
    tbl.innerHTML = d.bans.map(b => `<tr><td style="color:var(--text-dim)">#${b.ban_id}</td><td style="font-size:0.78rem">${b.ban_date||'—'}</td><td>${b.banned_by_name||b.banned_by||'—'}</td><td>${b.reason||'—'}</td><td style="font-size:0.78rem">${b.unban_date||'<span class="tag tag-magenta">навсегда</span>'}</td><td>${b.unban_date?'':`<button class="btn btn-xs btn-danger" onclick="unban(${b.ban_id})">разбанить</button>`}</td></tr>`).join('');
  } catch(e) { showToast('Баны: '+e.message, 'error'); }
}
async function unban(id) { if (!confirm('Разбанить?')) return; try { await api('/api/unban',{method:'POST',body:JSON.stringify({ban_id:id})}); showToast('Разбанен','success'); loadBans(); } catch(e) { showToast(e.message,'error'); } }

// ─── Players ───────────────────────────────────────────────
async function loadPlayers(s = '') {
  try {
    const d = await api('/api/stats');
    const el = document.getElementById('playerList'); if (!el) return;
    let p = d.players||[];
    if (s) { const q = s.toLowerCase(); p = p.filter(x => x.name.toLowerCase().includes(q)||x.char_name.toLowerCase().includes(q)||String(x.ipid).includes(q)); }
    if (!p.length) { el.innerHTML = '<div style="text-align:center;padding:32px;color:var(--text-dim)">Нет игроков онлайн</div>'; return; }
    el.innerHTML = p.map((x,i) => `<div class="pcard" onclick="openPlayerModal(${x.ipid})" style="animation-delay:${i*0.02}s"><div class="pcard-name">${x.char_name||x.name} <span style="font-weight:400;color:var(--text-dim)">(${x.name})</span>${x.is_mod?' <span class="tag tag-cyan">мод</span>':''}${x.is_muted?' <span class="tag tag-gold">мут</span>':''}${x.warnings_count?' <span class="tag tag-danger">'+x.warnings_count+' пред.</span>':''}</div><div class="pcard-meta"><span class="tag tag-cyan" style="font-size:0.6rem;padding:0 6px">${x.hub}</span> ${x.area} · IPID:${x.ipid}</div></div>`).join('');
  } catch(e) { showToast('Игроки: '+e.message, 'error'); }
}

// ─── Player Modal ─────────────────────────────────────────
async function openPlayerModal(ipid) {
  try {
    const info = await api(`/api/player/${ipid}`);
    const modal = document.getElementById('playerModal'); if (!modal) return;
    modal.dataset.ipid = ipid;
    document.getElementById('modalPName').textContent = info.char_name||info.name||'неизвестно';
    document.getElementById('modalPStatus').innerHTML = info.online ? '<span class="tag tag-green">онлайн</span>' : '<span class="tag tag-magenta">оффлайн</span>';

    // Playtime with current session
    let pt = info.stats?.playtime_seconds||0;
    const ptH = Math.floor(pt/3600), ptM = Math.floor((pt%3600)/60);

    document.getElementById('playerInfo').innerHTML = `
      <div class="grid-2">
        <div><div class="text-muted" style="font-size:0.75rem">OOC</div><div>${info.name||'—'}</div></div>
        <div><div class="text-muted" style="font-size:0.75rem">Персонаж</div><div>${info.char_name||'—'}</div></div>
        <div><div class="text-muted" style="font-size:0.75rem">IPID</div><div class="text-cyan">${info.ipid}</div></div>
        <div><div class="text-muted" style="font-size:0.75rem">IP</div><div>${info.ip||'—'}</div></div>
        <div><div class="text-muted" style="font-size:0.75rem">HDID</div><div style="font-size:0.7rem;word-break:break-all;color:var(--text-dim)">${info.hdid||'—'}</div></div>
        <div><div class="text-muted" style="font-size:0.75rem">Локация</div><div>${info.hub} / ${info.area}</div></div>
        <div><div class="text-muted" style="font-size:0.75rem">ID</div><div>${info.id}</div></div>
        <div><div class="text-muted" style="font-size:0.75rem">Char ID</div><div>${info.char_id}</div></div>
      </div>
      <div class="mt-3"><div class="text-muted" style="font-size:0.75rem">Прошлые имена</div><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px">${(info.past_names||[]).map(n=>`<span class="tag tag-cyan">${n.name} (${n.count})</span>`).join('')||'<span class="text-muted">—</span>'}</div></div>
      <div class="mt-2"><div class="text-muted" style="font-size:0.75rem">HDIDs</div><div style="font-size:0.7rem;word-break:break-all;color:var(--text-dim)">${(info.hdids||[]).join(', ')||'—'}</div></div>
      <div class="mt-2"><div class="text-muted" style="font-size:0.75rem">Статистика</div><div class="flex gap-3 mt-1" style="font-size:0.82rem"><span>${ptH}ч ${ptM}м</span><span>IC:${info.stats?.ic_messages||0}</span><span>OOC:${info.stats?.ooc_messages||0}</span><span>Входов:${info.stats?.logins||0}</span></div></div>`;
    const actionsDiv = document.getElementById('playerActions');
    if (info.online) {
      actionsDiv.innerHTML = `
        <div class="act-group"><div class="act-group-label">Наказания</div><div class="act-group-btns"><button class="btn btn-sm btn-danger" onclick="playerKick(${ipid})">Кик</button><button class="btn btn-sm btn-danger" onclick="playerBan(${ipid})">Бан</button><button class="btn btn-sm btn-warning" onclick="playerWarn(${ipid})">Пред.</button></div></div>
        <div class="act-group"><div class="act-group-label">Ограничения</div><div class="act-group-btns"><button class="btn btn-sm btn-warning" onclick="playerMute(${ipid},'ic')">Мут IC</button><button class="btn btn-sm btn-warning" onclick="playerMute(${ipid},'ooc')">Мут OOC</button></div></div>
        <div class="act-group"><div class="act-group-label">Развлечения</div><div class="act-group-btns"><button class="btn btn-sm btn-ghost" onclick="playerFun(${ipid},'disemvowel')">Безгласный</button><button class="btn btn-sm btn-ghost" onclick="playerFun(${ipid},'shake')">Тряска</button><button class="btn btn-sm btn-ghost" onclick="playerFun(${ipid},'rainbow')">Радуга</button></div></div>
        <div class="act-group"><div class="act-group-label">Иммитация</div><div class="act-group-btns"><button class="btn btn-sm btn-ghost" onclick="showImpersonateModal(${ipid},'ic')">IC</button><button class="btn btn-sm btn-ghost" onclick="showImpersonateModal(${ipid},'ooc')">OOC</button></div></div>`;
    } else {
      actionsDiv.innerHTML = '<div class="text-muted" style="padding:24px;text-align:center">Игрок не в сети</div>';
    }
    const achEl = document.getElementById('pAchieveList');
    try {
      const ad = await api(`/api/achievements?ipid=${ipid}`);
      const allDefs = await api('/api/achievements');
      const defMap = {};
      (allDefs.achievements||[]).forEach(a => { defMap[a.id] = a; });
      const unlocked = ad.achievements||[];
      const catOrder = ['communication','activity','exploration','moderation'];
      const catNames = {'communication':'Общение','activity':'Активность','exploration':'Исследование','moderation':'Модерация'};
      let achHtml = '<div style="display:flex;gap:8px;margin-bottom:10px"><button class="btn btn-sm btn-success" onclick="grantAchievement('+ipid+')">+ Выдать</button></div>';
      for (const cat of catOrder) {
        const items = unlocked.filter(a => a.category===cat);
        if (!items.length) continue;
        achHtml += `<div class="ach-cat">${catNames[cat]||cat}</div>`;
        items.forEach(a => {
          achHtml += `<div class="ach-row"><div class="ach-info"><strong>${a.name}</strong><br><span class="text-muted" style="font-size:0.75rem">${a.description||''}</span></div><div class="ach-actions">${a.unlocked?'<span class="tag tag-green">получено</span>':''}<button class="btn btn-xs btn-danger" onclick="revokeAchievement(${ipid},'${a.id}')">Отозвать</button></div></div>`;
        });
      }
      if (!unlocked.length) achHtml += '<span class="text-muted">Нет достижений</span>';
      achEl.innerHTML = achHtml;
    } catch(e) { achEl.innerHTML = '<span class="text-muted">Ошибка</span>'; }
    const wDiv = document.getElementById('playerWarnings');
    if (info.warnings&&info.warnings.length) { wDiv.innerHTML = info.warnings.map(w => `<div style="padding:8px 0;border-bottom:1px solid var(--border-subtle);display:flex;gap:8px;align-items:flex-start"><div style="flex:1"><div style="color:var(--gold)">${w.reason}</div><div class="text-muted" style="font-size:0.7rem">${w.warned_by||'Panel'} · ${w.warned_at||''}</div></div><button class="btn btn-xs btn-ghost" onclick="deleteWarning(${ipid},${w.id})" title="Удалить">✕</button></div>`).join(''); } else { wDiv.innerHTML = '<span class="text-muted">Нет предупреждений</span>'; }
    loadPlayerMessages(ipid);
    const infoDiv = document.getElementById('playerInfo');
    if (infoDiv) { const gb = document.createElement('div'); gb.className = 'mt-3'; gb.innerHTML = `<button class="btn btn-ghost btn-sm" onclick="loadConnectionGraph(${ipid})">◆ Связи</button><div id="graphInfo" class="text-muted mt-1" style="font-size:0.75rem"></div><div id="connectionGraph" class="graph-box mt-2"></div>`; infoDiv.appendChild(gb); }
    openModal('playerModal');
  } catch(e) { showToast('Игрок: '+e.message, 'error'); }
}

// ─── Player Messages ───────────────────────────────────────
async function loadPlayerMessages(ipid) {
  try {
    const d = await api(`/api/player/${ipid}/messages?limit=30`);
    const el = document.getElementById('playerMessages'); if (!el) return;
    if (!d.messages||!d.messages.length) { el.innerHTML = '<div class="text-muted" style="padding:16px;text-align:center">Нет сообщений</div>'; return; }
    el.innerHTML = d.messages.map(m => `<div class="surv-row"><span class="stime">${m.time||''}</span><span class="sname">${m.char_name||m.ooc_name||'?'}</span><span class="smsg">${m.message||''}</span><span class="sarea">${m.hub||''}${m.area?'/'+m.area:''}</span></div>`).join('');
    el.scrollTop = el.scrollHeight;
  } catch(e) { const el = document.getElementById('playerMessages'); if(el) el.innerHTML = '<div class="text-muted">Ошибка</div>'; }
}

// ─── Player Actions ────────────────────────────────────────
async function playerKick(ipid) { const r = prompt('Причина:'); if(r===null) return; try{await api('/api/player/kick',{method:'POST',body:JSON.stringify({ipid,reason:r||'Kicked'})});showToast('Кикнут','success');closeModal('playerModal');loadPlayers();}catch(e){showToast(e.message,'error');} }
async function playerBan(ipid) { const r = prompt('Причина:'); if(r===null) return; const h = prompt('Часов (0=навсегда):','0'); if(h===null) return; try{await api('/api/player/ban',{method:'POST',body:JSON.stringify({ipid,reason:r||'Banned',hours:parseInt(h)||0})});showToast('Забанен','success');closeModal('playerModal');loadPlayers();loadBans();}catch(e){showToast(e.message,'error');} }
async function playerMute(ipid,t) { try{const r=await api('/api/player/mute',{method:'POST',body:JSON.stringify({ipid,type:t})});showToast(`Мут ${t=== 'ic'?'IC':'OOC'}: ${r.state==='unmuted'?'снят':'включён'}`,'success');}catch(e){showToast(e.message,'error');} }
async function playerFun(ipid,c) { try{const r=await api('/api/player/fun',{method:'POST',body:JSON.stringify({ipid,command:c})});showToast(`${c}: ${r.state}`,'success');}catch(e){showToast(e.message,'error');} }
async function playerWarn(ipid) { const r = prompt('Причина предупреждения:'); if(r===null||!r.trim()) return; try{await api('/api/player/warn',{method:'POST',body:JSON.stringify({ipid,reason:r})});showToast('Предупреждение выдано','success');closeModal('playerModal');}catch(e){showToast(e.message,'error');} }
async function deleteWarning(ipid,wid) { if(!confirm('Удалить предупреждение?')) return; try{await api('/api/player/warn/delete',{method:'POST',body:JSON.stringify({ipid,warning_id:wid})});showToast('Удалено','success');openPlayerModal(ipid);}catch(e){showToast(e.message,'error');} }
async function revokeAchievement(ipid,aid) { if(!confirm(`Отозвать ${aid}?`)) return; try{await api('/api/achievements/revoke',{method:'POST',body:JSON.stringify({ipid,achievement_id:aid})});showToast('Отозвано','success');openPlayerModal(ipid);}catch(e){showToast(e.message,'error');} }
function showImpersonateModal(ipid,t) { const m = prompt(`Введите ${t.toUpperCase()} сообщение:`); if(m===null||!m.trim()) return; const ep = t==='ic'?'/api/player/impersonate_ic':'/api/player/impersonate_ooc'; api(ep,{method:'POST',body:JSON.stringify({ipid,message:m})}).then(()=>showToast('Отправлено','success')).catch(e=>showToast(e.message,'error')); }
async function grantAchievement(ipid) { try{const all=await api('/api/achievements');const list=all.achievements||[];if(!list.length){showToast('Нет достижений','warning');return;}const choice=prompt(`Доступные:\n${list.map(a=>`${a.id}: ${a.name||''}`).join('\n')}\n\nВведите ID:`);if(!choice)return;await api('/api/achievements/grant',{method:'POST',body:JSON.stringify({ipid,achievement_id:choice.trim()})});showToast('Выдано','success');}catch(e){showToast(e.message,'error');} }

// ─── Logs ─────────────────────────────────────────────────
function openLogDetail(idx) {
  try {
    const l = _logsCache[idx]; if (!l) return;
    const body = document.getElementById('logDetailBody');
    const typeLabel = l.type==='chat.ic'?'IC':'OOC';
    const typeCls = l.type==='chat.ic'?'tag-cyan':'tag-gold';
    body.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
        <div><div class="lbl" style="font-size:0.7rem;color:var(--text-dim)">Время (МСК)</div><div>${l.time}</div></div>
        <div><div class="lbl" style="font-size:0.7rem;color:var(--text-dim)">Тип</div><span class="tag ${typeCls}">${typeLabel}</span></div>
        <div><div class="lbl" style="font-size:0.7rem;color:var(--text-dim)">IPID</div><div>${l.ipid||'—'}</div></div>
        <div><div class="lbl" style="font-size:0.7rem;color:var(--text-dim)">Имя</div><div>${(function(l){const n=l.char_name||l.ooc_name||'—';return (l.ic_name&&l.ic_name!==l.char_name)?n+'//'+l.ic_name:n})(l)}</div></div>
        <div><div class="lbl" style="font-size:0.7rem;color:var(--text-dim)">Локация</div><div>${l.hub||''}${l.area?'/'+l.area:''}</div></div>
      </div>
      <div class="lbl" style="font-size:0.7rem;color:var(--text-dim);margin-bottom:4px">Сообщение</div>
      <div style="padding:12px;border-radius:8px;background:rgba(0,0,0,0.15);white-space:pre-wrap;word-break:break-word;font-size:0.85rem;line-height:1.5">${esc(l.message||'')}</div>
    `;
    openModal('logDetailModal');
  } catch(e) { showToast('Ошибка: '+e.message, 'error'); }
}

let _logsCache = [];

async function goToLogPage(time) {
  try {
    const d = await api(`/api/logs/find-page?time=${encodeURIComponent(time)}`);
    if (!d.page) { showToast('Не удалось определить страницу','warning'); return; }
    window._jumpToTime = time;
    const q = document.getElementById('logQuery'); if (q) q.value = '';
    const ip = document.getElementById('logIpid'); if (ip) ip.value = '';
    const h = document.getElementById('logHub'); if (h) h.value = '';
    const a = document.getElementById('logArea'); if (a) a.value = '';
    await loadLogs(d.page);
  } catch(e) { showToast('Переход: '+e.message,'error'); }
}

async function loadLogs(page) {
  if (page===undefined) page = window._logPage||1;
  try {
    const q = document.getElementById('logQuery')?.value;
    const ipid = document.getElementById('logIpid')?.value;
    const hub = document.getElementById('logHub')?.value;
    const area = document.getElementById('logArea')?.value;
    let url = `/api/logs?page=${page}&limit=50`;
    if (q) url += `&q=${encodeURIComponent(q)}`;
    if (ipid) url += `&ipid=${encodeURIComponent(ipid)}`;
    if (hub) url += `&hub=${encodeURIComponent(hub)}`;
    if (area) url += `&area=${encodeURIComponent(area)}`;
    const d = await api(url);
    const tbl = document.getElementById('logsTable'); if (!tbl) return;
    if (!d.logs||!d.logs.length) { tbl.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px">Нет сообщений</td></tr>'; return; }
    const pi = document.getElementById('logPageInfo');
    if (pi) { const tp = Math.ceil(d.total/d.limit); pi.textContent = `Стр. ${d.page}/${tp||1} (${d.total})`; }
    _logsCache = d.logs;
    function _logName(l) { const n = l.char_name||l.ooc_name||'—'; return (l.ic_name&&l.ic_name!==l.char_name) ? n+'//'+l.ic_name : n; }
    tbl.innerHTML = d.logs.map((l,i) => `<tr><td style="font-size:0.72rem;white-space:nowrap;color:var(--text-dim)">${l.time}</td><td><span class="tag ${l.type==='chat.ic'?'tag-cyan':'tag-gold'}">${l.type==='chat.ic'?'IC':'OOC'}</span></td><td>${_logName(l)}</td><td style="font-size:0.75rem;color:var(--text-dim)">${l.hub||''}${l.area?'/'+l.area:''}</td><td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer" onclick="openLogDetail(${i})" title="Нажмите для деталей">${l.message||''}</td><td><button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();goToLogPage('${l.time}')" style="font-size:0.7rem;padding:2px 8px">Перейти</button></td></tr>`).join('');
    window._logPage = d.page;
    const p = document.getElementById('logPrev'); if(p) p.style.display = d.page>1?'inline-flex':'none';
    const n = document.getElementById('logNext'); if(n) n.style.display = d.page*d.limit<d.total?'inline-flex':'none';
    if (window._jumpToTime) {
      const jt = window._jumpToTime; window._jumpToTime = null;
      setTimeout(() => {
        const rows = tbl.querySelectorAll('tr');
        for (let i = 0; i < rows.length; i++) {
          const td = rows[i].querySelector('td:first-child');
          if (td && td.textContent.trim() === jt) {
            rows[i].style.background = 'var(--gold-dim)';
            rows[i].scrollIntoView({ block: 'center', behavior: 'smooth' });
            break;
          }
        }
      }, 200);
    }
  } catch(e) { showToast('Логи: '+e.message, 'error'); }
}

// ─── Hubs filter ───────────────────────────────────────────
async function loadHubsFilter() {
  try {
    const d = await api('/api/hubs');
    const hs = document.getElementById('logHub'); if (!hs) return;
    hs.innerHTML = '<option value="">Все хабы</option>'+d.hubs.map(h=>`<option value="${h.id}">${h.name}</option>`).join('');
    hs.onchange = () => {
      const v = hs.value; const as = document.getElementById('logArea');
      if (as) { as.innerHTML = '<option value="">Все зоны</option>'; const hub = d.hubs.find(h=>String(h.id)===v); if (hub) as.innerHTML += hub.areas.map(a=>`<option value="${a.id}">${a.name}</option>`).join(''); }
      loadLogs(1);
    };
  } catch(e) { /* ignore */ }
}

// ─── OOC ───────────────────────────────────────────────────
async function sendOOC() {
  const t = document.getElementById('oocText')?.value;
  if (!t||!t.trim()) { showToast('Введите текст', 'warning'); return; }
  try { await api('/api/send_ooc',{method:'POST',body:JSON.stringify({text:t})}); showToast('OOC отправлен', 'success'); document.getElementById('oocText').value=''; } catch(e) { showToast(e.message, 'error'); }
}

// ─── Console ───────────────────────────────────────────────
function con(msg, cls='i') { const o=document.getElementById('conOut'); if(!o) return; const d=document.createElement('div'); d.className=`l ${cls}`; d.textContent=msg; o.appendChild(d); o.scrollTop=o.scrollHeight; }
async function execCmd() {
  const inp = document.getElementById('conIn'); if (!inp||!inp.value.trim()) return;
  const c = inp.value.trim(); con('> '+c,'i'); inp.value='';
  try { const r = await api('/api/execute',{method:'POST',body:JSON.stringify({command:c})}); con(r.result||'OK','s'); } catch(e) { con('Ошибка: '+e.message,'e'); }
}

// ─── Export ────────────────────────────────────────────────
function exportCSV(t) { window.open(`/api/export/${t}`,'_blank'); showToast('Экспорт '+t,'success'); }

// ─── Connection Graph ──────────────────────────────────────
async function loadConnectionGraph(ipid) {
  const c = document.getElementById('connectionGraph'); if (!c) return;
  try {
    const d = await api(`/api/connections/${ipid}`);
    const cv = document.createElement('canvas'); cv.width = c.clientWidth||600; cv.height = c.clientHeight||260;
    c.innerHTML = ''; c.appendChild(cv);
    const ctx = cv.getContext('2d'); if(!ctx) return;
    const nodes = d.nodes||[], edges = d.edges||[];
    if (!nodes.length) { const el=document.getElementById('graphInfo'); if(el) el.textContent='Нет связей'; return; }
    const cx = cv.width/2, cy = cv.height/2;
    const pos = {};
    nodes.forEach((n,i) => { const a = (i/nodes.length)*Math.PI*2; const r = n.type==='central'?0:50+Math.random()*50; pos[n.id] = {x:cx+Math.cos(a)*r, y:cy+Math.sin(a)*r}; });
    function draw() {
      ctx.clearRect(0,0,cv.width,cv.height);
      ctx.strokeStyle = 'rgba(0,180,255,0.1)'; ctx.lineWidth = 1;
      edges.forEach(e => { const a=pos[e.source], b=pos[e.target]; if(a&&b){ ctx.beginPath(); ctx.moveTo(a.x,a.y); ctx.lineTo(b.x,b.y); ctx.stroke(); } });
      nodes.forEach(n => { const p=pos[n.id]; if(!p) return; let color='rgba(0,180,255,0.5)', r=5; if(n.type==='central'){color='rgba(255,215,64,0.9)';r=9;} else if(n.type==='hdid'){color='rgba(255,64,129,0.4)';r=4;}
        ctx.beginPath(); ctx.arc(p.x,p.y,r,0,Math.PI*2); ctx.fillStyle=color; ctx.shadowColor=color; ctx.shadowBlur=6; ctx.fill(); ctx.shadowBlur=0;
        ctx.fillStyle='rgba(200,214,229,0.6)'; ctx.font='9px sans-serif'; ctx.textAlign='center'; ctx.fillText(n.label,p.x,p.y+r+10);
      });
    }
    draw();
    const el = document.getElementById('graphInfo'); if(el) el.textContent = `Узлов: ${nodes.length}, связей: ${edges.length}`;
  } catch(e) { c.innerHTML = '<div class="text-muted" style="padding:20px;text-align:center">Ошибка</div>'; }
}

// ─── Widget Config ─────────────────────────────────────────
async function loadWidgetCfg() {
  const ids = ['graph','areas','approvals','ooc','lb','console'];
  ids.forEach(id => { const e=document.getElementById(id+'W'); if(e) e.style.display='none'; });
  try {
    const d = await api('/api/dashboard/config');
    const cfg = d.config || { widgets: ids };
    (cfg.widgets||[]).forEach(id => { const e=document.getElementById(id+'W'); if(e) e.style.display=''; });
    document.querySelectorAll('.wcfg input').forEach(cb => { cb.checked = (cfg.widgets||[]).includes(cb.value); cb.onchange = saveWidgetCfg; });
  } catch(e) { /* ignore */ }
}
async function saveWidgetCfg() {
  const checked = []; document.querySelectorAll('.wcfg input:checked').forEach(cb => checked.push(cb.value));
  try { await api('/api/dashboard/config', {method:'POST', body:JSON.stringify({config:{widgets:checked}})}); } catch(e) { /* ignore */ }
}

// ─── WebSocket ─────────────────────────────────────────────
let ws = null;
function connectWS() {
  try {
    const proto = location.protocol==='https:'?'wss:':'ws:';
    ws = new WebSocket(`${proto}//${location.host}/ws`);
    ws.onmessage = e => { try { const m=JSON.parse(e.data); if(m.type==='ban') showToast('[Бан] '+m.what,'warning'); } catch(ex){} };
    ws.onclose = () => { setTimeout(connectWS,3000); };
  } catch(e) { setTimeout(connectWS,3000); }
}
connectWS();

// ─── Profile / Account ─────────────────────────────────────
async function openProfileModal() {
  const body = document.getElementById('profileModalBody');
  body.innerHTML = '<div class="text-muted" style="text-align:center;padding:24px">Загрузка...</div>';
  openModal('profileModal');
  try {
    const d = await api('/api/account/profile');
    if (!d.ok) { body.innerHTML = '<div class="text-muted" style="text-align:center;padding:24px">Вы не вошли в аккаунт. Используется общий пароль.</div><button class="btn btn-sm btn-ghost w-full mt-2" onclick="closeModal(\'profileModal\');location.href=\'/login\'">Войти в аккаунт</button>'; return; }
    const a = d.account;
    const roleNames = {superadmin:'Гл. администратор',admin:'Администратор',moderator:'Модератор',ga:'GA',user:'Пользователь'};
    let h = `<div class="profile-row"><span class="profile-label">Логин</span><span class="profile-value">${a.username||''}</span></div>`;
    h += `<div class="profile-row"><span class="profile-label">Имя</span><span class="profile-value">${a.display_name||''}</span></div>`;
    h += `<div class="profile-row"><span class="profile-label">Роль</span><span class="profile-value">${roleNames[a.role]||a.role}</span></div>`;
    h += `<div class="profile-row"><span class="profile-label">Создан</span><span class="profile-value">${a.created_at||''}</span></div>`;
    h += `<div class="profile-row"><span class="profile-label">Последний вход</span><span class="profile-value">${a.last_login_at||'—'}</span></div>`;
    h += `<div class="mt-2"><button class="btn btn-sm btn-success w-full" onclick="editProfileName(${JSON.stringify(a.display_name||'')})">Изменить имя</button></div>`;
    h += `<div class="mt-1"><button class="btn btn-sm btn-ghost w-full" onclick="doAccountLogout()">Выйти из аккаунта</button></div>`;
    if (a.role === 'superadmin') {
      h += `<div class="mt-3" style="border-top:1px solid var(--border);padding-top:12px"><h4 style="margin-bottom:8px">Управление пользователями</h4><div id="userList"></div></div>`;
    }
    body.innerHTML = h;
    if (a.role === 'superadmin') loadUserList();
  } catch(e) { body.innerHTML = '<span class="text-muted">Ошибка: '+e.message+'</span>'; }
}

async function editProfileName(cur) {
  const name = prompt('Отображаемое имя:', cur);
  if (name===null) return;
  try {
    await api('/api/account/profile', { method:'POST', body: JSON.stringify({ display_name: name.trim() }) });
    showToast('Имя обновлено', 'success');
    openProfileModal();
  } catch(e) { showToast(e.message, 'error'); }
}

async function loadUserList() {
  try {
    const d = await api('/api/account/list');
    if (!d.ok) return;
    const el = document.getElementById('userList'); if (!el) return;
    const roleOpts = ['user','ga','moderator','admin'];
    const roleNames = {superadmin:'Гл. администратор',admin:'Администратор',moderator:'Модератор',ga:'GA',user:'Пользователь'};
    let h = '';
    for (const u of d.accounts) {
      if (u.role === 'superadmin') continue;
      h += `<div class="user-row"><div class="user-row-info"><div class="user-row-name">${u.display_name||u.username}</div><div class="user-row-meta">${u.username} · ${u.created_at||''}</div></div><div class="user-row-actions" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">`;
      h += `<select class="input" style="font-size:0.7rem;padding:2px 4px;width:110px" onchange="changeUserRole(${u.id},this.value)">`;
      for (const r of roleOpts) {
        h += `<option value="${r}"${u.role===r?' selected':''}>${roleNames[r]}</option>`;
      }
      h += `</select>`;
      if (u.can_access_panel) {
        h += `<button class="btn btn-xs btn-ghost" onclick="approveUser(${u.id},false)" style="color:var(--danger)">Блок</button>`;
      } else {
        h += `<button class="btn btn-xs btn-success" onclick="approveUser(${u.id},true)">Одобрить</button>`;
      }
      h += `</div></div>`;
    }
    if (!d.accounts.filter(u=>u.role!=='superadmin').length) h += '<span class="text-muted">Нет пользователей</span>';
    el.innerHTML = h;
  } catch(e) { showToast(e.message, 'error'); }
}

async function changeUserRole(accountId, role) {
  try {
    await api('/api/account/role', { method:'POST', body: JSON.stringify({ account_id: accountId, role }) });
    showToast('Роль изменена', 'success');
    loadUserList();
  } catch(e) { showToast(e.message, 'error'); }
}

async function approveUser(accountId, grant) {
  try {
    await api('/api/account/approve', { method:'POST', body: JSON.stringify({ account_id: accountId, grant }) });
    showToast(grant?'Доступ выдан':'Доступ отозван', 'success');
    loadUserList();
  } catch(e) { showToast(e.message, 'error'); }
}

async function doAccountLogout() {
  try {
    await api('/api/account/logout', { method:'POST' });
    document.cookie = 'wp_session=;path=/;max-age=0';
    location.href = '/login';
  } catch(e) { showToast(e.message, 'error'); }
}

// ─── Auto refresh ──────────────────────────────────────────
let _ri = null;
function startAutoRefresh(iv=3000) {
  if(_ri) clearInterval(_ri);
  _ri = setInterval(() => {
    const p = document.body.dataset.page;
    if (p==='players') loadPlayers(document.getElementById('playerSearch')?.value);
    else if (p==='bans') loadBans();
    else if (p==='dashboard'||!p) loadDashboard();
    else if (p==='rooms') loadRooms();
  }, iv);
}
startAutoRefresh();

// ─── Init ──────────────────────────────────────────────────
// ─── Nav sections ───────────────────────────────────────────
function toggleNavSection(el) {
  const section = el.parentElement;
  if (!section) return;
  const isOpen = section.classList.toggle('open');
  const name = section.dataset.sectionName || section.querySelector('.nav-section-title')?.textContent?.trim() || '';
  if (name) try { localStorage.setItem('ns_' + name.replace(/\s+/g,'_'), isOpen ? '1' : '0'); } catch(e) {}
}
document.addEventListener('DOMContentLoaded', () => {
  const p = document.body.dataset.page;
  if (p==='dashboard'||!p) { loadDashboard(); loadLeaderboard(); loadWidgetCfg(); }
  else if (p==='players') { loadPlayers(); document.getElementById('playerSearch')?.addEventListener('input',e=>loadPlayers(e.target.value)); }
  else if (p==='bans') loadBans();
  else if (p==='logs') { loadHubsFilter(); loadLogs(1); }
  else if (p==='rooms') loadRooms();
  // Restore nav section states
  document.querySelectorAll('.nav-section').forEach(s => {
    const name = s.dataset.sectionName || s.querySelector('.nav-section-title')?.textContent?.trim() || '';
    if (name) {
      const saved = localStorage.getItem('ns_' + name.replace(/\s+/g,'_'));
      if (saved === '1') s.classList.add('open');
      else if (saved === '0') s.classList.remove('open');
    }
    if (s.querySelector('a.active')) s.classList.add('open');
  });
});

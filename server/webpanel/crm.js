// ─── CRM State ───────────────────────────────────────────────
let _crmPage = 1, _crmProfilePage = 1, _crmLogPage = 1;

// ─── Tab switch ──────────────────────────────────────────────
function switchTab(grp, tab) {
  document.querySelectorAll(`.tab-content[data-tab]`).forEach(el => el.classList.remove('active'));
  document.querySelectorAll(`.tab`).forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.crm-tab-page').forEach(el => el.classList.remove('active'));
  const t = document.querySelector(`.tab[data-tab="${tab}"]`);
  if (t) t.classList.add('active');
  const c = document.getElementById('crm' + tab.charAt(0).toUpperCase() + tab.slice(1));
  if (c) c.classList.add('active');
  if (tab === 'players') loadCRMPlayers();
  else if (tab === 'profiles') loadCRMProfiles();
  else if (tab === 'stats') loadCRMStats();
}

// ─── Players ─────────────────────────────────────────────────
async function loadCRMPlayers(resetPage) {
  if (resetPage) _crmPage = 1;
  const q = document.getElementById('crmPlayerSearch')?.value||'';
  const sort = document.getElementById('crmPlayerSort')?.value||'last_seen_desc';
  const profiled = document.getElementById('crmProfiledOnly')?.checked||false;
  const banned = document.getElementById('crmBannedOnly')?.checked||false;
  const body = document.getElementById('crmPlayerBody'); if (!body) return;
  body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-dim)">Загрузка...</td></tr>';
  try {
    const d = await api(`/api/crm/players?q=${encodeURIComponent(q)}&page=${_crmPage}&sort=${sort}&profiled=${profiled}&banned=${banned}`);
    if (!d.items||!d.items.length) { body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-dim)">Нет игроков</td></tr>'; return; }
    let h = '';
    for (const p of d.items) {
      const bans = p.is_hdid_banned||p.is_ip_banned;
      h += `<tr class="crm-player-row" onclick="openCRMPlayer('${p.hdid}')"><td><span class="crm-hdid">${p.hdid||'—'}</span>${bans?' <span class="crm-badge banned">БАН</span>':''}</td><td>${esc(p.last_ooc_name)||'—'}</td><td>${esc(p.last_ic_name)||'—'}</td><td>${esc(p.last_char_name)||'—'}</td><td style="font-size:0.75rem">${p.last_ip||'—'}</td><td>${p.connect_count||0}</td><td style="font-size:0.75rem">${p.last_seen_fmt||'—'}</td><td>${p.profile_count>0?'<span class="crm-badge profiled">+'+p.profile_count+'</span>':'—'}</td></tr>`;
    }
    body.innerHTML = h;
    renderPagination('crmPlayerPagination', d.page, d.pages, 'gotoCRMPlayerPage');
    document.getElementById('crmSubtitle').textContent = `Реестр игроков · ${d.total||0} всего`;
  } catch(e) { body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--danger)">'+e.message+'</td></tr>'; }
}

function gotoCRMPlayerPage(p) { _crmPage = p; loadCRMPlayers(); }
function gotoCRMProfilePage(p) { _crmProfilePage = p; loadCRMProfiles(); }

function renderPagination(containerId, page, pages, fn) {
  const el = document.getElementById(containerId); if (!el) return;
  if (pages<=1) { el.innerHTML = ''; return; }
  let h = '';
  const prev = page>1 ? ` onclick="${fn}(${page-1})"` : '';
  const next = page<pages ? ` onclick="${fn}(${page+1})"` : '';
  h += `<button class="btn btn-sm ${page<=1?'btn-ghost disabled':'btn-ghost'}"${page<=1?' disabled':prev}>← Назад</button>`;
  for (let i=Math.max(1,page-2); i<=Math.min(pages,page+2); i++) {
    h += `<button class="btn btn-sm ${i===page?'btn-primary':'btn-ghost'}" onclick="${fn}(${i})">${i}</button>`;
  }
  h += `<button class="btn btn-sm ${page>=pages?'btn-ghost disabled':'btn-ghost'}"${page>=pages?' disabled':next}>Вперёд →</button>`;
  el.innerHTML = h;
}

function esc(s) { if (!s) return ''; return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ─── Player Detail (full-page view) ──────────────────────────
let _crmCurrentHdid = null;
let _crmHeartbeat = null;
let _crmPrevTab = 'players';

function _crmStopHeartbeat() {
  if (_crmHeartbeat) { clearInterval(_crmHeartbeat); _crmHeartbeat = null; }
}

function _crmStartHeartbeat(hdid) {
  _crmStopHeartbeat();
  _crmHeartbeat = setInterval(() => {
    api('/api/crm/player/session/ping', { method:'POST', body:JSON.stringify({hdid}) }).catch(()=>{});
  }, 55000);
}

function _crmBackToPlayers() {
  _crmStopHeartbeat();
  if (_crmCurrentHdid) {
    api('/api/crm/player/session/end', { method:'POST', body:JSON.stringify({hdid:_crmCurrentHdid, reason:'back'}) }).catch(()=>{});
    _crmCurrentHdid = null;
  }
  document.querySelectorAll('.crm-tab-page').forEach(el => el.classList.remove('active'));
  switchTab('crm', 'players');
}

async function openCRMPlayer(hdid) {
  _crmPrevTab = 'players';
  const access = await api(`/api/crm/player/${hdid}/access`);
  if (!access.can_open) {
    const body = document.getElementById('crmPlayerModalBody');
    document.getElementById('crmPlayerModalTitle').textContent = 'Доступ к карточке: ' + hdid;
    body.innerHTML = '<div style="text-align:center;padding:40px">'
      + '<div style="font-size:2.4rem;margin-bottom:12px;color:var(--crm-accent)">&#9673;</div>'
      + '<p style="color:#b7abd6;margin-bottom:8px">Доступ к карточке этого игрока ограничен.</p>';
    if (access.pending_request_id) {
      body.innerHTML += '<p style="color:#ffd787;margin-top:4px">Запрос отправлен, ожидает решения суперадмина.</p>';
    } else if (!access.is_superadmin) {
      body.innerHTML += '<button class="btn btn-primary" onclick="requestCRMPlayerAccess(\''+hdid+'\')" style="margin-top:12px">Запросить доступ</button>';
    }
    body.innerHTML += '</div>';
    document.getElementById('crmPlayerModal').querySelector('.modal-close').onclick = function(){ closeModal('crmPlayerModal'); };
    openModal('crmPlayerModal');
    return;
  }
  showCRMSessionModal(hdid);
}

async function showCRMSessionModal(hdid) {
  const title = document.getElementById('crmSessionTitle');
  const body = document.getElementById('crmSessionBody');
  title.textContent = 'Карточка: ' + hdid;
  body.innerHTML = '<div style="text-align:center;padding:24px"><div class="spinner" style="margin:0 auto 12px"></div></div>';
  openModal('crmSessionModal');
  try {
    const d = await api(`/api/crm/player/${hdid}`);
    const p = d.player;
    const primaryName = p.last_ooc_name || p.last_char_name || p.hdid || '—';
    const avatarText = (primaryName.length >= 2 ? primaryName.slice(0,2) : primaryName.slice(0,1) || '?').toUpperCase();
    body.innerHTML = `
      <div style="text-align:center;padding:8px 0 16px">
        <div class="crm-session-avatar">${esc(avatarText)}</div>
        <div style="font-size:1rem;font-weight:600;color:#f6f1ff">${esc(primaryName)}</div>
        <div style="font-size:0.78rem;color:#b7abd6;margin-top:4px">${p.last_char_name ? esc(p.last_char_name) : '—'} · ${p.connect_count||0} входов</div>
        <div style="font-size:0.72rem;color:#7a6e9a;margin-top:2px">IP: ${p.last_ip||'—'} · IPID: ${p.last_ipid||'—'}</div>
      </div>
      <div style="display:flex;gap:10px;justify-content:center">
        <button class="btn btn-ghost" onclick="closeModal('crmSessionModal')" style="min-width:100px">Отмена</button>
        <button class="btn btn-primary" onclick="startCRMPlayerSession('${hdid}')" style="min-width:120px">Войти в сессию</button>
      </div>`;
  } catch(e) {
    body.innerHTML = '<div style="text-align:center;padding:24px;color:var(--danger)">Ошибка загрузки</div>';
  }
}

async function startCRMPlayerSession(hdid) {
  closeModal('crmSessionModal');
  await api('/api/crm/player/session/start', { method:'POST', body:JSON.stringify({hdid}) }).catch(()=>{});
  _crmCurrentHdid = hdid;
  _crmStartHeartbeat(hdid);
  renderCRMPlayerPage(hdid);
}

async function renderCRMPlayerPage(hdid) {
  _crmStopHeartbeat(); _crmStartHeartbeat(hdid);
  document.querySelectorAll('.crm-tab-page').forEach(el => el.classList.remove('active'));
  let pg = document.getElementById('crmPlayerPage');
  if (!pg) {
    pg = document.createElement('div');
    pg.id = 'crmPlayerPage';
    pg.className = 'crm-tab-page';
    document.querySelector('.main').appendChild(pg);
  }
  pg.classList.add('active');
  pg.innerHTML = '<div style="text-align:center;padding:40px;color:#b7abd6">Загрузка...</div>';

  let detail, p;
  try {
    detail = await api(`/api/crm/player/${hdid}`);
    p = detail.player;
  } catch (e) {
    pg.innerHTML = '<div style="text-align:center;padding:40px;color:var(--danger)">Ошибка загрузки: ' + e.message + '</div>';
    return;
  }
  let h = '';

  // Back button
  h += '<div style="margin-bottom:14px"><button class="btn btn-ghost" onclick="_crmBackToPlayers()" style="font-size:0.82rem">← Назад к списку</button></div>';

  // Hero
  const banBadge = detail.bans && detail.bans.length ? '<span class="crm-pill danger" style="margin-left:8px">'
    + detail.bans.length + ' бан</span>' : '<span class="crm-pill ok" style="margin-left:8px">Нет банов</span>';
  const primaryName = p.last_ooc_name || p.last_char_name || p.hdid || '—';
  const avatarText = (primaryName.length >= 2 ? primaryName.slice(0,2) : primaryName.slice(0,1) || '?').toUpperCase();
  h += '<div class="crm-hero" style="margin-bottom:16px">'
    + '<div style="display:flex;align-items:center;gap:16px">'
    + '<div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,#8b5cf6,#6d4cff);display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;letter-spacing:-0.04em;flex-shrink:0">' + esc(avatarText) + '</div>'
    + '<div><div class="eyebrow">' + esc(p.hdid||'') + '</div>'
    + '<h2 style="font-size:1.4rem">' + esc(primaryName) + '</h2>'
    + '<p class="muted" style="font-size:0.82rem">' + (p.last_ic_name ? esc(p.last_ic_name) + ' / ' : '') + esc(p.last_char_name||'—') + ' · ' + (p.connect_count||0) + ' входов</p></div></div>'
    + '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">' + banBadge
    + ((p.is_hdid_banned||p.is_ip_banned) ? '<span class="crm-pill danger">БАН</span>' : '')
    + '</div></div>';

  // Access rule bar (if superadmin)
  const accessInfo = await api(`/api/crm/player/${hdid}/access`);
  if (accessInfo.rule) {
    const gaOn = accessInfo.rule.requires_ga_accept;
    h += '<div class="crm-access-bar">'
      + '<span class="lbl">Доступ:</span>'
      + '<span class="crm-pill ' + (gaOn?'danger':'ok') + '">' + (gaOn?'Требуется аццепт ГА':'Свободный') + '</span>';
    if (accessInfo.is_superadmin) {
      h += '<button class="btn btn-ghost" style="font-size:0.72rem;padding:3px 10px;margin-left:auto" onclick="toggleCRMPlayerAccessRule(\'' + hdid + '\',' + (gaOn?0:1) + ')">' + (gaOn?'Снять аццепт':'Включить аццепт') + '</button>';
    }
    h += '</div>';
  }

  // Info grid
  h += '<div class="crm-info-grid" style="margin-bottom:14px">';
  const fields = [
    ['HDID', p.hdid||'—'], ['OOC', esc(p.last_ooc_name)||'—'], ['IC', esc(p.last_ic_name)||'—'],
    ['Персонаж', esc(p.last_char_name)||'—'], ['Хаб', esc(p.last_hub_name)||'—'],
    ['Последний IP', p.last_ip||'—'], ['IPID', p.last_ipid||'—'],
    ['Входов', p.connect_count], ['Неудачных', p.failed_count],
    ['Первый вход', p.first_seen||'—'], ['Последний вход', p.last_seen||'—'],
  ];
  for (const [l,v] of fields) h += '<div class="crm-info-item"><div class="lbl">' + l + '</div><div class="val">' + v + '</div></div>';
  h += '</div>';

  // Chips sections
  if (p.ooc_names && p.ooc_names.length) h += crmChips('Известные OOC имена', p.ooc_names);
  if (p.ic_names && p.ic_names.length) h += crmChips('Известные IC имена', p.ic_names);
  if (p.char_names && p.char_names.length) h += crmChips('Персонажи', p.char_names);
  if (p.ip_addresses && p.ip_addresses.length) h += crmChips('IP адреса', p.ip_addresses);

  // Bans
  if (detail.bans && detail.bans.length) {
    h += '<div class="crm-section" style="margin-top:14px"><h3>Баны</h3>';
    for (const b of detail.bans) {
      h += '<div class="crm-log-item" style="border-left:3px solid var(--danger);margin-top:8px"><div class="crm-log-time">' + (b.ban_date||'') + '</div><div>' + esc(b.reason||'—') + '</div></div>';
    }
    h += '</div>';
  }

  // Logs + Connections in two-column layout
  h += '<div class="crm-logs-conns">'
    + '<div class="crm-section"><h3>Последние логи</h3>'
    + '<div style="display:flex;gap:6px;margin:8px 0;flex-wrap:wrap">'
    + '<select id="crmLogFilter" onchange="_crmLogPage=1;loadCRMPlayerLogs(\'' + hdid + '\')" style="background:rgba(25,19,40,0.98);border:1px solid rgba(189,162,255,0.12);color:#f6f1ff;border-radius:8px;padding:4px 10px;font-size:0.78rem">'
    + '<option value="all">Все</option><option value="chat">Чат</option><option value="action">Действия</option><option value="moderation">Модерация</option></select></div>'
    + '<div id="crmPlayerLogs" style="font-size:0.82rem;color:#b7abd6">Загрузка...</div><div class="crm-pagination" id="crmLogPagination" style="margin-top:8px"></div></div>'
    + '<div class="crm-section"><h3>Подключения</h3><div id="crmPlayerConns" style="margin-top:8px;font-size:0.82rem;color:#b7abd6">Загрузка...</div></div></div>';

  pg.innerHTML = h;
  loadCRMPlayerLogs(hdid);
  loadCRMPlayerConns(hdid);
}

function crmChips(title, items) {
  return '<div class="crm-section" style="margin-top:10px"><h3>' + title + '</h3><div class="chips" style="margin-top:6px">'
    + items.map(n => '<span class="chip">' + esc(n) + '</span>').join('')
    + '</div></div>';
}

async function requestCRMPlayerAccess(hdid) {
  try {
    const d = await api(`/api/crm/player/${hdid}/access-request`, { method:'POST' });
    if (d.granted) {
      showToast('Доступ открыт', 'success');
      closeModal('crmPlayerModal');
      renderCRMPlayerPage(hdid);
    } else {
      showToast('Запрос на доступ отправлен суперадмину', 'info');
      closeModal('crmPlayerModal');
    }
  } catch(e) { showToast(e.message, 'error'); }
}

async function toggleCRMPlayerAccessRule(hdid, requiresGa) {
  try {
    await api(`/api/crm/player/${hdid}/access-rule`, { method:'POST', body:JSON.stringify({requires_ga_accept: requiresGa}) });
    showToast(requiresGa ? 'Аццепт ГА включён' : 'Аццепт ГА снят', 'success');
    renderCRMPlayerPage(hdid);
  } catch(e) { showToast(e.message, 'error'); }
}

let _crmLogHdid = null;

async function loadCRMPlayerLogs(hdid, page) {
  _crmLogHdid = hdid;
  if (page != null) _crmLogPage = page;
  const el = document.getElementById('crmPlayerLogs'); if (!el) return;
  const filter = document.getElementById('crmLogFilter')?.value||'all';
  try {
    const d = await api(`/api/crm/player/${hdid}/logs?filter=${filter}&page=${_crmLogPage}&limit=30`);
    if (!d.logs||!d.logs.length) { el.innerHTML = '<span class="text-muted">Нет логов</span>'; document.getElementById('crmLogPagination').innerHTML=''; return; }
    let h = '';
    for (const l of d.logs) {
      const tp = (l.category||'other').toLowerCase();
      const cls = tp==='chat'?'chat':tp==='action'?'action':tp==='moderation'?'mod':'system';
      h += '<div class="crm-log-item"><div class="crm-log-time">' + (l.event_time||'') + ' <span class="crm-log-type ' + cls + '">' + (l.event_type||'') + '</span></div>'
        + '<div>' + esc(l.message||'') + '</div>'
        + '<div style="font-size:0.7rem;color:#7a6e9a;margin-top:2px">' + esc(l.ooc_name||'') + (l.char_name?' / '+esc(l.char_name):'') + (l.hub_name?' · '+esc(l.hub_name):'') + '</div></div>';
    }
    el.innerHTML = h;
    const pi = document.getElementById('crmLogPagination');
    if (d.pages > 1) {
      let ph = '<span style="font-size:0.72rem;color:#7a6e9a;margin-right:8px">' + d.total + ' записей</span>';
      if (_crmLogPage > 1) ph += `<button class="btn btn-sm btn-ghost" onclick="loadCRMPlayerLogs('${hdid}', ${_crmLogPage-1})">←</button>`;
      ph += '<span style="font-size:0.72rem;color:#b7abd6;margin:0 6px">' + _crmLogPage + '/' + d.pages + '</span>';
      if (_crmLogPage < d.pages) ph += `<button class="btn btn-sm btn-ghost" onclick="loadCRMPlayerLogs('${hdid}', ${_crmLogPage+1})">→</button>`;
      pi.innerHTML = ph;
    } else { pi.innerHTML = ''; }
  } catch(e) { el.innerHTML = '<span style="color:#b7abd6">Ошибка</span>'; }
}

async function loadCRMPlayerConns(hdid) {
  const el = document.getElementById('crmPlayerConns'); if (!el) return;
  try {
    const d = await api(`/api/crm/player/${hdid}/connections?limit=20`);
    if (!d.connections||!d.connections.length) { el.innerHTML = '<span style="color:#b7abd6">Нет подключений</span>'; return; }
    let h = '';
    for (const c of d.connections) {
      h += '<div style="padding:8px 10px;border-radius:10px;background:rgba(255,255,255,0.025);margin-bottom:4px;display:flex;justify-content:space-between;align-items:center;font-size:0.78rem">'
        + '<span style="color:#b7abd6;font-size:0.7rem">' + (c.event_time||'') + '</span>'
        + '<span>' + (c.ipid||'') + '</span>'
        + '<span>' + (c.failed?'<span class="crm-pill danger">FAIL</span>':'<span class="crm-pill ok">OK</span>') + '</span></div>';
    }
    el.innerHTML = h;
  } catch(e) { el.innerHTML = '<span style="color:#b7abd6">Ошибка</span>'; }
}

// ─── Profiles ────────────────────────────────────────────────
async function loadCRMProfiles(resetPage) {
  if (resetPage) _crmProfilePage = 1;
  const q = document.getElementById('crmProfileSearch')?.value||'';
  const sort = document.getElementById('crmProfileSort')?.value||'updated_desc';
  const grid = document.getElementById('crmProfileGrid'); if (!grid) return;
  grid.innerHTML = '<div class="text-muted" style="text-align:center;padding:24px">Загрузка...</div>';
  try {
    const d = await api(`/api/crm/profiles?q=${encodeURIComponent(q)}&page=${_crmProfilePage}&sort=${sort}`);
    if (!d.items||!d.items.length) { grid.innerHTML = '<div class="text-muted" style="text-align:center;padding:24px">Нет профилей</div>'; return; }
      grid.innerHTML = d.items.map(p => `<div class="crm-profile-card" onclick="openCRMProfileForm(${p.id})">
      <h4>${esc(p.title)||'Новый профиль'}</h4>
      <div class="meta">${p.hdid_count||0} HDID${p.ooc_name?' / '+esc(p.ooc_name):''}${p.discord?' / '+esc(p.discord):''}</div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">${p.status?'<span class="crm-pill info">'+esc(p.status)+'</span>':''}${p.risk_level==='high'?'<span class="crm-pill danger">high</span>':p.risk_level==='low'?'<span class="crm-pill ok">low</span>':''}</div>
    </div>`).join('');
    renderPagination('crmProfilePagination', d.page, d.pages, 'gotoCRMProfilePage');
  } catch(e) { grid.innerHTML = '<div class="text-muted" style="text-align:center;padding:24px;color:var(--danger)">'+e.message+'</div>'; }
}

async function openCRMProfileForm(id) {
  const body = document.getElementById('crmProfileFormBody');
  document.getElementById('crmProfileFormTitle').textContent = id ? 'Редактировать профиль' : 'Новый профиль';
  body.innerHTML = '<div class="text-muted" style="text-align:center;padding:24px">Загрузка...</div>';
  openModal('crmProfileFormModal');
  let p = { title:'', ooc_name:'', discord:'', status:'new', risk_level:'medium', tags:'', notes:'', hdids:'' };
  let hdidsList = [];
  if (id) {
    try {
      const d = await api(`/api/crm/profile/${id}`);
      p = d.profile||p; hdidsList = d.hdids||[];
    } catch(e) { body.innerHTML = '<div class="text-muted">Ошибка</div>'; return; }
  }
  body.innerHTML = `<div class="form-row"><div class="form-group"><label>Название</label><input type="text" id="cpfTitle" value="${esc(p.title||'')}"></div><div class="form-group"><label>OOC имя</label><input type="text" id="cpfOoc" value="${esc(p.ooc_name||'')}"></div></div>
  <div class="form-row"><div class="form-group"><label>Discord</label><input type="text" id="cpfDiscord" value="${esc(p.discord||'')}"></div><div class="form-group"><label>Статус</label><select id="cpfStatus"><option value="new"${p.status==='new'?' selected':''}>Новый</option><option value="active"${p.status==='active'?' selected':''}>Активный</option><option value="limited"${p.status==='limited'?' selected':''}>Ограничен</option><option value="blacklisted"${p.status==='blacklisted'?' selected':''}>Чёрный список</option></select></div></div>
  <div class="form-row"><div class="form-group"><label>Уровень риска</label><select id="cpfRisk"><option value="low"${p.risk_level==='low'?' selected':''}>Низкий</option><option value="medium"${p.risk_level==='medium'?' selected':''}>Средний</option><option value="high"${p.risk_level==='high'?' selected':''}>Высокий</option></select></div><div class="form-group"><label>Теги (через запятую)</label><input type="text" id="cpfTags" value="${esc(p.tags||'')}"></div></div>
  <div class="form-group"><label>HDID (каждый с новой строки)</label><textarea id="cpfHdids" rows="3">${esc(hdidsList.map(h=>h.hdid).join('\n'))}</textarea></div>
  <div class="form-group"><label>Заметки</label><textarea id="cpfNotes" rows="4">${esc(p.notes||'')}</textarea></div>
  <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">${id?`<button class="btn btn-danger btn-sm" onclick="deleteCRMProfile(${id})">Удалить</button>`:''}<button class="btn btn-primary btn-sm" onclick="saveCRMProfile(${id||0})">Сохранить</button></div>`;
}

async function saveCRMProfile(id) {
  const body = {
    id: id||undefined, title: document.getElementById('cpfTitle').value,
    ooc_name: document.getElementById('cpfOoc').value, discord: document.getElementById('cpfDiscord').value,
    status: document.getElementById('cpfStatus').value, risk_level: document.getElementById('cpfRisk').value,
    tags: document.getElementById('cpfTags').value, notes: document.getElementById('cpfNotes').value,
    hdids: document.getElementById('cpfHdids').value,
  };
  try {
    const d = await api('/api/crm/profile/save', { method:'POST', body:JSON.stringify(body) });
    showToast('Сохранено', 'success'); closeModal('crmProfileFormModal'); loadCRMProfiles(true);
  } catch(e) { showToast(e.message, 'error'); }
}

async function deleteCRMProfile(id) {
  if (!confirm('Удалить профиль?')) return;
  try { await api('/api/crm/profile/delete', { method:'POST', body:JSON.stringify({id}) }); showToast('Удалено','success'); closeModal('crmProfileFormModal'); loadCRMProfiles(true); }
  catch(e) { showToast(e.message,'error'); }
}

// ─── Stats ───────────────────────────────────────────────────
async function loadCRMStats() {
  const grid = document.getElementById('crmStatGrid');
  const syncBody = document.getElementById('crmSyncBody');
  if (!grid) return;
  try {
    const d = await api('/api/crm/stats');
    if (!d.ok) { grid.innerHTML = '<div class="text-muted" style="grid-column:1/-1;text-align:center;padding:24px">'+d.error+'</div>'; return; }
    const syncStatus = d.sync.status==='running'?'Выполняется':d.sync.status==='ok'?'Готово':d.sync.status;
    grid.innerHTML = `
      <div class="crm-metric-card"><span>Игроков в кэше</span><strong>${d.stats.players}</strong></div>
      <div class="crm-metric-card"><span>Статус синхронизации</span><strong style="font-size:1rem">${syncStatus}</strong></div>
    `;
    if (syncBody) {
      syncBody.innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px">
          <div style="padding:10px 14px;border-radius:var(--crm-radius-md);background:rgba(25,19,40,0.98);border:1px solid rgba(189,162,255,0.12)">
            <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.06em;color:#b7abd6">Режим</div>
            <strong style="font-size:0.9rem">${d.sync.mode||'—'}</strong>
          </div>
          <div style="padding:10px 14px;border-radius:var(--crm-radius-md);background:rgba(25,19,40,0.98);border:1px solid rgba(189,162,255,0.12)">
            <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.06em;color:#b7abd6">Сообщение</div>
            <strong style="font-size:0.9rem">${d.sync.message||'—'}</strong>
          </div>
          <div style="padding:10px 14px;border-radius:var(--crm-radius-md);background:rgba(25,19,40,0.98);border:1px solid rgba(189,162,255,0.12)">
            <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.06em;color:#b7abd6">Запущена</div>
            <strong style="font-size:0.9rem">${d.sync.started_at||'—'}</strong>
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-sm btn-primary" onclick="triggerCRMSync(false)">Инкрементальная</button>
          <button class="btn btn-sm btn-danger" onclick="triggerCRMSync(true)">Полная пересборка</button>
        </div>
      `;
    }
  } catch(e) { grid.innerHTML = '<div class="text-muted" style="grid-column:1/-1;text-align:center;padding:24px;color:var(--danger)">'+e.message+'</div>'; }
}

async function triggerCRMSync(force) {
  try { const d = await api('/api/crm/sync', { method:'POST', body:JSON.stringify({force}) }); showToast(d.ok?'Синхронизация запущена':'Уже выполняется','info'); loadCRMStats(); }
  catch(e) { showToast(e.message,'error'); }
}

// ─── Init ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  if (document.body.dataset.page === 'crm') {
    loadCRMPlayers();
    setTimeout(loadCRMStats, 500);
  }
});

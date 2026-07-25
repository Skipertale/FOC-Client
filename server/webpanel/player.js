let ws = null;
const icLog = document.getElementById('icLog');
const oocLog = document.getElementById('oocLog');
const icInput = document.getElementById('icInput');
const oocInput = document.getElementById('oocInput');
const emoteInput = document.getElementById('emoteInput');
const emoteFolder = document.getElementById('emoteFolder');
const emoteGrid = document.getElementById('emoteGrid');
const charImg = document.getElementById('charImg');
const typingIndicator = document.getElementById('typingIndicator');
const typingText = document.getElementById('typingText');
const charName = document.getElementById('charName');
const areaName = document.getElementById('areaName');
const areaLabel = document.getElementById('areaLabel');
const playerCount = document.getElementById('playerCount');
const connectStatus = document.getElementById('connectStatus');
const bgDisplay = document.getElementById('bgDisplay');
const charSelect = document.getElementById('charSelect');
const areaSelect = document.getElementById('areaSelect');
const iniswapInput = document.getElementById('iniswapInput');
const oocNameInput = document.getElementById('oocNameInput');
const icNameInput = document.getElementById('icNameInput');
const clientTabs = document.getElementById('clientTabs');
const currentEmoteLabel = document.getElementById('currentEmoteLabel');

let clients = {};         // client_id -> {char_id, iniswap, oocname, icname, name, hub_id, area_id, importedChars:{}, activeImported:null, narrate, msgQueue}
let _pendingRestores = [];
let _pendingRestoreMap = {};  // client_id -> full restore state {iniswap, oocname, icname, ...}
let _pendingCharMap = {};  // client_id -> char_id to select after chars received
let _emotePoseMap = {};    // folder -> {emote_id -> pose_name} (from server char_subfolders)
let _charEmoteNames = {};  // folder -> [{name, index}, ...] from char.ini [Emotions]
let typingTimers = {};    // name -> timeout id
let typingState = false;  // am I currently typing?
let typingStopTimer = null;
let typingLastSend = 0;   // timestamp of last TYPING:1 sent
let globalQueue = [];
let queueRunner = {active: false, timer: null, type: null}; // 'client' or 'global'
let activeId = null;
let assetUrl = '';
let chars = [];
let defaultEmotes = ['normal','happy','sad','angry','surprise','blush','confident','desk slam','objection','hold it','take that','cornered','thinking','sleepy','cry','gura','pout','nervous','shrug','talk','side','zen','wink','point','sweat'];
let emotes = defaultEmotes.slice();
let _dbChars = {}; // IndexedDB-loaded chars (DataURL-based)

// ─── Duplicate message prevention ──────────────────────────
const _recentMsgs = new Map();
const DEDUP_MS = 3000;
function _isDuplicate(type, name, text) {
  const key = type + '|' + name + '|' + text;
  const now = Date.now();
  for (const [k, t] of _recentMsgs) { if (now - t > DEDUP_MS) _recentMsgs.delete(k); }
  if (_recentMsgs.has(key)) return true;
  _recentMsgs.set(key, now);
  return false;
}

// ─── Window Manager (cyberpunk floating windows) ──────────────────
const winManager = new (class WindowManager {
  constructor() {
    this.windows = {};
    this._zIndex = 100;
    this._activeId = null;
    this._drag = null;
    this._resize = null;
    this._boundMove = this._onDrag.bind(this);
    this._boundUp = this._endDrag.bind(this);
    this._boundResize = this._onResize.bind(this);
    this._boundResizeUp = this._endResize.bind(this);
    this._init();
  }
  _init() {
    document.querySelectorAll('.window').forEach(el => this._setup(el));
  }
  _setup(el) {
    const id = el.id;
    if (!id) return;
    el.style.left = el.dataset.defx + 'px';
    el.style.top = el.dataset.defy + 'px';
    if (el.dataset.defw) el.style.width = el.dataset.defw + 'px';
    if (el.dataset.defh) el.style.height = el.dataset.defh + 'px';
    if (el.hidden) { el.classList.add('hidden-win'); el.hidden = false; }
    this.windows[id] = { el, minimized: false };
    const bar = el.querySelector('.win-bar');
    if (bar) bar.addEventListener('mousedown', e => this._startDrag(id, e));
    el.addEventListener('mousedown', () => this._focus(id));
    el.querySelectorAll('.win-dot').forEach(dot => {
      const act = dot.dataset.action;
      if (act === 'close') dot.addEventListener('click', e => { e.stopPropagation(); if (id === 'win-queue') closeQueueModal(); else this._close(id); });
      if (act === 'minimize') dot.addEventListener('click', e => { e.stopPropagation(); this._minimize(id); });
    });
    // Resize handles
    const dirs = ['n','s','e','w','ne','nw','se','sw'];
    dirs.forEach(dir => {
      const h = document.createElement('div');
      h.className = 'win-handle win-handle-' + dir;
      h.addEventListener('mousedown', e => { e.stopPropagation(); this._startResize(id, dir, e); });
      el.appendChild(h);
    });
    // first visible window becomes active
    if (!this._activeId && !el.classList.contains('hidden-win')) this._focus(id);
  }
  _focus(id) {
    const w = this.windows[id];
    if (!w || w.minimized) return;
    this._zIndex++;
    w.el.style.zIndex = this._zIndex;
    w.el.classList.add('active');
    if (this._activeId && this.windows[this._activeId]) {
      this.windows[this._activeId].el.classList.remove('active');
    }
    this._activeId = id;
    this._syncTaskbar();
  }
  _syncTaskbar() {
    document.querySelectorAll('#win-taskbar .tb-btn').forEach(function(btn) {
      var winId = btn.dataset.win;
      var w = this.windows[winId];
      if (!w) return;
      var isActive = winId === this._activeId && !w.minimized && !w.el.classList.contains('hidden-win');
      btn.classList.toggle('active', isActive);
      btn.classList.toggle('minimized-win', w.minimized || w.el.classList.contains('hidden-win'));
    }, this);
  }
  // ─── Drag logic ───
  _startDrag(id, e) {
    if (e.target.closest('.win-dot') || e.target.closest('button') || e.target.closest('select') || e.target.closest('input') || e.target.closest('textarea') || e.target.closest('.win-handle')) return;
    this._focus(id);
    const el = this.windows[id].el;
    const rect = el.getBoundingClientRect();
    this._drag = { id, ox: e.clientX - rect.left, oy: e.clientY - rect.top };
    document.addEventListener('mousemove', this._boundMove);
    document.addEventListener('mouseup', this._boundUp);
    e.preventDefault();
  }
  _onDrag(e) {
    if (!this._drag) return;
    const el = this.windows[this._drag.id].el;
    const maxW = window.innerWidth - 40;
    el.style.left = Math.min(Math.max(0, e.clientX - this._drag.ox), maxW) + 'px';
    el.style.top = Math.max(0, e.clientY - this._drag.oy) + 'px';
  }
  _endDrag() {
    this._drag = null;
    document.removeEventListener('mousemove', this._boundMove);
    document.removeEventListener('mouseup', this._boundUp);
  }
  // ─── Resize logic ───
  _startResize(id, dir, e) {
    e.preventDefault();
    this._focus(id);
    const el = this.windows[id].el;
    const r = el.getBoundingClientRect();
    this._resize = { id, dir, sx: e.clientX, sy: e.clientY, sw: r.width, sh: r.height, sl: r.left, st: r.top };
    document.addEventListener('mousemove', this._boundResize);
    document.addEventListener('mouseup', this._boundResizeUp);
  }
  _onResize(e) {
    if (!this._resize) return;
    const { id, dir, sx, sy, sw, sh, sl, st } = this._resize;
    const el = this.windows[id].el;
    const dx = e.clientX - sx, dy = e.clientY - sy;
    let newL = sl, newT = st, newW = sw, newH = sh;
    const MIN_W = 200, MIN_H = 80;
    // Horizontal
    if (dir.includes('e')) { newW = Math.max(MIN_W, sw + dx); }
    if (dir.includes('w')) { const diff = Math.min(dx, sw - MIN_W); newL = sl + diff; newW = sw - diff; }
    // Vertical
    if (dir.includes('s')) { newH = Math.max(MIN_H, sh + dy); }
    if (dir.includes('n')) { const diff = Math.min(dy, sh - MIN_H); newT = st + diff; newH = sh - diff; }
    el.style.left = newL + 'px'; el.style.top = newT + 'px';
    el.style.width = newW + 'px'; el.style.height = newH + 'px';
  }
  _endResize() {
    this._resize = null;
    document.removeEventListener('mousemove', this._boundResize);
    document.removeEventListener('mouseup', this._boundResizeUp);
  }
  // ─── Minimize / Close / Show / Toggle ───
  _minimize(id) {
    const w = this.windows[id];
    if (!w) return;
    w.minimized = !w.minimized;
    const body = w.el.querySelector('.win-body');
    if (w.minimized) {
      w.el.classList.add('minimized');
      if (body) body.style.display = 'none';
      for (const [k, v] of Object.entries(this.windows)) {
        if (k !== id && !v.el.classList.contains('hidden-win') && !v.minimized) {
          this._focus(k);
          return;
        }
      }
      if (this._activeId === id) { this._activeId = null; w.el.classList.remove('active'); }
    } else {
      w.el.classList.remove('minimized');
      if (body) body.style.display = '';
      this._focus(id);
    }
    this._syncTaskbar();
  }
  _close(id) {
    const w = this.windows[id];
    if (!w) return;
    w.el.classList.add('hidden-win');
    if (w.minimized) { w.minimized = false; w.el.classList.remove('minimized'); }
    w.hidden = true;
    this._syncTaskbar();
  }
  show(id) {
    const w = this.windows[id];
    if (!w) return;
    w.el.classList.remove('hidden-win');
    w.hidden = false;
    if (w.minimized) { w.minimized = false; w.el.classList.remove('minimized'); const b = w.el.querySelector('.win-body'); if (b) b.style.display = ''; }
    w.el.style.opacity = '0';
    w.el.style.transform = 'scale(0.96) translateY(8px)';
    w.el.style.transition = 'opacity 0.15s ease, transform 0.15s ease';
    requestAnimationFrame(() => {
      w.el.style.opacity = '1';
      w.el.style.transform = '';
      setTimeout(() => { w.el.style.transition = ''; }, 200);
    });
    this._focus(id);
  }
  hide(id) { this._close(id); }
  toggle(id) {
    const w = this.windows[id];
    if (!w) return;
    if (w.el.classList.contains('hidden-win') || w.minimized) {
      this.show(id);
    } else {
      this._minimize(id);
    }
  }
})();

// ─── IndexedDB persistence for imported chars ──────────────────────────
function openCharDB() {
  return new Promise(function(resolve, reject) {
    var req = indexedDB.open('foccc_chars', 1);
    req.onupgradeneeded = function(e) {
      var db = e.target.result;
      if (!db.objectStoreNames.contains('chars')) db.createObjectStore('chars', {keyPath: 'dirName'});
    };
    req.onsuccess = function(e) { resolve(e.target.result); };
    req.onerror = function(e) { reject(e.target.error); };
  });
}
function saveCharToDB(charData) {
  return openCharDB().then(function(db) {
    return new Promise(function(resolve, reject) {
      var tx = db.transaction('chars', 'readwrite');
      tx.objectStore('chars').put(charData);
      tx.oncomplete = function() { resolve(); };
      tx.onerror = function(e) { reject(e.target.error); };
    });
  });
}
function loadAllCharsFromDB() {
  return openCharDB().then(function(db) {
    return new Promise(function(resolve, reject) {
      var tx = db.transaction('chars', 'readonly');
      var req = tx.objectStore('chars').getAll();
      req.onsuccess = function() { resolve(req.result || []); };
      req.onerror = function(e) { reject(e.target.error); };
    });
  });
}
function deleteCharFromDB(dirName) {
  return openCharDB().then(function(db) {
    return new Promise(function(resolve, reject) {
      var tx = db.transaction('chars', 'readwrite');
      tx.objectStore('chars').delete(dirName);
      tx.oncomplete = function() { resolve(); };
      tx.onerror = function(e) { reject(e.target.error); };
    });
  });
}

function connect() {
  var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
  ws = new WebSocket(proto + '//' + location.host + '/ws/player');
  ws.onopen = function() {
    connectStatus.textContent = 'Подключено';
    connectStatus.className = 'status-ok';
    var sd = document.getElementById('statusDot');
    if (sd) sd.className = 'status-dot on';
    addOOC('Система', 'Подключено к серверу');
    requestAreaList();
    requestClientList();
    requestSfxList();
    loadAllCharsFromDB().then(function(entries) {
      entries.forEach(function(cd) { _dbChars[cd.dirName] = cd; });
    }).catch(function(e) { console.error('DB load error', e); });
  };
  ws.onclose = function() {
    connectStatus.textContent = 'Отключено';
    connectStatus.className = 'status-err';
    var sd = document.getElementById('statusDot');
    if (sd) sd.className = 'status-dot off';
    addOOC('Система', 'Соединение разорвано');
    setTimeout(connect, 3000);
  };
  ws.onerror = function(e) { console.error('WebSocket error', e); };
  ws.onmessage = function(e) {
    try {
      handleEvent(JSON.parse(e.data));
    } catch(ex) { console.error('handleEvent error', ex, e.data); }
  };
}

function handleEvent(d) {
  switch (d.type) {
    case 'ready':
      break;
    case 'ic':
      if (!_isDuplicate('ic', d.name, d.text)) addIC(d.name, d.text, d.char_id);
      break;
    case 'ooc':
      if (!_isDuplicate('ooc', d.name, d.text)) addOOC(d.name, d.text);
      break;
    case 'chars':
      chars = d.list || [];
      updateCharSelect(chars);
      {
        var pcid = d.client_id;
        var didSelect = false;
        if (pcid !== undefined && _pendingCharMap[pcid] != null) {
          if (_pendingCharMap[pcid] < chars.length) {
            selectChar(_pendingCharMap[pcid], pcid);
            didSelect = true;
          }
          delete _pendingCharMap[pcid];
        }
        // apply restore only if no char was selected (char_select will handle it otherwise)
        if (!didSelect && pcid !== undefined && _pendingRestoreMap[pcid]) {
          applyPendingRestore(pcid);
        }
      }
      break;
    case 'chars_status':
      updateCharStatus(d.list || []);
      break;
    case 'char_select':
      var targetId = d.client_id !== undefined ? d.client_id : activeId;
      if (targetId !== null && clients[targetId]) clients[targetId].char_id = d.char_id;
      if (targetId === activeId) charSelect.value = String(d.char_id);
      // Handle pending char selection (from preset restore), but don't break — let restore logic also run
      if (_pendingCharMap[targetId] != null) {
        if (_pendingCharMap[targetId] < chars.length) {
          selectChar(_pendingCharMap[targetId], targetId);
        }
        delete _pendingCharMap[targetId];
      }
      // auto-fill INI-swap only if no pending restore
      if (!_pendingRestoreMap[targetId] && d.char_id >= 0 && d.char_id < chars.length) {
        var chName = chars[d.char_id].name;
        if (targetId !== null && clients[targetId]) {
          clients[targetId].iniswap = chName;
          if (targetId === activeId) {
            charName.textContent = chName;
            iniswapInput.value = chName;
            applyIniswap(chName);
            updateSprite();
          }
        }
      } else if (!_pendingRestoreMap[targetId] && targetId === activeId) {
        charName.textContent = '—';
        charImg.src = '';
      }
      // Apply pending restore (sets iniswap/ooc/ic from preset, overwriting any auto-fill)
      if (_pendingRestoreMap[targetId]) applyPendingRestore(targetId);
      break;
    case 'char_subfolders':
      {
        var ps = document.getElementById('poseSelect');
        if (!ps) break;
        // store emote→pose map for this folder
        var name = d.name || '';
        _emotePoseMap[name] = {};
        var emap = d.emote_map || {};
        Object.keys(emap).forEach(function(pose) {
          (emap[pose] || []).forEach(function(eid) {
            _emotePoseMap[name][eid] = pose;
          });
        });
        // store char.ini emote names for this folder
        if (d.emote_names && d.emote_names.length > 0) {
          _charEmoteNames[name] = d.emote_names;
        } else {
          delete _charEmoteNames[name];
        }
        // populate pose dropdown
        ps.innerHTML = '<option value="">—</option>';
        (d.list || []).forEach(function(s) {
          var o = document.createElement('option');
          o.value = s; o.textContent = s;
          ps.appendChild(o);
        });
        ps.style.display = d.list && d.list.length > 0 ? '' : 'none';
        // update emote grid with character-specific names
        rebuildEmoteList(name);
        renderEmoteGrid();
      }
      break;
    case 'bg':
      if (d.background) bgDisplay.style.backgroundImage = 'url(' + d.background + ')';
      break;
    case 'asset_url':
      assetUrl = d.url || '';
      updateSprite();
      break;
    case 'area_list_full':
      updateAreaSelect(d.hubs || []);
      break;
    case 'area_info':
      areaName.textContent = d.area_name || '—';
      areaLabel.textContent = (d.hub_name ? d.hub_name + ' / ' : '') + (d.area_name || '—');
      playerCount.textContent = d.player_count != null ? d.player_count + ' игроков' : '';
      var tb = document.getElementById('tbAreaLabel');
      if (tb) tb.textContent = d.area_name || '';
      if (d.background) bgDisplay.style.backgroundImage = 'url(' + d.background + ')';
      renderBookmarks();
      break;
    case 'music':
      playChannelAudio(d.channel || 0, d.song);
      break;
    case 'error':
      addOOC('Ошибка', d.msg || 'Неизвестная ошибка');
      break;
    case 'id':
      addOOC('Система', 'ID клиента: ' + d.client_id);
      break;
    case 'client_added':
      clients[d.client_id] = {char_id: -1, iniswap: '', oocname: '', icname: '', name: d.name, hub_id: null, area_id: null, importedChars: {}, activeImported: null, icMessages: [], oocMessages: [], narrate: false, msgQueue: []};
      if (activeId === null) activeId = d.client_id;
      renderTabs();
      if (_pendingRestores.length > 0) {
        var st = _pendingRestores.shift();
        if (st) {
          var restoreId = d.client_id;
          setActive(restoreId);
          // Optimistic restore: apply everything immediately, no waiting for server events
          // 1) area + char commands (auto-fill is skipped because _pendingRestoreMap exists)
          _pendingRestoreMap[restoreId] = st;
          if (st.hub_id != null && st.area_id != null) {
            selectArea(st.hub_id + ':' + st.area_id, restoreId);
          }
          if (st.char_id >= 0) {
            _pendingCharMap[restoreId] = st.char_id;
            selectChar(st.char_id, restoreId);
          }
          // 3) apply saved iniswap/oocname/icname immediately
          if (st.iniswap) {
            if (clients[restoreId]) clients[restoreId].iniswap = st.iniswap;
            if (restoreId === activeId) {
              iniswapInput.value = st.iniswap;
              applyIniswap(st.iniswap);
              updateSprite();
            }
            send({type:'INISWAP', name:st.iniswap, client_id:restoreId});
            fetchCharSubfolders(st.iniswap);
          }
          if (st.oocname) {
            if (clients[restoreId]) clients[restoreId].oocname = st.oocname;
            if (restoreId === activeId) oocNameInput.value = st.oocname;
            send({type:'OOC_NAME', name:st.oocname, client_id:restoreId});
          }
          if (st.icname) {
            if (clients[restoreId]) clients[restoreId].icname = st.icname;
            if (restoreId === activeId) icNameInput.value = st.icname;
            send({type:'IC_NAME', name:st.icname, client_id:restoreId});
            renderTabs();
          }
          // 4) if no area and no char — nothing to wait for server-side, release flag immediately
          if (!(st.hub_id != null && st.area_id != null) && !(st.char_id >= 0)) {
            delete _pendingRestoreMap[restoreId];
          }
          // else: keep _pendingRestoreMap alive until chars/char_select clears it
          // so auto-fill in selectChar/char_select is skipped
        }
      }
      renderQuickSwap();
      break;
    case 'client_removed':
      var old = clients[d.client_id];
      if (old && old.importedChars) {
        Object.keys(old.importedChars).forEach(function(dn) {
          var imp = old.importedChars[dn];
          Object.keys(imp.sprites).forEach(function(k) { URL.revokeObjectURL(imp.sprites[k]); });
          Object.keys(imp.buttons).forEach(function(k) { URL.revokeObjectURL(imp.buttons[k]); });
        });
      }
      delete clients[d.client_id];
      delete _pendingCharMap[d.client_id];
      delete _pendingRestoreMap[d.client_id];
      if (activeId === d.client_id) {
        var keys = Object.keys(clients);
        activeId = keys.length > 0 ? parseInt(keys[0]) : null;
      }
      renderTabs();
      break;
    case 'sfx_list':
      var sfxEl = document.getElementById('sfxList');
      if (!sfxEl) break;
      sfxEl.innerHTML = '';
      (d.files || []).slice(0, 20).forEach(function(f) {
        var item = document.createElement('span');
        item.textContent = ' ' + f;
        item.style.cssText = 'cursor:pointer;color:#888;margin-right:6px;';
        item.onclick = function() {
          document.getElementById('sfxInput').value = f.replace(/\.[^.]+$/, '');
          setSfx();
        };
        sfxEl.appendChild(item);
      });
      break;

    case 'client_list':
      // revoke all old object URLs
      Object.keys(clients).forEach(function(id) {
        var old = clients[id];
        if (old && old.importedChars) {
          Object.keys(old.importedChars).forEach(function(dn) {
            var imp = old.importedChars[dn];
            Object.keys(imp.sprites).forEach(function(k) { URL.revokeObjectURL(imp.sprites[k]); });
            Object.keys(imp.buttons).forEach(function(k) { URL.revokeObjectURL(imp.buttons[k]); });
          });
        }
      });
      clients = {};
      d.clients.forEach(function(c) {
        clients[c.client_id] = {char_id: c.char_id, iniswap: c.iniswap || '', oocname: c.oocname || '', icname: c.icname || '', name: c.name || ('Клиент ' + c.client_id), hub_id: c.hub_id, area_id: c.area_id, importedChars: {}, activeImported: null, icMessages: [], oocMessages: [], narrate: false, msgQueue: []};
      });
      activeId = d.active_id;
      // Restore saved clients state after page reload (apply BEFORE loadActiveClientState)
      var saved = parseInt(localStorage.getItem('player_client_count') || '0');
      var savedStates;
      try { savedStates = JSON.parse(localStorage.getItem('player_client_states') || '[]'); } catch(e) { savedStates = []; }
      if (saved > 0 && savedStates.length > 0) {
        var current = Object.keys(clients).length;
        var need = Math.max(0, saved - current);
        if (need > 0) {
          _pendingRestores = savedStates.slice(-need);
          for (var i = 0; i < need; i++) {
            connectClient();
          }
        }
        // apply remaining states to existing clients (matched by sorted order)
        var applyCount = Math.min(current, savedStates.length - need);
        var ids = Object.keys(clients).map(Number).sort(function(a,b){return a-b;});
        for (var i = 0; i < applyCount; i++) {
          var st = savedStates[i];
          var id = ids[i];
          if (!st || id == null) continue;
          if (clients[id]) {
            if (st.iniswap) clients[id].iniswap = st.iniswap;
            if (st.oocname) clients[id].oocname = st.oocname;
            if (st.icname) clients[id].icname = st.icname;
          }
          if (st.iniswap) {
            send({type:'INISWAP', name:st.iniswap, client_id:id});
            fetchCharSubfolders(st.iniswap);
          }
          if (st.oocname) send({type:'OOC_NAME', name:st.oocname, client_id:id});
          if (st.icname) send({type:'IC_NAME', name:st.icname, client_id:id});
        }
      }
      renderTabs();
      loadActiveClientState();
      renderQuickSwap();
      break;
    case 'active_changed':
      activeId = d.client_id;
      renderTabs();
      loadActiveClientState();
      break;
    case 'iniswap_set':
      if (clients[d.client_id]) clients[d.client_id].iniswap = d.name;
      break;
    case 'typing':
      if (d.state === 1) {
        if (typingTimers[d.name]) clearTimeout(typingTimers[d.name]);
        typingTimers[d.name] = setTimeout(function() {
          delete typingTimers[d.name];
          updateTypingIndicator();
        }, 4000);
      } else {
        if (typingTimers[d.name]) {
          clearTimeout(typingTimers[d.name]);
          delete typingTimers[d.name];
        }
      }
      updateTypingIndicator();
      break;
    case 'session_logs':
      _sessionLogsCache = d.messages || [];
      renderSessionLogs();
      break;
  }
}

function updateNarrateBtn() {
  var c = activeId !== null ? clients[activeId] : null;
  var btn = document.getElementById('narrateBtn');
  if (!btn) return;
  var on = c ? c.narrate : false;
  btn.textContent = 'Narrate: ' + (on ? 'ON' : 'OFF');
  btn.className = 'tool-btn' + (on ? ' active' : '');
}

function toggleNarrate() {
  var c = activeId !== null ? clients[activeId] : null;
  if (!c) return;
  c.narrate = !c.narrate;
  updateNarrateBtn();
  send({type:'OOC', text:'/narrate', client_id:activeId});
}

function loadActiveClientState() {
  var c = activeId !== null ? clients[activeId] : null;
  if (!c) return;
  iniswapInput.value = c.iniswap || '';
  oocNameInput.value = c.oocname || '';
  icNameInput.value = c.icname || '';
  charSelect.value = String(c.char_id != null ? c.char_id : -1);
  if (c.char_id >= 0 && c.char_id < chars.length) {
    charName.textContent = chars[c.char_id].name;
  } else {
    charName.textContent = '—';
  }
  // swap imported char for this client
  var imp = (c.importedChars && c.activeImported && c.importedChars[c.activeImported]) || null;
  applyImportedChar(imp);
  renderClientLog();
  updateNarrateBtn();
  renderQuickSwap();
  updateSprite();
  renderBookmarks();
}

function applyPendingRestore(clientId) {
  if (!_pendingRestoreMap[clientId]) return;
  var rst = _pendingRestoreMap[clientId];
  delete _pendingRestoreMap[clientId];
  if (rst.iniswap) {
    if (clients[clientId]) clients[clientId].iniswap = rst.iniswap;
    if (clientId === activeId) {
      iniswapInput.value = rst.iniswap;
      applyIniswap(rst.iniswap);
      updateSprite();
    }
    send({type:'INISWAP', name:rst.iniswap, client_id:clientId});
    fetchCharSubfolders(rst.iniswap);
  }
  if (rst.oocname) {
    if (clients[clientId]) clients[clientId].oocname = rst.oocname;
    if (clientId === activeId) oocNameInput.value = rst.oocname;
    send({type:'OOC_NAME', name:rst.oocname, client_id:clientId});
  }
  if (rst.icname) {
    if (clients[clientId]) clients[clientId].icname = rst.icname;
    if (clientId === activeId) icNameInput.value = rst.icname;
    send({type:'IC_NAME', name:rst.icname, client_id:clientId});
    renderTabs();
  }
}

function rebuildEmoteList(folder) {
  var en = folder ? (_charEmoteNames[folder] || null) : null;
  if (en && en.length > 0) {
    emotes = [];
    en.forEach(function(e) {
      emotes.push(e.name);
    });
  } else {
    emotes = defaultEmotes.slice();
  }
}

function fetchCharSubfolders(folder) {
  if (!folder) return;
  send({type:'GET_CHAR_SUBFOLDERS', name:folder});
}

function renderClientLog() {
  icLog.innerHTML = '';
  oocLog.innerHTML = '';
  var c = activeId !== null ? clients[activeId] : null;
  if (!c) return;
  (c.icMessages || []).forEach(function(m) {
    var el = document.createElement('div');
    el.className = 'msg ic';
    el.innerHTML = '<span class="time">' + m.t + '</span><span class="name">' + esc(m.n) + '</span>: ' + esc(m.x);
    icLog.appendChild(el);
  });
  (c.oocMessages || []).forEach(function(m) {
    var el = document.createElement('div');
    el.className = 'msg ooc';
    el.innerHTML = '<span class="time">' + m.t + '</span><span class="name">' + esc(m.n) + '</span>: ' + esc(m.x);
    oocLog.appendChild(el);
  });
  icLog.scrollTop = icLog.scrollHeight;
  oocLog.scrollTop = oocLog.scrollHeight;
}

function renderTabs() {
  var t = clientTabs;
  t.innerHTML = '';
  var ids = Object.keys(clients).map(Number).sort(function(a,b){return a-b;});
  var CLIENT_COLORS = ['#7af','#f77','#7f7','#ff7','#f7f','#77f','#fa7','#7ff','#a7f','#f88'];
  ids.forEach(function(id) {
    var c = clients[id];
    var label = c.icname || c.name || ('Клиент ' + id);
    var tab = document.createElement('span');
    tab.className = 'client-tab' + (id === activeId ? ' active' : '');
    var dot = document.createElement('span');
    var col = getClientColor(id);
    dot.style.cssText = 'display:inline-block;width:8px;height:8px;border-radius:50%;background:'+col+';margin-right:5px;cursor:pointer;vertical-align:middle';
    dot.title = 'Сменить цвет';
    dot.onclick = function(e) {
      e.stopPropagation();
      var cols = JSON.parse(localStorage.getItem('client_colors') || '{}');
      var cur = cols[id] || CLIENT_COLORS[parseInt(id) % CLIENT_COLORS.length];
      var idx = CLIENT_COLORS.indexOf(cur);
      cols[id] = CLIENT_COLORS[(idx + 1) % CLIENT_COLORS.length];
      localStorage.setItem('client_colors', JSON.stringify(cols));
      renderTabs();
    };
    tab.appendChild(dot);
    var txt = document.createTextNode('#' + id + ' ' + label);
    tab.appendChild(txt);
    tab.onclick = function() { setActive(id); };
    var close = document.createElement('span');
    close.className = 'close-btn';
    close.textContent = 'x';
    close.onclick = function(e) { e.stopPropagation(); disconnectClient(id); };
    tab.appendChild(close);
    t.appendChild(tab);
  });
  var add = document.createElement('span');
  add.className = 'add-tab';
  add.textContent = '+';
  add.onclick = function() { connectClient(); };
  t.appendChild(add);
}

function connectClient() {
  send({type:'CONNECT'});
}

function disconnectClient(id) {
  send({type:'DISCONNECT', client_id:id});
}

function setActive(id) {
  send({type:'SET_ACTIVE', client_id:id});
}

function requestClientList() {
  send({type:'GET_CLIENTS'});
}

function updateCharSelect(list) {
  var sel = charSelect;
  sel.innerHTML = '<option value="-1">— Наблюдатель —</option>';
  list.forEach(function(c) {
    var opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name;
    if (c.id === (activeId !== null && clients[activeId] ? clients[activeId].char_id : -1)) opt.selected = true;
    sel.appendChild(opt);
  });
}

function updateCharStatus(list) {
  list.forEach(function(s) {
    var opt = charSelect.querySelector('option[value="' + s.id + '"]');
    if (opt && s.taken) opt.textContent = chars[s.id] ? chars[s.id].name + ' (занят)' : '—';
  });
}

function updateAreaSelect(hubs) {
  var sel = areaSelect;
  sel.innerHTML = '<option value="">— Выберите —</option>';
  hubs.forEach(function(h) {
    if (h.areas.length === 0) return;
    var grp = document.createElement('optgroup');
    grp.label = h.name;
    h.areas.forEach(function(a) {
      var opt = document.createElement('option');
      opt.value = a.hub_id + ':' + a.id;
      opt.textContent = '[' + a.id + '] ' + a.name;
      grp.appendChild(opt);
    });
    sel.appendChild(grp);
  });
}

function getActiveImported() {
  var c = activeId !== null ? clients[activeId] : null;
  if (!c || !c.importedChars || !c.activeImported) return null;
  return c.importedChars[c.activeImported] || null;
}

function getPoseList() {
  var ps = document.getElementById('poseSelect');
  var out = [''];
  if (ps) {
    for (var i = 0; i < ps.options.length; i++) {
      if (ps.options[i].value) out.push(ps.options[i].value);
    }
  }
  return out;
}

function getEmoteSpriteKey(emote) {
  var pose = document.getElementById('poseSelect');
  var sub = pose ? pose.value : '';
  if (!sub) return emote;
  var imp = getActiveImported();
  if (imp && imp.emoteMap && imp.emoteMap[emote] !== undefined) {
    var nameKey = sub + '/' + emote;
    if (imp.sprites[nameKey]) return nameKey;
    return sub + '/' + imp.emoteMap[emote];
  }
  return sub + '/' + emote;
}

function emoteToAnim(folder, name) {
  var en = _charEmoteNames[folder] || [];
  for (var i = 0; i < en.length; i++) {
    if (en[i].name === name) return String(en[i].index);
  }
  if (!_charEmoteNames[folder]) {
    var idx = defaultEmotes.indexOf(name);
    if (idx >= 0) return String(idx);
  }
  return name;
}

function updateSprite(emoteOverride) {
  var emote = emoteOverride || emoteInput.value.trim() || 'normal';
  currentEmoteLabel.textContent = emote;
  var imp = getActiveImported();
  if (imp) {
    var key = getEmoteSpriteKey(emote);
    if (imp.sprites[key]) {
      charImg.src = imp.sprites[key];
      charImg.style.display = 'block';
      return;
    }
    if (imp.sprites[emote]) {
      charImg.src = imp.sprites[emote];
      charImg.style.display = 'block';
      return;
    }
  }
  if (!assetUrl || activeId === null) { charImg.src = ''; return; }
  var c = clients[activeId];
  if (!c || c.char_id < 0 || c.char_id >= chars.length) { charImg.src = ''; return; }
  var folder = c.iniswap || chars[c.char_id].name;
  var base = assetUrl.replace(/\/+$/, '') + '/characters/' + encodeURIComponent(folder);
  var anim = emoteToAnim(folder, emote);
  var poses = getPoseList();
  if (!charImg._tryPoses) charImg._tryPoses = {};
  var key = folder + '/' + emote;
  if (charImg._tryPoses[key] !== undefined) {
    var p = charImg._tryPoses[key];
    charImg.src = base + (p ? '/' + encodeURIComponent(p) : '') + '/' + encodeURIComponent(anim) + '.png';
  } else {
    charImg._poseQueue = { base: base, emote: anim, key: key, poses: poses, index: 0, folder: folder };
    var imgObj = charImg;
    imgObj.onload = function() {
      var q = this._poseQueue;
      if (q) { this._tryPoses[q.key] = q.poses[q.index]; }
      this._poseQueue = null;
    };
    imgObj.onerror = function() {
      var q = this._poseQueue;
      if (!q) return;
      q.index++;
      if (q.index < q.poses.length) {
        var p = q.poses[q.index];
        this.src = q.base + (p ? '/' + encodeURIComponent(p) : '') + '/' + encodeURIComponent(q.emote) + '.png';
      } else {
        this._poseQueue = null;
      }
    };
    imgObj.onerror();
  }
  charImg.style.display = 'block';
}

function filterEmotes() {
  updateSprite();
  renderEmoteGrid();
}

function renderEmoteGrid() {
  var g = emoteGrid;
  g.innerHTML = '';
  var sel = emoteInput.value.trim().toLowerCase();
  var imp = getActiveImported();
  var poseSel = document.getElementById('poseSelect');
  // determine actual folder from current client state
  var folder = '';
  var c = activeId !== null ? clients[activeId] : null;
  if (c && c.char_id >= 0 && c.char_id < chars.length) folder = c.iniswap || chars[c.char_id].name;
  var pmap = folder ? _emotePoseMap[folder] || {} : {};
  emotes.slice(0, 60).forEach(function(e) {
    var btn = document.createElement('button');
    btn.title = e;
    if (imp && imp.buttons[e]) {
      var img = document.createElement('img');
      img.src = imp.buttons[e];
      img.style.width = '36px';
      img.style.height = '36px';
      img.style.imageRendering = 'pixelated';
      img.style.display = 'block';
      btn.appendChild(img);
    } else {
      btn.textContent = e;
    }
    btn.style.padding = '1px';
    btn.style.border = '1px solid #333';
    btn.style.borderRadius = '2px';
    btn.style.cursor = 'pointer';
    btn.style.fontSize = '0.65rem';
    if (e === sel) {
      btn.style.background = '#333';
      btn.style.borderColor = '#7af';
    } else {
      btn.style.background = 'none';
    }
    // resolve emote name → index → pose
    var idx = -1;
    var en = folder ? (_charEmoteNames[folder] || []) : [];
    for (var k = 0; k < en.length; k++) {
      if (en[k].name === e) { idx = en[k].index; break; }
    }
    var ep = (idx >= 0) ? pmap[idx] : null;
    if (ep) {
      btn.title = e + ' [' + ep + ']';
      btn.style.borderColor = '#6a6';
      btn.style.color = '#6a6';
    }
    btn._emoji = e;
    btn._epIdx = idx;
    btn.onmouseenter = function(ev) {
      var p = (this._epIdx >= 0) ? pmap[this._epIdx] : null;
      if (p && poseSel) { poseSel.value = p; }
      showEmoteTooltip(this._emoji, ev);
    };
    btn.onmouseleave = function() { hideEmoteTooltip(); };
    btn.onmousemove = function(ev) { moveEmoteTooltip(ev); };
    btn.onclick = function() { emoteInput.value = e; renderEmoteGrid(); updateSprite(e); };
    g.appendChild(btn);
  });
}
renderEmoteGrid();

function openSpritePopup() {
  if (document.getElementById('spriteViewer')) return;
  if (!assetUrl || activeId === null) return;
  var c = clients[activeId];
  if (!c) return;
  var folder = c.iniswap || (c.char_id >= 0 && c.char_id < chars.length ? chars[c.char_id].name : '');
  if (!folder) return;
  var currentEmote = emoteInput.value.trim() || 'normal';
  var emoteList = _charEmoteNames[folder] ? _charEmoteNames[folder].map(function(e) { return e.name; }) : defaultEmotes;
  var currentIdx = Math.max(0, emoteList.indexOf(currentEmote));

  var overlay = document.createElement('div');
  overlay.id = 'spriteViewer';
  overlay.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,0.9);display:flex;align-items:center;justify-content:center;flex-direction:column';

  var viewerImg = document.createElement('img');
  viewerImg.style.cssText = 'max-width:90vw;max-height:80vh;object-fit:contain;image-rendering:pixelated;transition:transform 0.15s';
  var scale = 1;
  viewerImg.onwheel = function(e) {
    e.preventDefault();
    scale = Math.max(0.5, Math.min(5, scale - e.deltaY * 0.005));
    viewerImg.style.transform = 'scale(' + scale + ')';
  };

  function mkBtn(text, title) {
    var b = document.createElement('button');
    b.textContent = text;
    b.title = title || '';
    b.style.cssText = 'background:var(--cp-btn-bg);border:1px solid rgba(0,240,255,0.12);border-radius:3px;padding:5px 12px;color:var(--cp-cyan);cursor:pointer;font-size:0.82rem;font-family:inherit';
    return b;
  }
  var nav = document.createElement('div');
  nav.style.cssText = 'display:flex;gap:10px;align-items:center;padding:10px 0';
  var prevBtn = mkBtn('◀', 'Предыдущая (←)');
  var nextBtn = mkBtn('▶', 'Следующая (→)');
  var counter = document.createElement('span');
  counter.style.cssText = 'font-size:0.78rem;color:var(--cp-text-dim);min-width:180px;text-align:center';
  var closeBtn = mkBtn('✕ Закрыть', 'Закрыть (Esc)');
  nav.appendChild(prevBtn); nav.appendChild(counter); nav.appendChild(nextBtn); nav.appendChild(closeBtn);

  var base = assetUrl.replace(/\/+$/, '') + '/characters/' + encodeURIComponent(folder);
  var poses = getPoseList();

  function loadEmote(idx) {
    if (idx < 0 || idx >= emoteList.length) return;
    currentIdx = idx;
    var emote = emoteList[currentIdx];
    var anim = emoteToAnim(folder, emote);
    counter.textContent = emote + ' (' + (currentIdx + 1) + '/' + emoteList.length + ')';
    tryPose(0);
    function tryPose(pi) {
      if (pi >= poses.length) { viewerImg.src = ''; return; }
      var p = poses[pi];
      viewerImg.src = base + (p ? '/' + encodeURIComponent(p) : '') + '/' + encodeURIComponent(anim) + '.png';
      viewerImg.onload = undefined;
      viewerImg.onerror = function() { tryPose(pi + 1); };
    }
  }

  prevBtn.onclick = function() { loadEmote(currentIdx - 1); };
  nextBtn.onclick = function() { loadEmote(currentIdx + 1); };
  closeBtn.onclick = function() { overlay.remove(); };
  overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };
  document.addEventListener('keydown', function handler(e) {
    if (!document.getElementById('spriteViewer')) { document.removeEventListener('keydown', handler); return; }
    if (e.key === 'Escape') { overlay.remove(); }
    if (e.key === 'ArrowLeft') { e.preventDefault(); loadEmote(currentIdx - 1); }
    if (e.key === 'ArrowRight') { e.preventDefault(); loadEmote(currentIdx + 1); }
  });

  overlay.appendChild(viewerImg);
  overlay.appendChild(nav);
  document.body.appendChild(overlay);
  loadEmote(currentIdx);
}

function showEmoteTooltip(emote, ev) {
  var tip = document.getElementById('emoteTooltip');
  var img = document.getElementById('emoteTooltipImg');
  if (!tip || !img) return;
  if (!assetUrl || activeId === null) { tip.style.display = 'none'; return; }
  var c = clients[activeId];
  if (!c) { tip.style.display = 'none'; return; }
  var folder = c.iniswap || (c.char_id >= 0 && c.char_id < chars.length ? chars[c.char_id].name : '');
  if (!folder) { tip.style.display = 'none'; return; }
  var base = assetUrl.replace(/\/+$/, '') + '/characters/' + encodeURIComponent(folder);
  var anim = emoteToAnim(folder, emote);
  var poses = getPoseList();
  img._poseQueue = { base: base, emote: anim, poses: poses, index: 0 };
  img.onload = function() { this._poseQueue = null; tip.style.display = 'block'; };
  img.onerror = function() {
    var q = this._poseQueue;
    if (!q) return;
    q.index++;
    if (q.index < q.poses.length) {
      var p = q.poses[q.index];
      this.src = q.base + (p ? '/' + encodeURIComponent(p) : '') + '/' + encodeURIComponent(q.emote) + '.png';
    } else {
      this._poseQueue = null;
      tip.style.display = 'none';
    }
  };
  img.onerror();
  tip.style.left = (ev.clientX + 15) + 'px';
  tip.style.top = (ev.clientY + 15) + 'px';
}

function hideEmoteTooltip() {
  var tip = document.getElementById('emoteTooltip');
  if (tip) tip.style.display = 'none';
}

function moveEmoteTooltip(ev) {
  var tip = document.getElementById('emoteTooltip');
  if (!tip || tip.style.display === 'none') return;
  tip.style.left = (ev.clientX + 15) + 'px';
  tip.style.top = (ev.clientY + 15) + 'px';
}

// ─── Onboarding / help overlay ──────────────────────────────
function showOnboarding() {
  if (document.getElementById('onboardingOverlay')) return;
  var overlay = document.createElement('div');
  overlay.id = 'onboardingOverlay';
  overlay.style.cssText = 'position:fixed;inset:0;z-index:200000;background:rgba(6,6,12,0.92);display:flex;align-items:center;justify-content:center';
  overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };

  var box = document.createElement('div');
  box.style.cssText = 'background:var(--cp-bg-win);border:1px solid var(--cp-border);border-radius:var(--cp-radius);max-width:520px;width:90%;max-height:80vh;overflow-y:auto;padding:24px 28px;box-shadow:0 0 40px rgba(0,240,255,0.08)';
  box.innerHTML =
    '<h2 style="color:var(--cp-cyan);font-size:1rem;margin:0 0 12px 0;text-transform:uppercase;letter-spacing:1.5px">Режим игрока</h2>' +
    '<div style="font-size:0.82rem;line-height:1.7;color:var(--cp-text)">' +
    '<p><b style="color:var(--cp-cyan)">IC / OOC</b> — основные чаты. Ввод с автофокусом.</p>' +
    '<p><b style="color:var(--cp-cyan)">Перс</b> — выбор персонажа, INI-Swap, эмоции, /pos, спрайт.</p>' +
    '<p><b style="color:var(--cp-cyan)">Инст</b> — инструменты: SFX, музыка, громкость, очередь, заметки.</p>' +
    '<p><b style="color:var(--cp-cyan)">Очередь</b> — отложенные сообщения. Текст, INI-Swap, эмоция, /pos, SFX, пауза. Импорт/экспорт INI.</p>' +
    '<p><b style="color:var(--cp-cyan)">Пресеты</b> — сохранить/загрузить клиентов (INI-Swap, имя, персонаж).</p>' +
    '<p><b style="color:var(--cp-cyan)">Логи</b> — история сессий.</p>' +
    '<hr style="border-color:rgba(0,240,255,0.06);margin:10px 0">' +
    '<p><b style="color:var(--cp-magenta)">/p текст</b> — отправить в очередь из IC чата (AO клиент).<br>' +
    '<b style="color:var(--cp-magenta)">/p 5 текст</b> — с паузой 5 секунд.</p>' +
    '<p><b style="color:var(--cp-magenta)">/p_send N</b> — отправить очередь с интервалом N сек.<br>' +
    '<b style="color:var(--cp-magenta)">/p_list</b> / <b>/p_remove N</b> / <b>/p_sendone N</b> — управление очередью.</p>' +
    '<hr style="border-color:rgba(0,240,255,0.06);margin:10px 0">' +
    '<p><b style="color:var(--cp-gold)">Формат</b> — кнопки <b>/me</b> <b>/it</b> <b>*I*</b> <b>A!</b> <b>~w~</b> над полем IC.</p>' +
    '<p><b style="color:var(--cp-gold)">Спрайт</b> — кнопка "🔍 Открыть спрайт" в окне персонажа → просмотр с зумом и стрелками.</p>' +
    '<p><b style="color:var(--cp-gold)">Громкость</b> — ползунок в окне "Инст".</p>' +
    '</div>' +
    '<div style="text-align:center;margin-top:16px">' +
    '<button onclick="this.closest(\'#onboardingOverlay\').remove()" style="background:var(--cp-btn-bg);border:1px solid rgba(0,240,255,0.15);border-radius:3px;padding:8px 24px;color:var(--cp-cyan);cursor:pointer;font-size:0.82rem;font-family:inherit">Понятно</button>' +
    '</div>';
  overlay.appendChild(box);
  document.body.appendChild(overlay);
}

function requestAreaList() {
  send({type:'GET_AREA_LIST'});
}

function storeMsg(arr, name, text) {
  if (!arr) return;
  var t = new Date().toLocaleTimeString();
  arr.push({n: name, x: text, t: t});
  if (arr.length > 500) arr.splice(0, arr.length - 500);
}

function addIC(name, text, charId) {
  var c = activeId !== null ? clients[activeId] : null;
  if (c) storeMsg(c.icMessages, name, text);
  var el = document.createElement('div');
  el.className = 'msg ic';
  var t = new Date().toLocaleTimeString();
  var tag = activeId !== null ? '<span class="client-tag">#' + activeId + '</span>' : '';
  el.innerHTML = '<span class="time">' + t + '</span>' + tag + '<span class="name">' + esc(name) + '</span>: ' + formatIcText(esc(text));
  icLog.appendChild(el);
  icLog.scrollTop = icLog.scrollHeight;
}

function addOOC(name, text) {
  var c = activeId !== null ? clients[activeId] : null;
  if (c) storeMsg(c.oocMessages, name, text);
  var el = document.createElement('div');
  el.className = 'msg ooc';
  var t = new Date().toLocaleTimeString();
  el.innerHTML = '<span class="time">' + t + '</span><span class="name">' + esc(name) + '</span>: ' + esc(text);
  oocLog.appendChild(el);
  oocLog.scrollTop = oocLog.scrollHeight;
}

function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function updateTypingIndicator() {
  var names = Object.keys(typingTimers);
  if (names.length === 0) {
    typingIndicator.style.height = '0';
    return;
  }
  var text = names.map(function(n) { return '"' + esc(n) + '"'; }).join(', ');
  typingText.textContent = text + ' печатает' + (names.length > 1 ? 'ют' : 'ет') + '...';
  typingIndicator.style.height = '20px';
}

function send(data) {
  if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(data));
}

function sendIC() {
  var text = icInput.value;
  if (!text.trim()) text = '';
  var emoteName = emoteInput.value.trim() || 'normal';
  var folder = emoteFolder.value.trim() || '';
  var c = activeId !== null ? clients[activeId] : null;
  if (!folder && c && c.iniswap) folder = c.iniswap;
  var poseSel = document.getElementById('poseSelect');
  var pos = poseSel ? poseSel.value : '';
  // resolve emote name to file index via char.ini mapping
  var en = folder ? (_charEmoteNames[folder] || null) : null;
  var anim = emoteName;
  if (en) {
    for (var i = 0; i < en.length; i++) {
      if (en[i].name === emoteName) {
        anim = String(en[i].index);
        break;
      }
    }
  }
  sendIcRaw(activeId, text, anim, folder, pos);
  icInput.value = '';
  if (typingState) {
    typingState = false;
    if (typingStopTimer) clearTimeout(typingStopTimer);
    typingStopTimer = null;
    send({type:'TYPING', state:0});
  }
}

function sendIcRaw(clientId, text, emote, folder, pos) {
  var payload = {type:'IC', text:text, emote:emote, folder:folder, pos:pos || ''};
  if (clientId != null) payload.client_id = clientId;
  send(payload);
}

function sendOOC() {
  var text = oocInput.value.trim();
  if (!text) return;
  // ─── Client-side command handling ───
  if (text.startsWith('/preset ')) {
    handlePresetCmd(text);
    oocInput.value = '';
    return;
  }
  if (text === '/preset') {
    handlePresetCmd('/preset list');
    oocInput.value = '';
    return;
  }
  if (text === '/stats') {
    handleStatsCmd(oocInput);
    oocInput.value = '';
    return;
  }
  var c = activeId !== null ? clients[activeId] : null;
  var oocname = (c && c.oocname) ? c.oocname : '';
  var payload = {type:'OOC', text:text, ooc_name:oocname};
  if (activeId !== null) payload.client_id = activeId;
  send(payload);
  oocInput.value = '';
}

function selectChar(id, cid) {
  cid = (cid !== undefined) ? cid : activeId;
  var c = cid !== null ? clients[cid] : null;
  if (c) c.char_id = parseInt(id);
  send({type:'CHAR', char_id:parseInt(id), client_id:cid});
  var ch = chars[parseInt(id)];
  if (ch && ch.name) {
    // auto-fill INI-swap only if not restoring from preset
    if (!_pendingRestoreMap[cid]) {
      if (cid === activeId) iniswapInput.value = ch.name;
      if (clients[cid]) clients[cid].iniswap = ch.name;
      send({type:'INISWAP', name:ch.name, client_id:cid});
      updateSprite();
    }
    fetchCharSubfolders(ch.name);
  }
}

function selectArea(val, cid) {
  if (!val) return;
  var parts = val.split(':');
  cid = (cid !== undefined) ? cid : activeId;
  var c = cid !== null ? clients[cid] : null;
  if (c) { c.hub_id = parseInt(parts[0]); c.area_id = parseInt(parts[1]); }
  send({type:'AREA', hub_id:parseInt(parts[0]), area_id:parseInt(parts[1]), client_id:cid});
}

function setIniswap() {
  var name = iniswapInput.value.trim();
  if (activeId !== null && clients[activeId]) clients[activeId].iniswap = name;
  send({type:'INISWAP', name:name, client_id:activeId});
  applyIniswap(name);
  updateSprite();
}

function setOocName() {
  var name = oocNameInput.value.trim();
  if (activeId !== null && clients[activeId]) clients[activeId].oocname = name;
  send({type:'OOC_NAME', name:name, client_id:activeId});
}

function setIcName() {
  var name = icNameInput.value.trim();
  if (activeId !== null && clients[activeId]) clients[activeId].icname = name;
  send({type:'IC_NAME', name:name, client_id:activeId});
  renderTabs();
}

// ─── Import character folder for reference ─────────────────────────────
function applyImportedChar(data) {
  emotes = defaultEmotes.slice();
  var poseSel = document.getElementById('poseSelect');
  if (!data || !data.dirName) {
    document.getElementById('importedCharInfo').textContent = '';
    emoteInput.value = 'normal';
    if (poseSel) { poseSel.innerHTML = ''; poseSel.style.display = 'none'; }
    renderEmoteGrid();
    return;
  }
  emotes = data.emoteNames.slice();
  document.getElementById('importedCharInfo').textContent = data.dirName + ' (' + data.emoteNames.length + ' эмоутов)';
  // populate pose selector
  if (poseSel) {
    poseSel.innerHTML = '<option value="">—</option>';
    (data.subfolders || []).forEach(function(s) {
      var opt = document.createElement('option');
      opt.value = s;
      opt.textContent = s;
      poseSel.appendChild(opt);
    });
    poseSel.style.display = data.subfolders && data.subfolders.length > 0 ? '' : 'none';
  }
  renderEmoteGrid();
}
function importCharFolder(fileList) {
  var info = document.getElementById('importedCharInfo');
  if (!fileList || fileList.length === 0) return;
  var files = Array.prototype.slice.call(fileList);
  // extract folder name from the first file's webkitRelativePath
  var dirName = '';
  for (var i = 0; i < files.length; i++) {
    var rel = files[i].webkitRelativePath || files[i].name;
    var parts = rel.split('/');
    if (parts.length > 1) { dirName = parts[0]; break; }
  }
  if (!dirName) { info.textContent = 'Не удалось определить имя папки'; return; }
  // auto-fill INI-swap
  iniswapInput.value = dirName;
  if (activeId !== null && clients[activeId]) clients[activeId].iniswap = dirName;
  send({type:'INISWAP', name:dirName, client_id:activeId});

  // read char.ini for emote names if present
  var iniEmotes = {};
  var iniFile = files.filter(function(f) { return f.name.toLowerCase() === 'char.ini'; })[0];
  if (iniFile) {
    var reader = new FileReader();
    reader.onload = function(e) {
      parseCharIni(e.target.result, iniEmotes);
      finishImport(files, dirName, iniEmotes);
    };
    reader.readAsText(iniFile);
  } else {
    finishImport(files, dirName, iniEmotes);
  }
}

function parseCharIni(text, out) {
  var lines = text.split('\n');
  var inEmotions = false;
  for (var i = 0; i < lines.length; i++) {
    var line = lines[i].trim();
    if (line === '[Emotions]') { inEmotions = true; continue; }
    if (line.match(/^\[.*\]/)) { inEmotions = false; continue; }
    if (inEmotions && line.indexOf('=') > 0) {
      var parts = line.split('=');
      var num = parseInt(parts[0].trim());
      if (!isNaN(num)) {
        var val = parts[1].trim();
        var fields = val.split('#');
        var emoteName = fields[1] || String(num);
        out[num] = emoteName;
      }
    }
  }
}

function finishImport(files, dirName, iniEmotes) {
  var emotions = {};
  var sprites = {};      // key: idx or "subdir/idx"
  var subfolders = [];   // detected pose subdirectories
  for (var i = 0; i < files.length; i++) {
    var f = files[i];
    var rel = f.webkitRelativePath || f.name;
    var parts = rel.split('/');
    var fname = parts[parts.length - 1];
    // emotions/buttonX_off.png
    if (parts.indexOf('emotions') >= 0 || parts.indexOf('Emotions') >= 0) {
      var match = fname.match(/^button(\d+)/i);
      if (match) { emotions[parseInt(match[1])] = f; }
    } else if (fname.match(/^\d+\.png$/i)) {
      var match2 = fname.match(/^(\d+)\.png$/i);
      if (match2) {
        var idx = parseInt(match2[1]);
        if (parts.length === 2) {
          // root: 1.png
          sprites[idx] = f;
        } else if (parts.length === 3) {
          // subdir/1.png — pose folder (any name)
          var sub = parts[1];
          sprites[sub + '/' + idx] = f;
          if (subfolders.indexOf(sub) < 0) subfolders.push(sub);
        }
      }
    }
  }
  subfolders.sort();
  // merge all emotion indices
  var allIndices = {};
  Object.keys(emotions).forEach(function(k) { allIndices[k] = true; });
  Object.keys(sprites).forEach(function(k) {
    // only add root-level sprite indices to allIndices
    if (!isNaN(parseInt(k))) allIndices[k] = true;
  });
  var importedChar = {dirName:dirName, sprites:{}, buttons:{}, emoteNames:[], emoteMap:{}, subfolders:subfolders};
  Object.keys(allIndices).sort(function(a,b) { return parseInt(a)-parseInt(b); }).forEach(function(idx) {
    var name = iniEmotes[idx] || String(idx);
    importedChar.emoteNames.push(name);
    importedChar.emoteMap[name] = parseInt(idx);
    if (emotions[idx]) importedChar.buttons[name] = URL.createObjectURL(emotions[idx]);
    // root sprite
    if (sprites[idx]) importedChar.sprites[name] = URL.createObjectURL(sprites[idx]);
    // subfolder sprites — keyed by name, not index
    subfolders.forEach(function(sub) {
      var k = sub + '/' + idx;
      if (sprites[k]) importedChar.sprites[sub + '/' + name] = URL.createObjectURL(sprites[k]);
    });
  });
  // remember in localStorage for quick-swap
  saveIniswapPreset(dirName);
  // store in active client's importedChars map
  if (activeId !== null && clients[activeId]) {
    var c = clients[activeId];
    if (!c.importedChars) c.importedChars = {};
    var old = c.importedChars[dirName];
    if (old && old !== importedChar) {
      Object.keys(old.sprites).forEach(function(k) { URL.revokeObjectURL(old.sprites[k]); });
      Object.keys(old.buttons).forEach(function(k) { URL.revokeObjectURL(old.buttons[k]); });
    }
    c.importedChars[dirName] = importedChar;
    c.activeImported = dirName;
  }
  applyImportedChar(importedChar);
  if (importedChar.emoteNames.length > 0) {
    emoteInput.value = importedChar.emoteNames[0];
  }
  renderQuickSwap();
  updateSprite();
  // save to IndexedDB asynchronously
  saveImportToDB(files, dirName, iniEmotes);
}

function saveImportToDB(files, dirName, iniEmotes) {
  var emotionsFiles = {}, spritesFiles = {}, subfolders = [], allIndices = {};
  for (var i = 0; i < files.length; i++) {
    var f = files[i], rel = f.webkitRelativePath || f.name, parts = rel.split('/'), fname = parts[parts.length - 1];
    if (parts.indexOf('emotions') >= 0 || parts.indexOf('Emotions') >= 0) {
      var m = fname.match(/^button(\d+)/i);
      if (m) { emotionsFiles[parseInt(m[1])] = f; allIndices[parseInt(m[1])] = true; }
    } else if (fname.match(/^\d+\.png$/i)) {
      var m2 = fname.match(/^(\d+)\.png$/i);
      if (m2) {
        var idx = parseInt(m2[1]); allIndices[idx] = true;
        if (parts.length === 2) { spritesFiles[idx] = f; }
        else if (parts.length === 3) {
          var sub = parts[1];
          spritesFiles[sub+'/'+idx] = f; if (subfolders.indexOf(sub)<0) subfolders.push(sub);
        }
      }
    }
  }
  var reads = [], emD={}, spD={};
  Object.keys(emotionsFiles).forEach(function(idx) {
    reads.push(new Promise(function(r) { var rd=new FileReader(); rd.onload=function(){emD[idx]=rd.result;r();}; rd.readAsDataURL(emotionsFiles[idx]); }));
  });
  Object.keys(spritesFiles).forEach(function(key) {
    reads.push(new Promise(function(r) { var rd=new FileReader(); rd.onload=function(){spD[key]=rd.result;r();}; rd.readAsDataURL(spritesFiles[key]); }));
  });
  Promise.all(reads).then(function() {
    var cd = {dirName:dirName, emoteNames:[], emoteMap:{}, subfolders:subfolders, buttons:{}, sprites:{}};
    Object.keys(allIndices).sort(function(a,b){return parseInt(a)-parseInt(b);}).forEach(function(idx) {
      var nm = iniEmotes[idx] || String(idx);
      cd.emoteNames.push(nm); cd.emoteMap[nm]=parseInt(idx);
      if (emD[idx]) cd.buttons[nm]=emD[idx];
      if (spritesFiles[idx] && spD[idx]) cd.sprites[nm]=spD[idx];
      subfolders.forEach(function(s) { var k=s+'/'+idx; if(spD[k]) cd.sprites[s+'/'+nm]=spD[k]; });
    });
    saveCharToDB(cd).catch(function(){});
  });
}

// ─── INI-swap presets (localStorage) with categories ─────────────────
function migrateIniswapPresets() {
  var raw;
  try { raw = JSON.parse(localStorage.getItem('iniswap_presets') || '[]'); } catch(e) { raw = []; }
  if (raw.length === 0) return raw;
  // if stored as strings, convert to objects
  if (typeof raw[0] === 'string') {
    raw = raw.map(function(n) { return {name:n, cat:''}; });
    localStorage.setItem('iniswap_presets', JSON.stringify(raw));
  }
  return raw;
}
function getIniswapPresets() {
  try { return migrateIniswapPresets(); } catch(e) { return []; }
}
function getIniswapCategories() {
  try { return JSON.parse(localStorage.getItem('iniswap_categories') || '[]'); } catch(e) { return []; }
}
function saveIniswapPreset(name) {
  var list = getIniswapPresets();
  if (!list.some(function(p) { return p.name === name; })) {
    list.push({name:name, cat:''});
  }
  localStorage.setItem('iniswap_presets', JSON.stringify(list));
}
function removeIniswapPreset(name) {
  var list = getIniswapPresets().filter(function(p) { return p.name !== name; });
  localStorage.setItem('iniswap_presets', JSON.stringify(list));
}
function saveIniswapCategory(name) {
  name = name.trim();
  if (!name) return;
  var cats = getIniswapCategories();
  if (cats.indexOf(name) < 0) cats.push(name);
  localStorage.setItem('iniswap_categories', JSON.stringify(cats));
}
function removeIniswapCategory(name) {
  var cats = getIniswapCategories().filter(function(c) { return c !== name; });
  localStorage.setItem('iniswap_categories', JSON.stringify(cats));
  // reassign presets in this category to uncategorized
  var list = getIniswapPresets();
  list.forEach(function(p) { if (p.cat === name) p.cat = ''; });
  localStorage.setItem('iniswap_presets', JSON.stringify(list));
}
function setIniswapPresetCat(pname, cat) {
  var list = getIniswapPresets();
  var p = list.find(function(x) { return x.name === pname; });
  if (p) p.cat = cat;
  localStorage.setItem('iniswap_presets', JSON.stringify(list));
}

function applyIniswap(name) {
  var c = activeId !== null ? clients[activeId] : null;
  var imp = c && c.importedChars ? c.importedChars[name] : null;
  if (!imp && _dbChars[name]) {
    imp = _dbChars[name];
    if (c) { c.importedChars[name] = imp; c.activeImported = name; }
  } else if (imp && c) {
    c.activeImported = name;
  } else if (c) {
    c.activeImported = null;
  }
  applyImportedChar(imp);
}

function addCategory() {
  var inp = document.getElementById('catInput');
  if (!inp) return;
  saveIniswapCategory(inp.value);
  inp.value = '';
  renderQuickSwap();
}

function renderQuickSwap() {
  var el = document.getElementById('quickSwapList');
  if (!el) return;
  var list = getIniswapPresets();
  var cats = getIniswapCategories();
  var collapsed = {};
  try { collapsed = JSON.parse(localStorage.getItem('iniswap_collapsed') || '{}'); } catch(e) {}
  el.innerHTML = '';
  if (list.length === 0 && cats.length === 0) {
    el.innerHTML = '<div style="font-size:0.75rem;color:#666;padding:4px 0">Нет сохранённых</div>';
    return;
  }
  var grouped = {};
  list.forEach(function(p) {
    var c = p.cat || '';
    if (!grouped[c]) grouped[c] = [];
    grouped[c].push(p.name);
  });
  function renderGroup(label, names) {
    var isCat = label !== '';
    var isCol = isCat && collapsed[label];
    var hdr = document.createElement('div');
    if (isCat) {
      hdr.style.cssText = 'font-size:0.72rem;color:#8bf;padding:5px 4px 3px 4px;border-bottom:1px solid #2a3a50;margin-top:5px;display:flex;justify-content:space-between;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;cursor:pointer;user-select:none';
      hdr.innerHTML = '<span>' + (isCol ? '\u25B6' : '\u25BC') + ' ' + label + ' (' + names.length + ')</span>';
      var delCat = document.createElement('span');
      delCat.textContent = 'X';
      delCat.style.cssText = 'color:#855;cursor:pointer;font-size:0.65rem;padding:0 3px;font-weight:700';
      delCat.title = 'Удалить категорию';
      delCat.onclick = function(e) { e.stopPropagation(); removeIniswapCategory(label); renderQuickSwap(); };
      hdr.appendChild(delCat);
      hdr.onclick = function() {
        if (collapsed[label]) delete collapsed[label];
        else collapsed[label] = true;
        localStorage.setItem('iniswap_collapsed', JSON.stringify(collapsed));
        renderQuickSwap();
      };
    } else {
      hdr.style.cssText = 'font-size:0.68rem;color:#777;padding:5px 4px 2px 4px;border-bottom:1px solid #1a1a28;margin-top:2px;font-weight:500;letter-spacing:0.3px;text-transform:uppercase';
      hdr.textContent = 'Без категории (' + names.length + ')';
    }
    el.appendChild(hdr);
    if (isCol) return;
    if (names.length === 0) {
      var empty = document.createElement('div');
      empty.style.cssText = 'font-size:0.7rem;color:#555;padding:3px 6px';
      empty.textContent = '(пусто)';
      el.appendChild(empty);
      return;
    }
    names.slice().reverse().forEach(function(name) {
      var item = document.createElement('div');
      item.className = 'quick-swap-item';
      var nameSpan = document.createElement('span');
      nameSpan.textContent = name;
      nameSpan.style.flex = '1';
      nameSpan.style.cursor = 'pointer';
      nameSpan.style.fontWeight = '500';
      nameSpan.onclick = function() {
        iniswapInput.value = name;
        if (activeId !== null && clients[activeId]) clients[activeId].iniswap = name;
        send({type:'INISWAP', name:name, client_id:activeId});
        applyIniswap(name);
        updateSprite();
      };
      item.appendChild(nameSpan);

      var catBtn = document.createElement('span');
      catBtn.textContent = 'C';
      catBtn.style.cssText = 'color:#666;cursor:pointer;font-size:0.65rem;margin-right:4px;font-weight:600;padding:0 2px';
      catBtn.title = 'Сменить категорию';
      catBtn.onclick = function(e) {
        e.stopPropagation();
        var allCats = getIniswapCategories();
        var promptText = 'Категория для "' + name + '":\n' + allCats.map(function(c,i){return (i+1)+'. '+c;}).join('\n') + '\n(0 = без категории, Enter = отмена)';
        var choice = prompt(promptText, '');
        if (choice === null || choice === '') return;
        var idx = parseInt(choice);
        if (idx === 0) { setIniswapPresetCat(name, ''); renderQuickSwap(); return; }
        if (idx > 0 && idx <= allCats.length) { setIniswapPresetCat(name, allCats[idx-1]); renderQuickSwap(); }
      };
      item.appendChild(catBtn);

      var del = document.createElement('span');
      del.textContent = 'X';
      del.style.cssText = 'color:#744;cursor:pointer;font-size:0.7rem;font-weight:700;padding:0 2px';
      del.title = 'Удалить пресет';
      del.onclick = function(e) {
        e.stopPropagation();
        removeIniswapPreset(name);
        renderQuickSwap();
      };
      item.appendChild(del);
      el.appendChild(item);
    });
  }
  renderGroup('', grouped[''] || []);
  cats.forEach(function(c) { renderGroup(c, grouped[c] || []); });
}

// ─── Queue system ────────────────────────────────────────────────────
function updateQueueBadge() {
  var c = activeId !== null ? clients[activeId] : null;
  var cnt = c && c.msgQueue ? c.msgQueue.length : 0;
  var btn = document.getElementById('queueBtn');
  if (btn) btn.textContent = 'Отложка (' + cnt + ')';
  var gbtn = document.getElementById('globalQueueBtn');
  if (gbtn) gbtn.textContent = 'Общая отложка (' + globalQueue.length + ')';
  // update taskbar badge if exists
  var tb = document.querySelector('.tb-btn[data-win="win-queue"]');
  if (tb) tb.textContent = 'Очередь (' + (cnt + globalQueue.length) + ')';
}

function queueIc() {
  var c = activeId !== null ? clients[activeId] : null;
  if (!c) return;
  var text = icInput.value;
  if (!text.trim()) text = '';
  var emote = emoteInput.value.trim() || 'normal';
  var folder = emoteFolder.value.trim() || '';
  if (!folder && c.iniswap) folder = c.iniswap;
  var delay = parseInt(document.getElementById('delayInput').value) || 0;
  var poseSel = document.getElementById('poseSelect');
  var pos = poseSel ? poseSel.value : '';
  var sfx = document.getElementById('sfxInput') ? document.getElementById('sfxInput').value.trim() : '';
  if (!c.msgQueue) c.msgQueue = [];
  var item = {text:text, emote:emote, folder:folder, iniswap:c.iniswap||'', pos:pos, sfx:sfx, delay:delay, id:Date.now() + Math.random(), timerId:null};
  c.msgQueue.push(item);
  if (delay > 0) {
    qScheduleItem(item);
  }
  updateQueueBadge();
  icInput.value = '';
  document.getElementById('delayInput').value = '';
  qRender();
}

function scheduleQueueItem(item, items, mode) {
  // Legacy wrapper — delegates to new qScheduleItem but doesn't know the target list.
  // Used only by old preset code. Schedule via setTimeout directly.
  if (item.timerId) { clearTimeout(item.timerId); }
  item.timerId = setTimeout(function() {
    var idx = items.indexOf(item);
    if (idx < 0) return;
    items.splice(idx, 1);
    var folder = item.folder || item.iniswap || '';
    var cid = mode === 'client' ? activeId : item.client_id;
    if (item.sfx) sendOocCmd('/sfx ' + item.sfx + ' 0');
    sendIcRaw(cid, item.text, item.emote, folder, item.pos);
    updateQueueBadge();
    qRender();
  }, (item.delay || 0) * 1000);
}

// ─── Queue state ───
var qMode = 'client'; // 'client' | 'global'

function qSwitchTab(mode) {
  qMode = mode;
  document.querySelectorAll('.q-tab').forEach(function(el) { el.classList.toggle('active', el.textContent === (mode === 'client' ? 'Клиент' : 'Общая')); });
  qRender();
}

function qGetItems() {
  return qMode === 'client' ? (clients[activeId] ? clients[activeId].msgQueue : null) : globalQueue;
}

function qMakeItem(text, iniswap, emote, pos, sfx, delay) {
  return {text:text||'', folder:'', iniswap:iniswap||'', emote:emote||'normal', pos:pos||'', sfx:sfx||'', delay:delay||0, id:Date.now()+Math.random(), timerId:null};
}

// ─── Render ───
function qRender() {
  var list = document.getElementById('qList');
  if (!list) return;
  var items = qGetItems();
  list.innerHTML = '';
  if (!items) { list.innerHTML = '<div class="q-empty">Нет активного клиента</div>'; return; }
  if (items.length === 0) { list.innerHTML = '<div class="q-empty">Очередь пуста</div>'; }

  items.forEach(function(item, i) {
    var row = document.createElement('div');
    row.className = 'q-item';
    var info = document.createElement('div');
    info.className = 'qi-info';
    var txt = document.createElement('div');
    txt.className = 'qi-text';
    txt.textContent = item.text || '(без текста)';
    info.appendChild(txt);
    var meta = document.createElement('div');
    meta.className = 'qi-meta';
    var parts = [];
    if (item.iniswap) parts.push('👤 ' + item.iniswap);
    if (item.emote && item.emote !== 'normal') parts.push('🎭 ' + item.emote);
    if (item.pos) parts.push('📌 ' + item.pos);
    if (item.sfx) parts.push('🔊 ' + item.sfx);
    meta.textContent = parts.join(' | ') || '-';
    info.appendChild(meta);
    row.appendChild(info);

    var delE = document.createElement('span');
    delE.className = 'qi-delay';
    delE.textContent = item.delay > 0 ? (item.timerId ? '⏱ ' : '') + item.delay + 'c' : '';
    row.appendChild(delE);

    var sendBtn = document.createElement('button');
    sendBtn.className = 'qi-send';
    sendBtn.textContent = '▶';
    sendBtn.title = 'Отправить';
    sendBtn.onclick = function() { qSend(i); };
    row.appendChild(sendBtn);
    var delBtn = document.createElement('button');
    delBtn.textContent = '×';
    delBtn.title = 'Удалить';
    delBtn.onclick = function() { qRemove(i); };
    row.appendChild(delBtn);
    var upBtn = document.createElement('button');
    upBtn.textContent = '↑';
    upBtn.onclick = function() { qMove(i, -1); };
    row.appendChild(upBtn);
    var dnBtn = document.createElement('button');
    dnBtn.textContent = '↓';
    dnBtn.onclick = function() { qMove(i, 1); };
    row.appendChild(dnBtn);
    list.appendChild(row);
  });

  updateQueueBadge();
  qUpdateStatus();
}

function qUpdateStatus() {
  var items = qGetItems();
  var cnt = items ? items.length : 0;
  var el = document.getElementById('qStatus');
  if (el) el.textContent = cnt + ' сообщ. | ' + (queueRunner.active && queueRunner.type === qMode ? 'Работает' : 'Остановлено');
  var btn = document.getElementById('qStartBtn');
  if (btn) btn.textContent = (queueRunner.active && queueRunner.type === qMode) ? 'Остановить' : 'Запустить';
}

// ─── Add / Send / Remove / Move ───
function qAdd() {
  var text = document.getElementById('qText').value;
  var iniswap = document.getElementById('qIniswap').value.trim();
  var emote = document.getElementById('qEmote').value.trim() || 'normal';
  var pos = document.getElementById('qPos').value.trim();
  var sfx = document.getElementById('qSfx').value.trim();
  var delay = parseInt(document.getElementById('qDelay').value) || 0;

  var items = qGetItems();
  if (!items) return;

  if (qMode === 'client' && !iniswap) {
    var c = clients[activeId];
    if (c && c.iniswap) iniswap = c.iniswap;
  }
  // For global queue, if no iniswap and no client_id assigned, use active client
  var cid = qMode === 'global' ? (activeId != null ? activeId : null) : activeId;

  var item = qMakeItem(text, iniswap, emote, pos, sfx, delay);
  if (qMode === 'global' && cid != null) item.client_id = cid;

  items.push(item);
  if (delay > 0) qScheduleItem(item);
  qRender();
  // clear form fields
  document.getElementById('qText').value = '';
  document.getElementById('qIniswap').value = '';
  document.getElementById('qEmote').value = '';
  document.getElementById('qPos').value = '';
  document.getElementById('qSfx').value = '';
  document.getElementById('qDelay').value = '';
}

function qSend(index) {
  var items = qGetItems();
  if (!items || index >= items.length) return;
  var item = items.splice(index, 1)[0];
  if (item.timerId) { clearTimeout(item.timerId); item.timerId = null; }
  var folder = item.folder || item.iniswap || '';
  var cid = qMode === 'client' ? activeId : (item.client_id != null ? item.client_id : activeId);
  if (cid == null) return;
  // Send sfx if present
  if (item.sfx) sendOocCmd('/sfx ' + item.sfx + ' 0');
  sendIcRaw(cid, item.text, item.emote, folder, item.pos);
  qRender();
}

function qRemove(index) {
  var items = qGetItems();
  if (!items || index >= items.length) return;
  var item = items.splice(index, 1)[0];
  if (item.timerId) { clearTimeout(item.timerId); item.timerId = null; }
  qRender();
}

function qMove(index, dir) {
  var items = qGetItems();
  if (!items) return;
  var newIndex = index + dir;
  if (newIndex < 0 || newIndex >= items.length) return;
  var tmp = items[index]; items[index] = items[newIndex]; items[newIndex] = tmp;
  qRender();
}

// ─── Schedule single item (individual timer) ───
function qScheduleItem(item) {
  if (item.timerId) clearTimeout(item.timerId);
  item.timerId = setTimeout(function() {
    var items = qGetItems();
    var idx = items ? items.indexOf(item) : -1;
    if (idx < 0) return;
    items.splice(idx, 1);
    var folder = item.folder || item.iniswap || '';
    var cid = qMode === 'client' ? activeId : (item.client_id != null ? item.client_id : activeId);
    if (cid == null) return;
    if (item.sfx) sendOocCmd('/sfx ' + item.sfx + ' 0');
    sendIcRaw(cid, item.text, item.emote, folder, item.pos);
    qRender();
  }, (item.delay || 0) * 1000);
}

// ─── Queue runner (sequential playback) ───
function qStart() {
  if (queueRunner.active && queueRunner.type === qMode) { qStop(); return; }
  if (queueRunner.active) qStop();
  var items = qGetItems();
  if (!items || items.length === 0) return;
  queueRunner.active = true;
  queueRunner.type = qMode;
  qRender();
  qProcessNext();
}

function qStop() {
  queueRunner.active = false;
  queueRunner.type = null;
  if (queueRunner.timer) { clearTimeout(queueRunner.timer); queueRunner.timer = null; }
  qRender();
}

function qProcessNext() {
  if (!queueRunner.active) return;
  var items = queueRunner.type === 'client' ? (clients[activeId] ? clients[activeId].msgQueue : null) : globalQueue;
  if (!items || items.length === 0) { qStop(); return; }
  var item = items[0];
  var delay = item.delay || 0;
  queueRunner.timer = setTimeout(function() {
    if (!queueRunner.active) return;
    if (items.length === 0) { qStop(); return; }
    var shifted = items.shift();
    var folder = shifted.folder || shifted.iniswap || '';
    var cid = queueRunner.type === 'client' ? activeId : (shifted.client_id != null ? shifted.client_id : activeId);
    if (cid == null) { qStop(); return; }
    if (shifted.sfx) sendOocCmd('/sfx ' + shifted.sfx + ' 0');
    sendIcRaw(cid, shifted.text, shifted.emote, folder, shifted.pos);
    qRender();
    if (items.length > 0) qProcessNext(); else qStop();
  }, delay * 1000);
}

function qFlush() {
  var items = qGetItems();
  if (!items) return;
  while (items.length > 0) {
    var item = items.shift();
    if (item.timerId) { clearTimeout(item.timerId); item.timerId = null; }
    var folder = item.folder || item.iniswap || '';
    var cid = qMode === 'client' ? activeId : (item.client_id != null ? item.client_id : activeId);
    if (cid == null) continue;
    if (item.sfx) sendOocCmd('/sfx ' + item.sfx + ' 0');
    sendIcRaw(cid, item.text, item.emote, folder, item.pos);
  }
  qRender();
}

function qClear() {
  var items = qGetItems();
  if (!items) return;
  items.forEach(function(item) { if (item.timerId) clearTimeout(item.timerId); });
  items.length = 0;
  qRender();
}

// ─── Show/hide from old buttons ───
function showQueueModal() {
  qSwitchTab('client');
  winManager.show('win-queue');
}
function showGlobalQueueModal() {
  qSwitchTab('global');
  winManager.show('win-queue');
}
function closeQueueModal() {
  winManager.hide('win-queue');
  if (queueRunner.active) qStop();
}

// ─── Export to INI ───
function qExport() {
  var items = qGetItems();
  if (!items || items.length === 0) return;
  var lines = [];
  lines.push('[metadata]');
  lines.push('iniswap = ' + (items[0].iniswap || ''));
  lines.push('emote = ' + (items[0].emote || 'normal'));
  lines.push('pos = ' + (items[0].pos || ''));
  lines.push('');
  items.forEach(function(item, i) {
    lines.push('[message_' + i + ']');
    lines.push('text = ' + (item.text || ''));
    if (item.iniswap) lines.push('iniswap = ' + item.iniswap);
    if (item.emote && item.emote !== 'normal') lines.push('emote = ' + item.emote);
    if (item.pos) lines.push('pos = ' + item.pos);
    if (item.sfx) lines.push('sfx = ' + item.sfx);
    if (item.delay) lines.push('delay = ' + item.delay);
    lines.push('');
  });
  var blob = new Blob([lines.join('\r\n')], {type:'text/plain'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'queue_export.ini';
  a.click();
  URL.revokeObjectURL(a.href);
}

// ─── Import from INI ───
function qImport(event) {
  var file = event.target.files[0];
  if (!file) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var text = e.target.result;
    var metadata = {};
    var messages = [];
    var currentMsg = null;
    text.split(/\r?\n/).forEach(function(line) {
      line = line.trim();
      if (line.startsWith(';') || line.startsWith('#')) return;
      var m = line.match(/^\[(.+)\]$/);
      if (m) {
        var section = m[1].toLowerCase();
        if (section === 'metadata') { currentMsg = null; return; }
        if (section.startsWith('message_')) {
          if (currentMsg) messages.push(currentMsg);
          currentMsg = {};
          return;
        }
        currentMsg = null;
        return;
      }
      var kv = line.match(/^([\w_]+)\s*=\s*(.*)$/);
      if (!kv) return;
      var key = kv[1].toLowerCase();
      var val = kv[2].trim();
      if (currentMsg) {
        currentMsg[key] = val;
      } else {
        metadata[key] = val;
      }
    });
    if (currentMsg) messages.push(currentMsg);

    var items = qGetItems();
    if (!items) return;

    messages.forEach(function(msg) {
      var text = msg.text !== undefined ? msg.text : '';
      var iniswap = msg.iniswap || metadata.iniswap || '';
      var emote = msg.emote || metadata.emote || 'normal';
      var pos = msg.pos || metadata.pos || '';
      var sfx = msg.sfx || '';
      var delay = parseInt(msg.delay) || 0;
      var item = qMakeItem(text, iniswap, emote, pos, sfx, delay);
      if (qMode === 'global' && activeId != null) item.client_id = activeId;
      items.push(item);
    });
    qRender();
    document.getElementById('qImportFile').value = '';
  };
  reader.readAsText(file);
}

// ─── Sound / Music controls ──────────────────────────────────────────
function setSfx() {
  var name = document.getElementById('sfxInput').value.trim();
  if (!name) return;
  sendOocCmd('/sfx ' + name + ' 0');
}
function clearSfx() {
  sendOocCmd('/sfx_clear');
}
function playMusic() {
  var name = document.getElementById('musicInput').value.trim();
  if (!name) return;
  sendOocCmd('/play ' + name);
}
function stopMusic() {
  sendOocCmd('/play ~stop.mp3');
}
function requestSfxList() {
  send({type:'GET_SFX_LIST'});
}

function sendOocCmd(text) {
  if (!text) return;
  var c = activeId !== null ? clients[activeId] : null;
  var oocname = (c && c.oocname) ? c.oocname : '';
  var payload = {type:'OOC', text:text, ooc_name:oocname};
  if (activeId !== null) payload.client_id = activeId;
  send(payload);
}

// ─── Audio playback for music/sfx ────────────────────────────────────
// ─── Per-channel audio system (each MC channel = separate <audio>) ─────
var _channelAudio = {};
var _channelVolume = parseFloat(localStorage.getItem('player_volume') || '0.5');
function _getChannelEl(ch) {
  if (!_channelAudio[ch]) {
    _channelAudio[ch] = new Audio();
    _channelAudio[ch].volume = _channelVolume;
  }
  return _channelAudio[ch];
}

(function() {
  var slider = document.getElementById('volSlider');
  var label = document.getElementById('volLabel');
  if (slider) { slider.value = Math.round(_channelVolume * 100); }
  if (label) { label.textContent = Math.round(_channelVolume * 100) + '%'; }
  if (slider) {
    slider.addEventListener('input', function() {
      var v = parseInt(this.value) / 100;
      _channelVolume = v;
      Object.keys(_channelAudio).forEach(function(ch) { _channelAudio[ch].volume = v; });
      if (label) label.textContent = this.value + '%';
      try { localStorage.setItem('player_volume', String(v)); } catch(e) {}
    });
  }
})();

function playChannelAudio(channel, name) {
  if (!name) return;
  var el = _getChannelEl(channel);
  if (name === '~stop.mp3') {
    el.pause();
    el.src = '';
    return;
  }
  var url = name;
  if (!url.match(/^https?:\/\//i)) {
    if (assetUrl) url = assetUrl.replace(/\/+$/, '') + '/' + url.replace(/^\/+/, '');
  }
  el.src = url;
  el.loop = false;
  el.volume = _channelVolume;
  el.play().catch(function(){});
}

function playAudio(name) { playChannelAudio(0, name); }

// ─── Bookmarked rooms ─────────────────────────────────────────────
function getBookmarks() {
  try { return JSON.parse(localStorage.getItem('room_bookmarks') || '[]'); } catch(e) { return []; }
}
function saveBookmarks(bm) {
  localStorage.setItem('room_bookmarks', JSON.stringify(bm));
}
function toggleBookmark() {
  var sel = areaSelect.value;
  if (!sel) return;
  var bm = getBookmarks();
  var idx = bm.findIndex(function(b) { return b.key === sel; });
  if (idx >= 0) { bm.splice(idx, 1); } else {
    var opt = areaSelect.querySelector('option[value="' + sel.replace(/"/g,'\\"') + '"]');
    var label = opt ? opt.textContent : sel;
    bm.push({key:sel, label:label});
  }
  saveBookmarks(bm);
  renderBookmarks();
}
function renderBookmarks() {
  var el = document.getElementById('bookmarkList');
  if (!el) return;
  var bm = getBookmarks();
  var star = document.getElementById('bookmarkStar');
  if (star) {
    var cur = areaSelect.value;
    star.textContent = bm.some(function(b) { return b.key === cur; }) ? '\u2605' : '\u2606';
    star.style.color = bm.some(function(b) { return b.key === cur; }) ? '#fd0' : '#555';
  }
  el.innerHTML = '';
  bm.forEach(function(b) {
    var item = document.createElement('div');
    item.style.cssText = 'padding:2px 4px;cursor:pointer;color:#888;border-bottom:1px solid #1a1a28;display:flex;justify-content:space-between';
    var span = document.createElement('span');
    span.textContent = b.label;
    span.style.flex = '1';
    span.onclick = function() {
      areaSelect.value = b.key;
      selectArea(b.key);
    };
    item.appendChild(span);
    var del = document.createElement('span');
    del.textContent = 'X';
    del.style.cssText = 'color:#644;cursor:pointer;font-size:0.6rem;padding:0 2px';
    del.onclick = function(e) { e.stopPropagation(); var arr=getBookmarks(); var i=arr.findIndex(function(x){return x.key===b.key;}); if(i>=0){arr.splice(i,1);saveBookmarks(arr);renderBookmarks();} };
    item.appendChild(del);
    el.appendChild(item);
  });
}

// ─── Notes (auto-save to localStorage) ──────────────────────────
function initNotes() {
  var el = document.getElementById('notesBox');
  if (!el) return;
  el.value = localStorage.getItem('player_notes') || '';
  el.oninput = function() { localStorage.setItem('player_notes', el.value); };
}

// ─── Font size control ──────────────────────────────────────────
function setLogFontSize(delta) {
  var sz = parseFloat(localStorage.getItem('log_font_size')) || 0.9;
  if (delta === 0) sz = 0.9;
  else sz = Math.max(0.6, Math.min(1.8, sz + delta));
  localStorage.setItem('log_font_size', String(sz));
  document.getElementById('icLog').style.fontSize = sz + 'rem';
  document.getElementById('oocLog').style.fontSize = sz + 'rem';
}
function initLogFontSize() {
  var sz = parseFloat(localStorage.getItem('log_font_size')) || 0.9;
  document.getElementById('icLog').style.fontSize = sz + 'rem';
  document.getElementById('oocLog').style.fontSize = sz + 'rem';
}

// ─── Client color tags ─────────────────────────────────────────
function getClientColor(id) {
  var CLIENT_COLORS = ['#7af','#f77','#7f7','#ff7','#f7f','#77f','#fa7','#7ff','#a7f','#f88'];
  try {
    var cols = JSON.parse(localStorage.getItem('client_colors') || '{}');
    if (cols[id]) return cols[id];
  } catch(e) {}
  return CLIENT_COLORS[parseInt(id) % CLIENT_COLORS.length];
}

// ─── Clear logs ────────────────────────────────────────────────
function clearIcLog() {
  var c = activeId !== null ? clients[activeId] : null;
  if (c) c.icMessages = [];
  document.getElementById('icLog').innerHTML = '';
}
function clearOocLog() {
  var c = activeId !== null ? clients[activeId] : null;
  if (c) c.oocMessages = [];
  document.getElementById('oocLog').innerHTML = '';
}

// ─── IC text color formatting ─────────────────────────────────────
var IC_COLORS = [
  {pre:'`', suf:'`', color:'#7f7', label:'Зелёный'},
  {pre:'~', suf:'~', color:'#f77', label:'Красный'},
  {pre:'|', suf:'|', color:'#fa7', label:'Оранжевый'},
  {pre:'(', suf:')', color:'#7af', label:'Синий'},
  {pre:'\u00ba', suf:'\u00ba', color:'#fd7', label:'Жёлтый'},
  {pre:'\u2116', suf:'\u2116', color:'#ff6eb4', label:'Розовый'},
  {pre:'\u221a', suf:'\u221a', color:'#7ff', label:'Голубой'},
  {pre:'[', suf:']', color:'#999', label:'Серый'},
  {pre:'&', suf:'&', color:'#000', label:'Чёрный'},
];
function formatIcText(s) {
  if (!s) return s;
  for (var i = 0; i < IC_COLORS.length; i++) {
    var c = IC_COLORS[i];
    var re = new RegExp(escRegex(c.pre) + '(.+?)' + escRegex(c.suf), 'g');
    s = s.replace(re, '<span style="color:' + c.color + '">$1</span>');
  }
  return s;
}
function escRegex(s) {
  if (s === '[') return '\\[';
  if (s === ']') return '\\]';
  if (s === '(') return '\\(';
  if (s === ')') return '\\)';
  if (s === '|') return '\\|';
  if (s === '\\') return '\\\\';
  if (s === '^') return '\\^';
  if (s === '$') return '\\$';
  if (s === '.') return '\\.';
  if (s === '*') return '\\*';
  if (s === '+') return '\\+';
  if (s === '?') return '\\?';
  if (s === '{') return '\\{';
  if (s === '}') return '\\}';
  return s;
}
function initColorToolbar() {
  var sel = document.getElementById('colorSelect');
  if (!sel) return;
  sel.innerHTML = '<option value="">— цвет текста —</option>';
  IC_COLORS.forEach(function(c) {
    var opt = document.createElement('option');
    opt.value = c.pre;
    opt.textContent = c.label;
    opt.style.color = c.color;
    sel.appendChild(opt);
  });
}
function applyIcColor(sel) {
  if (!sel.value) return;
  var c = IC_COLORS.find(function(x) { return x.pre === sel.value; });
  if (!c) { sel.value = ''; return; }
  var inp = document.getElementById('icInput');
  if (!inp) { sel.value = ''; return; }
  var txt = inp.value;
  var start = inp.selectionStart, end = inp.selectionEnd;
  if (start !== end) {
    var selected = txt.substring(start, end);
    inp.value = txt.substring(0, start) + c.pre + selected + c.suf + txt.substring(end);
    inp.selectionStart = inp.selectionEnd = end + c.pre.length + c.suf.length;
  } else {
    if (txt) inp.value = c.pre + txt + c.suf;
    else inp.value = c.pre + c.suf;
    var p = c.pre.length;
    inp.selectionStart = inp.selectionEnd = p;
  }
  sel.value = '';
  inp.focus();
}

// ─── Queue Presets ────────────────────────────────────────────────────
var PRESET_KEY = 'focc_queue_presets2';

function getQueuePresets() {
  try { return JSON.parse(localStorage.getItem(PRESET_KEY)) || {}; } catch(e) { return {}; }
}

function saveQueuePresets(presets) {
  localStorage.setItem(PRESET_KEY, JSON.stringify(presets));
}

function saveQueuePreset(name, item) {
  var presets = getQueuePresets();
  presets[name] = {text:item.text||'', emote:item.emote||'normal', folder:item.folder||'', delay:item.delay||0};
  saveQueuePresets(presets);
  appendOoc('[Preset] Сохранён: "' + name + '"');
}

function deleteQueuePreset(name) {
  var presets = getQueuePresets();
  delete presets[name];
  saveQueuePresets(presets);
}

function loadQueuePreset(name) {
  var presets = getQueuePresets();
  var p = presets[name];
  if (!p) return;
  icInput.value = p.text || '';
  emoteInput.value = p.emote || 'normal';
  emoteFolder.value = p.folder || '';
  document.getElementById('delayInput').value = p.delay || '';
  icInput.focus();
}

function sendQueuePreset(name) {
  var presets = getQueuePresets();
  var p = presets[name];
  if (!p) return;
  var c = activeId !== null ? clients[activeId] : null;
  if (!c) return;
  sendIcRaw(activeId, p.text, p.emote, p.folder);
}

function queuePresetNow(name) {
  var presets = getQueuePresets();
  var p = presets[name];
  if (!p) return;
  var c = activeId !== null ? clients[activeId] : null;
  if (!c) return;
  if (!c.msgQueue) c.msgQueue = [];
  var item = {text:p.text||'', emote:p.emote||'normal', folder:p.folder||'', iniswap:p.iniswap||c.iniswap||'', pos:p.pos||'', sfx:p.sfx||'', delay:p.delay||0, id:Date.now()+Math.random(), timerId:null};
  c.msgQueue.push(item);
  updateQueueBadge();
  qRender();
}

function handlePresetCmd(text) {
  var parts = text.split(' ');
  var cmd = parts[1];
  var name = parts.slice(2).join(' ');
  if (cmd === 'save' && name) {
    var c = activeId !== null ? clients[activeId] : null;
    if (!c) { appendOoc('[Preset] Нет активного клиента'); return; }
    var item = {text:icInput.value, emote:emoteInput.value.trim()||'normal', folder:emoteFolder.value.trim()||c.iniswap||'', delay:parseInt(document.getElementById('delayInput').value)||0};
    saveQueuePreset(name, item);
  } else if (cmd === 'send' && name) {
    sendQueuePreset(name);
    appendOoc('[Preset] Отправлен: "' + name + '"');
  } else if (cmd === 'queue' && name) {
    queuePresetNow(name);
    appendOoc('[Preset] Добавлен в очередь: "' + name + '"');
  } else if (cmd === 'delete' && name) {
    deleteQueuePreset(name);
    appendOoc('[Preset] Удалён: "' + name + '"');
  } else if (cmd === 'list' || !cmd) {
    var presets = getQueuePresets();
    var names = Object.keys(presets);
    if (names.length === 0) {
      appendOoc('[Preset] Нет сохранённых пресетов. Используй: /preset save название');
    } else {
      appendOoc('[Preset] Пресеты: ' + names.join(', '));
    }
  } else {
    appendOoc('[Preset] Команды: save|send|queue|delete|list. Пример: /preset save мой_текст');
  }
}

function appendOoc(msg) {
  var el = document.createElement('div');
  el.className = 'msg';
  el.style.cssText = 'color:var(--cp-cyan);font-size:0.75rem;padding:2px 0';
  el.textContent = msg;
  oocLog.appendChild(el);
  oocLog.scrollTop = oocLog.scrollHeight;
}

function savePresetFromInput() {
  var c = activeId !== null ? clients[activeId] : null;
  if (!c) { appendOoc('[Preset] Нет активного клиента'); return; }
  var name = prompt('Название пресета:');
  if (!name || !name.trim()) return;
  name = name.trim().replace(/\s+/g, '_');
  var item = {text:icInput.value, emote:emoteInput.value.trim()||'normal', folder:emoteFolder.value.trim()||c.iniswap||'', delay:parseInt(document.getElementById('delayInput').value)||0};
  saveQueuePreset(name, item);
}

// ─── Client Presets ──────────────────────────────────────────────────
var CLIENT_PRESET_KEY = 'focc_client_presets';

function getClientPresets() {
  try { return JSON.parse(localStorage.getItem(CLIENT_PRESET_KEY) || '[]'); } catch(e) { return []; }
}

function saveClientPresets(list) {
  localStorage.setItem(CLIENT_PRESET_KEY, JSON.stringify(list));
}

function saveClientPreset() {
  var name = document.getElementById('presetNameInput').value.trim();
  if (!name) { appendOoc('[Пресеты] Введите имя пресета'); return; }
  var ids = Object.keys(clients).map(Number).sort(function(a,b){return a-b;});
  var preset = {name: name, clients: []};
  ids.forEach(function(id) {
    var c = clients[id];
    preset.clients.push({
      hub_id: c.hub_id,
      area_id: c.area_id,
      char_id: c.char_id,
      iniswap: c.iniswap || '',
      icname: c.icname || '',
      oocname: c.oocname || ''
    });
  });
  var list = getClientPresets();
  // replace if name exists
  var idx = list.findIndex(function(p) { return p.name === name; });
  if (idx >= 0) list[idx] = preset;
  else list.push(preset);
  saveClientPresets(list);
  document.getElementById('presetNameInput').value = '';
  renderClientPresets();
  appendOoc('[Пресеты] Пресет "' + name + '" сохранён (' + preset.clients.length + ' клиентов)');
}

function loadClientPreset(name) {
  var list = getClientPresets();
  var preset = list.find(function(p) { return p.name === name; });
  if (!preset) { appendOoc('[Пресеты] Пресет "' + name + '" не найден'); return; }
  // clear stale restore state
  _pendingCharMap = {};
  _pendingRestoreMap = {};
  // disconnect all current clients
  var ids = Object.keys(clients).map(Number);
  ids.forEach(function(id) { disconnectClient(id); });
  // connect needed clients
  for (var i = 0; i < preset.clients.length; i++) {
    connectClient();
  }
  // store preset data for restoration
  _pendingRestores = preset.clients.slice();
  renderClientPresets();
}

function deleteClientPreset(name) {
  var list = getClientPresets().filter(function(p) { return p.name !== name; });
  saveClientPresets(list);
  renderClientPresets();
}

function renderClientPresets() {
  var el = document.getElementById('presetList');
  if (!el) return;
  var list = getClientPresets();
  if (list.length === 0) {
    el.innerHTML = '<div style="color:var(--cp-text-dim);padding:10px;text-align:center">Нет сохранённых пресетов</div>';
    return;
  }
  el.innerHTML = '';
  list.forEach(function(p) {
    var div = document.createElement('div');
    div.style.cssText = 'display:flex;align-items:center;gap:6px;padding:5px 6px;border-bottom:1px solid rgba(255,255,255,0.03);transition:background 0.1s';
    div.onmouseenter = function() { div.style.background = 'rgba(0,240,255,0.04)'; };
    div.onmouseleave = function() { div.style.background = 'transparent'; };
    var nameSpan = document.createElement('span');
    nameSpan.style.cssText = 'flex:1;color:var(--cp-cyan);cursor:pointer;font-weight:500';
    nameSpan.textContent = p.name + ' (' + p.clients.length + ' кл.)';
    nameSpan.onclick = function() { loadClientPreset(p.name); };
    div.appendChild(nameSpan);
    var loadBtn = document.createElement('button');
    loadBtn.textContent = 'Загрузить';
    loadBtn.style.cssText = 'font-size:0.75rem;background:var(--cp-btn-bg);border:1px solid rgba(0,240,255,0.1);border-radius:3px;padding:3px 8px;color:var(--cp-cyan);cursor:pointer';
    loadBtn.onclick = function() { loadClientPreset(p.name); };
    div.appendChild(loadBtn);
    var delBtn = document.createElement('button');
    delBtn.textContent = '✕';
    delBtn.style.cssText = 'font-size:0.7rem;background:none;border:none;color:#633;cursor:pointer;padding:2px 4px';
    delBtn.onclick = function() { if (confirm('Удалить пресет "' + p.name + '"?')) deleteClientPreset(p.name); };
    div.appendChild(delBtn);
    el.appendChild(div);
  });
}

function handleStatsCmd(inputEl) {
  var c = activeId !== null ? clients[activeId] : null;
  if (!c) { appendOoc('[Stats] Нет активного клиента'); return; }
  // Fetch from server-side API that doesn't require auth (internal endpoint)
  send({type:'GET_STATS', client_id:activeId});
}

// ─── Session Logs ─────────────────────────────────────────────────────
var _sessionLogsCache = null;

function fetchSessionLogs() {
  _sessionLogsCache = null;
  renderSessionLogs();
  send({type:'GET_SESSION_LOGS'});
}

function renderSessionLogs() {
  var body = document.getElementById('logsBody');
  if (!body) return;
  var q = (document.getElementById('logsSearch')?.value || '').toLowerCase();
  var filter = document.getElementById('logsFilter')?.value || 'all';
  var msgs = _sessionLogsCache || [];
  if (q || filter !== 'all') {
    msgs = msgs.filter(function(m) {
      if (filter !== 'all' && m.type !== filter) return false;
      if (q && !(m.message||'').toLowerCase().includes(q) && !(m.char_name||'').toLowerCase().includes(q) && !(m.ooc_name||'').toLowerCase().includes(q)) return false;
      return true;
    });
  }
  body.innerHTML = '';
  if (msgs.length === 0) {
    body.innerHTML = '<div style="color:#555;padding:20px;text-align:center;font-size:0.7rem">' + (_sessionLogsCache === null ? 'Загрузка...' : 'Нет сообщений') + '</div>';
    return;
  }
  msgs.forEach(function(m) {
    var row = document.createElement('div');
    row.style.cssText = 'padding:3px 0;border-bottom:1px solid rgba(255,255,255,0.02);font-size:0.68rem';
    var time = document.createElement('span');
    time.style.cssText = 'color:#555;margin-right:5px;font-size:0.6rem';
    try {
      var d = m.time.split('.')[0].replace('T', ' ');
      time.textContent = d.slice(11, 19);
    } catch(e) { time.textContent = ''; }
    row.appendChild(time);
    var tag = document.createElement('span');
    tag.style.cssText = 'font-size:0.6rem;padding:1px 4px;border-radius:2px;margin-right:4px;background:rgba(0,240,255,0.06);color:var(--cp-text-dim)';
    tag.textContent = m.type === 'chat.ic' ? 'IC' : 'OOC';
    row.appendChild(tag);
    var name = document.createElement('span');
    name.style.cssText = 'color:var(--cp-cyan);font-weight:600;margin-right:4px';
    name.textContent = m.type === 'chat.ic' ? (m.char_name || '?') : (m.ooc_name || '?');
    row.appendChild(name);
    var areaEl = document.createElement('span');
    areaEl.style.cssText = 'color:#555;font-size:0.6rem;margin-right:4px';
    areaEl.textContent = m.area ? '[' + m.area + ']' : '';
    row.appendChild(areaEl);
    var msg = document.createElement('span');
    msg.style.cssText = 'color:var(--cp-text)';
    msg.textContent = m.message || '';
    row.appendChild(msg);
    body.appendChild(row);
  });
}

initNotes();
initLogFontSize();
initColorToolbar();
renderBookmarks();

icInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') sendIC(); });
icInput.addEventListener('input', function() {
  if (this.value.length > 0) {
    var now = Date.now();
    if (!typingState || now - typingLastSend > 1000) {
      typingState = true;
      typingLastSend = now;
      send({type:'TYPING', state:1, emote:emoteInput.value.trim()||'normal'});
    }
  }
  if (typingStopTimer) clearTimeout(typingStopTimer);
  typingStopTimer = setTimeout(function() {
    typingState = false;
    send({type:'TYPING', state:0});
  }, 3000);
});
oocInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') sendOOC(); });
window.addEventListener('beforeunload', function() {
  localStorage.setItem('player_client_count', String(Object.keys(clients).length));
  var states = [];
  var ids = Object.keys(clients).map(Number).sort(function(a,b){return a-b;});
  ids.forEach(function(id) {
    var c = clients[id];
    states.push({char_id: c.char_id, hub_id: c.hub_id, area_id: c.area_id, iniswap: c.iniswap || '', icname: c.icname || '', oocname: c.oocname || ''});
  });
  localStorage.setItem('player_client_states', JSON.stringify(states));
});

// Auto-show onboarding on first visit
if (!localStorage.getItem('onboarding_seen')) {
  setTimeout(showOnboarding, 500);
  localStorage.setItem('onboarding_seen', '1');
}

// ─── Window layout persistence ──────────────────────────────────────────
(function() {
  var KEY = 'win_layout';
  function save() {
    var out = {};
    document.querySelectorAll('.window').forEach(function(el) {
      if (el.classList.contains('hidden-win')) return;
      var r = el.getBoundingClientRect();
      if (r.width < 50 || r.height < 30) return;
      out[el.id] = { left: Math.round(r.left), top: Math.round(r.top), width: Math.round(r.width), height: Math.round(r.height) };
    });
    try { localStorage.setItem(KEY, JSON.stringify(out)); } catch(e) {}
  }
  function restore() {
    var saved;
    try { saved = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch(e) { return; }
    if (!saved) return;
    document.querySelectorAll('.window').forEach(function(el) {
      var s = saved[el.id];
      if (!s || !s.width || !s.height) return;
      el.style.left = s.left + 'px';
      el.style.top = s.top + 'px';
      el.style.width = s.width + 'px';
      el.style.height = s.height + 'px';
    });
  }
  window.addEventListener('beforeunload', save);
  var wm = winManager;
  var _origUp = wm._boundUp;
  wm._boundUp = function(e) { _origUp(e); save(); };
  var _origRup = wm._boundResizeUp;
  wm._boundResizeUp = function(e) { _origRup(e); save(); };
  restore();
})();

connect();

// fallback: ensure quick-swap list renders even if WS events didn't trigger it
setTimeout(renderQuickSwap, 1500);
setTimeout(renderQuickSwap, 3000);

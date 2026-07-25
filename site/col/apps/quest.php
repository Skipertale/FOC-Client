<?php
// apps/quest.php — Quest Player
// UI: upgraded cyber/HUD dialog (data/logic from quest_api.php).
?><div id="quest-root">
  <div class="q-stage">
    <div id="q-bg" class="q-bg"></div>
    <img id="q-s1" class="q-sprite q-sprite1" alt="">
    <img id="q-s2" class="q-sprite q-sprite2" alt="">
    <div id="q-popup" class="q-popup" style="display:none;">
      <img id="q-popup-img" alt="">
    </div>
  </div>

  <div id="q-banner" class="q-banner">
    <strong>ВНИМАНИЕ</strong> Сейчас нет активного квеста. В админке включи проект (СТАТУС → ВКЛ).
  </div>

  <div id="q-dialog" class="q-dialog">
    <div class="q-dialog-inner">
      <div class="q-head">
        <div class="q-title">
          <span class="q-title-chip"></span>
          <span id="q-name" class="q-title-text">[SYSTEM_BOOT]</span>
        </div>
        <div class="q-head-actions">
          <button id="q-gear" class="q-gear" title="Настройки" style="display:none;">⚙</button>
        </div>
      </div>

      <div class="q-body">
        <div id="q-text" class="q-text"></div>
      </div>

      <div class="q-foot">
        <div id="q-choices" class="q-choices"></div>
        <div id="q-hint" class="q-hint">клик по тексту — мгновенно показать полностью</div>
      </div>
    </div>
  </div>
</div>

<style>
:root{
  --q-cyan:#00ffcc;
  --q-cyan2:#00b8ff;
  --q-ink:#071014;
  --q-red:#ff3b3b;
}

#quest-root{
  position:relative;
  width:100%;
  height:100%;
  overflow:hidden;
  background:#050505;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
}

/* Stage */
.q-stage{ position:absolute; inset:0; }
.q-bg{
  position:absolute; inset:0;
  background-size:cover;
  background-position:center;
  filter:saturate(1.08) contrast(1.03);
  transform: translateZ(0);
}
.q-bg::after{
  content:"";
  position:absolute; inset:0;
  background:
    radial-gradient(1200px 600px at 70% 85%, rgba(0,255,204,0.16), rgba(0,0,0,0) 60%),
    radial-gradient(900px 500px at 20% 20%, rgba(0,184,255,0.10), rgba(0,0,0,0) 55%),
    linear-gradient(to top, rgba(0,0,0,0.45), rgba(0,0,0,0.08) 60%, rgba(0,0,0,0.22));
  pointer-events:none;
}

.q-sprite{
  position:absolute; bottom:0;
  max-height:92%; max-width:48%;
  width:auto; height:auto;
  pointer-events:none;
  opacity:0;
  transition: opacity 180ms linear, filter 180ms linear, transform 180ms linear;
  filter: drop-shadow(0 0 14px rgba(0,0,0,0.65));
  transform: translateZ(0);
}
.q-sprite.show{ opacity:1; }
.q-sprite.left{ left:6%; }
.q-sprite.center{ left:50%; transform: translateX(-50%) translateZ(0); }
.q-sprite.right{ right:6%; }
.q-dim{ filter: brightness(0.45) saturate(0.8) drop-shadow(0 0 14px rgba(0,0,0,0.65)); }

/* Dialog (HUD) */
.q-dialog{
  position:absolute; left:18px; right:18px; bottom:16px;
  border-radius: 14px;
  background: rgba(7,16,20,0.62);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  box-shadow:
    0 12px 40px rgba(0,0,0,0.55),
    0 0 0 1px rgba(0,255,204,0.10),
    inset 0 0 0 1px rgba(255,255,255,0.04);
  overflow:hidden;
}

.q-dialog::before{
  content:"";
  position:absolute; inset:0;
  padding:1px;
  border-radius: 14px;
  background: linear-gradient(135deg,
    rgba(0,255,204,0.95),
    rgba(0,184,255,0.65) 38%,
    rgba(255,255,255,0.10) 55%,
    rgba(0,255,204,0.40) 100%);
  -webkit-mask:
    linear-gradient(#000 0 0) content-box,
    linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
  mask-composite: exclude;
  pointer-events:none;
  opacity:0.75;
}

.q-dialog::after{
  /* scanlines + subtle noise */
  content:"";
  position:absolute; inset:0;
  background:
    repeating-linear-gradient(
      to bottom,
      rgba(255,255,255,0.03),
      rgba(255,255,255,0.03) 1px,
      rgba(0,0,0,0) 3px,
      rgba(0,0,0,0) 6px
    );
  mix-blend-mode: overlay;
  opacity:0.30;
  pointer-events:none;
}

.q-dialog-inner{ position:relative; padding:14px 16px 12px; }

.q-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:10px;
}
.q-title{
  display:flex; align-items:center; gap:10px;
  min-width: 0;
}
.q-title-chip{
  width:12px; height:12px;
  border-radius: 3px;
  background: radial-gradient(circle at 30% 30%, rgba(255,255,255,0.9), rgba(0,255,204,0.85) 40%, rgba(0,184,255,0.5) 100%);
  box-shadow: 0 0 16px rgba(0,255,204,0.45), 0 0 24px rgba(0,184,255,0.20);
}
.q-title-text{
  color: rgba(230,255,249,0.92);
  font-weight: 800;
  letter-spacing: 1.2px;
  text-transform: uppercase;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-shadow: 0 0 18px rgba(0,255,204,0.10);
}

.q-gear{
  background: rgba(0,255,204,0.06);
  border: 1px solid rgba(0,255,204,0.45);
  color: rgba(230,255,249,0.92);
  padding:4px 10px;
  border-radius: 10px;
  cursor:pointer;
  transition: transform 120ms ease, background 120ms ease, box-shadow 120ms ease;
}
.q-gear:hover{
  transform: translateY(-1px);
  background: rgba(0,255,204,0.10);
  box-shadow: 0 0 18px rgba(0,255,204,0.14);
}

.q-body{
  position:relative;
  padding: 10px 12px;
  border-radius: 12px;
  background: linear-gradient(180deg, rgba(0,0,0,0.30), rgba(0,0,0,0.10));
  box-shadow: inset 0 0 0 1px rgba(0,255,204,0.06);
}

.q-text{
  color:#e6fff9;
  font-size:14px;
  line-height:1.55;
  min-height:54px;
  white-space:pre-wrap;
  word-break: break-word;
}

#q-dialog.typing #q-text::after{
  content:"▍";
  margin-left: 2px;
  color: rgba(0,255,204,0.85);
  animation: qCaret 900ms steps(1) infinite;
}
@keyframes qCaret{ 0%,50%{opacity:1} 51%,100%{opacity:0} }

.q-foot{ margin-top:10px; display:flex; flex-direction:column; gap:8px; }

.q-choices{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}

.q-btn{
  position:relative;
  border-radius: 12px;
  border: 1px solid rgba(0,255,204,0.45);
  color: rgba(230,255,249,0.92);
  padding:10px 14px;
  cursor:pointer;
  background:
    radial-gradient(120px 40px at 25% 15%, rgba(0,255,204,0.18), rgba(0,0,0,0) 60%),
    linear-gradient(180deg, rgba(0,255,204,0.06), rgba(0,0,0,0.08));
  text-transform: uppercase;
  font-weight: 700;
  font-size: 12.5px;
  letter-spacing: 0.9px;
  transition: transform 120ms ease, background 120ms ease, box-shadow 120ms ease, border-color 120ms ease;
}
.q-btn::after{
  content:"";
  position:absolute;
  inset:0;
  border-radius: 12px;
  box-shadow: 0 0 0 0 rgba(0,255,204,0);
  pointer-events:none;
}
.q-btn:hover{
  transform: translateY(-1px);
  border-color: rgba(0,255,204,0.70);
  background:
    radial-gradient(140px 44px at 25% 15%, rgba(0,255,204,0.26), rgba(0,0,0,0) 62%),
    linear-gradient(180deg, rgba(0,255,204,0.10), rgba(0,0,0,0.08));
  box-shadow: 0 0 22px rgba(0,255,204,0.12);
}
.q-btn:active{
  transform: translateY(0px);
  box-shadow: 0 0 14px rgba(0,255,204,0.10);
}

.q-hint{
  color: rgba(230,255,249,0.44);
  font-size: 12px;
  letter-spacing: 0.2px;
  text-transform: lowercase;
}

/* Banner */
.q-banner{
  position:absolute; left:18px; right:18px; top:18px;
  border-radius: 12px;
  border:1px solid rgba(255,59,59,0.85);
  background: linear-gradient(90deg, rgba(255,0,0,0.35), rgba(7,16,20,0.75));
  padding:12px 14px;
  color:#fff;
  letter-spacing:1px;
  text-transform: uppercase;
  display:none;
  box-shadow: 0 8px 26px rgba(0,0,0,0.45);
}

/* Popup */
.q-popup{
  position:absolute; inset:0;
  display:flex; align-items:center; justify-content:center;
  background: rgba(0,0,0,0.0);
  pointer-events:none;
}
.q-popup img{
  max-width:85%;
  max-height:85%;
  image-rendering:auto;
  filter: drop-shadow(0 0 22px rgba(0,0,0,0.85));
}

/* FX */
.q-fade{ animation: qFadeIn var(--fx,700ms) ease-out both; }
@keyframes qFadeIn{ from{opacity:0;} to{opacity:1;} }
.q-flash{ animation: qFlash var(--fx,700ms) ease-out both; }
@keyframes qFlash{ 0%{filter:brightness(1);} 20%{filter:brightness(2.4);} 100%{filter:brightness(1);} }
.q-zoom{ animation: qZoom var(--fx,700ms) ease-out both; }
@keyframes qZoom{ from{transform:scale(1.06);} to{transform:scale(1);} }
.q-slide{ animation: qSlide var(--fx,700ms) ease-out both; }
@keyframes qSlide{ from{transform:translateX(18px);} to{transform:translateX(0);} }
.q-shake{ animation: qShake var(--fx,700ms) linear both; }
@keyframes qShake{
  0%,100%{transform:translateX(0)}
  10%{transform:translateX(-6px)} 20%{transform:translateX(6px)}
  30%{transform:translateX(-5px)} 40%{transform:translateX(5px)}
  50%{transform:translateX(-3px)} 60%{transform:translateX(3px)}
}

/* Small screens */
@media (max-width: 520px){
  .q-dialog{ left:10px; right:10px; bottom:10px; }
  .q-dialog-inner{ padding:12px 12px 10px; }
  .q-btn{ width: 100%; text-align: left; }
}

/* Overlay choices for silent scenes */
#q-choices-overlay{
  position:absolute;
  left:50%;
  bottom:28px;
  transform:translateX(-50%);
  width:min(560px, calc(100% - 40px));
  z-index:40;
  display:none;
  flex-direction:column;
  gap:10px;
}
#q-choices-overlay .q-btn{ width:100%; text-align:left; }
#q-silent-hint{
  position:absolute;
  left:18px;
  bottom:14px;
  z-index:45;
  font-size:11px;
  letter-spacing:0.6px;
  color:rgba(255,255,255,0.45);
  text-transform:uppercase;
  user-select:none;
  display:none;
}

</style>

<script type="text/plain" id="quest-js">
(function(){
  var root = document.getElementById('quest-root');
  if(!root) return;

  function $(id){ return document.getElementById(id); }

  var bgEl = $('q-bg');
  var s1 = $('q-s1');
  var s2 = $('q-s2');
  var banner = $('q-banner');
  var dialog = $('q-dialog');
  var nameEl = $('q-name');
  var textEl = $('q-text');
  var choicesEl = $('q-choices');
  
  // Separate overlay for choices when dialog is hidden (silent scenes)
  var choicesOverlay = (function(){
    var el = document.getElementById('q-choices-overlay');
    if(!el){
      el = document.createElement('div');
      el.id = 'q-choices-overlay';
      el.className = 'q-choices';
      root.appendChild(el);
    }
    return el;
  })();

  var silentHintEl = (function(){
    var el = document.getElementById('q-silent-hint');
    if(!el){
      el = document.createElement('div');
      el.id = 'q-silent-hint';
      el.textContent = 'клик — далее';
      root.appendChild(el);
    }
    return el;
  })();

var gearBtn = $('q-gear');
  var popupWrap = $('q-popup');
  var popupImg = $('q-popup-img');

  
  // One-tap next key handler for silent scenes (when dialog is hidden)
  var stageNextKey = null;
  root.addEventListener('click', function(e){
    if(!stageNextKey) return;
    // ignore clicks on buttons/controls
    if(e.target && (e.target.closest && (e.target.closest('.q-btn') || e.target.closest('#q-gear')))) return;
    var nk = stageNextKey;
    stageNextKey = null;
    silentHintEl.style.display = 'none';
    fetchScene(nk).then(renderScene).catch(function(err){
      nameEl.textContent='[SYSTEM]';
      typeText('Ошибка: ' + (err && err.message ? err.message : err));
    });
  }, true);

// OS BGM ducking (bg-music in os.php)
  var osBgm = document.getElementById('bg-music');
  var osRestoreVol = null;
  function fadeAudio(a, to, ms, done){
    if(!a) { if(done) done(); return; }
    var from = (typeof a.volume === 'number') ? a.volume : 1;
    var t0 = Date.now();
    var tick = function(){
      var p = Math.min(1, (Date.now()-t0)/ms);
      a.volume = from + (to-from)*p;
      if(p<1) requestAnimationFrame(tick);
      else if(done) done();
    };
    if(ms<=0){ a.volume = to; if(done) done(); return; }
    requestAnimationFrame(tick);
  }
  function duckOsBgm(){
    // If OS provides its own BGM bridge, let it handle ducking.
    if(window.__COL_OS_bgm_pause) return;
    if(!osBgm) return;
    if(osRestoreVol === null) osRestoreVol = (typeof osBgm.volume==='number') ? osBgm.volume : 1;
    fadeAudio(osBgm, 0, 700, function(){ try{ osBgm.pause(); }catch(e){} });
  }
  function restoreOsBgm(){
    // If OS provides its own BGM bridge, let it handle restore.
    if(window.__COL_OS_bgm_resume) return;
    if(!osBgm) return;
    var target = (osRestoreVol === null) ? ((typeof osBgm.volume==='number')?osBgm.volume:1) : osRestoreVol;
    try{ osBgm.volume = 0; }catch(e){}
    try{ osBgm.play(); }catch(e){}
    fadeAudio(osBgm, target, 700);
  }

  // Quest BGM
  var bgm = new Audio();
  bgm.loop = true;
  var currentBgm = '';
  var userVol = parseFloat(localStorage.getItem('quest_volume') || '0.2');
  if(!(userVol>=0 && userVol<=1)) userVol = 0.2;
  function applyVol(){ bgm.volume = userVol; }
  applyVol();

  function stopQuestAudio(){
    try{ bgm.pause(); }catch(e){}
    try{ bgm.src=''; bgm.load(); }catch(e){}
  }

  function isElInDom(el){ return el && document.body && document.body.contains(el); }
  // Watch removal/close
  var mo = new MutationObserver(function(){
    if(!isElInDom(root)){
      mo.disconnect();
      stopQuestAudio();
      restoreOsBgm();
    }
  });
  mo.observe(document.body, {childList:true, subtree:true});

  duckOsBgm();

  // API helper
  // --- API helper (auto-detect correct quest_api.php path; avoids 404 when quest.php is inside /apps)
var __apiBases = (function(){
  try{
    var p = String(location.pathname||'');
    var out = [];
    // If we're under ".../apps/quest.php" then base is everything before "/apps/"
    if(p.indexOf('/apps/') !== -1){
      var base = p.split('/apps/')[0] || '';
      out.push(base + '/quest_api.php');      // e.g. /col/quest_api.php
      out.push(base + '/apps/quest_api.php'); // e.g. /col/apps/quest_api.php
    }
    // Also try relative-to-current-dir and absolute fallbacks
    out.push('quest_api.php');     // same dir
    out.push('../quest_api.php');  // parent dir
    out.push('/quest_api.php');    // site root
    out.push('/apps/quest_api.php');

    // Unique
    var uniq = [];
    var seen = {};
    for(var i=0;i<out.length;i++){
      var u = out[i];
      if(!u) continue;
      if(seen[u]) continue;
      seen[u]=1;
      uniq.push(u);
    }
    return uniq;
  }catch(e){
    return ['quest_api.php','../quest_api.php','/quest_api.php','/apps/quest_api.php'];
  }
})();

function __buildApiUrls(requestUrl){
  var u = String(requestUrl||'');
  // Accept: "quest_api.php?..."; "../quest_api.php?..."; "/x/quest_api.php?..."
  var idx = u.indexOf('quest_api.php');
  if(idx === -1){
    // If caller passed only a querystring, support it too.
    if(u.charAt(0) === '?') u = 'quest_api.php' + u;
    else if(u.indexOf('action=') !== -1 && u.indexOf('?') === -1) u = 'quest_api.php?' + u;
    idx = u.indexOf('quest_api.php');
  }
  var suffix = (idx !== -1) ? u.slice(idx + 'quest_api.php'.length) : (u || '');
  // ensure suffix begins with ? or empty
  if(suffix && suffix.charAt(0) !== '?' && suffix.charAt(0) !== '&' && suffix.charAt(0) !== '#'){
    if(suffix.indexOf('=') !== -1) suffix = '?' + suffix;
  }
  var urls = [];
  for(var i=0;i<__apiBases.length;i++){
    var base = __apiBases[i];
    try{ base = new URL(base, location.href).toString(); }catch(e){}
    // Ensure ends with quest_api.php
    if(base.indexOf('quest_api.php') === -1){
      base = base.replace(/\/$/,'') + '/quest_api.php';
    }
    urls.push(base + suffix);
  }
  // Unique again
  var out = [];
  var seen = {};
  for(var j=0;j<urls.length;j++){
    var uu = urls[j];
    if(seen[uu]) continue;
    seen[uu]=1;
    out.push(uu);
  }
  return out;
}

function apiGet(url){
  var candidates = __buildApiUrls(url);
  // Try candidates until we get JSON. If server returns HTML 404, we'll skip it.
  return (function tryOne(i){
    if(i >= candidates.length){
      return Promise.reject(new Error('API not reachable: quest_api.php'));
    }
    return fetch(candidates[i], {credentials:'include'})
      .then(function(r){
        if(!r.ok){
          return r.text().then(function(t){
            var err = new Error('HTTP ' + r.status);
            err.httpStatus = r.status;
            err.body = t;
            throw err;
          });
        }
        var ct = (r.headers.get('content-type')||'').toLowerCase();
        if(ct.indexOf('application/json') === -1){
          return r.text().then(function(t){
            var err = new Error('API not JSON');
            err.body = t;
            throw err;
          });
        }
        return r.json();
      })
      .catch(function(e){
        return tryOne(i+1);
      });
  })(0);
}// typing
  var typingTimer = null;
  var typingRaw = '';
  var typingPlain = '';
  var typingIdx = 0;

  function escapeHtml(s){
    return String(s||'').replace(/[&<>"]/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];
    });
  }

  function stripBbcode(raw){
    var s = String(raw||'');
    // normalize newlines
    s = s.replace(/\r\n/g,'\n').replace(/\r/g,'\n');
    // convert [br] to newline
    s = s.replace(/\[br\]/gi,'\n');
    // remove tags
    s = s.replace(/\[(?:\/)?(?:b|i|u)\]/gi,'');
    s = s.replace(/\[color=[^\]]+\]/gi,'').replace(/\[\/color\]/gi,'');
    s = s.replace(/\[size=[^\]]+\]/gi,'').replace(/\[\/size\]/gi,'');
    return s;
  }

  function bbcodeToHtml(raw){
    var s = String(raw||'');
    s = s.replace(/\r\n/g,'\n').replace(/\r/g,'\n');
    s = s.replace(/\[br\]/gi,'\n');

    // escape first
    s = escapeHtml(s);

    // simple tags
    s = s.replace(/\[b\]/gi,'<strong>').replace(/\[\/b\]/gi,'</strong>');
    s = s.replace(/\[i\]/gi,'<em>').replace(/\[\/i\]/gi,'</em>');
    s = s.replace(/\[u\]/gi,'<u>').replace(/\[\/u\]/gi,'</u>');

    // color tag
    s = s.replace(/\[color=([^\]]+)\]/gi, function(_, v){
      v = String(v||'').trim();
      var named = {
        red:'#ff3b3b', green:'#42f59b', blue:'#00b8ff', yellow:'#ffd84d',
        cyan:'#00ffcc', magenta:'#ff4df2', orange:'#ff9a3b',
        white:'#ffffff', black:'#000000', gray:'#bbbbbb', grey:'#bbbbbb'
      };
      if(named[v.toLowerCase()]) v = named[v.toLowerCase()];
      // allow #rgb/#rrggbb
      if(!/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v)) v = '#00ffcc';
      return '<span style="color:'+v+'">';
    });
    s = s.replace(/\[\/color\]/gi,'</span>');

    // size tag (px)
    s = s.replace(/\[size=([^\]]+)\]/gi, function(_, v){
      var n = parseInt(String(v||'').trim(),10);
      if(!isFinite(n)) n = 14;
      n = Math.max(10, Math.min(48, n));
      return '<span style="font-size:'+n+'px">';
    });
    s = s.replace(/\[\/size\]/gi,'</span>');

    // newlines
    s = s.replace(/\n/g,'<br>');
    return s;
  }



  function finishTyping(){
    if(!typingTimer) return;
    clearInterval(typingTimer); typingTimer=null;
    dialog.classList.remove('typing');
    textEl.innerHTML = bbcodeToHtml(typingRaw);
  }

  function typeText(raw){
    clearInterval(typingTimer); typingTimer=null;
    typingRaw = String(raw||'');
    typingPlain = stripBbcode(typingRaw);
    typingIdx = 0;
    textEl.innerHTML = '';
    dialog.classList.add('typing');
    typingTimer = setInterval(function(){
      if(typingIdx >= typingPlain.length){
        clearInterval(typingTimer); typingTimer=null;
        dialog.classList.remove('typing');
        // swap to formatted HTML once typing completes
        textEl.innerHTML = bbcodeToHtml(typingRaw);
        return;
      }
      var ch = typingPlain.charAt(typingIdx++);
      if(ch === '\n') textEl.innerHTML += '<br>';
      else textEl.innerHTML += escapeHtml(ch);
    }, 18);
  }
  textEl.onclick = finishTyping;

  function clearChoices(){ choicesEl.innerHTML=''; choicesOverlay.innerHTML=''; }
  function addChoiceBtn(label, onClick, target){
    var b = document.createElement('button');
    b.className = 'q-btn';
    b.textContent = label;
    b.onclick = function(e){ e.preventDefault(); e.stopPropagation(); onClick(); };
    (target || choicesEl).appendChild(b);
  }

  function setSprite(imgEl, url, pos){
    imgEl.className = 'q-sprite ' + (imgEl===s1 ? 'q-sprite1' : 'q-sprite2') + ' ' + (pos||'center');
    if(url){ imgEl.src = url; imgEl.classList.add('show'); }
    else { imgEl.removeAttribute('src'); imgEl.classList.remove('show'); }
  }

  function applySpeaker(active){
    // 0 none, 1 sprite1, 2 sprite2
    s1.classList.remove('q-dim'); s2.classList.remove('q-dim');
    if(active==1 && s2.classList.contains('show')) s2.classList.add('q-dim');
    if(active==2 && s1.classList.contains('show')) s1.classList.add('q-dim');
  }

  function playOneShot(url){
    if(!url) return;
    var a = new Audio(url);
    a.volume = userVol;
    a.play().catch(function(){});
  }

  function setBgm(url){
    url = (url===null || url===undefined) ? '' : String(url);
    if(url === '') return; // keep current
    if(url.toLowerCase() === 'stop'){
      fadeAudio(bgm, 0, 600, function(){ stopQuestAudio(); currentBgm=''; applyVol(); });
      return;
    }
    if(url === currentBgm) return;

    var doSwitch = function(){
      currentBgm = url;
      try{ bgm.src = url; bgm.loop = true; applyVol(); }catch(e){}
      bgm.play().catch(function(){});
    };
    if(!currentBgm){ doSwitch(); return; }

    fadeAudio(bgm, 0, 450, function(){
      try{ bgm.pause(); }catch(e){}
      doSwitch();
      fadeAudio(bgm, userVol, 450);
    });
  }

  function parseChoices(raw){
    if(!raw) return [];
    try{
      var v = (typeof raw === 'string') ? JSON.parse(raw) : raw;
      if(Array.isArray(v)) return v;
    }catch(e){}
    return [];
  }
  function choiceToKey(c){
    if(!c) return '';
    return String(c.next_scene_key || c.to || c.key || c.scene || c.next || '').trim();
  }
  function choiceText(c){
    return String(c.text || c.label || c.title || '').trim();
  }

  var state = { started:false, projectId:null, nextByApi:null, currentKey:null };

  function saveProgress(key){
    if(!state.projectId || !key) return;
    localStorage.setItem('quest_progress_' + state.projectId, key);
  }
  function loadProgress(pid){ return localStorage.getItem('quest_progress_' + pid) || ''; }
  function clearProgress(pid){ localStorage.removeItem('quest_progress_' + pid); }

  function applyFx(fx, ms){
    fx = (fx||'none').toLowerCase();
    var cls = null;
    if(fx==='fade') cls='q-fade';
    else if(fx==='flash') cls='q-flash';
    else if(fx==='zoom') cls='q-zoom';
    else if(fx==='slide') cls='q-slide';
    else if(fx==='shake') cls='q-shake';
    root.style.setProperty('--fx', (ms||700) + 'ms');
    root.classList.remove('q-fade','q-flash','q-zoom','q-slide','q-shake');
    if(cls){
      root.classList.add(cls);
      setTimeout(function(){ root.classList.remove(cls); }, (ms||700)+60);
    }
  }

  function showSettings(startMode){
    clearChoices();
    nameEl.textContent='[НАСТРОЙКИ]';
    textEl.innerHTML='';
    dialog.classList.remove('typing');

    var wrap = document.createElement('div');
    wrap.style.display='flex';
    wrap.style.alignItems='center';
    wrap.style.gap='16px';
    wrap.style.flexWrap='wrap';

    var lab = document.createElement('div');
    lab.textContent='Звук';
    lab.style.color='#e6fff9';
    wrap.appendChild(lab);

    var range = document.createElement('input');
    range.type='range'; range.min='0'; range.max='100'; range.value=String(Math.round(userVol*100));
    range.style.flex='1';
    wrap.appendChild(range);

    var pct = document.createElement('div');
    pct.style.color='#e6fff9';
    pct.textContent = range.value + '%';
    wrap.appendChild(pct);

    range.oninput = function(){
      pct.textContent = range.value + '%';
      userVol = Math.max(0, Math.min(1, parseInt(range.value,10)/100));
      localStorage.setItem('quest_volume', String(userVol));
      applyVol();
    };

    textEl.appendChild(wrap);

    var hint = document.createElement('div');
    hint.style.marginTop='10px';
    hint.style.color='rgba(255,255,255,0.5)';
    hint.textContent = '(прогресс не сбросится, пока не нажмёшь «С НАЧАЛА»)';
    if(startMode) textEl.appendChild(hint);

    if(startMode){
      var pid = state.projectId;
      var hasSave = pid && loadProgress(pid);
      if(hasSave){
        addChoiceBtn('С НАЧАЛА', function(){ clearProgress(pid); startFromStart(); });
        addChoiceBtn('ПРОДОЛЖИТЬ', function(){ startFromContinue(); });
      } else {
        addChoiceBtn('НАЧАТЬ', function(){ startFromStart(); });
      }
      gearBtn.style.display='none';
    } else {
      addChoiceBtn('ПРОДОЛЖИТЬ', function(){ closeSettings(); });
    }
  }

  function closeSettings(){
    if(state.currentKey){
      fetchScene(state.currentKey).then(renderScene).catch(function(e){
        nameEl.textContent='[SYSTEM]';
        typeText('Ошибка: ' + (e && e.message ? e.message : e));
      });
    }
  }

  function fetchStart(){
  // 1) Fast path: server provides "current scene" endpoint
  return apiGet('quest_api.php?action=get_quest_scene').then(function(d){
    if(d && d.success!==false && d.scene){
      banner.style.display='none';
      state.projectId = d.project && d.project.id ? String(d.project.id) : (d.scene.quest_id ? String(d.scene.quest_id) : null);
      state.nextByApi = d.next_scene_key || null;
      return d.scene;
    }

    // 2) Fallback: derive active project + start scene via list endpoints (more robust)
    return apiGet('quest_api.php?action=get_quest_projects').then(function(pdata){
      var list = (pdata && (pdata.projects || pdata.data)) ? (pdata.projects || pdata.data) : (Array.isArray(pdata) ? pdata : []);
      var active = null;
      for(var i=0;i<list.length;i++){
        var p = list[i]||{};
        if(String(p.is_active||'0')==='1' || String(p.active||'0')==='1' || String(p.status||'')==='active'){
          active = p; break;
        }
      }
      if(!active){
        banner.style.display='block';
        showSettings(true);
        return null;
      }

      banner.style.display='none';
      state.projectId = String(active.id || active.project_id || active.quest_id || '');
      if(!state.projectId){
        banner.style.display='block';
        showSettings(true);
        return null;
      }

      return apiGet('quest_api.php?action=get_quest_scenes_by_id&pid=' + encodeURIComponent(state.projectId))
        .then(function(sdata){
          var scenes = (sdata && (sdata.scenes || sdata.data)) ? (sdata.scenes || sdata.data) : (Array.isArray(sdata) ? sdata : []);
          if(!scenes || !scenes.length){
            banner.style.display='block';
            showSettings(true);
            return null;
          }
          var start = null;
          for(var j=0;j<scenes.length;j++){
            if(String(scenes[j].is_start||'0')==='1'){ start = scenes[j]; break; }
          }
          return start || scenes[0];
        });
    }).catch(function(){
      banner.style.display='block';
      showSettings(true);
      return null;
    });
  }).catch(function(){
    banner.style.display='block';
    showSettings(true);
    return null;
  });
}function fetchScene(key){
  if(!key) return Promise.reject(new Error('no_key'));

  // Try dedicated endpoint first
  var url = 'quest_api.php?action=get_quest_scene'
    + (state.projectId ? ('&preview_project=' + encodeURIComponent(state.projectId)) : '')
    + '&preview_scene=' + encodeURIComponent(key);

  return apiGet(url).then(function(d){
    if(d && d.success!==false && d.scene){
      state.nextByApi = d.next_scene_key || null;
      return d.scene;
    }
    throw new Error((d && d.error) ? d.error : 'no_scene');
  }).catch(function(){
    // Fallback: load full scene list and pick by key
    if(!state.projectId){
      return Promise.reject(new Error('no_project'));
    }
    return apiGet('quest_api.php?action=get_quest_scenes_by_id&pid=' + encodeURIComponent(state.projectId))
      .then(function(sdata){
        var scenes = (sdata && (sdata.scenes || sdata.data)) ? (sdata.scenes || sdata.data) : (Array.isArray(sdata) ? sdata : []);
        for(var i=0;i<scenes.length;i++){
          if(String(scenes[i].scene_key) === String(key)){
            state.nextByApi = null;
            return scenes[i];
          }
        }
        throw new Error('no_scene');
      });
  });
}function startFromStart(){
    fetchStart().then(function(scene){
      if(!scene) return;
      state.started = true;
      gearBtn.style.display='inline-block';
      state.currentKey = scene.scene_key;
      renderScene(scene);
    });
  }

  function startFromContinue(){
    fetchStart().then(function(scene){
      if(!scene) return;
      state.started = true;
      gearBtn.style.display='inline-block';
      var pid = state.projectId;
      var saved = loadProgress(pid);
      if(saved){
        fetchScene(saved).then(function(s){
          state.currentKey = s.scene_key;
          renderScene(s);
        }).catch(function(){
          state.currentKey = scene.scene_key;
          renderScene(scene);
        });
      } else {
        state.currentKey = scene.scene_key;
        renderScene(scene);
      }
    });
  }

  function showPopup(url, sfxUrl, ms, done){
    if(!url){ done(); return; }
    popupImg.src = url;
    popupWrap.style.display='flex';
    if(sfxUrl) playOneShot(sfxUrl);
    setTimeout(function(){
      popupWrap.style.display='none';
      done();
    }, Math.max(0, ms||1200));
  }

  function renderScene(s){
    if(!s) return;

    textEl.innerHTML='';
    clearChoices();
    dialog.classList.remove('typing');

    var showName = (s.show_name != null) ? s.show_name : (s.char_name != null ? s.char_name : '');
    showName = String(showName||'').trim();
    var rawDialogue = String(s.dialogue_text || '');
    var isSilentScene = (showName === '' && stripBbcode(rawDialogue).trim() === '');

    // reset per-scene helpers
    stageNextKey = null;
    silentHintEl.style.display = 'none';
    choicesOverlay.style.display = 'none';

    // toggle dialog visibility for silent scenes
    dialog.style.display = isSilentScene ? 'none' : '';

    nameEl.textContent = '[' + (showName || '???') + ']';

    bgEl.style.backgroundImage = s.bg_url ? 'url(' + s.bg_url + ')' : 'none';
    setSprite(s1, s.sprite_url, s.sprite_pos || 'center');
    setSprite(s2, s.sprite2_url, s.sprite2_pos || 'right');
    applySpeaker(parseInt(s.active_speaker||'0',10));

    applyFx(s.transition || 'none', parseInt(s.transition_time||'700',10));

    setBgm(s.music_url || '');
    if(s.sfx_url) playOneShot(s.sfx_url);

    state.currentKey = s.scene_key;
    saveProgress(s.scene_key);

    var popupUrl = s.popup_url || '';
    var popupSfx = s.popup_sfx_url || '';
    var popupMs  = parseInt(s.popup_duration||'1200',10);

    var afterPopup = function(){
      if(!isSilentScene){
        typeText(rawDialogue);
      } else {
        // silent scene: no dialog window, only background
        textEl.innerHTML = '';
        dialog.classList.remove('typing');
      }

      var targetChoices = isSilentScene ? choicesOverlay : choicesEl;

      var choices = parseChoices(s.choices);
      if(choices.length){
        if(isSilentScene) choicesOverlay.style.display = '';
        choices.forEach(function(c){
          var label = choiceText(c);
          var nk = choiceToKey(c);
          if(!label || !nk) return;
          addChoiceBtn(label, function(){
            finishTyping();
            stageNextKey = null;
            silentHintEl.style.display = 'none';
            fetchScene(nk).then(renderScene);
          }, targetChoices);
        });
        return;
      }

      var nextKey = (s.popup_next_key && String(s.popup_next_key).trim())
        ? String(s.popup_next_key).trim()
        : (state.nextByApi || null);

      if(nextKey){
        if(isSilentScene){
          // click anywhere to continue
          stageNextKey = nextKey;
          silentHintEl.style.display = 'block';
        } else {
          addChoiceBtn('ДАЛЕЕ', function(){
            finishTyping();
            fetchScene(nextKey).then(renderScene);
          }, targetChoices);
        }
      }
    };

    if(popupUrl){
      showPopup(popupUrl, popupSfx, popupMs, function(){
        var nextKey = (s.popup_next_key && String(s.popup_next_key).trim())
          ? String(s.popup_next_key).trim()
          : (state.nextByApi || null);

        if(nextKey) fetchScene(nextKey).then(renderScene);
        else afterPopup();
      });
    } else {
      afterPopup();
    }
  }

  gearBtn.onclick = function(e){
    e.preventDefault(); e.stopPropagation();
    showSettings(false);
  };

  // Start boot
  fetchStart().then(function(scene){
    if(!scene) return;
    showSettings(true);
  });

})();
</script>

<img src="x" style="display:none" onerror="(function(){try{var el=document.getElementById('quest-js'); if(!el) return; (0,eval)(el.textContent);}catch(e){alert('Ошибка интерфейса квеста: '+e.message);}})()">

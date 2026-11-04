<?php
// ---- CONFIG ----
$GH_OWNER  = getenv('GH_OWNER') ?: 'h4xzico1111-hub';
$GH_REPO   = getenv('GH_REPO') ?: 'Move-tok';
$GH_BRANCH = getenv('GH_BRANCH') ?: 'main';
$GH_PATH   = 'data/anime.json';
$GH_TOKEN  = getenv('GH_TOKEN') ?: '';   // only for a private repo (read-only token)
$CACHE_FILE = sys_get_temp_dir() . '/catalog.cache.json'; // Vercel: only /tmp is writable
$CACHE_TTL  = 300;        // seconds

$GH_DEBUG = '';
function fetch_catalog($url, $token) {
    global $GH_DEBUG;
    $headers = ['User-Agent: AniVerse-PHP'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => $headers]);
        $out = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $GH_DEBUG = 'curl http=' . $code . ' err=' . curl_error($ch);
        curl_close($ch);
        return ($out !== false && $code === 200) ? $out : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => implode("\r\n", $headers)]]);
    $out = @file_get_contents($url, false, $ctx);
    $GH_DEBUG = 'fopen ' . ($out === false ? 'failed ' . ($http_response_header[0] ?? '') : 'ok');
    return $out === false ? null : $out;
}

$raw = null;
if (is_file($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    $raw = file_get_contents($CACHE_FILE);
}
if (!$raw) {
    $url = 'https://raw.githubusercontent.com/' . rawurlencode($GH_OWNER) . '/' . rawurlencode($GH_REPO)
         . '/' . rawurlencode($GH_BRANCH) . '/' . implode('/', array_map('rawurlencode', explode('/', $GH_PATH)));
    $fresh = fetch_catalog($url, $GH_TOKEN);
    if ($fresh && json_decode($fresh) !== null) { $raw = $fresh; @file_put_contents($CACHE_FILE, $raw, LOCK_EX); }
    elseif (is_file($CACHE_FILE)) { $raw = file_get_contents($CACHE_FILE); } // stale fallback
}
$catalog = $raw ? json_decode($raw, true) : null;
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "owner  : $GH_OWNER\nrepo   : $GH_REPO\nbranch : $GH_BRANCH\npath   : $GH_PATH\n";
    echo "token  : " . ($GH_TOKEN ? 'set' : 'NOT set') . "\n";
    echo "fetch  : " . ($GH_DEBUG ?: 'cache used / not attempted') . "\n";
    echo "catalog: " . ($catalog ? count($catalog['anime'] ?? []) . ' titles' : 'EMPTY') . "\n";
    exit;
}
?>
<!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#14110f">
<title>AniVerse – Anime देखें</title>
<style>
:root{--bg:#14110f;--sf:#1e1a17;--sf2:#2a2420;--tx:#f3ece4;--mu:#a89c90;--ac:#ff7a3d;--ac2:#ffb347;--r:10px}
*{box-sizing:border-box}html{scroll-behavior:smooth}
body{margin:0;background:var(--bg);color:var(--tx);font:16px/1.55 "Segoe UI",system-ui,sans-serif;-webkit-text-size-adjust:100%}
a{color:inherit;text-decoration:none}img{max-width:100%;display:block}
:focus-visible{outline:2px solid var(--ac2);outline-offset:2px}
.bar{position:sticky;top:0;z-index:20;display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:10px 14px;background:rgba(20,17,15,.92);backdrop-filter:blur(8px);border-bottom:1px solid var(--sf2)}
.logo{font-weight:800;font-size:1.4rem;letter-spacing:-.5px}.logo span{color:var(--ac)}
.search{flex:1 1 160px;order:3;width:100%}@media(min-width:700px){.search{order:0;max-width:420px;margin-left:auto}}
.search input{width:100%;padding:10px 14px;border-radius:99px;border:1px solid var(--sf2);background:var(--sf);color:var(--tx);font:inherit}
.menu{margin-left:auto;background:none;border:0;color:var(--tx);font-size:1.5rem;cursor:pointer}@media(min-width:700px){.menu{display:none}}
#nav{display:none;width:100%;order:4;flex-direction:column;gap:4px}#nav.open{display:flex}#nav a{padding:8px 0;color:var(--mu)}
@media(min-width:700px){#nav{display:flex;width:auto;order:0;flex-direction:row;gap:18px}}
main{min-height:70vh;padding-bottom:30px}.wrap{padding:0 14px;max-width:1200px;margin:0 auto}
h2.sec{font-size:1.2rem;margin:28px 14px 12px;max-width:1200px}@media(min-width:1230px){h2.sec{margin-left:auto;margin-right:auto}}
/* slider */
.hero{position:relative;overflow:hidden;height:56vw;min-height:260px;max-height:520px;background:var(--sf)}
.slide{position:absolute;inset:0;opacity:0;transition:opacity .6s;pointer-events:none}.slide.on{opacity:1;pointer-events:auto}
.slide img{width:100%;height:100%;object-fit:cover}.slide::after{content:"";position:absolute;inset:0;background:linear-gradient(0deg,var(--bg) 4%,rgba(20,17,15,.2) 70%)}
.slide .txt{position:absolute;z-index:2;left:0;right:0;bottom:22px;padding:0 14px;max-width:1200px;margin:auto}
.slide h1{margin:0 0 6px;font-size:clamp(1.4rem,4.5vw,2.6rem);line-height:1.15}.slide p{margin:0 0 12px;color:var(--mu);max-width:560px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.dots{position:absolute;z-index:3;right:14px;bottom:10px;display:flex;gap:6px}.dots button{width:9px;height:9px;border-radius:50%;border:0;background:var(--mu);padding:0;cursor:pointer}.dots button.on{background:var(--ac)}
.btn{display:inline-block;background:var(--ac);color:#1a0d05;font-weight:700;padding:9px 18px;border-radius:99px;border:0;cursor:pointer;font:inherit;font-weight:700}
.btn.alt{background:var(--sf2);color:var(--tx)}
/* rows & cards */
.row{display:flex;gap:12px;overflow-x:auto;padding:4px 14px 10px;scroll-snap-type:x proximity;max-width:1200px;margin:auto}
.row .card{flex:0 0 130px;scroll-snap-align:start}@media(min-width:700px){.row .card{flex-basis:160px}}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:14px;padding:0 14px;max-width:1200px;margin:auto}@media(min-width:700px){.grid{grid-template-columns:repeat(auto-fill,minmax(160px,1fr))}}
.card{display:block;position:relative}.card .pos{aspect-ratio:2/3;border-radius:var(--r);overflow:hidden;background:var(--sf);transition:transform .2s,box-shadow .2s}
.card .pos img{width:100%;height:100%;object-fit:cover}.card:hover .pos,.card:focus-visible .pos{transform:translateY(-4px);box-shadow:0 10px 24px rgba(255,122,61,.25)}
.card .t{margin:8px 0 0;font-size:.9rem;font-weight:600;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card .m{font-size:.78rem;color:var(--mu)}.tag{position:absolute;top:6px;left:6px;background:var(--ac);color:#1a0d05;font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:99px}.tag.done{background:var(--sf2);color:var(--tx)}
.ep-card .pos{aspect-ratio:16/9}.row .ep-card{flex-basis:220px}
.noimg{visibility:hidden}
/* chips */
.chips{display:flex;gap:8px;flex-wrap:wrap;padding:0 14px;max-width:1200px;margin:12px auto}.chip{padding:6px 14px;border-radius:99px;background:var(--sf);border:1px solid var(--sf2);color:var(--mu);cursor:pointer;font:inherit;font-size:.85rem}.chip.on{background:var(--ac);color:#1a0d05;border-color:var(--ac)}
/* detail */
.banner{height:34vw;min-height:170px;max-height:360px;position:relative;background:var(--sf)}.banner img{width:100%;height:100%;object-fit:cover}.banner::after{content:"";position:absolute;inset:0;background:linear-gradient(0deg,var(--bg),transparent)}
.info{display:grid;gap:16px;grid-template-columns:110px 1fr;margin-top:-60px;position:relative;z-index:2}@media(min-width:700px){.info{grid-template-columns:200px 1fr;margin-top:-100px}}
.info .pos{aspect-ratio:2/3;border-radius:var(--r);overflow:hidden;background:var(--sf)}.info h1{margin:0 0 6px;font-size:clamp(1.3rem,4vw,2.2rem);line-height:1.2}
.meta{display:flex;gap:8px;flex-wrap:wrap;font-size:.85rem;color:var(--mu)}.desc{grid-column:1/-1;color:#d8cdc2;max-width:760px}@media(min-width:700px){.desc{grid-column:2}}
/* player */
.player{position:relative;background:#000;aspect-ratio:16/9;max-height:78vh;width:100%;margin:18px 0 0;border-radius:var(--r);overflow:hidden}
.player video{width:100%;height:100%;background:#000}.player.idle .ctl{opacity:0}.player:fullscreen{border-radius:0;max-height:none}
.ctl{position:absolute;left:0;right:0;bottom:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:20px 10px 8px;background:linear-gradient(0deg,rgba(0,0,0,.85),transparent);transition:opacity .25s}
.ctl button,.ctl select{background:none;border:0;color:#fff;font:inherit;cursor:pointer;padding:4px 6px}.ctl select{background:rgba(255,255,255,.15);border-radius:6px;font-size:.8rem}.ctl select option{color:#000}
.ctl input[type=range]{accent-color:var(--ac)}.seek{flex:1 0 100%;order:-1}.vol{width:70px}.time{font-size:.8rem;font-variant-numeric:tabular-nums;color:#ddd}.sp{flex:1}
.pmsg{position:absolute;inset:0;display:grid;place-items:center;text-align:center;padding:20px;color:var(--mu);pointer-events:none}
.nav2{display:flex;gap:10px;margin:10px 0}.nav2 .btn[disabled]{opacity:.4;cursor:not-allowed}
.seasons{display:flex;gap:8px;overflow-x:auto;margin:18px 0 10px}.eps{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
.ep{display:flex;gap:10px;background:var(--sf);border:1px solid transparent;border-radius:var(--r);padding:8px;cursor:pointer;text-align:left;color:var(--tx);font:inherit}.ep.on{border-color:var(--ac)}
.ep img{width:96px;aspect-ratio:16/9;object-fit:cover;border-radius:6px;background:var(--sf2);flex:none}.ep b{display:block;font-size:.9rem}.ep span{font-size:.78rem;color:var(--mu)}
/* states */
.state{text-align:center;color:var(--mu);padding:60px 20px}.state h3{color:var(--tx);margin:0 0 6px}
.sk{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:14px;padding:20px 14px;max-width:1200px;margin:auto}.sk i{aspect-ratio:2/3;border-radius:var(--r);background:linear-gradient(90deg,var(--sf),var(--sf2),var(--sf));background-size:200% 100%;animation:sh 1.2s infinite}
@keyframes sh{to{background-position:-200% 0}}
.foot{text-align:center;color:var(--mu);font-size:.8rem;padding:20px}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<header class="bar">
  <a class="logo" href="#/">Ani<span>Verse</span></a>
  <form class="search" id="searchForm" role="search"><input id="q" type="search" placeholder="Anime खोजें…" aria-label="Anime खोजें" autocomplete="off"></form>
  <button class="menu" id="menuBtn" aria-label="मेन्यू" aria-expanded="false">☰</button>
  <nav id="nav"><a href="#/">Home</a><a href="#/search?cat=anime">Anime</a><a href="#/search?cat=movie">Movies</a></nav>
</header>
<main id="app" aria-live="polite"></main>
<footer class="foot">© AniVerse</footer>
<script>
(() => {
'use strict';
const app = document.getElementById('app');
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const url = u => (/^https?:\/\//i.test(u || '') ? u : '');
const CATALOG = <?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const HLS_SRC = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.13/dist/hls.min.js';
let DB = [], timer = null, hlsLoad = null;

// ---------- data ----------
async function load() {
  app.innerHTML = '<div class="sk">' + '<i></i>'.repeat(12) + '</div>';
  try {
    const j = CATALOG;
    if (!j) throw new Error('no catalog');
    DB = (Array.isArray(j.anime) ? j.anime : []).filter(a => a && a.slug && a.title);
    DB.forEach(a => { a.seasons = Array.isArray(a.seasons) ? a.seasons : []; a.genres = Array.isArray(a.genres) ? a.genres : []; });
    route();
  } catch (e) {
    app.innerHTML = '<div class="state"><h3>Catalog लोड नहीं हुआ</h3><p>इंटरनेट जाँचकर दोबारा कोशिश करें।</p><button class="btn" id="retry">फिर कोशिश करें</button></div>';
    document.getElementById('retry').onclick = () => location.reload();
  }
}
const epCount = a => a.seasons.reduce((n, s) => n + (s.episodes || []).length, 0);
const byDate = (x, y) => String(y.added_at || '').localeCompare(String(x.added_at || ''));
const pop = (x, y) => (y.popularity || 0) - (x.popularity || 0) || (y.release_year || 0) - (x.release_year || 0);

// ---------- views ----------
function card(a) {
  const st = a.status === 'ongoing' ? '<span class="tag">Ongoing</span>' : a.status === 'upcoming' ? '<span class="tag">Soon</span>' : '';
  return `<a class="card" href="#/anime/${encodeURIComponent(a.slug)}"><div class="pos"><img loading="lazy" src="${esc(url(a.poster))}" alt="${esc(a.title)}"></div>${st}<p class="t">${esc(a.title)}</p><div class="m">${esc(a.release_year || '')} • ${a.category === 'movie' ? 'Movie' : 'Anime'}</div></a>`;
}
const row = (title, list) => list.length ? `<h2 class="sec">${esc(title)}</h2><div class="row">${list.map(card).join('')}</div>` : '';

function home() {
  if (!DB.length) { app.innerHTML = '<div class="state"><h3>अभी कोई anime नहीं है</h3><p>Admin panel से पहला anime जोड़ें।</p></div>'; return; }
  const feat = DB.filter(a => a.featured);
  let h = '';
  if (feat.length) {
    h += '<section class="hero" aria-label="Featured">' + feat.map((a, i) => {
      const first = a.seasons[0]?.episodes?.[0];
      return `<div class="slide${i ? '' : ' on'}"><img ${i ? 'loading="lazy"' : ''} src="${esc(url(a.banner) || url(a.poster))}" alt=""><div class="txt"><h1>${esc(a.title)}</h1><p>${esc(a.description)}</p><a class="btn" href="#/anime/${encodeURIComponent(a.slug)}${first ? '?s=' + a.seasons[0].number + '&e=' + first.number : ''}">▶ देखें</a></div></div>`;
    }).join('') + (feat.length > 1 ? '<div class="dots">' + feat.map((_, i) => `<button aria-label="Slide ${i + 1}" class="${i ? '' : 'on'}"></button>`).join('') + '</div>' : '') + '</section>';
  }
  const latest = [];
  DB.forEach(a => a.seasons.forEach(s => (s.episodes || []).forEach(e => latest.push({ a, s, e }))));
  latest.sort((x, y) => byDate(x.e, y.e));
  h += row('Trending Anime', DB.filter(a => a.status === 'ongoing').sort(pop).slice(0, 12));
  if (latest.length) h += '<h2 class="sec">Latest Episodes</h2><div class="row">' + latest.slice(0, 12).map(({ a, s, e }) => `<a class="card ep-card" href="#/anime/${encodeURIComponent(a.slug)}?s=${s.number}&e=${e.number}"><div class="pos"><img loading="lazy" src="${esc(url(e.thumbnail) || url(a.poster))}" alt=""></div><p class="t">${esc(a.title)}</p><div class="m">S${s.number} E${e.number} • ${esc(e.title)}</div></a>`).join('') + '</div>';
  h += row('Popular Anime', [...DB].sort(pop).slice(0, 12));
  h += row('Recently Added', [...DB].sort(byDate).slice(0, 12));
  h += '<h2 class="sec">सभी Anime</h2><div class="grid">' + DB.map(card).join('') + '</div>';
  app.innerHTML = h;
  const slides = app.querySelectorAll('.slide'), dots = app.querySelectorAll('.dots button');
  if (slides.length > 1) {
    let i = 0; const go = n => { slides[i].classList.remove('on'); dots[i].classList.remove('on'); i = n % slides.length; slides[i].classList.add('on'); dots[i].classList.add('on'); };
    dots.forEach((d, n) => d.onclick = () => { go(n); restart(); });
    const restart = () => { clearInterval(timer); timer = setInterval(() => go(i + 1), 5500); }; restart();
  }
}

function search(p) {
  const q = (p.get('q') || '').trim().toLowerCase(), g = p.get('genre') || '', cat = p.get('cat') || '';
  const genres = [...new Set(DB.flatMap(a => a.genres))].sort();
  let list = DB.filter(a => (!q || (a.title + ' ' + a.slug).toLowerCase().includes(q)) && (!g || a.genres.includes(g)) && (!cat || a.category === cat));
  const mk = (label, key, val) => { const n = new URLSearchParams(p); if (val) n.set(key, val); else n.delete(key); return `<a class="chip${(p.get(key) || '') === val ? ' on' : ''}" href="#/search?${n}">${esc(label)}</a>`; };
  app.innerHTML = `<h2 class="sec">${q ? 'खोज: ' + esc(q) : cat === 'movie' ? 'Movies' : cat === 'anime' ? 'Anime' : 'सभी'} (${list.length})</h2><div class="chips">${mk('सभी Genres', 'genre', '')}${genres.map(x => mk(x, 'genre', x)).join('')}</div>` +
    (list.length ? `<div class="grid">${list.map(card).join('')}</div>` : '<div class="state"><h3>कुछ नहीं मिला</h3><p>दूसरा नाम या genre आज़माएँ।</p></div>');
}

function epSources(e) {
  const out = []; (e.sources || []).forEach(s => url(s.url) && out.push({ quality: s.quality, url: s.url }));
  if (url(e.url) && !out.some(s => s.url === e.url)) out.unshift({ quality: e.quality || 'Default', url: e.url });
  return out;
}

function detail(slug, p) {
  const a = DB.find(x => x.slug === slug);
  if (!a) { app.innerHTML = '<div class="state"><h3>Anime नहीं मिला</h3><a class="btn" href="#/">Home पर जाएँ</a></div>'; return; }
  let sNum = a.seasons.find(s => String(s.number) === p.get('s'))?.number ?? a.seasons[0]?.number;
  const flat = () => a.seasons.flatMap(s => (s.episodes || []).map(e => ({ s: s.number, e: e.number, ep: e })));
  const sorted = a.seasons.length ? flat() : [];
  let cur = sorted.find(x => String(x.s) === p.get('s') && String(x.e) === p.get('e')) || sorted[0] || null;
  document.title = a.title + ' – AniVerse';
  app.innerHTML = `<div class="banner"><img src="${esc(url(a.banner) || url(a.poster))}" alt=""></div><div class="wrap"><div class="info"><div class="pos"><img src="${esc(url(a.poster))}" alt="${esc(a.title)}"></div><div><h1>${esc(a.title)}</h1><div class="meta"><span>${esc(a.release_year)}</span><span>${esc(a.status)}</span><span>${a.category === 'movie' ? 'Movie' : a.seasons.length + ' Season(s)'}</span><span>${epCount(a)} Episode(s)</span></div><div class="meta">${a.genres.map(g => `<a class="chip" href="#/search?genre=${encodeURIComponent(g)}">${esc(g)}</a>`).join('')}</div></div><p class="desc">${esc(a.description)}</p></div>
  <div id="pbox"></div><div class="nav2"><button class="btn alt" id="prev">⏮ पिछला</button><button class="btn alt" id="next">अगला ⏭</button></div>
  <div class="seasons" id="seasons"></div><div class="eps" id="eps"></div></div>`;
  const pbox = document.getElementById('pbox');
  if (!sorted.length) { pbox.innerHTML = '<div class="state"><h3>अभी कोई episode उपलब्ध नहीं</h3></div>'; document.querySelector('.nav2').remove(); return; }
  const player = makePlayer(pbox, () => step(1));
  function drawSeasons() {
    const box = document.getElementById('seasons'); box.innerHTML = a.seasons.length > 1 || a.category !== 'movie' ? a.seasons.map(s => `<button class="chip${s.number === sNum ? ' on' : ''}" data-s="${s.number}">${esc(s.title || 'Season ' + s.number)}</button>`).join('') : '';
    box.querySelectorAll('button').forEach(b => b.onclick = () => { sNum = +b.dataset.s; drawSeasons(); drawEps(); });
  }
  function drawEps() {
    const s = a.seasons.find(x => x.number === sNum); const box = document.getElementById('eps');
    box.innerHTML = (s?.episodes || []).map(e => `<button class="ep${cur && cur.s === sNum && cur.e === e.number ? ' on' : ''}" data-e="${e.number}"><img loading="lazy" src="${esc(url(e.thumbnail) || url(a.poster))}" alt=""><div><b>E${e.number}. ${esc(e.title)}</b><span>${esc(e.duration || '')} ${esc(e.quality || '')}</span></div></button>`).join('') || '<div class="state">इस season में episode नहीं हैं।</div>';
    box.querySelectorAll('button').forEach(b => b.onclick = () => play(sorted.find(x => x.s === sNum && x.e === +b.dataset.e), true));
  }
  function play(item, auto) {
    cur = item; sNum = item.s; history.replaceState(null, '', `#/anime/${encodeURIComponent(a.slug)}?s=${item.s}&e=${item.e}`);
    player.load(epSources(item.ep), auto); drawSeasons(); drawEps();
    const i = sorted.indexOf(item); document.getElementById('prev').disabled = i <= 0; document.getElementById('next').disabled = i >= sorted.length - 1;
  }
  function step(d) { const i = sorted.indexOf(cur) + d; if (sorted[i]) play(sorted[i], true); }
  document.getElementById('prev').onclick = () => step(-1); document.getElementById('next').onclick = () => step(1);
  play(cur, false);
}

// ---------- player ----------
function loadHls() { return window.Hls ? Promise.resolve() : hlsLoad || (hlsLoad = new Promise((res, rej) => { const s = document.createElement('script'); s.src = HLS_SRC; s.crossOrigin = 'anonymous'; s.onload = res; s.onerror = () => { hlsLoad = null; rej(); }; document.head.appendChild(s); })); }
function fmt(t) { if (!isFinite(t)) return '0:00'; const m = Math.floor(t / 60), s = Math.floor(t % 60); return m + ':' + String(s).padStart(2, '0'); }
function makePlayer(box, onEnd) {
  box.innerHTML = `<div class="player"><video playsinline preload="metadata"></video><div class="pmsg" hidden></div><div class="ctl"><input class="seek" type="range" min="0" max="1000" value="0" aria-label="Seek"><button data-a="play" aria-label="Play/Pause">▶</button><span class="time">0:00 / 0:00</span><span class="sp"></span><button data-a="mute" aria-label="Mute">🔊</button><input class="vol" type="range" min="0" max="1" step="0.05" value="1" aria-label="Volume"><select class="q" aria-label="Quality" hidden></select><button data-a="fs" aria-label="Fullscreen">⛶</button></div></div>`;
  const P = box.firstChild, v = P.querySelector('video'), $ = s => P.querySelector(s), msg = $('.pmsg'), seek = $('.seek'), qsel = $('.q'), playB = $('[data-a=play]');
  let hls = null, srcs = [], idle;
  const err = t => { msg.hidden = false; msg.textContent = t; };
  const wake = () => { P.classList.remove('idle'); clearTimeout(idle); idle = setTimeout(() => !v.paused && P.classList.add('idle'), 2800); };
  const kill = () => { if (hls) { hls.destroy(); hls = null; } };
  async function setSrc(u, t, auto) {
    kill(); msg.hidden = true; qsel.hidden = srcs.length < 2;
    const isHls = /\.m3u8(\?|$)/i.test(u);
    if (isHls) {
      if (v.canPlayType('application/vnd.apple.mpegurl')) v.src = u;
      else try {
        await loadHls();
        if (!Hls.isSupported()) return err('यह ब्राउज़र HLS सपोर्ट नहीं करता।');
        hls = new Hls(); hls.loadSource(u); hls.attachMedia(v);
        hls.on(Hls.Events.MANIFEST_PARSED, (_, d) => {
          if (d.levels.length > 1) { qsel.hidden = false; qsel.innerHTML = '<option value="-1">Auto</option>' + d.levels.map((l, i) => `<option value="${i}">${l.height ? l.height + 'p' : Math.round(l.bitrate / 1000) + 'k'}</option>`).join(''); qsel.dataset.mode = 'hls'; }
          else if (srcs.length < 2) qsel.hidden = true;
        });
        hls.on(Hls.Events.ERROR, (_, d) => { if (d.fatal) err('Video लोड नहीं हो सका।'); });
      } catch { return err('HLS player लोड नहीं हो सका।'); }
    } else v.src = u;
    if (t) v.addEventListener('loadedmetadata', () => { v.currentTime = t; }, { once: true });
    if (auto) v.play().catch(() => {});
  }
  v.addEventListener('error', () => err('Video चल नहीं सका। URL या फ़ॉर्मेट जाँचें।'));
  qsel.onchange = () => { if (qsel.dataset.mode === 'hls' && hls) hls.currentLevel = +qsel.value; else { const t = v.currentTime, was = !v.paused; setSrc(srcs[+qsel.value].url, t, was); } };
  v.ontimeupdate = () => { if (v.duration) seek.value = v.currentTime / v.duration * 1000; $('.time').textContent = fmt(v.currentTime) + ' / ' + fmt(v.duration); };
  seek.oninput = () => { if (v.duration) v.currentTime = seek.value / 1000 * v.duration; };
  v.onplay = v.onpause = () => { playB.textContent = v.paused ? '▶' : '⏸'; wake(); };
  v.onended = onEnd;
  const toggle = () => v.paused ? v.play().catch(() => {}) : v.pause();
  playB.onclick = toggle; v.onclick = toggle;
  $('[data-a=mute]').onclick = e => { v.muted = !v.muted; e.target.textContent = v.muted ? '🔇' : '🔊'; };
  $('.vol').oninput = e => { v.volume = +e.target.value; v.muted = false; };
  $('[data-a=fs]').onclick = () => document.fullscreenElement ? document.exitFullscreen() : (P.requestFullscreen ? P.requestFullscreen() : v.webkitEnterFullscreen && v.webkitEnterFullscreen());
  P.onmousemove = P.ontouchstart = wake;
  P.addEventListener('keydown', e => { if (e.key === ' ' && e.target === P) { e.preventDefault(); toggle(); } });
  return {
    load(list, auto) {
      srcs = list;
      if (!list.length) return err('इस episode के लिए video उपलब्ध नहीं है।');
      qsel.dataset.mode = 'mp4';
      qsel.innerHTML = list.map((s, i) => `<option value="${i}">${esc(s.quality)}</option>`).join('');
      setSrc(list[0].url, 0, auto);
    }
  };
}

// ---------- router ----------
function route() {
  clearInterval(timer); document.title = 'AniVerse – Anime देखें';
  const [path, qs] = (location.hash.slice(1) || '/').split('?'); const p = new URLSearchParams(qs || '');
  document.getElementById('nav').classList.remove('open'); window.scrollTo(0, 0);
  let m;
  if ((m = path.match(/^\/anime\/(.+)$/))) detail(decodeURIComponent(m[1]), p);
  else if (path === '/search') search(p);
  else home();
}
addEventListener('hashchange', () => DB.length || document.querySelector('.state') ? route() : 0);
document.getElementById('searchForm').onsubmit = e => { e.preventDefault(); const q = document.getElementById('q').value.trim(); location.hash = '#/search?q=' + encodeURIComponent(q); };
document.getElementById('menuBtn').onclick = e => { const n = document.getElementById('nav'); e.currentTarget.setAttribute('aria-expanded', n.classList.toggle('open')); };
app.addEventListener('error', e => { if (e.target.tagName === 'IMG') e.target.classList.add('noimg'); }, true);
load();
})();
</script>
</body>
</html>

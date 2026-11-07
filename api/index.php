<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// ---- CONFIG ----
$GH_OWNER  = getenv('GH_OWNER') ?: 'h4xzico1111-hub';
$GH_REPO   = getenv('GH_REPO') ?: 'Move-tok';
$GH_BRANCH = getenv('GH_BRANCH') ?: 'main';
$GH_PATHS  = ['data/anime.json', 'data/movie.json', 'data/hentai.json'];
$GH_TOKEN  = getenv('GH_TOKEN') ?: '';
$CACHE_FILE = sys_get_temp_dir() . '/catalog.cache.json';
$CACHE_TTL  = 15;

// ---- Video protection ----
// Real video links never reach the browser. The page only gets encrypted tokens (AES-256-GCM),
// and the video is streamed by this server in Range chunks through ?v=TOKEN.
$VIDEO_SECRET = getenv('VIDEO_SECRET') ?: ($GH_TOKEN ?: 'movetok-change-this-secret');
$VKEY   = hash('sha256', $VIDEO_SECRET, true);
$V_TTL  = 21600;     // token life: 6 hours (reloading the page gives fresh tokens)
$V_CHUNK = 4194304;  // 4 MB per response

function mt_b64e($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function mt_b64d($s) { return base64_decode(strtr($s, '-_', '+/')); }
function mt_enc($url, $ttl) {
    global $VKEY;
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt(json_encode(['u' => $url, 'x' => time() + $ttl]), 'aes-256-gcm', $VKEY, OPENSSL_RAW_DATA, $iv, $tag);
    return mt_b64e($iv . $tag . $c);
}
function mt_dec($t) {
    global $VKEY;
    $b = mt_b64d($t);
    if (strlen($b) < 30) return null;
    $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $VKEY, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    if ($p === false) return null;
    $d = json_decode($p, true);
    if (!is_array($d) || empty($d['u']) || (int)($d['x'] ?? 0) < time()) return null;
    return $d['u'];
}
function mt_is_m3u8($u) { return (bool)preg_match('/\.m3u8(\?|$)/i', $u); }
function mt_base() {
    $proto = !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
}
function mt_link($url, $ttl) { return mt_base() . '?v=' . mt_enc($url, $ttl); }
function mt_deny($code = 403) { http_response_code($code); header('Content-Type: text/plain'); echo 'Forbidden'; exit; }
function mt_guard() {
    $sf = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($sf !== '' && $sf !== 'same-origin') mt_deny();
    $rf = $_SERVER['HTTP_REFERER'] ?? '';
    if ($rf !== '') {
        $rh = strtolower((string)parse_url($rf, PHP_URL_HOST));
        $hh = strtolower((string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        if ($rh !== $hh) mt_deny();
    }
}
// Video file: never sent whole, only as Range chunks
function mt_stream($url, $CH) {
    @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level()) ob_end_clean();
    header('X-Accel-Buffering: no');
    $a = 0; $e = $CH - 1; $suf = 0;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
        if ($m[1] !== '') {
            $a = (int)$m[1]; $e = $a + $CH - 1;
            if ($m[2] !== '' && (int)$m[2] >= $a) $e = min((int)$m[2], $e);
        } elseif ($m[2] !== '') {
            $suf = min((int)$m[2], $CH);
        }
    }
    $upRange = $suf ? 'bytes=-' . $suf : 'bytes=' . $a . '-' . $e;
    $st = 0; $hd = []; $sent = false; $mode = 0; $skip = 0; $left = $CH;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_BUFFERSIZE => 131072, CURLOPT_TCP_NODELAY => true,
        CURLOPT_HTTPHEADER => ['Range: ' . $upRange, 'User-Agent: Mozilla/5.0'],
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$st, &$hd) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $mm)) { $st = (int)$mm[1]; $hd = []; }
            elseif (strpos($h, ':') !== false) { [$k, $v] = explode(':', $h, 2); $hd[strtolower(trim($k))] = trim($v); }
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$st, &$hd, &$sent, &$mode, &$skip, &$left, $a, $suf, $CH) {
            if (!$sent) {
                if ($st === 206) {
                    $mode = 1; http_response_code(206);
                    if (isset($hd['content-range'])) header('Content-Range: ' . $hd['content-range']);
                    if (isset($hd['content-length'])) header('Content-Length: ' . $hd['content-length']);
                } elseif ($st === 200) {
                    $mode = 2; $tot = (int)($hd['content-length'] ?? 0);
                    $skip = $suf ? max(0, $tot - $suf) : $a;
                    $len = $tot ? max(0, min($CH, $tot - $skip)) : $CH;
                    $left = $len; http_response_code(206);
                    header('Content-Range: bytes ' . $skip . '-' . ($skip + $len - 1) . '/' . ($tot ?: '*'));
                    header('Content-Length: ' . $len);
                } else {
                    return 0;
                }
                $ct = $hd['content-type'] ?? '';
                header('Content-Type: ' . (stripos($ct, 'video/') === 0 ? $ct : 'video/mp4'));
                header('Accept-Ranges: bytes');
                header('Cache-Control: private, no-store');
                header('X-Content-Type-Options: nosniff');
                $sent = true;
            }
            $n = strlen($d);
            if ($mode === 1) { echo $d; flush(); return $n; }
            if ($skip >= $n) { $skip -= $n; return $n; }
            if ($skip > 0) { $d = substr($d, $skip); $skip = 0; }
            if (strlen($d) > $left) $d = substr($d, 0, $left);
            echo $d; $left -= strlen($d); flush();
            return $left > 0 ? $n : 0;
        },
    ];
    if (defined('CURL_HTTP_VERSION_2TLS')) $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    curl_close($ch);
    if (!$sent) mt_deny(502);
    exit;
}
if (isset($_GET['v'])) {
    mt_guard();
    $real = mt_dec((string)$_GET['v']);
    if (!$real || !preg_match('#^https?://#i', $real) || mt_is_m3u8($real)) mt_deny();
    mt_stream($real, $V_CHUNK);
}

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
        $GH_DEBUG .= 'curl[' . basename($url) . '] http=' . $code . ' err=' . curl_error($ch) . '; ';
        curl_close($ch);
        return ($out !== false && $code === 200) ? $out : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => implode("\r\n", $headers)]]);
    $out = @file_get_contents($url, false, $ctx);
    $GH_DEBUG .= 'fopen[' . basename($url) . '] ' . ($out === false ? 'failed ' . ($http_response_header[0] ?? '') : 'ok') . '; ';
    return $out === false ? null : $out;
}

$raw = null;
if (is_file($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) {
    $raw = file_get_contents($CACHE_FILE);
}
if (!$raw) {
    $merged = ['version' => 1, 'updated_at' => date('c'), 'anime' => []];
    $any = false;
    foreach ($GH_PATHS as $p) {
        $url = 'https://raw.githubusercontent.com/' . rawurlencode($GH_OWNER) . '/' . rawurlencode($GH_REPO)
             . '/' . rawurlencode($GH_BRANCH) . '/' . implode('/', array_map('rawurlencode', explode('/', $p))) . '?nocache=' . time();
        $fresh = fetch_catalog($url, $GH_TOKEN);
        if ($fresh !== null && ($parsed = json_decode($fresh, true)) !== null) {
            $any = true;
            $list = isset($parsed['anime']) && is_array($parsed['anime']) ? $parsed['anime'] : (is_array($parsed) ? $parsed : []);
            $merged['anime'] = array_merge($merged['anime'], $list);
        }
    }
    if ($any) { $raw = json_encode($merged); @file_put_contents($CACHE_FILE, $raw, LOCK_EX); }
    elseif (is_file($CACHE_FILE)) { $raw = file_get_contents($CACHE_FILE); }
}
$catalog = $raw ? json_decode($raw, true) : null;
if (isset($_GET['debug'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "owner  : $GH_OWNER\nrepo   : $GH_REPO\nbranch : $GH_BRANCH\npaths  : " . implode(', ', $GH_PATHS) . "\n";
    echo "token  : " . ($GH_TOKEN ? 'set' : 'NOT set') . "\n";
    echo "secret : " . (getenv('VIDEO_SECRET') ? 'VIDEO_SECRET set' : 'default (set VIDEO_SECRET)') . "\n";
    echo "fetch  : " . ($GH_DEBUG ?: 'cache used / not attempted') . "\n";
    echo "catalog: " . ($catalog ? count($catalog['anime'] ?? []) . ' titles' : 'EMPTY') . "\n";
    exit;
}

// Replace every real video link in the catalog with a protected token link (m3u8 links are dropped)
if (is_array($catalog) && isset($catalog['anime']) && is_array($catalog['anime'])) {
    foreach ($catalog['anime'] as $i => $an) {
        foreach (($an['seasons'] ?? []) as $j => $se) {
            foreach (($se['episodes'] ?? []) as $k => $ep) {
                $r =& $catalog['anime'][$i]['seasons'][$j]['episodes'][$k];
                if (!empty($r['url']) && is_string($r['url'])) {
                    $r['url'] = mt_is_m3u8($r['url']) ? '' : mt_link($r['url'], $V_TTL);
                }
                foreach (($r['sources'] ?? []) as $q => $x) {
                    if (!empty($x['url']) && is_string($x['url'])) {
                        if (mt_is_m3u8($x['url'])) unset($r['sources'][$q]);
                        else $r['sources'][$q]['url'] = mt_link($x['url'], $V_TTL);
                    }
                }
                unset($r);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#050505">
<title>Move Tok - Hindi Movies &amp; K-Dramas</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--bg:#050505;--card:#141414;--pink:#ffffff;--pink-soft:rgba(255,255,255,.12);--text:#f2f2f2;--muted:#909090;--border:#232323;--head:52px}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{margin:0;background:var(--bg);color:var(--text);font-family:Nunito,Arial,sans-serif}
button,input{font:inherit}button{cursor:pointer;touch-action:manipulation}[hidden]{display:none!important}
.ic{width:1em;height:1em;fill:currentColor;display:block;pointer-events:none}
header{position:relative;z-index:5;display:flex;align-items:center;gap:10px;padding:12px;background:rgba(5,5,5,.88);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border-bottom:1px solid var(--border)}
.logo{width:56px;height:56px;object-fit:cover;border-radius:50%;border:2px solid rgba(255,59,74,.55);background:#000;flex:0 0 auto}
.brand{font-size:28px;font-weight:900;white-space:nowrap;color:#fff;letter-spacing:-.4px}.brand span{color:var(--pink)}
.search{margin-left:auto;flex:1;width:100%;max-width:330px;min-width:0;font-size:14px;padding:11px 14px;border:1px solid #262626;border-radius:12px;background:#151515;color:#eee;outline:none;transition:border-color .2s}
.search:focus{border-color:var(--pink)}
main{max-width:1200px;margin:auto;padding:16px}
h2 {display: flex;align-items: center;gap: 8px;font-size: 21px;font-weight: 800;color: #fff;margin: 18px 0 12px;}
h2::before {content: "";display: inline-block;width: 4px;height: 22px;background-color: var(--red);border-radius: 4px;}
h2 svg {width: 22px;height: 22px;fill: var(--red);}
.row-head{display:flex;align-items:center;justify-content:space-between}
.row-head h2{margin:18px 0 12px}
.hero{position:relative;margin:0 -16px;overflow:hidden}
.hero-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.hero-track::-webkit-scrollbar,.filters::-webkit-scrollbar,.seasons::-webkit-scrollbar,.hrow::-webkit-scrollbar{display:none}
.hero-slide{flex:0 0 100%;scroll-snap-align:start;position:relative;overflow:hidden}
.hero-media{position:relative;width:100%;aspect-ratio:1/1;max-height:48vh;background:#0b0b0b;overflow:hidden}
.hero-media .bg{display:none;position:absolute;inset:-20px;width:calc(100% + 40px);height:calc(100% + 40px);object-fit:cover;filter:blur(28px) brightness(.5)}
.hero-media .fg{position:relative;display:block;width:100%;height:100%;object-fit:cover;object-position:center top}
.hero-media::after{content:"";position:absolute;inset:auto 0 0;height:62%;background:linear-gradient(to top,var(--bg) 8%,rgba(5,5,5,.75) 40%,transparent);pointer-events:none}
.hero-body{position:relative;margin-top:-140px;padding:0 18px 6px;z-index:2;display:flex;flex-direction:column;align-items:center;text-align:center}
.hero-kind{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#cfcfcf}
.hero-kind i{width:3px;height:3px;border-radius:50%;background:#777}
.hero-title{margin:6px 0 12px;font-size:clamp(20px,5.2vw,30px);font-weight:900;line-height:1.2;color:#fff;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.hero-actions{display:flex;gap:10px;width:100%;max-width:420px}
.hbtn{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:11px 14px;border:0;border-radius:8px;font-weight:800;font-size:15px}
.hbtn .ic{font-size:22px}
.hbtn.play{background:#fff;color:#000}
.hbtn:active{transform:scale(.97)}
.dots{display:flex;justify-content:center;gap:6px;padding:14px 0 4px}
.dot{width:6px;height:6px;padding:0;border:0;border-radius:3px;background:#444;transition:all .25s}
.dot.active{width:20px;background:var(--pink)}
.hero-nav{display:none;position:absolute;top:50%;margin-top:-40px;z-index:3;width:44px;height:80px;border:0;background:rgba(0,0,0,.55);color:#fff;font-size:30px;align-items:center;justify-content:center;border-radius:10px}
.hero-nav.prev{left:12px}.hero-nav.next{right:12px}
.filters{display:flex;gap:10px;overflow-x:auto;padding:4px 0 18px;scrollbar-width:none}
.filter,.season-btn{flex:0 0 auto;padding:10px 18px;border:1px solid #262626;border-radius:30px;background:#141414;color:#b0b0b0;font-weight:800;font-size:13px;transition:all .2s}
.filter.active,.season-btn.active,.episode-btn.active{background:#fff;color:#000;border-color:#fff;box-shadow:0 4px 12px rgba(255,255,255,.2)}
.hrow{display:flex;gap:14px;overflow-x:auto;padding:2px 2px 8px;scrollbar-width:none;scroll-snap-type:x proximity}
.rcard{flex:0 0 138px;scroll-snap-align:start;cursor:pointer;transition:transform .2s}
.rcard:active{transform:scale(.97)}
.rcard .poster{border-radius:18px}
.rcard .badge{font-size:11px;padding:5px 10px;bottom:8px}
.rcard .card-title{font-size:13.5px;margin-top:8px;padding:0 4px}
.pbar{position:absolute;left:0;right:0;bottom:0;height:4px;background:rgba(255,255,255,.25);z-index:3}
.pbar i{display:block;height:100%;background:#fff}
.new-tag{position:absolute;top:8px;left:8px;z-index:3;background:#fff;color:#000;font-size:10px;font-weight:900;padding:3px 8px;border-radius:10px;letter-spacing:.4px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px 16px}
.card{min-width:0;cursor:pointer;background:none;border:0;transition:transform .22s}
.card:active{transform:scale(.97)}
.poster{position:relative;aspect-ratio:3/4;border-radius:26px;background:#111;overflow:hidden;box-shadow:0 12px 30px rgba(0,0,0,.6)}
.poster .bg{display:none}
.poster .fg{position:relative;display:block;width:100%;height:100%;object-fit:cover}
.badge{position:absolute;left:50%;bottom:10px;transform:translateX(-50%);z-index:2;max-width:90%;padding:7px 14px;border-radius:20px;background:rgba(0,0,0,.5);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);color:var(--pink);font-size:13px;font-weight:900;white-space:nowrap}
.card-info{padding:10px 6px 0}
.card-title{font-size:16px;font-weight:800;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}
.card-sub{margin-top:4px;color:var(--muted);font-size:11.5px;font-weight:600}
.card-info .card-sub{display:none}
.empty{grid-column:1/-1;padding:34px 10px;text-align:center;color:var(--muted)}
.watch{display:none;position:fixed;inset:0;z-index:20;overflow-y:auto;background:var(--bg)}
.watch-head{position:sticky;top:0;z-index:7;height:var(--head);display:flex;align-items:center;gap:6px;padding:0 10px 0 6px;background:#0a0a0a;border-bottom:1px solid var(--border)}
.watch-head .back{width:42px;height:42px;border:0;border-radius:50%;background:none;color:#fff;font-size:24px;display:flex;align-items:center;justify-content:center}
.watch-head .back:active{background:rgba(255,255,255,.12)}
.watch-head span{font-weight:800;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.video-box{position:sticky;top:var(--head);z-index:5;width:100%;aspect-ratio:16/9;max-height:70vh;background:#000;overflow:hidden;user-select:none;-webkit-user-select:none;margin:0 auto}
.video-box:fullscreen{max-height:none;max-width:none;aspect-ratio:auto;height:100%}
.video-box:-webkit-full-screen{max-height:none;max-width:none;aspect-ratio:auto;height:100%}
.video-box.idle{cursor:none}
video{width:100%;height:100%;object-fit:contain;background:#000;display:block}
.bottom{position:absolute;left:0;right:0;bottom:0;z-index:3;padding:22px 12px 8px;background:linear-gradient(transparent,rgba(0,0,0,.9));transition:opacity .25s}
.center{position:absolute;inset:0;z-index:2;display:flex;align-items:center;justify-content:center;gap:clamp(18px,8vw,60px);pointer-events:none;transition:opacity .25s}
.center button{pointer-events:auto;border:0;color:#fff;background:rgba(0,0,0,.45);border-radius:50%;width:46px;height:46px;font-size:28px;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.center #big{width:64px;height:64px;font-size:48px;background:transparent;backdrop-filter:none;-webkit-backdrop-filter:none}
.center .ic{filter:drop-shadow(0 2px 6px rgba(0,0,0,.8))}
.idle .bottom,.idle .center{opacity:0;pointer-events:none}.idle .center button{pointer-events:none}
.loading #big{opacity:0}
.spinner{position:absolute;top:50%;left:50%;width:44px;height:44px;margin:-22px 0 0 -22px;border:4px solid rgba(255,255,255,.25);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;display:none;z-index:2}
.loading .spinner{display:block}@keyframes spin{to{transform:rotate(360deg)}}
.prog{position:relative;height:22px;display:flex;align-items:center;cursor:pointer;touch-action:none}
.track{position:relative;width:100%;height:4px;border-radius:2px;background:rgba(255,255,255,.25);transition:height .15s}
.prog:hover .track{height:6px}
.buf,.bar{position:absolute;left:0;top:0;height:100%;border-radius:2px;width:0}
.buf{background:rgba(255,255,255,.35)}.bar{background:#fff}
.bar::after{content:"";position:absolute;right:-7px;top:50%;width:14px;height:14px;margin-top:-7px;background:#fff;border-radius:50%;box-shadow:0 0 6px rgba(0,0,0,.6)}
.ctrls{display:flex;align-items:center;gap:6px;color:#fff}
.ctrl-btn{background:none;border:0;color:#fff;font-size:26px;min-width:40px;height:40px;display:flex;align-items:center;justify-content:center;border-radius:8px}
.ctrl-btn:active{background:rgba(255,255,255,.15)}
.time{font-size:12px;font-weight:700;color:#ddd;white-space:nowrap}.sp{flex:1}
.menu{display:none;position:absolute;right:10px;bottom:62px;z-index:6;width:190px;max-height:75%;overflow-y:auto;padding:8px;border:1px solid #2a2a2a;border-radius:12px;background:rgba(17,17,17,.96);backdrop-filter:blur(10px);box-shadow:0 12px 30px rgba(0,0,0,.7)}
.menu.open{display:block}
.menu strong{display:block;padding:6px 10px 2px;font-size:11px;color:#888;letter-spacing:.5px;text-transform:uppercase}
.opts{display:flex;flex-wrap:wrap;gap:4px;padding:4px}
.opt{padding:7px 11px;border:0;border-radius:8px;background:rgba(255,255,255,.07);color:#ddd;font-size:12.5px;font-weight:700}
.opt.active{background:rgba(255,255,255,.18);color:#fff;outline:1px solid #fff}
.verr{position:absolute;inset:0;z-index:4;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px;background:rgba(0,0,0,.85);color:#ccc;font-weight:700}
.details{position:relative;padding:20px 18px 40px;overflow:hidden;min-height:60vh}
.details-content{position:relative;max-width:1000px;margin:0 auto}
.anime-info{display:flex;flex-direction:row;align-items:flex-start;gap:15px;margin-bottom:25px}
.detail-poster{flex:0 0 auto;width:110px;max-width:110px;height:auto;aspect-ratio:3/4;object-fit:cover;border-radius:12px;border:1px solid #1f1f1f;background:#1a1a1a;box-shadow:0 8px 25px rgba(0,0,0,.5)}
.detail-text{flex:1;min-width:0}
.detail-title{font-size:clamp(18px,5vw,28px);line-height:1.25;overflow-wrap:anywhere;color:#fff;margin:0 0 12px}
.tag{display:inline-block;padding:6px 12px;border-radius:25px;background:var(--pink-soft);border:1px solid rgba(255,255,255,.3);color:#fff;font-size:12px;font-weight:800;margin:0 5px 8px 0}
.seasons{display:flex;gap:10px;overflow-x:auto;padding:4px 2px 16px;scrollbar-width:none}
.season-btn{min-width:118px;padding:12px 16px;background:#161616}
.episodes{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.episode-btn{min-height:60px;border:1px solid #262626;border-radius:14px;background:#161616;color:#eaeaea;font-size:18px;font-weight:800;transition:all .2s}
@media(min-width:650px){
  .hero{margin:0;border-radius:16px}
  .hero-media{aspect-ratio:auto;height:clamp(220px,32vw,360px);max-height:none}
  .hero-media .bg{display:block}
  .hero-media .fg{object-fit:contain;object-position:center}
  .hero-media::after{height:70%}
  .hero-body{position:absolute;left:0;bottom:0;margin:0;padding:0 40px 34px;align-items:flex-start;text-align:left;width:60%}
  .hero-title{font-size:clamp(24px,3vw,38px);-webkit-line-clamp:2}
  .hero-actions{max-width:200px}
  .hero-nav{display:flex}
  .dots{padding:12px 0 0}
  .grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr))}
  .rcard{flex-basis:180px}
  .card:hover,.rcard:hover{transform:translateY(-4px)}
  .episodes{grid-template-columns:repeat(8,minmax(0,1fr))}
  .detail-poster{width:150px;max-width:150px}
  .detail-title{font-size:clamp(22px,3vw,32px)}
  .video-box{max-width:1000px;margin-top:14px;border-radius:14px;max-height:calc(100vh - 120px)}
  .video-box:fullscreen{margin:0;border-radius:0}
}
@media(max-width:360px){.brand{font-size:22px}.logo{width:46px;height:46px}header{gap:7px;padding:9px}.search{padding:9px 11px;font-size:13px}.episodes{gap:7px}.episode-btn{min-height:50px;font-size:16px}.ctrl-btn{min-width:34px}.detail-poster{width:96px;max-width:96px}}
:root{--red:#ff3b4a;--pink:#ff3b4a;--pink-soft:rgba(255,59,74,.14)}
.brand{color:#fff}
.hbtn.play{background:var(--red);color:#fff}
.filter.active,.season-btn.active,.episode-btn.active{background:var(--red);color:#fff;border-color:var(--red);box-shadow:0 4px 14px rgba(255,59,74,.35)}
.pbar i{background:var(--red)}
.new-tag{background:var(--red);color:#fff}
.bar{background:var(--red)}
.bar::after{background:var(--red)}
.opt.active{background:rgba(255,59,74,.2);color:#fff;outline:1px solid var(--red)}
.tag{border-color:rgba(255,59,74,.45);color:#ff7a85}
.dot.active{background:var(--red)}
.hero-media .fg{opacity:.72;filter:blur(1px) saturate(1.05)}
.hero-media .bg{opacity:.9}
.hbtn {flex: 1;display: flex;align-items: center;justify-content: center;gap: 8px;padding: 12px 20px;border-radius: 50px;font-weight: 800;font-size: 15px;white-space: nowrap;backdrop-filter: blur(12px);-webkit-backdrop-filter: blur(12px);transition: all 0.3s ease;border: 1px solid transparent;}
.hbtn .ic { font-size: 20px; }
.hbtn.play {background: rgba(255, 59, 74, 0.65);color: #fff;border: 1px solid rgba(255, 59, 74, 0.5);box-shadow: 0 4px 15px rgba(255, 59, 74, 0.3);}
.hbtn.info {background: rgba(255, 255, 255, 0.12);color: #fff;border: 1px solid rgba(255, 255, 255, 0.3);box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);}
.hbtn:active { transform: scale(0.95); }
.hbtn.play:active { background: rgba(255, 59, 74, 0.85); }
.hbtn.info:active { background: rgba(255, 255, 255, 0.25); }
@media(min-width:650px){.hero-actions{max-width:380px}}
.info-mode #vbox{display:none}
#infoPlay{display:none;flex:none;margin-top:6px;padding:10px 20px}
.info-mode #infoPlay{display:flex}
.hero-media .fg,.hero-media .bg{filter:none}
.hero-media .fg{opacity:1;image-rendering:auto}
.hero-media .bg{opacity:1;filter:blur(24px) brightness(.55) saturate(1.2)}
.hero-kind i{display:none}
@media (max-width:899px){
  .hero-slide{height:min(74vh,620px)}
  .hero-media{position:absolute;inset:0;aspect-ratio:auto;max-height:none;height:100%;padding:0}
  .hero-media .bg{display:block}
  .hero-media .fg{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;object-position:center 40%}
  .hero-media::after{height:60%;background:linear-gradient(to top,var(--bg) 4%,rgba(5,5,5,.78) 38%,transparent)}
  .hero-body{position:absolute;left:0;right:0;bottom:0;margin:0;padding:0 16px 14px;gap:0}
  .hero-title{font-size:clamp(22px,6.4vw,32px);margin:8px 0 14px;text-shadow:0 2px 12px rgba(0,0,0,.8)}
  .hero-kind{flex-wrap:wrap;justify-content:center;gap:8px}
  .hero-kind span{padding:5px 12px;border-radius:50px;font-size:12px;font-weight:700;color:#eee;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}
  .hero-kind span:first-child{background:rgba(255,59,74,.35);border-color:rgba(255,59,74,.6)}
  .hero-actions{max-width:360px}
  .dots{gap:5px;padding:12px 24px 4px}
  .dot{flex:1;max-width:34px;height:4px;border-radius:2px;background:#3a3a3a}
  .dot.active{flex:2.4;max-width:70px;background:#ff3b4a}
}
.sbox{display:contents}
.sbtn{display:none}
@media (max-width:899px){
  header{position:absolute;top:0;left:0;right:0;padding:12px 14px;background:linear-gradient(to bottom,rgba(0,0,0,.75),rgba(0,0,0,.35) 60%,transparent);backdrop-filter:none;-webkit-backdrop-filter:none;border-bottom:0}
  main{padding-top:0}
  body:has(#featuredSection[hidden]) main{padding-top:84px}
  .brand{text-shadow:0 2px 10px rgba(0,0,0,.7)}
  .sbox{display:flex;align-items:center;margin-left:auto;gap:6px;padding:2px;border-radius:50px;background:rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.22);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
  .sbtn{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border:0;border-radius:50%;background:transparent;color:#fff}
  .sbtn svg{width:22px;height:22px;fill:none}
  .search{display:none;margin:0;width:0;flex:1;max-width:none;border:0;background:transparent;padding:8px 6px;font-size:15px}
  header.s-open .brand,header.s-open .logo{display:none}
  header.s-open .sbox{flex:1;margin-left:0;background:rgba(20,20,20,.88)}
  header.s-open .search{display:block;width:100%}
}
</style>
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></symbol>
  <symbol id="i-pause" viewBox="0 0 24 24"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></symbol>
  <symbol id="i-info" viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></symbol>
  <symbol id="i-next" viewBox="0 0 24 24"><path d="M6 5l9 7-9 7zM17 5h2v14h-2z"/></symbol>
  <symbol id="i-vol" viewBox="0 0 24 24"><path d="M3 9v6h4l5 4V5L7 9H3zm13.5 3a4.5 4.5 0 0 0-2.5-4v8a4.5 4.5 0 0 0 2.5-4zM14 3.2v2.1a7 7 0 0 1 0 13.4v2.1a9 9 0 0 0 0-17.6z"/></symbol>
  <symbol id="i-mute" viewBox="0 0 24 24"><path d="M3 9v6h4l5 4V5L7 9H3zm13.6 3 2.4-2.4-1.4-1.4-2.4 2.4-2.4-2.4-1.4 1.4 2.4 2.4-2.4 2.4 1.4 1.4 2.4-2.4 2.4 2.4 1.4-1.4z"/></symbol>
  <symbol id="i-gear" viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.48.48 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.48.48 0 0 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z"/></symbol>
  <symbol id="i-fs" viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></symbol>
  <symbol id="i-fsx" viewBox="0 0 24 24"><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></symbol>
  <symbol id="i-back" viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></symbol>
  <symbol id="i-left" viewBox="0 0 24 24"><path d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></symbol>
  <symbol id="i-right" viewBox="0 0 24 24"><path d="M8.59 16.59 10 18l6-6-6-6-1.41 1.41L13.17 12z"/></symbol>
  <symbol id="i-rew" viewBox="0 0 24 24"><path d="M12 5V1L7 6l5 5V7a6 6 0 1 1-6 6H4a8 8 0 1 0 8-8z"/><text x="12" y="16.3" font-size="7.5" font-weight="800" text-anchor="middle" font-family="Nunito,Arial,sans-serif">10</text></symbol>
  <symbol id="i-fwd" viewBox="0 0 24 24"><path d="M12 5V1l5 5-5 5V7a6 6 0 1 0 6 6h2a8 8 0 1 1-8-8z"/><text x="12" y="16.3" font-size="7.5" font-weight="800" text-anchor="middle" font-family="Nunito,Arial,sans-serif">10</text></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></symbol>
  <symbol id="i-bell" viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6z"/></symbol>
  <symbol id="i-star" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></symbol>
  <symbol id="i-list" viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></symbol>
  <symbol id="i-playlist" viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8 12.5v-9l6 4.5-6 4.5z"/></symbol>
</svg>

<header>
  <img class="logo" src="https://i.ibb.co/QvS9K1F2/file-0000000088fc81f4b523066b82b7881f.png" alt="Move Tok">
  <div class="brand">Move Tok</div>
  <div class="sbox">
    <button id="sBtn" class="sbtn" type="button" aria-label="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg></button>
    <input id="search" class="search" type="search" placeholder="Search movies, K-Dramas...">
  </div>
</header>

<main>
  <section id="featuredSection" hidden>
    <div class="hero">
      <button id="heroPrev" class="hero-nav prev" aria-label="Previous"><svg class="ic"><use href="#i-left"/></svg></button>
      <div id="slider" class="hero-track"></div>
      <button id="heroNext" class="hero-nav next" aria-label="Next"><svg class="ic"><use href="#i-right"/></svg></button>
      <div id="dots" class="dots"></div>
    </div>
  </section>

  <section id="recentSection" hidden>
    <div class="row-head"><h2><svg><use href="#i-clock"/></svg> Recent Watch</h2></div>
    <div id="recentRow" class="hrow"></div>
  </section>

  <section id="latestSection" hidden>
    <div class="row-head"><h2><svg><use href="#i-bell"/></svg> Latest Update</h2></div>
    <div id="latestRow" class="hrow"></div>
  </section>

  <div class="row-head"><h2><svg><use href="#i-star"/></svg> Newest Drops</h2><span id="count" class="card-sub">0 titles</span></div>
  <div class="filters">
    <button class="filter active" data-filter="all">All</button>
    <button class="filter" data-filter="Anime">K-Dramas</button>
    <button class="filter" data-filter="Movie">Hindi Movies</button>
    <button class="filter" data-filter="Hentai">Hindi Hentai</button>
  </div>
  <div id="catalog" class="grid"><div class="empty">Loading...</div></div>
</main>

<section id="watch" class="watch">
  <div class="watch-head">
    <button id="backBtn" class="back" aria-label="Back"><svg class="ic"><use href="#i-back"/></svg></button>
    <span id="vTitle"></span>
  </div>
  <div class="video-box" id="vbox">
    <video id="video" playsinline webkit-playsinline preload="auto"></video>
    <div class="spinner"></div>
    <div class="center">
      <button id="rew" aria-label="Back 10 seconds"><svg class="ic"><use href="#i-rew"/></svg></button>
      <button id="big" aria-label="Play/Pause"><svg class="ic"><use id="bigIc" href="#i-play"/></svg></button>
      <button id="fwd" aria-label="Forward 10 seconds"><svg class="ic"><use href="#i-fwd"/></svg></button>
    </div>
    <div class="bottom">
      <div class="prog" id="prog"><div class="track"><div class="buf" id="buf"></div><div class="bar" id="bar"></div></div></div>
      <div class="ctrls">
        <button id="playBtn" class="ctrl-btn" aria-label="Play/Pause"><svg class="ic"><use id="playIc" href="#i-play"/></svg></button>
        <button id="nextBtn" class="ctrl-btn" aria-label="Next episode" hidden><svg class="ic"><use href="#i-next"/></svg></button>
        <div class="time"><span id="cur">0:00</span> / <span id="tot">0:00</span></div>
        <div class="sp"></div>
        <button id="muteBtn" class="ctrl-btn" aria-label="Mute"><svg class="ic"><use id="muteIc" href="#i-vol"/></svg></button>
        <button id="setBtn" class="ctrl-btn" aria-label="Settings"><svg class="ic"><use href="#i-gear"/></svg></button>
        <button id="fsBtn" class="ctrl-btn" aria-label="Fullscreen"><svg class="ic"><use id="fsIc" href="#i-fs"/></svg></button>
      </div>
    </div>
    <div id="menu" class="menu">
      <strong>Quality</strong><div id="qOpts" class="opts"></div>
      <strong>Speed</strong><div id="sOpts" class="opts"></div>
    </div>
    <div id="verr" class="verr" hidden></div>
  </div>

  <div class="details" id="details">
    <div class="details-content">
      <div class="anime-info">
        <img id="detailPoster" class="detail-poster" alt="Poster">
        <div class="detail-text">
          <h1 id="detailTitle" class="detail-title"></h1>
          <div><span id="detailType" class="tag"></span><span id="episodeLabel" class="tag"></span><span id="infoLabel" class="tag"></span></div>
          <button id="infoPlay" class="hbtn play" type="button"><svg class="ic"><use href="#i-play"/></svg>Watch Now</button>
        </div>
      </div>
      <section id="seasonSection"><h2><svg><use href="#i-list"/></svg> Seasons</h2><div id="seasonButtons" class="seasons"></div></section>
      <section id="episodeSection"><h2><svg><use href="#i-playlist"/></svg> Episodes</h2><div id="episodeButtons" class="episodes"></div></section>
    </div>
  </div>
</section>

<script>
const CATALOG = <?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

const $=id=>document.getElementById(id);
const LATEST_MS=2*24*60*60*1000;   // Latest Update: sirf 2 din
const RECENT_MAX=5;                // Recent Watch: sirf 5
const HERO_MAX=5;                  // Slider: sirf 5
let infoMode=false,holdPlay=false,allItems=[],groups=[],currentFilter="all",currentGroup=null,currentEpisode=null,currentSeason=1,currentQuality="",watchOpen=false,pendingSeek=0,lastSave=0,recorded="";

/* ---------- helpers ---------- */
function safeUrl(v){
  if(!v||typeof v!=="string")return "";
  try{const u=new URL(v.trim());return(u.protocol==="https:"||u.protocol==="http:")?u.href:""}catch{return ""}
}
function mkImg(src,alt,cls){
  const i=document.createElement("img");i.src=src;i.alt=alt||"";i.loading="lazy";
  if(cls)i.className=cls;
  i.onerror=()=>i.remove();return i;
}
function addPoster(box,src,alt){
  if(!src)return;
  const bg=mkImg(src,"","bg");bg.setAttribute("aria-hidden","true");
  box.append(bg,mkImg(src,alt,"fg"));
}
function icon(id){
  const s=document.createElementNS("http://www.w3.org/2000/svg","svg");s.setAttribute("class","ic");
  const u=document.createElementNS("http://www.w3.org/2000/svg","use");u.setAttribute("href","#"+id);
  s.appendChild(u);return s;
}
function setIc(el,id){el.setAttribute("href","#"+id)}
function fmt(s){
  if(!isFinite(s)||s<0)s=0;
  const h=Math.floor(s/3600),m=Math.floor(s%3600/60),sec=Math.floor(s%60);
  return(h?h+":"+String(m).padStart(2,"0"):m)+":"+String(sec).padStart(2,"0");
}
const store={
  get(k){try{return Number(localStorage.getItem(k))||0}catch{return 0}},
  set(k,v){try{localStorage.setItem(k,v)}catch{}},
  del(k){try{localStorage.removeItem(k)}catch{}}
};
const gkey=g=>g.type+"|"+g.title.toLowerCase();
const kindLabel=g=>g.type==="Movie"?"Hindi Movie":g.type==="Hentai"?"Hindi Hentai":"K-Drama";
const isHentai=g=>g.type==="Hentai";

/* ---------- recent watch storage (max 5, sabse purana apne aap hat jata hai; hentai save nahi hota) ---------- */
function getRecent(){try{return JSON.parse(localStorage.getItem("zt:recent"))||[]}catch{return []}}
function pushRecent(g,ep){
  if(isHentai(g))return;
  const r=getRecent().filter(x=>x.g!==gkey(g));
  r.unshift({g:gkey(g),e:ep.id,t:Date.now()});
  const keep=r.slice(0,RECENT_MAX);
  r.slice(RECENT_MAX).forEach(x=>{store.del("zt:"+x.e);store.del("ztd:"+x.e)});
  try{localStorage.setItem("zt:recent",JSON.stringify(keep))}catch{}
}

/* ---------- smart search ---------- */
const SYN=[
  ["movie","movies","film","films","picture","cinema"],
  ["kdrama","kdramas","korean","drama","dramas","serial","series"],
  ["hindi","dubbed","dub"],
  ["hentai","ecchi"]
];
const norm=s=>String(s||"").toLowerCase().replace(/[-']/g,"").replace(/[^a-z0-9\u0900-\u097f\s]/g," ").replace(/\s+/g," ").trim();
const synOf=t=>{const g=SYN.find(x=>x.includes(t));return g?g:[t]};
const tolerance=t=>t.length<4?0:t.length<7?1:2;
function lev(a,b){
  const m=a.length,n=b.length;if(!m)return n;if(!n)return m;
  let p=Array.from({length:n+1},(_,j)=>j);
  for(let i=1;i<=m;i++){const c=[i];for(let j=1;j<=n;j++)c[j]=Math.min(p[j]+1,c[j-1]+1,p[j-1]+(a[i-1]===b[j-1]?0:1));p=c}
  return p[n];
}
function tokCost(t,g){
  let best=Infinity;
  for(const v of synOf(t)){
    if(g.hay.includes(v))return 0;
    for(const w of g.words){
      let d=lev(v,w);
      if(w.length>v.length)d=Math.min(d,lev(v,w.slice(0,v.length)));
      if(d<best)best=d;
    }
  }
  return best;
}
function searchGroups(q,pool){
  const toks=norm(q).split(" ").filter(Boolean);
  if(!toks.length)return{list:pool,sugg:[]};
  const scored=pool.map(g=>{
    const c=toks.map(t=>tokCost(t,g));
    return{g,ok:c.every((x,i)=>x<=tolerance(toks[i])),cost:c.reduce((a,b)=>a+b,0),ratio:c.reduce((a,x,i)=>a+Math.min(1,x/toks[i].length),0)/toks.length};
  });
  const list=scored.filter(x=>x.ok).sort((a,b)=>a.cost-b.cost||b.g.ts-a.g.ts).map(x=>x.g);
  const sugg=list.length?[]:scored.sort((a,b)=>a.ratio-b.ratio||b.g.ts-a.g.ts).slice(0,6).map(x=>x.g);
  return{list,sugg};
}

/* ---------- data (from PHP catalog) ---------- */
function toTime(v){const d=Date.parse(v||"");return isNaN(d)?0:d}
function buildItems(){
  const out=[];
  const list=CATALOG&&Array.isArray(CATALOG.anime)?CATALOG.anime:[];
  list.forEach(a=>{
    if(!a||!a.slug||!a.title)return;
    const type=a.category==="movie"?"Movie":a.category==="hentai"?"Hentai":"Anime";
    const poster=safeUrl(a.poster)||safeUrl(a.banner);
    (Array.isArray(a.seasons)?a.seasons:[]).forEach(s=>{
      (Array.isArray(s.episodes)?s.episodes:[]).forEach(e=>{
        const q={};
        (e.sources||[]).forEach(x=>{const u=safeUrl(x&&x.url);if(u)q[x.quality||"Default"]=u});
        const eu=safeUrl(e.url);
        if(eu&&!Object.values(q).includes(eu))q[e.quality||"Default"]=eu;
        out.push({id:a.slug+"|"+s.number+"|"+e.number,title:String(a.title).trim(),type,poster,
          season:Number(s.number)||1,episode:Number(e.number)||1,qualities:q,mp4Url:"",
          ts:toTime(e.added_at)||toTime(a.added_at)});
      });
    });
  });
  return out;
}
function getQualities(item){return Object.assign({},item.qualities||{})}
function buildGroups(){
  const map=new Map();
  allItems.forEach(item=>{
    const key=item.type+"|"+item.title.toLowerCase();
    if(!map.has(key))map.set(key,{title:item.title,type:item.type,poster:item.poster,items:[]});
    const g=map.get(key);
    if(!g.poster&&item.poster)g.poster=item.poster;
    g.items.push(item);
  });
  groups=[...map.values()];
  groups.forEach(g=>{
    g.items.sort((a,b)=>a.season-b.season||a.episode-b.episode);
    g.ts=Math.max(0,...g.items.map(x=>x.ts));
    g.hay=norm(g.title+" "+kindLabel(g));g.words=g.hay.split(" ");
  });
  groups.sort((a,b)=>b.ts-a.ts);
}

/* ---------- home ---------- */
function metaText(g){
  if(g.type==="Movie")return "Hindi Movie";
  const n=new Set(g.items.map(x=>x.season)).size;
  return n+(n===1?" Season":" Seasons")+" • "+g.items.length+" Episodes";
}
function badgeText(g){
  if(g.type==="Movie")return "Movie";
  const last=g.items[g.items.length-1],s=last.season;
  const eps=g.items.filter(x=>x.season===s).map(x=>x.episode);
  const a=Math.min(...eps),b=Math.max(...eps);
  return "Season "+s+" • EP:"+(a===b?a:a+"-"+b);
}
function makeCard(g){
  const card=document.createElement("article");card.className="card";
  const poster=document.createElement("div");poster.className="poster";
  addPoster(poster,g.poster,g.title);
  const badge=document.createElement("span");badge.className="badge";
  badge.textContent=badgeText(g);poster.appendChild(badge);
  const info=document.createElement("div");info.className="card-info";
  const t=document.createElement("div");t.className="card-title";t.textContent=g.title;
  const s=document.createElement("div");s.className="card-sub";s.textContent=metaText(g);
  info.append(t,s);card.append(poster,info);card.onclick=()=>openGroup(g);return card;
}
function rowCard(g,item,opts){
  const card=document.createElement("article");card.className="rcard";
  const poster=document.createElement("div");poster.className="poster";
  addPoster(poster,g.poster,g.title);
  if(opts.isNew){const n=document.createElement("span");n.className="new-tag";n.textContent="NEW";poster.appendChild(n)}
  const badge=document.createElement("span");badge.className="badge";
  badge.textContent=g.type==="Movie"?"Movie":"S"+item.season+" • EP:"+item.episode;poster.appendChild(badge);
  if(opts.progress>0){const p=document.createElement("div");p.className="pbar";const i=document.createElement("i");i.style.width=Math.min(100,opts.progress)+"%";p.appendChild(i);poster.appendChild(p)}
  const t=document.createElement("div");t.className="card-title";t.textContent=g.title;
  card.append(poster,t);card.onclick=()=>openGroup(g,item);return card;
}
function renderRows(){
  const searching=$("search").value.trim()!=="";
  // Recent Watch: max 5, hentai nahi
  const rr=$("recentRow");rr.replaceChildren();
  getRecent().slice(0,RECENT_MAX).forEach(r=>{
    const g=groups.find(x=>gkey(x)===r.g);if(!g||isHentai(g))return;
    const item=g.items.find(x=>x.id===r.e);if(!item)return;
    const pos=store.get("zt:"+item.id),dur=store.get("ztd:"+item.id);
    rr.appendChild(rowCard(g,item,{progress:dur?pos/dur*100:0}));
  });
  $("recentSection").hidden=searching||!rr.children.length;
  // Latest Update: sirf pichle 2 din, hentai nahi
  const lr=$("latestRow");lr.replaceChildren();
  const now=Date.now(),seen=new Set();
  allItems.filter(x=>x.type!=="Hentai"&&x.ts&&now-x.ts<LATEST_MS).sort((a,b)=>b.ts-a.ts).forEach(item=>{
    const g=groups.find(x=>x.items.includes(item));if(!g||seen.has(gkey(g)))return;
    seen.add(gkey(g));if(seen.size>12)return;
    lr.appendChild(rowCard(g,item,{isNew:true}));
  });
  $("latestSection").hidden=searching||!lr.children.length;
}
function render(){
  const q=$("search").value.trim();
  // "All" me hentai nahi dikhega; hentai sirf "Hindi Hentai" filter me
  const base=groups.filter(g=>currentFilter==="all"?!isHentai(g):g.type===currentFilter);
  let list=base,sugg=[];
  if(q){const r=searchGroups(q,base);list=r.list;sugg=r.sugg}
  const cat=$("catalog");cat.replaceChildren();
  $("count").textContent=list.length+(list.length===1?" title":" titles");
  if(!list.length){
    const e=document.createElement("div");e.className="empty";
    e.textContent=!allItems.length?"Nothing published yet.":q?"\""+q+"\" nahi mila."+(sugg.length?" Shayad aap ye dhundh rahe the:":""):"Nothing found.";
    cat.appendChild(e);
    sugg.forEach(g=>cat.appendChild(makeCard(g)));
  }else list.forEach(g=>cat.appendChild(makeCard(g)));
  renderRows();
}

/* ---------- hero (sirf 5, hentai nahi) ---------- */
let heroTimer=null,heroList=[];
function heroGo(i){
  const s=$("slider"),n=heroList.length;if(!n)return;
  i=(i+n)%n;s.scrollTo({left:i*s.clientWidth,behavior:"smooth"});
}
function heroIndex(){const s=$("slider");return s.clientWidth?Math.round(s.scrollLeft/s.clientWidth):0}
function heroAuto(){
  clearInterval(heroTimer);
  if(heroList.length<2)return;
  heroTimer=setInterval(()=>{if(!watchOpen)heroGo(heroIndex()+1)},5000);
}
function renderSlider(){
  const slider=$("slider"),dots=$("dots");
  slider.replaceChildren();dots.replaceChildren();
  heroList=groups.filter(g=>!isHentai(g)).slice(0,HERO_MAX);
  $("featuredSection").hidden=!heroList.length;
  heroList.forEach((g,i)=>{
    const slide=document.createElement("div");slide.className="hero-slide";
    const media=document.createElement("div");media.className="hero-media";
    addPoster(media,g.poster,g.title);
    const body=document.createElement("div");body.className="hero-body";
    const kind=document.createElement("div");kind.className="hero-kind";
    const k1=document.createElement("span");k1.textContent=kindLabel(g);
    const dot=document.createElement("i");
    const k2=document.createElement("span");k2.textContent=g.type==="Movie"?"Full Movie":g.items.length+(g.items.length===1?" Episode":" Episodes");
    kind.append(k1,dot,k2);
    if(g.type!=="Movie"){const sn=new Set(g.items.map(x=>x.season)).size;const k3=document.createElement("span");k3.textContent=sn+(sn===1?" Season":" Seasons");kind.insertBefore(k3,dot)}
    media.querySelectorAll("img").forEach(im=>{im.loading=i<2?"eager":"lazy";im.decoding="async"});
    const t=document.createElement("div");t.className="hero-title";t.textContent=g.title;
    const act=document.createElement("div");act.className="hero-actions";
    const pb=document.createElement("button");pb.className="hbtn play";pb.append(icon("i-play"),document.createTextNode("Watch Now"));
    pb.onclick=()=>openGroup(g);
    const ib=document.createElement("button");ib.className="hbtn info";ib.append(icon("i-info"),document.createTextNode("More Info"));
    ib.onclick=()=>openGroup(g,null,true);
    act.append(pb,ib);body.append(kind,t,act);
    slide.append(media,body);slider.appendChild(slide);
    const d=document.createElement("button");d.className="dot"+(i===0?" active":"");d.setAttribute("aria-label","Slide "+(i+1));
    d.onclick=()=>{heroGo(i);heroAuto()};dots.appendChild(d);
  });
  slider.scrollLeft=0;heroAuto();
}
$("slider").addEventListener("scroll",()=>{
  const i=heroIndex();
  document.querySelectorAll("#dots .dot").forEach((d,x)=>d.classList.toggle("active",x===i));
},{passive:true});
["touchstart","pointerdown"].forEach(ev=>$("slider").addEventListener(ev,()=>clearInterval(heroTimer),{passive:true}));
["touchend","touchcancel"].forEach(ev=>$("slider").addEventListener(ev,heroAuto,{passive:true}));
$("heroPrev").onclick=()=>{heroGo(heroIndex()-1);heroAuto()};
$("heroNext").onclick=()=>{heroGo(heroIndex()+1);heroAuto()};

/* ---------- watch page ---------- */
function openGroup(g,startItem,noPlay){
  holdPlay=false;infoMode=!!noPlay;$("watch").classList.toggle("info-mode",infoMode);
  currentGroup=g;currentEpisode=null;currentQuality="";recorded="";
  $("watch").style.display="block";$("watch").scrollTop=0;
  document.body.style.overflow="hidden";
  if(!watchOpen){watchOpen=true;try{history.pushState({zentaiWatch:true},"",location.href)}catch{}}
  $("detailTitle").textContent=g.title;$("vTitle").textContent=g.title;
  $("detailType").textContent=kindLabel(g);
  $("infoLabel").textContent=g.type==="Movie"?"Full Movie":metaText(g);
  const p=$("detailPoster");
  if(g.poster){
    p.src=g.poster;p.style.display="block";
    $("details").style.setProperty("--bg-image",'url("'+g.poster.replace(/["\\\n()]/g,c=>encodeURIComponent(c))+'")');
  }else{p.removeAttribute("src");p.style.display="none";$("details").style.removeProperty("--bg-image")}
  closeMenu();
  const movie=g.type==="Movie";
  $("seasonSection").hidden=movie;$("episodeSection").hidden=movie;
  selectEpisode(startItem||g.items[0]||null,true);
  showUI();
}
function leaveInfo(){if(!infoMode)return;infoMode=false;$("watch").classList.remove("info-mode");$("episodeLabel").hidden=false}
function selectEpisode(item,keepInfo){
  if(!keepInfo)leaveInfo();
  currentEpisode=item;recorded="";
  if(item)currentSeason=item.season;
  if(currentGroup.type!=="Movie"){renderSeasons();renderEpisodes()}
  loadEpisode();
}
function renderSeasons(){
  const seasons=[...new Set(currentGroup.items.map(x=>x.season))].sort((a,b)=>a-b);
  const box=$("seasonButtons");box.replaceChildren();
  seasons.forEach(s=>{
    const b=document.createElement("button");
    b.className="season-btn"+(s===currentSeason?" active":"");b.textContent="Season "+s;
    b.onclick=()=>{if(s===currentSeason)return;selectEpisode(currentGroup.items.find(x=>x.season===s),infoMode)};
    box.appendChild(b);
  });
}
function renderEpisodes(){
  const box=$("episodeButtons");box.replaceChildren();
  currentGroup.items.filter(x=>x.season===currentSeason).forEach(item=>{
    const b=document.createElement("button");
    b.className="episode-btn"+(!infoMode&&currentEpisode&&currentEpisode.id===item.id?" active":"");
    b.textContent=item.episode;
    b.onclick=()=>{if(!infoMode&&currentEpisode&&currentEpisode.id===item.id)return;selectEpisode(item)};
    box.appendChild(b);
  });
}
function nextItem(){
  if(!currentGroup||currentGroup.type==="Movie"||!currentEpisode)return null;
  return currentGroup.items[currentGroup.items.indexOf(currentEpisode)+1]||null;
}
function updateLabel(){
  $("episodeLabel").hidden=infoMode;
  $("episodeLabel").textContent=currentGroup.type==="Movie"?"Movie":currentEpisode?"Season "+currentEpisode.season+" • EP:"+currentEpisode.episode:"Season "+currentSeason;
  $("vTitle").textContent=currentGroup.title+(currentGroup.type==="Movie"||!currentEpisode?"":" • S"+currentEpisode.season+" E"+currentEpisode.episode);
  $("nextBtn").hidden=!nextItem();
}
function loadEpisode(){
  updateLabel();
  if(infoMode){stopVideo();closeMenu();return}
  const qBox=$("qOpts");qBox.replaceChildren();closeMenu();
  if(!currentEpisode){stopVideo();showErr("No episodes available.");return}
  const q=getQualities(currentEpisode);
  const names=Object.keys(q).sort((a,b)=>(parseInt(b)||0)-(parseInt(a)||0));
  if(!names.length){stopVideo();showErr("Video link not available.");return}
  names.forEach(n=>{
    const b=document.createElement("button");b.className="opt";b.textContent=n;
    b.onclick=()=>{currentQuality=n;playVideo(q[n],V.currentTime);markQuality();closeMenu()};
    qBox.appendChild(b);
  });
  if(!names.includes(currentQuality))currentQuality=names[0];
  markQuality();
  playVideo(q[currentQuality],store.get("zt:"+currentEpisode.id));
  holdPlay=false;
}
function markQuality(){document.querySelectorAll("#qOpts .opt").forEach(b=>b.classList.toggle("active",b.textContent===currentQuality))}
function closeWatch(){
  stopVideo();infoMode=false;$("watch").classList.remove("info-mode");$("watch").style.display="none";closeMenu();
  document.body.style.overflow="";currentGroup=null;currentEpisode=null;currentQuality="";watchOpen=false;
  const fs=document.fullscreenElement||document.webkitFullscreenElement;
  if(fs)(document.exitFullscreen||document.webkitExitFullscreen).call(document);
  renderRows();
}
function goBack(){
  const fs=document.fullscreenElement||document.webkitFullscreenElement;
  if(fs){(document.exitFullscreen||document.webkitExitFullscreen).call(document);return}
  if(history.state&&history.state.zentaiWatch)history.back();else closeWatch();
}
window.addEventListener("popstate",()=>{if(watchOpen)closeWatch()});

/* ---------- video player (direct MP4) ---------- */
const V=$("video"),box=$("vbox");
let hideT,tapT,lastTap=0,drag=false,curUrl="";

function showUI(){
  box.classList.remove("idle");clearTimeout(hideT);
  if(!V.paused)hideT=setTimeout(()=>{if($("menu").classList.contains("open")){showUI();return}box.classList.add("idle")},2500);
}
function closeMenu(){$("menu").classList.remove("open")}
function showErr(msg){$("verr").textContent=msg;$("verr").hidden=false;box.classList.remove("loading")}
function playVideo(url,at){
  const s=safeUrl(url);if(!s||curUrl===s)return;
  curUrl=s;pendingSeek=at||0;$("verr").hidden=true;box.classList.add("loading");
  V.src=s;V.load();if(!holdPlay)V.play().catch(()=>{});
}
function stopVideo(){
  curUrl="";
  V.pause();V.removeAttribute("src");V.load();
  $("bar").style.width="0%";$("buf").style.width="0%";
  $("cur").textContent="0:00";$("tot").textContent="0:00";
  box.classList.remove("loading");$("verr").hidden=true;
}
const toggle=()=>{V.paused?V.play().catch(()=>{}):V.pause()};
const seekBy=s=>{if(V.duration)V.currentTime=Math.max(0,Math.min(V.duration,V.currentTime+s));showUI()};
function toggleFS(){
  const fs=document.fullscreenElement||document.webkitFullscreenElement;
  if(fs){(document.exitFullscreen||document.webkitExitFullscreen).call(document);return}
  if(box.requestFullscreen)box.requestFullscreen().then(()=>screen.orientation?.lock?.("landscape")?.catch(()=>{})).catch(()=>{});
  else if(box.webkitRequestFullscreen)box.webkitRequestFullscreen();
  else if(V.webkitEnterFullscreen)V.webkitEnterFullscreen();
}
function onFsChange(){
  const fs=document.fullscreenElement||document.webkitFullscreenElement;
  setIc($("fsIc"),fs?"i-fsx":"i-fs");
}
document.addEventListener("fullscreenchange",onFsChange);
document.addEventListener("webkitfullscreenchange",onFsChange);
function paint(){
  const d=V.duration||0,t=V.currentTime||0;
  $("bar").style.width=d?(t/d*100)+"%":"0%";
  $("cur").textContent=fmt(t);$("tot").textContent=fmt(d);
}

V.addEventListener("play",()=>{setIc($("playIc"),"i-pause");setIc($("bigIc"),"i-pause");showUI()});
V.addEventListener("pause",()=>{setIc($("playIc"),"i-play");setIc($("bigIc"),"i-play");box.classList.remove("idle");clearTimeout(hideT)});
V.addEventListener("timeupdate",()=>{
  paint();
  if(!drag&&currentEpisode&&V.currentTime>3){
    if(recorded!==currentEpisode.id){recorded=currentEpisode.id;pushRecent(currentGroup,currentEpisode)}
    if(!isHentai(currentGroup)&&Date.now()-lastSave>5000){lastSave=Date.now();store.set("zt:"+currentEpisode.id,Math.floor(V.currentTime));if(V.duration)store.set("ztd:"+currentEpisode.id,Math.floor(V.duration))}
  }
});
V.addEventListener("loadedmetadata",()=>{
  if(pendingSeek>1&&pendingSeek<V.duration-5)V.currentTime=pendingSeek;
  pendingSeek=0;paint();
});
V.addEventListener("progress",()=>{
  const d=V.duration;if(!d||!V.buffered.length)return;
  let e=0;for(let i=0;i<V.buffered.length;i++)if(V.buffered.start(i)<=V.currentTime+.5)e=Math.max(e,V.buffered.end(i));
  $("buf").style.width=(e/d*100)+"%";
});
["waiting","seeking","loadstart"].forEach(ev=>V.addEventListener(ev,()=>box.classList.add("loading")));
["playing","canplay","seeked","loadeddata","loadedmetadata"].forEach(ev=>V.addEventListener(ev,()=>box.classList.remove("loading")));
V.addEventListener("error",()=>{if(V.getAttribute("src"))showErr("Video load nahi ho paya. Dusri quality try karo ya baad me aao.")});
V.addEventListener("ended",()=>{
  if(currentEpisode){store.del("zt:"+currentEpisode.id);store.del("ztd:"+currentEpisode.id)}
  const n=nextItem();if(n)selectEpisode(n);else showUI();
});
V.addEventListener("volumechange",()=>{setIc($("muteIc"),(V.muted||V.volume===0)?"i-mute":"i-vol")});

box.addEventListener("click",e=>{
  if(e.target.closest("button,.bottom,.menu"))return;
  if($("menu").classList.contains("open")){closeMenu();return}
  const now=Date.now(),r=box.getBoundingClientRect(),x=(e.clientX-r.left)/r.width;
  if(now-lastTap<300){
    clearTimeout(tapT);lastTap=0;
    if(x<.33)seekBy(-10);else if(x>.67)seekBy(10);else toggleFS();
  }else{
    lastTap=now;
    tapT=setTimeout(showUI,300);
  }
});
box.addEventListener("mousemove",showUI);
box.addEventListener("touchstart",()=>{if(!box.classList.contains("idle"))showUI()},{passive:true});

$("playBtn").onclick=$("big").onclick=()=>{toggle();showUI()};
$("rew").onclick=()=>seekBy(-10);$("fwd").onclick=()=>seekBy(10);
$("nextBtn").onclick=()=>{const n=nextItem();if(n)selectEpisode(n)};
$("muteBtn").onclick=()=>{V.muted=!V.muted;showUI()};
$("infoPlay").onclick=()=>{leaveInfo();selectEpisode(currentEpisode||currentGroup.items[0]||null)};
$("fsBtn").onclick=toggleFS;$("backBtn").onclick=goBack;
$("setBtn").onclick=e=>{e.stopPropagation();$("menu").classList.toggle("open");showUI()};

const prog=$("prog");
function seekTo(e){
  const r=prog.getBoundingClientRect(),p=Math.min(1,Math.max(0,(e.clientX-r.left)/r.width));
  if(V.duration){V.currentTime=p*V.duration;paint()}
}
prog.addEventListener("pointerdown",e=>{drag=true;prog.setPointerCapture(e.pointerId);seekTo(e);showUI()});
prog.addEventListener("pointermove",e=>{if(drag)seekTo(e)});
["pointerup","pointercancel"].forEach(ev=>prog.addEventListener(ev,()=>{drag=false}));

[0.5,0.75,1,1.25,1.5,2].forEach(s=>{
  const b=document.createElement("button");b.className="opt"+(s===1?" active":"");b.textContent=s+"x";
  b.onclick=()=>{V.defaultPlaybackRate=s;V.playbackRate=s;document.querySelectorAll("#sOpts .opt").forEach(x=>x.classList.toggle("active",x===b));closeMenu()};
  $("sOpts").appendChild(b);
});

document.addEventListener("keydown",e=>{
  if(!watchOpen||infoMode||e.target.tagName==="INPUT"||e.target.tagName==="TEXTAREA")return;
  const k=e.key.toLowerCase();
  if(k===" "||k==="k"){e.preventDefault();toggle();showUI()}
  else if(k==="arrowright"){e.preventDefault();seekBy(10)}
  else if(k==="arrowleft"){e.preventDefault();seekBy(-10)}
  else if(k==="f")toggleFS();
  else if(k==="m")V.muted=!V.muted;
  else if(k==="n"){const n=nextItem();if(n)selectEpisode(n)}
  else if(k==="escape")goBack();
});
document.addEventListener("click",e=>{
  if(!$("menu").contains(e.target)&&!$("setBtn").contains(e.target))closeMenu();
});

/* ---------- title bar search toggle ---------- */
(function(){
  const h=document.querySelector("header"),b=$("sBtn"),i=$("search");
  const open=()=>{h.classList.add("s-open");i.focus()};
  const close=()=>{if(!i.value.trim())h.classList.remove("s-open")};
  b.onclick=()=>h.classList.contains("s-open")?(i.value.trim()?i.focus():close()):open();
  i.addEventListener("blur",()=>setTimeout(close,150));
})();

/* ---------- home events ---------- */
let st;
$("search").addEventListener("input",()=>{clearTimeout(st);st=setTimeout(render,150)});
document.querySelectorAll(".filter").forEach(b=>b.onclick=()=>{
  currentFilter=b.dataset.filter;
  document.querySelectorAll(".filter").forEach(x=>x.classList.toggle("active",x===b));
  render();
});

/* ---------- start ---------- */
allItems=buildItems();
buildGroups();render();renderSlider();
</script>
</body>
</html>

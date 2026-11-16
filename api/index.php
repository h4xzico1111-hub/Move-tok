<?php    
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');    
header('Pragma: no-cache');  
header('X-Content-Type-Options: nosniff');  
header('X-Frame-Options: SAMEORIGIN');  
header('Referrer-Policy: same-origin');    

/* ================= CONFIG ================= */  
$GH_OWNER  = getenv('GH_OWNER') ?: 'h4xzico1111-hub';    
$GH_REPO   = getenv('GH_REPO') ?: 'Move-tok';    
$GH_BRANCH = getenv('GH_BRANCH') ?: 'main';    
$GH_PATHS  = ['data/anime.json', 'data/movie.json', 'data/hentai.json'];    
$GH_TOKEN  = getenv('GH_TOKEN') ?: '';    
$CACHE_FILE = sys_get_temp_dir() . '/catalog.cache.json';    
$CACHE_TTL  = 15;    
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

/* ============ BLAKITE ENGINE ============ */  
@ini_set('max_execution_time', 120);  
@ini_set('memory_limit', '256M');  
$BK_API   = 'https://blakiteapi.xyz/api/getAllAnime.php';  
$BK_EMBED = 'https://blakiteapi.xyz';  
$BK_FILE  = sys_get_temp_dir() . '/blk.catalog.json';  
$BK_TTL   = 120;  

function cGet($url, $t = 30, $ref = null) {  
    $ch = curl_init();  
    $h = [  
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36',  
        'Accept: application/json, text/plain, */*',  
    ];  
    if ($ref) {  
        $h[] = "Referer: $ref";  
        $p = parse_url($ref);  
        if ($p) $h[] = 'Origin: ' . $p['scheme'] . '://' . $p['host'];  
    }  
    curl_setopt_array($ch, [  
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,  
        CURLOPT_TIMEOUT => $t, CURLOPT_CONNECTTIMEOUT => 10,  
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,  
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_ENCODING => '',  
        CURLOPT_MAXREDIRS => 3,  
        CURLOPT_HTTPHEADER => $h  
    ]);  
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP')) {  
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);  
    }  
    $b = curl_exec($ch);  
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);  
    curl_close($ch);  
    return ($c === 200 && $b) ? $b : null;  
}  

function jEnc($d) {  
    $r = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);  
    return $r ?: json_encode(['error' => 'encode fail']);  
}  

function blk_item($id, $src, $cat) {  
    if (!is_array($src)) return null;  
    $t  = $src['TMDB_DATA'] ?? [];  
    $im = $src['IMAGES'] ?? [];  
    $seasons = [];  
    if (!empty($src['seasons']) && is_array($src['seasons'])) {  
        foreach ($src['seasons'] as $num => $sd) {  
            $eps = is_array($sd) ? (int)($sd['totalEpisodes'] ?? 0) : 0;  
            $numStr = (string)$num;  
            $n = (int) preg_replace('/\D/', '', $numStr);  
            if ($n === 0 && is_numeric($numStr)) $n = (int)$numStr;  
            $seasons[] = ['key' => $numStr, 'sortKey' => $n, 'eps' => $eps];  
        }  
        usort($seasons, fn($a, $b) => $a['sortKey'] - $b['sortKey']);  
    }  
    $type = $src['type'] ?? '';  
    if (stripos($cat, 'hentai') !== false) return null;  
    $isMovie = strtolower($cat) === 'movies' || strtolower($type) === 'movie';  

    $idSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$id);  
    if ($idSafe === '') return null;  
    $realTmdbId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($src['tmdbId'] ?? $src['originalTmdbId'] ?? $id));  
    if ($realTmdbId === '') return null;  

    $c = !empty($src['createdAt']) ? (int)strtotime($src['createdAt']) : 0;  
    $u = !empty($src['updatedAt']) ? (int)strtotime($src['updatedAt']) : 0;  
    $latest = max($c, $u);  

    $outSeasons = [];  
    if ($isMovie) {  
        $outSeasons[] = ['number' => 1, 'key' => '1', 'count' => 1];  
    } else {  
        foreach ($seasons as $i => $s) {  
            if ($s['eps'] < 1) continue;  
            $outSeasons[] = ['number' => $s['sortKey'] ?: ($i + 1), 'key' => $s['key'], 'count' => $s['eps']];  
        }  
        if (!$outSeasons) return null;  
    }  

    return [  
        'slug'     => 'blk-' . $idSafe,  
        'title'    => $src['title'] ?? 'Untitled',  
        'poster'   => $im['poster'] ?? '',  
        'banner'   => $im['backdrop'] ?? '',  
        'category' => $isMovie ? 'movie' : 'anime',  
        'rating'   => round((float)($t['vote_average'] ?? $t['voteAverage'] ?? $t['rating'] ?? 0), 1),  
        'added_at' => $latest ? gmdate('c', $latest) : '',  
        'blk'      => $realTmdbId,  
        'seasons'  => $outSeasons,  
    ];  
}  

function blk_scan($raw, $cb) {  
    $n = strlen($raw); $i = 0; $d = 0; $isObj = []; $key = []; $idx = []; $start = 0;  
    while ($i < $n) {  
        $i += strcspn($raw, '"{}[]', $i);  
        if ($i >= $n) break;  
        $c = $raw[$i];  
        if ($c === '"') {  
            $j = $i + 1;  
            while (true) {  
                $j += strcspn($raw, '"\\', $j);  
                if ($j >= $n) return;  
                if ($raw[$j] === '\\') { $j += 2; continue; }  
                break;  
            }  
            if ($d >= 1 && $d <= 3 && !empty($isObj[$d])) {  
                $k = $j + 1;  
                while ($k < $n && ctype_space($raw[$k])) $k++;  
                if ($k < $n && $raw[$k] === ':') $key[$d] = json_decode(substr($raw, $i, $j - $i + 1));  
            }  
            $i = $j + 1;  
            continue;  
        }  
        if ($c === '{' || $c === '[') {  
            if ($d >= 1 && empty($isObj[$d]) && $d <= 3) $key[$d] = $idx[$d]++;  
            $d++;  
            $isObj[$d] = ($c === '{'); $idx[$d] = 0;  
            if ($d === 4) $start = $i;  
        } else {  
            if ($d === 4) {  
                $item = json_decode(substr($raw, $start, $i - $start + 1), true);  
                if (is_array($item) && ($key[1] ?? '') === 'data') $cb($key[3] ?? '', $item, (string)($key[2] ?? ''));  
            }  
            $d--;  
        }  
        $i++;  
    }  
}  

function blk_catalog($existing) {  
    global $BK_API, $BK_FILE, $BK_TTL;  
    $list = null;  
    if (is_file($BK_FILE) && (time() - filemtime($BK_FILE)) < $BK_TTL) {  
        $list = json_decode((string)@file_get_contents($BK_FILE), true);  
    }  
    if (!is_array($list)) {  
        $raw = null;  
        for ($__try = 0; $__try < 3; $__try++) {  
            $raw = cGet($BK_API, 40);  
            if ($raw) break;  
            if ($__try < 2) sleep($__try + 1);  
        }  
        $list = [];  
        if ($raw) {  
            $scanned = 0;  
            blk_scan($raw, function($id, $src, $cat) use (&$list, &$scanned) {  
                $scanned++;  
                $it = blk_item($id, $src, $cat);  
                if ($it) $list[] = $it;  
            });  
            @error_log('[Blk] raw=' . strlen($raw) . ' scanned=' . $scanned . ' added=' . count($list));  
        } else {  
            @error_log('[Blk] API fetch failed: ' . $BK_API);  
        }  
        unset($raw);  
        if ($list) {  
            @file_put_contents($BK_FILE, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);  
        } elseif (is_file($BK_FILE)) {  
            $list = json_decode((string)@file_get_contents($BK_FILE), true);  
            @touch($BK_FILE);  
        } else {  
            $list = null;  
        }  
    }  
    if (!is_array($list)) return [];  
    $have = [];  
    foreach ($existing as $a) {  
        if (!is_array($a) || empty($a['title'])) continue;  
        $k = ($a['category'] ?? '') === 'movie' ? 'movie' : ((($a['category'] ?? '') === 'hentai') ? 'hentai' : 'anime');  
        $have[$k . '|' . strtolower(trim((string)$a['title']))] = 1;  
    }  
    return array_values(array_filter($list, fn($x) => empty($have[$x['category'] . '|' . strtolower(trim((string)$x['title']))])));  
}  

/* ============ FIREBASE ============ */  
$FB_URL    = rtrim(getenv('FB_DB_URL') ?: 'https://move-tok-default-rtdb.firebaseio.com', '/');  
$FB_SECRET = getenv('FB_DB_SECRET') ?: '';  
$FB_SA     = getenv('FB_SERVICE_ACCOUNT') ?: '';  
function vote_key($k) { return substr(md5(strtolower(trim((string)$k))), 0, 16); }  
function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }  
function fb_token() {  
    global $FB_SA, $FB_SECRET;  
    if ($FB_SA) {  
        $f = sys_get_temp_dir() . '/mt.fbtok.json';  
        if (is_file($f)) {  
            $c = json_decode((string)@file_get_contents($f), true);  
            if (!empty($c['t']) && ($c['e'] ?? 0) > time() + 60) return ['access_token', $c['t']];  
        }  
        $sa = json_decode($FB_SA, true);  
        if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) return null;  
        $now = time();  
        $h = b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));  
        $p = b64u(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));  
        if (!function_exists('openssl_sign') || !openssl_sign("$h.$p", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) return null;  
        $ch = curl_init('https://oauth2.googleapis.com/token');  
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,  
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$h.$p." . b64u($sig)])]);  
        $out = curl_exec($ch); curl_close($ch);  
        $j = $out ? json_decode($out, true) : null;  
        if (empty($j['access_token'])) return null;  
        @file_put_contents($f, json_encode(['t' => $j['access_token'], 'e' => $now + (int)($j['expires_in'] ?? 3000)]), LOCK_EX);  
        return ['access_token', $j['access_token']];  
    }  
    if ($FB_SECRET) return ['auth', $FB_SECRET];  
    return ['no_auth', '']; // Fallback if DB is open
}  
function fb($method, $path, $q = [], $body = null) {  
    global $FB_URL;  
    $t = fb_token();  
    if (!$t) return ['ok' => false, 'd' => null, 'err' => 'setup'];  
    if ($t[0] !== 'no_auth') {
        $q[$t[0]] = $t[1];  
    }
    $ch = curl_init($FB_URL . '/' . $path . '.json?' . http_build_query($q));  
    $o = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,  
          CURLOPT_HTTPHEADER => ['Content-Type: application/json']];  
    if ($body !== null) $o[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);  
    curl_setopt_array($ch, $o);  
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);  
    if ($out === false || $code < 200 || $code >= 300) return ['ok' => false, 'd' => null, 'err' => 'http' . $code];  
    return ['ok' => true, 'd' => json_decode($out, true)];  
}  
function votes_load() {  
    $f = sys_get_temp_dir() . '/mt.votes.cache.json';  
    if (is_file($f) && time() - filemtime($f) < 20) {  
        $c = json_decode((string)@file_get_contents($f), true);  
        if (is_array($c)) return $c;  
    }  
    $r = fb('GET', 'votes');  
    $out = [];  
    if ($r['ok'] && is_array($r['d'])) foreach ($r['d'] as $k => $e) if (is_array($e)) $out[$k] = ['l' => max(0, (int)($e['l'] ?? 0)), 'd' => max(0, (int)($e['d'] ?? 0))];  
    if ($r['ok']) @file_put_contents($f, json_encode($out), LOCK_EX);  
    return $out;  
}  
function rate_ok($tag, $sec) {  
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'x'))[0]);  
    $f = sys_get_temp_dir() . '/mt.rl.' . $tag . '.' . md5($ip);  
    if (is_file($f) && time() - filemtime($f) < $sec) return false;  
    @touch($f);  
    return true;  
}  
function clean_txt($s, $max) {  
    $s = (string)$s;  
    if (function_exists('iconv')) { $x = @iconv('UTF-8', 'UTF-8//IGNORE', $s); if ($x !== false) $s = $x; }  
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);  
    $s = trim($s);  
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);  
}  

$__a0 = $_GET['action'] ?? '';  
if (in_array($__a0, ['votes', 'vote', 'cm', 'cm_add'], true)) {  
    header('Content-Type: application/json; charset=utf-8');  
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';  
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);  
    $sfs  = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';  
    $orig = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');  
    $oh   = ($orig !== '' && $orig !== 'null') ? strtolower((string)parse_url($orig, PHP_URL_HOST)) : '';  
    $same = $sfs !== '' ? ($sfs === 'same-origin') : ($isPost ? ($oh !== '' && $oh === $host) : ($oh === '' || $oh === $host));  
    if (!$same) { http_response_code(403); echo jEnc(['ok' => false, 'error' => 'forbidden']); exit; }  
    
    if ($__a0 === 'votes' || $__a0 === 'vote') {
        $k = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_REQUEST['k'] ?? ''));  
        if ($k === '' || strlen($k) > 40) { echo jEnc(['ok' => false, 'error' => 'bad key']); exit; }  
        if ($__a0 === 'vote') {  
            if (!$isPost) { echo jEnc(['ok' => false]); exit; }  
            $okv = ['like', 'dislike', 'none'];  
            $v = in_array($_POST['v'] ?? '', $okv, true) ? $_POST['v'] : 'none';  
            $p = in_array($_POST['p'] ?? '', $okv, true) ? $_POST['p'] : 'none';  
            $dl = ($v === 'like' ? 1 : 0) - ($p === 'like' ? 1 : 0);  
            $dd = ($v === 'dislike' ? 1 : 0) - ($p === 'dislike' ? 1 : 0);  
            if ($dl || $dd) {  
                $b = [];  
                if ($dl) $b['l'] = ['.sv' => ['increment' => $dl]];  
                if ($dd) $b['d'] = ['.sv' => ['increment' => $dd]];  
                fb('PATCH', 'votes/' . $k, [], $b);  
            }  
        }  
        $r = fb('GET', 'votes/' . $k);  
        if (!$r['ok']) { echo jEnc(['ok' => false, 'error' => 'server']); exit; }  
        $e = is_array($r['d']) ? $r['d'] : [];  
        echo jEnc(['ok' => true, 'l' => max(0, (int)($e['l'] ?? 0)), 'd' => max(0, (int)($e['d'] ?? 0))]);  
        exit;  
    }  
    if ($__a0 === 'cm') {  
        $k = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_REQUEST['k'] ?? ''));  
        if ($k === '' || strlen($k) > 40) { echo jEnc(['ok' => false, 'error' => 'bad key']); exit; }  
        $r = fb('GET', 'comments/' . $k, ['orderBy' => '"$key"', 'limitToLast' => 100]);  
        if (!$r['ok']) { echo jEnc(['ok' => false, 'error' => 'server']); exit; }  
        $d = is_array($r['d']) ? $r['d'] : [];  
        ksort($d);  
        $items = [];  
        foreach (array_reverse($d, true) as $c) {  
            if (is_array($c) && !empty($c['t'])) $items[] = ['n' => clean_txt($c['n'] ?? 'User', 30), 't' => clean_txt($c['t'], 500), 'ts' => (int)($c['ts'] ?? 0)];  
        }  
        echo jEnc(['ok' => true, 'items' => $items]);  
        exit;  
    }  
    if ($__a0 === 'cm_add') {  
        $k = preg_replace('/[^a-zA-Z0-9]/', '', (string)($_REQUEST['k'] ?? ''));  
        if ($k === '' || strlen($k) > 40) { echo jEnc(['ok' => false, 'error' => 'bad key']); exit; }  
        if (!$isPost) { echo jEnc(['ok' => false]); exit; }  
        $n = clean_txt($_POST['n'] ?? '', 30);  
        $t = clean_txt($_POST['t'] ?? '', 500);  
        if ($n === '') { echo jEnc(['ok' => false, 'error' => 'Pehle apna naam likho.']); exit; }  
        if ($t === '') { echo jEnc(['ok' => false, 'error' => 'Comment likho.']); exit; }  
        if (!rate_ok('cm', 5)) { echo jEnc(['ok' => false, 'error' => 'Thoda ruko, 5 second baad dobara comment karo.']); exit; }  
        $r = fb('POST', 'comments/' . $k, [], ['n' => $n, 't' => $t, 'ts' => ['.sv' => 'timestamp']]);  
        echo jEnc($r['ok'] ? ['ok' => true] : ['ok' => false, 'error' => 'Comment post nahi hua.']);  
        exit;  
    }  
}  

/* ============ PROXY ============ */  
$__act = $_GET['action'] ?? '';  
if ($__act === 'proxy') {  
    $url = $_GET['url'] ?? '';  
    if (!$url) { http_response_code(403); exit('forbidden'); }  
    if (!preg_match('#^https?://#i', $url)) { http_response_code(403); exit('forbidden'); }  
    $phost = parse_url($url, PHP_URL_HOST);  
    if (!$phost || !preg_match('/(^|\.)rumble\.(cloud|com)$/i', $phost)) { http_response_code(403); exit('forbidden'); }  
    while (ob_get_level()) ob_end_clean();  
    $sendH = [  
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36',  
        'Referer: https://blakiteapi.xyz/',  
        'Origin: https://blakiteapi.xyz',  
    ];  
    if (!empty($_SERVER['HTTP_RANGE'])) $sendH[] = 'Range: ' . $_SERVER['HTTP_RANGE'];  
    header('Access-Control-Allow-Origin: *');  
    header('Accept-Ranges: bytes');  
    $ch = curl_init($url);  
    curl_setopt_array($ch, [  
        CURLOPT_FOLLOWLOCATION => true,  
        CURLOPT_MAXREDIRS => 3,  
        CURLOPT_SSL_VERIFYPEER => false,  
        CURLOPT_SSL_VERIFYHOST => false,  
        CURLOPT_TIMEOUT => 0,  
        CURLOPT_CONNECTTIMEOUT => 10,  
        CURLOPT_HTTPHEADER => $sendH,  
        CURLOPT_HEADERFUNCTION => function($ch, $h) {  
            $len = strlen($h);  
            $t = trim($h);  
            if ($t === '' || stripos($t, 'HTTP/') === 0) return $len;  
            $parts = explode(':', $t, 2);  
            if (count($parts) === 2) {  
                $n = strtolower(trim($parts[0]));  
                $v = trim($parts[1]);  
                if (in_array($n, ['content-type','content-length','content-range','accept-ranges'])) {  
                    header($parts[0] . ': ' . $v);  
                }  
            }  
            return $len;  
        },  
        CURLOPT_WRITEFUNCTION => function($ch, $data) {  
            echo $data;  
            flush();  
            return strlen($data);  
        },  
    ]);  
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP')) {  
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);  
    }  
    curl_exec($ch);  
    curl_close($ch);  
    exit;  
}  

/* ============ STREAM ============ */  
if ($__act === 'stream') {  
    header('Content-Type: application/json; charset=utf-8');  
    $tmdbId   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['tmdbId'] ?? '');  
    $uniqueId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['uniqueId'] ?? '');  
    $isMovie  = empty($uniqueId);  
    if (!$tmdbId) { echo jEnc(['ok'=>false, 'error'=>'no tmdbId']); exit; }  

    $variants = array_values(array_unique(array_filter([  
        $tmdbId,  
        ltrim($tmdbId, '0'),  
        str_pad(ltrim($tmdbId, '0'), 6, '0', STR_PAD_LEFT),  
        str_pad(ltrim($tmdbId, '0'), 7, '0', STR_PAD_LEFT),  
        str_pad(ltrim($tmdbId, '0'), 10, '0', STR_PAD_LEFT),  
    ])));  

    $uidVariants = $isMovie ? [null] : [$uniqueId];  
    $apiData = null;  
    $usedTmdb = $tmdbId;  
    $usedUid = $uniqueId;  
    $lastRaw = '';  

    foreach ($variants as $v) {  
        foreach ($uidVariants as $u) {  
            $apiUrl = $u  
                ? "$BK_EMBED/api/get.php?id=" . urlencode($u) . "&tmdbId=" . urlencode($v)  
                : "$BK_EMBED/api/get.php?tmdbId=" . urlencode($v);  
            $raw = cGet($apiUrl, 12, "$BK_EMBED/");  
            $lastRaw = $raw ?: $lastRaw;  
            if (!$raw) continue;  
            $j = json_decode($raw, true);  
            if ($j && !empty($j['data']['dataId'])) {  
                $apiData = $j['data'];  
                $usedTmdb = $v;  
                $usedUid = $u;  
                break 2;  
            }  
        }  
    }  

    if (!$apiData) {  
        if ($lastRaw) {  
            @error_log('[Stream] no data tmdbId=' . $tmdbId . ' uid=' . $uniqueId . ' raw=' . substr($lastRaw, 0, 300));  
        }  
        echo jEnc(['ok' => false, 'error' => 'Stream not found']);  
        exit;  
    }  

    $dataId = $apiData['dataId'];  
    $format = strtoupper($apiData['format'] ?? 'M3U8');  
    $baseDURL = "https://hugh.cdn.rumble.cloud/video/";  
    $codeMap = ['240p'=>'oaa','360p'=>'baa','480p'=>'caa','720p'=>'gaa','1080p'=>'haa'];  
    $streams = [];  

    if ($format === 'M3U8') {  
        $ranges = [];  
        if (!empty($apiData['ranges'])) {  
            foreach (explode("\n", $apiData['ranges']) as $line) {  
                $line = trim($line);  
                if (preg_match('/(\d+\s*-\s*\d+)\s*\((\d+)\s*p\)/i', $line, $m)) {  
                    $ranges[(int)$m[2].'p'] = preg_replace('/\s+/', '', $m[1]);  
                }  
            }  
        }  
        foreach ($codeMap as $label => $code) {  
            if (!isset($ranges[$label])) continue;  
            $rng = urlencode($ranges[$label]);  
            $url = "{$baseDURL}{$dataId}.{$code}.tar?r_file=chunklist.m3u8&r_type=application%2Fvnd.apple.mpegurl&r_range={$rng}";  
            $streams[] = ['quality'=>$label, 'url'=>$url, 'type'=>'hls'];  
        }  
    }  

    if ($format === 'MP4' || empty($streams)) {  
        $qid = (int)($apiData['qid'] ?? 0);  
        $count = $qid > 0 ? min($qid, 5) : 4;  
        $codes = array_slice($codeMap, 0, $count, true);  
        foreach ($codes as $label => $code) {  
            $url = "{$baseDURL}{$dataId}.{$code}.mp4";  
            $streams[] = ['quality'=>$label, 'url'=>$url, 'type'=>'mp4'];  
        }  
    }  

    if (empty($streams)) {  
        echo jEnc(['ok'=>false, 'error'=>'No streams', 'format'=>$format]);  
        exit;  
    }  

    usort($streams, fn($a,$b) => (int)$a['quality'] - (int)$b['quality']);  
    $default = $apiData['quality'] ?? null;  
    if (!$default || !in_array($default, array_column($streams, 'quality'))) {  
        $default = $streams[count($streams)-1]['quality'];  
    }  

    echo jEnc([  
        'ok' => true,  
        'title' => $apiData['animeTitle'] ?? '',  
        'format' => $format,  
        'streams' => $streams,  
        'default' => $default,  
        'poster' => $apiData['poster'] ?? '',  
        'usedTmdb' => $usedTmdb,  
        'usedUid' => $usedUid,  
        'dataId' => $dataId,  
    ]);  
    exit;  
}  

/* ============ LOAD CATALOG ============ */    
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
    $bk = blk_catalog($merged['anime']);    
    if ($bk) { $any = true; $merged['anime'] = array_merge($merged['anime'], $bk); }    
    if ($any) { $raw = json_encode($merged); @file_put_contents($CACHE_FILE, $raw, LOCK_EX); }    
    elseif (is_file($CACHE_FILE)) { $raw = file_get_contents($CACHE_FILE); }    
}    
$catalog = $raw ? json_decode($raw, true) : null;    
if (isset($_GET['debug'])) {    
    header('Content-Type: text/plain; charset=utf-8');    
    echo "owner  : $GH_OWNER\nrepo   : $GH_REPO\nbranch : $GH_BRANCH\npaths  : " . implode(', ', $GH_PATHS) . "\n";    
    echo "token  : " . ($GH_TOKEN ? 'set' : 'NOT set') . "\n";    
    echo "fetch  : " . ($GH_DEBUG ?: 'cache used / not attempted') . "\n";    
    echo "catalog: " . ($catalog ? count($catalog['anime'] ?? []) . ' titles' : 'EMPTY') . "\n";    
    exit;    
}    
$VOTES_MAP = [];  
if (is_array($catalog) && isset($catalog['anime']) && is_array($catalog['anime'])) {  
    $__v = votes_load();  
    foreach ($catalog['anime'] as &$__a) {  
        if (!is_array($__a) || empty($__a['title'])) continue;  
        $__c = $__a['category'] ?? '';  
        $__t = $__c === 'movie' ? 'Movie' : ($__c === 'hentai' ? 'Hentai' : 'Anime');  
        $__a['vk'] = vote_key($__t . '|' . trim((string)$__a['title']));  
        if (isset($__v[$__a['vk']])) $VOTES_MAP[$__a['vk']] = [(int)$__v[$__a['vk']]['l'], (int)$__v[$__a['vk']]['d']];  
    }  
    unset($__a);  
}  
$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');  
$SITE_URL = ($__https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');  
$SITE_NAME = 'Move Tok';  
$SITE_TITLE = 'Move Tok - Hindi Movies & K-Dramas';  
$SITE_DESC = 'Watch Hindi dubbed movies, K-Dramas and anime online in HD on Move Tok. Latest episodes, full movies and top rated anime in one place.';  
$SITE_IMG = 'https://i.ibb.co/d4hNqPZX/move-tok-logo-black-transparent.png';  
$h = fn($x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');  
?>  
<!DOCTYPE html>  
<html lang="en">    
<head>    
<meta charset="UTF-8">    
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">    
<meta name="theme-color" content="#ffffff">    
<meta name="referrer" content="no-referrer">    
<title><?= $h($SITE_TITLE) ?></title>  
<meta name="description" content="<?= $h($SITE_DESC) ?>">  
<meta name="keywords" content="Move Tok, Hindi movies, Hindi dubbed anime, K-Dramas, Korean drama Hindi, watch anime online, full movies, top rated anime">  
<meta name="author" content="Harsh Singh">  
<meta name="robots" content="index, follow, max-image-preview:large">  
<link rel="canonical" href="<?= $h($SITE_URL) ?>">  
<link rel="icon" href="<?= $h($SITE_IMG) ?>">  
<meta property="og:type" content="website">  
<meta property="og:site_name" content="<?= $h($SITE_NAME) ?>">  
<meta property="og:title" content="<?= $h($SITE_TITLE) ?>">  
<meta property="og:description" content="<?= $h($SITE_DESC) ?>">  
<meta property="og:url" content="<?= $h($SITE_URL) ?>">  
<meta property="og:image" content="<?= $h($SITE_IMG) ?>">  
<meta name="twitter:card" content="summary_large_image">  
<meta name="twitter:title" content="<?= $h($SITE_TITLE) ?>">  
<meta name="twitter:description" content="<?= $h($SITE_DESC) ?>">  
<meta name="twitter:image" content="<?= $h($SITE_IMG) ?>">  
<script type="application/ld+json"><?= json_encode(['@context'=>'https://schema.org','@type'=>'WebSite','name'=>$SITE_NAME,'url'=>$SITE_URL,'description'=>$SITE_DESC,'inLanguage'=>'hi','publisher'=>['@type'=>'Organization','name'=>$SITE_NAME,'logo'=>$SITE_IMG]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?></script>    
<link rel="preconnect" href="https://fonts.googleapis.com">    
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>    
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">    
<style>  
/* ============ BLACK & WHITE THEME ============ */
:root{
  --bg:#f5f6fa;
  --bg2:#ffffff;
  --text:#1a1a2e;
  --muted:#64748b;
  --red:#111111;
  --pink:#111111;
  --violet:#222222;
  --stroke:rgba(0,0,0,.08);
  --stroke2:rgba(0,0,0,.12);
  --glass:linear-gradient(135deg,rgba(255,255,255,.96),rgba(255,255,255,.85));
  --glass2:rgba(255,255,255,.75);
  --shadow:0 10px 30px rgba(20,20,50,.08),inset 0 1px 0 rgba(255,255,255,.95);
  --shadow-sm:0 4px 14px rgba(20,20,50,.06);
  --grad:linear-gradient(135deg, #111, #333);
  --blur:blur(18px) saturate(150%);
  --head:56px;
  --img-overlay:linear-gradient(to top,rgba(6,6,12,.92) 5%,rgba(6,6,12,.55) 45%,transparent);
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}  
html{color-scheme:light}  
body{margin:0;background:var(--bg);color:var(--text);font-family:Nunito,Arial,sans-serif;min-height:100vh;-webkit-font-smoothing:antialiased}  
body::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(60% 42% at 8% -4%,rgba(17,17,17,.08),transparent 70%),radial-gradient(48% 40% at 104% 18%,rgba(34,34,34,.08),transparent 70%),radial-gradient(70% 50% at 50% 108%,rgba(0,0,0,.04),transparent 70%)}  
button,input,textarea{font:inherit}  
button{cursor:pointer;touch-action:manipulation;color:inherit;background:none;border:0;padding:0}  
[hidden]{display:none!important}  
.ic{width:1em;height:1em;fill:currentColor;display:block;pointer-events:none}  
::-webkit-scrollbar{width:8px;height:8px}  
::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:8px}  
.hero-track::-webkit-scrollbar,.filters::-webkit-scrollbar,.seasons::-webkit-scrollbar,.hrow::-webkit-scrollbar,.toptrack::-webkit-scrollbar{display:none}  

/* ---------- header ---------- */
header{position:relative;z-index:10;display:flex;align-items:center;gap:10px;margin:8px auto 0;width:calc(100% - 24px);max-width:1176px;padding:8px 12px;border-radius:22px;background:rgba(255,255,255,.9);border:1px solid var(--stroke);-webkit-backdrop-filter:var(--blur);backdrop-filter:var(--blur);box-shadow:var(--shadow)}
.logo{width:44px;height:44px;object-fit:cover;border-radius:50%;border:2px solid var(--stroke2);background:#fff;flex:0 0 auto;box-shadow:0 0 12px rgba(0,0,0,.1)}
.brand{font-size:23px;font-weight:900;white-space:nowrap;letter-spacing:-.4px;color:var(--text)}
.sbox{display:flex;align-items:center;margin-left:auto;gap:6px;padding:2px;border-radius:50px;background:rgba(0,0,0,.04);border:1px solid var(--stroke)}
.sbtn{display:flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;color:var(--text)}
.sbtn svg{width:21px;height:21px;fill:none}
.search{display:none;margin:0;width:0;flex:1;min-width:0;border:0;background:transparent;color:var(--text);padding:8px 6px;font-size:15px;outline:none}
.search::placeholder{color:var(--muted);opacity:1}
header.s-open .brand,header.s-open .logo{display:none}
header.s-open .sbox{flex:1;margin-left:0}
header.s-open .search{display:block;width:100%}

/* 3 DOT MENU BUTTON */
.menu-btn{display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:50%;color:var(--text);flex:0 0 auto;background:#fff;border:1px solid var(--stroke);box-shadow:var(--shadow-sm);transition:all .2s}
.menu-btn:active{background:rgba(0,0,0,.05)}
.menu-btn svg{width:24px;height:24px;fill:currentColor}

@media(min-width:900px){
.sbox{display:contents}
.sbtn{display:none}
.search{display:block;margin-left:auto;flex:1;width:100%;max-width:340px;border:1px solid var(--stroke);border-radius:14px;background:#fff;padding:11px 14px;font-size:14px;transition:border-color .2s,box-shadow .2s;box-shadow:var(--shadow-sm)}
.search:focus{border-color:#111;box-shadow:0 0 0 3px rgba(0,0,0,.1)}
}

/* SIDE MENU */
.side-menu{position:fixed;top:0;right:-100%;width:280px;height:100%;background:#fff;z-index:200;transition:right .3s;box-shadow:-5px 0 30px rgba(0,0,0,.15);padding:20px;display:flex;flex-direction:column;gap:10px;overflow-y:auto}
.side-menu.open{right:0}
.menu-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:199;display:none;opacity:0;transition:opacity .3s}
.menu-overlay.open{display:block;opacity:1}
.side-menu h2{font-size:18px;margin:0 0 15px;color:var(--text);display:flex;justify-content:space-between;align-items:center}
.side-menu h2 button{font-size:22px;color:var(--muted);padding:5px}
.side-menu a{display:block;padding:14px 16px;border-radius:12px;background:rgba(0,0,0,.03);color:var(--text);font-weight:700;font-size:15px;text-decoration:none;transition:all .2s}
.side-menu a:hover,.side-menu a:active{background:#111;color:#fff}

/* PAGE CONTENT (Privacy, Terms etc.) */
.page-content{display:none;max-width:800px;margin:20px auto;padding:24px;background:#fff;border-radius:20px;border:1px solid var(--stroke);box-shadow:var(--shadow);min-height:60vh}
.page-content h1{font-size:26px;margin-top:0;color:var(--text);margin-bottom:20px}
.page-content p{line-height:1.7;color:var(--muted);margin-bottom:16px;font-size:15px}
.page-content a{color:#111;font-weight:700;text-decoration:none}

main{max-width:1200px;margin:auto;padding:6px 14px 40px}
h2{display:flex;align-items:center;gap:10px;font-size:20px;font-weight:900;color:var(--text);margin:22px 0 12px;letter-spacing:-.2px}
h2::before{content:"";display:inline-block;width:4px;height:22px;border-radius:4px;background:#111;box-shadow:0 0 12px rgba(0,0,0,.2)}
h2 svg{width:20px;height:20px;fill:var(--text)}
.row-head{display:flex;align-items:center;justify-content:space-between}
.row-head h2{margin:22px 0 12px}
.card-sub{color:var(--muted);font-size:11.5px;font-weight:600}

/* ---------- hero ---------- */
.hero{position:relative;margin:14px 0 0;border-radius:26px;overflow:hidden;background:var(--glass);border:1px solid var(--stroke);box-shadow:var(--shadow)}
.hero-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.hero-slide{flex:0 0 100%;scroll-snap-align:start;position:relative;height:min(64vh,520px);overflow:hidden}
.hero-media{position:absolute;inset:0;background:#0b0b14;overflow:hidden}
.hero-media .bg{display:none}
.hero-media .fg{display:block;position:absolute;inset:0;width:100%;height:100%;object-fit:contain;object-position:center 30%}
.hero-media::before{content:"";position:absolute;inset:-30px;background:var(--pimg) center/cover no-repeat;filter:blur(32px) brightness(.5) saturate(1.3)}
.hero-media::after{content:"";position:absolute;inset:auto 0 0;height:65%;background:var(--img-overlay);pointer-events:none}
.hero-body{position:absolute;left:0;right:0;bottom:0;padding:0 16px 16px;z-index:2;display:flex;flex-direction:column;align-items:center;text-align:center}
.hero-kind{display:flex;flex-wrap:wrap;justify-content:center;gap:8px}
.hero-kind i{display:none}
.hero-kind span{padding:5px 12px;border-radius:50px;font-size:12px;font-weight:800;color:#fff;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.hero-kind span:first-child{background:rgba(17,17,17,.85);border-color:rgba(0,0,0,.9)}
.hero-title{margin:10px 0 14px;font-size:clamp(22px,6vw,34px);font-weight:900;line-height:1.2;color:#fff;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;text-shadow:0 2px 14px rgba(0,0,0,.85)}
.hero-actions{display:flex;gap:10px;width:100%;max-width:380px}
.hbtn{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 18px;border-radius:50px;font-weight:800;font-size:15px;white-space:nowrap;color:#fff;border:1px solid transparent;-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);transition:transform .15s,box-shadow .2s}
.hbtn .ic{font-size:20px}
.hbtn.play{background:var(--grad);border-color:rgba(255,255,255,.3);box-shadow:0 8px 24px rgba(0,0,0,.45)}
.hbtn.info{background:rgba(255,255,255,.18);border-color:rgba(255,255,255,.3)}
.hbtn:active{transform:scale(.95)}
.dots{display:flex;justify-content:center;gap:6px;padding:12px 0;background:rgba(0,0,0,.04)}
.dot{width:6px;height:6px;padding:0;border:0;border-radius:3px;background:rgba(0,0,0,.2);transition:all .25s}
.dot.active{width:22px;background:#111}
.hero-nav{display:none;position:absolute;top:50%;margin-top:-40px;z-index:3;width:44px;height:72px;color:#fff;font-size:28px;align-items:center;justify-content:center;border-radius:14px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.hero-nav.prev{left:12px}.hero-nav.next{right:12px}

/* ---------- filters ---------- */
.filters{display:flex;gap:10px;overflow-x:auto;padding:4px 2px 16px;scrollbar-width:none}
.filter,.season-btn{flex:0 0 auto;padding:10px 18px;border-radius:30px;background:#fff;border:1px solid var(--stroke);color:var(--text);font-weight:800;font-size:13px;transition:all .2s;box-shadow:var(--shadow-sm)}
.filter.active,.season-btn.active,.episode-btn.active{background:var(--grad);color:#fff;border-color:transparent;box-shadow:0 6px 20px rgba(0,0,0,.3)}

/* ---------- rows + grid ---------- */
.hrow{display:flex;gap:14px;overflow-x:auto;padding:2px 2px 10px;scrollbar-width:none;scroll-snap-type:x proximity}
.rcard{flex:0 0 140px;scroll-snap-align:start;cursor:pointer;transition:transform .2s}
.rcard .card-title{font-size:13.5px;margin-top:8px;padding:0 4px}
.rcard .badge{font-size:11px;padding:5px 10px;bottom:8px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px 14px}
.card{min-width:0;cursor:pointer;background:none;border:0;transition:transform .22s}
.card:active,.rcard:active{transform:scale(.97)}
.poster{position:relative;aspect-ratio:3/4;border-radius:22px;background:#e8eaf0;overflow:hidden;border:1px solid var(--stroke);box-shadow:0 8px 24px rgba(20,20,50,.1),inset 0 1px 0 rgba(255,255,255,.8)}
.poster .bg{display:none}
.poster .fg{display:block;width:100%;height:100%;object-fit:cover}
.poster::after{content:"";position:absolute;inset:auto 0 0;height:45%;background:linear-gradient(to top,rgba(0,0,0,.75),transparent);pointer-events:none}
.badge{position:absolute;left:50%;bottom:10px;transform:translateX(-50%);z-index:2;max-width:90%;padding:6px 12px;border-radius:20px;background:rgba(14,14,24,.72);border:1px solid rgba(255,255,255,.22);color:#fff;font-size:12px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.new-tag{position:absolute;top:8px;left:8px;z-index:3;background:#111;color:#fff;font-size:10px;font-weight:900;padding:3px 9px;border-radius:10px;letter-spacing:.4px;box-shadow:0 4px 12px rgba(0,0,0,.4)}
.pbar{position:absolute;left:0;right:0;bottom:0;height:4px;background:rgba(255,255,255,.3);z-index:3}
.pbar i{display:block;height:100%;background:#111}
.card-info{padding:10px 4px 0}
.card-title{font-size:15px;font-weight:800;line-height:1.3;color:var(--text);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}
.card-info .card-sub{display:none}
.empty{grid-column:1/-1;padding:34px 10px;text-align:center;color:var(--muted)}

/* ---------- watch page ---------- */
.watch{display:none;position:fixed;inset:0;z-index:100;overflow-y:auto;background:radial-gradient(60% 40% at 8% 0%,rgba(17,17,17,.06),transparent 70%),radial-gradient(50% 40% at 100% 22%,rgba(34,34,34,.06),transparent 70%),var(--bg)}
.watch-head{position:relative;z-index:7;height:var(--head);display:flex;align-items:center;padding:0 16px;background:rgba(255,255,255,.9);-webkit-backdrop-filter:var(--blur);backdrop-filter:var(--blur);border-bottom:1px solid var(--stroke)}
.watch-head span{font-weight:800;font-size:15px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ---------- video player ---------- */
.video-box{position:relative;z-index:5;width:100%;aspect-ratio:16/9;max-height:70vh;overflow:hidden;user-select:none;-webkit-user-select:none;margin:0 auto;border-bottom:1px solid rgba(255,255,255,.1); background: #000 !important;}
.video-box:fullscreen{max-height:none;max-width:none;aspect-ratio:auto;height:100%;margin:0;border-radius:0;background:#000}
.video-box:-webkit-full-screen{max-height:none;max-width:none;aspect-ratio:auto;height:100%;margin:0;border-radius:0;background:#000}
.video-box.idle{cursor:none}
video{width:100%;height:100%;object-fit:contain;background:transparent;display:block}

/* NEW NORMAL SPINNER */
.v-spinner { display: none; position: absolute; top: 50%; left: 50%; width: 48px; height: 48px; margin: -24px 0 0 -24px; border: 4px solid rgba(255,255,255,0.2); border-top-color: #fff; border-radius: 50%; animation: vspin 1s linear infinite; z-index: 10; pointer-events: none; }
@keyframes vspin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
.loading .v-spinner { display: block; }
.loading #big { opacity: 0; }
.loading .center button { opacity: 0; pointer-events: none; }

/* PLAYER B&W & TRANSPARENT CONTROLS */
.bottom { position:absolute; left:0; right:0; bottom:0; z-index:3; padding:34px 14px 10px; background:linear-gradient(to top,rgba(0,0,0,0.95) 0%,rgba(0,0,0,0.6) 50%,transparent); transition:opacity .25s }
.center { position:absolute; inset:0; z-index:2; display:flex; align-items:center; justify-content:center; gap:clamp(18px,8vw,60px); pointer-events:none; transition:opacity .25s }
.center button { pointer-events:auto; color:#fff; background:rgba(0,0,0,0.15) !important; border:1px solid rgba(255,255,255,0.4) !important; border-radius:50%; width:46px; height:46px; font-size:28px; display:flex; align-items:center; justify-content:center; -webkit-backdrop-filter:blur(4px); backdrop-filter:blur(4px); box-shadow: none !important; }
.center #big { width:64px; height:64px; font-size:44px; background:rgba(0,0,0,0.2) !important; border:2px solid rgba(255,255,255,0.6) !important; box-shadow: none !important; }
.center .ic { filter:drop-shadow(0 2px 6px rgba(0,0,0,.8)) }
.idle .bottom,.idle .center{opacity:0;pointer-events:none}.idle .center button{pointer-events:none}

.prog{position:relative;height:22px;display:flex;align-items:center;cursor:pointer;touch-action:none}
.track{position:relative;width:100%;height:4px;border-radius:2px;background:rgba(255,255,255,.2) !important;transition:height .15s}
.prog:hover .track{height:6px}
.buf,.bar{position:absolute;left:0;top:0;height:100%;border-radius:2px;width:0}
.buf{background:rgba(255,255,255,.3) !important}
.bar{background:#fff !important; box-shadow: none !important;}
.bar::after{content:"";position:absolute;right:-7px;top:50%;width:14px;height:14px;margin-top:-7px;background:#fff !important;border:2px solid #fff !important;border-radius:50%; box-shadow: none !important;}
.ctrls{display:flex;align-items:center;gap:6px;color:#fff}
.ctrl-btn{color:#fff;font-size:26px;min-width:40px;height:40px;display:flex;align-items:center;justify-content:center;border-radius:12px;transition:background .15s,color .15s}
.ctrl-btn:hover{background:rgba(255,255,255,0.2) !important}
.ctrl-btn:active{background:rgba(255,255,255,0.3) !important}
.time{font-size:12px;font-weight:700;color:#fff;white-space:nowrap;text-shadow:0 1px 6px rgba(0,0,0,.8)}
.sp{flex:1}
.menu{display:none;position:absolute;right:10px;bottom:62px;z-index:6;width:200px;max-height:75%;overflow-y:auto;padding:8px;border:1px solid #444 !important;border-radius:16px;background:rgba(20,20,20,0.95) !important;-webkit-backdrop-filter:var(--blur);backdrop-filter:var(--blur);box-shadow:0 16px 38px rgba(0,0,0,.5) !important}
.menu.open{display:block}
.menu strong{display:block;padding:6px 10px 2px;font-size:11px;color:#fff !important;letter-spacing:.5px;text-transform:uppercase}
.opts{display:flex;flex-wrap:wrap;gap:4px;padding:4px}
.opt{padding:7px 11px;border-radius:9px;background:rgba(255,255,255,0.1) !important;color:#fff !important;font-size:12.5px;font-weight:700;border:1px solid transparent !important;}
.opt.active{background:#fff !important;color:#000 !important;border-color:#fff !important;}
.verr{position:absolute;inset:0;z-index:4;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px;background:rgba(0,0,0,0.9) !important;color:#fff;font-weight:700}

/* ---------- download modal ---------- */
.dl-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.88);padding:20px;-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px)}
.dl-content{background:#1a1a1a;border:1px solid #444;border-radius:20px;padding:22px;max-width:400px;width:100%;color:#fff;box-shadow:0 24px 60px rgba(0,0,0,.7)}
.dl-content h3{margin:0 0 4px;font-size:18px;font-weight:900;color:#fff}
.dl-content p{margin:0 0 14px;color:#aaa;font-size:12.5px;font-weight:700}
.dl-content .opts{padding:0;gap:8px;margin-bottom:18px;display:flex;flex-wrap:wrap}
.dl-content .opt{flex:1 1 auto;min-width:70px;text-align:center;padding:11px 12px;font-size:13px;cursor:pointer}
.dl-actions{display:flex;gap:10px}
.dl-actions .hbtn{flex:1;padding:12px 16px;font-size:14px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3)}
.dl-actions #dlStart{background:#fff;color:#000;border-color:#fff}

/* ---------- details ---------- */
.details{position:relative;padding:20px 16px 44px;overflow:hidden;min-height:60vh}
.details::before{content:"";position:absolute;inset:-40px;background-image:var(--bg-image);background-size:cover;background-position:center;filter:blur(60px) saturate(1.2) brightness(.9);opacity:.3;pointer-events:none}
.details-content{position:relative;max-width:1000px;margin:0 auto}
.anime-info{display:flex;flex-direction:row;align-items:flex-start;gap:15px;margin-bottom:22px;padding:14px;border-radius:24px;background:rgba(255,255,255,.9);border:1px solid var(--stroke);-webkit-backdrop-filter:var(--blur);backdrop-filter:var(--blur);box-shadow:var(--shadow)}
.detail-poster{flex:0 0 auto;width:110px;max-width:110px;height:auto;aspect-ratio:3/4;object-fit:cover;border-radius:16px;border:1px solid var(--stroke);background:#e8eaf0;box-shadow:0 8px 25px rgba(20,20,50,.15)}
.detail-text{flex:1;min-width:0}
.detail-title{font-size:clamp(18px,5vw,28px);line-height:1.25;overflow-wrap:anywhere;color:var(--text);margin:0 0 12px}
.tag{display:inline-block;padding:6px 12px;border-radius:25px;background:rgba(0,0,0,.05);border:1px solid rgba(0,0,0,.1);color:var(--text);font-size:12px;font-weight:800;margin:0 5px 8px 0}
#episodeSection,#seasonSection,#commentSection{padding:0 14px 14px;margin-bottom:14px;border-radius:22px;background:rgba(255,255,255,.9);border:1px solid var(--stroke)}
.seasons{display:flex;gap:10px;overflow-x:auto;padding:4px 2px 4px;scrollbar-width:none}
.season-btn{min-width:118px;padding:11px 16px}
.episodes{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.episode-btn{min-height:56px;border:1px solid var(--stroke);border-radius:14px;background:#fff;color:var(--text);font-size:18px;font-weight:800;transition:all .2s}
#infoPlay{display:none;flex:none;margin-top:6px;padding:10px 20px}
.info-mode #vbox{display:none}
.info-mode #infoPlay{display:flex}

/* ---------- comments ---------- */
.cm-form{display:flex;flex-direction:column;gap:10px}
.cm-form input,.cm-form textarea{width:100%;padding:12px 14px;border-radius:14px;border:1px solid var(--stroke);background:#fff;color:var(--text);font-size:15px;font-weight:600;outline:none;resize:vertical;font-family:inherit}
.cm-form input::placeholder,.cm-form textarea::placeholder{color:#94a3b8;opacity:1}
.cm-form input:focus,.cm-form textarea:focus{border-color:#111;box-shadow:0 0 0 3px rgba(0,0,0,.1)}
.cm-form .hbtn{flex:none;align-self:flex-start;padding:10px 26px;background:#111;color:#fff}
.cm-form .hbtn:disabled{opacity:.6}
.cm-err{color:#ef4444;font-size:13px;font-weight:700;min-height:0}
.cm-list{display:flex;flex-direction:column;gap:10px;margin-top:16px}
.cm-item{padding:12px 14px;border-radius:16px;background:rgba(0,0,0,.03);border:1px solid var(--stroke)}
.cm-top{display:flex;justify-content:space-between;gap:10px;align-items:baseline}
.cm-top b{font-size:14px;color:var(--text);overflow-wrap:anywhere}
.cm-top span{font-size:11px;font-weight:700;color:var(--muted);white-space:nowrap}
.cm-text{margin:6px 0 0;font-size:14px;line-height:1.45;color:var(--text);overflow-wrap:anywhere;white-space:pre-wrap}
.cm-empty{padding:10px 0;text-align:center;color:var(--muted);font-weight:700}

/* ---------- footer ---------- */
.foot{max-width:1176px;margin:10px auto 0;padding:18px 14px calc(26px + env(safe-area-inset-bottom));text-align:center;color:var(--muted);font-size:13px;font-weight:700;border-top:1px solid var(--stroke)}
.sr-only{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);border:0}

/* ---------- top rated slider ---------- */
.toptrack{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;padding:2px 2px 10px;-webkit-overflow-scrolling:touch}
.tslide{flex:0 0 86%;max-width:560px;scroll-snap-align:center;position:relative;height:min(58vh,440px);border-radius:26px;overflow:hidden;background:var(--glass);border:1px solid var(--stroke);box-shadow:var(--shadow);cursor:pointer;scroll-snap-stop:always}
.trank{position:absolute;top:12px;left:12px;z-index:3;background:#111;color:#fff;font-weight:900;font-size:14px;padding:5px 13px;border-radius:14px;box-shadow:0 4px 12px rgba(0,0,0,.4)}
.tbody{position:absolute;left:0;right:0;bottom:0;z-index:2;padding:0 16px 16px;display:flex;flex-direction:column;align-items:flex-start;gap:8px;max-width:60%}
.tbody .hero-title{margin:0}
.tmeta{display:flex;flex-wrap:wrap;gap:8px}
.tmeta span{padding:4px 11px;border-radius:50px;font-size:12px;font-weight:800;color:#fff;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25)}
.tbody .hbtn{flex:none;padding:10px 22px}

/* ---------- video slider landscape ---------- */
.hero-media .ban{display:none}
@media(min-width:650px){
.has-ban .bg,.has-ban .fg{display:none}
.has-ban .ban{display:block;position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 25%}
.hero-nav{display:flex}
.grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:24px 18px}
.rcard{flex-basis:180px}
.card:hover,.rcard:hover{transform:translateY(-4px)}
.card:hover .poster,.rcard:hover .poster{border-color:rgba(0,0,0,.2);box-shadow:0 16px 38px rgba(0,0,0,.15),inset 0 1px 0 rgba(255,255,255,.8)}
.episodes{grid-template-columns:repeat(8,minmax(0,1fr))}
.detail-poster{width:150px;max-width:150px}
.detail-title{font-size:clamp(22px,3vw,32px)}
.video-box{max-width:1000px;margin-top:14px;border-radius:20px;max-height:calc(100vh - 120px);border:1px solid rgba(255,255,255,.1);box-shadow:0 22px 56px rgba(0,0,0,.5)}
.hero-slide{height:clamp(320px,36vw,440px)}
.hero-body{align-items:flex-start;text-align:left;width:56%;padding:0 40px 38px}
.hero-kind{justify-content:flex-start}
.hero-title{font-size:clamp(24px,3vw,38px)}
.hero-actions{max-width:380px}
.hero-media::after{height:100%;background:linear-gradient(to right,rgba(6,6,12,.95) 6%,rgba(6,6,12,.6) 40%,transparent 72%),linear-gradient(to top,rgba(6,6,12,.78),transparent 50%)}
.hero-media:not(.has-ban)::before{inset:-40px;filter:blur(38px) brightness(.72) saturate(1.5)}
.hero-media:not(.has-ban) .fg{left:auto;inset:auto;top:24px;right:clamp(28px,7vw,120px);width:auto;height:calc(100% - 48px);aspect-ratio:2/3;object-fit:cover;object-position:center;border-radius:18px;border:1px solid rgba(255,255,255,.22);box-shadow:0 18px 46px rgba(0,0,0,.65),0 0 0 4px rgba(255,255,255,.04)}
.hero-media:not(.has-ban) .fg{z-index:1}
.hero-media:not(.has-ban)::after{z-index:0;background:linear-gradient(to right,rgba(6,6,12,.92) 6%,rgba(6,6,12,.5) 38%,transparent 62%),linear-gradient(to top,rgba(6,6,12,.55),transparent 45%)}
.has-ban .ban{object-position:center 22%}
.tslide .hero-media::after{background:linear-gradient(to right,rgba(6,6,12,.95) 6%,rgba(6,6,12,.6) 42%,transparent 74%),linear-gradient(to top,rgba(6,6,12,.78),transparent 55%)}
.tslide .hero-media:not(.has-ban) .fg{top:22px;right:22px;height:calc(100% - 44px);object-position:center;border-radius:16px}
.tslide{flex-basis:520px;max-width:520px;height:360px}
.tbody{max-width:56%;padding:0 22px 22px}
}
@media(min-width:900px){
.hero-slide{height:clamp(300px,34vw,420px)}
.tslide{flex-basis:640px;max-width:640px;height:400px}
.tslide .hero-media .fg{object-position:right center}
.tslide .hero-media::after{height:100%;background:linear-gradient(to right,rgba(6,6,12,.94) 8%,rgba(6,6,12,.55) 50%,transparent 78%),linear-gradient(to top,rgba(6,6,12,.8),transparent 55%)}
.tbody{max-width:60%;padding:0 24px 24px}
}
@media(max-width:360px){.brand{font-size:19px}.logo{width:38px;height:38px}.episodes{gap:7px}.episode-btn{min-height:48px;font-size:16px}.ctrl-btn{min-width:34px}.detail-poster{width:96px;max-width:96px}}

.fbar{max-width:1176px;margin:10px auto 0;padding:0 12px;display:flex;flex-direction:column;gap:8px}
.fbar .filters{padding:2px 2px 4px}
@media(min-width:650px){.fbar{flex-direction:row;align-items:center}.fbar .filters{flex:1;min-width:0}}
</style>
</head>    
<body>    
<svg width="0" height="0" style="position:absolute" aria-hidden="true">    
  <symbol id="i-chat" viewBox="0 0 24 24"><path d="M21.99 4c0-1.1-.89-2-1.99-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h14l4 4-.01-18z"/></symbol>  
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
  <symbol id="i-down" viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></symbol>
</svg>  
<header>    
  <img class="logo" src="https://i.ibb.co/d4hNqPZX/move-tok-logo-black-transparent.png" alt="Move Tok">    
  <div class="brand">Move Tok</div>    
  <div class="sbox">    
    <button id="sBtn" class="sbtn" type="button" aria-label="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg></button>    
    <input id="search" class="search" type="search" placeholder="Search movies, dramas, anime...">    
  </div>    
  <!-- 3 DOT MENU BUTTON -->
  <button id="menuBtn" class="menu-btn" aria-label="Menu"><svg class="ic" viewBox="0 0 24 24"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg></button>
</header>  

<!-- SIDE MENU -->
<div id="menuOverlay" class="menu-overlay"></div>
<div id="sideMenu" class="side-menu">
    <h2>Menu <button id="closeMenuBtn">✕</button></h2>
    <a href="#" onclick="setView('home');return false;">Home</a>
    <a href="#" onclick="setView('hentai');return false;">Hentai</a>
    <hr style="border:0;border-top:1px solid #eee;margin:10px 0;">
    <a href="#" onclick="setView('privacy');return false;">Privacy Policy</a>
    <a href="#" onclick="setView('terms');return false;">Terms & Conditions</a>
    <a href="#" onclick="setView('disclaimer');return false;">Disclaimer</a>
    <a href="#" onclick="setView('contact');return false;">Contact Us</a>
    <a href="https://t.me/movetok1" target="_blank">Telegram</a>
</div>

<nav class="fbar" aria-label="Filter" id="filterNav">  
  <div class="filters">    
    <button class="filter active" data-filter="all">All</button>    
    <button class="filter" data-filter="Anime">Dramas &amp; Anime</button>    
    <button class="filter" data-filter="Movie">Hindi Movies</button>  
  </div>  
</nav>  

<main id="mainContent">  
  <h1 class="sr-only">Move Tok - Watch Hindi Movies, K-Dramas &amp; Anime Online</h1>    
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
  <section id="topSection" hidden>  
    <div class="row-head"><h2><svg><use href="#i-star"/></svg> <span id="topTitle">Top Rated</span></h2></div>  
    <div id="topRow" class="toptrack"></div>  
  </section>  
  <div class="row-head"><h2><svg><use href="#i-star"/></svg> Newest Drops</h2><span id="count" class="card-sub">0 titles</span></div>  
  <div id="catalog" class="grid"><div class="empty">Loading...</div></div>    
<noscript><ul><?php foreach (array_slice(is_array($catalog) ? ($catalog['anime'] ?? []) : [], 0, 60) as $__n) { if (!empty($__n['title']) && ($__n['category'] ?? '') !== 'hentai') echo '<li>' . $h($__n['title']) . '</li>'; } ?></ul></noscript>  
</main>  

<!-- DYNAMIC PAGE CONTENT -->
<section id="pageContent" class="page-content">
    <div id="pageText"></div>
</section>

<!-- SEARCH PAGE -->
<section id="searchPage" style="display:none;">
    <div style="max-width:1200px; margin:0 auto; padding:20px 14px;">
        <div style="display:flex; gap:10px; margin-bottom:20px;">
            <input id="searchPageInput" type="search" placeholder="Search movies, anime..." style="flex:1; width:100%; padding:14px 18px; border-radius:16px; border:1px solid var(--stroke); font-size:16px; outline:none; background:#fff; box-shadow:var(--shadow-sm);">
        </div>
        <div class="row-head"><h2><svg><use href="#i-star"/></svg> Search Results</h2><span id="searchCount" class="card-sub">0 titles</span></div>
        <div id="searchResults" class="grid"></div>
    </div>
</section>

<footer class="foot">&copy; 2026 Move Tok. Made with ❤️ in India by Harsh Singh</footer>  

<section id="watch" class="watch">    
  <div class="watch-head">    
    <span id="vTitle"></span>    
  </div>    
  <div class="video-box" id="vbox">    
    <video id="video" playsinline webkit-playsinline preload="auto" poster="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"></video>    
    <!-- NORMAL SPINNER -->
    <div class="v-spinner"></div>
    
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
        <button id="dlBtn" class="ctrl-btn" aria-label="Download"><svg class="ic"><use href="#i-down"/></svg></button>    
        <button id="setBtn" class="ctrl-btn" aria-label="Settings"><svg class="ic"><use href="#i-gear"/></svg></button>    
        <button id="fsBtn" class="ctrl-btn" aria-label="Fullscreen"><svg class="ic"><use id="fsIc" href="#i-fs"/></svg></button>    
      </div>    
    </div>    
    <div id="menu" class="menu">    
      <strong>Quality</strong><div id="qOpts" class="opts"></div>    
      <strong>Speed</strong><div id="sOpts" class="opts"></div>    
    </div>    
    <div id="verr" class="verr" hidden></div>
    <div id="dlModal" class="dl-modal" hidden>
      <div class="dl-content">
        <h3>Download Video</h3>
        <p>Quality select karo, phir Download dabao</p>
        <div id="dlQualities" class="opts"></div>
        <div class="dl-actions">
          <button id="dlCancel" class="hbtn info" type="button">Cancel</button>
          <button id="dlStart" class="hbtn play" type="button">Download</button>
        </div>
      </div>
    </div>
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
      <section id="commentSection">  
        <h2><svg><use href="#i-chat"/></svg> Comments (<span id="cmCount">0</span>)</h2>  
        <div class="cm-form">  
          <input id="cmName" type="text" maxlength="30" placeholder="Apna naam likho" autocomplete="nickname">  
          <textarea id="cmText" maxlength="500" rows="3" placeholder="Apna comment likho..."></textarea>  
          <div id="cmErr" class="cm-err"></div>  
          <button id="cmPost" class="hbtn play" type="button">Post Comment</button>  
        </div>  
        <div id="cmList" class="cm-list"></div>  
      </section>    
    </div>    
  </div>    
</section>  

<script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>  
<script>    
const CATALOG = <?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;    

const $ = id => document.getElementById(id);
const LATEST_MS = 2*24*60*60*1000;
const RECENT_MAX = 5;
const HERO_MAX = 5;
let currentView = 'home'; 
let infoMode=false, holdPlay=false, allItems=[], groups=[], currentFilter="all", currentGroup=null, currentEpisode=null, currentSeason=1, currentQuality="", watchOpen=false, pendingSeek=0, lastSave=0, recorded="";

/* ---------- MENU & PAGE ROUTING ---------- */
function setView(view) {
    if (!history.state || history.state.view !== view) {
        history.pushState({ view }, '', location.href);
    }
    applyView(view);
}

function applyView(view) {
    currentView = view;
    closeMenuUI();
    
    const mainC = $("mainContent");
    const pageC = $("pageContent");
    const searchP = $("searchPage");
    const sBox = document.querySelector(".sbox");
    const filterNav = $("filterNav");
    const menuBtn = $("menuBtn");
    
    mainC.style.display = "none";
    pageC.style.display = "none";
    searchP.style.display = "none";
    filterNav.style.display = "none";
    
    // Reset Header Layout
    sBox.style.display = ""; 
    menuBtn.style.marginLeft = "8px"; 

    if (view === 'home' || view === 'hentai') {
        mainC.style.display = "block";
        filterNav.style.display = "flex";
        if (view === 'hentai') {
            $("topTitle").textContent = "Top Rated Hentai";
            document.querySelectorAll(".filter").forEach(b => b.classList.remove("active"));
        } else {
            $("topTitle").textContent = "Top Rated";
            document.querySelector(".filter[data-filter='all']").classList.add("active");
            currentFilter = 'all';
        }
        renderSlider();
        render();
        window.scrollTo(0, 0);
    } else if (view === 'search') {
        searchP.style.display = "block";
        sBox.style.display = "none";
        menuBtn.style.marginLeft = "auto";
        $("searchPageInput").focus();
        render();
        window.scrollTo(0, 0);
    } else {
        pageC.style.display = "block";
        sBox.style.display = "none";
        menuBtn.style.marginLeft = "auto";
        $("searchPageInput").value = "";
        renderPage(view);
        window.scrollTo(0, 0);
    }
}

function renderPage(view) {
    let html = "";
    if (view === 'privacy') {
        html = `<h1>Privacy Policy</h1>
                <p>At Move Tok, accessible from our website, one of our main priorities is the privacy of our visitors. This Privacy Policy document contains types of information that is collected and recorded by Move Tok and how we use it.</p>
                <p>If you have additional questions or require more information about our Privacy Policy, do not hesitate to contact us.</p>
                <h3>Log Files</h3>
                <p>Move Tok follows a standard procedure of using log files. These files log visitors when they visit websites. The information collected by log files include internet protocol (IP) addresses, browser type, Internet Service Provider (ISP), date and time stamp, referring/exit pages, and possibly the number of clicks.</p>
                <h3>Cookies and Web Beacons</h3>
                <p>Like any other website, Move Tok uses "cookies". These cookies are used to store information including visitors' preferences, and the pages on the website that the visitor accessed or visited.</p>`;
    } else if (view === 'terms') {
        html = `<h1>Terms & Conditions</h1>
                <p>Welcome to Move Tok!</p>
                <p>These terms and conditions outline the rules and regulations for the use of Move Tok's Website.</p>
                <p>By accessing this website we assume you accept these terms and conditions. Do not continue to use Move Tok if you do not agree to take all of the terms and conditions stated on this page.</p>
                <h3>License</h3>
                <p>Unless otherwise stated, Move Tok and/or its licensors own the intellectual property rights for all material on Move Tok. All intellectual property rights are reserved. You may access this from Move Tok for your own personal use subjected to restrictions set in these terms and conditions.</p>
                <p>You must not: Republish material from Move Tok, Sell, rent or sub-license material from Move Tok, Reproduce, duplicate or copy material from Move Tok.</p>`;
    } else if (view === 'disclaimer') {
        html = `<h1>Disclaimer</h1>
                <p>The information provided by Move Tok is for general informational purposes only. All information on the Site is provided in good faith, however we make no representation or warranty of any kind, express or implied, regarding the accuracy, adequacy, validity, reliability, availability, or completeness of any information on the Site.</p>
                <p>Under no circumstance shall we have any liability to you for any loss or damage of any kind incurred as a result of the use of the site or reliance on any information provided on the site.</p>
                <p>This website does not host any files on its server. All contents are provided by non-affiliated third parties.</p>`;
    } else if (view === 'contact') {
        html = `<h1>Contact Us</h1>
                <p>If you have any questions or suggestions about our website, do not hesitate to contact us.</p>
                <p>Email: <a href="mailto:h4xzico1111@gmail.com">h4xzico1111@gmail.com</a></p>
                <p>Telegram: <a href="https://t.me/movetok1" target="_blank">@movetok1</a></p>`;
    }
    $("pageText").innerHTML = html;
}

function openMenuUI(){
    $("sideMenu").classList.add("open");
    $("menuOverlay").classList.add("open");
}
function closeMenuUI(){
    $("sideMenu").classList.remove("open");
    $("menuOverlay").classList.remove("open");
}
$("menuBtn").onclick = openMenuUI;
$("closeMenuBtn").onclick = closeMenuUI;
$("menuOverlay").onclick = closeMenuUI;


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
  box.style.setProperty("--pimg",'url("'+src.replace(/["\\n()]/g,c=>encodeURIComponent(c))+'")');
  box.append(bg,mkImg(src,alt,"fg"));
}
function addBanner(media,g){
  if(!g.banner||g.banner===g.poster)return;
  const i=mkImg(g.banner,"","ban");i.decoding="async";
  i.onerror=()=>{i.remove();media.classList.remove("has-ban")};
  media.classList.add("has-ban");media.appendChild(i);
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
const kindLabel=g=>g.type==="Movie"?"Hindi Movie":g.type==="Hentai"?"Hindi Hentai":"Drama / Anime";
const isHentai=g=>g.type==="Hentai";

/* ---------- recent ---------- */
const WEEK_MS=7*24*60*60*1000;
function getRecent(){
  let r;try{r=JSON.parse(localStorage.getItem("zt:recent"))||[]}catch{r=[]}
  if(!r.length)return r;
  let ws=store.get("zt:wk");
  if(!ws){ws=Math.min(...r.map(x=>x.t||Date.now()));store.set("zt:wk",ws)}
  if(Date.now()-ws>=WEEK_MS){
    r.forEach(x=>{store.del("zt:"+x.e);store.del("ztd:"+x.e)});
    try{localStorage.setItem("zt:recent","[]")}catch{}
    store.del("zt:wk");return [];
  }
  return r;
}
function pushRecent(g,ep){
  const cur=getRecent();if(!cur.length)store.set("zt:wk",Date.now());
  const r=cur.filter(x=>x.g!==gkey(g));
  r.unshift({g:gkey(g),e:ep.id,t:Date.now()});
  const keep=r.slice(0,RECENT_MAX);
  try{localStorage.setItem("zt:recent",JSON.stringify(keep))}catch{}
}

/* ---------- search ---------- */
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

/* ---------- build data ---------- */
function toTime(v){const d=Date.parse(v||"");return isNaN(d)?0:d}
function buildItems(){
  const out=[];
  const list=CATALOG&&Array.isArray(CATALOG.anime)?CATALOG.anime:[];
  list.forEach(a=>{
    if(!a||!a.slug||!a.title)return;
    const type=a.category==="movie"?"Movie":a.category==="hentai"?"Hentai":"Anime";
    const poster=safeUrl(a.poster)||safeUrl(a.banner);
    (Array.isArray(a.seasons)?a.seasons:[]).forEach(s=>{
      (Array.isArray(s.episodes)?s.episodes:Array.from({length:s.count||0},(_,i)=>({number:i+1}))).forEach(e=>{
        const q={};
        (e.sources||[]).forEach(x=>{const u=safeUrl(x&&x.url);if(u)q[x.quality||"Default"]=u});
        const eu=safeUrl(e.url);
        if(eu&&!Object.values(q).includes(eu))q[e.quality||"Default"]=eu;
        out.push({
          id:a.slug+"|"+s.number+"|"+e.number,title:String(a.title).trim(),type,poster,
          season:Number(s.number)||1,episode:Number(e.number)||1,qualities:q,mp4Url:"",
          blk:a.blk?{t:a.blk,u:a.category==="movie"?"":(s.key||s.number)+"-"+e.number}:null,
          rating:Number(a.rating)||0,vk:a.vk||"",banner:safeUrl(a.banner),ts:toTime(e.added_at)||toTime(a.added_at)
        });
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
    if(!map.has(key))map.set(key,{title:item.title,type:item.type,poster:item.poster,vk:item.vk,banner:item.banner,rating:0,items:[]});
    const g=map.get(key);
    if(!g.poster&&item.poster)g.poster=item.poster;
    if(!g.banner&&item.banner)g.banner=item.banner;
    g.items.push(item);
  });
  groups=[...map.values()];
  groups.forEach(g=>{
    g.items.sort((a,b)=>a.season-b.season||a.episode-b.episode);
    g.ts=Math.max(0,...g.items.map(x=>x.ts));
    g.rating=Math.max(0,...g.items.map(x=>x.rating||0));
    g.hay=norm(g.title+" "+kindLabel(g));
    g.words=g.hay.split(" ");
  });
  groups.sort((a,b)=>b.ts-a.ts);
}

/* ---------- home render ---------- */
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
function topCmp(a,b){return (b.rating||0)-(a.rating||0)||b.ts-a.ts}
function renderTop(searching){
  const row=$("topRow"),keep=row.scrollLeft;row.replaceChildren();
  const list=groups.filter(g=>currentView==='hentai' ? isHentai(g) : !isHentai(g)).sort(topCmp).slice(0,100);
  list.forEach((g,i)=>{
    const sl=document.createElement("article");sl.className="tslide";
    const media=document.createElement("div");media.className="hero-media";addPoster(media,g.poster,g.title);addBanner(media,g);
    media.querySelectorAll("img:not(.ban)").forEach(im=>{im.loading=i<2?"eager":"lazy"});
    const rk=document.createElement("span");rk.className="trank";rk.textContent="#"+(i+1);
    const body=document.createElement("div");body.className="tbody";
    const t=document.createElement("div");t.className="hero-title";t.textContent=g.title;
    const meta=document.createElement("div");meta.className="tmeta";
    if(g.rating){const r=document.createElement("span");r.textContent="★ "+g.rating.toFixed(1);meta.appendChild(r)}
    const sn=new Set(g.items.map(x=>x.season)).size;
    const m2=document.createElement("span");m2.textContent=sn+(sn===1?" Season":" Seasons")+" • "+g.items.length+" EP";meta.appendChild(m2);
    const pb=document.createElement("button");pb.className="hbtn play";pb.append(icon("i-play"),document.createTextNode("Watch Now"));
    body.append(t,meta,pb);sl.append(media,rk,body);sl.onclick=()=>openGroup(g);row.appendChild(sl);
  });
  row.scrollLeft=keep;
  $("topSection").hidden=searching||!list.length;
}
function renderRows(){
  const searching=$("search").value.trim()!=="";
  
  // Recent Watch
  const rr=$("recentRow");rr.replaceChildren();
  if(currentView === 'home'){
      getRecent().forEach(r=>{
        const g=groups.find(x=>gkey(x)===r.g);if(!g || isHentai(g))return;
        const item=g.items.find(x=>x.id===r.e);if(!item)return;
        const pos=store.get("zt:"+item.id),dur=store.get("ztd:"+item.id);
        rr.appendChild(rowCard(g,item,{progress:dur?pos/dur*100:0}));
      });
  }
  $("recentSection").hidden=searching||currentView==='hentai'||!rr.children.length;

  // Latest Update
  const lr=$("latestRow");lr.replaceChildren();
  const now=Date.now(),cand=[];
  groups.forEach(g=>{
    if(currentView==='home' && isHentai(g))return;
    if(currentView==='hentai' && !isHentai(g))return;
    
    if(!g.ts||now-g.ts>=LATEST_MS)return;
    let best=null;
    g.items.forEach(x=>{if(x.ts&&now-x.ts<LATEST_MS&&(!best||x.ts>best.ts||(x.ts===best.ts&&(x.season>best.season||(x.season===best.season&&x.episode>best.episode)))))best=x});
    if(best)cand.push([g,best]);
  });
  cand.sort((a,b)=>b[1].ts-a[1].ts).slice(0,20).forEach(c=>lr.appendChild(rowCard(c[0],c[1],{isNew:true})));
  $("latestSection").hidden=searching||!lr.children.length;
  
  renderTop(searching);
}
function render(){
  if (currentView === 'search') {
      const q=$("searchPageInput").value.trim();
      const base = groups;
      let list = [], sugg = [];
      if(q) {
          const r = searchGroups(q, base);
          list = r.list;
          sugg = r.sugg;
      }
      const cat = $("searchResults");
      cat.replaceChildren();
      $("searchCount").textContent = list.length + (list.length===1?" title":" titles");
      if(!list.length){
          const e=document.createElement("div");e.className="empty";
          e.textContent=q?"\""+q+"\" nahi mila.":"Type something to search...";
          cat.appendChild(e);
          sugg.forEach(g=>cat.appendChild(makeCard(g)));
      } else {
          list.forEach(g=>cat.appendChild(makeCard(g)));
      }
      return;
  }

  if (currentView === 'home' || currentView === 'hentai') {
      const q=$("search").value.trim();
      const base=groups.filter(g=>{
          if (currentView === 'hentai') return isHentai(g);
          if (currentFilter === 'all') return !isHentai(g);
          return g.type === currentFilter;
      });
      let list=base,sugg=[];
      if(q){const r=searchGroups(q,base);list=r.list;sugg=r.sugg}
      const cat=$("catalog");cat.replaceChildren();
      $("count").textContent=list.length+(list.length===1?" title":" titles");
      if(!list.length){
        const e=document.createElement("div");e.className="empty";
        e.textContent=!allItems.length?"Nothing published yet.":q?"\""+q+"\" nahi mila."+(sugg.length?" Shayad ye dhundh rahe the:":""):"Nothing found.";
        cat.appendChild(e);
        sugg.forEach(g=>cat.appendChild(makeCard(g)));
      }else list.forEach(g=>cat.appendChild(makeCard(g)));
      $("featuredSection").hidden=!heroList.length;
      renderRows();
  }
}

/* ---------- hero ---------- */
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
  
  heroList = groups.filter(g => currentView === 'hentai' ? isHentai(g) : !isHentai(g)).slice(0, HERO_MAX);
  
  $("featuredSection").hidden=!heroList.length;
  heroList.forEach((g,i)=>{
    const slide=document.createElement("div");slide.className="hero-slide";
    const media=document.createElement("div");media.className="hero-media";
    addPoster(media,g.poster,g.title);addBanner(media,g);
    const body=document.createElement("div");body.className="hero-body";
    const kind=document.createElement("div");kind.className="hero-kind";
    const k1=document.createElement("span");k1.textContent=kindLabel(g);
    const dot=document.createElement("i");
    const k2=document.createElement("span");k2.textContent=g.type==="Movie"?"Full Movie":g.items.length+(g.items.length===1?" Episode":" Episodes");
    kind.append(k1,dot,k2);
    if(g.type!=="Movie"){const sn=new Set(g.items.map(x=>x.season)).size;const k3=document.createElement("span");k3.textContent=sn+(sn===1?" Season":" Seasons");kind.insertBefore(k3,dot)}
    media.querySelectorAll("img:not(.ban)").forEach(im=>{im.loading=i<2?"eager":"lazy";im.decoding="async"});
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

/* ---------- SEO ---------- */
const SEO0={t:document.title,d:(document.querySelector('meta[name="description"]')||{}).content||""};
function seo(t,d){
  document.title=t;
  const m=document.querySelector('meta[name="description"]');
  if(m)m.content=d;
}
function detachFb(){}

/* ---------- V / box refs (early) ---------- */
const V=$("video"), box=$("vbox");

/* ---------- openGroup ---------- */
function openGroup(g, startItem, noPlay) {
  history.pushState({ view: 'watch', groupKey: gkey(g), episodeId: startItem ? startItem.id : null, noPlay: !!noPlay }, '', location.href);
  showWatch(g, startItem, noPlay);
}

function showWatch(g, startItem, noPlay) {
  holdPlay=false;
  infoMode=!!noPlay;
  $("watch").classList.toggle("info-mode",infoMode);
  currentGroup=g;currentEpisode=null;currentQuality="";recorded="";
  $("watch").style.display="block";$("watch").scrollTop=0;
  document.body.style.overflow="hidden";
  watchOpen=true;
  
  $("detailTitle").textContent=g.title;$("vTitle").textContent=g.title;
  $("detailType").textContent=kindLabel(g);
  $("infoLabel").textContent=g.type==="Movie"?"Full Movie":metaText(g);
  const p=$("detailPoster");
  if(g.poster){
    p.src=g.poster;p.style.display="block";
    $("details").style.setProperty("--bg-image",'url("'+g.poster.replace(/["\\n()]/g,c=>encodeURIComponent(c))+'")');
  }else{p.removeAttribute("src");p.style.display="none";$("details").style.removeProperty("--bg-image")}
  closeMenu();
  loadComments(g);
  seo(g.title+" - Watch Online in Hindi | Move Tok","Watch "+g.title+" ("+kindLabel(g)+") online in HD on Move Tok. "+(g.type==="Movie"?"Full movie":g.items.length+" episodes")+" available.");
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
function blkLoad(item){
  box.classList.add("loading");$("verr").hidden=true;
  const fd = "?action=stream&tmdbId="+encodeURIComponent(item.blk.t)+(item.blk.u?"&uniqueId="+encodeURIComponent(item.blk.u):"");
  fetch(fd)
    .then(r=>r.json())
    .then(d=>{
      if(d&&d.ok&&d.streams)d.streams.forEach(s=>{item.qualities[s.quality]=s.url});
      if(d&&d.error)console.warn("[blkLoad]",d.error);
    })
    .catch(e=>console.warn("[blkLoad] fetch fail",e))
    .then(()=>{if(currentEpisode===item)loadEpisode(true)});
}
function loadEpisode(noBlk){
  closeDlModal();
  updateLabel();
  if(infoMode){stopVideo();closeMenu();return}
  const qBox=$("qOpts");qBox.replaceChildren();qBox.dataset.h="";closeMenu();
  if(!currentEpisode){stopVideo();showErr("No episodes available.");return}
  if(!noBlk&&currentEpisode.blk&&!Object.keys(currentEpisode.qualities).length){blkLoad(currentEpisode);return}
  const q=getQualities(currentEpisode);
  const names=Object.keys(q).sort((a,b)=>a==="Auto"?-1:b==="Auto"?1:(parseInt(b)||0)-(parseInt(a)||0));
  if(!names.length){stopVideo();showErr("Video link not available.");return}
  names.forEach(n=>{
    const b=document.createElement("button");b.className="opt";b.textContent=n;
    b.onclick=()=>{currentQuality=n;playVideo(q[n],V.currentTime);markQuality();closeMenu()};
    qBox.appendChild(b);
  });
  if(!names.includes(currentQuality)){
    const c=navigator.connection;
    const slow=c&&(c.saveData||/2g|3g/.test(c.effectiveType||"")||(c.downlink&&c.downlink<1.5));
    currentQuality=names.includes("Auto")?"Auto":(slow?names[names.length-1]:names[0]);
  }
  markQuality();
  playVideo(q[currentQuality],store.get("zt:"+currentEpisode.id));
  holdPlay=false;
}
function markQuality(){document.querySelectorAll("#qOpts .opt").forEach(b=>b.classList.toggle("active",b.textContent===currentQuality))}

/* ---------- download modal ---------- */
let dlSelected = "";
function openDlModal(){
  if(!currentEpisode)return;
  const q = getQualities(currentEpisode);
  const names = Object.keys(q).filter(n=>n!=="Auto").sort((a,b)=>(parseInt(b)||0)-(parseInt(a)||0));
  if(!names.length){ showErr("Download link available nahi hai."); return; }
  const dlQ = $("dlQualities");
  dlQ.replaceChildren();
  dlSelected = names[0];
  names.forEach(n=>{
    const b = document.createElement("button");
    b.className = "opt"+(n===dlSelected?" active":"");
    b.textContent = n;
    b.type = "button";
    b.onclick = () => {
      dlSelected = n;
      dlQ.querySelectorAll(".opt").forEach(x=>x.classList.toggle("active", x===b));
    };
    dlQ.appendChild(b);
  });
  $("dlModal").hidden = false;
  showUI();
}
function closeDlModal(){ $("dlModal").hidden = true; }
function startDownload(){
  if(!currentEpisode || !dlSelected) return;
  const q = getQualities(currentEpisode);
  const url = q[dlSelected];
  if(!url) return;
  const baseName = (currentGroup ? currentGroup.title.replace(/[\\/:*?"<>|]/g,"_") : "video")
    + (currentGroup && currentGroup.type!=="Movie" && currentEpisode ? " S"+currentEpisode.season+"E"+currentEpisode.episode : "");
  const a = document.createElement("a");
  a.href = url;
  a.download = baseName + " " + dlSelected + ".mp4";
  a.target = "_blank";
  a.rel = "noopener noreferrer";
  document.body.appendChild(a);
  a.click();
  a.remove();
  closeDlModal();
}
$("dlBtn").onclick = openDlModal;
$("dlCancel").onclick = closeDlModal;
$("dlStart").onclick = startDownload;
$("dlModal").addEventListener("click", e => { if(e.target === $("dlModal")) closeDlModal(); });

function hideWatch(){
  closeDlModal();stopVideo();infoMode=false;$("watch").classList.remove("info-mode");$("watch").style.display="none";closeMenu();
  document.body.style.overflow="";currentGroup=null;currentEpisode=null;currentQuality="";watchOpen=false;detachFb();seo(SEO0.t,SEO0.d);
  const fs=document.fullscreenElement||document.webkitFullscreenElement;
  if(fs)(document.exitFullscreen||document.webkitExitFullscreen).call(document);
}

window.addEventListener("popstate", (e) => {
    const state = e.state;

    if (watchOpen) {
        hideWatch();
    }

    if (state && state.view === 'watch') {
        const g = groups.find(x => gkey(x) === state.groupKey);
        if (g) {
            const item = state.episodeId ? g.items.find(x => x.id === state.episodeId) : g.items[0];
            showWatch(g, item, state.noPlay);
        } else {
            applyView('home');
        }
    } else if (state && state.view) {
        applyView(state.view);
    } else {
        history.replaceState({ view: 'home' }, '', location.href);
        applyView('home');
    }
    
    if (!state || state.view !== 'search') {
        $("searchPageInput").value = "";
    }
});

/* ---------- video player ---------- */
let hideT,tapT,lastTap=0,drag=false,curUrl="",hls=null,hlsRetry=0;

function showUI(){
  box.classList.remove("idle");clearTimeout(hideT);
  if(!V.paused)hideT=setTimeout(()=>{if($("menu").classList.contains("open")||!$("dlModal").hidden){showUI();return}box.classList.add("idle")},2500);
}
function closeMenu(){$("menu").classList.remove("open")}
function showErr(msg){$("verr").textContent=msg;$("verr").hidden=false;box.classList.remove("loading")}
const isHls=u=>/\.m3u8|\.tar/i.test(u||"");
function destroyHls(){if(hls){try{hls.destroy()}catch{}hls=null}hlsRetry=0}
function stopVideo(){
  try{V.pause()}catch(e){}
  destroyHls();
  curUrl="";
  try{V.removeAttribute("src");V.load()}catch(e){}
  box.classList.remove("loading");
}
function hlsMenu(){
  if(!hls||!hls.levels||!hls.levels.length)return;
  const box=$("qOpts");
  if(box.children.length>1&&box.dataset.h!=="1")return;
  box.replaceChildren();box.dataset.h="1";
  const add=(label,lvl)=>{
    const b=document.createElement("button");b.className="opt";b.textContent=label;
    b.onclick=()=>{if(!hls)return;hls.currentLevel=lvl;currentQuality=label;markQuality();closeMenu()};
    box.appendChild(b);
  };
  add("Auto",-1);
  const seen=new Set();
  hls.levels.map((l,i)=>({i,h:l.height||0,k:l.bitrate||0})).sort((a,b)=>b.h-a.h||b.k-a.k).forEach(x=>{
    const label=x.h?x.h+"p":Math.round(x.k/1000)+" kbps";
    if(seen.has(label))return;seen.add(label);add(label,x.i);
  });
  currentQuality="Auto";markQuality();
}
function playVideo(url,at){
  const s=safeUrl(url);if(!s||curUrl===s)return;
  curUrl=s;pendingSeek=at||0;$("verr").hidden=true;box.classList.add("loading");
  destroyHls();
  if(isHls(s)){
    if(window.Hls&&Hls.isSupported()){
      hls=new Hls({
        enableWorker:true,
        startPosition:pendingSeek>1?pendingSeek:-1,
        startLevel:-1,
        capLevelToPlayerSize:true,
        abrEwmaDefaultEstimate:500000,
        abrBandWidthFactor:0.70,
        abrBandWidthUpFactor:0.60,
        maxBufferLength:45,
        maxMaxBufferLength:75,
        maxBufferSize:50*1000*1000,
        backBufferLength:20,
        maxBufferHole:0.5,
        manifestLoadingMaxRetry:4,
        manifestLoadingRetryDelay:1000,
        manifestLoadingMaxRetryTimeout:8000
      });
      hls.loadSource(s);
      hls.attachMedia(V);
      hls.on(Hls.Events.MANIFEST_PARSED,()=>{
        hlsMenu();
        if(pendingSeek>1){try{V.currentTime=pendingSeek}catch(e){}}
        if(!holdPlay)V.play().catch(()=>{});
      });
      hls.on(Hls.Events.ERROR,(e,d)=>{
        if(!d.fatal)return;
        if(d.type===Hls.ErrorTypes.NETWORK_ERROR){
          if(hlsRetry<2){hlsRetry++;setTimeout(()=>{try{hls.startLoad()}catch(e){}},1000);return}
        }else if(d.type===Hls.ErrorTypes.MEDIA_ERROR){
          try{hls.recoverMediaError()}catch(e){}return;
        }
        showErr("Video load failed. Try another quality.");
      });
    }else if(V.canPlayType("application/vnd.apple.mpegurl")){
      V.src=s;
      if(pendingSeek>1){const f=()=>{try{V.currentTime=pendingSeek}catch(e){}};V.addEventListener("loadedmetadata",f,{once:true})}
      if(!holdPlay)V.play().catch(()=>{});
    }else showErr("HLS not supported in this browser.");
  }else{
    V.src=s;
    if(pendingSeek>1){const f=()=>{try{V.currentTime=pendingSeek}catch(e){}};V.addEventListener("loadedmetadata",f,{once:true})}
    if(!holdPlay)V.play().catch(()=>{});
  }
  showUI();
}

/* progress bar drag */
let progDrag=false;
function progSeek(e){
  const r=$("prog").getBoundingClientRect();
  const x=Math.max(0,Math.min(1,(e.clientX-r.left)/r.width));
  if(V.duration)V.currentTime=x*V.duration;
  $("bar").style.width=(x*100)+"%";
}
$("prog").addEventListener("pointerdown",e=>{progDrag=true;try{$("prog").setPointerCapture(e.pointerId)}catch(_){}progSeek(e);showUI()});
$("prog").addEventListener("pointermove",e=>{if(progDrag)progSeek(e)});
$("prog").addEventListener("pointerup",()=>{progDrag=false});
$("prog").addEventListener("pointercancel",()=>{progDrag=false});

/* player events */
V.addEventListener("loadedmetadata",()=>{$("tot").textContent=fmt(V.duration)});
V.addEventListener("timeupdate",()=>{
  $("cur").textContent=fmt(V.currentTime);
  if(V.duration&&!progDrag)$("bar").style.width=(V.currentTime/V.duration*100)+"%";
  if(currentEpisode&&V.duration&&Date.now()-lastSave>3000){
    store.set("zt:"+currentEpisode.id,Math.floor(V.currentTime));
    store.set("ztd:"+currentEpisode.id,Math.floor(V.duration));
    lastSave=Date.now();
  }
});
V.addEventListener("progress",()=>{
  if(V.buffered.length&&V.duration){
    const end=V.buffered.end(V.buffered.length-1);
    $("buf").style.width=(end/V.duration*100)+"%";
  }
});
V.addEventListener("play",()=>{setIc($("playIc"),"i-pause");setIc($("bigIc"),"i-pause");box.classList.remove("loading");showUI()});
V.addEventListener("pause",()=>{setIc($("playIc"),"i-play");setIc($("bigIc"),"i-play");showUI()});
V.addEventListener("waiting",()=>box.classList.add("loading"));
V.addEventListener("playing",()=>{box.classList.remove("loading");box.classList.remove("idle")});
V.addEventListener("canplay",()=>box.classList.remove("loading"));
V.addEventListener("ended",()=>{const nx=nextItem();if(nx)selectEpisode(nx);else showUI()});
V.addEventListener("error",()=>{if(curUrl)showErr("Video play nahi ho paaya. Doosri quality try karo.")});

/* center buttons */
$("big").onclick=()=>{if(V.paused)V.play().catch(()=>{});else V.pause()};
$("playBtn").onclick=()=>{if(V.paused)V.play().catch(()=>{});else V.pause()};
$("rew").onclick=()=>{if(V.duration)V.currentTime=Math.max(0,V.currentTime-10)};
$("fwd").onclick=()=>{if(V.duration)V.currentTime=Math.min(V.duration,V.currentTime+10)};
$("nextBtn").onclick=()=>{const nx=nextItem();if(nx)selectEpisode(nx)};
$("muteBtn").onclick=()=>{V.muted=!V.muted;setIc($("muteIc"),V.muted?"i-mute":"i-vol")};
$("infoPlay").onclick=()=>{leaveInfo();loadEpisode()};

/* settings */
const speeds=[0.5,0.75,1,1.25,1.5,2];
const sBox=$("sOpts");
speeds.forEach(sp=>{
  const b=document.createElement("button");b.className="opt"+(sp===1?" active":"");b.textContent=sp+"x";
  b.onclick=()=>{V.playbackRate=sp;sBox.querySelectorAll(".opt").forEach(x=>x.classList.toggle("active",x===b))};
  sBox.appendChild(b);
});

/* menus & fullscreen */
$("setBtn").onclick=()=>{$("menu").classList.toggle("open");showUI()};
document.addEventListener("click",e=>{if(!$("menu").contains(e.target)&&e.target!==$("setBtn")&&!$("setBtn").contains(e.target))closeMenu()});
function toggleFs(){
  const b=$("vbox");
  if(document.fullscreenElement||document.webkitFullscreenElement){
    (document.exitFullscreen||document.webkitExitFullscreen).call(document);
  }else{
    (b.requestFullscreen||b.webkitRequestFullscreen||b.msRequestFullscreen).call(b);
  }
}
$("fsBtn").onclick=toggleFs;
document.addEventListener("fullscreenchange",()=>{setIc($("fsIc"),document.fullscreenElement?"i-fsx":"i-fs")});
document.addEventListener("webkitfullscreenchange",()=>{setIc($("fsIc"),document.webkitFullscreenElement?"i-fsx":"i-fs")});

/* keyboard */
document.addEventListener("keydown",e=>{
  if(!watchOpen)return;
  const t=e.target.tagName;
  if(t==="INPUT"||t==="TEXTAREA")return;
  if(e.key===" "||e.key==="k"){e.preventDefault();V.paused?V.play().catch(()=>{}):V.pause()}
  else if(e.key==="ArrowLeft"&&V.duration)V.currentTime=Math.max(0,V.currentTime-10);
  else if(e.key==="ArrowRight"&&V.duration)V.currentTime=Math.min(V.duration,V.currentTime+10);
  else if(e.key==="m"){V.muted=!V.muted;setIc($("muteIc"),V.muted?"i-mute":"i-vol")}
  else if(e.key==="f")toggleFs();
  else if(e.key==="Escape"){
    if(!$("dlModal").hidden)closeDlModal();
    else if(watchOpen&&!document.fullscreenElement&&!document.webkitFullscreenElement)hideWatch();
  }
});

/* tap-to-toggle controls */
box.addEventListener("pointerdown",e=>{
  if(e.target.closest("button")||e.target.closest(".prog")||e.target.closest(".menu")||e.target.closest(".dl-modal"))return;
  const now=Date.now();
  if(now-lastTap<300){toggleFs();lastTap=0;return}
  lastTap=now;clearTimeout(tapT);
  tapT=setTimeout(()=>{if(box.classList.contains("idle"))showUI();else box.classList.add("idle")},300);
});
box.addEventListener("pointermove",showUI,{passive:true});
box.addEventListener("pointerleave",()=>{if(!V.paused)box.classList.add("idle")});

/* filters */
document.querySelectorAll(".filter").forEach(b=>{
  b.onclick=()=>{
    document.querySelectorAll(".filter").forEach(x=>x.classList.remove("active"));
    b.classList.add("active");
    currentFilter=b.dataset.filter;
    render();
  };
});

/* search */
$("sBtn").onclick=()=>{
  const h=document.querySelector("header");
  if(h.classList.contains("s-open")){h.classList.remove("s-open");$("search").value="";render()}
  else{h.classList.add("s-open");$("search").focus()}
};
let sT;
$("search").addEventListener("input",()=>{clearTimeout(sT);sT=setTimeout(render,200)});
let spT;
$("searchPageInput").addEventListener("input",()=>{clearTimeout(spT);spT=setTimeout(render,200)});

/* save on unload */
window.addEventListener("beforeunload",()=>{
  if(currentEpisode&&V.duration){
    store.set("zt:"+currentEpisode.id,Math.floor(V.currentTime));
    store.set("ztd:"+currentEpisode.id,Math.floor(V.duration));
  }
});

/* comments */
async function loadComments(g){
  $("cmList").innerHTML="";$("cmCount").textContent="0";$("cmErr").textContent="";
  try{
    const r=await fetch("?action=cm&k="+encodeURIComponent(g.vk||""));
    const d=await r.json();
    if(d.ok&&Array.isArray(d.items)){
      $("cmCount").textContent=d.items.length;
      if(!d.items.length){$("cmList").innerHTML='<div class="cm-empty">Pehla comment tumhari taraf se 😎</div>';return}
      d.items.forEach(c=>{
        const el=document.createElement("div");el.className="cm-item";
        el.innerHTML='<div class="cm-top"><b></b><span></span></div><p class="cm-text"></p>';
        el.querySelector("b").textContent=c.n||"User";
        el.querySelector("span").textContent=c.ts?new Date(c.ts*1000).toLocaleDateString():"";
        el.querySelector(".cm-text").textContent=c.t||"";
        $("cmList").appendChild(el);
      });
    }
  }catch(e){}
}
$("cmPost").onclick=async()=>{
  const n=$("cmName").value.trim(),t=$("cmText").value.trim();
  if(!currentGroup||!currentGroup.vk)return;
  if(!n){$("cmErr").textContent="Pehle naam likho.";return}
  if(!t){$("cmErr").textContent="Comment likho.";return}
  $("cmErr").textContent="";
  $("cmPost").disabled=true;
  try{
    const fd=new FormData();fd.append("n",n);fd.append("t",t);fd.append("k",currentGroup.vk);
    const r=await fetch("?action=cm_add",{method:"POST",body:fd});
    const d=await r.json();
    if(d.ok){$("cmText").value="";loadComments(currentGroup)}
    else $("cmErr").textContent=d.error||"Comment post nahi hua.";
  }catch(e){$("cmErr").textContent="Network error."}
  $("cmPost").disabled=false;
};

/* init */
allItems=buildItems();
buildGroups();
history.replaceState({view:'home'},'',location.href);
applyView('home');
</script>
</body>
</html>

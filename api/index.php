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
    return ['no_auth', ''];
}
function fb($method, $path, $q = [], $body = null) {
    global $FB_URL;
    $t = fb_token();
    if (!$t) return ['ok' => false, 'd' => null, 'err' => 'setup'];
    if ($t[0] !== 'no_auth') $q[$t[0]] = $t[1];
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
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 0, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $sendH,
        CURLOPT_HEADERFUNCTION => function($ch, $h) {
            $len = strlen($h); $t = trim($h);
            if ($t === '' || stripos($t, 'HTTP/') === 0) return $len;
            $parts = explode(':', $t, 2);
            if (count($parts) === 2) {
                $n = strtolower(trim($parts[0])); $v = trim($parts[1]);
                if (in_array($n, ['content-type','content-length','content-range','accept-ranges'])) header($parts[0] . ': ' . $v);
            }
            return $len;
        },
        CURLOPT_WRITEFUNCTION => function($ch, $data) { echo $data; flush(); return strlen($data); },
    ]);
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP')) curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_exec($ch); curl_close($ch); exit;
}

/* ============ STREAM ============ */
if ($__act === 'stream') {
    header('Content-Type: application/json; charset=utf-8');
    $tmdbId   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['tmdbId'] ?? '');
    $uniqueId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['uniqueId'] ?? '');
    $isMovie  = empty($uniqueId);
    if (!$tmdbId) { echo jEnc(['ok'=>false, 'error'=>'no tmdbId']); exit; }
    $variants = array_values(array_unique(array_filter([
        $tmdbId, ltrim($tmdbId, '0'),
        str_pad(ltrim($tmdbId, '0'), 6, '0', STR_PAD_LEFT),
        str_pad(ltrim($tmdbId, '0'), 7, '0', STR_PAD_LEFT),
        str_pad(ltrim($tmdbId, '0'), 10, '0', STR_PAD_LEFT),
    ])));
    $uidVariants = $isMovie ? [null] : [$uniqueId];
    $apiData = null; $usedTmdb = $tmdbId; $usedUid = $uniqueId; $lastRaw = '';
    foreach ($variants as $v) {
        foreach ($uidVariants as $u) {
            $apiUrl = $u ? "$BK_EMBED/api/get.php?id=" . urlencode($u) . "&tmdbId=" . urlencode($v) : "$BK_EMBED/api/get.php?tmdbId=" . urlencode($v);
            $raw = cGet($apiUrl, 12, "$BK_EMBED/");
            $lastRaw = $raw ?: $lastRaw;
            if (!$raw) continue;
            $j = json_decode($raw, true);
            if ($j && !empty($j['data']['dataId'])) { $apiData = $j['data']; $usedTmdb = $v; $usedUid = $u; break 2; }
        }
    }
    if (!$apiData) { echo jEnc(['ok' => false, 'error' => 'Stream not found']); exit; }
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
                if (preg_match('/(\d+\s*-\s*\d+)\s*\((\d+)\s*p\)/i', $line, $m)) $ranges[(int)$m[2].'p'] = preg_replace('/\s+/', '', $m[1]);
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
        foreach (array_slice($codeMap, 0, $count, true) as $label => $code) {
            $url = "{$baseDURL}{$dataId}.{$code}.mp4";
            $streams[] = ['quality'=>$label, 'url'=>$url, 'type'=>'mp4'];
        }
    }
    if (empty($streams)) { echo jEnc(['ok'=>false, 'error'=>'No streams', 'format'=>$format]); exit; }
    usort($streams, fn($a,$b) => (int)$a['quality'] - (int)$b['quality']);
    $default = $apiData['quality'] ?? null;
    if (!$default || !in_array($default, array_column($streams, 'quality'))) $default = $streams[count($streams)-1]['quality'];
    echo jEnc([
        'ok' => true, 'title' => $apiData['animeTitle'] ?? '', 'format' => $format,
        'streams' => $streams, 'default' => $default, 'poster' => $apiData['poster'] ?? '',
        'usedTmdb' => $usedTmdb, 'usedUid' => $usedUid, 'dataId' => $dataId,
    ]);
    exit;
}

/* ============ ANIMESALT + RAVOK ENGINE ============ */
$AS_BASE      = 'https://animesalt.cx';
$AS_RAVOK     = 'https://ravok.buzz';
$AS_UA        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
$AS_FILE      = sys_get_temp_dir() . '/as.catalog.json';
$AS_TTL       = 1800;
$AS_MAX_PAGES = 40;

function as_self() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = strtok($uri, '?');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
}
function as_siteUrl($u) {
    global $AS_BASE;
    $u = trim((string)$u);
    if (!preg_match('#^https?://#i', $u)) return '';
    $p  = parse_url($u);
    $bh = parse_url($AS_BASE, PHP_URL_HOST);
    $hh = isset($p['host']) ? strtolower($p['host']) : '';
    return ($hh === $bh || $hh === 'www.' . $bh) ? $u : '';
}
function as_absUrl($href) {
    global $AS_BASE;
    $href = trim((string)$href);
    if ($href === '') return '';
    if (strpos($href, '//') === 0) return 'https:' . $href;
    if (preg_match('#^https?://#i', $href)) return $href;
    return $AS_BASE . '/' . ltrim($href, '/');
}
function as_get($url, $extraHeaders = []) {
    global $AS_UA;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => $AS_UA,
        CURLOPT_HTTPHEADER => array_merge(['Accept-Language: en-US,en;q=0.9'], $extraHeaders),
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['body' => $body ? $body : '', 'code' => $code];
}
function as_resolve($u, $base_url) {
    global $AS_RAVOK;
    if (preg_match('#^https?://#i', $u)) return $u;
    if (strpos($u, '//') === 0) return 'https:' . $u;
    if ($u !== '' && $u[0] === '/') return $AS_RAVOK . $u;
    return $base_url . $u;
}
function as_ravokId($src) {
    if (!$src) return '';
    if (preg_match('#[?&]data=([A-Za-z0-9]+)#', $src, $m)) return $m[1];
    if (preg_match('#ravok[^/]*/(?:[A-Za-z_]+/)*([A-Za-z0-9]{16,})#i', $src, $m)) return $m[1];
    return '';
}
function as_safe($url) {
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower(isset($p['scheme']) ? $p['scheme'] : ''), ['http', 'https'])) return false;
    $ip = filter_var($p['host'], FILTER_VALIDATE_IP) ? $p['host'] : gethostbyname($p['host']);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
function as_cards($html) {
    $items = []; $seen = [];
    preg_match_all('/<article[^>]*>(.*?)<\/article>/is', $html, $matches);
    foreach ($matches[1] as $block) {
        $title = ''; $thumb = ''; $link = '';
        if (preg_match('/href="([^"]+)"/i', $block, $m)) $link = as_absUrl($m[1]);
        if (!$link || !preg_match('#/(series|movies)/#i', $link) || !as_siteUrl($link)) continue;
        if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/is', $block, $m)) $title = trim(strip_tags($m[1]));
        if (empty($title) || strpos($title, '${') !== false || strpos($title, '{item.') !== false) {
            if (preg_match('/alt="([^"]+)"/i', $block, $m)) $title = trim($m[1]);
        }
        if (empty($title) || strpos($title, '${') !== false || strpos($title, '{item.') !== false) continue;
        if (preg_match('/data-src="([^"]+)"/i', $block, $m)) $thumb = as_absUrl($m[1]);
        elseif (preg_match('/src="([^"]+)"/i', $block, $m) && strpos($m[1], 'data:image') === false) $thumb = as_absUrl($m[1]);
        if (isset($seen[$link])) continue;
        $seen[$link] = true;
        $items[] = ['title' => html_entity_decode($title, ENT_QUOTES, 'UTF-8'), 'thumb' => $thumb, 'link' => $link];
    }
    return $items;
}
function as_crawl($max) {
    global $AS_BASE, $AS_UA;
    $items = []; $seen = []; $page = 1; $batch = 6;
    while ($page <= $max) {
        $mh = curl_multi_init(); $hs = [];
        for ($i = 0; $i < $batch && $page + $i <= $max; $i++) {
            $p  = $page + $i;
            $ch = curl_init($AS_BASE . ($p > 1 ? "/page/{$p}/" : '/'));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_ENCODING => '',
                CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => $AS_UA,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9'],
            ]);
            curl_multi_add_handle($mh, $ch); $hs[$p] = $ch;
        }
        do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
        ksort($hs); $added = 0;
        foreach ($hs as $p => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $body = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            if ($code !== 200 || !$body) continue;
            foreach (as_cards($body) as $it) {
                if (isset($seen[$it['link']])) continue;
                $seen[$it['link']] = 1; $items[] = $it; $added++;
            }
        }
        curl_multi_close($mh);
        if ($added === 0) break;
        $page += $batch;
    }
    return $items;
}
function as_series($url) {
    $res = as_get($url);
    if ($res['code'] !== 200) return ['ok' => false, 'error' => 'Page load nahi hua (HTTP ' . $res['code'] . ')'];
    $raw = $res['body'];
    $title = 'Unknown';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $raw, $m)) $title = trim(strip_tags($m[1]));
    elseif (preg_match('/<meta property="og:title" content="([^"]+)"/i', $raw, $m)) $title = trim($m[1]);
    $thumb = '';
    if (preg_match('/(\/\/image\.tmdb\.org\/[^"]+)/i', $raw, $m)) $thumb = 'https:' . $m[1];
    elseif (preg_match('/<meta property="og:image" content="([^"]+)"/i', $raw, $m)) $thumb = as_absUrl($m[1]);
    $seasons = []; $seen = []; $post_id = '';
    if (preg_match('/data-post="(\d+)"/', $raw, $m)) $post_id = $m[1];
    elseif (preg_match('/postid-(\d+)/', $raw, $m)) $post_id = $m[1];
    $available = [];
    if (preg_match_all('/data-season="(\d+)"/', $raw, $m)) $available = array_unique($m[1]);
    sort($available);
    global $AS_BASE;
    if ($post_id && !empty($available)) {
        foreach ($available as $s_num) {
            $r2 = as_get($AS_BASE . '/wp-admin/admin-ajax.php?action=action_select_season&season=' . $s_num . '&post=' . $post_id);
            if ($r2['code'] === 200 && !empty($r2['body'])) {
                preg_match_all('/href=["\']([^"\']*\/episode\/[^"\']*)["\']/i', $r2['body'], $em);
                if (!empty($em[1])) {
                    foreach (array_unique($em[1]) as $link) {
                        $link = as_absUrl($link);
                        if (isset($seen[$link])) continue;
                        $seen[$link] = true;
                        $s = (int)$s_num; $e = 0;
                        if (preg_match('/-(\d+)x(\d+)/i', $link, $m)) { $s = (int)$m[1]; $e = (int)$m[2]; }
                        if ($e === 0) $e = isset($seasons[$s]) ? count($seasons[$s]) + 1 : 1;
                        if (!isset($seasons[$s])) $seasons[$s] = [];
                        $seasons[$s][] = ['ep_num' => $e, 'link' => $link];
                    }
                }
            }
        }
    }
    if (empty($seasons)) {
        if (strpos($url, '/movies/') !== false) { $seasons[1][] = ['ep_num' => 1, 'link' => $url]; }
        else {
            preg_match_all('/href=["\']([^"\']*(?:episode|watch)\/[^"\']*)["\']/i', $raw, $em);
            if (!empty($em[1])) $seasons[1][] = ['ep_num' => 1, 'link' => as_absUrl($em[1][0])];
        }
    }
    ksort($seasons);
    $out = [];
    foreach ($seasons as $sn => $eps) {
        usort($eps, function ($a, $b) { return $a['ep_num'] - $b['ep_num']; });
        $list = [];
        foreach ($eps as $ep) $list[] = ['number' => (int)$ep['ep_num'], 'link' => $ep['link']];
        $out[] = ['number' => (int)$sn, 'episodes' => $list];
    }
    return ['ok' => true, 'title' => $title, 'thumb' => $thumb, 'seasons' => $out];
}
function as_video($url) {
    $res = as_get($url);
    if ($res['code'] !== 200) return '';
    $raw = str_replace('\\/', '/', $res['body']);
    if (preg_match_all('#https?://[^"\'\s<>]*ravok[^"\'\s<>]*#i', $raw, $mm)) {
        foreach ($mm[0] as $u) { $vid = as_ravokId($u); if ($vid) return $vid; }
    }
    return '';
}
function as_source($vid) {
    global $AS_RAVOK, $AS_BASE;
    $ch = curl_init($AS_RAVOK . "/player/index.php?data=$vid&do=getVideo");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['hash' => $vid, 'r' => $AS_BASE . '/']),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 12, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . $AS_RAVOK . "/video/$vid",
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
        ],
    ]);
    $body = curl_exec($ch); curl_close($ch);
    if ($body === false) return '';
    $j = json_decode($body, true);
    return isset($j['videoSource']) ? (string)$j['videoSource'] : '';
}

if (in_array($__act, ['as_list', 'as_series', 'as_play', 'rv_proxy', 'as_search'], true)) {
    if ($__act !== 'rv_proxy') {
        header('Content-Type: application/json; charset=utf-8');
        if (!function_exists('curl_init')) { echo jEnc(['ok' => false, 'error' => 'cURL missing']); exit; }
    }

    if ($__act === 'as_list') {
        @set_time_limit(120);
        $list = null;
        if (is_file($AS_FILE) && (time() - filemtime($AS_FILE)) < $AS_TTL) $list = json_decode((string)@file_get_contents($AS_FILE), true);
        if (!is_array($list)) {
            $fresh = as_crawl($AS_MAX_PAGES);
            $list = [];
            foreach ($fresh as $it) {
                $list[] = [
                    'id'     => substr(md5($it['link']), 0, 12),
                    'title'  => $it['title'],
                    'poster' => $it['thumb'],
                    'link'   => $it['link'],
                    'vk'     => vote_key('Multi|' . $it['title']),
                ];
            }
            if ($list) @file_put_contents($AS_FILE, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
            elseif (is_file($AS_FILE)) $list = json_decode((string)@file_get_contents($AS_FILE), true);
        }
        echo jEnc(['ok' => is_array($list) && count($list) > 0, 'items' => is_array($list) ? $list : []]);
        exit;
    }

    /* ===== NEW: AS SEARCH (Multi source search) ===== */
    if ($__act === 'as_search') {
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '' || strlen($q) > 80) { echo jEnc(['ok' => false, 'error' => 'bad query']); exit; }
        $items = []; $seen = [];
        $urls  = [
            $AS_BASE . '/?s=' . urlencode($q),
            $AS_BASE . '/search/' . rawurlencode($q) . '/',
        ];
        foreach ($urls as $tryUrl) {
            $res = as_get($tryUrl);
            if ($res['code'] !== 200 || empty($res['body'])) continue;
            foreach (as_cards($res['body']) as $it) {
                if (isset($seen[$it['link']])) continue;
                $seen[$it['link']] = 1;
                $items[] = [
                    'id'     => substr(md5($it['link']), 0, 12),
                    'title'  => $it['title'],
                    'poster' => $it['thumb'],
                    'link'   => $it['link'],
                    'vk'     => vote_key('Multi|' . $it['title']),
                ];
            }
            if ($items) break;
        }
        echo jEnc(['ok' => !empty($items), 'items' => $items]);
        exit;
    }

    if ($__act === 'as_series') {
        $u = as_siteUrl($_GET['url'] ?? '');
        if (!$u) { echo jEnc(['ok' => false, 'error' => 'bad url']); exit; }
        $cf = sys_get_temp_dir() . '/as.series.' . md5($u) . '.json';
        if (is_file($cf) && (time() - filemtime($cf)) < 600) {
            $c = (string)@file_get_contents($cf);
            if ($c !== '') { echo $c; exit; }
        }
        $r = as_series($u);
        $out = jEnc($r);
        if (!empty($r['ok']) && !empty($r['seasons'])) @file_put_contents($cf, $out, LOCK_EX);
        echo $out;
        exit;
    }

    if ($__act === 'as_play') {
        $u = as_siteUrl($_GET['url'] ?? '');
        if (!$u) { echo jEnc(['ok' => false, 'error' => 'bad url']); exit; }
        $vid = as_video($u);
        if (!$vid) { echo jEnc(['ok' => false, 'error' => 'Video id nahi mila']); exit; }
        $src = as_source($vid);
        if (!$src) { echo jEnc(['ok' => false, 'error' => 'Video URL nahi mila']); exit; }
        echo jEnc(['ok' => true, 'url' => as_self() . '?action=rv_proxy&url=' . rawurlencode($src) . '&ext=.m3u8']);
        exit;
    }

    if ($__act === 'rv_proxy') {
        $SELF   = as_self();
        $RAVOK  = $AS_RAVOK;
        $target = $_GET['url'] ?? '';
        if ($target === '') { http_response_code(400); exit; }
        if (!preg_match('#^https?://#i', $target)) $target = $target[0] === '/' ? $RAVOK . $target : $RAVOK . '/' . $target;
        if (!as_safe($target)) { http_response_code(403); exit; }
        while (ob_get_level()) ob_end_clean();
        @ini_set('zlib.output_compression', '0');
        @set_time_limit(0);
        $isM3u8 = null; $buffer = ''; $ctype = '';
        $ch = curl_init($target);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 0, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Referer: ' . $RAVOK . '/', 'Origin: ' . $RAVOK, 'User-Agent: Mozilla/5.0'],
            CURLOPT_HEADERFUNCTION => function ($ch, $hd) use (&$ctype) {
                if (stripos($hd, 'Content-Type:') === 0) $ctype = strtolower(trim(substr($hd, 13)));
                return strlen($hd);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$isM3u8, &$buffer, &$ctype, $target) {
                if ($isM3u8 === null) {
                    $upCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    if ($upCode >= 400) { $isM3u8 = false; http_response_code($upCode); header('Content-Type: text/plain; charset=utf-8'); echo "Upstream HTTP $upCode\n"; return strlen($chunk); }
                    $isM3u8 = strpos($ctype, 'mpegurl') !== false || strpos($target, '.m3u8') !== false || strncmp(ltrim($chunk), '#EXTM3U', 7) === 0;
                    if (!$isM3u8) { header('Content-Type: video/mp2t'); header('Access-Control-Allow-Origin: *'); }
                }
                if ($isM3u8) $buffer .= $chunk; else { echo $chunk; flush(); }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $actual = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if ($isM3u8) {
            $parts = explode('?', $actual);
            $base_url = substr($parts[0], 0, strrpos($parts[0], '/') + 1);
            $out = []; $seen = false;
            foreach (preg_split('/\r\n|\r|\n/', $buffer) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if ($line === '#EXTM3U') { if (!$seen) { $out[] = $line; $seen = true; } continue; }
                if ($line === '#') continue;
                if (strpos($line, 'URI="') !== false) {
                    $s = strpos($line, 'URI="') + 5; $e = strpos($line, '"', $s);
                    $uri = as_resolve(substr($line, $s, $e - $s), $base_url);
                    $line = substr($line, 0, $s) . $SELF . '?action=rv_proxy&url=' . rawurlencode($uri) . substr($line, $e);
                } elseif ($line[0] !== '#') {
                    $line = $SELF . '?action=rv_proxy&url=' . rawurlencode(as_resolve($line, $base_url));
                }
                $out[] = $line;
            }
            header('Content-Type: application/vnd.apple.mpegurl');
            header('Access-Control-Allow-Origin: *');
            echo implode("\n", $out);
        }
        exit;
    }
}

/* ============ LOAD CATALOG ============ */
$raw = null;
if (is_file($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_TTL) $raw = file_get_contents($CACHE_FILE);
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
    elseif (is_file($CACHE_FILE)) $raw = file_get_contents($CACHE_FILE);
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
$__uri_path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$SITE_URL = ($__https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $__uri_path;
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
  --bg:#f5f6fa;--bg2:#ffffff;--text:#1a1a2e;--muted:#64748b;
  --red:#111111;--pink:#111111;--violet:#222222;
  --stroke:rgba(0,0,0,.08);--stroke2:rgba(0,0,0,.12);
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

.menu-btn{display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:50%;color:var(--text);flex:0 0 auto;background:#fff;border:1px solid var(--stroke);box-shadow:var(--shadow-sm);transition:all .2s}
.menu-btn:active{background:rgba(0,0,0,.05)}
.menu-btn svg{width:24px;height:24px;fill:currentColor}

@media(min-width:900px){
.sbox{display:contents}
.sbtn{display:none}
.search{display:block;margin-left:auto;flex:1;width:100%;max-width:340px;border:1px solid var(--stroke);border-radius:14px;background:#fff;padding:11px 14px;font-size:14px;transition:border-color .2s,box-shadow .2s;box-shadow:var(--shadow-sm)}
.search:focus{border-color:#111;box-shadow:0 0 0 3px rgba(0,0,0,.1)}
}

.side-menu{position:fixed;top:0;right:-100%;width:280px;height:100%;background:#fff;z-index:200;transition:right .3s;box-shadow:-5px 0 30px rgba(0,0,0,.15);padding:20px;display:flex;flex-direction:column;gap:10px;overflow-y:auto}
.side-menu.open{right:0}
.menu-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:199;display:none;opacity:0;transition:opacity .3s}
.menu-overlay.open{display:block;opacity:1}
.side-menu h2{font-size:18px;margin:0 0 15px;color:var(--text);display:flex;justify-content:space-between;align-items:center}
.side-menu h2 button{font-size:22px;color:var(--muted);padding:5px}
.side-menu a{display:block;padding:14px 16px;border-radius:12px;background:rgba(0,0,0,.03);color:var(--text);font-weight:700;font-size:15px;text-decoration:none;transition:all .2s}
.side-menu a:hover,.side-menu a:active{background:#111;color:#fff}

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

.filters{display:flex;gap:10px;overflow-x:auto;padding:4px 2px 16px;scrollbar-width:none}
.filter,.season-btn{flex:0 0 auto;padding:10px 18px;border-radius:30px;background:#fff;border:1px solid var(--stroke);color:var(--text);font-weight:800;font-size:13px;transition:all .2s;box-shadow:var(--shadow-sm)}
.filter.active,.season-btn.active,.episode-btn.active{background:var(--grad);color:#fff;border-color:transparent;box-shadow:0 6px 20px rgba(0,0,0,.3)}

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
/* NEW: Language tags on posters */
.poster-tag{position:absolute;top:8px;right:8px;z-index:4;padding:4px 10px;border-radius:10px;font-size:10px;font-weight:900;color:#fff;letter-spacing:.3px;text-transform:uppercase;border:1px solid rgba(255,255,255,.28);box-shadow:0 4px 12px rgba(0,0,0,.45);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);white-space:nowrap}
.poster-tag.hindi  {background:linear-gradient(135deg,#ff8a00,#e65100)}
.poster-tag.multi  {background:linear-gradient(135deg,#1e88e5,#0d47a1)}
.poster-tag.english{background:linear-gradient(135deg,#43a047,#1b5e20)}
.poster-tag.dub    {background:linear-gradient(135deg,#8e24aa,#4a148c)}
.pbar{position:absolute;left:0;right:0;bottom:0;height:4px;background:rgba(255,255,255,.3);z-index:3}
.pbar i{display:block;height:100%;background:#111}
.card-info{padding:10px 4px 0}
.card-title{font-size:15px;font-weight:800;line-height:1.3;color:var(--text);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}
.card-info .card-sub{display:none}
.empty{grid-column:1/-1;padding:34px 10px;text-align:center;color:var(--muted)}

.watch{display:none;position:fixed;inset:0;z-index:100;overflow-y:auto;background:radial-gradient(60% 40% at 8% 0%,rgba(17,17,17,.06),transparent 70%),radial-gradient(50% 40% at 100% 22%,rgba(34,34,34,.06),transparent 70%),var(--bg)}
.watch-head{position:relative;z-index:7;height:var(--head);display:flex;align-items:center;padding:0 16px;background:rgba(255,255,255,.9);-webkit-backdrop-filter:var(--blur);backdrop-filter:var(--blur);border-bottom:1px solid var(--stroke)}
.watch-head span{font-weight:800;font-size:15px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.video-box{position:relative;z-index:5;width:100%;aspect-ratio:16/9;max-height:70vh;overflow:hidden;user-select:none;-webkit-user-select:none;margin:0 auto;border-bottom:1px solid rgba(255,255,255,.1);background:#000!important}
.video-box:fullscreen{max-height:none;max-width:none;aspect-ratio:auto;height:100%;margin:0;border-radius:0;background:#000}
.video-box:-webkit-full-screen{max-height:none;max-width:none;aspect-ratio:auto;height:100%;margin:0;border-radius:0;background:#000}
.video-box.idle{cursor:none}
video{width:100%;height:100%;object-fit:contain;background:transparent;display:block}

.v-spinner{display:none;position:absolute;top:50%;left:50%;width:48px;height:48px;margin:-24px 0 0 -24px;border:4px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:vspin 1s linear infinite;z-index:10;pointer-events:none}
@keyframes vspin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}
.loading .v-spinner{display:block}
.loading #big{opacity:0}
.loading .center button{opacity:0;pointer-events:none}

.bottom{position:absolute;left:0;right:0;bottom:0;z-index:3;padding:34px 14px 10px;background:linear-gradient(to top,rgba(0,0,0,.95) 0%,rgba(0,0,0,.6) 50%,transparent);transition:opacity .25s}
.center{position:absolute;inset:0;z-index:2;display:flex;align-items:center;justify-content:center;gap:clamp(18px,8vw,60px);pointer-events:none;transition:opacity .25s}
.center button{pointer-events:auto;color:#fff;background:rgba(0,0,0,.15)!important;border:1px solid rgba(255,255,255,.4)!important;border-radius:50%;width:46px;height:46px;font-size:28px;display:flex;align-items:center;justify-content:center;-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);box-shadow:none!important}
.center #big{width:64px;height:64px;font-size:44px;background:rgba(0,0,0,.2)!important;border:2px solid rgba(255,255,255,.6)!important;box-shadow:none!important}
.center .ic{filter:drop-shadow(0 2px 6px rgba(0,0,0,.8))}
.idle .bottom,.idle .center{opacity:0;pointer-events:none}.idle .center button{pointer-events:none}

.prog{position:relative;height:22px;display:flex;align-items:center;cursor:pointer;touch-action:none}
.track{position:relative;width:100%;height:4px;border-radius:2px;background:rgba(255,255,255,.2)!important;transition:height .15s}
.prog:hover .track{height:6px}
.buf,.bar{position:absolute;left:0;top:0;height:100%;border-radius:2px;width:0}
.buf{background:rgba(255,255,255,.3)!important}
.bar{background:#fff!important;box-shadow:none!important}
.bar::after{content:"";position:absolute;right:-7px;top:50%;width:14px;height:14px;margin-top:-7px;background:#fff!important;border:2px solid #fff!important;border-radius:50%;box-shadow:none!important}
.ctrls{display:flex;align-items:center;gap:6px;color:#fff}
.ctrl-btn{color:#fff;font-size:26px;min-width:40px;height:40px;display:flex;align-items:center;justify-content:center;border-radius:12px;transition:background .15s,color .15s}
.ctrl-btn:hover{background:rgba(255,255,255,.2)!important}
.ctrl-btn:active{background:rgba(255,255,255,.3)!important}
.time{font-size:12px;font-weight:700;color:#fff;white-space:nowrap;text-shadow:0 1px 6px rgba(0,0,0,.8)}
.sp{flex:1}
.menu{display:none;position:absolute;right:10px;bottom:62px;z-index:6;width:200px;max-height:75%;overflow-y:auto;padding:8px;border:1px solid #444!important;border-radius:16px;background:rgba(20,20,20,.95)!important;-webkit-backdrop-filter:var(--blur);backdrop-filter:var(--blur);box-shadow:0 16px 38px rgba(0,0,0,.5)!important}
.menu.open{display:block}
.menu strong{display:block;padding:6px 10px 2px;font-size:11px;color:#fff!important;letter-spacing:.5px;text-transform:uppercase}
.opts{display:flex;flex-wrap:wrap;gap:4px;padding:4px}
.opt{padding:7px 11px;border-radius:9px;background:rgba(255,255,255,.1)!important;color:#fff!important;font-size:12.5px;font-weight:700;border:1px solid transparent!important}
.opt.active{background:#fff!important;color:#000!important;border-color:#fff!important}
.verr{position:absolute;inset:0;z-index:4;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px;background:rgba(0,0,0,.9)!important;color:#fff;font-weight:700}

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

.foot{max-width:1176px;margin:10px auto 0;padding:18px 14px calc(26px + env(safe-area-inset-bottom));text-align:center;color:var(--muted);font-size:13px;font-weight:700;border-top:1px solid var(--stroke)}
.sr-only{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);border:0}

.toptrack{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;padding:2px 2px 10px;-webkit-overflow-scrolling:touch}
.tslide{flex:0 0 86%;max-width:560px;scroll-snap-align:center;position:relative;height:min(58vh,440px);border-radius:26px;overflow:hidden;background:var(--glass);border:1px solid var(--stroke);box-shadow:var(--shadow);cursor:pointer;scroll-snap-stop:always}
.trank{position:absolute;top:12px;left:12px;z-index:3;background:#111;color:#fff;font-weight:900;font-size:14px;padding:5px 13px;border-radius:14px;box-shadow:0 4px 12px rgba(0,0,0,.4)}
.tbody{position:absolute;left:0;right:0;bottom:0;z-index:2;padding:0 16px 16px;display:flex;flex-direction:column;align-items:flex-start;gap:8px;max-width:60%}
.tbody .hero-title{margin:0}
.tmeta{display:flex;flex-wrap:wrap;gap:8px}
.tmeta span{padding:4px 11px;border-radius:50px;font-size:12px;font-weight:800;color:#fff;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25)}
.tbody .hbtn{flex:none;padding:10px 22px}

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
</svg>
<header>
  <img class="logo" src="https://i.ibb.co/d4hNqPZX/move-tok-logo-black-transparent.png" alt="Move Tok">
  <div class="brand">Move Tok</div>
  <div class="sbox">
    <button id="sBtn" class="sbtn" type="button" aria-label="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg></button>
    <input id="search" class="search" type="search" placeholder="Search movies, dramas, anime...">
  </div>
  <button id="menuBtn" class="menu-btn" aria-label="Menu"><svg class="ic" viewBox="0 0 24 24"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg></button>
</header>

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
    <button class="filter" data-filter="Multi">Multi Language Anime</button>
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

<section id="pageContent" class="page-content">
    <div id="pageText"></div>
</section>

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
    <button id="backBtn" class="ctrl-btn" aria-label="Back" style="color:var(--text);min-width:36px"><svg class="ic"><use href="#i-back"/></svg></button>
    <span id="vTitle" style="margin-left:8px"></span>
  </div>
  <div class="video-box" id="vbox">
    <video id="video" playsinline webkit-playsinline preload="auto" poster="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"></video>
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
        <button id="setBtn" class="ctrl-btn" aria-label="Settings"><svg class="ic"><use href="#i-gear"/></svg></button>
        <button id="fsBtn" class="ctrl-btn" aria-label="Fullscreen"><svg class="ic"><use id="fsIc" href="#i-fs"/></svg></button>
      </div>
    </div>
    <div id="menu" class="menu">
      <strong>Quality</strong><div id="qOpts" class="opts"></div>
      <div id="aSec" hidden><strong>Audio / Language</strong><div id="aOpts" class="opts"></div></div>
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

/* ============ GLOBALS ============ */
const $ = id => document.getElementById(id);
const LATEST_MS = 2*24*60*60*1000;
const RECENT_MAX = 5;
const HERO_MAX = 5;
let currentView = 'home';
let infoMode = false, allItems = [], groups = [], currentFilter = 'all';
let currentGroup = null, currentEpisode = null, currentSeason = 1;
let watchOpen = false, pendingSeek = 0, lastSave = 0;
let multiList = [], multiState = "idle";
let hlsInstance = null;
let currentStreams = [], currentQuality = "";
let playerIdleTimer = null;

/* ============ MENU & ROUTING ============ */
function setView(view) {
    if (!history.state || history.state.view !== view) history.pushState({ view }, '', location.href);
    applyView(view);
}
function applyView(view) {
    currentView = view;
    closeMenuUI();
    const mainC = $("mainContent"), pageC = $("pageContent"), searchP = $("searchPage");
    const sBox = document.querySelector(".sbox"), filterNav = $("filterNav"), menuBtn = $("menuBtn");
    mainC.style.display = "none"; pageC.style.display = "none"; searchP.style.display = "none"; filterNav.style.display = "none";
    sBox.style.display = ""; menuBtn.style.marginLeft = "8px";

    if (view === 'home' || view === 'hentai') {
        mainC.style.display = "block"; filterNav.style.display = "flex";
        if (view === 'hentai') { $("topTitle").textContent = "Top Rated Hentai"; currentFilter = 'Hentai'; }
        else {
            $("topTitle").textContent = "Top Rated";
            if (!currentFilter || !['all','Anime','Movie','Multi'].includes(currentFilter)) currentFilter = 'all';
        }
        document.querySelectorAll(".filter").forEach(b => b.classList.toggle("active", b.dataset.filter === currentFilter));
        ensureMultiLoaded();
        renderSlider(); render();
        window.scrollTo(0, 0);
    } else if (view === 'search') {
        searchP.style.display = "block"; sBox.style.display = "none"; menuBtn.style.marginLeft = "auto";
        $("searchPageInput").focus();
        render();
        window.scrollTo(0, 0);
    } else {
        pageC.style.display = "block"; sBox.style.display = "none"; menuBtn.style.marginLeft = "auto";
        $("searchPageInput").value = "";
        renderPage(view);
        window.scrollTo(0, 0);
    }
}
function renderPage(view) {
    let html = "";
    if (view === 'privacy') {
        html = `<h1>Privacy Policy</h1><p>At Move Tok, accessible from our website, one of our main priorities is the privacy of our visitors. This Privacy Policy document contains types of information that is collected and recorded by Move Tok and how we use it.</p><p>If you have additional questions or require more information about our Privacy Policy, do not hesitate to contact us.</p><h3>Log Files</h3><p>Move Tok follows a standard procedure of using log files. These files log visitors when they visit websites. The information collected by log files include internet protocol (IP) addresses, browser type, Internet Service Provider (ISP), date and time stamp, referring/exit pages, and possibly the number of clicks.</p><h3>Cookies and Web Beacons</h3><p>Like any other website, Move Tok uses "cookies". These cookies are used to store information including visitors' preferences, and the pages on the website that the visitor accessed or visited.</p>`;
    } else if (view === 'terms') {
        html = `<h1>Terms & Conditions</h1><p>Welcome to Move Tok!</p><p>These terms and conditions outline the rules and regulations for the use of Move Tok's Website.</p><p>By accessing this website we assume you accept these terms and conditions. Do not continue to use Move Tok if you do not agree to take all of the terms and conditions stated on this page.</p><h3>License</h3><p>Unless otherwise stated, Move Tok and/or its licensors own the intellectual property rights for all material on Move Tok. All intellectual property rights are reserved. You may access this from Move Tok for your own personal use subjected to restrictions set in these terms and conditions.</p><p>You must not: Republish material from Move Tok, Sell, rent or sub-license material from Move Tok, Reproduce, duplicate or copy material from Move Tok.</p>`;
    } else if (view === 'disclaimer') {
        html = `<h1>Disclaimer</h1><p>The information provided by Move Tok is for general informational purposes only. All information on the Site is provided in good faith, however we make no representation or warranty of any kind, express or implied, regarding the accuracy, adequacy, validity, reliability, availability, or completeness of any information on the Site.</p><p>Under no circumstance shall we have any liability to you for any loss or damage of any kind incurred as a result of the use of the site or reliance on any information provided on the site.</p><p>This website does not host any files on its server. All contents are provided by non-affiliated third parties.</p>`;
    } else if (view === 'contact') {
        html = `<h1>Contact Us</h1><p>If you have any questions or suggestions about our website, do not hesitate to contact us.</p><p>Email: <a href="mailto:h4xzico1111@gmail.com">h4xzico1111@gmail.com</a></p><p>Telegram: <a href="https://t.me/movetok1" target="_blank">@movetok1</a></p>`;
    }
    $("pageText").innerHTML = html;
}
function openMenuUI() { $("sideMenu").classList.add("open"); $("menuOverlay").classList.add("open"); }
function closeMenuUI() { $("sideMenu").classList.remove("open"); $("menuOverlay").classList.remove("open"); }

/* ============ HELPERS ============ */
function safeUrl(v) {
    if (!v || typeof v !== "string") return "";
    try { const u = new URL(v.trim()); return (u.protocol === "https:" || u.protocol === "http:") ? u.href : ""; } catch { return ""; }
}
function mkImg(src, alt, cls) {
    const i = document.createElement("img"); i.src = src; i.alt = alt || ""; i.loading = "lazy";
    if (cls) i.className = cls;
    i.onerror = () => i.remove();
    return i;
}
/* NEW: language label helper */
function langInfo(g) {
    if (!g) return { text: 'Hindi', cls: 'hindi' };
    if (g.type === 'Multi')  return { text: 'Multi Language', cls: 'multi' };
    if (g.type === 'Hentai') return { text: 'Hindi',         cls: 'hindi' };
    if (g.type === 'Movie')  return { text: 'Hindi',         cls: 'hindi' };
    const t = String(g.title || '');
    if (/english\s*dubb?ed|eng\s*dub|\beng\.?\b/i.test(t)) return { text: 'English Dubbed', cls: 'english' };
    if (/tamil|telugu|malayalam|kannada/i.test(t))         return { text: 'South Dub',      cls: 'dub' };
    return { text: 'Hindi', cls: 'hindi' };
}
function addPoster(box, src, alt, label) {
    if (!src) return;
    const bg = mkImg(src, "", "bg"); bg.setAttribute("aria-hidden", "true");
    box.style.setProperty("--pimg", 'url("' + src.replace(/["\\n()]/g, c => encodeURIComponent(c)) + '")');
    box.append(bg, mkImg(src, alt, "fg"));
    if (label) {
        const t = document.createElement("span");
        t.className = "poster-tag " + (label.cls || "hindi");
        t.textContent = label.text;
        box.appendChild(t);
    }
}
function addBanner(media, g) {
    if (!g.banner || g.banner === g.poster) return;
    const i = mkImg(g.banner, "", "ban"); i.decoding = "async";
    i.onerror = () => { i.remove(); media.classList.remove("has-ban"); };
    media.classList.add("has-ban"); media.appendChild(i);
}
function icon(id) {
    const s = document.createElementNS("http://www.w3.org/2000/svg", "svg"); s.setAttribute("class", "ic");
    const u = document.createElementNS("http://www.w3.org/2000/svg", "use"); u.setAttribute("href", "#" + id);
    s.appendChild(u); return s;
}
function setIc(el, id) { el.setAttribute("href", "#" + id); }
function fmt(s) {
    if (!isFinite(s) || s < 0) s = 0;
    const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), sec = Math.floor(s % 60);
    return (h ? h + ":" + String(m).padStart(2, "0") : m) + ":" + String(sec).padStart(2, "0");
}
const store = {
    get(k) { try { return Number(localStorage.getItem(k)) || 0; } catch { return 0; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch {} },
    del(k) { try { localStorage.removeItem(k); } catch {} }
};
const gkey = g => g.type + "|" + g.title.toLowerCase();
const kindLabel = g => g.type === "Movie" ? "Hindi Movie" : g.type === "Hentai" ? "Hindi Hentai" : g.type === "Multi" ? "Multi Language Anime" : "Drama / Anime";
function timeAgo(ts) {
    if (!ts) return "";
    const s = Math.floor((Date.now() - ts * 1000) / 1000);
    if (s < 60) return "abhi";
    if (s < 3600) return Math.floor(s / 60) + "m";
    if (s < 86400) return Math.floor(s / 3600) + "h";
    if (s < 2592000) return Math.floor(s / 86400) + "d";
    return new Date(ts * 1000).toLocaleDateString();
}

/* ============ RECENT ============ */
const WEEK_MS = 7 * 24 * 60 * 60 * 1000;
function getRecent() {
    let r; try { r = JSON.parse(localStorage.getItem("zt:recent")) || []; } catch { r = []; }
    if (!r.length) return r;
    let ws = store.get("zt:wk");
    if (!ws) { ws = Math.min(...r.map(x => x.t || Date.now())); store.set("zt:wk", ws); }
    if (Date.now() - ws >= WEEK_MS) {
        r.forEach(x => { store.del("zt:" + x.e); });
        try { localStorage.setItem("zt:recent", "[]"); } catch {}
        store.del("zt:wk"); return [];
    }
    return r;
}
function pushRecent(g, ep) {
    const cur = getRecent();
    if (!cur.length) store.set("zt:wk", Date.now());
    const r = cur.filter(x => x.g !== gkey(g));
    r.unshift({ g: gkey(g), e: ep.id, t: Date.now() });
    try { localStorage.setItem("zt:recent", JSON.stringify(r.slice(0, RECENT_MAX))); } catch {}
}

/* ============ SEARCH ============ */
const SYN = [
    ["movie", "movies", "film", "films", "picture", "cinema"],
    ["kdrama", "kdramas", "korean", "drama", "dramas", "serial", "series"],
    ["hindi", "dubbed", "dub"],
    ["hentai", "ecchi"]
];
const norm = s => String(s || "").toLowerCase().replace(/[-']/g, "").replace(/[^a-z0-9\u0900-\u097f\s]/g, " ").replace(/\s+/g, " ").trim();
const synOf = t => { const g = SYN.find(x => x.includes(t)); return g ? g : [t]; };
const tolerance = t => t.length < 4 ? 0 : t.length < 7 ? 1 : 2;
function lev(a, b) {
    const m = a.length, n = b.length; if (!m) return n; if (!n) return m;
    let p = Array.from({ length: n + 1 }, (_, j) => j);
    for (let i = 1; i <= m; i++) { const c = [i]; for (let j = 1; j <= n; j++) c[j] = Math.min(p[j] + 1, c[j - 1] + 1, p[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)); p = c; }
    return p[n];
}
function tokCost(t, g) {
    let best = Infinity;
    for (const v of synOf(t)) {
        if (g.hay.includes(v)) return 0;
        for (const w of g.words) {
            let d = lev(v, w);
            if (w.length > v.length) d = Math.min(d, lev(v, w.slice(0, v.length)));
            if (d < best) best = d;
        }
    }
    return best;
}
function searchGroups(q, pool) {
    const toks = norm(q).split(" ").filter(Boolean);
    if (!toks.length) return { list: pool, sugg: [] };
    const enriched = pool.map(g => ({
        g,
        hay: norm(g.title),
        words: norm(g.title).split(" ").filter(Boolean)
    }));
    const scored = enriched.map(e => {
        const c = toks.map(t => tokCost(t, e));
        return { g: e.g, ok: c.every((x, i) => x <= tolerance(toks[i])), cost: c.reduce((a, b) => a + b, 0), ratio: c.reduce((a, x, i) => a + Math.min(1, x / toks[i].length), 0) / toks.length };
    });
    const list = scored.filter(x => x.ok).sort((a, b) => a.cost - b.cost || b.g.ts - a.g.ts).map(x => x.g);
    const sugg = list.length ? [] : scored.sort((a, b) => a.ratio - b.ratio || b.g.ts - a.g.ts).slice(0, 6).map(x => x.g);
    return { list, sugg };
}

/* ============ DATA BUILD ============ */
function toTime(v) { const d = Date.parse(v || ""); return isNaN(d) ? 0 : d; }
function buildItems() {
    const out = [];
    const list = CATALOG && Array.isArray(CATALOG.anime) ? CATALOG.anime : [];
    list.forEach(a => {
        if (!a || !a.slug || !a.title) return;
        const type = a.category === "movie" ? "Movie" : a.category === "hentai" ? "Hentai" : "Anime";
        const poster = safeUrl(a.poster) || safeUrl(a.banner);
        (Array.isArray(a.seasons) ? a.seasons : []).forEach(s => {
            (Array.isArray(s.episodes) ? s.episodes : Array.from({ length: s.count || 0 }, (_, i) => ({ number: i + 1 }))).forEach(e => {
                const q = {};
                (e.sources || []).forEach(x => { const u = safeUrl(x && x.url); if (u) q[x.quality || "Default"] = u; });
                const eu = safeUrl(e.url);
                if (eu && !Object.values(q).includes(eu)) q[e.quality || "Default"] = eu;
                out.push({
                    id: a.slug + "|" + s.number + "|" + e.number,
                    title: String(a.title).trim(),
                    type,
                    poster,
                    season: Number(s.number) || 1,
                    episode: Number(e.number) || 1,
                    qualities: q,
                    mp4Url: "",
                    blk: a.blk ? { t: a.blk, u: a.category === "movie" ? "" : (s.key || s.number) + "-" + e.number } : null,
                    rating: Number(a.rating) || 0,
                    vk: a.vk || "",
                    banner: safeUrl(a.banner),
                    ts: toTime(e.added_at) || toTime(a.added_at)
                });
            });
        });
    });
    const nowT = Date.now();
    multiList.forEach((m, i) => {
        if (!m || !m.id || !m.title || !m.link) return;
        const poster = safeUrl(m.poster);
        out.push({
            id: "as-" + m.id + "|1|1",
            slug: "as-" + m.id,
            title: String(m.title).trim(),
            type: "Multi",
            poster,
            season: 1,
            episode: 1,
            qualities: {},
            mp4Url: "",
            blk: null,
            as: { u: m.link, ph: true },
            rating: 0,
            vk: m.vk || "",
            banner: poster,
            ts: nowT - i
        });
    });
    return out;
}
function buildGroups(items) {
    const map = new Map();
    (items || []).forEach(g => {
        if (!g || !g.title) return;
        const k = gkey(g);
        if (!map.has(k)) {
            map.set(k, {
                key: k, type: g.type, title: g.title, poster: g.poster, banner: g.banner,
                rating: g.rating || 0, vk: g.vk || "", ts: g.ts || 0,
                blk: g.blk || null, as: g.as || null,
                seasons: new Map()
            });
        }
        const grp = map.get(k);
        if (!grp.seasons.has(g.season)) grp.seasons.set(g.season, []);
        grp.seasons.get(g.season).push(g);
        if ((g.ts || 0) > (grp.ts || 0)) grp.ts = g.ts;
    });
    return Array.from(map.values()).map(grp => {
        const sn = Array.from(grp.seasons.keys()).sort((a, b) => a - b);
        return {
            ...grp,
            seasonsList: sn.map(n => ({
                number: n,
                episodes: grp.seasons.get(n).sort((a, b) => a.episode - b.episode)
            }))
        };
    }).sort((a, b) => (b.ts || 0) - (a.ts || 0));
}
function rebuildAll() {
    allItems = buildItems();
    groups = buildGroups(allItems);
}
function ensureMultiLoaded(force) {
    if (force) multiState = "idle";
    if (multiState === "loading" || multiState === "done") return;
    multiState = "loading";
    fetch('?action=as_list', { cache: 'no-store' })
        .then(r => r.json())
        .then(j => {
            if (j && j.ok && Array.isArray(j.items) && j.items.length) {
                multiList = j.items;
                multiState = "done";
                rebuildAll();
                if (currentView === 'home' || currentView === 'hentai') {
                    try { renderSlider(); render(); } catch (e) {}
                }
            } else {
                multiState = "idle";
            }
        })
        .catch(() => { multiState = "idle"; });
}

/* ============ RENDER ============ */
function makeCard(g) {
    if (!g) return null;
    const card = document.createElement("div");
    card.className = "card";
    const poster = document.createElement("div");
    poster.className = "poster";
    addPoster(poster, g.poster, g.title, langInfo(g));
    card.appendChild(poster);
    const info = document.createElement("div");
    info.className = "card-info";
    const t = document.createElement("div");
    t.className = "card-title";
    t.textContent = g.title;
    info.appendChild(t);
    card.appendChild(info);
    card.onclick = () => openGroup(g);
    return card;
}
function makeRowCard(g) {
    if (!g) return null;
    const card = document.createElement("div");
    card.className = "rcard";
    const poster = document.createElement("div");
    poster.className = "poster";
    addPoster(poster, g.poster, g.title, langInfo(g));
    card.appendChild(poster);
    const t = document.createElement("div");
    t.className = "card-title";
    t.textContent = g.title;
    card.appendChild(t);
    card.onclick = () => openGroup(g);
    return card;
}
function makeTopSlide(g, rank) {
    if (!g) return null;
    const el = document.createElement("div");
    el.className = "tslide";
    const media = document.createElement("div");
    media.className = "hero-media";
    addPoster(media, g.poster, g.title, langInfo(g));
    addBanner(media, g);
    el.appendChild(media);
    const rk = document.createElement("div"); rk.className = "trank"; rk.textContent = "#" + rank; el.appendChild(rk);
    const body = document.createElement("div"); body.className = "tbody";
    const tt = document.createElement("div"); tt.className = "hero-title"; tt.textContent = g.title; body.appendChild(tt);
    const meta = document.createElement("div"); meta.className = "tmeta";
    const sp1 = document.createElement("span"); sp1.textContent = kindLabel(g); meta.appendChild(sp1);
    if (g.rating > 0) { const sp2 = document.createElement("span"); sp2.textContent = "★ " + g.rating.toFixed(1); meta.appendChild(sp2); }
    body.appendChild(meta);
    el.appendChild(body);
    el.onclick = () => openGroup(g);
    return el;
}
function renderSlider() {
    const slider = $("slider"), dots = $("dots"), section = $("featuredSection");
    if (!slider || !dots || !section) return;
    slider.innerHTML = ""; dots.innerHTML = "";
    let pool = currentFilter === 'hentai' || currentView === 'hentai'
        ? groups.filter(g => g.type === 'Hentai')
        : groups.filter(g => g.type !== 'Hentai');
    pool = pool.slice().sort((a, b) => (b.ts || 0) - (a.ts || 0)).slice(0, HERO_MAX);
    if (!pool.length) { section.hidden = true; return; }
    section.hidden = false;
    pool.forEach((g, i) => {
        const slide = document.createElement("div");
        slide.className = "hero-slide";
        const media = document.createElement("div"); media.className = "hero-media";
        addPoster(media, g.poster, g.title, langInfo(g));
        addBanner(media, g);
        slide.appendChild(media);
        const body = document.createElement("div"); body.className = "hero-body";
        const kind = document.createElement("div"); kind.className = "hero-kind";
        const kspan = document.createElement("span"); kspan.textContent = kindLabel(g); kind.appendChild(kspan);
        if (g.rating > 0) { const rs = document.createElement("span"); rs.textContent = "★ " + g.rating.toFixed(1); kind.appendChild(rs); }
        body.appendChild(kind);
        const tt = document.createElement("div"); tt.className = "hero-title"; tt.textContent = g.title; body.appendChild(tt);
        const acts = document.createElement("div"); acts.className = "hero-actions";
        const play = document.createElement("button"); play.className = "hbtn play"; play.type = "button";
        play.innerHTML = '<svg class="ic"><use href="#i-play"/></svg> Watch Now';
        play.onclick = (e) => { e.stopPropagation(); openGroup(g); };
        const info = document.createElement("button"); info.className = "hbtn info"; info.type = "button";
        info.innerHTML = '<svg class="ic"><use href="#i-info"/></svg> Details';
        info.onclick = (e) => { e.stopPropagation(); openGroup(g); };
        acts.appendChild(play); acts.appendChild(info);
        body.appendChild(acts);
        slide.appendChild(body);
        slide.onclick = () => openGroup(g);
        slider.appendChild(slide);
        const d = document.createElement("button"); d.className = "dot" + (i === 0 ? " active" : "");
        d.onclick = (e) => { e.stopPropagation(); slider.scrollTo({ left: slide.offsetLeft, behavior: 'smooth' }); };
        dots.appendChild(d);
    });
    slider.onscroll = () => {
        const idx = Math.round(slider.scrollLeft / slider.clientWidth);
        dots.querySelectorAll(".dot").forEach((d, i) => d.classList.toggle("active", i === idx));
    };
    const prev = $("heroPrev"), next = $("heroNext");
    if (prev) prev.onclick = () => slider.scrollBy({ left: -slider.clientWidth, behavior: 'smooth' });
    if (next) next.onclick = () => slider.scrollBy({ left: slider.clientWidth, behavior: 'smooth' });
}
function renderRecent() {
    const row = $("recentRow"), sec = $("recentSection");
    if (!row || !sec) return;
    const recent = getRecent();
    row.innerHTML = "";
    if (!recent.length) { sec.hidden = true; return; }
    let count = 0;
    recent.forEach(r => {
        const g = groups.find(x => x.key === r.g);
        if (!g) return;
        const c = makeRowCard(g); if (!c) return;
        row.appendChild(c); count++;
    });
    sec.hidden = count === 0;
}
function renderLatest() {
    const row = $("latestRow"), sec = $("latestSection");
    if (!row || !sec) return;
    row.innerHTML = "";
    const now = Date.now();
    let pool = groups.filter(g => (now - (g.ts || 0)) < LATEST_MS && g.type !== 'Hentai');
    if (currentView === 'hentai') pool = groups.filter(g => (now - (g.ts || 0)) < LATEST_MS && g.type === 'Hentai');
    pool.sort((a, b) => (b.ts || 0) - (a.ts || 0));
    pool.slice(0, 15).forEach(g => { const c = makeRowCard(g); if (c) row.appendChild(c); });
    sec.hidden = pool.length === 0;
}
function renderTop() {
    const row = $("topRow"), sec = $("topSection");
    if (!row || !sec) return;
    row.innerHTML = "";
    let pool = groups.filter(g => g.rating > 0);
    if (currentView === 'hentai') pool = pool.filter(g => g.type === 'Hentai');
    else pool = pool.filter(g => g.type !== 'Hentai');
    pool.sort((a, b) => b.rating - a.rating);
    pool.slice(0, 10).forEach((g, i) => { const c = makeTopSlide(g, i + 1); if (c) row.appendChild(c); });
    sec.hidden = pool.length === 0;
}
function render() {
    const catalog = $("catalog"), count = $("count");
    if (!catalog) return;
    let pool = groups;
    if (currentFilter === 'Anime') pool = groups.filter(g => g.type === 'Anime');
    else if (currentFilter === 'Movie') pool = groups.filter(g => g.type === 'Movie');
    else if (currentFilter === 'Multi') pool = groups.filter(g => g.type === 'Multi');
    else if (currentFilter === 'Hentai') pool = groups.filter(g => g.type === 'Hentai');
    else pool = groups.filter(g => g.type !== 'Hentai');
    catalog.innerHTML = "";
    if (!pool.length) {
        const d = document.createElement("div"); d.className = "empty";
        d.textContent = multiState === "loading" ? "Loading..." : "Abhi koi title nahi hai.";
        catalog.appendChild(d);
        if (count) count.textContent = "0 titles";
        return;
    }
    pool.forEach(g => { const c = makeCard(g); if (c) catalog.appendChild(c); });
    if (count) count.textContent = pool.length + " titles";
    try { renderRecent(); } catch (e) {}
    try { renderLatest(); } catch (e) {}
    try { renderTop(); } catch (e) {}
}

/* ============ OPEN GROUP / WATCH ============ */
async function openGroup(g) {
    if (!g) return;
    // Multi: fetch episodes if not loaded
    if (g.type === 'Multi' && g.as && g.as.u && (!g.seasonsList || !g.seasonsList.length || (g.seasonsList.length === 1 && g.seasonsList[0].episodes.length === 1 && !g.seasonsList[0].episodes[0].as))) {
        try {
            const r = await fetch("?action=as_series&url=" + encodeURIComponent(g.as.u), { cache: 'no-store' });
            const j = await r.json();
            if (j.ok && j.seasons && j.seasons.length) {
                g.seasonsList = j.seasons.map(s => ({
                    number: s.number,
                    episodes: s.episodes.map(e => ({
                        id: "as-" + g.key + "|" + s.number + "|" + e.number,
                        title: g.title,
                        type: "Multi",
                        poster: g.poster || j.thumb || "",
                        season: s.number,
                        episode: e.number,
                        qualities: {},
                        as: { u: e.link, ph: true },
                        rating: g.rating || 0,
                        vk: g.vk || "",
                        banner: g.banner || ""
                    }))
                }));
                if (j.title) g.title = j.title;
                if (j.thumb) g.poster = j.thumb;
            }
        } catch (e) {}
    }
    currentGroup = g;
    currentSeason = g.seasonsList && g.seasonsList.length ? g.seasonsList[0].number : 1;
    const firstEp = g.seasonsList && g.seasonsList.length && g.seasonsList[0].episodes.length ? g.seasonsList[0].episodes[0] : null;
    if (!firstEp) { alert("Episodes not available yet."); return; }
    openWatch(firstEp, g);
}
function openWatch(item, group) {
    if (!item) return;
    currentEpisode = item;
    if (group) currentGroup = group;
    watchOpen = true;
    $("watch").style.display = "block";
    document.body.style.overflow = "hidden";

    const poster = item.poster || (currentGroup && currentGroup.poster) || "";
    const banner = (currentGroup && currentGroup.banner) || poster;
    $("vTitle").textContent = item.title + " - S" + item.season + "E" + item.episode;
    $("detailPoster").src = poster;
    $("detailTitle").textContent = item.title;
    $("detailType").textContent = kindLabel(currentGroup || item);
    $("episodeLabel").textContent = "S" + item.season + " E" + item.episode;
    $("infoLabel").textContent = item.rating > 0 ? "★ " + item.rating.toFixed(1) : "";
    if (banner) $("details").style.setProperty("--bg-image", 'url("' + banner.replace(/["\\n()]/g, c => encodeURIComponent(c)) + '")');

    // Seasons
    const seasonBox = $("seasonButtons");
    seasonBox.innerHTML = "";
    const seasons = (currentGroup && currentGroup.seasonsList) || [];
    seasons.forEach(s => {
        const b = document.createElement("button");
        b.className = "season-btn" + (s.number === item.season ? " active" : "");
        b.textContent = "Season " + s.number;
        b.onclick = () => {
            currentSeason = s.number;
            const first = s.episodes[0];
            if (first) {
                seasonBox.querySelectorAll(".season-btn").forEach(x => x.classList.toggle("active", x === b));
                playEpisode(first);
            }
        };
        seasonBox.appendChild(b);
    });
    if (!seasons.length) seasonBox.innerHTML = '<div style="padding:10px;color:var(--muted)">No seasons</div>';

    // Episodes
    renderEpisodes(item.season, item);

    // Comments
    if (item.vk) loadComments(item.vk);

    // Save recent
    pushRecent(currentGroup || item, item);

    // Play
    playEpisode(item);
}
function renderEpisodes(seasonNum, currentEp) {
    const epBox = $("episodeButtons");
    epBox.innerHTML = "";
    const seasons = (currentGroup && currentGroup.seasonsList) || [];
    const s = seasons.find(x => x.number === seasonNum);
    if (!s || !s.episodes.length) { epBox.innerHTML = '<div style="padding:10px;color:var(--muted)">No episodes</div>'; return; }
    s.episodes.forEach(ep => {
        const b = document.createElement("button");
        b.className = "episode-btn" + (currentEp && ep.episode === currentEp.episode ? " active" : "");
        b.textContent = ep.episode;
        b.onclick = () => playEpisode(ep);
        epBox.appendChild(b);
    });
}
async function playEpisode(item) {
    currentEpisode = item;
    $("vTitle").textContent = item.title + " - S" + item.season + "E" + item.episode;
    $("episodeLabel").textContent = "S" + item.season + " E" + item.episode;
    renderEpisodes(item.season, item);
    document.querySelectorAll("#seasonButtons .season-btn").forEach((b, i) => {
        b.classList.toggle("active", (currentGroup.seasonsList[i] && currentGroup.seasonsList[i].number === item.season));
    });
    if (item.vk) loadComments(item.vk);
    pushRecent(currentGroup || item, item);
    showPlayerError("");
    $("vbox").classList.add("loading");
    // reset quality menu
    $("qOpts").innerHTML = "";
    $("sOpts").innerHTML = "";

    // Get stream URLs
    let streams = [], defaultQ = "";
    try {
        if (item.as && item.as.u) {
            const r = await fetch("?action=as_play&url=" + encodeURIComponent(item.as.u), { cache: 'no-store' });
            const j = await r.json();
            if (j.ok && j.url) {
                streams = [{ quality: "Auto", url: j.url, type: "hls" }];
                defaultQ = "Auto";
            } else {
                showPlayerError(j.error || "Stream not available.");
                return;
            }
        } else if (item.blk) {
            const q = new URLSearchParams();
            q.set("tmdbId", item.blk.t);
            if (item.blk.u) q.set("uniqueId", item.blk.u);
            const r = await fetch("?action=stream&" + q.toString(), { cache: 'no-store' });
            const j = await r.json();
            if (j.ok && j.streams && j.streams.length) {
                streams = j.streams;
                defaultQ = j.default || streams[streams.length - 1].quality;
            } else {
                // fallback: use direct mp4 qualities
                const qs = item.qualities || {};
                const keys = Object.keys(qs);
                if (keys.length) {
                    streams = keys.map(k => ({ quality: k, url: qs[k], type: "mp4" }));
                    defaultQ = keys[keys.length - 1];
                } else {
                    showPlayerError("Stream not found.");
                    return;
                }
            }
        } else {
            const qs = item.qualities || {};
            const keys = Object.keys(qs);
            if (keys.length) {
                streams = keys.map(k => ({ quality: k, url: qs[k], type: "mp4" }));
                defaultQ = keys[keys.length - 1];
            } else if (item.mp4Url) {
                streams = [{ quality: "Default", url: item.mp4Url, type: "mp4" }];
                defaultQ = "Default";
            } else {
                showPlayerError("No source available.");
                return;
            }
        }
    } catch (e) {
        showPlayerError("Network error.");
        return;
    }
    currentStreams = streams;
    buildQualityMenu(streams, defaultQ);
    buildSpeedMenu();
    const pick = streams.find(x => x.quality === defaultQ) || streams[streams.length - 1];
    playVideoUrl(pick.url, pick.type, defaultQ);
}

/* ============ VIDEO PLAYER ============ */
function playVideoUrl(url, type, quality) {
    const v = $("video");
    $("vbox").classList.add("loading");
    if (hlsInstance) { try { hlsInstance.destroy(); } catch (e) {} hlsInstance = null; }
    v.pause();
    v.removeAttribute("src");
    v.load();
    currentQuality = quality || "";
    $("video").dataset.type = type || "mp4";

    if (type === "hls") {
        if (window.Hls && Hls.isSupported()) {
            hlsInstance = new Hls({ maxBufferLength: 30 });
            hlsInstance.loadSource(url);
            hlsInstance.attachMedia(v);
            hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => {
                $("vbox").classList.remove("loading");
                v.play().catch(() => {});
            });
            hlsInstance.on(Hls.Events.ERROR, (e, d) => {
                if (d.fatal) {
                    $("vbox").classList.remove("loading");
                    showPlayerError("Playback error. Try another quality.");
                }
            });
        } else if (v.canPlayType("application/vnd.apple.mpegurl")) {
            v.src = url;
            v.addEventListener("loadedmetadata", () => { $("vbox").classList.remove("loading"); v.play().catch(() => {}); }, { once: true });
            v.addEventListener("error", () => showPlayerError("Playback error."), { once: true });
        } else {
            v.src = url;
            v.play().catch(() => {});
        }
    } else {
        v.src = url;
        v.addEventListener("loadedmetadata", () => { $("vbox").classList.remove("loading"); }, { once: true });
        v.addEventListener("error", () => showPlayerError("Playback error."), { once: true });
        v.play().catch(() => {});
    }
}
function showPlayerError(msg) {
    const e = $("verr");
    if (!e) return;
    if (!msg) { e.hidden = true; e.textContent = ""; $("vbox").classList.remove("loading"); return; }
    e.textContent = msg;
    e.hidden = false;
    $("vbox").classList.remove("loading");
}
function buildQualityMenu(streams, active) {
    const box = $("qOpts");
    box.innerHTML = "";
    streams.forEach(s => {
        const b = document.createElement("button");
        b.className = "opt" + (s.quality === active ? " active" : "");
        b.textContent = s.quality;
        b.onclick = () => {
            box.querySelectorAll(".opt").forEach(x => x.classList.toggle("active", x === b));
            playVideoUrl(s.url, s.type, s.quality);
            closeMenu();
        };
        box.appendChild(b);
    });
}
function buildSpeedMenu() {
    const box = $("sOpts");
    box.innerHTML = "";
    [0.5, 0.75, 1, 1.25, 1.5, 2].forEach(sp => {
        const b = document.createElement("button");
        b.className = "opt" + (sp === 1 ? " active" : "");
        b.textContent = sp + "×";
        b.onclick = () => {
            $("video").playbackRate = sp;
            box.querySelectorAll(".opt").forEach(x => x.classList.toggle("active", x === b));
            closeMenu();
        };
        box.appendChild(b);
    });
}
function openMenu() { $("menu").classList.add("open"); }
function closeMenu() { $("menu").classList.remove("open"); }

function setupPlayer() {
    const v = $("video"), vbox = $("vbox");
    if (!v) return;
    const playBtn = $("playBtn"), big = $("big"), cur = $("cur"), tot = $("tot");
    const bar = $("bar"), buf = $("buf"), prog = $("prog");

    const updatePlayIcon = () => {
        const paused = v.paused;
        setIc($("playIc"), paused ? "i-play" : "i-pause");
        setIc($("bigIc"), paused ? "i-play" : "i-pause");
    };
    const togglePlay = () => { if (v.paused) v.play().catch(() => {}); else v.pause(); };

    playBtn.onclick = togglePlay;
    big.onclick = togglePlay;
    v.onclick = togglePlay;
    v.onplay = () => { updatePlayIcon(); vbox.classList.remove("loading"); };
    v.onpause = updatePlayIcon;
    v.onwaiting = () => vbox.classList.add("loading");
    v.onplaying = () => vbox.classList.remove("loading");
    v.onloadedmetadata = () => { tot.textContent = fmt(v.duration || 0); };
    v.ontimeupdate = () => {
        cur.textContent = fmt(v.currentTime || 0);
        const pct = (v.duration > 0 ? (v.currentTime / v.duration) * 100 : 0);
        bar.style.width = pct + "%";
        if (v.buffered.length) {
            try {
                const b = v.buffered.end(v.buffered.length - 1);
                buf.style.width = (b / v.duration) * 100 + "%";
            } catch (e) {}
        }
    };
    v.onvolumechange = () => {
        setIc($("muteIc"), v.muted || v.volume === 0 ? "i-mute" : "i-vol");
    };
    v.onerror = () => showPlayerError("Video error. Try another source.");
    $("muteBtn").onclick = () => { v.muted = !v.muted; };
    $("setBtn").onclick = () => { $("menu").classList.contains("open") ? closeMenu() : openMenu(); };
    $("fsBtn").onclick = () => {
        if (document.fullscreenElement) document.exitFullscreen();
        else if (vbox.requestFullscreen) vbox.requestFullscreen();
    };
    document.addEventListener("fullscreenchange", () => {
        setIc($("fsIc"), document.fullscreenElement ? "i-fsx" : "i-fs");
    });

    // Seek on progress bar
    let seeking = false;
    const seek = e => {
        if (!v.duration) return;
        const rect = prog.getBoundingClientRect();
        const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
        const pct = Math.max(0, Math.min(1, x / rect.width));
        v.currentTime = pct * v.duration;
    };
    prog.addEventListener("click", seek);
    prog.addEventListener("mousedown", () => seeking = true);
    prog.addEventListener("mousemove", e => { if (seeking) seek(e); });
    prog.addEventListener("mouseup", () => seeking = false);
    prog.addEventListener("touchstart", () => seeking = true, { passive: true });
    prog.addEventListener("touchmove", e => { if (seeking) { seek(e); e.preventDefault(); } }, { passive: false });
    prog.addEventListener("touchend", () => seeking = false);

    // Skip buttons
    $("rew").onclick = () => { v.currentTime = Math.max(0, v.currentTime - 10); };
    $("fwd").onclick = () => { v.currentTime = Math.min(v.duration || 0, v.currentTime + 10); };

    // Idle controls
    const showControls = () => {
        vbox.classList.remove("idle");
        clearTimeout(playerIdleTimer);
        playerIdleTimer = setTimeout(() => { if (!v.paused) vbox.classList.add("idle"); }, 2800);
    };
    vbox.addEventListener("mousemove", showControls);
    vbox.addEventListener("touchstart", showControls, { passive: true });
    vbox.addEventListener("click", showControls);
    updatePlayIcon();
}

/* ============ COMMENTS ============ */
async function loadComments(vk) {
    const list = $("cmList");
    if (!list || !vk) return;
    list.innerHTML = "";
    try {
        const r = await fetch("?action=cm&k=" + encodeURIComponent(vk), { cache: 'no-store' });
        const j = await r.json();
        if (!j.ok) return;
        $("cmCount").textContent = j.items.length;
        if (!j.items.length) { list.innerHTML = '<div class="cm-empty">Abhi koi comment nahi hai.</div>'; return; }
        j.items.forEach(c => {
            const el = document.createElement("div"); el.className = "cm-item";
            const top = document.createElement("div"); top.className = "cm-top";
            const b = document.createElement("b"); b.textContent = c.n || "User";
            const s = document.createElement("span"); s.textContent = timeAgo(c.ts);
            top.append(b, s);
            const p = document.createElement("p"); p.className = "cm-text"; p.textContent = c.t;
            el.append(top, p); list.appendChild(el);
        });
    } catch (e) {}
}
async function postComment() {
    const k = currentEpisode ? currentEpisode.vk : (currentGroup ? currentGroup.vk : "");
    if (!k) return;
    const n = $("cmName").value.trim();
    const t = $("cmText").value.trim();
    const err = $("cmErr");
    err.textContent = "";
    if (!n) { err.textContent = "Pehle apna naam likho."; return; }
    if (!t) { err.textContent = "Comment likho."; return; }
    const btn = $("cmPost");
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append("k", k); fd.append("n", n); fd.append("t", t);
        const r = await fetch("?action=cm_add", { method: "POST", body: fd });
        const j = await r.json();
        if (!j.ok) { err.textContent = j.error || "Comment post nahi hua."; return; }
        $("cmText").value = "";
        loadComments(k);
    } catch (e) {
        err.textContent = "Network error.";
    } finally { btn.disabled = false; }
}

/* ============ SEARCH (async + server merge) ============ */
let searchTimer = null;
async function doSearch(q) {
    q = String(q || '').trim();
    if (!q) { render(); return; }
    let local = searchGroups(q, groups).list;
    let server = [];
    try {
        const r = await fetch('?action=as_search&q=' + encodeURIComponent(q), { cache: 'no-store' });
        const j = await r.json();
        if (j && j.ok && Array.isArray(j.items)) server = j.items;
    } catch (e) {}
    if (server.length) {
        const have = new Set(multiList.map(x => x.link));
        let added = 0;
        server.forEach(m => { if (!have.has(m.link)) { multiList.push(m); have.add(m.link); added++; } });
        if (added) rebuildAll();
    }
    let merged = searchGroups(q, groups).list;
    const seen = new Set(); const final = [];
    [...merged, ...local].forEach(g => {
        const k = g.type + '|' + g.title.toLowerCase();
        if (seen.has(k)) return;
        seen.add(k); final.push(g);
    });
    renderSearchResults(final, q);
}
function renderSearchResults(list, q) {
    const box = $("searchResults"), cnt = $("searchCount");
    if (!box) return;
    box.innerHTML = "";
    if (cnt) cnt.textContent = list.length + " titles";
    if (!list.length) {
        const d = document.createElement("div"); d.className = "empty";
        d.textContent = `"${q}" ke liye koi result nahi mila.`;
        box.appendChild(d); return;
    }
    list.forEach(g => { const c = makeCard(g); if (c) box.appendChild(c); });
}

/* ============ CLOSE WATCH ============ */
function closeWatch() {
    watchOpen = false;
    $("watch").style.display = "none";
    document.body.style.overflow = "";
    const v = $("video");
    if (v) { v.pause(); v.removeAttribute("src"); v.load(); }
    if (hlsInstance) { try { hlsInstance.destroy(); } catch (e) {} hlsInstance = null; }
    currentGroup = null; currentEpisode = null;
    render();
}

/* ============ INIT ============ */
(function boot() {
    // Menu
    $("menuBtn").onclick = openMenuUI;
    $("closeMenuBtn").onclick = closeMenuUI;
    $("menuOverlay").onclick = closeMenuUI;

    // Filter buttons
    document.querySelectorAll('.filter').forEach(btn => {
        btn.addEventListener('click', () => {
            currentFilter = btn.dataset.filter || 'all';
            document.querySelectorAll('.filter').forEach(b => b.classList.toggle('active', b.dataset.filter === currentFilter));
            if (currentView === 'hentai' && currentFilter !== 'Hentai') { setView('home'); return; }
            render();
        });
    });

    // Search (header)
    const inp = $("search");
    if (inp) {
        inp.addEventListener("input", e => {
            clearTimeout(searchTimer);
            const q = e.target.value;
            searchTimer = setTimeout(() => {
                if (q.trim()) {
                    if ($("searchPageInput")) $("searchPageInput").value = q;
                    setView('search'); doSearch(q);
                } else {
                    applyView(currentView === 'search' ? 'home' : currentView);
                }
            }, 380);
        });
        inp.addEventListener("keydown", e => {
            if (e.key === "Enter") {
                e.preventDefault(); clearTimeout(searchTimer);
                const q = e.target.value.trim();
                if (q) { setView('search'); doSearch(q); }
            }
        });
    }
    const sBtn = $("sBtn");
    if (sBtn) sBtn.onclick = () => {
        const header = document.querySelector("header");
        if (header) header.classList.toggle("s-open");
        if (inp) inp.focus();
    };

    // Search (search page)
    const inp2 = $("searchPageInput");
    if (inp2) {
        inp2.addEventListener("input", e => {
            clearTimeout(searchTimer);
            const q = e.target.value;
            searchTimer = setTimeout(() => {
                if (q.trim()) doSearch(q);
                else { $("searchResults").innerHTML = ""; if ($("searchCount")) $("searchCount").textContent = "0 titles"; }
            }, 380);
        });
        inp2.addEventListener("keydown", e => {
            if (e.key === "Enter") { e.preventDefault(); clearTimeout(searchTimer); const q = e.target.value.trim(); if (q) doSearch(q); }
        });
    }

    // Info play button
    $("infoPlay").onclick = () => {
        if (currentEpisode) playEpisode(currentEpisode);
    };

    // Comments post
    $("cmPost").onclick = postComment;

    // Back button on watch
    $("backBtn").onclick = closeWatch;

    // Player
    setupPlayer();

    // Popstate
    window.addEventListener("popstate", e => {
        if (watchOpen) { closeWatch(); return; }
        const v = (e.state && e.state.view) || 'home';
        applyView(v);
    });

    // ESC closes watch / menu
    document.addEventListener("keydown", e => {
        if (e.key === "Escape") {
            if ($("menu").classList.contains("open")) { closeMenu(); return; }
            if (watchOpen) closeWatch();
        }
    });

    // Boot
    ensureMultiLoaded();
    applyView('home');
    window.scrollTo(0, 0);
})();
</script>
</body>
</html>
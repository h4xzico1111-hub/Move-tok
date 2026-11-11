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
  
/* ============ ENGINE FROM 1.php (blakiteapi) — no cache folder ============ */
@ini_set('max_execution_time', 120);
@ini_set('memory_limit', '256M');
$BK_API   = 'https://blakiteapi.xyz/api/getAllAnime.php';
$BK_EMBED = 'https://blakiteapi.xyz';
$BK_FILE  = sys_get_temp_dir() . '/blk.catalog.json';   // single temp file, koi folder nahi banta
$BK_TTL   = 120;   // blakite par naya upload itne second ke andar site par aa jata hai

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

// 1.php ka item() logic -> index.php ke catalog format me
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
    if (stripos($cat, 'hentai') !== false) return null;   // hentai sirf aapke upload se
    $isMovie = strtolower($cat) === 'movies' || strtolower($type) === 'movie';   // movies me sirf movies

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

// poora JSON ek saath decode nahi hota (phone par memory khatam ho jati hai): ek-ek item padhkar callback ko deta hai
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
            blk_scan($raw, function($id, $src, $cat) use (&$list) {
                $it = blk_item($id, $src, $cat);
                if ($it) $list[] = $it;
            });
        }
        unset($raw);
        if ($list) {
            @file_put_contents($BK_FILE, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
        } elseif (is_file($BK_FILE)) {
            $list = json_decode((string)@file_get_contents($BK_FILE), true);
            @touch($BK_FILE);   // API down ho to har request par dobara na atke
        } else {
            $list = null;
        }
    }
    if (!is_array($list)) return [];
    // jo title pehle se aapke upload (GitHub) me hai wo dobara nahi judega
    $have = [];
    foreach ($existing as $a) {
        if (!is_array($a) || empty($a['title'])) continue;
        $k = ($a['category'] ?? '') === 'movie' ? 'movie' : ((($a['category'] ?? '') === 'hentai') ? 'hentai' : 'anime');
        $have[$k . '|' . strtolower(trim((string)$a['title']))] = 1;
    }
    return array_values(array_filter($list, fn($x) => empty($have[$x['category'] . '|' . strtolower(trim((string)$x['title']))])));
}

/* ---------- LIKE / DISLIKE (single temp file, koi folder nahi banta) ---------- */
$VOTE_FILE = sys_get_temp_dir() . '/mt.votes.json';
$RD_URL = getenv('UPSTASH_REDIS_REST_URL') ?: (getenv('KV_REST_API_URL') ?: '');
$RD_TOK = getenv('UPSTASH_REDIS_REST_TOKEN') ?: (getenv('KV_REST_API_TOKEN') ?: '');
function rd($cmds) {
    global $RD_URL, $RD_TOK;
    if (!$RD_URL || !$RD_TOK) return null;
    $ch = curl_init(rtrim($RD_URL, '/') . '/pipeline');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $RD_TOK, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($cmds)]);
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = $out ? json_decode($out, true) : null;
    return ($code === 200 && is_array($j)) ? array_map(fn($x) => $x['result'] ?? null, $j) : null;
}
function vote_key($k) { return substr(md5(strtolower(trim((string)$k))), 0, 16); }
function votes_load() {
    global $VOTE_FILE;
    $res = rd([['HGETALL', 'mt:votes']]);
    if ($res && is_array($res[0] ?? null)) {
        $flat = $res[0]; $out = [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            [$vk, $f] = array_pad(explode(':', (string)$flat[$i], 2), 2, '');
            if ($f === 'l' || $f === 'd') $out[$vk][$f] = max(0, (int)$flat[$i + 1]);
        }
        foreach ($out as $vk => $e) $out[$vk] = ['l' => $e['l'] ?? 0, 'd' => $e['d'] ?? 0];
        return $out;
    }
    $d = is_file($VOTE_FILE) ? json_decode((string)@file_get_contents($VOTE_FILE), true) : null;
    return is_array($d) ? $d : [];
}
$__act0 = $_GET['action'] ?? '';
if ($__act0 === 'vote' || $__act0 === 'votes') {
    header('Content-Type: application/json; charset=utf-8');
    $k = vote_key($_REQUEST['k'] ?? '');
    if ($RD_URL && $RD_TOK) {
        $cmds = [];
        if ($__act0 === 'vote' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $okv = ['like', 'dislike', 'none'];
            $v = in_array($_POST['v'] ?? '', $okv, true) ? $_POST['v'] : 'none';
            $p = in_array($_POST['p'] ?? '', $okv, true) ? $_POST['p'] : 'none';
            if ($p === 'like')    $cmds[] = ['HINCRBY', 'mt:votes', $k . ':l', -1];
            if ($p === 'dislike') $cmds[] = ['HINCRBY', 'mt:votes', $k . ':d', -1];
            if ($v === 'like')    $cmds[] = ['HINCRBY', 'mt:votes', $k . ':l', 1];
            if ($v === 'dislike') $cmds[] = ['HINCRBY', 'mt:votes', $k . ':d', 1];
        }
        $cmds[] = ['HMGET', 'mt:votes', $k . ':l', $k . ':d'];
        $res = rd($cmds);
        if ($res) { $g = end($res); echo jEnc(['ok' => true, 'l' => max(0, (int)($g[0] ?? 0)), 'd' => max(0, (int)($g[1] ?? 0))]); exit; }
    }
    $fh = @fopen($VOTE_FILE, 'c+');
    if (!$fh) { echo jEnc(['ok' => false]); exit; }
    flock($fh, LOCK_EX);
    $all = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($all)) $all = [];
    $e = $all[$k] ?? ['l' => 0, 'd' => 0];
    if ($__act0 === 'vote' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $okv = ['like', 'dislike', 'none'];
        $v = in_array($_POST['v'] ?? '', $okv, true) ? $_POST['v'] : 'none';
        $p = in_array($_POST['p'] ?? '', $okv, true) ? $_POST['p'] : 'none';
        if ($p === 'like')    $e['l'] = max(0, (int)$e['l'] - 1);
        if ($p === 'dislike') $e['d'] = max(0, (int)$e['d'] - 1);
        if ($v === 'like')    $e['l'] = (int)$e['l'] + 1;
        if ($v === 'dislike') $e['d'] = (int)$e['d'] + 1;
        $all[$k] = $e;
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($all));
        fflush($fh);
    }
    flock($fh, LOCK_UN); fclose($fh);
    echo jEnc(['ok' => true, 'l' => (int)$e['l'], 'd' => (int)$e['d']]);
    exit;
}

// ?action=stream  /  ?action=proxy  (1.php ka stream + proxy engine)
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
/* ============ ENGINE END ============ */
  
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
    $__v = [];
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
$SITE_IMG = 'https://i.ibb.co/QvS9K1F2/file-0000000088fc81f4b523066b82b7881f.png';
$h = fn($x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
?>  <!DOCTYPE html>  <html lang="en">  
<head>  
<meta charset="UTF-8">  
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">  
<meta name="theme-color" content="#06060c">  
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
:root{--bg:#06060c;--text:#fff;--muted:#fff;--red:#ff3b4a;--pink:#ff3b4a;--violet:#8a5cff;--stroke:rgba(255,255,255,.13);--glass:linear-gradient(135deg,rgba(255,255,255,.11),rgba(255,255,255,.04));--shadow:0 10px 34px rgba(0,0,0,.45),inset 0 1px 0 rgba(255,255,255,.10);--grad:linear-gradient(135deg,#ff3b4a,#ff4f93 55%,#8a5cff);--blur:blur(18px) saturate(150%);--head:56px}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{color-scheme:dark}
body{margin:0;background:var(--bg);color:var(--text);font-family:Nunito,Arial,sans-serif;min-height:100vh;-webkit-font-smoothing:antialiased}
body::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(60% 42% at 8% -4%,rgba(255,59,74,.30),transparent 70%),radial-gradient(48% 40% at 104% 18%,rgba(138,92,255,.28),transparent 70%),radial-gradient(70% 50% at 50% 108%,rgba(0,150,255,.16),transparent 70%)}
button,input{font:inherit}
button{cursor:pointer;touch-action:manipulation;color:inherit;background:none;border:0;padding:0}
[hidden]{display:none!important}
.ic{width:1em;height:1em;fill:currentColor;display:block;pointer-events:none}
::-webkit-scrollbar{width:8px;height:8px}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.18);border-radius:8px}
.hero-track::-webkit-scrollbar,.filters::-webkit-scrollbar,.seasons::-webkit-scrollbar,.hrow::-webkit-scrollbar{display:none}

/* ---------- header (glass bar) ---------- */
header{position:sticky;top:calc(8px + env(safe-area-inset-top));z-index:10;display:flex;align-items:center;gap:10px;margin:calc(8px + env(safe-area-inset-top)) auto 0;width:calc(100% - 24px);max-width:1176px;padding:8px 12px;border-radius:22px;background:var(--glass);border:1px solid var(--stroke);-webkit-backdrop-filter:var(--blur);backdrop-filter:var(--blur);box-shadow:var(--shadow)}
.logo{width:44px;height:44px;object-fit:cover;border-radius:50%;border:2px solid rgba(255,59,74,.6);background:#000;flex:0 0 auto;box-shadow:0 0 18px rgba(255,59,74,.35)}
.brand{font-size:23px;font-weight:900;white-space:nowrap;letter-spacing:-.4px;color:#fff}
.sbox{display:flex;align-items:center;margin-left:auto;gap:6px;padding:2px;border-radius:50px;background:rgba(255,255,255,.07);border:1px solid var(--stroke)}
.sbtn{display:flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;color:#fff}
.sbtn svg{width:21px;height:21px;fill:none}
.search{display:none;margin:0;width:0;flex:1;min-width:0;border:0;background:transparent;color:#fff;padding:8px 6px;font-size:15px;outline:none}
.search::placeholder{color:#fff;opacity:1}
header.s-open .brand,header.s-open .logo{display:none}
header.s-open .sbox{flex:1;margin-left:0}
header.s-open .search{display:block;width:100%}
@media(min-width:900px){
  .sbox{display:contents}
  .sbtn{display:none}
  .search{display:block;margin-left:auto;flex:1;width:100%;max-width:340px;border:1px solid var(--stroke);border-radius:14px;background:rgba(255,255,255,.06);padding:11px 14px;font-size:14px;transition:border-color .2s,box-shadow .2s}
  .search:focus{border-color:var(--red);box-shadow:0 0 0 3px rgba(255,59,74,.18)}
}

main{max-width:1200px;margin:auto;padding:6px 14px 40px}
h2{display:flex;align-items:center;gap:10px;font-size:20px;font-weight:900;color:#fff;margin:22px 0 12px;letter-spacing:-.2px}
h2::before{content:"";display:inline-block;width:4px;height:22px;border-radius:4px;background:var(--grad);box-shadow:0 0 14px rgba(255,59,74,.6)}
h2 svg{width:20px;height:20px;fill:var(--red)}
.row-head{display:flex;align-items:center;justify-content:space-between}
.row-head h2{margin:22px 0 12px}

/* ---------- hero ---------- */
.hero{position:relative;margin:14px 0 0;border-radius:26px;overflow:hidden;background:var(--glass);border:1px solid var(--stroke);box-shadow:var(--shadow)}
.hero-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.hero-slide{flex:0 0 100%;scroll-snap-align:start;position:relative;height:min(64vh,520px);overflow:hidden}
.hero-media{position:absolute;inset:0;background:#0b0b14;overflow:hidden}
.hero-media .bg{display:block;position:absolute;inset:-24px;width:calc(100% + 48px);height:calc(100% + 48px);object-fit:cover;filter:blur(30px) brightness(.5) saturate(1.3)}
.hero-media .fg{display:block;position:absolute;inset:0;width:100%;height:100%;object-fit:contain;object-position:center 30%}
.hero-media::after{content:"";position:absolute;inset:auto 0 0;height:65%;background:linear-gradient(to top,rgba(6,6,12,.96) 5%,rgba(6,6,12,.6) 45%,transparent);pointer-events:none}
.hero-body{position:absolute;left:0;right:0;bottom:0;padding:0 16px 16px;z-index:2;display:flex;flex-direction:column;align-items:center;text-align:center}
.hero-kind{display:flex;flex-wrap:wrap;justify-content:center;gap:8px}
.hero-kind i{display:none}
.hero-kind span{padding:5px 12px;border-radius:50px;font-size:12px;font-weight:800;color:#fff;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.hero-kind span:first-child{background:rgba(255,59,74,.32);border-color:rgba(255,99,115,.6)}
.hero-title{margin:10px 0 14px;font-size:clamp(22px,6vw,34px);font-weight:900;line-height:1.2;color:#fff;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;text-shadow:0 2px 14px rgba(0,0,0,.8)}
.hero-actions{display:flex;gap:10px;width:100%;max-width:380px}
.hbtn{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 18px;border-radius:50px;font-weight:800;font-size:15px;white-space:nowrap;color:#fff;border:1px solid transparent;-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);transition:transform .15s,box-shadow .2s}
.hbtn .ic{font-size:20px}
.hbtn.play{background:var(--grad);border-color:rgba(255,255,255,.28);box-shadow:0 8px 24px rgba(255,59,74,.4)}
.hbtn.info{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.28)}
.hbtn:active{transform:scale(.95)}
.dots{display:flex;justify-content:center;gap:6px;padding:12px 0;background:rgba(0,0,0,.25)}
.dot{width:6px;height:6px;padding:0;border:0;border-radius:3px;background:rgba(255,255,255,.3);transition:all .25s}
.dot.active{width:22px;background:var(--grad)}
.hero-nav{display:none;position:absolute;top:50%;margin-top:-40px;z-index:3;width:44px;height:72px;color:#fff;font-size:28px;align-items:center;justify-content:center;border-radius:14px;background:rgba(255,255,255,.1);border:1px solid var(--stroke);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.hero-nav.prev{left:12px}.hero-nav.next{right:12px}

/* ---------- filters ---------- */
.filters{display:flex;gap:10px;overflow-x:auto;padding:4px 2px 16px;scrollbar-width:none}
.filter,.season-btn{flex:0 0 auto;padding:10px 18px;border-radius:30px;background:rgba(255,255,255,.07);border:1px solid var(--stroke);color:#fff;font-weight:800;font-size:13px;transition:all .2s;-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.filter.active,.season-btn.active,.episode-btn.active{background:var(--grad);color:#fff;border-color:rgba(255,255,255,.35);box-shadow:0 6px 20px rgba(255,59,74,.35)}

/* ---------- rows + grid ---------- */
.hrow{display:flex;gap:14px;overflow-x:auto;padding:2px 2px 10px;scrollbar-width:none;scroll-snap-type:x proximity}
.rcard{flex:0 0 140px;scroll-snap-align:start;cursor:pointer;transition:transform .2s}
.rcard .card-title{font-size:13.5px;margin-top:8px;padding:0 4px}
.rcard .badge{font-size:11px;padding:5px 10px;bottom:8px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px 14px}
.card{min-width:0;cursor:pointer;background:none;border:0;transition:transform .22s}
.card:active,.rcard:active{transform:scale(.97)}
.poster{position:relative;aspect-ratio:3/4;border-radius:22px;background:#12121c;overflow:hidden;border:1px solid var(--stroke);box-shadow:0 12px 30px rgba(0,0,0,.5),inset 0 1px 0 rgba(255,255,255,.12)}
.poster .bg{display:none}
.poster .fg{display:block;width:100%;height:100%;object-fit:cover}
.poster::after{content:"";position:absolute;inset:auto 0 0;height:45%;background:linear-gradient(to top,rgba(6,6,12,.85),transparent);pointer-events:none}
.badge{position:absolute;left:50%;bottom:10px;transform:translateX(-50%);z-index:2;max-width:90%;padding:6px 12px;border-radius:20px;background:rgba(14,14,24,.6);border:1px solid rgba(255,255,255,.2);color:#fff;font-size:12px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.new-tag{position:absolute;top:8px;left:8px;z-index:3;background:var(--grad);color:#fff;font-size:10px;font-weight:900;padding:3px 9px;border-radius:10px;letter-spacing:.4px;box-shadow:0 4px 12px rgba(255,59,74,.45)}
.pbar{position:absolute;left:0;right:0;bottom:0;height:4px;background:rgba(255,255,255,.25);z-index:3}
.pbar i{display:block;height:100%;background:var(--grad)}
.card-info{padding:10px 4px 0}
.card-title{font-size:15px;font-weight:800;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}
.card-sub{margin-top:4px;color:var(--muted);font-size:11.5px;font-weight:600}
.card-info .card-sub{display:none}
.empty{grid-column:1/-1;padding:34px 10px;text-align:center;color:var(--muted)}

/* ---------- watch page ---------- */
.watch{display:none;position:fixed;inset:0;z-index:100;overflow-y:auto;background:radial-gradient(60% 40% at 8% 0%,rgba(255,59,74,.24),transparent 70%),radial-gradient(50% 40% at 100% 22%,rgba(138,92,255,.22),transparent 70%),var(--bg)}
.watch-head{position:sticky;top:0;z-index:7;height:var(--head);display:flex;align-items:center;gap:6px;padding:0 12px 0 6px;background:rgba(10,10,18,.6);-webkit-backdrop-filter:var(--blur);backdrop-filter:var(--blur);border-bottom:1px solid var(--stroke)}
.watch-head .back{width:42px;height:42px;border-radius:50%;color:#fff;font-size:24px;display:flex;align-items:center;justify-content:center}
.watch-head .back:active{background:rgba(255,255,255,.14)}
.watch-head span{font-weight:800;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.video-box{position:sticky;top:var(--head);z-index:5;width:100%;aspect-ratio:16/9;max-height:70vh;background:#000;overflow:hidden;user-select:none;-webkit-user-select:none;margin:0 auto}
.video-box:fullscreen{max-height:none;max-width:none;aspect-ratio:auto;height:100%;margin:0;border-radius:0}
.video-box:-webkit-full-screen{max-height:none;max-width:none;aspect-ratio:auto;height:100%;margin:0;border-radius:0}
.video-box.idle{cursor:none}
video{width:100%;height:100%;object-fit:contain;background:#000;display:block}
.bottom{position:absolute;left:0;right:0;bottom:0;z-index:3;padding:22px 12px 8px;background:linear-gradient(transparent,rgba(0,0,0,.88));transition:opacity .25s}
.center{position:absolute;inset:0;z-index:2;display:flex;align-items:center;justify-content:center;gap:clamp(18px,8vw,60px);pointer-events:none;transition:opacity .25s}
.center button{pointer-events:auto;color:#fff;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-radius:50%;width:46px;height:46px;font-size:28px;display:flex;align-items:center;justify-content:center;-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px)}
.center #big{width:64px;height:64px;font-size:44px;background:rgba(255,255,255,.16)}
.center .ic{filter:drop-shadow(0 2px 6px rgba(0,0,0,.8))}
.idle .bottom,.idle .center{opacity:0;pointer-events:none}.idle .center button{pointer-events:none}
.loading #big{opacity:0}
.loader{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2;display:none;flex-direction:column;align-items:center;gap:8px;pointer-events:none;width:100%;padding:0 12px}
.loading .loader{display:flex}
.wave{display:flex;align-items:center;justify-content:center;gap:3px;height:24px}
.wave i{display:block;width:4px;height:16px;border-radius:3px;background:#ff3b4a;box-shadow:0 0 6px rgba(255,59,74,.9),0 0 14px rgba(255,59,74,.45);transform:scaleY(.25);animation:wv 1.1s ease-in-out infinite}
@keyframes wv{0%,100%{transform:scaleY(.25);opacity:.5}50%{transform:scaleY(1);opacity:1}}
.lt{font-size:11px;font-weight:700;color:#fff;text-align:center;text-shadow:0 1px 6px rgba(0,0,0,.9);white-space:nowrap}
.prog{position:relative;height:22px;display:flex;align-items:center;cursor:pointer;touch-action:none}
.track{position:relative;width:100%;height:4px;border-radius:2px;background:rgba(255,255,255,.25);transition:height .15s}
.prog:hover .track{height:6px}
.buf,.bar{position:absolute;left:0;top:0;height:100%;border-radius:2px;width:0}
.buf{background:rgba(255,255,255,.35)}.bar{background:var(--grad)}
.bar::after{content:"";position:absolute;right:-7px;top:50%;width:14px;height:14px;margin-top:-7px;background:#fff;border-radius:50%;box-shadow:0 0 8px rgba(255,59,74,.8)}
.ctrls{display:flex;align-items:center;gap:6px;color:#fff}
.ctrl-btn{color:#fff;font-size:26px;min-width:40px;height:40px;display:flex;align-items:center;justify-content:center;border-radius:10px}
.ctrl-btn:active{background:rgba(255,255,255,.15)}
.time{font-size:12px;font-weight:700;color:#fff;white-space:nowrap}.sp{flex:1}
.menu{display:none;position:absolute;right:10px;bottom:62px;z-index:6;width:200px;max-height:75%;overflow-y:auto;padding:8px;border:1px solid var(--stroke);border-radius:16px;background:rgba(18,18,30,.72);-webkit-backdrop-filter:var(--blur);backdrop-filter:var(--blur);box-shadow:0 14px 34px rgba(0,0,0,.6)}
.menu.open{display:block}
.menu strong{display:block;padding:6px 10px 2px;font-size:11px;color:#fff;letter-spacing:.5px;text-transform:uppercase}
.opts{display:flex;flex-wrap:wrap;gap:4px;padding:4px}
.opt{padding:7px 11px;border-radius:9px;background:rgba(255,255,255,.08);color:#fff;font-size:12.5px;font-weight:700;border:1px solid transparent}
.opt.active{background:rgba(255,59,74,.22);color:#fff;border-color:var(--red)}
.verr{position:absolute;inset:0;z-index:4;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px;background:rgba(0,0,0,.85);color:#fff;font-weight:700}

.details{position:relative;padding:20px 16px 44px;overflow:hidden;min-height:60vh}
.details::before{content:"";position:absolute;inset:-40px;background-image:var(--bg-image);background-size:cover;background-position:center;filter:blur(60px) saturate(1.4);opacity:.3;pointer-events:none}
.details-content{position:relative;max-width:1000px;margin:0 auto}
.anime-info{display:flex;flex-direction:row;align-items:flex-start;gap:15px;margin-bottom:22px;padding:14px;border-radius:24px;background:var(--glass);border:1px solid var(--stroke);-webkit-backdrop-filter:var(--blur);backdrop-filter:var(--blur);box-shadow:var(--shadow)}
.detail-poster{flex:0 0 auto;width:110px;max-width:110px;height:auto;aspect-ratio:3/4;object-fit:cover;border-radius:16px;border:1px solid var(--stroke);background:#1a1a26;box-shadow:0 8px 25px rgba(0,0,0,.5)}
.detail-text{flex:1;min-width:0}
.detail-title{font-size:clamp(18px,5vw,28px);line-height:1.25;overflow-wrap:anywhere;color:#fff;margin:0 0 12px}
.tag{display:inline-block;padding:6px 12px;border-radius:25px;background:rgba(255,59,74,.13);border:1px solid rgba(255,99,115,.4);color:#fff;font-size:12px;font-weight:800;margin:0 5px 8px 0}
#episodeSection,#seasonSection{padding:0 14px 14px;margin-bottom:14px;border-radius:22px;background:var(--glass);border:1px solid var(--stroke)}
.seasons{display:flex;gap:10px;overflow-x:auto;padding:4px 2px 4px;scrollbar-width:none}
.season-btn{min-width:118px;padding:11px 16px}
.episodes{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.episode-btn{min-height:56px;border:1px solid var(--stroke);border-radius:14px;background:rgba(255,255,255,.07);color:#fff;font-size:18px;font-weight:800;transition:all .2s}
#infoPlay{display:none;flex:none;margin-top:6px;padding:10px 20px}
.info-mode #vbox{display:none}
.info-mode #infoPlay{display:flex}

@media(min-width:650px){
  .hero-nav{display:flex}
  .grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:24px 18px}
  .rcard{flex-basis:180px}
  .card:hover,.rcard:hover{transform:translateY(-4px)}
  .card:hover .poster,.rcard:hover .poster{border-color:rgba(255,99,115,.5);box-shadow:0 16px 38px rgba(255,59,74,.22),inset 0 1px 0 rgba(255,255,255,.14)}
  .episodes{grid-template-columns:repeat(8,minmax(0,1fr))}
  .detail-poster{width:150px;max-width:150px}
  .detail-title{font-size:clamp(22px,3vw,32px)}
  .video-box{max-width:1000px;margin-top:14px;border-radius:20px;max-height:calc(100vh - 120px);border:1px solid var(--stroke);box-shadow:0 20px 50px rgba(0,0,0,.6)}
}
@media(min-width:900px){
  .hero-slide{height:clamp(300px,34vw,420px)}
  .hero-media .fg{object-position:88% center}
  .hero-media::after{height:100%;background:linear-gradient(to right,rgba(6,6,12,.94) 8%,rgba(6,6,12,.55) 45%,transparent 75%),linear-gradient(to top,rgba(6,6,12,.8),transparent 55%)}
  .hero-body{align-items:flex-start;text-align:left;width:58%;padding:0 40px 34px}
  .hero-kind{justify-content:flex-start}
  .hero-title{font-size:clamp(24px,3vw,38px)}
  .hero-actions{max-width:380px}
}
@media(max-width:360px){.brand{font-size:19px}.logo{width:38px;height:38px}.episodes{gap:7px}.episode-btn{min-height:48px;font-size:16px}.ctrl-btn{min-width:34px}.detail-poster{width:96px;max-width:96px}}

/* ---------- extra: player fixes ---------- */
.loading .center button{opacity:0;pointer-events:none}
.lt{max-width:100%;overflow:hidden;text-overflow:ellipsis}
/* ---------- extra: filter bar under search ---------- */
.fbar{max-width:1176px;margin:10px auto 0;padding:0 12px;display:flex;flex-direction:column;gap:8px}
.fbar .filters{padding:2px 2px 4px}
@media(min-width:650px){.fbar{flex-direction:row;align-items:center}.fbar .filters{flex:1;min-width:0}}
/* ---------- extra: top rated slider ---------- */
.toptrack{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;padding:2px 2px 10px;-webkit-overflow-scrolling:touch}
.toptrack::-webkit-scrollbar{display:none}
.tslide{flex:0 0 86%;max-width:560px;scroll-snap-align:center;position:relative;height:min(58vh,440px);border-radius:26px;overflow:hidden;background:var(--glass);border:1px solid var(--stroke);box-shadow:var(--shadow);cursor:pointer}
.trank{position:absolute;top:12px;left:12px;z-index:3;background:var(--grad);color:#fff;font-weight:900;font-size:14px;padding:5px 13px;border-radius:14px;box-shadow:0 4px 12px rgba(255,59,74,.45)}
.tbody{position:absolute;left:0;right:0;bottom:0;z-index:2;padding:0 16px 16px;display:flex;flex-direction:column;align-items:flex-start;gap:8px}
.tbody .hero-title{margin:0}
.tmeta{display:flex;flex-wrap:wrap;gap:8px}
.tmeta span{padding:4px 11px;border-radius:50px;font-size:12px;font-weight:800;color:#fff;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2)}
.tbody .hbtn{flex:none;padding:10px 22px}
@media(min-width:900px){.tslide{flex-basis:560px;height:380px}}
/* ---------- extra: like / dislike ---------- */
.vote{display:flex;gap:10px;margin:2px 0 8px}
.vbtn{display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:30px;background:rgba(255,255,255,.08);border:1px solid var(--stroke);color:#fff;font-weight:800;font-size:14px;transition:all .2s}
.vbtn .ic{font-size:20px}
.vbtn.dis .ic{transform:rotate(180deg)}
.vbtn.on{background:var(--grad);border-color:rgba(255,255,255,.35);box-shadow:0 6px 18px rgba(255,59,74,.35)}
.vbtn:active{transform:scale(.95)}
/* ---------- extra: footer + sr-only ---------- */
.foot{max-width:1176px;margin:10px auto 0;padding:18px 14px calc(26px + env(safe-area-inset-bottom));text-align:center;color:#fff;font-size:13px;font-weight:700;border-top:1px solid var(--stroke)}
.sr-only{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);border:0}
/* ---------- comments ---------- */
#commentSection{padding:0 14px 14px;margin-bottom:14px;border-radius:22px;background:var(--glass);border:1px solid var(--stroke)}
.cm-form{display:flex;flex-direction:column;gap:10px}
.cm-form input,.cm-form textarea{width:100%;padding:12px 14px;border-radius:14px;border:1px solid var(--stroke);background:rgba(255,255,255,.07);color:#fff;font-size:15px;font-weight:600;outline:none;resize:vertical}
.cm-form input::placeholder,.cm-form textarea::placeholder{color:#fff;opacity:1}
.cm-form input:focus,.cm-form textarea:focus{border-color:var(--red)}
.cm-form .hbtn{flex:none;align-self:flex-start;padding:10px 26px}
.cm-form .hbtn:disabled{opacity:.6}
.cm-err{color:#fff;font-size:13px;font-weight:700;min-height:0}
.cm-list{display:flex;flex-direction:column;gap:10px;margin-top:16px}
.cm-item{padding:12px 14px;border-radius:16px;background:rgba(255,255,255,.06);border:1px solid var(--stroke)}
.cm-top{display:flex;justify-content:space-between;gap:10px;align-items:baseline}
.cm-top b{font-size:14px;color:#fff;overflow-wrap:anywhere}
.cm-top span{font-size:11px;font-weight:700;color:#fff;white-space:nowrap}
.cm-text{margin:6px 0 0;font-size:14px;line-height:1.45;color:#fff;overflow-wrap:anywhere;white-space:pre-wrap}
.cm-empty{padding:10px 0;text-align:center;color:#fff;font-weight:700}
</style>  
</head>  
<body>  
<svg width="0" height="0" style="position:absolute" aria-hidden="true">  
  <symbol id="i-like" viewBox="0 0 24 24"><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></symbol>
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
</svg>  <header>  
  <img class="logo" src="https://i.ibb.co/QvS9K1F2/file-0000000088fc81f4b523066b82b7881f.png" alt="Move Tok">  
  <div class="brand">Move Tok</div>  
  <div class="sbox">  
    <button id="sBtn" class="sbtn" type="button" aria-label="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg></button>  
    <input id="search" class="search" type="search" placeholder="Search movies, dramas, anime...">  
  </div>  
</header>
<nav class="fbar" aria-label="Filter and sort">
  <div class="filters">  
    <button class="filter active" data-filter="all">All</button>  
    <button class="filter" data-filter="Anime">Dramas &amp; Anime</button>  
    <button class="filter" data-filter="Movie">Hindi Movies</button>  
    <button class="filter" data-filter="Hentai">Hindi Hentai</button>  
  </div>
</nav>  <main>
  <h1 class="sr-only">Move Tok - Watch Hindi Movies, K-Dramas &amp; Anime Online</h1>  
  <section id="featuredSection" hidden>  
    <div class="hero">  
      <button id="heroPrev" class="hero-nav prev" aria-label="Previous"><svg class="ic"><use href="#i-left"/></svg></button>  
      <div id="slider" class="hero-track"></div>  
      <button id="heroNext" class="hero-nav next" aria-label="Next"><svg class="ic"><use href="#i-right"/></svg></button>  
      <div id="dots" class="dots"></div>  
    </div>  
  </section>    <section id="recentSection" hidden>  
    <div class="row-head"><h2><svg><use href="#i-clock"/></svg> Recent Watch</h2></div>  
    <div id="recentRow" class="hrow"></div>  
  </section>    <section id="latestSection" hidden>  
    <div class="row-head"><h2><svg><use href="#i-bell"/></svg> Latest Update</h2></div>  
    <div id="latestRow" class="hrow"></div>  
  </section>    <section id="topSection" hidden>
    <div class="row-head"><h2><svg><use href="#i-star"/></svg> Top Rated Anime</h2></div>
    <div id="topRow" class="toptrack"></div>
  </section>
  <div class="row-head"><h2><svg><use href="#i-star"/></svg> Newest Drops</h2><span id="count" class="card-sub">0 titles</span></div>  
    
  <div id="catalog" class="grid"><div class="empty">Loading...</div></div>  
<noscript><ul><?php foreach (array_slice(is_array($catalog) ? ($catalog['anime'] ?? []) : [], 0, 60) as $__n) { if (!empty($__n['title']) && ($__n['category'] ?? '') !== 'hentai') echo '<li>' . $h($__n['title']) . '</li>'; } ?></ul></noscript>
</main>
<footer class="foot">&copy; 2026 Move Tok. Made with ❤️ in India by Harsh Singh</footer>  <section id="watch" class="watch">  
  <div class="watch-head">  
    <button id="backBtn" class="back" aria-label="Back"><svg class="ic"><use href="#i-back"/></svg></button>  
    <span id="vTitle"></span>  
  </div>  
  <div class="video-box" id="vbox">  
    <video id="video" playsinline webkit-playsinline preload="auto" poster="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"></video>  
    <div class="loader"><div class="wave" id="wave"></div><div class="lt" id="lt">Loading...</div></div>  
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
  </div>    <div class="details" id="details">  
    <div class="details-content">  
      <div class="anime-info">  
        <img id="detailPoster" class="detail-poster" alt="Poster">  
        <div class="detail-text">  
          <h1 id="detailTitle" class="detail-title"></h1>  
          <div><span id="detailType" class="tag"></span><span id="episodeLabel" class="tag"></span><span id="infoLabel" class="tag"></span></div>
          <div class="vote"><button id="likeBtn" class="vbtn" type="button" aria-label="Like"><svg class="ic"><use href="#i-like"/></svg><span id="likeN">0</span></button><button id="dislikeBtn" class="vbtn dis" type="button" aria-label="Dislike"><svg class="ic"><use href="#i-like"/></svg><span id="dislikeN">0</span></button></div>  
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
</section>  <script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-database-compat.js"></script>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>  <script>  
const VOTES = <?= json_encode((object)$VOTES_MAP) ?>;
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
const kindLabel=g=>g.type==="Movie"?"Hindi Movie":g.type==="Hentai"?"Hindi Hentai":"Drama / Anime";  
const isHentai=g=>g.type==="Hentai";  
  
/* ---------- recent watch storage (max 5, sabse purana apne aap hat jata hai; hentai save nahi hota) ---------- */  
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
  if(isHentai(g))return;  
  const cur=getRecent();if(!cur.length)store.set("zt:wk",Date.now());
  const r=cur.filter(x=>x.g!==gkey(g));  
  r.unshift({g:gkey(g),e:ep.id,t:Date.now()});  
  const keep=r;  
    
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
      (Array.isArray(s.episodes)?s.episodes:Array.from({length:s.count||0},(_,i)=>({number:i+1}))).forEach(e=>{  
        const q={};  
        (e.sources||[]).forEach(x=>{const u=safeUrl(x&&x.url);if(u)q[x.quality||"Default"]=u});  
        const eu=safeUrl(e.url);  
        if(eu&&!Object.values(q).includes(eu))q[e.quality||"Default"]=eu;  
        out.push({id:a.slug+"|"+s.number+"|"+e.number,title:String(a.title).trim(),type,poster,  
          season:Number(s.number)||1,episode:Number(e.number)||1,qualities:q,mp4Url:"",blk:a.blk?{t:a.blk,u:a.category==="movie"?"":(s.key||s.number)+"-"+e.number}:null,  
          rating:Number(a.rating)||0,vk:a.vk||"",ts:toTime(e.added_at)||toTime(a.added_at)});  
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
    if(!map.has(key))map.set(key,{title:item.title,type:item.type,poster:item.poster,vk:item.vk,rating:0,items:[]});  
    const g=map.get(key);  
    if(!g.poster&&item.poster)g.poster=item.poster;  
    g.items.push(item);  
  });  
  groups=[...map.values()];  
  groups.forEach(g=>{  
    g.items.sort((a,b)=>a.season-b.season||a.episode-b.episode);  
    g.ts=Math.max(0,...g.items.map(x=>x.ts));g.rating=Math.max(0,...g.items.map(x=>x.rating||0));  
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
function voteScore(g){const v=VOTES[g.vk]||[0,0];return v[0]-v[1]}
function topCmp(a,b){return (b.rating||0)-(a.rating||0)||voteScore(b)-voteScore(a)||b.ts-a.ts}
function renderTop(searching){
  const row=$("topRow"),keep=row.scrollLeft;row.replaceChildren();
  const list=groups.filter(g=>g.type==="Anime").sort(topCmp).slice(0,100);
  list.forEach((g,i)=>{
    const sl=document.createElement("article");sl.className="tslide";
    const media=document.createElement("div");media.className="hero-media";addPoster(media,g.poster,g.title);
    media.querySelectorAll("img").forEach(im=>{im.loading=i<2?"eager":"lazy"});
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
  $("topSection").hidden=searching||!list.length||!(currentFilter==="all"||currentFilter==="Anime");
}
function renderRows(){  
  const searching=$("search").value.trim()!=="";  
  // Recent Watch: max 5, hentai nahi  
  const rr=$("recentRow");rr.replaceChildren();  
  getRecent().forEach(r=>{  
    const g=groups.find(x=>gkey(x)===r.g);if(!g||isHentai(g))return;  
    const item=g.items.find(x=>x.id===r.e);if(!item)return;  
    const pos=store.get("zt:"+item.id),dur=store.get("ztd:"+item.id);  
    rr.appendChild(rowCard(g,item,{progress:dur?pos/dur*100:0}));  
  });  
  $("recentSection").hidden=searching||currentFilter==="Hentai"||!rr.children.length;  
  // Latest Update: sirf pichle 2 din, hentai nahi  
  const lr=$("latestRow");lr.replaceChildren();  
  const now=Date.now(),cand=[];  
  groups.forEach(g=>{  
    if(isHentai(g)||!g.ts||now-g.ts>=LATEST_MS)return;  
    let best=null;  
    g.items.forEach(x=>{if(x.ts&&now-x.ts<LATEST_MS&&(!best||x.ts>best.ts||(x.ts===best.ts&&(x.season>best.season||(x.season===best.season&&x.episode>best.episode)))))best=x});  
    if(best)cand.push([g,best]);  
  });  
  cand.sort((a,b)=>b[1].ts-a[1].ts).slice(0,20).forEach(c=>lr.appendChild(rowCard(c[0],c[1],{isNew:true})));  
  $("latestSection").hidden=searching||currentFilter==="Hentai"||!lr.children.length;
  renderTop(searching);  
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
  $("featuredSection").hidden=!heroList.length||currentFilter==="Hentai";  
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
  loadVotes(g);loadComments(g);
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
  fetch("?action=stream&tmdbId="+encodeURIComponent(item.blk.t)+(item.blk.u?"&uniqueId="+encodeURIComponent(item.blk.u):""))
    .then(r=>r.json())
    .then(d=>{if(d&&d.ok&&d.streams)d.streams.forEach(s=>{item.qualities[s.quality]=s.url})})
    .catch(()=>{})
    .then(()=>{if(currentEpisode===item)loadEpisode(true)});
}
function loadEpisode(noBlk){  
  updateLabel();  
  if(infoMode){stopVideo();closeMenu();return}  
  const qBox=$("qOpts");qBox.replaceChildren();qBox.dataset.h="";closeMenu();  
  if(!currentEpisode){stopVideo();showErr("No episodes available.");return}  
  if(!noBlk&&currentEpisode.blk&&!Object.keys(currentEpisode.qualities).length){blkLoad(currentEpisode);return}  
  const q=getQualities(currentEpisode);  
  // "Auto" (HLS master link) sabse upar, baaki 1080p, 720p... ke order me  
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
    // "Auto" HLS khud quality badalta hai, isliye wo hamesha pehli choice  
    currentQuality=names.includes("Auto")?"Auto":(slow?names[names.length-1]:names[0]);  
  }  
  markQuality();    
  playVideo(q[currentQuality],store.get("zt:"+currentEpisode.id));  
  holdPlay=false;  
}  
function markQuality(){document.querySelectorAll("#qOpts .opt").forEach(b=>b.classList.toggle("active",b.textContent===currentQuality))}  
function closeWatch(){  
  stopVideo();infoMode=false;$("watch").classList.remove("info-mode");$("watch").style.display="none";closeMenu();  
  document.body.style.overflow="";currentGroup=null;currentEpisode=null;currentQuality="";watchOpen=false;detachFb();seo(SEO0.t,SEO0.d);  
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
  
/* ---------- video player (MP4 + HLS m3u8) ---------- */  
const V=$("video"),box=$("vbox");  
let hideT,tapT,lastTap=0,drag=false,curUrl="",hls=null,hlsRetry=0;  
  
function showUI(){  
  box.classList.remove("idle");clearTimeout(hideT);  
  if(!V.paused)hideT=setTimeout(()=>{if($("menu").classList.contains("open")){showUI();return}box.classList.add("idle")},2500);  
}  
function closeMenu(){$("menu").classList.remove("open")}  
function showErr(msg){$("verr").textContent=msg;$("verr").hidden=false;box.classList.remove("loading")}  
const isHls=u=>/\.m3u8(\?|&|$)|\.tar\?/i.test(u||"");  
function destroyHls(){if(hls){try{hls.destroy()}catch{}hls=null}hlsRetry=0}  
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
        manifestLoadingMaxRetryTimeout:10000,  
        fragLoadingMaxRetry:6,  
        fragLoadingRetryDelay:1000,  
        fragLoadingMaxRetryTimeout:12000,  
        levelLoadingMaxRetry:4,  
        levelLoadingRetryDelay:1000  
      });  
      hls.loadSource(s);  
      hls.attachMedia(V);  
      hls.on(Hls.Events.MANIFEST_PARSED,()=>{hlsMenu();if(!holdPlay)V.play().catch(()=>{})});  
      hls.on(Hls.Events.ERROR,(ev,d)=>{  
        if(!d||!d.fatal)return;  
        if(d.type===Hls.ErrorTypes.NETWORK_ERROR){  
          if(hlsRetry<5){hlsRetry++;hls.startLoad();return}  
          showErr("Network failed. Connection check karo.");return;  
        }  
        if(d.type===Hls.ErrorTypes.MEDIA_ERROR){hls.recoverMediaError();return}  
        destroyHls();  
        showErr("Video load nahi ho paya (HLS). Net check karo ya baad me aao.");  
      });  
      return;  
    }  
    if(V.canPlayType("application/vnd.apple.mpegurl")){  
      V.src=s;V.load();if(!holdPlay)V.play().catch(()=>{});return;  
    }  
    showErr("Is browser me HLS (m3u8) support nahi hai.");return;  
  }  
  V.src=s;V.load();if(!holdPlay)V.play().catch(()=>{});  
}  
/* ---------- loading wave + buffer info ---------- */  
(function(){  
  const w=$("wave");  
  for(let i=0;i<16;i++){const b=document.createElement("i");b.style.animationDelay=(i*0.07)+"s";w.appendChild(b)}  
})();  
let statT=null,fakePct=0;  
function bufAhead(){  
  let e=0;for(let i=0;i<V.buffered.length;i++)if(V.buffered.start(i)<=V.currentTime+.5)e=Math.max(e,V.buffered.end(i));  
  return Math.max(0,e-V.currentTime);  
}  
function statTick(){  
  // fake buffer %: tezi se badhta hai, 95% par ruk jata hai jab tak video na chale  
  fakePct+=(95-fakePct)*0.1;  
  const c=navigator.connection,real=bufAhead();  
  $("lt").textContent="Buffering: "+Math.floor(fakePct)+"%"+(c&&c.downlink?" | Net: "+c.downlink+" Mbps":"")+(real>0?" | "+real.toFixed(0)+"s":"");  
}  
new MutationObserver(()=>{  
  if(box.classList.contains("loading")){  
    if(!statT){fakePct=3;statTick();statT=setInterval(statTick,500)}  
  }else if(statT){clearInterval(statT);statT=null}  
}).observe(box,{attributes:true,attributeFilter:["class"]});  
  
function stopVideo(){  
  curUrl="";destroyHls();  
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
  
V.addEventListener("playing",()=>{hlsRetry=0});  
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
V.addEventListener("error",()=>{if(!hls&&V.getAttribute("src")){const ce=currentEpisode;if(ce&&ce.blk&&!ce.blkProxy&&!isHls(curUrl)){ce.blkProxy=true;Object.keys(ce.qualities).forEach(k=>{ce.qualities[k]=new URL("?action=proxy&url="+encodeURIComponent(ce.qualities[k]),location.href).href});curUrl="";loadEpisode(true);return}showErr("Video load nahi ho paya. Dusri quality try karo ya baad me aao.")}});  
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
  

/* ---------- like / dislike ---------- */
const vRaw=g=>g.type+"|"+g.title;
const myVote=g=>{try{return localStorage.getItem("mt:v:"+vRaw(g))||"none"}catch{return "none"}};
function markVote(v){$("likeBtn").classList.toggle("on",v==="like");$("dislikeBtn").classList.toggle("on",v==="dislike")}
function setCounts(l,d){$("likeN").textContent=l;$("dislikeN").textContent=d}
function showCounts(g,l,d){const m=myVote(g);if(m==="like"&&l<1)l=1;if(m==="dislike"&&d<1)d=1;setCounts(l,d)}
const FB_CFG={apiKey:"AIzaSyCYOS7gsCGzaySTiA7EmeqxcP2sa3bySRU",authDomain:"move-tok.firebaseapp.com",databaseURL:"https://move-tok-default-rtdb.firebaseio.com",projectId:"move-tok",storageBucket:"move-tok.firebasestorage.app",messagingSenderId:"994220546725",appId:"1:994220546725:web:71c3a8484416a9e8344b4c"};
let DB=null;
try{if(window.firebase){firebase.initializeApp(FB_CFG);DB=firebase.database()}}catch{}
const gid=g=>g.vk||("h"+[...vRaw(g)].reduce((h,c)=>(h*31+c.charCodeAt(0))>>>0,7).toString(16));
let voteRef=null,cmRef=null,lastPost=0;
function detachFb(){if(voteRef){voteRef.off();voteRef=null}if(cmRef){cmRef.off();cmRef=null}}
function loadVotes(g){
  if(voteRef){voteRef.off();voteRef=null}
  const b=VOTES[g.vk]||[0,0];showCounts(g,b[0],b[1]);markVote(myVote(g));
  if(!DB)return;
  voteRef=DB.ref("votes/"+gid(g));
  voteRef.on("value",sn=>{
    const v=sn.val()||{},l=Math.max(0,+v.l||0),d=Math.max(0,+v.d||0);
    VOTES[g.vk]=[l,d];if(currentGroup===g)showCounts(g,l,d);
  });
}
function castVote(kind){
  const g=currentGroup;if(!g||!DB)return;
  const prev=myVote(g),next=prev===kind?"none":kind;
  try{localStorage.setItem("mt:v:"+vRaw(g),next)}catch{}
  markVote(next);
  const r=DB.ref("votes/"+gid(g));
  const bump=(f,n)=>r.child(f).transaction(v=>Math.max(0,(v||0)+n));
  if(prev==="like")bump("l",-1);if(prev==="dislike")bump("d",-1);
  if(next==="like")bump("l",1);if(next==="dislike")bump("d",1);
}
$("likeBtn").onclick=()=>castVote("like");
$("dislikeBtn").onclick=()=>castVote("dislike");
if(DB)DB.ref("votes").once("value").then(sn=>{
  const o=sn.val()||{};
  Object.keys(o).forEach(k=>{VOTES[k]=[Math.max(0,+o[k].l||0),Math.max(0,+o[k].d||0)]});
  renderRows();
}).catch(()=>{});

/* ---------- comments (Firebase Realtime Database) ---------- */
function loadComments(g){
  if(cmRef){cmRef.off();cmRef=null}
  const list=$("cmList");list.replaceChildren();$("cmCount").textContent="0";$("cmErr").textContent="";
  try{$("cmName").value=localStorage.getItem("mt:name")||""}catch{}
  if(!DB){list.textContent="Comments abhi available nahi hain.";return}
  cmRef=DB.ref("comments/"+gid(g)).limitToLast(100);
  cmRef.on("value",sn=>{
    if(currentGroup!==g)return;
    const arr=[];sn.forEach(c=>{const v=c.val();if(v&&v.t)arr.push(v)});
    arr.reverse();list.replaceChildren();$("cmCount").textContent=arr.length;
    if(!arr.length){const e=document.createElement("div");e.className="cm-empty";e.textContent="Abhi koi comment nahi. Pehla comment aap karo!";list.appendChild(e);return}
    arr.forEach(v=>{
      const it=document.createElement("div");it.className="cm-item";
      const top=document.createElement("div");top.className="cm-top";
      const n=document.createElement("b");n.textContent=String(v.n||"User").slice(0,30);
      const d=document.createElement("span");
      d.textContent=v.ts?new Date(v.ts).toLocaleString([],{day:"numeric",month:"short",hour:"2-digit",minute:"2-digit"}):"";
      top.append(n,d);
      const t=document.createElement("p");t.className="cm-text";t.textContent=String(v.t).slice(0,500);
      it.append(top,t);list.appendChild(it);
    });
  });
}
function postComment(){
  const g=currentGroup;if(!g||!DB)return;
  const n=$("cmName").value.trim().slice(0,30),t=$("cmText").value.trim().slice(0,500),err=$("cmErr");
  err.textContent="";
  if(!n){err.textContent="Pehle apna naam likho.";$("cmName").focus();return}
  if(!t){err.textContent="Comment likho.";$("cmText").focus();return}
  if(Date.now()-lastPost<5000){err.textContent="Thoda ruko, 5 second baad dobara comment karo.";return}
  lastPost=Date.now();
  try{localStorage.setItem("mt:name",n)}catch{}
  const b=$("cmPost");b.disabled=true;
  DB.ref("comments/"+gid(g)).push({n:n,t:t,ts:firebase.database.ServerValue.TIMESTAMP})
    .then(()=>{$("cmText").value=""})
    .catch(()=>{err.textContent="Comment post nahi hua. Dobara try karo."})
    .then(()=>{b.disabled=false});
}
$("cmPost").onclick=postComment;

/* ---------- SEO (title/description badalte hain) ---------- */
const SEO0={t:document.title,d:(document.querySelector('meta[name="description"]')||{}).content||""};
function seo(t,d){
  document.title=t;
  const m=document.querySelector('meta[name="description"]');if(m&&d)m.setAttribute("content",d);
  const og=document.querySelector('meta[property="og:title"]');if(og)og.setAttribute("content",t);
}

/* ---------- start ---------- */  
allItems=buildItems();  
buildGroups();render();renderSlider();  
</script>  </body>  
</html>

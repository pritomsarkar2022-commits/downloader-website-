<?php
declare(strict_types=1);

/**
 * Live proof for the general deep reader: pages from sites that have no dedicated
 * extractor at all, plus one nested player, one bare file address, one refusal and
 * one page that holds no video. Every link must end in either a fetched file or an
 * honest refusal. A generated url or an HTTP 200 on the page proves nothing on its
 * own, so each source is pulled through the relay and its first bytes are read.
 *
 *   php tests/deep_matrix.php [base-url]
 *   php tests/deep_matrix.php http://127.0.0.1:8085/index.php https://example.com/page
 */
require __DIR__ . '/../lib/support.php';

$base = $argv[1] ?? 'http://127.0.0.1:8085/index.php';

/** url => what a correct answer looks like */
$cases = [
    // A page whose player is built by javascript and whose files sit behind an embed document.
    'https://archive.org/details/BigBuckBunny_328' => 'fetch',
    // The player document on its own, which is what a visitor would paste from an embed.
    'https://archive.org/embed/BigBuckBunny_328' => 'fetch',
    // A commons file page: the media address is in the markup, behind several sections.
    'https://commons.wikimedia.org/wiki/File:Big_Buck_Bunny_4K.webm' => 'fetch',
    // A teaching page that puts its file straight in a video element. Its host
    // answers this server with a Cloudflare challenge, so the right result here is
    // a refusal that says so, not a download that would not work.
    'https://www.w3.org/2010/05/video/mediaevents.html' => 'refuse',
    // A sample file index: the files are anchors, not a player.
    'https://test-videos.co.uk/bigbuckbunny/mp4-h264' => 'fetch',
    // A bare playlist address pasted directly, which is what a network tab copy looks like.
    'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8' => 'fetch',
    // A page with no video on it: the answer must be a clean refusal, not a dead link.
    'https://example.com/' => 'refuse',
    // The one host this tool answers about on purpose.
    'https://www.youtube.com/watch?v=abcd1234' => 'refuse',
];
if (($argv[2] ?? '') !== '') {
    $cases = [$argv[2] => 'fetch'];
}

function post(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 150,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string) $body, true);
    return [$status, is_array($json) ? $json : null, $err, (string) $body];
}

/** @return array{0:int,1:string,2:int,3:string} */
function fetchBytes(string $url): array
{
    // Wikimedia and several test cdns answer a second request for the same file
    // within a few seconds with 429. Waiting is the only honest way to still see
    // whether the relay itself works.
    for ($try = 0; $try < 3; $try++) {
        $out = fetchOnce($url);
        if ($out[0] !== 429) {
            return $out;
        }
        echo "    upstream rate limit, waiting 20s\n";
        sleep(20);
    }
    return fetchOnce($url);
}

/** @return array{0:int,1:string,2:int,3:string} */
function fetchOnce(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RANGE => '0-200000',
        CURLOPT_HEADER => true,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hsize);
    $body = substr($raw, $hsize);
    $ct = preg_match('~^content-type:\s*(.+)$~mi', $headers, $m) ? trim($m[1]) : '?';
    return [$status, $ct, strlen($body), substr($body, 0, 24)];
}

function magic(string $bytes): string
{
    if ($bytes === '') {
        return 'empty';
    }
    if (str_starts_with(ltrim($bytes), '#EXTM3U')) {
        return 'HLS playlist';
    }
    if ($bytes[0] === "\x47" && (strlen($bytes) < 4 || ($bytes[188] ?? '') === "\x47")) {
        return 'MPEG-TS';
    }
    if (strlen($bytes) > 11 && substr($bytes, 4, 4) === 'ftyp') {
        return 'MP4/' . substr($bytes, 8, 4);
    }
    if (str_starts_with($bytes, "\x1a\x45\xdf\xa3")) {
        return 'WebM/MKV';
    }
    if (preg_match('~<(?:!doctype|html)~i', $bytes)) {
        return 'html (not a file)';
    }
    return 'bytes:' . strtoupper(bin2hex(substr($bytes, 0, 8)));
}

$rows = [];
$fatal = 0;
$first = true;
foreach ($cases as $url => $expect) {
    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    if (!$first) {
        // Every host here answers a second visit sooner than its rate limiter
        // forgets the first, so the run walks slowly on purpose.
        sleep(6);
    }
    $first = false;
    echo "\n=== {$url}  (expect {$expect})\n";
    $json = null;
    $err = '';
    $started = microtime(true);
    for ($try = 0; $try < 4; $try++) {
        [$st, $json, $err] = post($base . '?r=analyze', ['url' => $url]);
        if ($err !== '' || ($json['code'] ?? '') !== 'rate_limited') {
            break;
        }
        $wait = min(75, max(1, (int) ($json['retryAfter'] ?? 15)));
        echo "  waiting {$wait}s for the analysis limit\n";
        sleep($wait);
    }
    $ms = (int) round((microtime(true) - $started) * 1000);
    if ($err !== '') {
        echo "  curl error: {$err}\n";
        $fatal++;
        $rows[] = [$host, 'ERROR', $err, $ms];
        continue;
    }
    if (!is_array($json)) {
        echo "  not json (HTTP $st)\n";
        $fatal++;
        $rows[] = [$host, 'ERROR', 'not json', $ms];
        continue;
    }
    if (empty($json['ok'])) {
        $code = (string) ($json['code'] ?? '');
        echo '  refusal: ' . ($json['error'] ?? '?') . '  [' . ($json['hint'] ?? '') . "] code={$code}\n";
        if ($expect === 'refuse') {
            $rows[] = [$host, 'HONEST REFUSAL', $code, $ms];
        } else {
            $fatal++;
            $rows[] = [$host, 'FAIL', $code . ' ' . ($json['error'] ?? ''), $ms];
        }
        continue;
    }

    $bestBytes = '';
    $lines = [];
    foreach ($json['sources'] as $s) {
        [$pSt, $pCt, $pLen, $pHead] = fetchBytes($s['previewUrl']);
        [$dSt, $dCt, $dLen, $dHead] = fetchBytes($s['downloadUrl']);
        $lines[] = sprintf(
            '    %-4s %-22s %6s %9s  preview %d %s %dB  magic=%s',
            (string) $s['kind'],
            substr((string) ($s['label'] ?? ''), 0, 22),
            ($s['height'] ?? null) !== null ? $s['height'] . 'p' : '-',
            (string) ($s['sizeHuman'] ?? '-'),
            $pSt,
            substr($pCt, 0, 24),
            $pLen,
            magic($pHead)
        );
        if ($dSt < 200 || $dSt >= 400 || $dLen < 64) {
            $lines[] = '    download FAILED: HTTP ' . $dSt . ' ' . $dLen . 'B';
        } else {
            $lines[] = '    download HTTP ' . $dSt . ' ' . $dCt . ' ' . $dLen . 'B magic=' . magic($dHead);
        }
        $bestBytes = magic($pHead);
    }
    foreach ($lines as $l) {
        echo $l . "\n";
    }
    if (!empty($json['notices'])) {
        echo '  notices: ' . implode(' | ', (array) $json['notices']) . "\n";
    }
    $isMedia = $bestBytes !== '' && !str_contains($bestBytes, 'html') && $bestBytes !== 'empty';
    if ($isMedia) {
        $rows[] = [$host, 'FETCH', count($json['sources']) . ' source(s), ' . $json['provider'] . ', conf ' . $json['confidence'], $ms];
        if ($expect !== 'fetch') {
            $fatal++;
        }
    } else {
        $fatal++;
        $rows[] = [$host, 'FAIL', 'no media bytes came back', $ms];
    }
}

echo "\n==================== deep discovery matrix ====================\n";
printf("%-34s %-16s %-52s %7s\n", 'host', 'verdict', 'detail', 'ms');
foreach ($rows as $r) {
    printf("%-34s %-16s %-52s %7d\n", substr($r[0], 0, 34), $r[1], substr($r[2], 0, 52), $r[3]);
}
$fetch = count(array_filter($rows, static fn(array $r) => $r[1] === 'FETCH'));
$refused = count(array_filter($rows, static fn(array $r) => $r[1] === 'HONEST REFUSAL'));
printf(
    "\n%d link(s): %d fetched, %d honestly refused, %d wrong\n",
    count($rows),
    $fetch,
    $refused,
    $fatal
);
exit($fatal === 0 ? 0 : 1);

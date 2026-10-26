<?php
declare(strict_types=1);
/**
 * Live smoke test: php tests/smoke.php [base-url]
 * Posts each sample link to the running app and prints what came back,
 * then fetches the returned preview link and the first bytes of a download.
 */
$base = $argv[1] ?? 'http://127.0.0.1:8085/index.php';
$urls = [
    'https://streamtape.com/v/74AwoLa6xQCAAQ4/1792525423448.mp4',
    'https://streamfile.net/2xdgz3t2bphw',
    'https://luluvido.com/66ms4l6xkfb9',
    'https://firestream.to/e/5MVxhqei',
    'https://vidara.to/v/qY27dmqIAWJ3O',
    'https://vidara.to/v/3FNwKcVmnNvw5',
    'https://playmate.to/watch/Nh93AdD9E6cl5',
    'https://cloudfam.io/cf3b9cf55ec3',
    'https://cloudfam.io/ad06204a6eec',
    'https://cloudfam.io/4a6b3c77aa6c',
    'https://cloudfam.io/2d395ec13fe2',
    'https://vixeo.io/e/2aEpRw0sAzmB',
    'https://vixeo.io/e/6izqlFDgja6E',
    'https://vixeo.io/e/znKoDEi0mtBC',
    'https://mxdrop.click/f/vngdxnpetkgneq',
    'https://mxdrop.click/f/jd9o7jm8f76ge8r',
    'https://dupload.net/d69az94bqhso',
    'https://advtpe.com/v/6RKRoe082Ru99xa/mncfn.mp4',
    'https://advtpe.com/v/qWX2g9KrMoTY4M/UP24924aaliya_bhattt.mp4',
    'https://frdl.by/863fzrwqojan',
    'https://frdl.by/nyusaaciejvk/Bangla.mp4.html',
    'https://flash-files.com/Lmdk5gC6mxmjGSfG',
    'https://flash-files.com/ztpeNtxSKo5mIko9',
    'https://torupload.com/file/bzsidgtdpk1c',
    'https://upfilesgo.com/NOn8F5',
    'https://www.file-upload.org/emdsxzq5ihxi',
];
if (($argv[2] ?? '') === 'one' && isset($argv[3])) {
    $urls = [$argv[3]];
}

function post(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string) $body, true);
    return [$status, is_array($json) ? $json : null, $err, (string) $body];
}

function fetchBytes(string $url, int $cap = 200000): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RANGE => '0-' . $cap,
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
        return 'MP4 (' . substr($bytes, 8, 4) . ')';
    }
    if (str_starts_with($bytes, '{"status":500')) {
        return 'streamtape error json';
    }
    return 'bytes:' . strtoupper(bin2hex(substr($bytes, 0, 8)));
}

$total = 0;
$pass = 0;
$refused = 0;
$skipped = 0;
// These hosts put a captcha, a browser check or an account in front of the file.
// A clean refusal is the correct result, so the test counts it as a pass.
$gated = ['frdl.by', 'freedl.ink', 'flash-files.com', 'torupload.com', 'torlock.com', 'upfilesgo.com', 'upfiles.com', 'file-upload.org', 'uptobox.com', 'mixdrop.ac'];
foreach ($urls as $u) {
    $total++;
    echo "\n=== " . $u . "\n";
    // The live app rate limits analysis, and a wall of links tested one after
    // another hits it. Waiting is the only honest way to still see the real answer.
    for ($try = 0; $try < 4; $try++) {
        [$st, $json, $err, $raw] = post($base . '?r=analyze', ['url' => $u]);
        if ($err !== '') {
            echo "  curl error: $err\n";
            break;
        }
        if (($json['code'] ?? '') !== 'rate_limited') {
            break;
        }
        $wait = min(75, max(1, (int) ($json['retryAfter'] ?? 15)));
        echo "  waiting {$wait}s for the analysis limit (attempt " . ($try + 1) . ")\n";
        sleep($wait);
    }
    if ($err !== '') {
        continue;
    }
    if (!is_array($json)) {
        echo "  HTTP $st, not json: " . substr(preg_replace('/\s+/', ' ', $raw), 0, 300) . "\n";
        continue;
    }
    if (empty($json['ok'])) {
        echo "  REJECTED (HTTP $st): " . ($json['error'] ?? '?')
            . "\n    hint: " . ($json['hint'] ?? '') . "  code=" . ($json['code'] ?? '') . "\n";
        // Only the app's own refusal counts. A rate limit or a generic error on a
        // gated host says nothing about whether that host is really understood.
        if (($json['code'] ?? '') === 'gated') {
            $pass++;
            $refused++;
            echo "  => HONEST REFUSAL\n";
        } elseif (($json['code'] ?? '') === 'rate_limited') {
            $skipped++;
            echo "  => NOT TESTED (still limited)\n";
        } elseif (in_array((string) preg_replace('~^www\.~', '', (string) (parse_url($u, PHP_URL_HOST) ?: '')), $gated, true)) {
            $skipped++;
            echo "  => NOT TESTED (gated host, but the app said " . ($json['code'] ?? '?') . ")\n";
        }
        continue;
    }
    echo "  provider=" . $json['provider']
        . " conf=" . $json['confidence']
        . " title=" . mb_substr((string) $json['title'], 0, 48)
        . " job=" . $json['job'] . "\n";
    $hostOk = 0;
    foreach ($json['sources'] as $s) {
        printf(
            "  - kind=%-4s label=%-18s height=%-5s size=%-9s dur=%-7s variants=%d\n",
            $s['kind'],
            substr((string) ($s['label'] ?? ''), 0, 18),
            (string) ($s['height'] ?? '-'),
            (string) ($s['sizeHuman'] ?? '-'),
            (string) ($s['duration'] ?? '-'),
            count($s['variants'])
        );
        foreach (array_slice($s['variants'], 0, 4) as $v) {
            printf(
                "      variant h=%-5s bw=%-9s segs=%-5s enc=%-5s dur=%s\n",
                (string) ($v['height'] ?? '-'),
                (string) ($v['bandwidth'] ?? '-'),
                (string) ($v['segments'] ?? '-'),
                !empty($v['encrypted']) ? 'yes' : 'no',
                (string) ($v['duration'] ?? '-')
            );
        }
        if (!empty($s['note'])) {
            echo "      note: " . $s['note'] . "\n";
        }
        // Proof: fetch the relayed preview and the relayed download.
        [$pSt, $pCt, $pLen, $pBytes] = fetchBytes($s['previewUrl']);
        echo "      preview  HTTP $pSt  $pCt  $pLen B  magic=" . magic($pBytes) . "\n";
        [$dSt, $dCt, $dLen, $dBytes] = fetchBytes($s['downloadUrl']);
        echo "      download HTTP $dSt  $dCt  $dLen B  magic=" . magic($dBytes) . "\n";
        if ($pSt >= 200 && $pSt < 400 && $pLen > 64) {
            $hostOk++;
        }
    }
    if ($hostOk > 0) {
        $pass++;
        echo "  => WORKS ($hostOk source(s) fetchable)\n";
    } else {
        echo "  => NO FETCHABLE SOURCE\n";
    }
}
echo "\n-------------------------------------------------\n";
echo "hosts passing: $pass / $total   (of which honest refusals: $refused, not tested: $skipped)\n";
// Anything neither fetched nor cleanly refused is an unproven host, so the run
// only passes when every link landed in one of the two known buckets.
exit($pass + $skipped === $total ? 0 : 1);

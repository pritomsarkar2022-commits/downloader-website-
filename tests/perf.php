<?php
declare(strict_types=1);
/**
 * Measured numbers, no claims: what the page costs to load and what one analysis
 * costs to run. php -S on a laptop is not LiteSpeed on a shared plan, so anything
 that depends on apache modules is labelled as unmeasured here rather than guessed.
 *
 *   php tests/perf.php [base-url]
 */
require __DIR__ . '/../lib/support.php';

$base = rtrim((string) ($argv[1] ?? 'http://127.0.0.1:8085/index.php'), '/');
$root = (string) preg_replace('~/index\.php$~', '', $base);

function timed(string $url, array $opts = []): array
{
    $tfb = null;
    $buffer = '';
    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        // One callback keeps the first-byte timestamp, the transfer size and the
        // bytes themselves, which return transfer would not give together.
        CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$tfb, &$buffer, $t0): int {
            $buffer .= $chunk;
            if ($tfb === null) {
                $tfb = microtime(true) - $t0;
            }
            return strlen($chunk);
        },
    ] + $opts);
    curl_exec($ch);
    $total = microtime(true) - $t0;
    $info = curl_getinfo($ch);
    curl_close($ch);
    $hs = (int) $info['header_size'];
    $hdr = [];
    foreach (explode("\r\n", substr($buffer, 0, $hs)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $hdr[strtolower(trim($k))][] = trim($v);
        }
    }
    return [
        'code' => (int) $info['http_code'],
        'tfb' => ($tfb ?? $total) * 1000,
        'total' => $total * 1000,
        'bytes' => max(0, strlen($buffer) - $hs),
        'headers' => $hdr,
        'body' => substr($buffer, $hs),
    ];
}

function ms(float $v): string
{
    return number_format($v, 1) . ' ms';
}

echo "== page\n";
$r = timed($root . '/');
echo '  GET /            HTTP ' . $r['code'] . '  ttfb ' . ms($r['tfb']) . '  total ' . ms($r['total'])
    . '  transfer ' . number_format($r['bytes']) . " B\n";
$gzip = isset($r['headers']['content-encoding']);
echo '  content-encoding: ' . ($gzip ? implode(',', $r['headers']['content-encoding']) : 'none (php -S does not run mod_deflate)') . "\n";
foreach (['cache-control', 'x-content-type-options', 'content-security-policy'] as $h) {
    echo '  ' . $h . ': ' . ($r['headers'][$h][0] ?? 'not set locally') . "\n";
}

$html = $r['body'];
preg_match_all('~<script[^>]*\ssrc="([^"]+)"[^>]*>~i', $html, $jsTags);
$blockingJs = array_values(array_filter(
    $jsTags[0],
    static fn(string $tag): bool => !preg_match('~\s(defer|async)\b~i', $tag)
));
preg_match_all('~<link[^>]*\srel="stylesheet"[^>]*>~i', $html, $cssTags);
$blockingCss = array_values(array_filter(
    $cssTags[0],
    static fn(string $tag): bool => !preg_match('~media=(["\']?)print~i', $tag)
));
$sheetUrls = array_map(static fn(string $t): string => (string) (preg_match('~href="([^"]+)~', $t, $m) ? $m[1] : '?'), $cssTags[0]);
echo '  requests before the page is usable: ' . (count($blockingCss) + count($jsTags[0])) . ' subresources ('
    . count($blockingCss) . ' render-blocking css, ' . count($blockingJs) . ' blocking js of ' . count($jsTags[0]) . ' script tags)' . "\n";
echo '  stylesheets loaded: ' . implode(', ', array_map(static fn(string $u): string => preg_replace('~\?.*$~', '', $u), $sheetUrls)) . "\n";

echo "\n== assets\n";
foreach (['assets/css/material.css', 'assets/css/app.css', 'assets/js/app.js'] as $rel) {
    $path = VD_ROOT . '/' . $rel;
    $raw = (string) file_get_contents($path);
    $z = gzencode($raw, 6);
    $r = timed($root . '/' . $rel . '?v=' . ASSET_VER);
    printf(
        "  %-24s %6d B  gz %6d B (%2d%%)  HTTP %d  %s  cache: %s\n",
        $rel,
        strlen($raw),
        strlen((string) $z),
        (int) round(100 - strlen((string) $z) / max(1, strlen($raw)) * 100),
        $r['code'],
        ms($r['total']),
        $r['headers']['cache-control'][0] ?? ($r['headers']['expires'][0] ?? 'none locally')
    );
}
echo "  hls.js is not on the page: app.js inserts the tag only when an HLS preview opens,\n";
echo "  so a plain mp4 result never downloads it.\n";

echo "\n== analysis latency (real network, shared host not involved)\n";
foreach ([
    'https://cloudfam.io/ad06204a6eec',
    'https://streamtape.com/v/74AwoLa6xQCAAQ4/1792525423448.mp4',
    'https://vixeo.io/e/2aEpRw0sAzmB',
] as $page) {
    $best = null;
    $sources = 0;
    $provider = '?';
    for ($i = 0; $i < 2; $i++) {
        $t0 = microtime(true);
        $ch = curl_init($base . '?r=analyze');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['url' => $page]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $el = (microtime(true) - $t0) * 1000;
        $json = json_decode($body, true);
        if (empty($json['ok'])) {
            $best = null;
            $provider = (string) ($json['code'] ?? 'http ' . $code);
            break;
        }
        $best = $best === null ? $el : min($best, $el);
        $sources = count($json['sources']);
        $provider = (string) $json['provider'];
        usleep(2_600_000);   // stay under the 20 analyses per minute guard
    }
    echo '  ' . str_pad((string) parse_url($page, PHP_URL_HOST), 22) . ' '
        . ($best === null ? 'not measured (' . $provider . ')' : 'fastest of 2 ' . ms($best) . ', provider ' . $provider . ', ' . $sources . ' source(s)')
        . "\n";
}

echo "\n== relay cost (proxying a segment through this server)\n";
$ch = curl_init($base . '?r=analyze');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['url' => 'https://cloudfam.io/ad06204a6eec']), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
$a = json_decode((string) curl_exec($ch), true);
curl_close($ch);
$src = $a['sources'][0] ?? null;
if (is_array($src)) {
    $r = timed((string) $src['downloadUrl']);
    echo '  full file through the relay: ' . number_format($r['bytes']) . " B in " . ms($r['total'])
        . ' = ' . number_format($r['bytes'] / max(1, $r['total'] / 1000) / 1048576, 2) . " MB/s\n";
}

echo "\nLocal numbers only. Compression, byte ranges served by apache, and the\n";
echo "shared-plan cpu quota cannot be measured from php -S and are reported as\n";
echo "unverified until the site is on the hosting account.\n";

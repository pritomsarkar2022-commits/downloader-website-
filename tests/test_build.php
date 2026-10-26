<?php
declare(strict_types=1);
/**
 * Live end to end build: page link -> analyse -> save as MP4 -> poll -> download.
 *
 * Usage: php tests/test_build.php <page-url> [base-url]
 * Writes tests/out/build-<host>.mp4 and prints the table dump so the file can also
 * be played through tests/out/preview.html.
 */
require __DIR__ . '/../lib/support.php';

$page = $argv[1] ?? null;
if (!is_string($page) || $page === '') {
    fwrite(STDERR, "usage: php tests/test_build.php <page-url> [base-url]\n");
    exit(2);
}
$base = $argv[2] ?? 'http://127.0.0.1:8085/index.php';
$deadline = microtime(true) + (float) ($argv[3] ?? 900);

function call(string $url, array $post = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($post !== []) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = json_decode($body, true);
    return [$status, is_array($json) ? $json : null, $err, $body];
}

[$st, $a, $err] = call($base . '?r=analyze', ['url' => $page]);
if (!is_array($a) || !($a['ok'] ?? false)) {
    fwrite(STDERR, "analyze failed ($st) " . ($a['error'] ?? $err) . "\n");
    exit(1);
}
$job = (string) ($a['job'] ?? '');
$sources = $a['sources'] ?? [];
$hls = null;
foreach ($sources as $s) {
    if (($s['kind'] ?? '') === 'hls') {
        $hls = $s;
        break;
    }
}
printf("analyse: job=%s sources=%d picked=%s\n", $job, count($sources), $hls['label'] ?? 'none');
if ($hls === null) {
    fwrite(STDERR, "no HLS source in this result, nothing to convert\n");
    exit(1);
}

[$st, $b, $err] = call($base . '?r=build', ['job' => $job, 'src' => $hls['id'] ?? '']);
if (!is_array($b) || !($b['ok'] ?? false)) {
    fwrite(STDERR, "build refused ($st): " . ($b['error'] ?? $err) . ' / ' . ($b['hint'] ?? '') . "\n");
    exit(1);
}
$build = (string) $b['build'];
printf("build: %s (%s parts, %s s)\n", $build, $b['segments'] ?? '?', $b['duration'] ?? '?');

$p = [];
$polls = 0;
while (microtime(true) < $deadline) {
    [, $p, $err] = call($base . '?r=progress&build=' . $build);
    if (!is_array($p)) {
        fwrite(STDERR, "progress broke: $err\n");
        exit(1);
    }
    $polls++;
    printf("  %3d%%  %s/%s parts  %s  %s\n",
        (int) ($p['percent'] ?? 0), (string) ($p['parts'] ?? 0), (string) ($p['segments'] ?? 0),
        (string) ($p['sizeHuman'] ?? '?'), (string) ($p['status'] ?? ''));
    if (!empty($p['done'])) {
        break;
    }
    if (!($p['ok'] ?? true)) {
        fwrite(STDERR, "build error: " . ($p['error'] ?? '') . ' / ' . ($p['hint'] ?? '') . "\n");
        exit(1);
    }
    usleep(400000);
}
if (empty($p['done'])) {
    fwrite(STDERR, "did not finish inside the window\n");
    exit(1);
}

$out = __DIR__ . '/out/build-' . preg_replace('/[^a-z0-9]+/i', '', (string) parse_url($page, PHP_URL_HOST)) . '.mp4';
$ch = curl_init((string) $p['fileUrl']);
$fh = fopen($out, 'wb');
curl_setopt_array($ch, [
    CURLOPT_FILE => $fh,
    CURLOPT_TIMEOUT => 600,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$ok = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
fclose($fh);
if (!$ok || $code !== 200) {
    fwrite(STDERR, "download failed: http $code\n");
    exit(1);
}
printf("done: %s polls, saved %s (%s), %s video samples\n",
    $polls, $out, human_bytes((int) filesize($out)), (string) ($p['videoSamples'] ?? '?'));
if (!empty($p['note'])) {
    echo 'note: ' . $p['note'] . "\n";
}
printf("head: %s\n", bin2hex((string) file_get_contents($out, false, null, 0, 16)));

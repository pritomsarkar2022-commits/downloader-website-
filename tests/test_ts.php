<?php
declare(strict_types=1);
/** Usage: php tests/test_ts.php  (offline: demuxes tests/fixtures/*.ts) */
require __DIR__ . '/../lib/support.php';

// The fixtures are captures from real hosts, so they are not in the repository.
// Without them this file checks nothing, and a quiet pass would be a false claim.
if ((glob(__DIR__ . '/fixtures/*.ts') ?: []) === []) {
    fwrite(STDERR, "SKIPPED: tests/fixtures/*.ts are missing, so nothing was demuxed. See tests/fixtures/README.md\n");
    exit(3);
}

$fail = 0;
foreach (glob(__DIR__ . '/fixtures/*.ts') ?: [] as $file) {
    $name = basename($file, '.ts');
    $bytes = (string) file_get_contents($file);
    if ($name === 'luluvido') {
        $key = (string) file_get_contents(__DIR__ . '/fixtures/luluvido.key');
        $iv = pack('N', 1) . pack('N3', 0, 0, 0);
        $plain = openssl_decrypt($bytes, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false || $plain[0] !== "\x47") {
            echo "[FAIL] $name decrypt\n";
            $fail++;
            continue;
        }
        $bytes = $plain;
    }
    $d = new TsDemuxer();
    $perKind = ['video' => 0, 'audio' => 0];
    $ptsMin = PHP_INT_MAX;
    $ptsMax = 0;
    $d->demux($bytes, static function (int $pid, string $kind, ?int $pts, ?int $dts, string $body) use (&$perKind, &$ptsMin, &$ptsMax): void {
        $perKind[$kind] = ($perKind[$kind] ?? 0) + 1;
        if ($kind === 'video' && $pts !== null) {
            $ptsMin = min($ptsMin, $pts);
            $ptsMax = max($ptsMax, $pts);
        }
    });
    $d->flush(static function (): void {
    });
    printf(
        "%-11s size %8d  mod188 %3d  videoPid %-5s audioPid %-5s pes %5d  v=%-5d a=%-5d bad=%d  pts span %.3fs\n",
        $name,
        strlen($bytes),
        strlen($bytes) % 188,
        var_export($d->videoPid, true),
        var_export($d->audioPid, true),
        $d->pesCount,
        $perKind['video'] ?? 0,
        $perKind['audio'] ?? 0,
        $d->badPackets,
        ($ptsMax - $ptsMin) / 90000
    );
    foreach ($d->streamType as $pid => $type) {
        printf("      pid %-6d type 0x%02X  %s\n", $pid, $type, $d->pidKind[$pid] ?? '-');
    }
    if ($d->videoPid === null || ($perKind['video'] ?? 0) < 10) {
        echo "      [FAIL] no video\n";
        $fail++;
    }
}
exit($fail === 0 ? 0 : 1);

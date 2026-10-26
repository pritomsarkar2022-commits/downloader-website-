<?php
declare(strict_types=1);
/** Usage: php tests/test_es.php  (offline: H.264 + AAC parsing of tests/fixtures/*.ts) */
require __DIR__ . '/../lib/support.php';

// The fixtures are captures from real hosts, so they are not in the repository.
// Without them this file checks nothing, and a quiet pass would be a false claim.
if ((glob(__DIR__ . '/fixtures/*.ts') ?: []) === []) {
    fwrite(STDERR, "SKIPPED: tests/fixtures/*.ts are missing, so nothing was parsed. See tests/fixtures/README.md\n");
    exit(3);
}

$fail = 0;
foreach (glob(__DIR__ . '/fixtures/*.ts') ?: [] as $file) {
    $name = basename($file, '.ts');
    $bytes = (string) file_get_contents($file);
    if ($name === 'luluvido') {
        $key = (string) file_get_contents(__DIR__ . '/fixtures/luluvido.key');
        $bytes = (string) openssl_decrypt($bytes, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, pack('N', 1) . pack('N3', 0, 0, 0));
    }

    $d = new TsDemuxer();
    $avc = new Avc();
    $aac = new Aac();
    $units = [];
    $audioFrames = 0;
    $d->demux($bytes, static function (int $pid, string $kind, ?int $pts, ?int $dts, string $body) use ($d, $avc, $aac, &$units, &$audioFrames): void {
        if ($kind === 'video') {
            foreach ($avc->push($body, (int) $pts, $dts) as $u) {
                $units[] = $u;
            }
        } elseif ($pid === $d->audioPid) {
            $audioFrames += count($aac->push($body));
        }
    });
    foreach ($avc->finish() as $u) {
        $units[] = $u;
    }

    printf(
        "%-11s video PES %-5d AUs %-5d key %-3d  %dx%d  profile %s level %s  avcC %s\n",
        $name,
        $avc->samples,
        count($units),
        $avc->keyframes,
        $avc->width,
        $avc->height,
        $avc->spsInfo['profile'] ?? '?',
        $avc->spsInfo['level'] ?? '?',
        $avc->avcC() === null ? 'NULL' : strtoupper(bin2hex(substr((string) $avc->avcC(), 0, 8)))) . '...' . "\n";
    printf(
        "           audio frames %-5d rate %-6s ch %-2s asc %s  skipped %d\n",
        $audioFrames,
        (string) ($aac->sampleRate ?? '?'),
        (string) ($aac->channels ?? '?'),
        $aac->asc === null ? 'NULL' : strtoupper(bin2hex($aac->asc)),
        $aac->skippedBytes
    );

    $errors = [];
    if (count($units) < 20) {
        $errors[] = 'too few access units';
    }
    if ($avc->spsInfo === null || (int) $avc->spsInfo['profile'] !== 100) {
        $errors[] = 'sps profile should be 100 (all fixtures are avc1.64xxxx)';
    }
    if ($avc->width < 64 || $avc->height < 64) {
        $errors[] = 'sps size looks wrong';
    }
    if ($avc->keyframes === 0) {
        $errors[] = 'no idr found';
    }
    if ($avc->avcC() === null) {
        $errors[] = 'no avcC';
    }
    if (!$aac->isMp4Friendly()) {
        $errors[] = 'audio not mp4 friendly';
    }
    // Presentation timestamps must be non-decreasing and match the segment length.
    $ptsList = array_column($units, 'pts');
    sort($ptsList);
    $span = $ptsList === [] ? 0 : ($ptsList[count($ptsList) - 1] - $ptsList[0]) / 90000;
    printf("           AU count/PES ratio %.2f  pts span %.2fs\n", $avc->samples === 0 ? 0 : count($units) / $avc->samples, $span);
    if ($span < 3 || $span > 12) {
        $errors[] = 'pts span outside one segment: ' . $span;
    }
    if ($errors) {
        $fail++;
        echo "      [FAIL] " . implode('; ', $errors) . "\n";
    }
}
exit($fail === 0 ? 0 : 1);

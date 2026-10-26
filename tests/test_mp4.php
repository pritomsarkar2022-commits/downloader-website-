<?php
declare(strict_types=1);
/**
 * Usage: php tests/test_mp4.php
 * Offline end to end: fixture ts -> demux -> mux -> mp4, then read the mp4 boxes back
 * and prove the sample tables point at real data. Writes tests/out/<name>.mp4, which
 * tests/out/preview.html plays in a browser.
 */
require __DIR__ . '/../lib/support.php';

// php tests/test_mp4.php [fixture] [dropLastVideoAus]
$only = $argv[1] ?? null;
$dropLast = (int) ($argv[2] ?? 0);

// The fixtures are captures from real hosts, so they are not in the repository.
// Without them this file muxes nothing, and a quiet pass would be a false claim.
if (!is_file(__DIR__ . '/fixtures/streamfile.ts')
    || !is_file(__DIR__ . '/fixtures/vidara.ts')
    || !is_file(__DIR__ . '/fixtures/luluvido.ts')) {
    fwrite(STDERR, "SKIPPED: tests/fixtures/*.ts are missing, so nothing was remuxed. See tests/fixtures/README.md\n");
    exit(3);
}

@mkdir(__DIR__ . '/out', 0775, true);

/** Minimal ISO-BMFF walker: containers recurse, leaves keep their payload. */
function boxes(string $data, int $from, int $to): array
{
    $out = [];
    $pos = $from;
    while ($pos + 8 <= $to) {
        $size = unpack('N', substr($data, $pos, 4))[1];
        $type = substr($data, $pos + 4, 4);
        if ($size < 8 || $pos + $size > $to) {
            break;
        }
        $node = ['at' => $pos, 'size' => $size, 'type' => $type];
        if (in_array($type, ['moov', 'trak', 'mdia', 'minf', 'stbl', 'dinf', 'edts', 'mvex'], true)) {
            $node['children'] = boxes($data, $pos + 8, $pos + $size);
        } else {
            $node['payload'] = substr($data, $pos + 8, $size - 8);
        }
        $out[$type . '#' . count($out)] = $node;
        $pos += $size;
    }
    return $out;
}

/** Accepts either a box list or a single box. */
function find(array $tree, string $name): ?array
{
    if (isset($tree['type'])) {
        $tree = [$tree];
    }
    foreach ($tree as $node) {
        if ($node['type'] === $name) {
            return $node;
        }
        if (isset($node['children'])) {
            $hit = find($node['children'], $name);
            if ($hit !== null) {
                return $hit;
            }
        }
    }
    return null;
}

function allOf(array $tree, string $name): array
{
    if (isset($tree['type'])) {
        $tree = [$tree];
    }
    $hits = [];
    foreach ($tree as $node) {
        if ($node['type'] === $name) {
            $hits[] = $node;
        }
        if (isset($node['children'])) {
            $hits = array_merge($hits, allOf($node['children'], $name));
        }
    }
    return $hits;
}

function u32(string $s, int $i): int
{
    return strlen($s) < $i + 4 ? 0 : unpack('N', substr($s, $i, 4))[1];
}

function u16(string $s, int $i): int
{
    return strlen($s) < $i + 2 ? 0 : unpack('n', substr($s, $i, 2))[1];
}

/** Full-box payload: bytes 0..3 are version+flags, so every real field starts at 4. */
function entryCount(string $payload): int
{
    return u32($payload, 4);
}

$fail = 0;
foreach (['streamfile', 'vidara', 'luluvido'] as $name) {
    if ($only !== null && $only !== $name && $only !== 'all') {
        continue;
    }
    $tsPath = __DIR__ . '/fixtures/' . $name . '.ts';
    if (!is_file($tsPath)) {
        continue;
    }
    $bytes = (string) file_get_contents($tsPath);
    $key = __DIR__ . '/fixtures/' . $name . '.key';
    if ($name === 'luluvido' && is_file($key)) {
        // No IV attribute in the playlist, so the IV is the 128-bit big-endian media
        // sequence number and this fixture is segment 1.
        $bytes = (string) openssl_decrypt($bytes, 'aes-128-cbc', (string) file_get_contents($key), OPENSSL_RAW_DATA, str_repeat("\x00", 15) . "\x01");
    }

    $d = new TsDemuxer();
    $avc = new Avc();
    $aac = new Aac();
    $openPts = null;
    $videoUnits = [];
    $audioUnits = [];
    $d->demux($bytes, static function (int $pid, string $kind, ?int $pts, ?int $dts, string $body) use ($d, $avc, $aac, &$videoUnits, &$audioUnits, &$openPts): void {
        $openPts ??= $pts;
        if ($kind === 'video') {
            foreach ($avc->push($body, (int) $pts, $dts) as $u) {
                $videoUnits[] = [
                    'data' => Avc::toAvcc($u['nalus']),
                    'pts' => (int) $u['pts'],
                    'dts' => (int) $u['dts'],
                    'key' => (bool) $u['key'],
                ];
            }
        } elseif ($pid === $d->audioPid) {
            foreach ($aac->push($body) as $f) {
                $audioUnits[] = ['data' => $f['data'], 'pts' => (int) ($openPts ?? 0)];
            }
        }
    });
    foreach ($avc->finish() as $u) {
        $videoUnits[] = ['data' => Avc::toAvcc($u['nalus']), 'pts' => (int) $u['pts'], 'dts' => (int) $u['dts'], 'key' => (bool) $u['key']];
    }

    $mp4Path = __DIR__ . '/out/' . $name . ($dropLast > 0 ? '-drop' . $dropLast : '') . '.mp4';
    if ($dropLast > 0) {
        $videoUnits = array_slice($videoUnits, 0, count($videoUnits) - $dropLast);
    }
    $logPath = $mp4Path . '.log';
    $mux = Mp4Mux::open($mp4Path, $logPath);
    $mux->addSegment($videoUnits, $audioUnits);
    $mux->writeMoov($logPath, [
        'width' => $avc->width,
        'height' => $avc->height,
        'avcC' => (string) $avc->avcC(),
        'sampleRate' => $aac->sampleRate,
        'channels' => $aac->channels,
        'asc' => $aac->asc,
    ]);
    $mux->close();

    $data = (string) file_get_contents($mp4Path);
    $top = boxes($data, 0, strlen($data));
    $errors = [];
    $mdats = allOf($top, 'mdat');
    if (find($top, 'ftyp') === null) {
        $errors[] = 'no ftyp';
    }
    $moov = find($top, 'moov');
    if ($moov === null) {
        $errors[] = 'no moov';
        echo "      [FAIL] $name: no moov\n";
        $fail++;
        continue;
    }
    $traks = allOf($moov, 'trak');
    if (count($traks) !== 2) {
        $errors[] = 'expected 2 traks, got ' . count($traks);
    }
    $mvhd = (string) (find($moov, 'mvhd')['payload'] ?? '');
    $movieTs = u32($mvhd, 12);
    $movieDur = u32($mvhd, 16);

    $stsdWidths = [];
    foreach ($traks as $trak) {
        $kids = $trak['children'] ?? [];
        $hdlr = substr((string) (find($kids, 'hdlr')['payload'] ?? ''), 8, 4);
        $stbl = find($kids, 'stbl')['children'] ?? [];
        $stsz = (string) (find($stbl, 'stsz')['payload'] ?? '');
        $stco = (string) (find($stbl, 'stco')['payload'] ?? '');
        $stsc = (string) (find($stbl, 'stsc')['payload'] ?? '');
        $stts = (string) (find($stbl, 'stts')['payload'] ?? '');

        $count = u32($stsz, 8);
        $sizes = [];
        for ($s = 0; $s < $count; $s++) {
            $sizes[] = u32($stsz, 12 + $s * 4);
        }
        $expected = $hdlr === 'vide' ? count($videoUnits) : count($audioUnits);
        if ($count !== $expected) {
            $errors[] = "$hdlr stsz count $count != $expected";
        }
        if (array_sum($sizes) <= 0) {
            $errors[] = "$hdlr stsz sums to zero";
        }

        $chunkCount = entryCount($stco);
        $scEntries = entryCount($stsc);
        // A table whose payload does not match its entry count is what makes a
        // player feed the decoder garbage, so check the exact byte layout here.
        foreach ([['stts', $stts, $scEntries, 8], ['ctts', (string) (find($stbl, 'ctts')['payload'] ?? ''), 0, 8]] as $t) {
            if ($t[1] !== '') {
                $entries = entryCount($t[1]);
                if (strlen($t[1]) !== 8 + $entries * $t[3]) {
                    $errors[] = sprintf('%s declares %d entries but holds %d bytes', $t[0], $entries, strlen($t[1]) - 8);
                }
            }
        }
        foreach ([['stsc', $stsc, 12], ['stco', $stco, 4]] as $t) {
            $entries = entryCount($t[1]);
            if (strlen($t[1]) !== 8 + $entries * $t[2]) {
                $errors[] = sprintf('%s declares %d entries but holds %d bytes', $t[0], $entries, strlen($t[1]) - 8);
            }
        }
        if (strlen($stsz) !== 12 + $count * 4) {
            $errors[] = sprintf('stsz declares %d sizes but holds %d bytes', $count, strlen($stsz) - 12);
        }
        $perChunk = [];
        $lastFirst = 0;
        for ($e = 0; $e < $scEntries; $e++) {
            $firstChunk = u32($stsc, 8 + $e * 12);
            $samplesPer = u32($stsc, 12 + $e * 12);
            if ($firstChunk <= $lastFirst) {
                $errors[] = "$hdlr stsc chunks are not increasing";
                break;
            }
            $lastFirst = $firstChunk;
            $until = $e + 1 < $scEntries ? min(u32($stsc, 8 + ($e + 1) * 12), $chunkCount + 1) : $chunkCount + 1;
            for ($c = $firstChunk; $c < $until; $c++) {
                $perChunk[$c] = $samplesPer;
            }
        }
        for ($c = 1; $c <= $chunkCount; $c++) {
            $perChunk[$c] ??= 1;
        }
        $order = [];
        for ($c = 1; $c <= $chunkCount; $c++) {
            for ($k = 0; $k < $perChunk[$c]; $k++) {
                $order[] = $c;
            }
        }
        if ($order === [] && $count > 0) {
            $errors[] = "$hdlr stsc maps no samples";
        }
        if (count($order) !== $count) {
            $errors[] = "$hdlr stsc maps " . count($order) . " samples, stsz says $count";
        }

        // every chunk offset must sit inside an mdat and hold the declared bytes
        $sampleIdx = 0;
        $chunkSamples = [];
        foreach ($order as $chunkNo) {
            $chunkSamples[$chunkNo] = ($chunkSamples[$chunkNo] ?? 0) + 1;
        }
        $cursor = 0;
        for ($c = 1; $c <= $chunkCount; $c++) {
            $off = u32($stco, 8 + ($c - 1) * 4);
            $inside = false;
            foreach ($mdats as $m) {
                if ($off >= $m['at'] + 8 && $off + 16 <= $m['at'] + $m['size']) {
                    $inside = true;
                }
            }
            if (!$inside) {
                $errors[] = "$hdlr chunk $c offset $off is outside every mdat";
                break;
            }
            $chunkBytes = 0;
            for ($k = 0; $k < ($chunkSamples[$c] ?? 1); $k++) {
                $size = $sizes[$sampleIdx + $k] ?? 0;
                $chunkBytes += $size;
            }
            $sampleIdx += $chunkSamples[$c] ?? 1;
            if ($off + $chunkBytes > strlen($data)) {
                $errors[] = "$hdlr chunk $c runs past end of file";
                break;
            }
            if ($hdlr === 'vide') {
                $nalLen = u32($data, $off);
                $type = ord($data[$off + 4]) & 0x1F;
                if ($nalLen === 0 || $nalLen > $chunkBytes || !in_array($type, [1, 5, 6, 7, 8, 9, 12, 13, 14, 15, 20, 21], true)) {
                    $errors[] = "$hdlr chunk $c starts with nal length $nalLen type $type (chunk $chunkBytes bytes)";
                }
            } elseif ($data[$off] === "\xFF" && (ord($data[$off + 1]) & 0xF0) === 0xF0) {
                $errors[] = "$hdlr chunk $c still carries an ADTS header";
            }
            $cursor += $chunkBytes;
        }
        if ($sampleIdx !== $count) {
            $errors[] = "$hdlr walked $sampleIdx samples, stsz says $count";
        }

        $sttsEntries = entryCount($stts);
        $dur = 0;
        $runSamples = 0;
        for ($e = 0; $e < $sttsEntries; $e++) {
            $n = u32($stts, 8 + $e * 8);
            $delta = u32($stts, 12 + $e * 8);
            $runSamples += $n;
            $dur += $n * $delta;
        }
        if ($runSamples !== $count) {
            $errors[] = "$hdlr stts covers $runSamples samples, stsz says $count";
        }
        $mdhd = (string) (find($kids, 'mdhd')['payload'] ?? '');
        $trackTs = u32($mdhd, 12);
        $seconds = $dur / max(1, $trackTs);
        printf("%-11s %s: samples %-5d chunks %-4d stsz_total %-9d duration %.3fs timescale %d\n", $name, $hdlr, $count, $chunkCount, array_sum($sizes), $seconds, $trackTs);

        $stss = find($stbl, 'stss');
        if ($hdlr === 'soun') {
            // The object descriptor framework tags its lengths in single bytes here, so
            // a wrong nesting shows up as a length that does not match the bytes left.
            // The descriptor sits inside the mp4a entry, which sits inside stsd, so
            // walk from the first entry's children rather than the trak tree.
            $stsdForEsds = (string) (find($kids, 'stsd')['payload'] ?? '');
            $esdsNode = boxes($stsdForEsds, 44, strlen($stsdForEsds))['esds#0'] ?? null;
            $b = $esdsNode === null ? '' : substr((string) $esdsNode['payload'], 4);
            if ($esdsNode === null) {
                $errors[] = 'no esds inside the mp4a entry';
            } elseif (strlen($b) < 2 || ord($b[0]) !== 0x03) {
                $errors[] = 'esds does not open with an ES_Descriptor';
            } else {
                $esLen = ord($b[1]);
                $es = substr($b, 2, $esLen);
                if (strlen($es) !== $esLen) {
                    $errors[] = "ES_Descriptor says $esLen bytes, only " . strlen($es) . " follow";
                }
                $p = 3;                       // es_id(2) + flags(1)
                if (ord((string) substr($es, $p, 1)) !== 0x04) {
                    $errors[] = 'no DecoderConfigDescriptor where expected';
                } else {
                    $dcdLen = ord(substr($es, $p + 1, 1));
                    $dcd = substr($es, $p + 2, $dcdLen);
                    if (strlen($dcd) !== $dcdLen) {
                        $errors[] = "DecoderConfigDescriptor says $dcdLen bytes, only " . strlen($dcd) . ' follow';
                    }
                    if (ord(substr($dcd, 0, 1)) !== 0x40) {
                        $errors[] = 'objectTypeIndication is not AAC';
                    }
                    if ((ord(substr($dcd, 1, 1)) & 0x3F) !== 0x05) {
                        $errors[] = 'streamType is not audio';
                    }
                    if (strlen($dcd) > 13 && ord(substr($dcd, 13, 1)) !== 0x05) {
                        $errors[] = 'no DecSpecificInfo tag at the end of DecoderConfigDescriptor';
                    }
                    // AudioSpecificConfig: 5 type, 4 rate, 4 channels, so the channel
                    // configuration ends at byte1 bit3. A wrong shift means the decoder
                    // is configured for another layout and rejects every packet.
                    $ascLen = ord(substr($dcd, 14, 1));
                    $asc = substr($dcd, 15, $ascLen);
                    if (strlen($asc) < 2 || (((ord($asc[1]) >> 3) & 0x0F) !== (int) $aac->channels)) {
                        $errors[] = 'AudioSpecificConfig channel bits are ' . strlen($asc) . 'B/'
                            . (strlen($asc) > 1 ? ((ord($asc[1]) >> 3) & 0x0F) : '?') . ', stream has ' . $aac->channels;
                    }
                    $slPos = $p + 2 + $dcdLen;
                    if (ord(substr($es, $slPos, 1)) !== 0x06 || ord(substr($es, $slPos + 2, 1)) !== 0x02) {
                        $errors[] = 'SLConfigDescriptor is not a sibling of DecoderConfigDescriptor';
                    }
                }
            }
            // stsd payload: ver/flags, entry_count, then the mp4a entry whose
            // channelcount sits at entry offset 24 and samplerate at 32.
            $stsd = (string) (find($kids, 'stsd')['payload'] ?? '');
            if (u16($stsd, 8 + 24) !== (int) $aac->channels) {
                $errors[] = 'mp4a channel count does not match the stream';
            }
            if (u32($stsd, 8 + 32) !== ((int) $aac->sampleRate << 16)) {
                $errors[] = 'mp4a samplerate is not the stream rate in 16.16 fixed point';
            }
            if (substr($stsd, 12, 4) !== 'mp4a') {
                $errors[] = 'audio sample entry is not mp4a';
            }
        }
        if ($hdlr === 'vide') {
            $keyCount = $stss === null ? 0 : entryCount((string) $stss['payload']);
            $expectedKeys = 0;
            foreach ($videoUnits as $u) {
                $expectedKeys += $u['key'] ? 1 : 0;
            }
            if ($keyCount !== $expectedKeys) {
                $errors[] = "vide stss lists $keyCount keyframes, demux found $expectedKeys";
            }
            // stsd: version+flags, entry_count, then the avc1 entry (size, fourcc, 8-byte
            // sample-entry header, 16 bytes of pre-defined) so width lands at payload 40.
            $stsd = (string) (find($kids, 'stsd')['payload'] ?? '');
            $wid = u16($stsd, 40);
            $hei = u16($stsd, 42);
            if ($wid !== (int) $avc->width || $hei !== (int) $avc->height) {
                $errors[] = "vide stsd says {$wid}x{$hei}, sps says {$avc->width}x{$avc->height}";
            }
            if (abs($seconds - 9.98) > 3.0 && $name === 'streamfile') {
                $errors[] = 'video duration far from the 9.98s segment';
            }
        }
        if ($seconds <= 0) {
            $errors[] = "$hdlr has no duration";
        }
        $ctts = find($stbl, 'ctts');
        if ($hdlr === 'vide' && $ctts !== null) {
            $cttsEntries = entryCount((string) $ctts['payload']);
            $ctsSamples = 0;
            for ($e = 0; $e < $cttsEntries; $e++) {
                $ctsSamples += u32((string) $ctts['payload'], 8 + $e * 8);
            }
            if ($ctsSamples !== $count) {
                $errors[] = "vide ctts covers $ctsSamples samples, stsz says $count";
            }
        }
    }
    printf("%-11s mvhd timescale %d duration %d ms, tracks %d, mdats %d\n", $name, $movieTs, $movieDur, count($traks), count($mdats));
    if ($movieTs !== 1000) {
        $errors[] = 'mvhd timescale should be 1000, got ' . $movieTs;
    }
    if ($movieDur <= 0 || $movieDur > 600000) {
        $errors[] = 'movie duration ' . $movieDur . ' ms is implausible';
    }

    if ($errors) {
        $fail++;
        foreach ($errors as $e) {
            echo "      [FAIL] $e\n";
        }
    } else {
        printf("[PASS] %-11s %s  %.2f MB -> %d video + %d audio samples\n", $name, $mp4Path, filesize($mp4Path) / 1048576, count($videoUnits), count($audioUnits));
    }
    @unlink($logPath);
}
exit($fail === 0 ? 0 : 1);

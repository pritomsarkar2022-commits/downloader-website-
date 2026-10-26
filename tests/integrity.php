<?php
declare(strict_types=1);
/**
 * Whole-file integrity proof. A 200 response or a first few kilobytes are not a
 * download, so this fetches the entire file through the relay and then reads the
 * finished mp4 back: byte count against Content-Length, box table tiling the file
 * exactly, and a mid-file range request that must match the same bytes offline.
 *
 *   php tests/integrity.php <base> <page-url>      analyse, download, verify
 *   php tests/integrity.php --file tests/out/x.mp4 [expected-bytes]
 */
require __DIR__ . '/../lib/support.php';

$fail = 0;
function check(string $what, bool $yes, string $detail = ''): void
{
    global $fail;
    if (!$yes) {
        $fail++;
        echo "  [FAIL] $what" . ($detail !== '' ? "  ($detail)" : '') . "\n";
        return;
    }
    echo "  [ok]   $what" . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

/** Stream the file so a 700 MB download never has to fit in memory. */
function topLevelBoxes(string $path): array
{
    $size = (int) filesize($path);
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('cannot open ' . $path);
    }
    $boxes = [];
    $pos = 0;
    while ($pos + 8 <= $size) {
        fseek($fh, $pos);
        $head = (string) fread($fh, 8);
        $len = unpack('N', substr($head, 0, 4))[1];
        $type = substr($head, 4, 4);
        if (!preg_match('/^[\x20-\x7e]{4}$/', $type)) {
            break;
        }
        $headerBytes = 8;
        if ($len === 1) {
            $ext = (string) fread($fh, 8);
            $un = unpack('J', $ext);
            $len = is_array($un) ? (int) $un[1] : 0;
            $headerBytes = 16;
        }
        if ($len < $headerBytes || $pos + $len > $size) {
            $boxes[] = ['type' => $type, 'at' => $pos, 'size' => $len, 'broken' => true];
            break;
        }
        $boxes[] = ['type' => $type, 'at' => $pos, 'size' => $len, 'broken' => false];
        $pos += $len;
    }
    fclose($fh);
    return $boxes;
}

function download(string $url, string $to, ?string $range = null): array
{
    $fh = fopen($to, 'wb');
    $ch = curl_init($url);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 900,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            } elseif (preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $line, $m)) {
                $headers[':status'] = (int) $m[1];
            }
            return strlen($line);
        },
    ]);
    if ($range !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Range: ' . $range]);
    }
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $got = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    fclose($fh);
    return ['ok' => $ok !== false, 'err' => $err, 'bytes' => $got, 'headers' => $headers];
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
    $body = (string) curl_exec($ch);
    curl_close($ch);
    $json = json_decode($body, true);
    return is_array($json) ? $json : [];
}

@mkdir(__DIR__ . '/out', 0775, true);

if (($argv[1] ?? '') === '--file') {
    $file = (string) ($argv[2] ?? '');
    $expect = isset($argv[3]) ? (int) $argv[3] : null;
    $source = null;
    $url = '';
} else {
    $base = rtrim((string) ($argv[1] ?? 'http://127.0.0.1:8085/index.php'), '/');
    $page = (string) ($argv[2] ?? '');
    if ($page === '') {
        fwrite(STDERR, "usage: php tests/integrity.php <base> <page-url> | --file <path> [bytes]\n");
        exit(2);
    }
    $result = post($base . '?r=analyze', ['url' => $page]);
    if (empty($result['ok']) || empty($result['sources'])) {
        fwrite(STDERR, 'analyze refused: ' . ($result['error'] ?? 'no response') . "\n");
        exit(1);
    }
    // Prefer a real file over a stream, and the tallest one when several exist.
    $sources = $result['sources'];
    usort($sources, static function (array $a, array $b): int {
        $rank = static fn(array $s): int => ($s['kind'] ?? '') === 'mp4' ? 2 : (($s['kind'] ?? '') === 'webm' ? 1 : 0);
        return $rank($b) <=> $rank($a) ?: (int) ($b['height'] ?? 0) <=> (int) ($a['height'] ?? 0);
    });
    $source = $sources[0];
    $url = (string) ($source['downloadUrl'] ?? '');
    if ($url === '') {
        fwrite(STDERR, "source {$source['id']} carries no download link\n");
        exit(1);
    }
    $ext = ($source['kind'] ?? 'mp4') === 'webm' ? 'webm' : 'mp4';
    $file = __DIR__ . '/out/integrity-' . preg_replace('/[^a-z0-9]+/i', '', (string) (parse_url($page, PHP_URL_HOST) ?: 'host')) . '.' . $ext;
    $expect = isset($source['sizeBytes']) && $source['sizeBytes'] > 0 ? (int) $source['sizeBytes'] : null;
}

if (!is_file($file) || filesize($file) === 0) {
    if (!isset($url) || $url === '') {
        fwrite(STDERR, "no such file: $file\n");
        exit(1);
    }
    echo "== downloading the whole file\n";
    $r = download($url, $file);
    check('transfer completed', $r['ok'], $r['err']);
    $status = (int) ($r['headers'][':status'] ?? 0);
    check('relay answered 200 for the full file', $status === 200, "http $status");
    check('the whole file was sent, not a range', !isset($r['headers']['content-range']), (string) ($r['headers']['content-range'] ?? 'none'));
    $cl = isset($r['headers']['content-length']) ? (int) $r['headers']['content-length'] : null;
    $onDisk = (int) filesize($file);
    check('bytes written equal Content-Length', $cl === null || $cl === $onDisk, ($cl ?? '-') . ' vs ' . $onDisk);
    if ($expect !== null) {
        check('bytes equal the size the analyser reported', abs($onDisk - $expect) <= 1, $expect . ' vs ' . $onDisk);
    }
    $disposition = strtolower((string) ($r['headers']['content-disposition'] ?? ''));
    check('served as an attachment', str_contains($disposition, 'attachment;'), $disposition ?: 'missing');
    check('download name has a media extension', (bool) preg_match('~filename="?[^"\r\n]*\.(mp4|webm|mkv|ts)"?~i', $disposition), $disposition ?: 'missing');
    $ct = strtolower((string) ($r['headers']['content-type'] ?? ''));
    check('content type is a media type', (bool) preg_match('~(video/|octet-stream|mpegurl)~', $ct), $ct ?: 'missing');
    echo "  saved $file (" . human_bytes($onDisk) . ")\n";
} else {
    $onDisk = (int) filesize($file);
    echo "== verifying $file (" . human_bytes($onDisk) . ")\n";
    if ($expect !== null) {
        check('file size equals the expected byte count', abs($onDisk - $expect) <= 1, $expect . ' vs ' . $onDisk);
    }
}

$onDisk ??= (int) filesize($file);
$head = (string) file_get_contents($file, false, null, 0, 16);
$isMp4 = substr($head, 4, 4) === 'ftyp';
$isWebm = substr($head, 0, 4) === "\x1a\x4f\xdf\xa3";
check('file starts with a real container header', $isMp4 || $isWebm, strtoupper(bin2hex(substr($head, 0, 12))));

if ($isMp4) {
    $boxes = topLevelBoxes($file);
    $types = array_column($boxes, 'type');
    $covered = array_sum(array_column($boxes, 'size'));
    $broken = array_values(array_filter($boxes, static fn(array $b): bool => $b['broken']));
    echo '  boxes: ' . implode(' ', $types) . "\n";
    check('every box parses', $broken === [], $broken ? $broken[0]['type'] . ' at ' . $broken[0]['at'] : '');
    check('the box table ends exactly at the last byte', $covered === $onDisk, $covered . ' vs ' . $onDisk);
    check('ftyp is the first box', ($types[0] ?? '') === 'ftyp');
    check('moov is present', in_array('moov', $types, true));
    check('mdat is present', in_array('mdat', $types, true));
    $moovAt = 0;
    $mdatBytes = 0;
    foreach ($boxes as $b) {
        if ($b['type'] === 'moov') {
            $moovAt = $b['at'];
        }
        if ($b['type'] === 'mdat') {
            $mdatBytes += $b['size'];
        }
    }
    check('media data holds most of the file', $mdatBytes > 0 && $mdatBytes >= (int) ($onDisk * 0.5), human_bytes($mdatBytes));
    // A moov after the data is playable but not seekable-friendly; report it, do not fail.
    if ($moovAt > 0 && array_search('mdat', $types, true) !== false && $moovAt > $boxes[array_search('mdat', $types, true)]['at']) {
        echo "  note: moov sits after mdat, so a player must download the whole file before seeking\n";
    }

    // The sample table has to point at bytes that really exist inside an mdat.
    $fh = fopen($file, 'rb');
    $moovRaw = '';
    foreach ($boxes as $b) {
        if ($b['type'] === 'moov') {
            fseek($fh, $b['at'] + 8);
            $moovRaw = (string) fread($fh, min($b['size'] - 8, 20_000_000));
        }
    }
    fclose($fh);
    $leaf = static function (string $moof, string $name) use (&$leaf): array {
        $hits = [];
        $pos = 0;
        $to = strlen($moof);
        while ($pos + 8 <= $to) {
            $len = unpack('N', substr($moof, $pos, 4))[1];
            $type = substr($moof, $pos + 4, 4);
            if ($len < 8 || $pos + $len > $to) {
                break;
            }
            if ($type === $name) {
                $hits[] = substr($moof, $pos + 8, $len - 8);
            } elseif (in_array($type, ['moov', 'trak', 'mdia', 'minf', 'stbl', 'dinf', 'edts', 'mvex', 'udta', 'meta', 'ilst', 'mean', 'name'], true) || $type === 'stsd') {
                $hits = array_merge($hits, $leaf(substr($moof, $pos + 8, $len - 8), $name));
            }
            $pos += $len;
        }
        return $hits;
    };
    $stsz = $leaf($moovRaw, 'stsz');
    $stco = $leaf($moovRaw, 'stco');
    $mdats = [];
    foreach ($boxes as $b) {
        if ($b['type'] === 'mdat') {
            $mdats[] = [$b['at'] + 8, $b['at'] + $b['size']];
        }
    }
    $declaredSamples = 0;
    $stray = 0;
    $firstOffset = null;
    foreach ($stsz as $p) {
        $declaredSamples += max(0, unpack('N', substr($p, 8, 4))[1] ?? 0);
    }
    foreach ($stco as $p) {
        $n = unpack('N', substr($p, 4, 4))[1] ?? 0;
        for ($i = 0; $i < $n; $i++) {
            $off = unpack('N', substr($p, 8 + $i * 4, 4))[1] ?? 0;
            $firstOffset ??= $off;
            $inside = false;
            foreach ($mdats as [$from, $to]) {
                if ($off >= $from && $off <= $to) {
                    $inside = true;
                }
            }
            if (!$inside) {
                $stray++;
            }
        }
    }
    if ($stco && $stsz) {
        check('chunk offsets all land inside an mdat', $stray === 0, $stray . ' stray of ' . $declaredSamples . ' samples');
        check('the first chunk offset is inside the file', $firstOffset !== null && $firstOffset < $onDisk, (string) $firstOffset);
    }
}

// Range behaviour: a resume must give back the identical bytes.
$relayUrl = $url ?? '';
if ($relayUrl !== '') {
    $mid = intdiv(max(1, $onDisk - 2), 3);
    $len = min(65536, $onDisk - $mid);
    $r = download($relayUrl, __DIR__ . '/out/integrity-range.bin', 'bytes=' . $mid . '-' . ($mid + $len - 1));
    $status = (int) ($r['headers'][':status'] ?? 0);
    check('a mid-file range request is honoured', $status === 206, 'http ' . $status);
    check('the range is the requested length', (int) filesize(__DIR__ . '/out/integrity-range.bin') === $len, filesize(__DIR__ . '/out/integrity-range.bin') . ' vs ' . $len);
    $rangeBytes = (string) file_get_contents(__DIR__ . '/out/integrity-range.bin', false, null, 0, $len);
    $whole = (string) file_get_contents($file, false, null, $mid, $len);
    check('the relayed range equals the same bytes offline', $rangeBytes === $whole);
    @unlink(__DIR__ . '/out/integrity-range.bin');
}

echo $fail === 0 ? "\nINTEGRITY OK\n" : "\n$fail problem(s) found\n";
exit($fail === 0 ? 0 : 1);

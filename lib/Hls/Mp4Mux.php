<?php
declare(strict_types=1);

/**
 * MPEG-TS elementary streams -> one progressive MP4, written as it is read.
 *
 * Media is appended straight to disk as one mdat per segment, so memory stays flat
 * whatever the file size and the process can stop and resume between segments on a
 * shared host with a 30 second limit. Sample metadata goes to a sidecar log of fixed
 * 16-byte records, and the moov is assembled from that log after the last segment.
 *
 * Muxes H.264 + AAC-LC, which is what these hosts serve. Anything else is refused
 * with a message rather than producing a file that will not play.
 */
final class Mp4Mux
{
    public const VIDEO = 1;
    public const AUDIO = 2;

    private const REC_SAMPLE = 1;
    private const REC_CHUNK = 2;
    private const REC_BYTES = 16;
    private const TS = 90000;      // timestamps arrive on the 90 kHz TS clock
    private const MOVIE_TS = 1000; // mvhd timescale, so edit lists are in milliseconds

    /** @var resource */
    private $mp4;
    /** @var resource */
    private $log;
    private int $written = 0;

    private function __construct($mp4, $log, int $written)
    {
        $this->mp4 = $mp4;
        $this->log = $log;
        $this->written = $written;
    }

    public static function open(string $mp4Path, string $logPath, bool $resume = false): self
    {
        $mp4 = @fopen($mp4Path, $resume && is_file($mp4Path) ? 'ab' : 'wb');
        $log = @fopen($logPath, $resume && is_file($logPath) ? 'ab' : 'wb');
        if ($mp4 === false || $log === false) {
            throw new ExtractException('The server could not create the output file.', 'Storage is full or not writable.', 'io');
        }
        $written = $resume && is_file($mp4Path) ? (int) filesize($mp4Path) : 0;
        $m = new self($mp4, $log, $written);
        if (!$resume) {
            $m->append(self::box('ftyp', 'isom' . pack('N', 0x200) . 'isomiso2avc1mp42'));
        }
        return $m;
    }

    public function bytesWritten(): int
    {
        return $this->written;
    }

    public function close(): void
    {
        if (is_resource($this->mp4)) {
            fclose($this->mp4);
        }
        if (is_resource($this->log)) {
            fclose($this->log);
        }
    }

    /**
     * Append one segment.
     *
     * @param array<int,array{data:string,pts:int,dts:int,key:bool}> $video
     * @param array<int,array{data:string,pts:int}> $audio
     */
    public function addSegment(array $video, array $audio): void
    {
        $videoBytes = '';
        foreach ($video as $s) {
            $videoBytes .= $s['data'];
        }
        $audioBytes = '';
        foreach ($audio as $s) {
            $audioBytes .= $s['data'];
        }
        if ($videoBytes === '' && $audioBytes === '') {
            return;
        }
        $payload = $videoBytes . $audioBytes;
        $mediaAt = $this->written + 8;
        $this->append(pack('N', strlen($payload) + 8) . 'mdat' . $payload);

        foreach ($video as $s) {
            $this->sample(self::VIDEO, strlen((string) $s['data']), (int) $s['dts'], (int) $s['pts'], (bool) ($s['key'] ?? false));
        }
        foreach ($audio as $s) {
            $pts = (int) ($s['pts'] ?? 0);
            $this->sample(self::AUDIO, strlen((string) $s['data']), $pts, $pts, false);
        }
        if ($video !== []) {
            $this->chunk(self::VIDEO, count($video), $mediaAt);
        }
        if ($audio !== []) {
            $this->chunk(self::AUDIO, count($audio), $mediaAt + strlen($videoBytes));
        }
    }

    private function sample(int $track, int $size, int $dts, int $pts, bool $key): void
    {
        // 1 type + 1 track|flags + 4 size + 4 dts + 4 pts + 2 pad = 16
        fwrite($this->log, pack(
            'C2NNNxx',
            self::REC_SAMPLE,
            $track | ($key ? 0x80 : 0),
            $size,
            self::clampClock($dts),
            self::clampClock($pts)
        ));
    }

    private function chunk(int $track, int $count, int $offset): void
    {
        // 1 type + 1 track + 4 count + 8 offset + 2 pad = 16
        fwrite($this->log, pack('C2NP', self::REC_CHUNK, $track, $count, $offset) . "\x00\x00");
    }

    /** Media here is minutes long, so a wrapped 32-bit clock value stays monotonic enough. */
    private static function clampClock(int $t): int
    {
        return $t & 0xFFFFFFFF;
    }

    /**
     * Build and append moov from the sidecar log.
     *
     * @param array{width:int,height:int,avcC:string,sampleRate?:int|null,channels?:int|null,asc?:string|null} $p
     */
    public function writeMoov(string $logPath, array $p): void
    {
        $video = self::readLog($logPath, self::VIDEO);
        $audio = self::readLog($logPath, self::AUDIO);
        if ($video['samples'] === 0) {
            throw new ExtractException('That stream carried no video frames.', 'The stream file can still be saved.', 'no_video');
        }
        if (!isset($p['avcC']) || !is_string($p['avcC']) || $p['avcC'] === '') {
            throw new ExtractException('That stream never announced its H.264 parameters.', 'The stream file can still be saved.', 'no_sps');
        }
        if ($this->written > 0xFFFFFFF0) {
            throw new ExtractException('This file grew past 4 GB.', 'Pick a shorter clip or a lower quality.', 'too_big');
        }

        $base = $video['dts'][0];
        if ($audio['samples'] > 0) {
            $base = min($base, $audio['dts'][0]);
        }
        $hasAudio = $audio['samples'] > 0
            && isset($p['asc'], $p['sampleRate'], $p['channels'])
            && is_string($p['asc']) && $p['asc'] !== ''
            && (int) $p['sampleRate'] > 0;

        $tracks = [self::videoTrack($video, $base, $p)];
        if ($hasAudio) {
            $tracks[] = self::audioTrack($audio, $base, $p);
        }

        $movieDur = 0;
        $body = '';
        foreach ($tracks as $t) {
            $body .= $t['trak'];
            $movieDur = max($movieDur, $t['movie_duration']);
        }
        $mvhd = self::fullBox('mvhd', 0,
            pack('N2', time(), time())
            . pack('N', self::MOVIE_TS) . pack('N', $movieDur)
            . pack('N', 0x00010000) . pack('n2', 0x0100, 0) . str_repeat("\x00", 8)
            . self::matrix() . str_repeat("\x00", 24)
            . pack('N', count($tracks) + 1)
        );
        $this->append(self::box('moov', $mvhd . $body));
    }

    /**
     * @return array{samples:int,sizes:int[],dts:int[],pts:int[],key:int[],chunk_counts:int[],chunk_offsets:int[]}
     */
    private static function readLog(string $path, int $track): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new ExtractException('The build state disappeared.', 'Start the save again.', 'io');
        }
        $out = ['samples' => 0, 'sizes' => [], 'dts' => [], 'pts' => [], 'key' => [], 'chunk_counts' => [], 'chunk_offsets' => []];
        while (($rec = fread($fh, self::REC_BYTES)) !== false && strlen($rec) === self::REC_BYTES) {
            $head = ord($rec[0]);
            if ($head === self::REC_CHUNK) {
                $c = unpack('Ctype/Ctrack/Ncount/Poffset', substr($rec, 0, 14));
                if (is_array($c) && (int) $c['track'] === $track) {
                    $out['chunk_counts'][] = (int) $c['count'];
                    $out['chunk_offsets'][] = (int) $c['offset'];
                }
                continue;
            }
            if ($head !== self::REC_SAMPLE) {
                continue;
            }
            $s = unpack('Ctype/Cflags/Nsize/Ndts/Npts', $rec);
            if (!is_array($s) || ((int) $s['flags'] & 0x7F) !== $track) {
                continue;
            }
            $out['samples']++;
            $out['sizes'][] = (int) $s['size'];
            $out['dts'][] = (int) $s['dts'];
            $out['pts'][] = (int) $s['pts'];
            $out['key'][] = ((int) $s['flags'] & 0x80) !== 0 ? 1 : 0;
        }
        fclose($fh);
        return $out;
    }

    /** @param array<string,mixed> $p */
    private static function videoTrack(array $v, int $base, array $p): array
    {
        $n = $v['samples'];
        $deltas = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $d = self::diff($v['dts'][$i + 1], $v['dts'][$i]);
            $deltas[] = $d > 0 ? $d : 1;
        }
        $deltas[] = $n > 1 ? $deltas[$n - 2] : intdiv(self::TS, 25);
        $mediaDur = array_sum($deltas);

        $cts = [];
        for ($i = 0; $i < $n; $i++) {
            $cts[] = max(0, self::diff($v['pts'][$i], $v['dts'][$i]));
        }
        $sync = [];
        foreach ($v['key'] as $i => $k) {
            if ($k === 1) {
                $sync[] = $i + 1;
            }
        }

        $width = max(2, (int) ($p['width'] ?? 0));
        $height = max(2, (int) ($p['height'] ?? 0));
        // SampleEntry boxes are plain boxes: stsd is the only full box in this path.
        $avc1 = self::box('avc1',
            str_repeat("\x00", 6) . pack('n', 1)
            . pack('n2', 0, 0) . pack('N3', 0, 0, 0)
            . pack('n2', $width, $height)
            . pack('N2', 0x00480000, 0x00480000) . pack('N', 0)
            . pack('n', 1) . str_repeat("\x00", 32)
            . pack('n2', 24, 0xFFFF)
            . self::box('avcC', (string) $p['avcC'])
        );
        $stbl = self::stsd($avc1)
            . self::stts($deltas)
            . self::ctts($cts)
            . (count($sync) === $n || $sync === [] ? '' : self::stss($sync))
            . self::stsc($v['chunk_counts'])
            . self::stsz($v['sizes'])
            . self::stco($v['chunk_offsets']);

        $delayMs = max(0, (int) round(self::diff($v['pts'][0], $base) / self::TS * self::MOVIE_TS));
        $movieDur = $delayMs + (int) round($mediaDur / self::TS * self::MOVIE_TS);
        $trak = self::box('trak',
            self::tkhd(1, $movieDur, $width, $height, false)
            . self::edts($delayMs, $mediaDur, self::TS)
            . self::box('mdia',
                self::mdhd(self::TS, $mediaDur)
                . self::hdlr('vide', 'VideoHandler')
                . self::box('minf',
                    self::fullBox('vmhd', 1, pack('n', 0) . str_repeat("\x00", 6))
                    . self::dinf()
                    . self::box('stbl', $stbl)
                )
            )
        );
        return ['trak' => $trak, 'movie_duration' => $movieDur];
    }

    /** @param array<string,mixed> $p */
    private static function audioTrack(array $a, int $base, array $p): array
    {
        $rate = max(1, (int) $p['sampleRate']);
        $ch = max(1, (int) $p['channels']);
        $n = $a['samples'];
        // AAC frames are 1024 samples each, but a stream that loses a frame at a
        // segment boundary must show that gap in stts or the rest of the audio
        // slides ahead of the picture.
        $deltas = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $d = (int) round(self::diff($a['pts'][$i + 1], $a['pts'][$i]) / self::TS * $rate);
            $deltas[] = $d > 0 ? $d : 1024;
        }
        $deltas[] = 1024;
        $mediaDur = array_sum($deltas);

        $mp4a = self::box('mp4a',
            str_repeat("\x00", 6) . pack('n', 1)
            . pack('n2', 0, 0) . pack('N', 0)
            . pack('n', $ch) . pack('n', 16) . pack('n2', 0, 0)
            . pack('N', $rate << 16)
            . self::fullBox('esds', 0, self::esDescriptor((string) $p['asc'], $rate, $a['sizes']))
        );
        $stbl = self::stsd($mp4a)
            . self::stts($deltas)
            . self::stsc($a['chunk_counts'])
            . self::stsz($a['sizes'])
            . self::stco($a['chunk_offsets']);

        $delayMs = max(0, (int) round(self::diff($a['pts'][0], $base) / self::TS * self::MOVIE_TS));
        $movieDur = $delayMs + (int) round($mediaDur / $rate * self::MOVIE_TS);
        $trak = self::box('trak',
            self::tkhd(2, $movieDur, 0, 0, true)
            . self::edts($delayMs, $mediaDur, $rate)
            . self::box('mdia',
                self::mdhd($rate, $mediaDur)
                . self::hdlr('soun', 'SoundHandler')
                . self::box('minf',
                    self::fullBox('smhd', 0, pack('n2', 0, 0))
                    . self::dinf()
                    . self::box('stbl', $stbl)
                )
            )
        );
        return ['trak' => $trak, 'movie_duration' => $movieDur];
    }

    /** @param int[] $sizes */
    private static function esDescriptor(string $asc, int $rate, array $sizes): string
    {
        $frames = max(1, count($sizes));
        $avg = count($sizes) > 0 ? (int) (array_sum($sizes) / $frames * 8 * $rate / 1024) : 128000;
        $spec = chr(0x05) . chr(strlen($asc)) . $asc;
        // streamType 5 (audio) lives in the low 6 bits after two reserved bits, so the
        // byte is 0x05; the three bytes after it carry upStream, a reserved bit and the
        // 22-bit bufferSizeDB. The SL config descriptor is a sibling of this descriptor,
        // not part of it, so its bytes must not count toward the length below.
        $decCfg = chr(0x04) . chr(13 + strlen($spec))
            . chr(0x40)                                  // AAC
            . chr(0x05)                                  // audio stream, not upstream
            . substr(pack('N', 2048), 1)                 // bufferSizeDB, 22 bits
            . pack('N', $avg * 2)                        // maxBitrate
            . pack('N', max(1, $avg))                    // avgBitrate
            . $spec;
        $sl = chr(0x06) . chr(1) . chr(0x02);
        $body = pack('n', 1) . chr(0) . $decCfg . $sl;
        return chr(0x03) . chr(strlen($body)) . $body;
    }

    /** @param int[] $deltas */
    private static function stts(array $deltas): string
    {
        return self::sttsRun(self::runs($deltas));
    }

    /** @param array<int,array{0:int,1:int}> $runs [value, count] */
    private static function sttsRun(array $runs): string
    {
        $body = pack('N', count($runs));
        foreach ($runs as [$value, $count]) {
            $body .= pack('N2', $count, $value);
        }
        return self::fullBox('stts', 0, $body);
    }

    /** @param int[] $values @return array<int,array{0:int,1:int}> */
    private static function runs(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $last = $out === [] ? null : count($out) - 1;
            if ($last !== null && $out[$last][0] === $v) {
                $out[$last][1]++;
                continue;
            }
            $out[] = [$v, 1];
        }
        return $out;
    }

    /** @param int[] $offsets */
    private static function ctts(array $offsets): string
    {
        if ($offsets === [] || max($offsets) === 0) {
            return '';
        }
        $runs = self::runs($offsets);
        // entry_count is the number of runs, not the number of samples.
        $body = pack('N', count($runs));
        foreach ($runs as [$offset, $count]) {
            $body .= pack('N2', $count, $offset);
        }
        return self::fullBox('ctts', 0, $body);
    }

    /** @param int[] $indexes */
    private static function stss(array $indexes): string
    {
        $body = pack('N', count($indexes));
        foreach ($indexes as $i) {
            $body .= pack('N', $i);
        }
        return self::fullBox('stss', 0, $body);
    }

    /** @param int[] $sizes */
    private static function stsz(array $sizes): string
    {
        $body = pack('N', 0) . pack('N', count($sizes));
        foreach ($sizes as $s) {
            $body .= pack('N', (int) $s);
        }
        return self::fullBox('stsz', 0, $body);
    }

    /** @param int[] $counts samples per chunk, in chunk order */
    private static function stsc(array $counts): string
    {
        $entries = [];
        $prev = null;
        foreach ($counts as $i => $c) {
            if ($c !== $prev) {
                $entries[] = [$i + 1, $c];
                $prev = $c;
            }
        }
        $body = pack('N', count($entries));
        foreach ($entries as [$chunkNo, $per]) {
            $body .= pack('N3', $chunkNo, $per, 1);
        }
        return self::fullBox('stsc', 0, $body);
    }

    /** @param int[] $offsets */
    private static function stco(array $offsets): string
    {
        $body = pack('N', count($offsets));
        foreach ($offsets as $o) {
            $body .= pack('N', $o);
        }
        return self::fullBox('stco', 0, $body);
    }

    private static function stsd(string $entry): string
    {
        return self::fullBox('stsd', 0, pack('N', 1) . $entry);
    }

    private static function tkhd(int $id, int $movieDur, int $w, int $h, bool $audio): string
    {
        return self::fullBox('tkhd', 3,
            pack('N2', time(), time()) . pack('N', $id) . pack('N', 0) . pack('N', max(0, $movieDur))
            . str_repeat("\x00", 8)
            . pack('n2', 0, 0)
            . pack('n', $audio ? 0x0100 : 0) . pack('n', 0)
            . self::matrix()
            . pack('N2', $w << 16, $h << 16)
        );
    }

    private static function mdhd(int $timescale, int $dur): string
    {
        return self::fullBox('mdhd', 0,
            pack('N2', time(), time()) . pack('N', max(1, $timescale)) . pack('N', max(0, $dur))
            . pack('n2', 0x55C4, 0)
        );
    }

    private static function hdlr(string $type, string $name): string
    {
        return self::fullBox('hdlr', 0, pack('N', 0) . $type . str_repeat("\x00", 12) . $name . "\x00");
    }

    private static function dinf(): string
    {
        return self::box('dinf', self::fullBox('dref', 0, pack('N', 1) . self::fullBox('url ', 1, '')));
    }

    private static function edts(int $delayMs, int $mediaDur, int $trackTs): string
    {
        if ($delayMs <= 0) {
            return '';
        }
        $entries = pack('N2', $delayMs, 0xFFFFFFFF) . pack('n2', 1, 0)
            . pack('N2', (int) round($mediaDur / max(1, $trackTs) * self::MOVIE_TS), 0) . pack('n2', 1, 0);
        return self::box('edts', self::fullBox('elst', 0, pack('N', 2) . $entries));
    }

    private static function matrix(): string
    {
        return pack('N3', 0x00010000, 0, 0) . pack('N3', 0, 0x00010000, 0) . pack('N3', 0, 0, 0x40000000);
    }

    /** Signed difference between two wrapped 32-bit clock readings. */
    private static function diff(int $a, int $b): int
    {
        $d = $a - $b;
        if ($d >= 0x80000000) {
            $d -= 0x100000000;
        } elseif ($d < -0x80000000) {
            $d += 0x100000000;
        }
        return $d;
    }

    private function append(string $bytes): void
    {
        $n = fwrite($this->mp4, $bytes);
        if ($n !== strlen($bytes)) {
            throw new ExtractException('The server could not write the video.', 'Storage is full or not writable.', 'io');
        }
        $this->written += $n;
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', strlen($payload) + 8) . $type . $payload;
    }

    private static function fullBox(string $type, int $flags, string $payload): string
    {
        return self::box($type, pack('N', $flags) . $payload);
    }
}

<?php
declare(strict_types=1);

/**
 * MPEG-TS packet reader. Feeds whole 188-byte packets, rebuilds PES packets from
 * the payload units, and reports which pid carries which codec.
 *
 * One instance is reused across every segment of a stream, so a PES packet cut in
 * half by a segment boundary still comes out whole.
 */
final class TsDemuxer
{
    private const PKT = 188;

    private const VIDEO_TYPES = [0x01 => 'mpeg2', 0x02 => 'mpeg2', 0x1B => 'h264', 0x24 => 'hevc'];
    private const AUDIO_TYPES = [0x03 => 'mp1', 0x04 => 'mp2', 0x0F => 'aac2', 0x11 => 'aac1', 0x1F => 'aac4'];

    /** @var array<int,string> pid => payload under construction */
    private array $pending = [];
    /** @var array<int,array{pts:?int,dts:?int}> */
    private array $pendingMeta = [];
    /** @var array<int,int> pid => last continuity counter */
    private array $lastCc = [];

    /** @var array<int,int> pid => stream_type from the PMT */
    public array $streamType = [];
    /** @var array<int,string> pid => 'video'|'audio' */
    public array $pidKind = [];
    public ?int $videoPid = null;
    public ?int $audioPid = null;
    public ?int $pcrPid = null;
    private ?int $pmtPid = null;
    /** @var array<int,string> pid => why it was ignored */
    public array $skipped = [];

    public int $pesCount = 0;
    public int $badPackets = 0;

    /**
     * @param callable(int $pid, string $kind, ?int $pts, ?int $dts, string $pesBody): void $onPes
     */
    public function demux(string $bytes, callable $onPes): void
    {
        $len = strlen($bytes);
        $pos = self::findSync($bytes, 0, $len) ?? $len;
        while ($pos + self::PKT <= $len) {
            if ($bytes[$pos] !== "\x47") {
                $next = self::findSync($bytes, $pos, $len);
                if ($next === null) {
                    break;
                }
                $this->badPackets++;
                $pos = $next;
                continue;
            }
            $this->packet($bytes, $pos, $len, $onPes);
            $pos += self::PKT;
        }
    }

    /** Emit any PES packet left open when the stream ends. */
    public function flush(callable $onPes): void
    {
        foreach ($this->pending as $pid => $buf) {
            if ($buf !== '') {
                $this->emit((int) $pid, $buf, $this->pendingMeta[$pid] ?? [], $onPes);
            }
        }
        $this->pending = [];
        $this->pendingMeta = [];
    }

    /** True when the buffer starts with four packet sync bytes 188 bytes apart. */
    public static function looksLikeTs(string $b): bool
    {
        return strlen($b) >= 4 * self::PKT && self::findSync($b, 0, strlen($b)) !== null;
    }

    /** Absolute index of the first packet start at or after $from, or null. */
    private static function findSync(string $b, int $from, int $len): ?int
    {
        for ($i = $from; $i + 3 * self::PKT < $len; $i++) {
            if ($b[$i] === "\x47"
                && $b[$i + self::PKT] === "\x47"
                && $b[$i + 2 * self::PKT] === "\x47"
                && $b[$i + 3 * self::PKT] === "\x47") {
                return $i;
            }
        }
        return ($from < $len && $b[$from] === "\x47") ? $from : null;
    }

    private function packet(string $b, int $o, int $len, callable $onPes): void
    {
        $b1 = ord($b[$o + 1]);
        if (($b1 & 0x80) !== 0) {
            return; // transport error
        }
        $pusi = ($b1 & 0x40) !== 0;
        if ((($b1 >> 4) & 0x03) === 0x03) {
            return; // reserved scrambling value
        }
        $pid = (($b1 & 0x1F) << 8) | ord($b[$o + 2]);
        $b4 = ord($b[$o + 3]);
        $afc = ($b4 >> 4) & 0x03;
        if ($afc === 0x00) {
            return; // reserved
        }
        $cc = $b4 & 0x0F;
        if (isset($this->lastCc[$pid]) && $this->lastCc[$pid] !== $cc && (($this->lastCc[$pid] + 1) & 0x0F) !== $cc) {
            $this->badPackets++;
        }
        $this->lastCc[$pid] = $cc;

        $end = min($o + self::PKT, $len);
        $payloadAt = $o + 4;
        if ($afc === 0x02 || $afc === 0x03) {
            $afLen = ord($b[$o + 4]);
            if ($payloadAt + 1 + $afLen > $end) {
                return;
            }
            if ($afLen > 0 && $afc === 0x03 && (ord($b[$o + 5]) & 0x10) !== 0 && $afLen >= 8) {
                $this->pcrPid ??= $pid;
            }
            $payloadAt += 1 + $afLen;
            if ($afc === 0x02) {
                return; // adaptation only, no payload
            }
        }
        if ($payloadAt >= $end || $pid === 0x1FFF || $pid === 0x1FFE) {
            return;
        }
        $payload = substr($b, $payloadAt, $end - $payloadAt);

        if ($pusi) {
            if (isset($this->pending[$pid]) && $this->pending[$pid] !== '') {
                $this->emit($pid, $this->pending[$pid], $this->pendingMeta[$pid], $onPes);
            }
            $this->pending[$pid] = '';
            $this->pendingMeta[$pid] = ['pts' => null, 'dts' => null];
            if ($pid === 0) {
                $this->readPat($payload);
                return;
            }
            if ($pid === $this->pmtPid) {
                $this->readPmt($payload);
                return;
            }
        }
        $this->pending[$pid] .= $payload;
    }

    private function readPat(string $p): void
    {
        $i = self::tableStart($p);
        if ($i === null || ord($p[$i]) !== 0x00 || $i + 5 > strlen($p)) {
            return;
        }
        $sectionLen = ((ord($p[$i + 1]) & 0x0F) << 8) | ord($p[$i + 2]);
        $from = $i + 5;
        $to = min(strlen($p) - 4, $from + $sectionLen - 3 - 4);
        for ($j = $from; $j + 3 < $to; $j += 4) {
            $program = (ord($p[$j]) << 8) | ord($p[$j + 1]);
            $pmt = ((ord($p[$j + 2]) & 0x1F) << 8) | ord($p[$j + 3]);
            if ($program !== 0 && $pmt !== 0x1FFF) {
                $this->pmtPid = $pmt;
                return; // first real program is the one we play
            }
        }
    }

    private function readPmt(string $p): void
    {
        $i = self::tableStart($p);
        if ($i === null || ord($p[$i]) !== 0x02 || $i + 12 > strlen($p)) {
            return;
        }
        $sectionLen = ((ord($p[$i + 1]) & 0x0F) << 8) | ord($p[$i + 2]);
        $this->pcrPid = ((ord($p[$i + 8]) & 0x1F) << 8) | ord($p[$i + 9]);
        $progInfoLen = ((ord($p[$i + 10]) & 0x0F) << 8) | ord($p[$i + 11]);
        $j = $i + 12 + $progInfoLen;
        $to = min(strlen($p) - 4, $i + 3 + $sectionLen - 4);
        while ($j + 4 < $to) {
            $type = ord($p[$j]);
            $esPid = ((ord($p[$j + 1]) & 0x1F) << 8) | ord($p[$j + 2]);
            $esInfoLen = ((ord($p[$j + 3]) & 0x0F) << 8) | ord($p[$j + 4]);
            $j += 5 + $esInfoLen;
            if ($esPid === 0x1FFF || isset($this->streamType[$esPid])) {
                continue;
            }
            $this->streamType[$esPid] = $type;
            $isVideo = isset(self::VIDEO_TYPES[$type]);
            $kind = $isVideo ? self::VIDEO_TYPES[$type] : (self::AUDIO_TYPES[$type] ?? ($type === 0x06 ? 'private' : null));
            if ($kind === null) {
                $this->skipped[$esPid] = 'stream type 0x' . dechex($type);
                continue;
            }
            if ($isVideo) {
                if ($this->videoPid === null) {
                    $this->videoPid = $esPid;
                    $this->pidKind[$esPid] = 'video';
                } else {
                    $this->skipped[$esPid] = 'a second video track';
                }
                continue;
            }
            if ($this->audioPid === null) {
                $this->audioPid = $esPid;
                $this->pidKind[$esPid] = 'audio';
            } else {
                $this->skipped[$esPid] = 'another audio language';
            }
        }
    }

    private static function tableStart(string $p): ?int
    {
        $i = 1 + ord($p[0]);
        return $i < strlen($p) ? $i : null;
    }

    private function emit(int $pid, string $pes, array $meta, callable $onPes): void
    {
        if (strlen($pes) < 14 || substr($pes, 0, 3) !== "\x00\x00\x01") {
            return;
        }
        $streamId = ord($pes[3]);
        if ($streamId === 0xBE || $streamId === 0xBF || $streamId === 0xFF) {
            return; // padding, end-of-stream, program stream map
        }
        $flags = ord($pes[7]);
        if (($flags & 0xC0) !== 0x80 && ($flags & 0xC0) !== 0xC0) {
            return;
        }
        $pts = self::readTimestamp($pes, 9);
        $dts = ($flags & 0xC0) === 0xC0 ? self::readTimestamp($pes, 14) : null;
        if ($pts === null) {
            return;
        }
        $start = 9 + ord($pes[8]);
        if ($start >= strlen($pes)) {
            return;
        }
        $kind = $this->pidKind[$pid] ?? null;
        if ($kind === null) {
            $kind = ($streamId >= 0xE0 && $streamId <= 0xEF) ? 'video'
                : (($streamId >= 0xC0 && $streamId <= 0xDF) || $streamId === 0xBD ? 'audio' : null);
            if ($kind === null) {
                return; // teletext, ids3, metadata
            }
            $this->pidKind[$pid] = $kind;
        }
        $this->videoPid ??= $kind === 'video' ? $pid : null;
        $this->audioPid ??= $kind === 'audio' ? $pid : null;
        $this->pesCount++;
        $onPes($pid, $kind, $pts, $dts, substr($pes, $start));
    }

    /** 33-bit PTS/DTS field, marker bits skipped. */
    public static function readTimestamp(string $b, int $o): ?int
    {
        if ($o + 4 >= strlen($b)) {
            return null;
        }
        return (((ord($b[$o]) & 0x0E) >> 1) << 30)
            | (((ord($b[$o + 1]) << 7) | (ord($b[$o + 2]) >> 1)) << 15)
            | ((ord($b[$o + 3]) << 7) | (ord($b[$o + 4]) >> 1));
    }
}

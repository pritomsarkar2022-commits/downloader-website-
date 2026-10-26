<?php
declare(strict_types=1);

/**
 * H.264 Annex-B -> MP4 sample builder.
 *
 * Splits the start-code stream into NAL units, finds access-unit boundaries
 * (a slice whose first_mb_in_slice is 0, or parameter sets in front of a new
 * picture), rewrites start codes into 4-byte lengths and keeps the SPS/PPS for avcC.
 */
final class Avc
{
    private const SLICE_TYPES = [1, 2, 3, 4, 5, 19, 20, 21];
    private const HIGH_PROFILES = [100, 110, 120, 144, 44, 83, 86, 118, 128, 138, 139, 134, 135];

    /** @var array<int,string> NAL units of the access unit being collected */
    private array $current = [];
    private ?int $currentPts = null;
    private ?int $currentDts = null;
    private bool $hasSlice = false;
    private bool $hasIdr = false;

    /** @var array<int,string> nal type => bytes of the first SPS and PPS seen */
    private array $paramSets = [];
    /**
     * A PES packet may end in the middle of a NAL unit, so the bytes before the
     * next start code continue the last unit of the access unit still open.
     */
    private string $carry = '';
    /** @var array<string,int>|null */
    public ?array $spsInfo = null;
    public int $width = 0;
    public int $height = 0;
    public int $samples = 0;
    public int $keyframes = 0;

    /**
     * Feed one video PES packet; get back the access units it completed.
     *
     * @return array<int,array{nalus:array<int,string>,pts:int,dts:int,key:bool}>
     */
    public function push(string $annexB, int $pts, ?int $dts): array
    {
        $this->stealCarry($annexB);
        $done = [];
        foreach (self::split($annexB) as [$type, $nalu]) {
            if (($type === 7 || $type === 8) && !isset($this->paramSets[$type])) {
                $this->paramSets[$type] = $nalu;
                if ($type === 7) {
                    $this->readSps($nalu);
                }
            }
            $isSlice = in_array($type, self::SLICE_TYPES, true);
            $newPicture = ($isSlice && self::firstMbIsZero($nalu)) || (in_array($type, [7, 8, 9], true) && $this->hasSlice);
            if ($newPicture && $this->hasSlice) {
                $done[] = $this->close();
            }
            if ($type !== 0) {
                $this->current[] = $nalu;
            }
            if ($isSlice) {
                if (!$this->hasSlice) {
                    $this->currentPts = $pts;
                    $this->currentDts = $dts ?? $pts;
                }
                $this->hasSlice = true;
            }
            if ($type === 5) {
                $this->hasIdr = true;
            }
        }
        return $done;
    }

    /**
     * Move the bytes that sit before this chunk's first start code back into the
     * NAL unit they were cut out of.
     */
    private function stealCarry(string $annexB): void
    {
        $first = self::firstStart($annexB);
        if ($first === null) {
            $this->carry .= $annexB;
            return;
        }
        if ($first > 0) {
            $this->carry .= substr($annexB, 0, $first);
        }
        if ($this->carry === '') {
            return;
        }
        $last = $this->current === [] ? null : array_key_last($this->current);
        if ($last !== null) {
            $this->current[$last] .= $this->carry;
        }
        $this->carry = '';
    }

    /** Close the access unit left open at the end of the stream. */
    public function finish(): array
    {
        if ($this->carry !== '' && $this->current !== []) {
            $this->current[array_key_last($this->current)] .= $this->carry;
            $this->carry = '';
        }
        if (!$this->hasSlice) {
            return [];
        }
        $unit = $this->close();
        return [$unit];
    }

    /** @return array{nalus:array<int,string>,pts:int,dts:int,key:bool} */
    private function close(): array
    {
        $this->samples++;
        if ($this->hasIdr) {
            $this->keyframes++;
        }
        $unit = [
            'nalus' => $this->current,
            'pts' => (int) $this->currentPts,
            'dts' => (int) ($this->currentDts ?? $this->currentPts),
            'key' => $this->hasIdr,
        ];
        $this->current = [];
        $this->currentPts = null;
        $this->currentDts = null;
        $this->hasSlice = false;
        $this->hasIdr = false;
        return $unit;
    }

    /** Index of the first start code in the chunk, or null when there is none. */
    private static function firstStart(string $b): ?int
    {
        $len = strlen($b);
        for ($i = 0; $i < $len - 2; $i++) {
            if ($b[$i] === "\x00" && $b[$i + 1] === "\x00"
                && ($b[$i + 2] === "\x01" || ($b[$i + 2] === "\x00" && $i + 3 < $len && $b[$i + 3] === "\x01"))) {
                return $i;
            }
        }
        return null;
    }

    /** @return array<int,array{0:int,1:string}> [nal type, nalu bytes without the start code] */
    public static function split(string $b): array
    {
        $out = [];
        $len = strlen($b);
        $i = 0;
        $start = -1;
        while ($i < $len - 2) {
            if ($b[$i] === "\x00" && $b[$i + 1] === "\x00") {
                if ($b[$i + 2] === "\x01") {
                    $new = 3;
                } elseif ($b[$i + 2] === "\x00" && $i + 3 < $len && $b[$i + 3] === "\x01") {
                    $new = 4;
                } else {
                    $new = 0;
                }
                if ($new !== 0) {
                    if ($start >= 0) {
                        self::collect($out, substr($b, $start, $i - $start));
                    }
                    $start = $i + $new;
                    $i = $start;
                    continue;
                }
            }
            $i++;
        }
        if ($start >= 0) {
            self::collect($out, substr($b, $start));
        }
        return $out;
    }

    /** @param array<int,array{0:int,1:string}> $out */
    private static function collect(array &$out, string $unit): void
    {
        $unit = rtrim($unit, "\x00");
        if ($unit !== '') {
            $out[] = [ord($unit[0]) & 0x1F, $unit];
        }
    }

    /** slice_header opens with first_mb_in_slice ue(v): zero means a new picture. */
    private static function firstMbIsZero(string $nalu): bool
    {
        $br = new BitReader(self::unescape(substr($nalu, 1)));
        $v = $br->ue();
        return $v === null || $v === 0;
    }

    /** Drop the 0x03 emulation-prevention bytes. */
    public static function unescape(string $b): string
    {
        return (string) preg_replace('/\x00\x00\x03([\x00\x01\x02\x03])/', "\x00\x00$1", $b);
    }

    private function readSps(string $nalu): void
    {
        $br = new BitReader(self::unescape(substr($nalu, 1)));
        $profile = $br->u(8);
        $constraints = $br->u(8);
        $level = $br->u(8);
        if ($profile === null || $constraints === null || $level === null) {
            return;
        }
        $br->ue(); // sps_id
        $chroma = 1;
        $depthLuma = 8;
        $depthChroma = 8;
        if (in_array($profile, self::HIGH_PROFILES, true)) {
            $got = $br->ue();
            if ($got === null) {
                return;
            }
            $chroma = $got;
            if ($chroma === 3) {
                $br->u(1);
            }
            $dl = $br->ue();
            $dc = $br->ue();
            if ($dl === null || $dc === null) {
                return;
            }
            $depthLuma = $dl + 8;
            $depthChroma = $dc + 8;
            $br->u(1); // qpprime_y_zero_transform_bypass_flag
            if ($br->u(1) === 1) {
                for ($i = 0, $n = $chroma === 3 ? 12 : 8; $i < $n; $i++) {
                    if ($br->u(1) === 1) {
                        self::skipScalingList($br, $i < 6 ? 16 : 64);
                    }
                }
            }
        }
        $br->ue(); // log2_max_frame_num_minus4
        $pocType = $br->ue();
        if ($pocType === 0) {
            $br->ue(); // log2_max_pic_order_cnt_lsb_minus4
        } elseif ($pocType === 1) {
            $br->u(1);
            $br->se();
            $br->se();
            for ($i = 0, $n = $br->ue() ?? 0; $i < $n; $i++) {
                $br->se();
            }
        }
        $br->ue(); // max_num_ref_frames
        $br->u(1); // gaps_in_frame_num_value_allowed_flag
        $wMbs = $br->ue();
        $hUnits = $br->ue();
        $mbsOnly = $br->u(1);
        if ($wMbs === null || $hUnits === null || $mbsOnly === null) {
            return;
        }
        if ($mbsOnly === 0) {
            $br->u(1); // mb_adaptive_frame_field_flag
        }
        $br->u(1); // direct_8x8_inference_flag
        $width = ($wMbs + 1) * 16;
        $height = ($hUnits + 1) * 16 * (2 - $mbsOnly);
        $frameCropping = $br->u(1);
        if ($frameCropping === 1) {
            $cropX = ($chroma === 0 ? 1 : ($chroma === 3 ? 1 : 2));
            $cropY = ($chroma === 0 ? 1 : ($chroma === 1 ? 2 : 1)) * (2 - $mbsOnly);
            $width -= (($br->ue() ?? 0) + ($br->ue() ?? 0)) * $cropX;
            $height -= (($br->ue() ?? 0) + ($br->ue() ?? 0)) * $cropY;
        }
        $this->width = max(0, $width);
        $this->height = max(0, $height);
        $this->spsInfo = [
            'profile' => $profile,
            'constraints' => $constraints,
            'level' => $level,
            'chroma' => $chroma,
            'depth_luma' => $depthLuma,
            'depth_chroma' => $depthChroma,
        ];
    }

    private static function skipScalingList(BitReader $br, int $size): void
    {
        $last = 8;
        $next = 8;
        for ($i = 0; $i < $size; $i++) {
            if ($i !== 0) {
                $d = $br->se();
                if ($d === null) {
                    return;
                }
                $next = ($last + $d + 256) % 256;
            }
            if ($next !== 0) {
                $last = $next;
            }
        }
    }

    /** avcC box contents, or null until both parameter sets have been seen. */
    public function avcC(): ?string
    {
        if (!isset($this->paramSets[7], $this->paramSets[8]) || $this->spsInfo === null) {
            return null;
        }
        $sps = $this->paramSets[7];
        $pps = $this->paramSets[8];
        $out = chr(1)
            . chr((int) $this->spsInfo['profile'])
            . chr((int) $this->spsInfo['constraints'])
            . chr((int) $this->spsInfo['level'])
            . chr(0xFC | 3)   // 4-byte nal length field
            . chr(0xE0 | 1)   // one sps
            . pack('n', strlen($sps)) . $sps
            . chr(1)          // one pps
            . pack('n', strlen($pps)) . $pps;
        if (in_array((int) $this->spsInfo['profile'], self::HIGH_PROFILES, true)) {
            $out .= chr(0xF8 | (int) $this->spsInfo['chroma'])
                . chr(0xF8 | ((int) $this->spsInfo['depth_luma'] - 8))
                . chr(0xF8 | ((int) $this->spsInfo['depth_chroma'] - 8))
                . chr(0xFC | 0);
        }
        return $out;
    }

    /** @param array<int,string> $nalus */
    public static function toAvcc(array $nalus): string
    {
        $out = '';
        foreach ($nalus as $n) {
            $out .= pack('N', strlen($n)) . $n;
        }
        return $out;
    }
}

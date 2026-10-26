<?php
declare(strict_types=1);

/**
 * AAC in MPEG-TS: ADTS frames in, MP4 payloads out.
 *
 * TS carries AAC as ADTS, which repeats a header in front of every 1024-sample
 * frame. MP4 wants bare frames plus one AudioSpecificConfig in esds, so headers
 * are stripped and the first one is converted into that config.
 */
final class Aac
{
    public const RATES = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 16000, 12000, 11025, 8000, 7350, 0, 0];

    public ?int $sampleRate = null;
    public ?int $channels = null;
    public ?int $objectType = null;
    public ?string $asc = null;
    public int $frames = 0;
    public int $skippedBytes = 0;
    /** A frame can be cut in half by a PES packet boundary, so keep the loose end. */
    private string $carry = '';

    /**
     * Feed one audio PES packet.
     *
     * @return array<int,array{data:string,samples:int}> raw AAC frames
     */
    public function push(string $pes): array
    {
        if ($this->carry !== '') {
            $pes = $this->carry . $pes;
            $this->carry = '';
        }
        $out = [];
        $i = 0;
        $len = strlen($pes);
        while ($i + 7 <= $len) {
            if ($pes[$i] !== "\xFF" || (ord($pes[$i + 1]) & 0xF6) !== 0xF0) {
                $i++;
                $this->skippedBytes++;
                continue;
            }
            $b1 = ord($pes[$i + 1]);
            $b2 = ord($pes[$i + 2]);
            $b3 = ord($pes[$i + 3]);
            $b4 = ord($pes[$i + 4]);
            $b5 = ord($pes[$i + 5]);
            // byte1 bit3 is the mpeg *version*: 0 means MPEG-4, 1 means MPEG-2.
            $mpeg4 = ($b1 & 0x08) === 0;
            $headerLen = ($b1 & 0x01) === 1 ? 7 : 9;
            $frameLen = (($b3 & 0x03) << 11) | ($b4 << 3) | (($b5 >> 5) & 0x07);
            if ($frameLen < $headerLen) {
                $i++;
                $this->skippedBytes++;
                continue;
            }
            if ($i + $frameLen > $len) {
                if ($frameLen <= 8192) {
                    $this->carry = substr($pes, $i);
                }
                break;
            }
            $profile = $b2 >> 6;
            $freqIdx = ($b2 >> 2) & 0x0F;
            $chan = (($b2 & 0x01) << 2) | (($b3 >> 6) & 0x03);
            $objectType = $mpeg4 ? $profile + 1 : $profile;
            if ($this->asc === null && $mpeg4 && isset(self::RATES[$freqIdx]) && self::RATES[$freqIdx] > 0 && $chan > 0) {
                $this->objectType = $objectType;
                $this->sampleRate = self::RATES[$freqIdx];
                $this->channels = $chan;
                $this->asc = self::audioSpecificConfig($objectType, $freqIdx, $chan);
            }
            $out[] = [
                'data' => substr($pes, $i + $headerLen, $frameLen - $headerLen),
                'samples' => 1024,
            ];
            $this->frames++;
            $i += $frameLen;
        }
        return $out;
    }

    /**
     * Two-byte AudioSpecificConfig: 5 bits object type, 4 bits rate index, then
     * 4 bits channel configuration starting at bit 9, which is byte1 bit 3.
     */
    public static function audioSpecificConfig(int $objectType, int $freqIdx, int $channels): string
    {
        return chr(($objectType << 3) | ($freqIdx >> 1))
            . chr((($freqIdx & 1) << 7) | ($channels << 3));
    }

    /** MP4 plays AAC-LC/Main/SSR; anything else should stay a stream file. */
    public function isMp4Friendly(): bool
    {
        return $this->asc !== null && in_array((int) $this->objectType, [1, 2, 3, 4, 6], true);
    }
}

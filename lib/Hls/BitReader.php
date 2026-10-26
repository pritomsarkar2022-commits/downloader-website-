<?php
declare(strict_types=1);

/** MSB-first bit reader with the Exp-Golomb codes H.264 headers use. */
final class BitReader
{
    private int $bit = 0;

    public function __construct(private readonly string $data)
    {
    }

    public function bitsLeft(): int
    {
        return strlen($this->data) * 8 - $this->bit;
    }

    /** Read $n bits as an unsigned integer, or null past the end. */
    public function u(int $n): ?int
    {
        if ($n < 0 || $this->bitsLeft() < $n) {
            return null;
        }
        $value = 0;
        for ($i = 0; $i < $n; $i++) {
            $byte = ord($this->data[intdiv($this->bit + $i, 8)]);
            $value = ($value << 1) | (($byte >> (7 - (($this->bit + $i) % 8))) & 1);
        }
        $this->bit += $n;
        return $value;
    }

    /** unsigned Exp-Golomb. */
    public function ue(): ?int
    {
        $zeros = 0;
        while (true) {
            $b = $this->u(1);
            if ($b === null) {
                return null;
            }
            if ($b === 1) {
                break;
            }
            $zeros++;
            if ($zeros > 31) {
                return null; // corrupt stream
            }
        }
        if ($zeros === 0) {
            return 0;
        }
        $rest = $this->u($zeros);
        return $rest === null ? null : (1 << $zeros) - 1 + $rest;
    }

    /** signed Exp-Golomb. */
    public function se(): ?int
    {
        $k = $this->ue();
        if ($k === null) {
            return null;
        }
        $half = intdiv($k + 1, 2);
        return $k % 2 === 0 ? -$half : $half;
    }
}

<?php
declare(strict_types=1);

/** A single playable/downloadable file that an extractor managed to resolve. */
final class Source
{
    public const MP4 = 'mp4';
    public const HLS = 'hls';
    public const TS = 'ts';
    public const WEBM = 'webm';
    public const MKV = 'mkv';

    public string $url;
    public string $kind;
    public ?string $label = null;
    public ?int $height = null;
    public ?int $bitrate = null;
    public ?int $sizeBytes = null;
    public ?string $mime = null;

    /** Outbound headers the file host demands (Referer, Cookie, Range, ...). */
    public array $headers = [];

    /** Unix ts when the token in the url is expected to die, if known. */
    public ?int $tokenExpiresAt = null;

    /** True when the file cannot be fetched by the browser directly. */
    public bool $needsProxy = true;

    /** For HLS: variant list, filled by Hls\Playlist. */
    public array $variants = [];

    /** Playtime in seconds, when the source exposes it (single media playlist). */
    public ?float $duration = null;

    /** Anything worth surfacing about this candidate: DRM, slow host, expired token. */
    public ?string $note = null;

    /** AES-128 details for HLS sources, resolved while verifying the variant. */
    public ?string $keyUri = null;
    public ?string $keyMethod = null;

    public string $id = '';

    /** Discovery confidence, set by MediaSniffer::candidates. Never shown to visitors. */
    public int $score = 0;

    public static function make(string $url, string $kind = self::MP4): self
    {
        $s = new self();
        $s->url = $url;
        $s->kind = $kind;
        $s->id = substr(sha1($url), 0, 12);
        return $s;
    }

    public function isVideo(): bool
    {
        return in_array($this->kind, [self::MP4, self::HLS, self::WEBM, self::MKV, self::TS], true);
    }

    public function tokenExpiryFromQuery(): void
    {
        $q = parse_url($this->url, PHP_URL_QUERY);
        if (!$q) {
            return;
        }
        parse_str($q, $p);
        foreach (['expires', 'exp', 'ttl', 'e', 'timeout'] as $k) {
            if (isset($p[$k]) && preg_match('/^\d{9,11}$/', (string) $p[$k])) {
                $v = (int) $p[$k];
                // Some hosts use a duration (seconds) instead of a timestamp.
                $this->tokenExpiresAt = $v > 1_000_000_000 ? $v : time() + min($v, 86_400);
                return;
            }
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label ?? match ($this->kind) {
                self::HLS => 'Video stream',
                self::TS => 'Raw stream',
                default => strtoupper($this->kind) . ' file',
            },
            'height' => $this->height,
            'bitrate' => $this->bitrate,
            'size' => $this->sizeBytes,
            'sizeHuman' => human_bytes($this->sizeBytes),
            'mime' => $this->mime ?? match ($this->kind) {
                self::HLS => 'application/vnd.apple.mpegurl',
                self::WEBM => 'video/webm',
                self::MKV => 'video/x-matroska',
                self::TS => 'video/mp2t',
                default => 'video/mp4',
            },
            'tokenExpiresAt' => $this->tokenExpiresAt,
            'needsProxy' => $this->needsProxy,
            'duration' => $this->duration !== null ? round($this->duration, 1) : null,
            'note' => $this->note,
            'variants' => array_map(static fn(array $v) => [
                'height' => $v['height'] ?? null,
                'bandwidth' => $v['bandwidth'] ?? null,
                'codecs' => $v['codecs'] ?? null,
                'segments' => $v['segments'] ?? null,
                'duration' => $v['duration'] ?? null,
                'encrypted' => !empty($v['encrypted']),
                // The variant's own signed url stays on the server.
            ], $this->variants),
            // Never leak the signed url to the UI as anything it can copy straight
            // into a new tab: the relay is the only thing that uses it.
            'url' => null,
        ];
    }
}

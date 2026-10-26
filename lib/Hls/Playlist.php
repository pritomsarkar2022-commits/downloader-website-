<?php
declare(strict_types=1);

/**
 * Reads HLS playlists and rewrites them so a browser can play a cross-origin
 * stream through our relay without CORS or hotlink problems.
 */
final class Playlist
{
    public bool $isMaster = false;

    /** @var array<int,array{url:string,bandwidth:?int,width:?int,height:?int,codecs:?string,name:?string}> */
    public array $variants = [];

    /** @var array<int,array{url:string,duration:float,seq:int,byterange:?string,keyId:?string,map:?string}> */
    public array $segments = [];

    public ?array $key = null;      // {method, uri, iv}
    public ?string $map = null;     // init segment url (fmp4 input)
    public ?float $targetDuration = null;
    public ?int $mediaSequence = 0;
    public bool $endList = false;
    public bool $live = false;
    public bool $sampleAes = false;
    public float $totalDuration = 0.0;
    public ?string $codecs = null;

    public static function parse(string $text, string $baseUrl): self
    {
        $p = new self();
        // Every line without a tag would otherwise become a segment, so an error
        // page or a truncated response has to be refused here rather than built
        // into a video made of html.
        if (stripos($text, '#EXTM3U') === false) {
            return $p;
        }
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $seq = 0;
        $pendingInfo = null;
        $pendingDuration = null;
        $mapLocal = null;

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (!str_starts_with($t, '#')) {
                $url = url_absolutize($baseUrl, $t);
                if ($p->isMaster) {
                    $p->variants[] = ($pendingInfo ?? []) + ['url' => $url];
                    $pendingInfo = null;
                } else {
                    $dur = $pendingDuration ?? ($p->targetDuration ?? 10.0);
                    $p->segments[] = [
                        'url' => $url,
                        'duration' => $dur,
                        'seq' => $seq++,
                        'byterange' => null,
                        'map' => $mapLocal,
                    ];
                    $p->totalDuration += $dur;
                    $pendingDuration = null;
                }
                continue;
            }
            if (stripos($t, '#EXT-X-STREAM-INF') !== false) {
                $p->isMaster = true;
                $pendingInfo = self::attributes(substr($t, strpos($t, ':') + 1));
                $res = $pendingInfo['resolution'] ?? null;
                $w = $h = null;
                if (is_string($res) && preg_match('/^(\d+)x(\d+)$/', $res, $mm)) {
                    [$w, $h] = [(int) $mm[1], (int) $mm[2]];
                }
                $codecs = isset($pendingInfo['codecs']) ? trim((string) $pendingInfo['codecs'], " \t\n\r\0\x0B\"") : null;
                if ($codecs === '') {
                    $codecs = null;
                }
                $pendingInfo = [
                    'bandwidth' => isset($pendingInfo['bandwidth']) ? (int) $pendingInfo['bandwidth'] : null,
                    'width' => $w,
                    'height' => $h,
                    'codecs' => $codecs,
                    'name' => isset($pendingInfo['name']) ? trim((string) $pendingInfo['name'], '"') : null,
                ];
                continue;
            }
            if (stripos($t, '#EXTINF') !== false) {
                if (preg_match('/:\s*([\d.]+)\s*,?/', $t, $mm)) {
                    $pendingDuration = (float) $mm[1];
                }
                continue;
            }
            if (stripos($t, '#EXT-X-TARGETDURATION') !== false && preg_match('/:(\d+)/', $t, $mm)) {
                $p->targetDuration = (float) $mm[1];
                continue;
            }
            if (stripos($t, '#EXT-X-MEDIA-SEQUENCE') !== false && preg_match('/:(\d+)/', $t, $mm)) {
                $p->mediaSequence = (int) $mm[1];
                $seq = $p->mediaSequence;
                continue;
            }
            if (stripos($t, '#EXT-X-KEY') !== false) {
                $attrs = self::attributes(substr($t, strpos($t, ':') + 1));
                $method = strtoupper((string) ($attrs['method'] ?? 'NONE'));
                if ($method === 'SAMPLE-AES' || str_contains($method, 'SAMPLE')) {
                    $p->sampleAes = true;
                }
                $uri = isset($attrs['uri']) ? trim((string) $attrs['uri'], '"') : null;
                $p->key = $method === 'NONE' || $uri === null
                    ? null
                    : ['method' => $method, 'uri' => url_absolutize($baseUrl, $uri), 'iv' => $attrs['iv'] ?? null];
                continue;
            }
            if (stripos($t, '#EXT-X-MAP') !== false) {
                $attrs = self::attributes(substr($t, strpos($t, ':') + 1));
                if (isset($attrs['uri'])) {
                    $mapLocal = url_absolutize($baseUrl, trim((string) $attrs['uri'], '"'));
                    $p->map = $mapLocal;
                }
                continue;
            }
            if (stripos($t, '#EXT-X-ENDLIST') !== false) {
                $p->endList = true;
                continue;
            }
            if (stripos($t, '#EXT-X-BYTERANGE') !== false && $p->segments) {
                $i = count($p->segments) - 1;
                $p->segments[$i]['byterange'] = trim((string) preg_replace('/^#EXT-X-BYTERANGE\s*:\s*/i', '', $t));
            }
        }

        $p->live = !$p->endList && !$p->isMaster && $p->segments !== [];
        if ($p->isMaster) {
            usort($p->variants, static fn($a, $b) => (int) $b['bandwidth'] <=> (int) $a['bandwidth']);
        }
        return $p;
    }

    /** @return array<string,string> lower-cased attribute map, quoted values kept as-is */
    private static function attributes(string $s): array
    {
        preg_match_all('/([A-Za-z0-9_-]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^,]*)/', $s, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $pair) {
            $out[strtolower($pair[1])] = trim($pair[2]);
        }
        return $out;
    }

    /** Pick the variant closest to a target height, preferring the higher one. */
    public function selectVariant(?int $targetHeight = null): ?array
    {
        if (!$this->variants) {
            return null;
        }
        $targetHeight ??= (int) cfg('hls.default_height', 1080);
        $best = null;
        $bestPenalty = PHP_FLOAT_MAX;
        foreach ($this->variants as $v) {
            $h = $v['height'] ?? (int) sqrt(max(1, (int) $v['bandwidth'] / 1.5));
            $penalty = abs($h - $targetHeight) + ($h > $targetHeight ? 5 : 0);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $v;
            }
        }
        return $best;
    }

    /**
     * Rewrite every uri in a playlist through $link(target, isAttributeURI).
     * Key and init segment uris are flagged so they are never treated as playlists.
     */
    public static function rewrite(string $text, string $baseUrl, callable $link): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $t = rtrim($line);
            if ($t === '') {
                $out[] = '';
                continue;
            }
            if (str_starts_with($t, '#')) {
                if (preg_match('~URI="([^"]+)"~', $t, $m)) {
                    $t = str_replace('"' . $m[1] . '"', '"' . $link(url_absolutize($baseUrl, $m[1]), true) . '"', $t);
                }
                $out[] = $t;
                continue;
            }
            $out[] = $link(url_absolutize($baseUrl, $t), false);
        }
        return implode("\n", $out);
    }
}

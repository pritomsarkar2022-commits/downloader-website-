<?php
declare(strict_types=1);

/** Pull every plausible media url out of whatever text a host served us. */
final class MediaSniffer
{
    private const EXT_KIND = [
        'm3u8' => Source::HLS,
        'mp4' => Source::MP4,
        'webm' => Source::WEBM,
        'mkv' => Source::MKV,
        'ts' => Source::TS,
    ];

    /** @return string[] absolute-ish media urls, most specific first */
    public static function mediaUrls(string $text): array
    {
        // Quoted-excluded class so trailing junk from minified js does not glue two urls together.
        $re = '~(?:https?:)?//[^\s"\'`<>(){}|\\\\]+?\.(?:m3u8|mp4|webm|mkv|ts)(?:\?[^\s"\'`<>(){}|\\\\]{0,300})?~i';
        preg_match_all($re, $text, $m);
        $found = [];
        foreach ($m[0] ?? [] as $u) {
            $u = self::clean($u);
            if ($u !== '') {
                $found[$u] = true;
            }
        }
        // Also catch protocol-relative and root-relative player config values.
        $rel = '~["\'`](/[^"\'`\s]{3,400}?\.(?:m3u8|mp4|webm|ts)(?:\?[^"\'`\s]{0,300})?)["\'`]~i';
        if (preg_match_all($rel, $text, $rm)) {
            foreach ($rm[1] as $u) {
                $found[self::clean($u)] = true;
            }
        }
        $list = array_keys($found);
        usort($list, static fn($a, $b) => strlen($b) <=> strlen($a));
        return array_values(array_filter($list, static fn($u) => $u !== ''));
    }

    private static function clean(string $u): string
    {
        $u = trim($u, " \t\n\r\0\x0B\"'`,;");
        // A url can legitimately end in .mp4 with no scheme inside a js string.
        if (preg_match('~^(?://)~', $u)) {
            $u = 'https:' . $u;
        }
        return $u;
    }

    /** @return Source[] */
    public static function sourcesFromText(string $text, string $baseUrl, array $headers = []): array
    {
        $out = [];
        foreach (self::mediaUrls($text) as $u) {
            $abs = url_absolutize($baseUrl, $u);
            // A flattened js string can glue a filename onto the page url, and
            // handing that to the relay would only produce a broken download.
            if (!preg_match('~^https?://~i', $abs) || !filter_var($abs, FILTER_VALIDATE_URL)) {
                continue;
            }
            $kind = self::kindOf($abs);
            if ($kind === null) {
                continue;
            }
            $s = Source::make($abs, $kind);
            $s->headers = $headers;
            $s->tokenExpiryFromQuery();
            $out[] = $s;
        }
        return $out;
    }

    public static function kindOf(string $url): ?string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '' && preg_match('~/(hls|dash|stream|playlist|master|index)[./]~i', $path)) {
            return Source::HLS;
        }
        return self::EXT_KIND[$ext] ?? null;
    }

    /** @return array{title:?string,thumbnail:?string} */
    public static function meta(string $html, string $baseUrl): array
    {
        $pick = static function (string $re) use ($html): ?string {
            return preg_match($re, $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) : null;
        };
        $title = $pick('~<meta[^>]+property=["\']og:video:alt["\'][^>]+content=["\']([^"\']{2,200})["\']~i')
            ?? $pick('~<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']{2,200})["\']~i')
            ?? $pick('~<title[^>]*>([^<]{2,200})</title>~i');
        $thumb = $pick('~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']{4,400})["\']~i')
            ?? $pick('~["\']image["\']\s*:\s*["\'](https?:[^"\']{4,400})["\']~i')
            ?? $pick('~image:\s*["\'](https?://[^"\']{4,400})["\']~i');
        return [
            'title' => $title !== null && $title !== '' ? self::tidy($title) : null,
            'thumbnail' => $thumb ? url_absolutize($baseUrl, $thumb) : null,
        ];
    }

    /**
     * Page titles carry the site's own decoration, and a card that opens with
     * "Stream Verification" reads like an error. The file's own name is behind it.
     */
    private static function tidy(string $t): string
    {
        $t = trim((string) preg_replace('~\s+~u', ' ', $t));
        $t = (string) preg_replace('~\s*\|\s*[^\s|]{2,26}\s*$~u', '', $t);                          // " ... | CloudFam"
        $t = (string) preg_replace('~\s+(?:at|on)\s+[a-z0-9][a-z0-9.-]*\.[a-z]{2,6}\s*$~i', '', $t); // " ... at Streamtape.com"
        $t = (string) preg_replace('~^(?:[A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,6}|CloudFam|MixDrop|Mxdrop|Streamtape|Dupload)\s*[—–|-]\s*~iu', '', $t);
        $t = (string) preg_replace('~^(?:Stream Verification|Watch Online|Watch|Download|Embed Player)\s*[—–|-]?\s*~iu', '', $t);
        $t = preg_replace('~^[—–|-]+|[—–|-]+$~u', '', $t) ?? $t;
        return trim($t, " \t\0\x0B-|");
    }

    /**
     * How much we trust the shape a url was found in. A page that names its file
     * in an og:video tag or a <video> element is telling us on purpose; the same
     * string floating in minified js may be an advert.
     */
    private const SCORE = [
        'regex' => 40,
        'link' => 50,
        'config' => 62,
        'data' => 66,
        'jsonld' => 76,
        'tag' => 80,
    ];

    /** Attribute names a player uses to hand its file url to itself. */
    private const FILE_KEYS = [
        'file', 'files', 'fileurl', 'file_url', 'videourl', 'video_url', 'mp4', 'mp4url',
        'mp4_url', 'hls', 'hlsurl', 'hls_url', 'm3u8', 'stream', 'streamurl', 'stream_url',
        'playbackurl', 'playback_url', 'playurl', 'src', 'srcset', 'sources', 'url',
    ];

    /**
     * The general formula: every candidate a page text offers, without knowing the
     * host. Structured tags first because they are written for machines, then the
     * player config shapes, then anything that looks like a file.
     *
     * @return Source[] ranked by trust, best first
     */
    public static function candidates(string $text, string $baseUrl, array $headers = []): array
    {
        $base = $baseUrl;
        $href = self::baseHref($text);
        if ($href !== null) {
            $abs = url_absolutize($baseUrl, $href);
            if (preg_match('~^https?://~i', $abs)) {
                $base = $abs;
            }
        }

        /** @var array<string,array{score:int,kind:string}> $found */
        $found = [];
        $add = static function (string $u, int $score, bool $allowEndpoint) use (&$found, $base): void {
            $u = self::clean($u);
            if ($u === '' || strlen($u) > 1200) {
                return;
            }
            if (preg_match('~^(?:javascript|data|blob|about|mailto|tel|file|chrome|view-source):~i', $u)) {
                return;
            }
            $abs = url_absolutize($base, $u);
            if (!preg_match('~^https?://~i', $abs) || strlen($abs) > 2400 || !filter_var($abs, FILTER_VALIDATE_URL)) {
                return;
            }
            $kind = self::kindOf($abs);
            if ($kind === null) {
                if (!$allowEndpoint || !self::looksLikeEndpoint($abs)) {
                    return;
                }
                // No extension: the file type is whatever the host answers with,
                // and verify() is the one that gets to decide, not this guess.
                $kind = Source::MP4;
            }
            if (!isset($found[$abs]) || $found[$abs]['score'] < $score - self::adPenalty($abs)) {
                $found[$abs] = ['score' => $score - self::adPenalty($abs), 'kind' => $kind];
            }
        };

        foreach (self::mediaUrls($text) as $u) {
            $add($u, self::SCORE['regex'], false);
        }
        foreach (self::tagUrls($text) as $u) {
            $add($u, self::SCORE['tag'], true);
        }
        foreach (self::jsonLdUrls($text) as $u) {
            $add($u, self::SCORE['jsonld'], true);
        }
        foreach (self::dataAttributeUrls($text) as $u) {
            $add($u, self::SCORE['data'], true);
        }
        foreach (self::configKeyUrls($text) as $u => $score) {
            // Only a file-shaped key is trusted with an address that has no extension.
            $add((string) $u, (int) $score, (int) $score >= self::SCORE['config']);
        }

        uasort($found, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $out = [];
        foreach (array_slice($found, 0, max(4, (int) cfg('extract.deep_candidates', 40))) as $u => $row) {
            $s = Source::make((string) $u, $row['kind']);
            $s->headers = $headers;
            $s->score = $row['score'];
            $s->tokenExpiryFromQuery();
            $out[] = $s;
        }
        return $out;
    }

    /** The page's own <base href>, which root-relative player paths resolve against. */
    public static function baseHref(string $html): ?string
    {
        if (!preg_match('~<base\b[^>]+href=["\']([^"\']{4,300})["\']~i', $html, $m)) {
            return null;
        }
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    /**
     * Elements and meta tags whose only job is to name a media file: open graph
     * video, twitter's player stream, <video>, <source>, plugin embeds, the
     * m3u8 <link> a desktop player leaves for Safari, and direct download links.
     *
     * @return string[]
     */
    public static function tagUrls(string $html): array
    {
        $out = [];
        $metaKeys = ['og:video', 'og:video:url', 'og:video:secure_url', 'twitter:player:stream', 'video:src', 'media:content'];
        preg_match_all('~<meta\b[^>]*>~i', $html, $ms);
        foreach ($ms[0] as $tag) {
            if (!preg_match('~(?:property|name)=["\']([^"\']+)["\']~i', $tag, $k)) {
                continue;
            }
            if (!in_array(strtolower($k[1]), $metaKeys, true)) {
                continue;
            }
            if (preg_match('~content=["\']([^"\']{6,700})["\']~i', $tag, $v)) {
                $out[] = html_entity_decode($v[1], ENT_QUOTES, 'UTF-8');
            }
        }

        preg_match_all('~<(video|source|audio|embed|object|a|link)\b[^>]*>~i', $html, $ts);
        foreach ($ts[0] as $i => $tag) {
            $name = strtolower($ts[1][$i]);
            $attrs = match ($name) {
                'link' => ['href'],
                'a' => ['href'],
                'object' => ['data'],
                'source' => ['src', 'srcset'],
                default => ['src', 'data-src', 'data-file', 'data-video', 'data-mp4', 'data-hls', 'data-url'],
            };
            $mediaOnly = in_array($name, ['a', 'link'], true);
            foreach ($attrs as $attr) {
                if (!preg_match('~(?:^|\s)' . preg_quote($attr, '~') . '=["\']([^"\'\s]{6,700})["\']~i', $tag, $m)) {
                    continue;
                }
                $u = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                // A link tag or an anchor only counts when the address itself names
                // a file, otherwise a site menu would be read as a video list.
                if ($mediaOnly && self::kindOf($u) === null) {
                    continue;
                }
                $out[] = $u;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Any data- attribute that reads like a file pointer. Players that build the
     * src in javascript still have to carry the address in the markup.
     *
     * @return string[]
     */
    public static function dataAttributeUrls(string $html): array
    {
        $out = [];
        preg_match_all(
            '~\bdata-(mp4|webm|hls|video|media|source|file|stream|src|url|embed)[a-z0-9_-]*=["\']([^"\'\s]{6,900})["\']~i',
            $html,
            $m
        );
        foreach ($m[2] ?? [] as $v) {
            $v = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
            foreach (preg_split('~[,\s]+~', $v) ?: [] as $piece) {
                if ($piece === '') {
                    continue;
                }
                if (self::kindOf($piece) !== null || preg_match('~^(?:https?:|//|/)~i', $piece)) {
                    $out[] = $piece;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Schema.org VideoObject blocks. A site that publishes these wants its file
     * found, and the address inside is the one its own player uses.
     *
     * @return string[] file urls, not pages
     */
    public static function jsonLdUrls(string $html): array
    {
        return self::jsonLdValues($html, ['contenturl', 'videourl', 'streamurl', 'mp4url', 'hlsurl']);
    }

    /** The embed page a VideoObject points at, for sites that only publish that. */
    public static function jsonLdEmbedPages(string $html): array
    {
        return self::jsonLdValues($html, ['embedurl', 'embed']);
    }

    /**
     * @param string[] $wanted lower-cased keys to collect
     * @return string[]
     */
    private static function jsonLdValues(string $html, array $wanted): array
    {
        $out = [];
        preg_match_all(
            '~<script[^>]+type=["\']application/(?:ld\+json|json)["\'][^>]*>(.*?)</script>~is',
            $html,
            $ms
        );
        $walk = static function ($node) use (&$walk, $wanted, &$out): void {
            if (!is_array($node)) {
                return;
            }
            $type = strtolower((string) json_encode($node['@type'] ?? ''));
            $isVideo = $type === '' || str_contains($type, 'video') || str_contains($type, 'media');
            foreach ($node as $k => $v) {
                $key = strtolower((string) $k);
                if (is_string($v)) {
                    if (in_array($key, $wanted, true) && $isVideo && strlen($v) > 5) {
                        $out[] = $v;
                    }
                } elseif (is_array($v)) {
                    if (in_array($key, $wanted, true) && $isVideo) {
                        foreach ($v as $one) {
                            if (is_string($one) && strlen($one) > 5) {
                                $out[] = $one;
                            }
                        }
                    }
                    $walk($v);
                }
            }
        };
        foreach ($ms[1] ?? [] as $json) {
            $decoded = json_decode(html_entity_decode($json, ENT_QUOTES, 'UTF-8'), true);
            if (is_array($decoded)) {
                $walk($decoded);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The player-config shapes: jwplayer, videojs, plyr, clappr and the hand-rolled
     * ones all put the file behind a key like file, sources or videoUrl.
     *
     * @return array<string,int> url => score
     */
    public static function configKeyUrls(string $text): array
    {
        $out = [];
        $strong = ['file', 'files', 'fileurl', 'file_url', 'videourl', 'video_url', 'mp4', 'mp4url',
            'hls', 'hlsurl', 'hls_url', 'm3u8', 'streamurl', 'stream_url', 'playbackurl', 'playurl', 'sources'];
        $re = '~[{"\[\s,](["\']?)([a-zA-Z0-9_-]{2,20})\1\s*[:=]\s*(["\'])([^"\'\n]{6,900})\3~';
        preg_match_all($re, $text, $m, PREG_SET_ORDER);
        foreach ($m as $row) {
            $key = strtolower($row[2]);
            $isStrong = in_array($key, $strong, true);
            if (!$isStrong && !in_array($key, ['src', 'srcset', 'url', 'stream', 'embed', 'embedurl'], true)) {
                continue;
            }
            $value = $row[4];
            // A sources array holds objects, so scan its text rather than itself.
            if ($key === 'sources' && str_starts_with(trim($value), '[')) {
                $out2 = self::configKeyUrls($value);
                foreach ($out2 as $u => $s) {
                    $out[(string) $u] = max($out[(string) $u] ?? 0, $s);
                }
                continue;
            }
            $pieces = str_contains($value, ',') && $key === 'srcset'
                ? array_map(static fn($p) => trim(explode(' ', trim($p))[0]), explode(',', $value))
                : [$value];
            foreach ($pieces as $u) {
                if ($u === '' || !preg_match('~^[A-Za-z0-9_./:%?&=+#-]{6,}$~', $u)) {
                    continue;
                }
                $score = $isStrong ? self::SCORE['config'] : self::SCORE['link'];
                $out[$u] = max($out[$u] ?? 0, $score);
            }
        }
        return $out;
    }

    /**
     * Files without an extension do exist: a signed endpoint answers the player
     * with video/mp4 no matter what its path says. Only a value that came from a
     * file-shaped key reaches here, so navigation links stay out, and a path needs
     * at least two levels before it looks like an endpoint rather than a page.
     */
    public static function looksLikeEndpoint(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '') {
            return in_array($ext, ['mp4', 'm3u8', 'webm', 'mkv', 'ts'], true);
        }
        return substr_count($path, '/') >= 2 && strlen($path) >= 8;
    }

    /**
     * Promo and inventory clips live on the same cdn as the feature and carry the
     * same extension, so they cannot be thrown away, but they must not outrank the
     * file the visitor came for.
     */
    private static function adPenalty(string $url): int
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        return preg_match('~(?:^|[/_.-])(?:ad|ads|advert|promo|promotion|banner|spot|trailers?|intro|preload|pixel|tracker|teaser)(?:[/_.-]|$)~', $path)
            ? 25
            : 0;
    }

    /**
     * Wrapper pages that only hand control to another document: iframe, embed,
     * object, a meta refresh, or a javascript location assignment. Each one is a
     * page to open, not a file to fetch.
     *
     * @return string[] absolute urls, same-host first
     */
    public static function playerPages(string $html, string $baseUrl): array
    {
        $out = [];
        $push = static function (string $u) use (&$out, $baseUrl): void {
            $u = self::clean(html_entity_decode($u, ENT_QUOTES, 'UTF-8'));
            if ($u === '' || preg_match('~^(?:javascript|data|blob|about|mailto|#)~i', $u)) {
                return;
            }
            $abs = url_absolutize($baseUrl, $u);
            if (!preg_match('~^https?://~i', $abs) || !filter_var($abs, FILTER_VALIDATE_URL)) {
                return;
            }
            if (self::kindOf($abs) !== null) {
                return;  // a file, not a page
            }
            $out[$abs] = ($out[$abs] ?? 0) + (parse_url($abs, PHP_URL_HOST) === parse_url($baseUrl, PHP_URL_HOST) ? 2 : 1);
        };

        preg_match_all('~<(iframe|embed|frame|object)\b[^>]*>~i', $html, $ts);
        foreach ($ts[0] as $tag) {
            foreach (['src', 'data-src', 'data-original', 'data-embed', 'data-url', 'data'] as $attr) {
                if (preg_match('~(?:^|\s)' . preg_quote($attr, '~') . '=["\']([^"\'\s]{6,700})["\']~i', $tag, $m)) {
                    $push($m[1]);
                }
            }
        }
        foreach (self::jsonLdEmbedPages($html) as $u) {
            $push($u);
        }
        if (preg_match('~<meta[^>]+http-equiv=["\']refresh["\'][^>]+content=["\'][^"\']*url=([^"\']+)["\']~i', $html, $m)) {
            $push($m[1]);
        }
        preg_match_all('~(?:window\.|top\.|self\.|document\.)(?:location(?:\.href)?|location)\s*=\s*["\']([^"\'\s]{6,500})["\']~i', $html, $m);
        foreach ($m[1] ?? [] as $u) {
            $push($u);
        }
        preg_match_all('~location(?:\.href|\.replace)\s*=\s*["\']([^"\'\s]{6,500})["\']~i', $html, $m);
        foreach ($m[1] ?? [] as $u) {
            $push($u);
        }

        uasort($out, static fn(int $a, int $b): int => $b <=> $a);
        return array_keys($out);
    }

    /**
     * Sidecar config files a player pulls to get its file list. These are the
     * urls that most often hide a playlist behind one more request.
     *
     * @return string[]
     */
    public static function configEndpoints(string $html, string $baseUrl, int $limit = 3): array
    {
        $out = [];
        $re = '~["\']([^"\'\s]{0,500}?(?:\.json(?:p)?|/config[a-z0-9_-]*|/sources[a-z0-9_-]*|/playlist[a-z0-9_-]*|/getvideo[a-z0-9_-]*|/api/(?:video|player|media|stream)[^"\'\s]{0,120}))["\']~i';
        preg_match_all($re, $html, $m);
        foreach ($m[1] ?? [] as $u) {
            $abs = url_absolutize($baseUrl, self::clean($u));
            if (strlen($u) >= 6 && preg_match('~^https?://~i', $abs) && filter_var($abs, FILTER_VALIDATE_URL) && !str_contains($abs, '{')) {
                $out[$abs] = true;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return array_keys($out);
    }
}

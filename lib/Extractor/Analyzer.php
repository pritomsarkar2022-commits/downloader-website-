<?php
declare(strict_types=1);

/**
 * Turns a pasted url into a stored job plus the only links the browser is
 * allowed to use: signed relay urls.
 */
final class Analyzer
{
    public static function analyze(string $pageUrl): array
    {
        $pageUrl = self::normalize($pageUrl);
        $registry = new ExtractorRegistry();
        $http = new HttpClient();
        $extractor = $registry->forUrl($pageUrl);

        $bundle = self::runExtractor($extractor, $pageUrl, $http, $registry);

        if (!$bundle->sources) {
            throw new ExtractException(
                'No downloadable file could be found on that page.',
                'Check that the link points straight at a video page, not a profile or a list.',
                'no_media'
            );
        }

        $store = new JobStore();
        $record = $store->create($bundle);
        return self::present($record, $store);
    }

    /** @param Source[] $extra */
    public static function present(array $record, JobStore $store, ?array $extraSources = null): array
    {
        $sources = [];
        foreach (($extraSources ?? $record['sources']) as $data) {
            $s = $data instanceof Source ? $data : $store->source($record, (string) $data['id']);
            if ($s === null) {
                continue;
            }
            $row = $s->toArray();
            $isHls = $s->kind === Source::HLS;
            $row['previewUrl'] = self::relayLink($record, $store, $s->url, false, '', $isHls);
            $row['downloadUrl'] = self::relayLink($record, $store, $s->url, true, self::fileName($record, $s), $isHls);
            $row['variants'] = array_map(function (array $v) use ($record, $store): array {
                if (isset($v['url'])) {
                    // A variant line is always a playlist of its own.
                    $v['streamUrl'] = self::relayLink($record, $store, (string) $v['url'], false, '', true);
                    unset($v['url']);
                }
                return $v;
            }, $s->variants);
            $sources[] = $row;
        }

        return [
            'job' => $record['id'],
            'provider' => $record['provider'],
            'title' => self::titleFor((string) ($record['title'] ?? ''), (string) $record['pageUrl']),
            'thumbnail' => !empty($record['thumbnail'])
                ? self::relayLink($record, $store, (string) $record['thumbnail'], false)
                : null,
            'pageUrl' => $record['pageUrl'],
            'notices' => $record['notices'],
            'confidence' => $record['confidence'],
            'expiresAt' => $record['expires'],
            'sources' => $sources,
        ];
    }

    private static function relayLink(array $record, JobStore $store, string $url, bool $download, string $name = '', bool $isPlaylist = false): string
    {
        $q = [
            'r' => 'relay',
            'job' => $record['id'],
            'u' => link_seal($url, (string) $record['secret']),
            's' => $store->sign($record, $url),
        ];
        if ($download) {
            $q['dl'] = '1';
            $q['name'] = $name;
        }
        if ($isPlaylist) {
            $q['p'] = '1';
        }
        return Relay::appUrl(http_build_query($q));
    }

    private static function fileName(array $record, Source $s): string
    {
        $base = trim(preg_replace('/[^\w\-. ]+/u', '', (string) ($record['title'] ?? '')) ?? '');
        if ($base === '' || $base === '-') {
            $base = substr(md5((string) $s->url), 0, 10);
        }
        $ext = match ($s->kind) {
            Source::MP4 => 'mp4',
            Source::WEBM => 'webm',
            Source::MKV => 'mkv',
            Source::HLS, Source::TS => 'ts',
            default => 'bin',
        };
        $base = substr($base, 0, 90);
        if (preg_match('/\.' . $ext . '$/i', $base) === 1) {
            // Pages love naming the file after itself, and "clip.mp4.mp4" in the
            // downloads folder reads like a bug rather than a renamed file.
            return $base;
        }
        return $base . '.' . $ext;
    }

    private static function runExtractor(ExtractorInterface $extractor, string $pageUrl, HttpClient $http, ExtractorRegistry $registry): MediaBundle
    {
        try {
            $bundle = $extractor->extract($pageUrl, $http);
        } catch (ExtractException $e) {
            // A specialised extractor that fails should not be the last word:
            // the generic reader often finds what the host-specific rule missed.
            // An intentional refusal is the last word, though, and falling through
            // to the generic reader there would paper over it with a dead link.
            if ($extractor instanceof GenericExtractor || $e->codeName === 'gated') {
                throw $e;
            }
            vd_log('host extractor failed, retrying generic', ['host' => $extractor->name(), 'error' => $e->getMessage()]);
            $bundle = (new GenericExtractor())->extract($pageUrl, $http);
        }
        $bundle->provider = $extractor->name() !== 'generic' ? $extractor->name() : ExtractorRegistry::registrable((string) parse_url($pageUrl, PHP_URL_HOST));
        return $bundle;
    }

    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new ExtractException('Paste a video link first.', '', 'empty');
        }
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $url)) {
            // People paste "youtube.com/watch?v=..." constantly.
            $url = 'https://' . ltrim($url, '/');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ExtractException('That is not a valid link.', '', 'bad_url');
        }
        UrlGuard::assertSafe($url);
        return $url;
    }

    /**
     * A page title that is only the site's own name tells the visitor nothing,
     * and it would also become the filename of the download. Fall back to a
     * plain host label in that case.
     */
    private static function titleFor(string $title, string $pageUrl): string
    {
        $host = ExtractorRegistry::registrable((string) (parse_url($pageUrl, PHP_URL_HOST) ?: ''));
        $core = (string) preg_replace('~\.[a-z]{2,}$~i', '', $host);
        $flat = strtolower((string) preg_replace('~[^a-z0-9]+~', '', $title));
        $flatCore = strtolower((string) preg_replace('~[^a-z0-9]+~', '', $core));
        if ($title === '' || preg_match('~^[0-9a-f]{16,}$~i', trim($title)) || ($flatCore !== '' && (str_starts_with($flat, $flatCore) || $flat === $flatCore))) {
            return self::label($host);
        }
        return $title;
    }

    private static function label(string $host): string
    {
        return ucwords(str_replace('-', ' ', (string) preg_replace('~\.[a-z]{2,}$~i', '', $host)));
    }
}

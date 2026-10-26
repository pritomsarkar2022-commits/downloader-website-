<?php
declare(strict_types=1);

/**
 * The general deep reader, for hosts that have no dedicated rules.
 *
 * It does not guess the site: it opens the page, then every player document the
 * page points at (iframe, embed, meta refresh, a javascript location), then the
 * sidecar config files a player fetches to get its file list. Media urls found at
 * any level are merged, ranked by the shape they appeared in, and handed back for
 * verification, which is the only step that proves a candidate is a real file.
 */
final class DeepFinder
{
    /** Sites this tool will not reach into, whatever wrapper names them. */
    private const SKIP_HOSTS = [
        'youtube.com', 'youtu.be', 'ytimg.com', 'googlevideo.com', 'youtubekids.com',
        'music.youtube.com', 'm.youtube.com',
    ];

    private HttpClient $http;
    private MediaBundle $bundle;
    private int $maxDepth;
    private int $maxPages;
    private int $framesPerPage;
    private int $configsPerPage;
    private int $reserve;
    private float $deadline;

    /** @var array<int,array{url:string,depth:int,referer:string}> */
    private array $queue = [];

    /** @var array<string,true> */
    private array $seen = [];

    /** @var array<string,Source> */
    private array $collected = [];

    public function __construct(HttpClient $http, MediaBundle $bundle)
    {
        $this->http = $http;
        $this->bundle = $bundle;
        $this->maxDepth = (int) cfg('extract.depth', 3);
        $this->maxPages = (int) cfg('extract.max_pages', 8);
        $this->framesPerPage = (int) cfg('extract.frames_per_page', 5);
        $this->configsPerPage = (int) cfg('extract.json_configs', 3);
        // Verification costs one request per candidate. Keep that much budget for
        // the end instead of spending it all on reading pages.
        $this->reserve = (int) cfg('extract.verify_limit', 7);
        $this->deadline = microtime(true) + ((int) cfg('extract.deadline_ms', 18000) / 1000);
    }

    /** @return Source[] */
    public function discover(string $pageUrl, array $headers = []): array
    {
        $this->push($pageUrl, 0, $headers['Referer'] ?? $pageUrl);
        return $this->drain();
    }

    /**
     * Read one document the caller already fetched, so the request a host
     * extractor paid for is not paid for twice, then follow what it points at.
     *
     * @return Source[]
     */
    public function withPage(HttpResponse $res): array
    {
        $this->readDocument($res, 0);
        return $this->drain();
    }

    /** Walk the queue until the budget, the depth or the clock says stop. */
    private function drain(): array
    {
        $pages = 0;
        while ($this->queue && $pages < $this->maxPages) {
            $job = array_shift($this->queue);
            if ($this->http->remaining() <= $this->reserve || microtime(true) > $this->deadline) {
                $this->bundle->notice('The player for that page is nested more deeply than this tool can follow in one request.');
                break;
            }
            $pages++;
            try {
                $res = $this->http->get($job['url'], [
                    'headers' => ['Referer' => $job['referer']],
                    'max_bytes' => (int) cfg('http.page_max_bytes', 2_000_000),
                ]);
            } catch (HttpException|UrlGuardException $e) {
                continue;
            }
            if (!$res->ok()) {
                continue;
            }
            $this->readDocument($res, (int) $job['depth']);
            $res->free();
        }
        return $this->ranked();
    }

    /**
     * Collect the media this document names and queue the documents it delegates
     * to. Candidates from an embed are not a fallback for candidates from the
     * page: a poster file on the page and the real playlist in the frame is the
     * usual shape of these players.
     */
    public function readDocument(HttpResponse $res, int $depth): void
    {
        $this->seen[$this->key($res->finalUrl)] = true;
        $this->seen[$this->key($res->finalUrl, true)] = true;
        $headers = ['Referer' => $res->finalUrl];
        $flat = JsUnpacker::flatten($res->body);
        foreach (MediaSniffer::candidates($flat, $res->finalUrl, $headers) as $s) {
            $this->keep($s, $depth, $res->finalUrl);
        }

        if ($depth >= $this->maxDepth || $this->http->remaining() <= $this->reserve) {
            return;
        }

        foreach (array_slice(MediaSniffer::playerPages($res->body, $res->finalUrl), 0, $this->framesPerPage) as $frame) {
            if (self::skipped($frame)) {
                $this->bundle->notice('A player that site keeps to itself is inside this page, so its file is not reachable from here.');
                continue;
            }
            $this->push($frame, $depth + 1, $res->finalUrl);
        }
        foreach (array_slice(MediaSniffer::configEndpoints($res->body, $res->finalUrl, $this->configsPerPage), 0, $this->configsPerPage) as $cfgUrl) {
            if (self::skipped($cfgUrl)) {
                continue;
            }
            $this->push($cfgUrl, $depth + 1, $res->finalUrl);
        }
    }

    private function keep(Source $s, int $depth, string $from): void
    {
        if (isset($this->collected[$s->url])) {
            return;
        }
        $s->score += max(0, 6 - $depth * 3);
        if ($s->height === null && $s->kind !== Source::HLS) {
            $s->height = self::heightHint($s->url);
        }
        if ($depth > 0) {
            $host = (string) (parse_url($from, PHP_URL_HOST) ?: '');
            $s->note = $s->note ?? ($host !== '' ? 'Found inside the player at ' . $host : null);
        }
        $this->collected[$s->url] = $s;
    }

    /** @return Source[] best first, whatever the walk found */
    public function ranked(): array
    {
        $list = array_values($this->collected);
        usort($list, static function (Source $a, Source $b): int {
            $kind = static fn(Source $s): int => match ($s->kind) {
                Source::MP4 => 4,
                Source::HLS => 3,
                Source::WEBM => 2,
                Source::MKV => 2,
                default => 1,
            };
            return ($b->score <=> $a->score)
                ?: ($kind($b) <=> $kind($a))
                ?: ((int) $b->height <=> (int) $a->height)
                ?: (strlen($a->url) <=> strlen($b->url));
        });
        return $list;
    }

    /** @return array<int,array{url:string,depth:int,referer:string}> what the walk still intends to open */
    public function queued(): array
    {
        return $this->queue;
    }

    private function push(string $url, int $depth, string $referer): void
    {
        $url = (string) preg_replace('~\s~', '', $url);
        if ($url === '' || strlen($url) > 2000) {
            return;
        }
        foreach ([$this->key($url), $this->key($url, true)] as $k) {
            if (isset($this->seen[$k])) {
                return;
            }
            $this->seen[$k] = true;
        }
        $this->queue[] = ['url' => $url, 'depth' => $depth, 'referer' => $referer];
    }

    /** The same player reached with a different token is still the same player. */
    private function key(string $url, bool $pathOnly = false): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($pathOnly) {
            return $host . (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        }
        return $url;
    }

    public static function skipped(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        foreach (self::SKIP_HOSTS as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                return true;
            }
        }
        return false;
    }

    /** "/1080/index.m3u8" and "?h=720" are how hosts spell a quality in a file name. */
    public static function heightHint(string $url): ?int
    {
        $hay = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '') . '?' . (string) (parse_url($url, PHP_URL_QUERY) ?: ''));
        if (preg_match('~(?:^|[^0-9])(2160|1440|1080|720|480|360|240|144)p?(?![0-9])~', $hay, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}

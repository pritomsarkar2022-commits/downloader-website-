<?php
declare(strict_types=1);

/**
 * Shared plumbing for extractors: read a page, decode the js that hides the
 * file, verify a candidate really is video, and chase nested players.
 */
abstract class BaseExtractor implements ExtractorInterface
{
    public function priority(): int
    {
        return 10;
    }

    public function name(): string
    {
        $parts = explode('\\', static::class);
        return strtolower((string) preg_replace('/extractor$/i', '', (string) end($parts)));
    }

    public function hosts(): array
    {
        return [];
    }

    /** Page text with packed/escaped/concatenated js flattened out. */
    protected function readable(HttpResponse $res): string
    {
        return JsUnpacker::flatten($res->body);
    }

    /** @return Source[] */
    protected function sniff(HttpResponse $res, array $headers = []): array
    {
        return MediaSniffer::sourcesFromText($this->readable($res), $res->finalUrl, $headers);
    }

    protected function htmlHeaders(HttpResponse $res): array
    {
        return ['Referer' => $res->finalUrl];
    }

    /**
     * Ask the file host for one byte. This is what turns a regex match into a
     * result we are willing to show: real content-type, real size, real liveness.
     */
    protected function verify(Source $s, HttpClient $http): bool
    {
        if ($s->kind === Source::HLS) {
            return $this->verifyPlaylist($s, $http);
        }
        try {
            $res = $http->get($s->url, [
                'headers' => $s->headers + ['Range' => 'bytes=0-0'],
                'max_bytes' => 65_536,
                'timeout' => 15,
                'accept' => '*/*',
            ]);
            // Some file hosts answer a ranged probe with 416 while the plain
            // request a player makes works fine, so ask once without it.
            if ($res->status === 416) {
                $res = $http->get($s->url, [
                    'headers' => $s->headers,
                    'max_bytes' => 65_536,
                    'timeout' => 15,
                    'accept' => '*/*',
                ]);
            }
        } catch (HttpException $e) {
            $s->note = $e->getMessage();
            return false;
        }
        if ($res->status === 401 || $res->status === 403) {
            return false;
        }
        if (!in_array($res->status, [200, 206], true)) {
            return false;
        }
        $type = strtolower($res->contentType);
        $clen = (int) ($res->header('content-length') ?? 0);
        $total = $res->header('content-range');
        if (is_string($total) && preg_match('#/(\d+)$#', $total, $m)) {
            $s->sizeBytes = (int) $m[1];
        } elseif ($res->status === 200 && $clen > 1) {
            $s->sizeBytes = $clen;
        }
        $looksVideo = $type === '' || str_contains($type, 'video') || str_contains($type, 'octet-stream') || str_contains($type, 'binary');
        $s->mime = $type !== '' ? explode(';', $type)[0] : $s->mime;
        return $looksVideo && ($s->sizeBytes === null || $s->sizeBytes > 1024);
    }

    protected function verifyPlaylist(Source $s, HttpClient $http): bool
    {
        try {
            $res = $http->get($s->url, [
                'headers' => $s->headers,
                'max_bytes' => 3_000_000,
                'accept' => 'application/x-mpegURL,application/vnd.apple.mpegurl,*/*;q=0.8',
            ]);
        } catch (HttpException $e) {
            return false;
        }
        if (!$res->ok() || stripos($res->body, '#EXTM3U') === false) {
            return false;
        }
        $pl = Playlist::parse($res->body, $res->finalUrl);
        if ($pl->sampleAes) {
            $s->note = 'This stream is encrypted in a way that needs its own player, so it cannot be joined into one file.';
            return false;
        }
        if ($pl->isMaster) {
            foreach ($pl->variants as $v) {
                $s->variants[] = [
                    'height' => $v['height'],
                    'bandwidth' => $v['bandwidth'],
                    'codecs' => $v['codecs'],
                    'url' => $v['url'],      // server-side only; the UI gets relay links
                ];
            }
            if (!$s->variants) {
                return false;
            }
            usort($s->variants, static fn(array $a, array $b): int => (int) ($b['height'] ?? 0) <=> (int) ($a['height'] ?? 0));
            $s->height = $s->variants[0]['height'];
            $s->bitrate = $s->variants[0]['bandwidth'];
            // One more read tells us length, segment count and whether the payload
            // is encrypted, which is what the MP4 builder needs to plan the work.
            if ($http->remaining() > 0 && !empty($s->variants[0]['url'])) {
                $this->readVariant($s, (string) $s->variants[0]['url'], $http);
            }
            return true;
        }
        if (!$pl->segments) {
            return false;
        }
        $s->duration = $pl->totalDuration > 0 ? $pl->totalDuration : null;
        $s->variants = [[
            'height' => null,
            'bandwidth' => null,
            'codecs' => null,
            'url' => $s->url,
            'segments' => count($pl->segments),
            'duration' => round($pl->totalDuration, 1),
            'encrypted' => $pl->key !== null,
        ]];
        return true;
    }

    /** Open the chosen media playlist and record what a download would involve. */
    protected function readVariant(Source $s, string $variantUrl, HttpClient $http): void
    {
        if ($variantUrl === $s->url) {
            return;
        }
        try {
            $res = $http->get($variantUrl, [
                'headers' => $s->headers,
                'max_bytes' => 6_000_000,
                'accept' => 'application/x-mpegURL,*/*;q=0.8',
            ]);
        } catch (HttpException|UrlGuardException $e) {
            return;
        }
        if (!$res->ok() || stripos($res->body, '#EXTM3U') === false) {
            return;
        }
        $pl = Playlist::parse($res->body, $res->finalUrl);
        if ($pl->isMaster || !$pl->segments) {
            return;
        }
        $s->duration = $pl->totalDuration > 0 ? $pl->totalDuration : $s->duration;
        $first = 0;
        foreach ($s->variants as $i => $v) {
            if (($v['url'] ?? '') === $variantUrl) {
                $first = $i;
                break;
            }
        }
        $s->variants[$first]['segments'] = count($pl->segments);
        $s->variants[$first]['duration'] = round($pl->totalDuration, 1);
        $s->variants[$first]['encrypted'] = $pl->key !== null;
        if ($pl->key !== null) {
            $s->keyUri = $pl->key['uri'];
            $s->keyMethod = $pl->key['method'];
        }
    }

    /** @param Source[] $sources */
    protected function verifyAll(array $sources, HttpClient $http, MediaBundle $b): array
    {
        $kept = [];
        foreach ($sources as $s) {
            if ($this->verify($s, $http)) {
                $kept[] = $s;
            } else {
                vd_log('candidate rejected', ['url' => $s->url, 'kind' => $s->kind]);
            }
        }
        if ($kept) {
            $b->confidence = 0.85;
        }
        return $kept;
    }

    /**
     * Some pages are only a wrapper around an embed, so the search has to go
     * through documents, not just read one. That job moved to DeepFinder, which
     * reads every level instead of stopping at the first one that names a file.
     */
    protected function newBundle(string $pageUrl, string $provider = ''): MediaBundle
    {
        $b = new MediaBundle();
        $b->pageUrl = $pageUrl;
        $b->provider = $provider !== '' ? $provider : $this->name();
        return $b;
    }

    protected function fillMeta(MediaBundle $b, HttpResponse $res): void
    {
        $b->finalUrl = $res->finalUrl;
        $meta = MediaSniffer::meta($res->body, $res->finalUrl);
        $b->title ??= $meta['title'];
        $b->thumbnail ??= $meta['thumbnail'];
    }
}

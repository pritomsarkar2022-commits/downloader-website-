<?php
declare(strict_types=1);

/**
 * streamfile.net (same t=/s=/e= scheme as the LuluStream backend)
 * The master playlist sits in the page markup literally, inside a path that
 * contains commas, so the url pattern must not stop at a comma.
 */
final class StreamfileExtractor extends BaseExtractor
{
    public function hosts(): array
    {
        return ['streamfile.net', 'streamfile.io', 'streamfile.to', 'streamfiles.site'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl, 'streamfile');
        $res = $http->get($pageUrl, ['headers' => ['Referer' => $pageUrl]]);
        $this->fillMeta($b, $res);
        if (!$res->ok()) {
            throw new ExtractException('Streamfile answered HTTP ' . $res->status . '.', 'The video was probably removed.', 'http_' . $res->status);
        }

        $candidates = [];
        if (preg_match_all('~<source\s+src=["\'](https?://[^"\']+master\.m3u8\?[^"\']+)["\']~i', $res->body, $m)) {
            $candidates = array_merge($candidates, $m[1]);
        }
        if (preg_match_all('~(https?://[a-z0-9.-]+/(?:hls2?|e|fs)/[^"\']+master\.m3u8\?[^"\']+)~i', $res->body, $m2)) {
            $candidates = array_merge($candidates, $m2[1]);
        }
        if (!$candidates) {
            $found = $this->sniff($res, $this->htmlHeaders($res));
            if (!$found) {
                throw new ExtractException('Streamfile shows no stream on that page.', 'The file is gone, or the link is not a video page.', 'no_media');
            }
            $b->sources = $this->verifyAll($found, $http, $b);
            return $b;
        }

        foreach (array_unique($candidates) as $u) {
            $s = Source::make($u, Source::HLS);
            $s->label = 'Video stream';
            $s->headers = ['Referer' => $res->finalUrl];
            $s->tokenExpiryFromQuery();
            $b->addSource($s);
        }
        $b->sources = $this->verifyAll($b->sources, $http, $b);
        if (!$b->sources) {
            throw new ExtractException('Streamfile refused the stream.', 'Their token expired. Run the same link again.', 'token_expired');
        }
        $b->confidence = 0.9;
        return $b;
    }
}

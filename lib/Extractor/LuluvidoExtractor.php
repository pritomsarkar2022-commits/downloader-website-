<?php
declare(strict_types=1);

/**
 * luluvido.com (LuluStream backend)
 * The player config is a jwplayer().setup({...}) hidden in a Dean Edwards packed
 * script. Unpacking is pure string substitution, so no js engine is needed.
 */
final class LuluvidoExtractor extends BaseExtractor
{
    public function hosts(): array
    {
        return ['luluvido.com', 'luluvid.com', 'lulustream.com', 'lulu.gs', 'tnmr.org'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl, 'luluvido');
        $res = $http->get($pageUrl, ['headers' => ['Referer' => $pageUrl]]);
        $this->fillMeta($b, $res);
        if (!$res->ok()) {
            throw new ExtractException('That Luluvido page returned HTTP ' . $res->status . '.', 'The video is likely removed.', 'http_' . $res->status);
        }

        $flat = JsUnpacker::joinConcatenations(JsUnpacker::unescape(JsUnpacker::unpack($res->body)));

        $urls = [];
        if (preg_match_all('~file\s*:\s*["\'](https?://[^"\']+\.m3u8\?[^"\']+)["\']~i', $flat, $m)) {
            $urls = $m[1];
        }
        if (!$urls) {
            $urls = array_values(array_filter(MediaSniffer::mediaUrls($flat), static fn($u) => str_contains($u, '.m3u8')));
        }
        if (!$urls) {
            throw new ExtractException('The player on that page never revealed a stream.', 'Their packing changed, or the video needs their app.', 'no_media');
        }

        foreach (array_unique($urls) as $u) {
            $s = Source::make(url_absolutize($res->finalUrl, $u), Source::HLS);
            $s->label = 'Video stream';
            $s->headers = ['Referer' => $res->finalUrl];
            $s->tokenExpiryFromQuery();
            $b->addSource($s);
        }
        $b->sources = $this->verifyAll($b->sources, $http, $b);
        if (!$b->sources) {
            throw new ExtractException('Luluvido refused the stream request.', 'Try the link again; their token is short lived.', 'token_expired');
        }
        $encrypted = false;
        foreach ($b->sources as $s) {
            foreach ($s->variants as $v) {
                if (!empty($v['encrypted'])) {
                    $encrypted = true;
                }
            }
        }
        if ($encrypted) {
            $b->notice('This stream is AES-128 encrypted in transit. Preview works, and the MP4 save decrypts it as it goes.');
        }
        $b->confidence = 0.9;
        return $b;
    }
}

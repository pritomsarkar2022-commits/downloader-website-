<?php
declare(strict_types=1);

/**
 * mxdrop.click and the MixDrop family
 *
 * The /f/ page is only a frame around /e/, and /e/ keeps its player config in a
 * Dean Edwards packed script. Unpacked it is plain assignment:
 *   MDCore.wurl = "//ok2cl0c87o.mxcontent.net/v2/vngdxnpetkgneq.mp4?s=..&e=..";
 * The cdn host is different for every file, so nothing here may be hardcoded,
 * and their edge answers 403 to a request that carries a Referer it did not ask
 * for. The token lives in the query and the link is good for about four hours.
 */
final class MxdropExtractor extends BaseExtractor
{
    public function hosts(): array
    {
        return ['mxdrop.click', 'mxdrop.to', 'mxdrop.ch', 'mixdrop.to', 'mixdrop.co', 'mixdrop.ch', 'mixdrop.ps', 'mxcontent.net'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl, 'mxdrop');

        $res = $http->get($pageUrl, ['headers' => ['Referer' => $pageUrl]]);
        $this->fillMeta($b, $res);
        $embed = self::embedUrl($res->body, $res->finalUrl);
        if ($embed !== '' && $embed !== $res->finalUrl) {
            $res = $http->get($embed, ['headers' => ['Referer' => $res->finalUrl]]);
        }
        if (!$res->ok()) {
            throw new ExtractException('MixDrop answered HTTP ' . $res->status . '.', 'The file was probably removed.', 'http_' . $res->status);
        }

        $flat = JsUnpacker::joinConcatenations(JsUnpacker::unescape(JsUnpacker::unpack($res->body)));
        if (!preg_match('~MDCore\.wurl\s*=\s*["\']([^"\']+)["\']~', $flat, $m)) {
            throw new ExtractException('That MixDrop page never named a file.', 'The video may still be processing, or it needs their app.', 'no_media');
        }
        $media = url_absolutize($res->finalUrl, $m[1]);
        if (preg_match('~MDCore\.vtitle\s*=\s*["\']([^"\']+)["\']~', $flat, $t)) {
            $b->title = trim($t[1]) !== '' ? trim($t[1]) : $b->title;
        }
        if (preg_match('~MDCore\.poster\s*=\s*["\']([^"\']+)["\']~', $flat, $p)) {
            $b->thumbnail = $b->thumbnail ?? url_absolutize($res->finalUrl, $p[1]);
        }

        $s = Source::make($media, str_contains($media, '.m3u8') ? Source::HLS : Source::MP4);
        $s->label = 'Original file';
        $s->headers = ['Referer' => $res->finalUrl];
        $s->tokenExpiryFromQuery();
        $b->addSource($s);

        $kept = $this->verifyAll($b->sources, $http, $b);
        if (!$kept) {
            // Their edge is inconsistent about the Referer, so ask once without it.
            $s->headers = [];
            $kept = $this->verifyAll([$s], $http, $b);
        }
        $b->sources = $kept;
        if (!$b->sources) {
            throw new ExtractException('MixDrop refused the file request.', 'Their link is short lived. Paste the address again.', 'token_expired');
        }
        $b->confidence = 0.9;
        return $b;
    }

    /** The /e/ address the /f/ page frames, if the markup carries one. */
    public static function embedUrl(string $html, string $baseUrl): string
    {
        if (preg_match('~<iframe[^>]+src=["\']([^"\']*?/e/[A-Za-z0-9]+)["\']~i', $html, $m)) {
            return url_absolutize($baseUrl, $m[1]);
        }
        if (preg_match('~^(/f/)([A-Za-z0-9]+)$~', (string) (parse_url($baseUrl, PHP_URL_PATH) ?: ''), $m)) {
            return (string) parse_url($baseUrl, PHP_URL_SCHEME) . '://' . (string) parse_url($baseUrl, PHP_URL_HOST) . '/e/' . $m[2];
        }
        return '';
    }
}

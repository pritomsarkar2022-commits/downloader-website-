<?php
declare(strict_types=1);

/**
 * cloudfam.io
 *
 * Three hops and no js to decode. The short link redirects to prestream.php,
 * which hands out a token when the page-load ping is posted back, and stream.php
 * then renders a plain <video><source> with the file on their cdn. The token is
 * tied to the session cookie, so all three hops have to share one jar, which the
 * client already does. The countdown on their page is decoration; the server
 * answers immediately.
 */
final class CloudfamExtractor extends BaseExtractor
{
    public function hosts(): array
    {
        return ['cloudfam.io', 'files.cloudfam.io'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl, 'cloudfam');

        $gate = $http->get($pageUrl, ['headers' => ['Referer' => $pageUrl]]);
        $this->fillMeta($b, $gate);
        $prestream = $gate->finalUrl;
        parse_str((string) (parse_url($prestream, PHP_URL_QUERY) ?: ''), $q);
        $slug = (string) ($q['slug'] ?? '');
        if ($slug === '' && preg_match('~/(?:cf|)([a-f0-9]{12,20})$~i', $pageUrl, $m)) {
            $slug = $m[1];
        }
        if ($slug === '') {
            throw new ExtractException('That does not look like a Cloudfam file link.', 'Use the short address, the one like cloudfam.io/cf3b9cf55ec3.', 'bad_url');
        }

        $token = '';
        try {
            $ping = $http->post($prestream, '', [
                'form' => ['action' => 'log_first_page', 'adblock_detected' => '0'],
                'headers' => ['Referer' => $prestream, 'Origin' => (string) parse_url($prestream, PHP_URL_SCHEME) . '://' . (string) parse_url($prestream, PHP_URL_HOST)],
                'timeout' => 15,
            ]);
            $data = $ping->json();
            $token = is_array($data) ? (string) ($data['token'] ?? '') : '';
        } catch (HttpException $e) {
            vd_log('cloudfam ping failed', ['err' => $e->getMessage()]);
        }

        $origin = (string) parse_url($pageUrl, PHP_URL_SCHEME) . '://' . (string) parse_url($pageUrl, PHP_URL_HOST);
        $stream = null;
        $tries = $token !== '' ? [$origin . '/stream.php?slug=' . rawurlencode($slug) . '&token=' . rawurlencode($token)] : [];
        foreach ($tries as $url) {
            $res = $http->get($url, ['headers' => ['Referer' => $prestream]]);
            if (!$res->ok()) {
                continue;
            }
            $media = self::firstSourceTag($res->body, $res->finalUrl);
            if ($media !== '') {
                $stream = $media;
                break;
            }
        }
        if ($stream === null) {
            throw new ExtractException('Cloudfam would not open the file.', 'Their page counts down before it serves the video. Open it in your browser, wait it out, then paste the same address again.', 'no_media');
        }

        $s = Source::make($stream, str_contains($stream, '.m3u8') ? Source::HLS : Source::MP4);
        $s->label = 'Original file';
        $s->headers = [];
        $s->tokenExpiryFromQuery();
        $b->addSource($s);

        $b->sources = $this->verifyAll($b->sources, $http, $b);
        if (!$b->sources) {
            throw new ExtractException('The Cloudfam file address did not answer.', 'Their link is minted per session. Paste the page address again.', 'token_expired');
        }
        $b->confidence = 0.9;
        return $b;
    }

    /** The src of the first <source> or <video src> in the markup, percent decoded. */
    public static function firstSourceTag(string $html, string $baseUrl): string
    {
        if (preg_match('~<source[^>]+src=["\']([^"\']+)["\']~i', $html, $m)) {
            return url_absolutize($baseUrl, rawurldecode(trim($m[1])));
        }
        if (preg_match('~<video[^>]+src=["\']([^"\']+)["\']~i', $html, $m)) {
            return url_absolutize($baseUrl, rawurldecode(trim($m[1])));
        }
        return '';
    }
}

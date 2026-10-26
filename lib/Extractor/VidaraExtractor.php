<?php
declare(strict_types=1);

/**
 * vidara.to
 * The watch page is a wrapper: the player lives on a rotating embed domain, and
 * that domain's /api/stream mints a token bound to the ip that asked for it.
 * Because our server both mints the token and relays the bytes, the ip stays
 * consistent. Handing this url to the browser directly would break.
 */
final class VidaraExtractor extends BaseExtractor
{
    public function hosts(): array
    {
        return ['vidara.to', 'vidara.sx', 'dstyladsg.com'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl, 'vidara');

        $res = null;
        foreach ([$pageUrl, $pageUrl] as $attempt => $target) {
            try {
                $res = $http->get($target, ['headers' => ['Referer' => $target]]);
                break;
            } catch (HttpException $e) {
                if ($attempt === 1) {
                    throw new ExtractException('Vidara is not answering right now.', 'Their server throws occasional connection errors. Try again in a minute.', 'host_down');
                }
                sleep(1);
            }
        }
        if ($res === null) {
            throw new ExtractException('Vidara is not answering right now.', 'Try again in a minute.', 'host_down');
        }
        $this->fillMeta($b, $res);
        // 525 is Cloudflare failing to reach their origin, which happens in bursts.
        if ($res->status === 525 || $res->status === 521 || $res->status === 523) {
            throw new ExtractException('Vidara origin is down at the moment (HTTP ' . $res->status . ').', 'This clears on its own. Try again shortly.', 'host_down');
        }
        if (!$res->ok()) {
            throw new ExtractException('Vidara answered HTTP ' . $res->status . '.', 'The video may have been removed.', 'http_' . $res->status);
        }

        $embed = null;
        if (preg_match('~<iframe\s+src=["\'](https?://[^"/]+/(?:e|embed)/[A-Za-z0-9]+)["\']~i', $res->body, $m)) {
            $embed = $m[1];
        } elseif (preg_match('~https?://[a-z0-9.-]+/(?:e|embed)/([A-Za-z0-9]+)~i', $res->body, $m2)) {
            $embed = $m2[0];
        }
        if ($embed === null) {
            $found = $this->verifyAll($this->sniff($res, $this->htmlHeaders($res)), $http, $b);
            if ($found) {
                $b->sources = $found;
                return $b;
            }
            throw new ExtractException('No Vidara player found inside that page.', 'Paste the watch link, not the share text.', 'no_media');
        }

        $filecode = basename((string) parse_url($embed, PHP_URL_PATH));
        $embedOrigin = (string) parse_url($embed, PHP_URL_SCHEME) . '://' . (string) parse_url($embed, PHP_URL_HOST);

        $er = null;
        if ($http->remaining() > 0 && !str_starts_with($embed, $res->finalUrl)) {
            try {
                $er = $http->get($embed, ['headers' => ['Referer' => $res->finalUrl]]);
            } catch (HttpException $e) {
                $er = null;
            }
        }

        $body = json_encode([
            'filecode' => $filecode,
            'device' => 'web',
            'codecs' => ['avc1.42E01E', 'mp4a.40.2'],
        ]);
        try {
            $api = $http->post($embedOrigin . '/api/stream', (string) $body, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Origin' => $embedOrigin,
                    'Referer' => $embed,
                ],
                'timeout' => 15,
            ]);
        } catch (HttpException $e) {
            throw new ExtractException('The Vidara player server refused us.', 'Their embed host was unreachable. Try again shortly.', 'api_down');
        }
        $data = $api->json();
        $stream = $data['streaming_url'] ?? $data['sx'] ?? null;
        if (!is_string($stream) || $stream === '') {
            throw new ExtractException('Vidara returned no stream for that file.', 'The video is gone or playback is disabled.', 'no_media');
        }
        $b->title = $b->title ?? ($data['title'] ?? null);
        if (!empty($data['thumbnail'])) {
            $b->thumbnail = $b->thumbnail ?? (string) $data['thumbnail'];
        }

        $s = Source::make($stream, Source::HLS);
        $s->label = 'Video stream';
        $s->headers = ['Referer' => $embed, 'Origin' => $embedOrigin];
        $s->tokenExpiryFromQuery();
        $s->needsProxy = true;
        $s->note = 'Vidara binds its stream token to the server ip, so this one only works through our relay.';
        $b->addSource($s);

        $b->sources = $this->verifyAll($b->sources, $http, $b);
        if (!$b->sources) {
            throw new ExtractException('Vidara refused the stream it just issued.', 'Try the link once more.', 'token_expired');
        }
        $b->confidence = 0.85;
        return $b;
    }
}

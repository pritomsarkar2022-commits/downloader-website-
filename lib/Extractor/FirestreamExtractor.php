<?php
declare(strict_types=1);

/**
 * firestream.to -> firestream.site
 * The page carries a single-use base64 blob; posting it to /api/videos/<slug>/resolve
 * returns the signed playlist. The blob dies on reuse (502) and on age (403), so this
 * must happen inside one analysis request and must never be cached.
 */
final class FirestreamExtractor extends BaseExtractor
{
    public function hosts(): array
    {
        return ['firestream.to', 'firestream.site', 'firestream.net', 'firestrm.com'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl, 'firestream');
        $res = $http->get($pageUrl, ['headers' => ['Referer' => $pageUrl]]);
        $this->fillMeta($b, $res);
        if (!$res->ok()) {
            throw new ExtractException('Firestream answered HTTP ' . $res->status . '.', 'The video may be private or removed.', 'http_' . $res->status);
        }

        $slug = null;
        if (preg_match('~/(?:e|v|embed|f)/([A-Za-z0-9]+)~', $res->finalUrl, $s)) {
            $slug = $s[1];
        } elseif (preg_match('~["\']slug["\']\s*:\s*["\']([A-Za-z0-9]+)~', $res->body, $s2)) {
            $slug = $s2[1];
        }
        if ($slug === null) {
            throw new ExtractException('Could not read the video id from that Firestream link.', '', 'bad_url');
        }

        if (!preg_match('~<script\s+id="token-blob"\s+type="text/plain">\s*([^<]+?)\s*</script>~is', $res->body, $m)) {
            // Fall back to the general reader in case they inline the url again.
            $found = $this->verifyAll($this->sniff($res, $this->htmlHeaders($res)), $http, $b);
            if ($found) {
                $b->sources = $found;
                return $b;
            }
            throw new ExtractException('Firestream no longer puts a resolve token in the page.', 'Their player changed; this host needs an update.', 'no_token');
        }
        $blob = trim($m[1]);

        $origin = (string) parse_url($res->finalUrl, PHP_URL_SCHEME) . '://' . (string) parse_url($res->finalUrl, PHP_URL_HOST);
        $api = $origin . '/api/videos/' . rawurlencode($slug) . '/resolve';
        try {
            $json = $http->post($api, json_encode(['blob' => $blob]), [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Origin' => $origin, 'Referer' => $res->finalUrl],
                'timeout' => 15,
            ]);
        } catch (HttpException $e) {
            throw new ExtractException('Firestream refused to hand out the stream.', 'Their token is single use, so this is usually a retry away.', 'resolve_failed');
        }
        if ($json->status === 403 || str_contains($json->body, 'Token expired')) {
            throw new ExtractException('Firestream said that token was stale.', 'Try the link again in a few seconds.', 'token_expired');
        }
        if ($json->status === 502) {
            throw new ExtractException('Firestream counted that token as already used.', 'Reload the video page and paste it again.', 'blob_used');
        }
        $data = $json->json();
        $signed = [];
        foreach (['signedVideoUrl' => 'Best quality', 'signedVideoSdUrl' => 'Standard quality'] as $k => $label) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                $s = Source::make($data[$k], MediaSniffer::kindOf($data[$k]) ?? Source::HLS);
                $s->kind = str_contains($data[$k], '.m3u8') ? Source::HLS : $s->kind;
                $s->label = $label;
                $s->headers = ['Referer' => $res->finalUrl, 'Origin' => $origin];
                $s->tokenExpiryFromQuery();
                if (!empty($data['fileSize'])) {
                    $s->sizeBytes = (int) $data['fileSize'];
                }
                $signed[] = $s;
            }
        }
        if (!$signed) {
            throw new ExtractException('Firestream returned a resolve reply with no url in it.', '', 'no_media');
        }
        $b->title = $b->title ?? ($data['title'] ?? null);
        if (!empty($data['thumbnail'])) {
            $b->thumbnail = $b->thumbnail ?? (string) $data['thumbnail'];
        }

        $b->sources = $this->verifyAll($signed, $http, $b);
        if (!$b->sources) {
            throw new ExtractException('The stream link Firestream gave is no longer live.', 'Run it again right away; those links expire in about two hours.', 'token_expired');
        }
        $b->confidence = 0.9;
        return $b;
    }
}

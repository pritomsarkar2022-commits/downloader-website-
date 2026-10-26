<?php
declare(strict_types=1);

/**
 * vixeo.io (VidSonic / StreamSonic white label)
 *
 * The watch page carries its player config as a base64 json blob on
 * <div id="streamsonic-player-root" data-config="...">. Inside it the source is
 * stored as hex chunks separated by |, and each chunk has to be hex decoded,
 * joined and then reversed end to end before it reads as a url. The result is a
 * signed HLS playlist on their cdn.
 */
final class VixeoExtractor extends BaseExtractor
{
    public function hosts(): array
    {
        return ['vixeo.io', 'vidsonic.net', 'streamsonic.net'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl, 'vixeo');
        $res = $http->get($pageUrl, ['headers' => ['Referer' => $pageUrl]]);
        if (!$res->ok()) {
            throw new ExtractException('Vixeo answered HTTP ' . $res->status . '.', 'The file may be gone, or the link is wrong.', 'http_' . $res->status);
        }
        $this->fillMeta($b, $res);

        if (!preg_match('~data-config="([^"]+)"~', $res->body, $m)) {
            throw new ExtractException('That Vixeo page has no player on it.', 'Use the address of the watch page, the one with /e/ in it.', 'no_config');
        }
        $cfg = json_decode(base64_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), true), true);
        if (!is_array($cfg)) {
            throw new ExtractException('The Vixeo player config did not read.', 'Their page changed shape.', 'bad_config');
        }

        $media = self::decodeSource((string) ($cfg['source'] ?? ''));
        if ($media === '') {
            throw new ExtractException('Vixeo gave no stream address.', 'The video is likely removed or still processing.', 'no_media');
        }
        if (!empty($cfg['title']) && is_string($cfg['title'])) {
            $b->title = $cfg['title'];
        }
        if (!empty($cfg['poster']) && is_string($cfg['poster'])) {
            $b->thumbnail = $b->thumbnail ?? $cfg['poster'];
        }

        $isHls = str_contains($media, '.m3u8');
        $s = Source::make($media, $isHls ? Source::HLS : Source::MP4);
        $s->label = $isHls ? 'Video stream' : 'Original file';
        // The cdn answers on the token in the query alone; a Referer of ours only
        // risks tripping their hotlink check.
        $s->headers = [];
        $s->tokenExpiryFromQuery();
        $b->addSource($s);

        $b->sources = $this->verifyAll($b->sources, $http, $b);
        if (!$b->sources) {
            throw new ExtractException('The stream Vixeo handed out is not answering.', 'Their link is minted per request. Paste the address again.', 'token_expired');
        }
        $b->confidence = 0.9;
        return $b;
    }

    /** Hex chunks split by |, decoded, joined, then the whole string reversed. */
    public static function decodeSource(string $blob): string
    {
        $blob = trim($blob);
        if ($blob === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $blob)) {
            return $blob;
        }
        $out = '';
        foreach (explode('|', $blob) as $chunk) {
            $bin = preg_match('~^([0-9a-f]{2,})$~i', trim($chunk), $m) ? (string) @hex2bin($m[1]) : '';
            $out .= $bin;
        }
        return $out === '' ? '' : strrev($out);
    }
}

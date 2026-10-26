<?php
declare(strict_types=1);

/**
 * playmate.to
 * A Vite single page app: the html holds no media at all. The player posts the
 * filecode to /api/s and gets back obfuscated keys, where sx is the stream url.
 * The stream files are disguised as .txt and .css, so nothing may be judged by
 * extension here.
 */
final class PlaymateExtractor extends BaseExtractor
{
    public function hosts(): array
    {
        return ['playmate.to', 'playmate.net', 'plauymito.live'];
    }

    public function priority(): int
    {
        return 20;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl, 'playmate');
        $filecode = null;
        if (preg_match('~/(?:watch|embed|v|f)/([A-Za-z0-9]+)~', $pageUrl, $m)) {
            $filecode = $m[1];
        } elseif (preg_match('~^([A-Za-z0-9]{8,20})$~', trim(parse_url($pageUrl, PHP_URL_PATH) ?: ''), $m2)) {
            $filecode = $m2[1];
        }
        if ($filecode === null) {
            throw new ExtractException('That does not look like a Playmate video link.', 'Use the address of the watch page.', 'bad_url');
        }

        $origin = (string) parse_url($pageUrl, PHP_URL_SCHEME) . '://' . (string) parse_url($pageUrl, PHP_URL_HOST);
        $body = json_encode(['c' => $filecode, 'd' => 'web']);
        if ($body === false) {
            throw new ExtractException('Could not build the request.', '', 'server');
        }
        try {
            $res = $http->post($origin . '/api/s', $body, [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Origin' => $origin, 'Referer' => $origin . '/'],
                'timeout' => 15,
            ]);
        } catch (HttpException $e) {
            throw new ExtractException('Playmate did not answer.', 'Their service was unreachable or is rate limiting us.', 'api_down');
        }
        if ($res->status === 404) {
            throw new ExtractException('Playmate has no video with that code.', 'The file was deleted.', 'not_found');
        }
        $data = $res->json();
        $stream = $data['sx'] ?? $data['streaming_url'] ?? null;
        if (!is_string($stream) || $stream === '') {
            throw new ExtractException('Playmate refused to give a stream for that video.', 'The owner may have disabled playback, or the file is gone.', 'no_media');
        }
        $b->title = $b->title ?? ($data['tx'] ?? null);
        if (!empty($data['ix'])) {
            $b->thumbnail = $b->thumbnail ?? (string) $data['ix'];
        }

        // Their master.txt is a playlist no matter what the filename claims.
        $s = Source::make($stream, Source::HLS);
        $s->label = 'Video stream';
        $s->headers = ['Origin' => $origin, 'Referer' => $origin . '/'];
        $b->addSource($s);

        $b->sources = $this->verifyAll($b->sources, $http, $b);
        if (!$b->sources) {
            throw new ExtractException('The stream Playmate handed out is not answering.', 'Call the link again; their url is minted per request.', 'token_expired');
        }
        if (!empty($data['kx']) && is_array($data['kx'])) {
            $b->notice('This video has subtitle tracks available.');
        }
        $b->confidence = 0.88;
        return $b;
    }
}

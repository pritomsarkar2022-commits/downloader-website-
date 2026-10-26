<?php
declare(strict_types=1);

/**
 * Everything that is not a known special case. This is where the general formula
 * lives: read the page, read the players it delegates to, read the config files
 * those players fetch, then prove the candidates by asking the file host for bytes.
 */
final class GenericExtractor extends BaseExtractor
{
    private string $providerName = 'generic';

    public function setProvider(string $host): void
    {
        $this->providerName = $host;
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function priority(): int
    {
        return 1;
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $b = $this->newBundle($pageUrl);

        $res = $http->get($pageUrl, [
            'headers' => ['Referer' => $pageUrl],
            // A pasted address that ends in a file extension is worth one small
            // read: a copied m3u8 from a network tab is a file, while a page called
            // something.webm is a page. What the host answers is the only proof.
            'max_bytes' => MediaSniffer::kindOf($pageUrl) === null
                ? (int) cfg('http.page_max_bytes', 2_000_000)
                : 65_536,
            'accept' => '*/*',
        ]);

        if (MediaSniffer::kindOf($res->finalUrl) !== null && !$res->isHtml()) {
            $s = Source::make($res->finalUrl, (string) MediaSniffer::kindOf($res->finalUrl));
            $s->headers = ['Referer' => $pageUrl];
            $s->tokenExpiryFromQuery();
            $b->finalUrl = $res->finalUrl;
            if ($this->verify($s, $http)) {
                $b->sources = [$s];
                $b->confidence = 0.8;
                $this->labelSources($b);
                return $b;
            }
            throw new ExtractException(
                'That file address did not answer.',
                'Its link may have expired. Paste the address of the page that shows the player.',
                'file_refused'
            );
        }

        // It was a page after all, so read the whole of it.
        if (MediaSniffer::kindOf($pageUrl) !== null) {
            $res = $http->get($pageUrl, ['headers' => ['Referer' => $pageUrl], 'accept' => 'text/html,*/*;q=0.8']);
        }

        $this->fillMeta($b, $res);

        if ($res->status === 401) {
            throw new ExtractException(
                'That page needs a sign in.',
                'This tool does not use accounts, so it stops there. Paste a page that is open to visitors.',
                'gated'
            );
        }
        if ($res->status === 403) {
            throw new ExtractException(
                'That site only answers its own visitors.',
                'A browser check sits between this server and the page, and this tool does not go around one. Open the link in your own browser, then paste the address of the page that shows the player.',
                'gated'
            );
        }
        if ($res->status === 404 || $res->status === 410) {
            throw new ExtractException(
                'That page is not there.',
                'Check the link, or paste the address of the page that shows the player.',
                'not_found'
            );
        }

        if (self::deadPageSignal($res->body)) {
            throw new ExtractException('That file is no longer on the video host.', 'The page exists but says the video was removed or is not available in your country.', 'file_gone');
        }

        $sources = (new DeepFinder($http, $b))->withPage($res);
        $res->free();

        if (!$sources) {
            throw new ExtractException(
                'No video file was found on that page.',
                'The page loads its player with javascript after the fact, or the link is not a video page. Paste the address of the page that shows the player.',
                'no_media'
            );
        }

        $probe = array_slice($sources, 0, max(2, (int) cfg('extract.verify_limit', 6)));
        $b->sources = $this->verifyAll($probe, $http, $b);
        if (!$b->sources) {
            // A candidate that will not answer is not a result. Showing one only
            // moves the failure to the moment somebody presses download.
            throw new ExtractException(
                'That page names a video, but the host will not serve the file.',
                'Its player works with its own session only. Paste the address of the player page, or try again in a minute.',
                'file_refused'
            );
        }
        $this->labelSources($b);
        return $b;
    }

    /**
     * A quality written into the file address is the only label the host itself
     * offers, so it is worth showing next to the verified size.
     */
    private function labelSources(MediaBundle $b): void
    {
        foreach ($b->sources as $s) {
            if ($s->label !== null) {
                continue;
            }
            $base = match ($s->kind) {
                Source::HLS => 'Video stream',
                Source::WEBM => 'WebM video',
                Source::TS => 'Raw stream',
                Source::MKV => 'MKV video',
                default => 'MP4 video',
            };
            $s->label = $s->height !== null ? $s->height . 'p ' . strtolower($base) : $base;
        }
    }

    private static function deadPageSignal(string $body): bool
    {
        $needle = [
            'video not found', 'file does not exist', 'file not found',
            'this video has been removed', 'video has been deleted',
            'not available in your country', 'account has been suspended',
            'file is temporarily unavailable',
        ];
        $hay = strtolower(substr($body, 0, 200_000));
        foreach ($needle as $n) {
            if (str_contains($hay, $n)) {
                return true;
            }
        }
        return false;
    }
}

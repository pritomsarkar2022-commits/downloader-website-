<?php
declare(strict_types=1);

/**
 * The one host this tool answers about on purpose.
 *
 * YouTube's player does not hand out a file: it runs a check inside the browser and
 * signs every stream against that session. Reaching the file from a server means
 * going around that check, which both their terms and this tool's rules say no to,
 * so the honest answer is better than an empty result that looks like a bug.
 */
final class YouTubeExtractor implements ExtractorInterface
{
    public function hosts(): array
    {
        return ['youtube.com', 'youtu.be', 'youtube-nocookie.com'];
    }

    public function priority(): int
    {
        return 60;
    }

    public function name(): string
    {
        return 'youtube';
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        throw new ExtractException(
            'YouTube keeps its videos inside its own player.',
            'That site signs every stream for the browser that asked for it, and taking the file from a server would mean going around their check. Paste a video page from any other host instead.',
            'gated'
        );
    }
}

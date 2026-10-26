<?php
declare(strict_types=1);

/**
 * Hosts people keep asking for that cannot be served from a server.
 *
 * Each entry is a gate we will not try to defeat: a captcha, a browser challenge,
 * a login, or a redirect loop that only a js engine can walk. Answering with the
 * reason is better than letting the general reader come back empty and look broken.
 */
final class BlockedExtractor implements ExtractorInterface
{
    private const REASONS = [
        'frdl.by' => ['a captcha check and a 60 second timer', 'Freedl'],
        'freedl.ink' => ['a captcha check and a 60 second timer', 'Freedl'],
        'flash-files.com' => ['a browser security check', 'Flash Files'],
        'torupload.com' => ['a browser security check', 'TorUpload'],
        'torlock.com' => ['a browser security check', 'TorUpload'],
        'upfilesgo.com' => ['a login style token loop', 'UpFiles'],
        'upfiles.com' => ['a login style token loop', 'UpFiles'],
        'file-upload.org' => ['a captcha check', 'File-Upload'],
        'uptobox.com' => ['an account and a payment plan', 'Uptobox'],
        'mixdrop.ac' => ['an account', 'MixDrop'],
    ];

    public function hosts(): array
    {
        return array_keys(self::REASONS);
    }

    public function priority(): int
    {
        return 60;
    }

    public function name(): string
    {
        return 'blocked';
    }

    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle
    {
        $host = ExtractorRegistry::registrable((string) (parse_url($pageUrl, PHP_URL_HOST) ?: ''));
        [$gate, $brand] = self::REASONS[$host] ?? ['a check this tool does not attempt', 'That site'];
        throw new ExtractException(
            $brand . ' puts ' . $gate . ' between the visitor and the file.',
            'That is their rule, and this tool does not try to get around it. Open the link in your own browser and pass their check, then the file is theirs to give.',
            'gated'
        );
    }
}

<?php
declare(strict_types=1);

final class HttpResponse
{
    /** @param array<string,string> $headers lower-cased keys */
    public function __construct(
        public readonly int $status,
        public string $body,
        public readonly array $headers,
        public readonly string $contentType,
        public string $finalUrl,
        public readonly int $downloadedBytes,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isHtml(): bool
    {
        return str_contains(strtolower($this->contentType), 'html')
            || str_contains(strtolower($this->contentType), 'xml')
            || preg_match('#<!DOCTYPE|<html#i', substr($this->body, 0, 4096)) === 1;
    }

    public function json(array $default = []): array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : $default;
    }

    public function free(): void
    {
        $this->body = '';
    }
}

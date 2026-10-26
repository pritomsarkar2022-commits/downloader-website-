<?php
declare(strict_types=1);

interface ExtractorInterface
{
    /** Domains this extractor owns, matched against the registrable host. */
    public function hosts(): array;

    /** Higher wins when several extractors claim a host. */
    public function priority(): int;

    public function name(): string;

    /** @param string[] $visited guards against redirect loops between players */
    public function extract(string $pageUrl, HttpClient $http, array $visited = []): MediaBundle;
}

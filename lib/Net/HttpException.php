<?php
declare(strict_types=1);

/** Raised when an outbound request fails or is refused, with the remote status attached. */
final class HttpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $url = '')
    {
        parent::__construct($message);
    }
}

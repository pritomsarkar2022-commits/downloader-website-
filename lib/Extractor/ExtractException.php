<?php
declare(strict_types=1);

/**
 * Raised when a host refuses to give up a usable file. Carries a short,
 * user-facing hint because these messages render straight into the result card.
 */
final class ExtractException extends RuntimeException
{
    public function __construct(string $message, public readonly string $hint = '', public readonly string $codeName = 'extract_failed')
    {
        parent::__construct($message);
    }
}

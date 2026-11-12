<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Config;

use RuntimeException;

/**
 * config.php is missing or invalid; the server refuses to start (SPEC.md 8).
 *
 * The messages name config keys only, never their values, so they are safe for the error log.
 */
final class ConfigException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid configuration: ' . implode('; ', $errors));
    }
}

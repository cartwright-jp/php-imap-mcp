<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

use RuntimeException;

final class AuthenticationFailed extends RuntimeException
{
    public const MISSING = 'missing';
    public const MALFORMED = 'malformed';
    public const INVALID = 'invalid';

    /**
     * @param self::MISSING|self::MALFORMED|self::INVALID $reason recorded in the audit log, never sent to the client
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Authentication failed: ' . $reason);
    }
}

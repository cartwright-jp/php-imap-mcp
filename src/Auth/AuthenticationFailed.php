<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

use RuntimeException;

final class AuthenticationFailed extends RuntimeException
{
    public const MISSING = 'missing';
    public const MALFORMED = 'malformed';
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const REVOKED = 'revoked';
    /** The OAuth token was issued for another resource (RFC 8707 audience). */
    public const AUDIENCE = 'audience';

    /**
     * @param self::* $reason recorded in the audit log, never sent to the client
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Authentication failed: ' . $reason);
    }
}

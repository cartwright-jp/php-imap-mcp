<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

/**
 * Who an authenticated request acts for: the mail account, plus identifiers for the audit log.
 */
final class Principal
{
    public function __construct(
        public readonly Account $account,
        /** The OAuth client the token was issued to. */
        public readonly string $clientId,
        /** Shared by every token of one login; used to revoke that login (bin/revoke-grant.php). */
        public readonly string $grantId,
    ) {
    }
}

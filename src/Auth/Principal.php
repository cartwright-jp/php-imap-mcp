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
        /** Token label (token mode) or OAuth client id (imap_oauth mode). */
        public readonly string $label,
        /** OAuth grant id shared by every token of one login, for revocation; null in token mode. */
        public readonly ?string $grantId = null,
    ) {
    }
}

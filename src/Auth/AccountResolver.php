<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

/**
 * Decides which mail account an authenticated request uses (SPEC.md 4).
 *
 * MVP: look the token's account up in config.php. v0.3: decrypt a sealed token instead.
 */
interface AccountResolver
{
    /**
     * @throws \RuntimeException when the account cannot be resolved
     */
    public function resolve(TokenEntry $token): Account;
}

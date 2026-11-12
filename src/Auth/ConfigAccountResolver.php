<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

use RuntimeException;

/**
 * MVP AccountResolver: the token entry names an account in config.php.
 */
final class ConfigAccountResolver implements AccountResolver
{
    /**
     * @param array<string, Account> $accounts
     */
    public function __construct(private readonly array $accounts)
    {
    }

    public function resolve(TokenEntry $token): Account
    {
        $account = $this->accounts[$token->accountId] ?? null;
        if ($account === null) {
            // The config validation rejects this, so it only happens if the two get out of sync.
            throw new RuntimeException('Token refers to an unknown account');
        }

        return $account;
    }
}

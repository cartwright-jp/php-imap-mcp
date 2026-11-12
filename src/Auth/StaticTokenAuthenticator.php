<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

/**
 * token mode: a configured Bearer token names an account in config.php (SPEC.md 7, 8).
 */
final class StaticTokenAuthenticator implements BearerAuthenticator
{
    public function __construct(
        private readonly TokenAuthenticator $tokens,
        private readonly AccountResolver $accounts,
    ) {
    }

    public function authenticate(?string $authorization): Principal
    {
        $entry = $this->tokens->authenticate($authorization);

        return new Principal($this->accounts->resolve($entry), $entry->label);
    }

    public function challenge(AuthenticationFailed $failure): string
    {
        return 'Bearer';
    }
}

<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

/**
 * Turns the Authorization header of an MCP request into a Principal (SPEC.md 7).
 *
 * token mode: StaticTokenAuthenticator; imap_oauth mode: OAuth\AccessTokenAuthenticator.
 */
interface BearerAuthenticator
{
    /**
     * @throws AuthenticationFailed
     */
    public function authenticate(?string $authorization): Principal;

    /**
     * The WWW-Authenticate value of a 401 response for this failure.
     */
    public function challenge(AuthenticationFailed $failure): string;
}

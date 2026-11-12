<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Mail\Mailbox;
use Cartwright\ImapMcp\Mail\MailException;
use Closure;

/**
 * The identity check: an actual IMAP login with the given credentials (SPEC.md 7).
 */
final class CredentialVerifier
{
    /**
     * @param Closure(Account): Mailbox $mailboxFactory
     */
    public function __construct(private readonly Closure $mailboxFactory)
    {
    }

    /**
     * @return bool false when the server rejects the credentials
     *
     * @throws MailException when the server cannot be reached (not a statement about the credentials)
     */
    public function verify(Account $account): bool
    {
        $mailbox = ($this->mailboxFactory)($account);
        try {
            $mailbox->checkLogin();

            return true;
        } catch (MailException $e) {
            if ($e->isLoginFailure()) {
                return false;
            }
            throw $e;
        } finally {
            $mailbox->close();
        }
    }
}

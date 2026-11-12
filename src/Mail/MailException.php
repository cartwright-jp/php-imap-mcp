<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail;

use RuntimeException;

/**
 * A mail operation failed in a way the AI can act on. The message is short, safe to show and contains
 * no server paths, credentials or raw server responses (SPEC.md 5).
 */
class MailException extends RuntimeException
{
    public const UNAVAILABLE = 1;
    public const LOGIN_FAILED = 2;

    public static function unavailable(): self
    {
        return new self('Could not connect to the mail server. Try again later.', self::UNAVAILABLE);
    }

    public static function loginFailed(): self
    {
        return new self(
            'The mail server rejected the login. The password may have changed: check the account settings '
                . 'or sign in again.',
            self::LOGIN_FAILED,
        );
    }

    public function isLoginFailure(): bool
    {
        return $this->getCode() === self::LOGIN_FAILED;
    }

    public static function folderNotFound(string $folder): self
    {
        return new self(sprintf("Folder '%s' does not exist. Use list_folders to see the folder names.", $folder));
    }

    public static function uidValidityChanged(string $folder): self
    {
        return new self(sprintf(
            "The UIDs of folder '%s' have changed since the search. Run search_emails again.",
            $folder,
        ));
    }

    public static function serverError(): self
    {
        return new self('The mail server returned an error.');
    }
}

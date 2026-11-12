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
    public static function unavailable(): self
    {
        return new self('Could not connect to the mail server. Try again later.');
    }

    public static function loginFailed(): self
    {
        return new self('The mail server rejected the login. Check the account settings in config.php.');
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

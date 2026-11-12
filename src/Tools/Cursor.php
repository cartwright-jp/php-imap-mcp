<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tools;

/**
 * search_emails paging cursor: the folder's UIDVALIDITY and the last UID returned, bound to the search
 * criteria so it cannot be reused with other arguments. Opaque to the client.
 */
final class Cursor
{
    public function __construct(
        public readonly int $uidValidity,
        /** The next page contains UIDs below this one. */
        public readonly int $beforeUid,
    ) {
    }

    public function encode(string $fingerprint): string
    {
        $raw = sprintf('%d.%d.%s', $this->uidValidity, $this->beforeUid, $fingerprint);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @throws InvalidToolArguments
     */
    public static function decode(string $cursor, string $fingerprint): self
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || preg_match('/^(\d{1,10})\.(\d{1,10})\.([0-9a-f]{16})$/', $raw, $m) !== 1) {
            throw new InvalidToolArguments("'cursor' is not valid");
        }
        if (!hash_equals($m[3], $fingerprint)) {
            throw new InvalidToolArguments("'cursor' belongs to a search with other arguments; repeat them unchanged");
        }

        return new self((int) $m[1], (int) $m[2]);
    }
}

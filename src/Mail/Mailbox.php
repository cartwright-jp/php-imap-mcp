<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail;

/**
 * One mail account for the length of one request (SPEC.md 4: connect, work, disconnect).
 *
 * Folder names are UTF-8 here; implementations convert to the server's encoding. Implementations must not
 * download attachment contents.
 *
 * @throws MailException from every method
 */
interface Mailbox
{
    /**
     * @return list<FolderInfo>
     */
    public function listFolders(): array;

    /**
     * Matching UIDs in ascending order, with the folder's UIDVALIDITY.
     */
    public function search(string $folder, SearchCriteria $criteria): SearchResult;

    /**
     * Headers, flags and a body snippet for each UID, in the given order. Stops early (returning fewer)
     * when the deadline is near; returns at least one when any exists.
     *
     * @param list<int> $uids
     *
     * @return list<MessageSummary>
     */
    public function summaries(string $folder, array $uids, int $snippetChars, Deadline $deadline): array;

    /**
     * The message, with the readable body cut at $maxChars, or null when the UID does not exist.
     *
     * @param int|null $uidValidity when given and the folder's current value differs, throws UidValidityChanged
     */
    public function message(string $folder, int $uid, int $maxChars, bool $markSeen, ?int $uidValidity): ?MessageDetail;

    /**
     * Disconnects. Safe to call more than once and when never connected.
     */
    public function close(): void;
}

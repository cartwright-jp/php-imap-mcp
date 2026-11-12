<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Support;

use Cartwright\ImapMcp\Mail\Deadline;
use Cartwright\ImapMcp\Mail\FolderInfo;
use Cartwright\ImapMcp\Mail\Mailbox;
use Cartwright\ImapMcp\Mail\MailException;
use Cartwright\ImapMcp\Mail\MessageDetail;
use Cartwright\ImapMcp\Mail\MessageSummary;
use Cartwright\ImapMcp\Mail\SearchCriteria;
use Cartwright\ImapMcp\Mail\SearchResult;

/**
 * In-memory Mailbox for tool tests (CLAUDE.md: Mail/ is swappable for a mock).
 */
final class FakeMailbox implements Mailbox
{
    /** @var list<FolderInfo> */
    public array $folders = [];
    /** @var array<string, int> folder => UIDVALIDITY */
    public array $uidValidity = ['INBOX' => 100];
    /** @var array<string, array<int, MessageSummary>> */
    public array $summaries = [];
    /** @var array<string, array<int, MessageDetail>> */
    public array $details = [];
    public ?SearchCriteria $lastCriteria = null;
    /** @var list<int> */
    public array $lastSummaryUids = [];
    /** How many summaries to return at most, to simulate the deadline. */
    public ?int $summaryLimit = null;
    public ?MailException $failWith = null;
    public bool $closed = false;
    /** @var list<array{folder: string, uid: int, maxChars: int, markSeen: bool, uidValidity: ?int}> */
    public array $messageCalls = [];

    public function listFolders(): array
    {
        $this->fail();

        return $this->folders;
    }

    public function search(string $folder, SearchCriteria $criteria): SearchResult
    {
        $this->fail();
        $this->lastCriteria = $criteria;
        $uids = array_keys($this->summaries[$folder] ?? []);
        sort($uids);

        return new SearchResult($this->folderValidity($folder), $uids);
    }

    public function summaries(string $folder, array $uids, int $snippetChars, Deadline $deadline): array
    {
        $this->fail();
        $this->lastSummaryUids = $uids;
        $out = [];
        foreach ($uids as $uid) {
            if ($this->summaryLimit !== null && count($out) >= $this->summaryLimit) {
                break;
            }
            if (isset($this->summaries[$folder][$uid])) {
                $out[] = $this->summaries[$folder][$uid];
            }
        }

        return $out;
    }

    public function message(string $folder, int $uid, int $maxChars, bool $markSeen, ?int $uidValidity): ?MessageDetail
    {
        $this->fail();
        $this->messageCalls[] = compact('folder', 'uid', 'maxChars', 'markSeen', 'uidValidity');
        if ($uidValidity !== null && $uidValidity !== $this->folderValidity($folder)) {
            throw MailException::uidValidityChanged($folder);
        }

        return $this->details[$folder][$uid] ?? null;
    }

    /** @var list<array{folder: string, message: string, messageId: string}> */
    public array $appended = [];
    /** UID returned by appendDraft; null simulates a server that does not report it. */
    public ?int $nextUid = 42;

    public function appendDraft(string $folder, string $message, string $messageId): ?int
    {
        $this->fail();
        $this->folderValidity($folder);
        $this->appended[] = compact('folder', 'message', 'messageId');

        return $this->nextUid;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    private function folderValidity(string $folder): int
    {
        if (!isset($this->uidValidity[$folder])) {
            throw MailException::folderNotFound($folder);
        }

        return $this->uidValidity[$folder];
    }

    private function fail(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}

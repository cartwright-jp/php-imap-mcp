<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tools;

use Cartwright\ImapMcp\Mail\Deadline;
use Cartwright\ImapMcp\Mail\Mailbox;
use Cartwright\ImapMcp\Mail\MailException;
use Cartwright\ImapMcp\Mail\MessageSummary;
use Cartwright\ImapMcp\Mail\SearchCriteria;
use Cartwright\ImapMcp\Policy\MailPolicy;
use DateTimeZone;

/**
 * search_emails (SPEC.md 6): newest first, paged with an opaque cursor.
 */
final class SearchEmailsTool implements Tool
{
    public const DEFAULT_LIMIT = 20;
    public const SNIPPET_CHARS = 200;
    private const TERM_MAX = 200;
    private const ARGUMENTS = [
        'folder', 'from', 'to', 'subject', 'text', 'since', 'before', 'unread_only', 'limit', 'cursor',
    ];

    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly MailPolicy $policy,
        private readonly DateTimeZone $timezone,
        private readonly Deadline $deadline,
    ) {
    }

    public function name(): string
    {
        return 'search_emails';
    }

    public function definition(): array
    {
        $term = static fn (string $what): array => [
            'type' => 'string',
            'maxLength' => self::TERM_MAX,
            'description' => $what . ' contains this text (case-insensitive substring)',
        ];
        $date = static fn (string $what): array => [
            'type' => 'string',
            'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
            'description' => $what,
        ];

        return [
            'title' => 'Search emails',
            'description' => 'Searches one folder and lists matching emails, newest first, with sender, recipients, '
                . 'subject, read state and the first ' . self::SNIPPET_CHARS . ' characters of the body. '
                . 'All given conditions must match. Use get_email with the uid to read one. '
                . 'If next_cursor is not null, call again with the same arguments and that cursor for more. '
                . MailOutput::UNTRUSTED_NOTE,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'folder' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'default' => 'INBOX',
                        'description' => 'Folder name from list_folders',
                    ],
                    'from' => $term('The From header'),
                    'to' => $term('The To header'),
                    'subject' => $term('The subject'),
                    'text' => $term('The headers or body'),
                    'since' => $date('Received on or after this date (YYYY-MM-DD)'),
                    'before' => $date('Received before this date (YYYY-MM-DD)'),
                    'unread_only' => ['type' => 'boolean', 'default' => false],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => $this->policy->searchLimitMax,
                        'default' => $this->defaultLimit(),
                    ],
                    'cursor' => [
                        'type' => 'string',
                        'maxLength' => 200,
                        'description' => 'next_cursor of the previous call',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'folder' => ['type' => 'string'],
                    'uidvalidity' => ['type' => 'integer'],
                    'total' => ['type' => 'integer', 'description' => 'Number of matching emails'],
                    'emails' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'uid' => ['type' => 'integer'],
                                'date' => ['type' => ['string', 'null']],
                                'from' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'to' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'subject' => ['type' => 'string'],
                                'seen' => ['type' => 'boolean'],
                                'flagged' => ['type' => 'boolean'],
                                'has_attachments' => ['type' => 'boolean'],
                                'size' => ['type' => 'integer'],
                                'snippet' => ['type' => 'string'],
                            ],
                            'required' => [
                                'uid', 'date', 'from', 'to', 'subject', 'seen', 'flagged', 'has_attachments',
                                'size', 'snippet',
                            ],
                        ],
                    ],
                    'next_cursor' => ['type' => ['string', 'null']],
                ],
                'required' => ['folder', 'uidvalidity', 'total', 'emails', 'next_cursor'],
            ],
            'annotations' => MailOutput::readOnlyAnnotations(),
        ];
    }

    public function call(array $arguments): ToolResult
    {
        $args = new Arguments($arguments, self::ARGUMENTS);
        $folder = $args->folder();
        $criteria = new SearchCriteria(
            $args->string('from', self::TERM_MAX),
            $args->string('to', self::TERM_MAX),
            $args->string('subject', self::TERM_MAX),
            $args->string('text', self::TERM_MAX),
            $args->date('since'),
            $args->date('before'),
            $args->bool('unread_only', false),
        );
        $limit = (int) $args->int('limit', $this->defaultLimit(), 1, $this->policy->searchLimitMax);
        $cursor = $args->string('cursor', 200);
        $after = $cursor === null ? null : Cursor::decode($cursor, $criteria->fingerprint($folder));

        if (!$this->policy->isFolderAllowed($folder)) {
            return ToolResult::error(sprintf("Folder '%s' is not allowed by the server policy.", $folder));
        }

        try {
            $result = $this->mailbox->search($folder, $criteria);
            if ($after !== null && $after->uidValidity !== $result->uidValidity) {
                throw MailException::uidValidityChanged($folder);
            }
            $uids = array_reverse($result->uids);
            if ($after !== null) {
                $uids = array_values(array_filter($uids, static fn (int $uid): bool => $uid < $after->beforeUid));
            }
            $page = array_slice($uids, 0, $limit);
            $summaries = $this->mailbox->summaries($folder, $page, self::SNIPPET_CHARS, $this->deadline);
        } catch (MailException $e) {
            return ToolResult::error($e->getMessage());
        }

        $nextCursor = null;
        $last = $summaries === [] ? null : $summaries[count($summaries) - 1]->uid;
        if ($last !== null && $uids !== [] && end($uids) < $last) {
            $nextCursor = (new Cursor($result->uidValidity, $last))->encode($criteria->fingerprint($folder));
        }

        $structured = [
            'folder' => $folder,
            'uidvalidity' => $result->uidValidity,
            'total' => count($result->uids),
            'emails' => array_map(fn (MessageSummary $m): array => [
                'uid' => $m->uid,
                'date' => MailOutput::date($m->date, $this->timezone),
                'from' => MailOutput::addresses($m->from),
                'to' => MailOutput::addresses($m->to),
                'subject' => $m->subject,
                'seen' => $m->seen,
                'flagged' => $m->flagged,
                'has_attachments' => $m->hasAttachments,
                'size' => $m->size,
                'snippet' => $m->snippet === '' ? '' : MailOutput::untrusted($m->snippet),
            ], $summaries),
            'next_cursor' => $nextCursor,
        ];

        return ToolResult::success(MailOutput::text($structured), $structured);
    }

    public function auditArguments(array $arguments): array
    {
        // Search terms can be personal; record which filters were used, not their values.
        $used = [];
        foreach (['from', 'to', 'subject', 'text', 'since', 'before', 'unread_only', 'cursor'] as $name) {
            if (isset($arguments[$name])) {
                $used[] = $name;
            }
        }

        return [
            'folder' => is_string($arguments['folder'] ?? null)
                ? mb_substr($arguments['folder'], 0, 100, 'UTF-8')
                : null,
            'filters' => $used,
            'limit' => is_int($arguments['limit'] ?? null) ? $arguments['limit'] : null,
        ];
    }

    private function defaultLimit(): int
    {
        return min(self::DEFAULT_LIMIT, $this->policy->searchLimitMax);
    }
}

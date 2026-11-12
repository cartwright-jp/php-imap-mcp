<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tools;

use Cartwright\ImapMcp\Mail\AttachmentInfo;
use Cartwright\ImapMcp\Mail\Mailbox;
use Cartwright\ImapMcp\Mail\MailException;
use Cartwright\ImapMcp\Mail\MessageDetail;
use Cartwright\ImapMcp\Policy\MailPolicy;
use DateTimeZone;

/**
 * get_email (SPEC.md 6): headers, readable text body, attachment metadata, threading headers.
 */
final class GetEmailTool implements Tool
{
    private const ARGUMENTS = ['folder', 'uid', 'uidvalidity', 'max_chars', 'mark_as_read'];
    private const MAX_UID = 4_294_967_295;

    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly MailPolicy $policy,
        private readonly DateTimeZone $timezone,
    ) {
    }

    public function name(): string
    {
        return 'get_email';
    }

    public function definition(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'title' => 'Read an email',
            'description' => 'Returns one email: headers, the body as plain text (HTML-only emails are converted), '
                . 'attachment names, types and sizes (not their contents), and Message-ID / In-Reply-To for replies. '
                . 'Identify the email by folder and uid from search_emails; pass its uidvalidity too so a changed '
                . 'folder is detected. ' . MailOutput::UNTRUSTED_NOTE,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'folder' => ['type' => 'string', 'maxLength' => 255, 'default' => 'INBOX'],
                    'uid' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_UID],
                    'uidvalidity' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_UID,
                        'description' => 'uidvalidity from search_emails',
                    ],
                    'max_chars' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => $this->policy->bodyMaxChars,
                        'default' => $this->policy->bodyMaxChars,
                        'description' => 'Cut the body after this many characters',
                    ],
                    'mark_as_read' => ['type' => 'boolean', 'default' => false],
                ],
                'required' => ['uid'],
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'folder' => ['type' => 'string'],
                    'uid' => ['type' => 'integer'],
                    'uidvalidity' => ['type' => 'integer'],
                    'date' => ['type' => ['string', 'null']],
                    'from' => $strings,
                    'to' => $strings,
                    'cc' => $strings,
                    'reply_to' => $strings,
                    'subject' => ['type' => 'string'],
                    'message_id' => ['type' => ['string', 'null']],
                    'in_reply_to' => ['type' => ['string', 'null']],
                    'references' => $strings,
                    'seen' => ['type' => 'boolean'],
                    'flagged' => ['type' => 'boolean'],
                    'body' => ['type' => 'string'],
                    'body_format' => [
                        'type' => 'string',
                        'enum' => [MessageDetail::FORMAT_TEXT, MessageDetail::FORMAT_HTML],
                    ],
                    'body_truncated' => ['type' => 'boolean'],
                    'attachments' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'filename' => ['type' => ['string', 'null']],
                                'content_type' => ['type' => 'string'],
                                'size' => ['type' => 'integer'],
                            ],
                            'required' => ['filename', 'content_type', 'size'],
                        ],
                    ],
                ],
                'required' => [
                    'folder', 'uid', 'uidvalidity', 'date', 'from', 'to', 'cc', 'reply_to', 'subject', 'message_id',
                    'in_reply_to', 'references', 'seen', 'flagged', 'body', 'body_format', 'body_truncated',
                    'attachments',
                ],
            ],
            'annotations' => MailOutput::readOnlyAnnotations(),
        ];
    }

    public function call(array $arguments): ToolResult
    {
        $args = new Arguments($arguments, self::ARGUMENTS);
        $folder = $args->folder();
        $uid = $args->requiredInt('uid', 1, self::MAX_UID);
        $uidValidity = $args->int('uidvalidity', null, 1, self::MAX_UID);
        $maxChars = (int) $args->int('max_chars', $this->policy->bodyMaxChars, 1, $this->policy->bodyMaxChars);
        $markAsRead = $args->bool('mark_as_read', false);

        if (!$this->policy->isFolderAllowed($folder)) {
            return ToolResult::error(sprintf("Folder '%s' is not allowed by the server policy.", $folder));
        }
        try {
            $message = $this->mailbox->message($folder, $uid, $maxChars, $markAsRead, $uidValidity);
        } catch (MailException $e) {
            return ToolResult::error($e->getMessage());
        }
        if ($message === null) {
            return ToolResult::error(sprintf(
                "No email with uid %d in folder '%s'. It may have been moved or deleted; search again.",
                $uid,
                $folder,
            ));
        }

        $structured = [
            'folder' => $folder,
            'uid' => $message->uid,
            'uidvalidity' => $message->uidValidity,
            'date' => MailOutput::date($message->date, $this->timezone),
            'from' => MailOutput::addresses($message->from),
            'to' => MailOutput::addresses($message->to),
            'cc' => MailOutput::addresses($message->cc),
            'reply_to' => MailOutput::addresses($message->replyTo),
            'subject' => $message->subject,
            'message_id' => $message->messageId,
            'in_reply_to' => $message->inReplyTo,
            'references' => $message->references,
            'seen' => $message->seen,
            'flagged' => $message->flagged,
            'body' => MailOutput::untrusted($message->body),
            'body_format' => $message->bodyFormat,
            'body_truncated' => $message->bodyTruncated,
            'attachments' => array_map(static fn (AttachmentInfo $a): array => [
                'filename' => $a->filename,
                'content_type' => $a->contentType,
                'size' => $a->size,
            ], $message->attachments),
        ];

        return ToolResult::success(MailOutput::text($structured), $structured);
    }

    public function auditArguments(array $arguments): array
    {
        return [
            'folder' => is_string($arguments['folder'] ?? null)
                ? mb_substr($arguments['folder'], 0, 100, 'UTF-8')
                : null,
            'uid' => is_int($arguments['uid'] ?? null) ? $arguments['uid'] : null,
            'mark_as_read' => is_bool($arguments['mark_as_read'] ?? null) ? $arguments['mark_as_read'] : null,
        ];
    }
}

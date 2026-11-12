<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Support;

use Cartwright\ImapMcp\Mail\Address;
use Cartwright\ImapMcp\Mail\AttachmentInfo;
use Cartwright\ImapMcp\Mail\MessageDetail;
use Cartwright\ImapMcp\Mail\MessageSummary;
use DateTimeImmutable;

/**
 * Value objects for tool tests.
 */
final class MailFixtures
{
    public static function summary(int $uid, string $subject = '件名', string $snippet = '本文の冒頭'): MessageSummary
    {
        return new MessageSummary(
            $uid,
            new DateTimeImmutable('2026-11-09T01:15:00+00:00'),
            [new Address('hanako@example.com', '佐藤 花子')],
            [new Address('test@example.com')],
            $subject,
            false,
            false,
            $uid % 2 === 0,
            1234,
            $snippet,
        );
    }

    public static function detail(int $uid, string $body = '本文です。', int $uidValidity = 100): MessageDetail
    {
        return new MessageDetail(
            $uid,
            $uidValidity,
            new DateTimeImmutable('2026-11-09T01:15:00+00:00'),
            [new Address('hanako@example.com', '佐藤 花子')],
            [new Address('test@example.com', '山田 太郎')],
            [new Address('boss@example.com')],
            [],
            '打ち合わせの日程について',
            'msg-1@example.com',
            'root-0@example.com',
            ['root-0@example.com'],
            true,
            false,
            $body,
            MessageDetail::FORMAT_TEXT,
            false,
            [new AttachmentInfo('見積書.pdf', 'application/pdf', 4296)],
        );
    }
}

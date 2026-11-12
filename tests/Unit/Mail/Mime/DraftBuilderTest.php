<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Mime;

use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Mail\Mime\Draft;
use Cartwright\ImapMcp\Mail\Mime\DraftBuilder;
use Cartwright\ImapMcp\Mail\Mime\HeaderBlock;
use Cartwright\ImapMcp\Mail\Mime\TransferDecoder;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;

/**
 * The generated draft, read back with the same decoders get_email uses.
 */
#[CoversClass(DraftBuilder::class)]
#[CoversClass(Draft::class)]
final class DraftBuilderTest extends TestCase
{
    public function testJapaneseReplyDraft(): void
    {
        $draft = DraftBuilder::build(
            new Account('default', 'test@example.com', 'x', 'imap.example.com', 993, 'ssl', '山田 太郎'),
            [Address::create('佐藤 花子 <hanako@example.com>')],
            [Address::create('boss@example.com')],
            'Re: 打ち合わせの日程について',
            "火曜日の14時でいかがでしょうか。\r\n\r\n山田",
            'iso2022jp-1@example.com',
            ['root-0@example.com', 'iso2022jp-1@example.com'],
            new DateTimeImmutable('2026-11-12T13:00:00+09:00'),
        );

        [$head, $body] = explode("\r\n\r\n", $draft->message, 2);
        $headers = new HeaderBlock($head . "\r\n");
        self::assertSame('Re: 打ち合わせの日程について', $headers->text('Subject'));
        self::assertSame('山田 太郎 <test@example.com>', $headers->addresses('From')[0]->toString());
        self::assertSame('佐藤 花子 <hanako@example.com>', $headers->addresses('To')[0]->toString());
        self::assertSame('boss@example.com', $headers->addresses('Cc')[0]->toString());
        self::assertSame(['iso2022jp-1@example.com'], $headers->messageIds('In-Reply-To'));
        self::assertSame(['root-0@example.com', 'iso2022jp-1@example.com'], $headers->messageIds('References'));
        self::assertSame([$draft->messageId], $headers->messageIds('Message-ID'));
        self::assertStringEndsWith('@example.com', $draft->messageId);
        self::assertSame('2026-11-12T13:00:00+09:00', $headers->date()?->format(DATE_ATOM));
        self::assertStringContainsString('charset=utf-8', (string) $headers->raw('Content-Type'));
        self::assertNull($headers->raw('Bcc'));

        $text = TransferDecoder::decode($body, $headers->raw('Content-Transfer-Encoding'));
        self::assertSame("火曜日の14時でいかがでしょうか。\r\n\r\n山田", rtrim($text, "\r\n"));
        // Every line ends with CRLF and the header stays 7-bit (RFC 5322).
        self::assertSame(0, preg_match("/(?<!\r)\n/", $draft->message));
        self::assertSame(1, preg_match('/^[\x20-\x7E\r\n\t]*$/', $head));
    }

    public function testNewDraftHasNoThreadingHeaders(): void
    {
        $draft = DraftBuilder::build(
            new Account('default', 'test@example.com', 'x', 'imap.example.com', 993, 'ssl'),
            [Address::create('a@example.com')],
            [],
            '',
            'body',
            null,
            [],
            new DateTimeImmutable(),
        );
        $headers = new HeaderBlock(explode("\r\n\r\n", $draft->message, 2)[0]);

        self::assertNull($headers->raw('In-Reply-To'));
        self::assertNull($headers->raw('References'));
        self::assertNull($headers->raw('Cc'));
        self::assertSame('test@example.com', $headers->addresses('From')[0]->toString());
        self::assertNotSame(
            $draft->messageId,
            DraftBuilder::build(
                new Account('default', 'test@example.com', 'x', 'imap.example.com', 993, 'ssl'),
                [Address::create('a@example.com')],
                [],
                '',
                'body',
                null,
                [],
                new DateTimeImmutable(),
            )->messageId,
        );
    }
}

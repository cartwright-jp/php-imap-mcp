<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Mime;

use Cartwright\ImapMcp\Mail\Address;
use Cartwright\ImapMcp\Mail\Mime\ReplyQuote;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReplyQuote::class)]
#[CoversClass(Address::class)]
final class ReplyQuoteTest extends TestCase
{
    public function testQuoteGoesBelowTheReplyWithAGmailStyleHeader(): void
    {
        $body = ReplyQuote::apply(
            '承知しました。',
            "元のメールの1行目\n\n元のメールの3行目",
            false,
            new DateTimeImmutable('2026-11-19T01:51:00+00:00'),
            [new Address('taro@example.com', '山田 太郎')],
            new DateTimeZone('Asia/Tokyo'),
        );

        self::assertSame(
            "承知しました。\n\n"
            . "2026年11月19日(木) 10:51 山田 太郎 <taro@example.com>:\n\n"
            . "> 元のメールの1行目\n"
            . ">\n"
            . "> 元のメールの3行目\n",
            $body,
        );
    }

    public function testOldQuotesGoOneLevelDeeper(): void
    {
        $body = $this->quote("はい。\n\n> 前のメール\n>> その前");

        self::assertStringEndsWith("> はい。\n>\n> > 前のメール\n> >> その前\n", $body);
    }

    public function testLineEndingsAreNormalisedAndTrailingBlankLinesDropped(): void
    {
        $body = $this->quote("一\r\n二\r三\r\n\r\n  \n");

        self::assertStringEndsWith(":\n\n> 一\n> 二\n> 三\n", $body);
        self::assertStringNotContainsString("\r", $body);
    }

    public function testTrailingNewlinesOfTheReplyAreNotDoubled(): void
    {
        $body = $this->quote('元', "返信です。\n\n\n");

        self::assertStringStartsWith("返信です。\n\n2026年", $body);
    }

    public function testEmptyReplyStartsWithTheHeader(): void
    {
        self::assertStringStartsWith('2026年11月9日(月) 10:15 ', $this->quote('元', ''));
    }

    public function testTruncatedQuoteEndsWithAnUnquotedMark(): void
    {
        $body = $this->quote("一行目\n二行目\n", 'x', true);

        self::assertStringEndsWith("> 一行目\n> 二行目\n（以下省略）\n", $body);
    }

    public function testControlCharactersAreDropped(): void
    {
        self::assertStringEndsWith("> ab\tc\n", $this->quote("a\x1b\x00b\tc\x7f"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'blank lines' => ["\r\n \n\t\n"];
    }

    #[DataProvider('emptyBodies')]
    public function testEmptyOriginalIsNotQuoted(string $original): void
    {
        self::assertNull(ReplyQuote::apply(
            'x',
            $original,
            false,
            null,
            [],
            new DateTimeZone('Asia/Tokyo'),
        ));
    }

    /**
     * @return iterable<string, array{DateTimeImmutable|null, list<Address>, string|null}>
     */
    public static function headers(): iterable
    {
        $date = new DateTimeImmutable('2026-11-15T23:05:00+00:00');
        yield 'name and address' => [
            $date,
            [new Address('hanako@example.com', '佐藤 花子'), new Address('other@example.com', '別人')],
            '2026年11月16日(月) 8:05 佐藤 花子 <hanako@example.com>:',
        ];
        yield 'address only' => [$date, [new Address('hanako@example.com')], '2026年11月16日(月) 8:05 hanako@example.com:'];
        yield 'no sender' => [$date, [], '2026年11月16日(月) 8:05:'];
        yield 'no date' => [null, [new Address('hanako@example.com')], 'hanako@example.com:'];
        yield 'neither' => [null, [], null];
        yield 'sunday' => [new DateTimeImmutable('2026-11-22T03:00:00+09:00'), [], '2026年11月22日(日) 3:00:'];
    }

    /**
     * @param list<Address> $from
     */
    #[DataProvider('headers')]
    public function testHeader(?DateTimeImmutable $date, array $from, ?string $expected): void
    {
        self::assertSame($expected, ReplyQuote::header($date, $from, new DateTimeZone('Asia/Tokyo')));
    }

    public function testWithoutAHeaderLineTheQuoteFollowsTheReply(): void
    {
        $body = ReplyQuote::apply('返信', '元', false, null, [], new DateTimeZone('Asia/Tokyo'));

        self::assertSame("返信\n\n> 元\n", $body);
    }

    private function quote(string $original, string $reply = '返信', bool $truncated = false): string
    {
        $body = ReplyQuote::apply(
            $reply,
            $original,
            $truncated,
            new DateTimeImmutable('2026-11-09T01:15:00+00:00'),
            [new Address('hanako@example.com', '佐藤 花子')],
            new DateTimeZone('Asia/Tokyo'),
        );
        self::assertNotNull($body);

        return $body;
    }
}

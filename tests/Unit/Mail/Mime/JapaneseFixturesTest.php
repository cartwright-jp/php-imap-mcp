<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Mime;

use Cartwright\ImapMcp\Mail\Address;
use Cartwright\ImapMcp\Mail\Mime\AddressParser;
use Cartwright\ImapMcp\Mail\Mime\Charset;
use Cartwright\ImapMcp\Mail\Mime\HeaderBlock;
use Cartwright\ImapMcp\Mail\Mime\HeaderDecoder;
use Cartwright\ImapMcp\Mail\Mime\HtmlToText;
use Cartwright\ImapMcp\Mail\Mime\TransferDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Japanese fixtures decode without mojibake (SPEC.md 11). The IMAP path is covered by
 * tests/Integration/ImapMailboxTest.php.
 */
#[CoversClass(HeaderBlock::class)]
#[CoversClass(HeaderDecoder::class)]
#[CoversClass(AddressParser::class)]
#[CoversClass(Charset::class)]
#[CoversClass(TransferDecoder::class)]
#[CoversClass(HtmlToText::class)]
#[CoversClass(Address::class)]
final class JapaneseFixturesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function fixtures(): iterable
    {
        yield 'ISO-2022-JP' => ['iso-2022-jp.eml', '打ち合わせの日程について', '佐藤 花子 <hanako@example.com>', '来週の打ち合わせの件'];
        yield 'Shift_JIS (CP932)' => ['shift_jis.eml', '【請求書】①11月分～', '経理部 <billing@example.com>', '①ご確認ください～'];
        yield 'EUC-JP' => ['euc-jp.eml', 'EUC-JPのテスト', '鈴木 <suzuki@example.com>', '日本語の本文です。'];
        yield 'HTML only' => ['html-only.eml', 'お知らせ（HTMLのみ）', 'ニュース <news@example.com>', "価格は<100円> & 送料無料\n- 一つ目"];
    }

    #[DataProvider('fixtures')]
    public function testDecodesSubjectSenderAndBody(string $file, string $subject, string $from, string $bodyPart): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/mail/' . $file);
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $headers = new HeaderBlock($head . "\r\n");

        self::assertSame($subject, $headers->text('Subject'));
        self::assertSame($from, $headers->addresses('From')[0]->toString());

        preg_match('/charset=([^;\s]+)/i', (string) $headers->raw('Content-Type'), $m);
        $decoded = TransferDecoder::decode($body, $headers->raw('Content-Transfer-Encoding'));
        $text = Charset::toUtf8($decoded, $m[1] ?? null);
        if (str_contains((string) $headers->raw('Content-Type'), 'text/html')) {
            $text = HtmlToText::convert($text);
        }
        self::assertStringContainsString($bodyPart, str_replace("\r\n", "\n", $text));
    }

    public function testThreadingHeaders(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/mail/reply.eml');
        $headers = new HeaderBlock(explode("\r\n\r\n", $raw, 2)[0]);

        self::assertSame(['reply-1@example.com'], $headers->messageIds('Message-ID'));
        self::assertSame(['iso2022jp-1@example.com'], $headers->messageIds('In-Reply-To'));
        self::assertSame(['root-0@example.com', 'iso2022jp-1@example.com'], $headers->messageIds('References'));
        self::assertSame('2026-11-09T15:00:00+09:00', $headers->date()?->format(DATE_ATOM));
    }
}

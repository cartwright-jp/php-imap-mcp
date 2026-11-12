<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Mime;

use Cartwright\ImapMcp\Mail\Mime\Charset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Charset::class)]
final class CharsetTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function japanese(): iterable
    {
        $text = '日本語のテスト①～㈱';
        yield 'ISO-2022-JP with CP932 extensions' => [
            mb_convert_encoding($text, 'ISO-2022-JP-MS', 'UTF-8'),
            'ISO-2022-JP',
            $text,
        ];
        yield 'Shift_JIS label, CP932 bytes' => [mb_convert_encoding($text, 'SJIS-win', 'UTF-8'), 'Shift_JIS', $text];
        yield 'Windows-31J label' => [mb_convert_encoding($text, 'SJIS-win', 'UTF-8'), 'windows-31j', $text];
        yield 'EUC-JP' => [mb_convert_encoding('日本語', 'eucJP-win', 'UTF-8'), 'euc-jp', '日本語'];
        yield 'UTF-8, label in other case and quotes' => ['日本語', '"utf-8"', '日本語'];
        yield 'us-ascii' => ['hello', 'US-ASCII', 'hello'];
        yield 'ISO-8859-1' => ["caf\xE9", 'iso-8859-1', 'café'];
    }

    #[DataProvider('japanese')]
    public function testConvertsToUtf8(string $bytes, string $charset, string $expected): void
    {
        self::assertSame($expected, Charset::toUtf8($bytes, $charset));
    }

    public function testMissingCharsetIsGuessed(): void
    {
        self::assertSame('日本語', Charset::toUtf8(mb_convert_encoding('日本語', 'SJIS-win', 'UTF-8'), null));
        self::assertSame('日本語', Charset::toUtf8(mb_convert_encoding('日本語', 'ISO-2022-JP', 'UTF-8'), null));
        self::assertSame('日本語', Charset::toUtf8('日本語', null));
    }

    public function testUnknownCharsetFallsBackToAGuess(): void
    {
        self::assertSame('日本語', Charset::toUtf8('日本語', 'x-unknown-charset'));
    }

    public function testInvalidBytesBecomeReplacementCharactersInsteadOfFailing(): void
    {
        $result = Charset::toUtf8("abc\xff\xfedef", 'UTF-8');

        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
        self::assertStringStartsWith('abc', $result);
        self::assertStringEndsWith('def', $result);
    }

    public function testBrokenShiftJisIsStillValidUtf8(): void
    {
        $bytes = substr(mb_convert_encoding('日本語', 'SJIS-win', 'UTF-8'), 0, 3); // cut in the middle of a character

        self::assertTrue(mb_check_encoding(Charset::toUtf8($bytes, 'Shift_JIS'), 'UTF-8'));
    }
}

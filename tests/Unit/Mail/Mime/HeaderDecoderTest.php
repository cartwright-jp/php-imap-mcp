<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Mime;

use Cartwright\ImapMcp\Mail\Mime\Charset;
use Cartwright\ImapMcp\Mail\Mime\HeaderDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderDecoder::class)]
#[CoversClass(Charset::class)]
final class HeaderDecoderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headers(): iterable
    {
        yield 'ISO-2022-JP B' => ['=?ISO-2022-JP?B?GyRCQkckQTlnJG8kOyRORnxEeCRLJEQkJCRGGyhC?=', '打ち合わせの日程について'];
        yield 'UTF-8 Q with underscore' => ['=?UTF-8?Q?=E3=83=8B=E3=83=A5=E3=83=BC=E3=82=B9_2?=', 'ニュース 2'];
        yield 'lowercase charset and encoding' => ['=?utf-8?b?5pel5pys?=', '日本'];
        yield 'mixed plain and encoded' => ['Re: =?UTF-8?B?5pel5pys?= (2)', 'Re: 日本 (2)'];
        yield 'whitespace between words is dropped' => ['=?UTF-8?B?5pel?= =?UTF-8?B?5pys?=', '日本'];
        yield 'folded header' => ["=?UTF-8?B?5pel?=\r\n =?UTF-8?B?5pys?=", '日本'];
        yield 'Shift_JIS character split across words' => [
            '=?Shift_JIS?B?' . base64_encode(substr(mb_convert_encoding('日本', 'SJIS-win', 'UTF-8'), 0, 3)) . '?= '
                . '=?Shift_JIS?B?' . base64_encode(substr(mb_convert_encoding('日本', 'SJIS-win', 'UTF-8'), 3)) . '?=',
            '日本',
        ];
        yield 'raw 8-bit UTF-8' => ['日本語の件名', '日本語の件名'];
        yield 'raw 8-bit Shift_JIS' => [mb_convert_encoding('日本語の件名', 'SJIS-win', 'UTF-8'), '日本語の件名'];
        yield 'RFC 2231 language suffix' => ['=?UTF-8*ja?B?5pel5pys?=', '日本'];
        yield 'control characters are removed' => ["a\x07b=?UTF-8?B?" . base64_encode("c\x1bd") . '?=', 'abcd'];
        yield 'broken base64 does not fail' => ['=?UTF-8?B?***?=', ''];
        yield 'plain ASCII' => ['Hello', 'Hello'];
    }

    #[DataProvider('headers')]
    public function testDecode(string $raw, string $expected): void
    {
        self::assertSame($expected, HeaderDecoder::decode($raw));
    }

    public function testRfc2231(): void
    {
        self::assertSame('見積書.pdf', HeaderDecoder::decodeRfc2231("UTF-8''%E8%A6%8B%E7%A9%8D%E6%9B%B8.pdf"));
        self::assertSame(
            '見積書.pdf',
            HeaderDecoder::decodeRfc2231(
                "Shift_JIS'ja'" . rawurlencode(mb_convert_encoding('見積書.pdf', 'SJIS-win', 'UTF-8')),
            ),
        );
    }
}

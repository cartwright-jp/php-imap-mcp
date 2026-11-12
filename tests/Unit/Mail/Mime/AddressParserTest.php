<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Mime;

use Cartwright\ImapMcp\Mail\Address;
use Cartwright\ImapMcp\Mail\Mime\AddressParser;
use Cartwright\ImapMcp\Mail\Mime\HeaderDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddressParser::class)]
#[CoversClass(Address::class)]
#[CoversClass(HeaderDecoder::class)]
final class AddressParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lists(): iterable
    {
        yield 'bare address' => ['test@example.com', ['test@example.com']];
        yield 'name and address' => ['Taro Yamada <taro@example.com>', ['Taro Yamada <taro@example.com>']];
        yield 'encoded Japanese name' => [
            '=?ISO-2022-JP?B?GyRCOjRGIxsoQiAbJEIyVjtSGyhC?= <hanako@example.com>',
            ['佐藤 花子 <hanako@example.com>'],
        ];
        yield 'quoted name with comma' => [
            '"Yamada, Taro" <taro@example.com>, b@example.com',
            ['Yamada, Taro <taro@example.com>', 'b@example.com'],
        ];
        yield 'encoded name containing a comma' => [
            '=?UTF-8?B?' . base64_encode('山田, 太郎') . '?= <taro@example.com>',
            ['山田, 太郎 <taro@example.com>'],
        ];
        yield 'comment form' => ['taro@example.com (Taro)', ['Taro <taro@example.com>']];
        yield 'group syntax' => ['team: a@example.com, b@example.com;', ['a@example.com', 'b@example.com']];
        yield 'undisclosed recipients' => ['undisclosed-recipients:;', []];
        yield 'angle address only' => ['<a@example.com>', ['a@example.com']];
        yield 'garbage is skipped' => ['not an address, a@example.com', ['a@example.com']];
        yield 'folded' => ["a@example.com,\r\n b@example.com", ['a@example.com', 'b@example.com']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('lists')]
    public function testParse(string $raw, array $expected): void
    {
        $parsed = array_map(static fn (Address $a): string => $a->toString(), AddressParser::parse($raw));

        self::assertSame($expected, $parsed);
    }
}

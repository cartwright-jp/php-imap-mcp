<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mcp;

use Cartwright\ImapMcp\Mcp\HeaderValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderValue::class)]
final class HeaderValueTest extends TestCase
{
    /**
     * Examples from the Streamable HTTP "Value Encoding" table.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function values(): iterable
    {
        yield 'plain ASCII' => ['us-west1', 'us-west1'];
        yield 'non-ASCII' => ['=?base64?SGVsbG8sIOS4lueVjA==?=', 'Hello, 世界'];
        yield 'padded' => ['=?base64?IHBhZGRlZCA=?=', ' padded '];
        yield 'newline' => ['=?base64?bGluZTEKbGluZTI=?=', "line1\nline2"];
        yield 'sentinel literal' => ['=?base64?PT9iYXNlNjQ/bGl0ZXJhbD89?=', '=?base64?literal?='];
        yield 'control character' => ["a\x00b", null];
        yield 'raw non-ASCII' => ['世界', null];
        yield 'broken Base64' => ['=?base64?@@@?=', null];
        yield 'Base64 of invalid UTF-8' => ['=?base64?' . base64_encode("\xFF") . '?=', null];
    }

    #[DataProvider('values')]
    public function testDecode(string $raw, ?string $expected): void
    {
        self::assertSame($expected, HeaderValue::decode($raw));
    }
}

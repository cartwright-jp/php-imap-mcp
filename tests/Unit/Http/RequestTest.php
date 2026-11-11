<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Http;

use Cartwright\ImapMcp\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function testHeadersAreCaseInsensitive(): void
    {
        $request = new Request('POST', ['MCP-Protocol-Version' => '2026-07-28'], '{}');

        self::assertSame('2026-07-28', $request->header('mcp-protocol-version'));
        self::assertTrue($request->hasHeader('Mcp-Protocol-Version'));
        self::assertNull($request->header('Mcp-Method'));
    }
}

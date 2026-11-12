<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Http;

use Cartwright\ImapMcp\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function serverVariables(): iterable
    {
        yield 'HTTPS=on (Apache, XServer)' => [['HTTPS' => 'on', 'SERVER_PORT' => '443'], true];
        yield 'HTTPS=1' => [['HTTPS' => '1'], true];
        yield 'REQUEST_SCHEME only' => [['REQUEST_SCHEME' => 'https'], true];
        yield 'HTTPS=off (IIS)' => [['HTTPS' => 'off', 'REQUEST_SCHEME' => 'http'], false];
        yield 'plain http' => [['SERVER_PORT' => '80', 'REQUEST_SCHEME' => 'http'], false];
        yield 'nothing' => [[], false];
        // Any client can send these; only the web server's own variables count.
        yield 'forwarded header only' => [['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_SSL' => 'on'], false];
        yield 'port 443 alone' => [['SERVER_PORT' => '443'], false];
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('serverVariables')]
    public function testHttpsIsReadFromTheWebServersVariables(array $server, bool $expected): void
    {
        self::assertSame($expected, Request::isHttps($server));
    }

    public function testRequestsAreTreatedAsHttpsUnlessSaidOtherwise(): void
    {
        self::assertTrue((new Request('GET', [], ''))->secure);
        self::assertFalse((new Request('GET', [], '', secure: false))->secure);
    }
}

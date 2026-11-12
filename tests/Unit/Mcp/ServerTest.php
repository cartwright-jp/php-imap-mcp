<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mcp;

use Cartwright\ImapMcp\Http\Request;
use Cartwright\ImapMcp\Http\Response;
use Cartwright\ImapMcp\Mcp\ErrorCode;
use Cartwright\ImapMcp\Mcp\HeaderValue;
use Cartwright\ImapMcp\Mcp\JsonRpcError;
use Cartwright\ImapMcp\Mcp\ProtocolVersion;
use Cartwright\ImapMcp\Mcp\Server;
use Cartwright\ImapMcp\ServerInfo;
use Cartwright\ImapMcp\Tests\Support\EchoTool;
use Cartwright\ImapMcp\Tests\Support\MemoryAuditLogger;
use Cartwright\ImapMcp\Tools\Tool;
use Cartwright\ImapMcp\Tools\ToolRegistry;
use Cartwright\ImapMcp\Tools\ToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Server::class)]
#[CoversClass(JsonRpcError::class)]
#[CoversClass(HeaderValue::class)]
#[CoversClass(ProtocolVersion::class)]
#[CoversClass(Request::class)]
#[CoversClass(Response::class)]
#[CoversClass(ToolRegistry::class)]
#[CoversClass(ToolResult::class)]
#[CoversClass(ServerInfo::class)]
final class ServerTest extends TestCase
{
    private const MODERN = '2026-07-28';
    private const LEGACY = '2025-11-25';

    // ---- 2026-07-28 (modern) ----

    public function testServerDiscover(): void
    {
        $response = $this->send($this->modern('server/discover'));

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $body = $this->body($response);
        self::assertSame(1, $body['id']);
        self::assertSame([
            'resultType' => 'complete',
            'supportedVersions' => ['2026-07-28', '2025-11-25'],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'instructions' => $body['result']['instructions'],
            'ttlMs' => Server::LIST_TTL_MS,
            'cacheScope' => 'private',
            '_meta' => ['io.modelcontextprotocol/serverInfo' => ServerInfo::toArray()],
        ], $body['result']);
        self::assertStringContainsString('untrusted', $body['result']['instructions']);
    }

    public function testToolsList(): void
    {
        $body = $this->body($this->send($this->modern('tools/list')));

        $result = $body['result'];
        self::assertSame('complete', $result['resultType']);
        self::assertSame(['echo'], array_column($result['tools'], 'name'));
        self::assertTrue($result['tools'][0]['annotations']['readOnlyHint']);
        self::assertSame(Server::LIST_TTL_MS, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
    }

    public function testToolsCall(): void
    {
        $response = $this->send($this->modern('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'こんにちは']]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('こんにちは', $response->body, 'JSON must not escape Japanese');
        self::assertSame([
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => 'こんにちは']],
            'structuredContent' => ['text' => 'こんにちは'],
            'isError' => false,
            '_meta' => ['io.modelcontextprotocol/serverInfo' => ServerInfo::toArray()],
        ], $this->body($response)['result']);
    }

    public function testMcpNameMayBeBase64Encoded(): void
    {
        $request = $this->modern('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'x']], [
            'Mcp-Name' => '=?base64?' . base64_encode('echo') . '?=',
        ]);

        self::assertSame(200, $this->send($request)->status);
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $request = $this->request($this->modernPayload('tools/list'), [
            'content-type' => 'application/json',
            'mcp-protocol-version' => self::MODERN,
            'MCP-METHOD' => 'tools/list',
        ]);

        self::assertSame(200, $this->send($request)->status);
    }

    /**
     * @return iterable<string, array{array<string, string|null>, int}>
     */
    public static function headerProblems(): iterable
    {
        yield 'missing MCP-Protocol-Version' => [['MCP-Protocol-Version' => null], ErrorCode::HEADER_MISMATCH];
        yield 'version header differs from _meta' => [
            ['MCP-Protocol-Version' => '2025-11-25'],
            ErrorCode::HEADER_MISMATCH,
        ];
        yield 'missing Mcp-Method' => [['Mcp-Method' => null], ErrorCode::HEADER_MISMATCH];
        yield 'Mcp-Method differs from body' => [['Mcp-Method' => 'tools/list'], ErrorCode::HEADER_MISMATCH];
        yield 'missing Mcp-Name' => [['Mcp-Name' => null], ErrorCode::HEADER_MISMATCH];
        yield 'Mcp-Name differs from body' => [['Mcp-Name' => 'other'], ErrorCode::HEADER_MISMATCH];
        yield 'Mcp-Name with control character' => [['Mcp-Name' => "echo\x01"], ErrorCode::HEADER_MISMATCH];
        yield 'Mcp-Name with broken Base64' => [['Mcp-Name' => '=?base64?***?='], ErrorCode::HEADER_MISMATCH];
    }

    /**
     * @param array<string, string|null> $headers
     */
    #[DataProvider('headerProblems')]
    public function testHeaderValidationFailures(array $headers, int $code): void
    {
        $params = ['name' => 'echo', 'arguments' => ['text' => 'x']];
        $response = $this->send($this->modern('tools/call', $params, $headers));

        self::assertSame(400, $response->status);
        $body = $this->body($response);
        self::assertSame(1, $body['id']);
        self::assertSame($code, $body['error']['code']);
    }

    public function testUnsupportedProtocolVersion(): void
    {
        $payload = $this->modernPayload('tools/list');
        $payload['params']['_meta']['io.modelcontextprotocol/protocolVersion'] = '1900-01-01';
        $response = $this->send($this->request($payload, $this->modernHeaders('tools/list', '1900-01-01')));

        self::assertSame(400, $response->status);
        $error = $this->body($response)['error'];
        self::assertSame(ErrorCode::UNSUPPORTED_PROTOCOL_VERSION, $error['code']);
        self::assertSame(['supported' => ['2026-07-28', '2025-11-25'], 'requested' => '1900-01-01'], $error['data']);
    }

    public function testMissingProtocolVersionInMetaIsInvalidParams(): void
    {
        $payload = $this->modernPayload('tools/list');
        unset($payload['params']['_meta']['io.modelcontextprotocol/protocolVersion']);
        $response = $this->send($this->request($payload, $this->modernHeaders('tools/list')));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_PARAMS, $this->body($response)['error']['code']);
    }

    public function testMissingClientCapabilitiesIsInvalidParams(): void
    {
        $payload = $this->modernPayload('tools/list');
        unset($payload['params']['_meta']['io.modelcontextprotocol/clientCapabilities']);
        $response = $this->send($this->request($payload, $this->modernHeaders('tools/list')));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_PARAMS, $this->body($response)['error']['code']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methodsUnknownToModern(): iterable
    {
        yield 'unknown' => ['resources/list'];
        yield 'ping was removed in 2026-07-28' => ['ping'];
        yield 'initialize is legacy only' => ['initialize'];
    }

    #[DataProvider('methodsUnknownToModern')]
    public function testUnknownModernMethodIs404(string $method): void
    {
        $response = $this->send($this->modern($method));

        self::assertSame(404, $response->status);
        self::assertSame(ErrorCode::METHOD_NOT_FOUND, $this->body($response)['error']['code']);
    }

    public function testUnknownToolIsInvalidParams(): void
    {
        $params = ['name' => 'nope', 'arguments' => []];
        $response = $this->send($this->modern('tools/call', $params, ['Mcp-Name' => 'nope']));

        self::assertSame(200, $response->status);
        $error = $this->body($response)['error'];
        self::assertSame(ErrorCode::INVALID_PARAMS, $error['code']);
        self::assertSame('Unknown tool: nope', $error['message']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidEchoArguments(): iterable
    {
        yield 'missing text' => [[]];
        yield 'wrong type' => [['text' => 1]];
        yield 'unknown argument' => [['text' => 'a', 'extra' => true]];
        yield 'too long' => [['text' => str_repeat('あ', EchoTool::MAX_LENGTH + 1)]];
        yield 'arguments is a list' => [['a']];
    }

    #[DataProvider('invalidEchoArguments')]
    public function testInvalidToolArgumentsAreInvalidParams(mixed $arguments): void
    {
        $response = $this->send($this->modern('tools/call', ['name' => 'echo', 'arguments' => $arguments]));

        self::assertSame(ErrorCode::INVALID_PARAMS, $this->body($response)['error']['code']);
    }

    public function testCursorIsRejectedBecauseNoneIsIssued(): void
    {
        $response = $this->send($this->modern('tools/list', ['cursor' => 'abc']));

        self::assertSame(ErrorCode::INVALID_PARAMS, $this->body($response)['error']['code']);
    }

    public function testSessionIdIsIgnoredAndNotIssued(): void
    {
        $response = $this->send($this->modern('tools/list', [], ['Mcp-Session-Id' => 'abc']));

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('Mcp-Session-Id', $response->headers);
    }

    // ---- 2025-11-25 (legacy) ----

    public function testLegacyInitialize(): void
    {
        $response = $this->send($this->request([
            'jsonrpc' => '2.0',
            'id' => 'init-1',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::LEGACY,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]));

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('Mcp-Session-Id', $response->headers);
        $body = $this->body($response);
        self::assertSame('init-1', $body['id']);
        self::assertSame(self::LEGACY, $body['result']['protocolVersion']);
        self::assertSame(['tools' => ['listChanged' => false]], $body['result']['capabilities']);
        self::assertSame(ServerInfo::toArray(), $body['result']['serverInfo']);
        self::assertArrayNotHasKey('resultType', $body['result']);
    }

    public function testLegacyInitializeWithOlderVersionNegotiatesLatestLegacy(): void
    {
        $body = $this->body($this->send($this->request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass()],
        ])));

        self::assertSame(self::LEGACY, $body['result']['protocolVersion']);
    }

    public function testLegacyToolsListAndCall(): void
    {
        $list = $this->body($this->send($this->legacy('tools/list')));
        self::assertSame(['echo'], array_column($list['result']['tools'], 'name'));
        self::assertArrayNotHasKey('resultType', $list['result']);
        self::assertArrayNotHasKey('ttlMs', $list['result']);

        $params = ['name' => 'echo', 'arguments' => ['text' => 'a']];
        $call = $this->body($this->send($this->legacy('tools/call', $params)));
        self::assertSame([['type' => 'text', 'text' => 'a']], $call['result']['content']);
    }

    public function testLegacyPingReturnsEmptyObject(): void
    {
        $response = $this->send($this->legacy('ping'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"result":{}', $response->body);
    }

    public function testLegacyUnknownMethod(): void
    {
        $response = $this->send($this->legacy('resources/list'));

        self::assertSame(200, $response->status);
        self::assertSame(ErrorCode::METHOD_NOT_FOUND, $this->body($response)['error']['code']);
    }

    public function testLegacyRequestWithoutVersionHeaderIsRejected(): void
    {
        $response = $this->send($this->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::HEADER_MISMATCH, $this->body($response)['error']['code']);
    }

    public function testLegacyRequestWithUnsupportedVersionHeader(): void
    {
        $response = $this->send($this->request(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2025-06-18'],
        ));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::UNSUPPORTED_PROTOCOL_VERSION, $this->body($response)['error']['code']);
    }

    // ---- HTTP transport ----

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPostMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'DELETE' => ['DELETE'];
        yield 'PUT' => ['PUT'];
    }

    #[DataProvider('nonPostMethods')]
    public function testOnlyPostIsAllowed(string $method): void
    {
        $response = $this->send(new Request($method, [], ''));

        self::assertSame(405, $response->status);
        self::assertSame('POST', $response->headers['Allow']);
    }

    // Origin, source IP and the Bearer token are checked before the server (Auth\GuardTest).

    // ---- audit log ----

    public function testToolCallIsAudited(): void
    {
        $audit = new MemoryAuditLogger();
        (new Server(new ToolRegistry([new EchoTool()]), $audit))
            ->handle($this->modern('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hello']]));

        $record = $audit->events('tools/call')[0];
        self::assertSame('echo', $record['tool']);
        self::assertSame(['text_length' => 5], $record['arguments']);
        self::assertSame('ok', $record['result']);
        self::assertSame('2026-07-28', $record['protocol']);
        self::assertSame(['name' => 'test', 'version' => '1.0'], $record['client']);
    }

    public function testLegacyToolCallIsAudited(): void
    {
        $audit = new MemoryAuditLogger();
        (new Server(new ToolRegistry([new EchoTool()]), $audit))
            ->handle($this->legacy('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hi']]));

        $record = $audit->events('tools/call')[0];
        self::assertSame('ok', $record['result']);
        self::assertSame('2025-11-25', $record['protocol']);
        self::assertArrayNotHasKey('client', $record);
    }

    public function testToolErrorAndUnexpectedExceptionAreAudited(): void
    {
        $audit = new MemoryAuditLogger();
        $failing = new class implements Tool {
            public function name(): string
            {
                return 'failing';
            }

            public function definition(): array
            {
                return [];
            }

            public function call(array $arguments): ToolResult
            {
                if (($arguments['mode'] ?? null) === 'throw') {
                    throw new \RuntimeException('/secret/path');
                }

                return ToolResult::error('Mailbox not found');
            }

            public function auditArguments(array $arguments): array
            {
                return ['mode' => $arguments['mode'] ?? null];
            }
        };
        $server = new Server(new ToolRegistry([$failing]), $audit);
        $logged = ini_set('error_log', '/dev/null');

        $server->handle($this->modern('tools/call', ['name' => 'failing', 'arguments' => ['mode' => 'error']]));
        $response = $server->handle(
            $this->modern('tools/call', ['name' => 'failing', 'arguments' => ['mode' => 'throw']]),
        );
        ini_set('error_log', (string) $logged);

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('/secret/path', $response->body);
        self::assertSame(
            ['tool_error', 'internal_error'],
            array_column($audit->events('tools/call'), 'result'),
        );
    }

    public function testContentTypeMustBeJson(): void
    {
        $response = $this->send($this->modern('tools/list', [], ['Content-Type' => 'text/plain']));

        self::assertSame(415, $response->status);
    }

    public function testContentTypeWithCharsetIsAccepted(): void
    {
        $response = $this->send($this->modern('tools/list', [], ['Content-Type' => 'application/json; charset=utf-8']));

        self::assertSame(200, $response->status);
    }

    public function testBodyTooLarge(): void
    {
        $response = $this->send(new Request('POST', ['Content-Type' => 'application/json'], '', true));

        self::assertSame(413, $response->status);
    }

    public function testInvalidJson(): void
    {
        $response = $this->send(new Request('POST', ['Content-Type' => 'application/json'], '{"jsonrpc":'));

        self::assertSame(400, $response->status);
        $body = $this->body($response);
        self::assertSame(ErrorCode::PARSE_ERROR, $body['error']['code']);
        self::assertArrayNotHasKey('id', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMessages(): iterable
    {
        yield 'batch' => ['[{"jsonrpc":"2.0","id":1,"method":"tools/list"}]'];
        yield 'not an object' => ['"tools/list"'];
        yield 'wrong jsonrpc version' => ['{"jsonrpc":"1.0","id":1,"method":"tools/list"}'];
        yield 'missing method' => ['{"jsonrpc":"2.0","id":1}'];
        yield 'client response' => ['{"jsonrpc":"2.0","id":1,"result":{}}'];
        yield 'null id' => ['{"jsonrpc":"2.0","id":null,"method":"tools/list"}'];
        yield 'params is a list' => ['{"jsonrpc":"2.0","id":1,"method":"tools/list","params":[1]}'];
    }

    #[DataProvider('invalidMessages')]
    public function testInvalidRequests(string $body): void
    {
        $response = $this->send(new Request('POST', ['Content-Type' => 'application/json'], $body));

        self::assertSame(400, $response->status);
        self::assertSame(ErrorCode::INVALID_REQUEST, $this->body($response)['error']['code']);
    }

    public function testNotificationIsAcceptedWithoutBody(): void
    {
        $response = $this->send($this->request(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        self::assertSame(202, $response->status);
        self::assertSame('', $response->body);
    }

    // ---- helpers ----

    private function send(Request $request): Response
    {
        return (new Server(new ToolRegistry([new EchoTool()])))->handle($request);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string|null> $headers overrides; null removes the header
     */
    private function modern(string $method, array $params = [], array $headers = []): Request
    {
        $payload = $this->modernPayload($method, $params);
        $all = $this->modernHeaders($method);
        if (isset($params['name']) && is_string($params['name'])) {
            $all['Mcp-Name'] = $params['name'];
        }
        foreach ($headers as $name => $value) {
            $all[$name] = $value;
        }

        return $this->request($payload, array_filter($all, static fn (?string $v): bool => $v !== null));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{jsonrpc: string, id: int, method: string, params: array<string, mixed>}
     */
    private function modernPayload(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params + [
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MODERN,
                    'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1.0'],
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function modernHeaders(string $method, string $version = self::MODERN): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => $version,
            'Mcp-Method' => $method,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function legacy(string $method, array $params = []): Request
    {
        $payload = ['jsonrpc' => '2.0', 'id' => 7, 'method' => $method];
        if ($params !== []) {
            $payload['params'] = $params;
        }

        return $this->request($payload, ['Content-Type' => 'application/json', 'MCP-Protocol-Version' => self::LEGACY]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function request(array $payload, array $headers = ['Content-Type' => 'application/json']): Request
    {
        return new Request('POST', $headers, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

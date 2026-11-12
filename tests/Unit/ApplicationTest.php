<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit;

use Cartwright\ImapMcp\Application;
use Cartwright\ImapMcp\Auth\ConfigAccountResolver;
use Cartwright\ImapMcp\Auth\Guard;
use Cartwright\ImapMcp\Auth\Lockout;
use Cartwright\ImapMcp\Config\Config;
use Cartwright\ImapMcp\Http\Request;
use Cartwright\ImapMcp\Http\Response;
use Cartwright\ImapMcp\Mail\MailException;
use Cartwright\ImapMcp\Mcp\Server;
use Cartwright\ImapMcp\Policy\ToolPolicy;
use Cartwright\ImapMcp\Tests\Support\FakeMailbox;
use Cartwright\ImapMcp\Tests\Support\MailFixtures;
use Cartwright\ImapMcp\Tests\Support\MemoryAuditLogger;
use Cartwright\ImapMcp\Tests\Support\TempDirectory;
use Cartwright\ImapMcp\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The whole request path: Guard → AccountResolver → ToolPolicy → Server → audit log (SPEC.md 4, 11).
 */
#[CoversClass(Application::class)]
#[CoversClass(Guard::class)]
#[CoversClass(ConfigAccountResolver::class)]
#[CoversClass(ToolPolicy::class)]
#[CoversClass(Server::class)]
final class ApplicationTest extends TestCase
{
    private const IP = '203.0.113.10';

    private TempDirectory $dir;
    private MemoryAuditLogger $audit;
    private FakeMailbox $mailbox;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory();
        $this->audit = new MemoryAuditLogger();
        $this->mailbox = new FakeMailbox();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testAuthenticatedToolCallIsServedAuditedAndTheMailboxClosed(): void
    {
        $this->mailbox->details['INBOX'][7] = MailFixtures::detail(7, 'secret body text');

        $response = $this->app()->handle($this->toolCall('get_email', ['uid' => 7]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('secret body text', $response->body);
        self::assertTrue($this->mailbox->closed, 'one connection per request');
        $records = $this->audit->events('tools/call');
        self::assertCount(1, $records);
        $record = $records[0];
        self::assertSame(self::IP, $record['ip']);
        self::assertSame('default', $record['account']);
        self::assertSame('test', $record['token']);
        self::assertSame('get_email', $record['tool']);
        self::assertSame(['folder' => null, 'uid' => 7, 'mark_as_read' => null], $record['arguments']);
        self::assertSame('ok', $record['result']);
        self::assertIsInt($record['duration_ms']);
        self::assertSame(['name' => 'test', 'version' => '1.0'], $record['client']);
        self::assertSame('2026-07-28', $record['protocol']);
        self::assertStringNotContainsString('secret body text', serialize($records), 'bodies are never logged');
    }

    public function testMailToolsAreListedAndWriteToolsAreNot(): void
    {
        $list = $this->decode($this->app()->handle($this->modern('tools/list', [])));

        self::assertSame(
            ['list_folders', 'search_emails', 'get_email'],
            array_column($list['result']['tools'], 'name'),
        );
    }

    public function testMailServerFailureIsAToolErrorAndAudited(): void
    {
        $this->mailbox->failWith = MailException::loginFailed();

        $result = $this->decode($this->app()->handle($this->toolCall('list_folders', [])))['result'];

        self::assertTrue($result['isError']);
        self::assertSame('tool_error', $this->audit->events('tools/call')[0]['result']);
    }

    public function testMissingTokenIs401AndLogged(): void
    {
        $response = $this->app()->handle($this->toolCall('list_folders', [], null));

        self::assertSame(401, $response->status);
        self::assertSame('Bearer', $response->headers['WWW-Authenticate']);
        self::assertSame(
            [['event' => 'auth_failed', 'ip' => self::IP, 'reason' => 'missing']],
            $this->audit->records->getArrayCopy(),
        );
        self::assertFalse($this->mailbox->closed, 'no mailbox is created before authentication');
    }

    public function testWrongTokenIs401(): void
    {
        self::assertSame(401, $this->app()->handle($this->toolCall('list_folders', [], 'Bearer nope'))->status);
    }

    public function testDisallowedIpIs403(): void
    {
        $response = $this->app()->handle($this->toolCall('list_folders', [], ip: '198.51.100.1'));

        self::assertSame(403, $response->status);
    }

    public function testDisabledToolIsNotListedAndCannotBeCalled(): void
    {
        $app = $this->app(TestConfig::config(['tools' => ['search_emails' => false]]));

        $list = $this->decode($app->handle($this->modern('tools/list', [])));
        self::assertSame(['list_folders', 'get_email'], array_column($list['result']['tools'], 'name'));

        $call = $app->handle($this->toolCall('search_emails', []));
        self::assertSame(200, $call->status);
        $error = $this->decode($call)['error'];
        self::assertSame(-32602, $error['code']);
        self::assertSame('Unknown tool: search_emails', $error['message']);
        self::assertSame('unknown_tool', $this->audit->events('tools/call')[0]['result']);
        self::assertNull($this->mailbox->lastCriteria);
    }

    public function testInvalidArgumentsAreAuditedWithoutTheirValues(): void
    {
        $this->app()->handle($this->toolCall('search_emails', ['from' => 'secret@example.com', 'limit' => 999]));

        $record = $this->audit->events('tools/call')[0];
        self::assertSame('invalid_params', $record['result']);
        self::assertSame(['folder' => null, 'filters' => ['from'], 'limit' => 999], $record['arguments']);
    }

    public function testNonPostIsStillRefusedAfterAuthentication(): void
    {
        $request = new Request('GET', ['Authorization' => 'Bearer ' . TestConfig::TOKEN], '', false, self::IP);

        self::assertSame(405, $this->app()->handle($request)->status);
    }

    public function testFromConfigWritesTheAuditLogUnderStorageDir(): void
    {
        $config = TestConfig::config([], $this->dir->path . '/var');

        Application::fromConfig($config)->handle($this->toolCall('list_folders', [], 'Bearer wrong'));

        $files = $this->dir->glob('var/logs/audit-*.jsonl');
        self::assertCount(1, $files);
        self::assertStringContainsString('"event":"auth_failed"', (string) file_get_contents($files[0]));
        self::assertCount(1, $this->dir->glob('var/lockout/*.json'));
    }

    private function app(?Config $config = null): Application
    {
        $config ??= TestConfig::config();

        $mailbox = $this->mailbox;

        return new Application(
            $config,
            $this->audit,
            new Lockout($this->dir->path . '/lockout', $config->lockout),
            static fn (): FakeMailbox => $mailbox,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function toolCall(
        string $tool,
        array $arguments,
        ?string $authorization = 'Bearer ' . TestConfig::TOKEN,
        string $ip = self::IP,
    ): Request {
        return $this->modern('tools/call', ['name' => $tool, 'arguments' => $arguments], $authorization, $ip);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function modern(
        string $method,
        array $params,
        ?string $authorization = 'Bearer ' . TestConfig::TOKEN,
        string $ip = self::IP,
    ): Request {
        $headers = [
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ];
        if (isset($params['name']) && is_string($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }
        if ($authorization !== null) {
            $headers['Authorization'] = $authorization;
        }
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1.0'],
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return new Request('POST', $headers, $body, false, $ip);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

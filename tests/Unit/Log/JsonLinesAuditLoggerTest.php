<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Log;

use Cartwright\ImapMcp\Log\JsonLinesAuditLogger;
use Cartwright\ImapMcp\Tests\Support\TempDirectory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonLinesAuditLogger::class)]
final class JsonLinesAuditLoggerTest extends TestCase
{
    private TempDirectory $dir;
    private int $now;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory();
        // 2026-11-12 09:30 in Tokyo (00:30 UTC)
        $this->now = (new DateTimeImmutable('2026-11-12T09:30:00+09:00'))->getTimestamp();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testWritesOneJsonLinePerRecordWithContext(): void
    {
        $logger = $this->logger()->withContext(['ip' => '203.0.113.1'])->withContext(['account' => 'default']);

        $logger->record('tools/call', ['tool' => 'echo', 'result' => 'ok']);
        $logger->record('auth_failed', ['reason' => 'invalid']);

        $lines = $this->lines('2026-11-12');
        self::assertCount(2, $lines);
        self::assertSame([
            'ts' => '2026-11-12T09:30:00+09:00',
            'event' => 'tools/call',
            'account' => 'default',
            'ip' => '203.0.113.1',
            'tool' => 'echo',
            'result' => 'ok',
        ], $lines[0]);
        self::assertSame('auth_failed', $lines[1]['event']);
    }

    public function testFileDateFollowsTheConfiguredTimezone(): void
    {
        $this->now = (new DateTimeImmutable('2026-11-12T23:30:00+00:00'))->getTimestamp(); // 08:30 next day in Tokyo

        $this->logger()->record('x');

        self::assertFileExists($this->dir->path . '/logs/audit-2026-11-13.jsonl');
    }

    public function testNewlinesInValuesCannotForgeRecords(): void
    {
        $this->logger()->record('tools/call', ['tool' => "echo\n{\"event\":\"forged\"}"]);

        self::assertCount(1, $this->lines('2026-11-12'));
    }

    public function testInvalidUtf8IsReplacedInsteadOfDroppingTheRecord(): void
    {
        $this->logger()->record('tools/call', ['tool' => "bad\xff"]);

        self::assertSame("bad\u{FFFD}", $this->lines('2026-11-12')[0]['tool']);
    }

    public function testNewDayFileDeletesFilesOlderThanRetention(): void
    {
        mkdir($this->dir->path . '/logs', 0700, true);
        foreach (['2026-10-13', '2026-10-14', '2026-11-11'] as $day) {
            file_put_contents($this->dir->path . "/logs/audit-$day.jsonl", "{}\n");
        }
        file_put_contents($this->dir->path . '/logs/other.txt', 'keep');

        $this->logger()->record('x');

        // 30 days are kept: 2026-10-14 .. 2026-11-12.
        self::assertFileDoesNotExist($this->dir->path . '/logs/audit-2026-10-13.jsonl');
        self::assertFileExists($this->dir->path . '/logs/audit-2026-10-14.jsonl');
        self::assertFileExists($this->dir->path . '/logs/audit-2026-11-11.jsonl');
        self::assertFileExists($this->dir->path . '/logs/other.txt');
    }

    public function testLogFileIsPrivate(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permissions only');
        }
        $this->logger()->record('x');

        self::assertSame(0600, fileperms($this->dir->path . '/logs/audit-2026-11-12.jsonl') & 0777);
    }

    private function logger(): JsonLinesAuditLogger
    {
        return new JsonLinesAuditLogger(
            $this->dir->path . '/logs',
            new DateTimeZone('Asia/Tokyo'),
            [],
            fn (): int => $this->now,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(string $day): array
    {
        $content = (string) file_get_contents($this->dir->path . "/logs/audit-$day.jsonl");
        $lines = [];
        foreach (explode("\n", trim($content)) as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            $lines[] = $decoded;
        }

        return $lines;
    }
}

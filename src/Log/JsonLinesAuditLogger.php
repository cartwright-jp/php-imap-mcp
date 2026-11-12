<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Log;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * Audit log as JSON Lines, one file per day (audit-YYYY-MM-DD.jsonl), kept for 30 days (SPEC.md 9).
 *
 * Old files are deleted when the first record of a day creates a new file, so cron is not needed.
 * A failure to write is reported to the PHP error log and does not fail the request.
 */
final class JsonLinesAuditLogger implements AuditLogger
{
    public const RETENTION_DAYS = 30;
    private const FILE_PATTERN = '/^audit-(\d{4}-\d{2}-\d{2})\.jsonl$/';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param array<string, mixed> $context
     * @param (Closure(): int)|null $clock current Unix time; injectable for tests
     */
    public function __construct(
        private readonly string $directory,
        private readonly DateTimeZone $timezone,
        private readonly array $context = [],
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function withContext(array $context): self
    {
        return new self($this->directory, $this->timezone, $context + $this->context, $this->clock);
    }

    public function record(string $event, array $fields = []): void
    {
        $now = (new DateTimeImmutable('@' . ($this->clock)()))->setTimezone($this->timezone);
        $entry = ['ts' => $now->format(DATE_ATOM), 'event' => $event] + $this->context + $fields;

        try {
            $line = json_encode($entry, self::JSON_FLAGS) . "\n";
        } catch (JsonException) {
            error_log('php-imap-mcp: could not encode an audit log entry');
            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            error_log('php-imap-mcp: cannot create the audit log directory');
            return;
        }
        $path = $this->directory . '/audit-' . $now->format('Y-m-d') . '.jsonl';
        $isNew = !is_file($path);
        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('php-imap-mcp: cannot write the audit log');
            return;
        }
        if ($isNew) {
            @chmod($path, 0600);
            $this->deleteOldFiles($now);
        }
    }

    private function deleteOldFiles(DateTimeImmutable $now): void
    {
        $oldest = $now->modify(sprintf('-%d days', self::RETENTION_DAYS - 1))->format('Y-m-d');
        $files = scandir($this->directory);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if (preg_match(self::FILE_PATTERN, $file, $m) === 1 && $m[1] < $oldest) {
                @unlink($this->directory . '/' . $file);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Config;

use DateTimeZone;

/**
 * config.php 'runtime' (SPEC.md 8, 9).
 */
final class RuntimeSettings
{
    public const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];

    public function __construct(
        public readonly DateTimeZone $timezone,
        public readonly int $imapTimeoutSec,
        public readonly int $requestBudgetSec,
        /** Holds logs/ (audit log) and lockout/; must be outside public_html. */
        public readonly string $storageDir,
        public readonly string $logLevel,
    ) {
    }

    public function auditLogDir(): string
    {
        return $this->storageDir . '/logs';
    }

    public function lockoutDir(): string
    {
        return $this->storageDir . '/lockout';
    }
}

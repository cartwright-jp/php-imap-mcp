<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Log;

/**
 * Audit log of tool calls and authentication events (SPEC.md 7).
 *
 * Never pass passwords, tokens or message bodies in the fields.
 */
interface AuditLogger
{
    /**
     * @param array<string, mixed> $fields
     */
    public function record(string $event, array $fields = []): void;

    /**
     * A logger that adds these fields (e.g. ip, account) to every record.
     *
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self;
}

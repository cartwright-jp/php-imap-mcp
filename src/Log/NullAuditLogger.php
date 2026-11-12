<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Log;

final class NullAuditLogger implements AuditLogger
{
    public function record(string $event, array $fields = []): void
    {
    }

    public function withContext(array $context): self
    {
        return $this;
    }
}

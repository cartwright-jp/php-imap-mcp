<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Support;

use ArrayObject;
use Cartwright\ImapMcp\Log\AuditLogger;

/**
 * Collects audit records in memory. Loggers made by withContext() share the same record list.
 */
final class MemoryAuditLogger implements AuditLogger
{
    /** @var ArrayObject<int, array<string, mixed>> */
    public readonly ArrayObject $records;

    /**
     * @param array<string, mixed> $context
     * @param ArrayObject<int, array<string, mixed>>|null $records
     */
    public function __construct(private readonly array $context = [], ?ArrayObject $records = null)
    {
        $this->records = $records ?? new ArrayObject();
    }

    public function record(string $event, array $fields = []): void
    {
        $this->records->append(['event' => $event] + $this->context + $fields);
    }

    public function withContext(array $context): self
    {
        return new self($context + $this->context, $this->records);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(string $event): array
    {
        return array_values(array_filter(
            $this->records->getArrayCopy(),
            static fn (array $r): bool => $r['event'] === $event,
        ));
    }
}

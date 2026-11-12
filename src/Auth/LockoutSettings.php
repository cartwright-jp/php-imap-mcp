<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

/**
 * Ban an IP for $banSec after $maxFailures authentication failures within $windowSec (SPEC.md 7).
 */
final class LockoutSettings
{
    public function __construct(
        public readonly int $maxFailures = 10,
        public readonly int $windowSec = 600,
        public readonly int $banSec = 900,
    ) {
    }
}

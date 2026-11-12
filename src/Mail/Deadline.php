<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail;

/**
 * The request's time budget (config runtime.request_budget_sec, SPEC.md 9), so long operations can stop
 * early and return partial results with a cursor instead of hitting PHP's time limit.
 */
final class Deadline
{
    public function __construct(private readonly float $expiresAt)
    {
    }

    public static function in(float $seconds): self
    {
        return new self(microtime(true) + $seconds);
    }

    public function remaining(): float
    {
        return $this->expiresAt - microtime(true);
    }
}

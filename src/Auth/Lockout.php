<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

use Closure;
use RuntimeException;

/**
 * File-based per-IP lockout after repeated authentication failures (SPEC.md 7).
 *
 * One small JSON file per IP (named by its hash) under the storage directory, updated under flock.
 *
 * @phpstan-type State array{failures: list<int>, banned_until: int}
 */
final class Lockout
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock current Unix time; injectable for tests
     */
    public function __construct(
        private readonly string $directory,
        private readonly LockoutSettings $settings,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @return int|null seconds until the ban ends, or null when the IP is not banned
     */
    public function bannedFor(string $ip): ?int
    {
        $path = $this->path($ip);
        if (!is_file($path)) {
            return null;
        }
        $state = $this->decode((string) @file_get_contents($path));
        $remaining = $state['banned_until'] - ($this->clock)();

        return $remaining > 0 ? $remaining : null;
    }

    /**
     * Records one failure.
     *
     * @return bool true when this failure started a ban
     */
    public function recordFailure(string $ip): bool
    {
        return $this->update($ip, /** @param State $state */ function (array $state): array {
            $now = ($this->clock)();
            $since = $now - $this->settings->windowSec;
            $failures = array_values(array_filter($state['failures'], static fn (int $t): bool => $t > $since));
            $failures[] = $now;
            $banned = false;
            if (count($failures) >= $this->settings->maxFailures && $state['banned_until'] <= $now) {
                $state['banned_until'] = $now + $this->settings->banSec;
                $failures = [];
                $banned = true;
            }
            $state['failures'] = $failures;

            return [$state, $banned];
        });
    }

    /**
     * Forgets earlier failures after a successful authentication.
     */
    public function reset(string $ip): void
    {
        $path = $this->path($ip);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param Closure(State): array{0: State, 1: bool} $change returns the new state and the result
     */
    private function update(string $ip, Closure $change): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the lockout directory');
        }
        $handle = @fopen($this->path($ip), 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the lockout file');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the lockout file');
            }
            [$state, $result] = $change($this->decode((string) stream_get_contents($handle)));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /**
     * @return State
     */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        $failures = [];
        if (is_array($data) && is_array($data['failures'] ?? null)) {
            foreach ($data['failures'] as $t) {
                if (is_int($t)) {
                    $failures[] = $t;
                }
            }
        }
        $bannedUntil = is_array($data) && is_int($data['banned_until'] ?? null) ? $data['banned_until'] : 0;

        return ['failures' => $failures, 'banned_until' => $bannedUntil];
    }

    private function path(string $ip): string
    {
        return $this->directory . '/' . hash('sha256', $ip) . '.json';
    }
}

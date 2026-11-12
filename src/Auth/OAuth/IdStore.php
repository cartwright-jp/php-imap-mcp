<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

use Closure;
use RuntimeException;

/**
 * The small, short-lived records OAuth needs (SPEC.md 7 "状態について"): used authorization codes, used
 * refresh tokens and revoked grants. One file per id (named by its hash, holding the expiry time) under the
 * storage directory. Creating the file is atomic, so a code or refresh token can be redeemed only once even
 * when two requests race.
 */
final class IdStore
{
    public const USED_CODES = 'used-codes';
    public const USED_REFRESH_TOKENS = 'used-refresh';
    public const REVOKED_GRANTS = 'revoked-grants';

    /** Expired records are swept on roughly one write in this many. */
    private const SWEEP_EVERY = 50;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock current Unix time; injectable for tests
     */
    public function __construct(private readonly string $directory, ?Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /**
     * Records the id until $expiresAt.
     *
     * @return bool true when it was not recorded before (first use), false when it already was (reuse)
     */
    public function markOnce(string $namespace, string $id, int $expiresAt): bool
    {
        $path = $this->path($namespace, $id);
        $this->ensureDirectory(dirname($path));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                fwrite($handle, (string) $expiresAt);
                fclose($handle);
                if (random_int(1, self::SWEEP_EVERY) === 1) {
                    $this->sweep($namespace);
                }

                return true;
            }
            if ($this->isMarked($namespace, $id)) {
                return false;
            }
            @unlink($path); // an expired record: forget it and try once more
        }

        return false;
    }

    public function isMarked(string $namespace, string $id): bool
    {
        $path = $this->path($namespace, $id);
        if (!is_file($path)) {
            return false;
        }
        $expiresAt = (int) @file_get_contents($path);

        return $expiresAt > ($this->clock)();
    }

    public function sweep(string $namespace): void
    {
        $now = ($this->clock)();
        $files = glob($this->directory . '/' . $namespace . '/*');
        foreach ($files === false ? [] : $files as $file) {
            if ((int) @file_get_contents($file) <= $now) {
                @unlink($file);
            }
        }
    }

    private function path(string $namespace, string $id): string
    {
        return $this->directory . '/' . $namespace . '/' . hash('sha256', $id);
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the OAuth storage directory');
        }
    }
}

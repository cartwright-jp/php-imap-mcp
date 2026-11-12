<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

/**
 * Typed reading of the claims inside a sealed token. A missing or mistyped claim makes the token invalid.
 */
final class Claims
{
    /**
     * @param array<string, mixed> $claims
     */
    private function __construct(private readonly array $claims)
    {
    }

    /**
     * @param array<string, mixed>|null $claims
     * @param list<string> $strings claims that must be non-empty strings
     * @param list<string> $ints claims that must be integers
     */
    public static function of(?array $claims, array $strings, array $ints = ['exp']): ?self
    {
        if ($claims === null) {
            return null;
        }
        foreach ($strings as $name) {
            if (!is_string($claims[$name] ?? null) || $claims[$name] === '') {
                return null;
            }
        }
        foreach ($ints as $name) {
            if (!is_int($claims[$name] ?? null)) {
                return null;
            }
        }

        return new self($claims);
    }

    public function string(string $name): string
    {
        $value = $this->claims[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    public function int(string $name): int
    {
        $value = $this->claims[$name] ?? 0;

        return is_int($value) ? $value : 0;
    }
}

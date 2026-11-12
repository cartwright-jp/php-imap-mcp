<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tools;

use DateTimeImmutable;

/**
 * Checks tools/call arguments against the limits declared in a tool's inputSchema (SPEC.md 6 共通ルール).
 */
final class Arguments
{
    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $known names declared in the inputSchema; anything else is rejected
     *
     * @throws InvalidToolArguments
     */
    public function __construct(private readonly array $arguments, array $known)
    {
        $unknown = array_diff(array_keys($arguments), $known);
        if ($unknown !== []) {
            throw new InvalidToolArguments(sprintf('Unknown argument: %s', implode(', ', $unknown)));
        }
    }

    public function string(string $name, int $maxLength, ?string $default = null): ?string
    {
        $value = $this->arguments[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            throw new InvalidToolArguments(sprintf("'%s' must be a string", $name));
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidToolArguments(sprintf("'%s' must be at most %d characters", $name, $maxLength));
        }
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidToolArguments(sprintf("'%s' must not contain control characters", $name));
        }

        return $value;
    }

    /**
     * A folder name: 1-255 characters (SPEC.md 6).
     */
    public function folder(string $name = 'folder'): string
    {
        $folder = $this->string($name, 255, 'INBOX') ?? 'INBOX';
        if (trim($folder) === '') {
            throw new InvalidToolArguments(sprintf("'%s' must not be empty", $name));
        }

        return $folder;
    }

    public function int(string $name, ?int $default, int $min, int $max): ?int
    {
        $value = $this->arguments[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        // JSON numbers like 20.0 arrive as float.
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidToolArguments(sprintf("'%s' must be an integer from %d to %d", $name, $min, $max));
        }

        return $value;
    }

    public function requiredInt(string $name, int $min, int $max): int
    {
        $value = $this->int($name, null, $min, $max);
        if ($value === null) {
            throw new InvalidToolArguments(sprintf("Missing required argument '%s'", $name));
        }

        return $value;
    }

    public function bool(string $name, bool $default): bool
    {
        $value = $this->arguments[$name] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_bool($value)) {
            throw new InvalidToolArguments(sprintf("'%s' must be true or false", $name));
        }

        return $value;
    }

    /**
     * A calendar date in ISO 8601 form (YYYY-MM-DD).
     */
    public function date(string $name): ?DateTimeImmutable
    {
        $value = $this->string($name, 10);
        if ($value === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidToolArguments(sprintf("'%s' must be a date in the form YYYY-MM-DD", $name));
        }

        return $date;
    }
}

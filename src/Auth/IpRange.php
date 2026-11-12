<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth;

use InvalidArgumentException;

/**
 * An IPv4 or IPv6 address range in CIDR notation (a bare address is a single host).
 */
final class IpRange
{
    private function __construct(
        public readonly string $notation,
        private readonly string $network,
        private readonly int $prefix,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the notation is not a valid address or CIDR range
     */
    public static function parse(string $notation): self
    {
        $parts = explode('/', trim($notation), 2);
        $address = self::pack($parts[0]);
        if ($address === null) {
            throw new InvalidArgumentException(sprintf('Invalid IP address: %s', $notation));
        }
        $maxPrefix = strlen($address) * 8;
        $prefix = $maxPrefix;
        if (isset($parts[1])) {
            if (preg_match('/^(0|[1-9][0-9]{0,2})$/', $parts[1]) !== 1 || (int) $parts[1] > $maxPrefix) {
                throw new InvalidArgumentException(sprintf('Invalid CIDR prefix: %s', $notation));
            }
            $prefix = (int) $parts[1];
        }

        return new self(trim($notation), self::mask($address, $prefix), $prefix);
    }

    public function contains(string $ip): bool
    {
        $address = self::pack($ip);
        if ($address === null || strlen($address) !== strlen($this->network)) {
            return false;
        }

        return self::mask($address, $this->prefix) === $this->network;
    }

    /**
     * Binary form of an address; IPv4-mapped IPv6 (::ffff:192.0.2.1) is treated as IPv4.
     */
    private static function pack(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    private static function mask(string $address, int $prefix): string
    {
        $masked = '';
        foreach (str_split($address) as $i => $byte) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $masked .= chr(ord($byte) & ((0xff << (8 - $bits)) & 0xff));
        }

        return $masked;
    }
}

<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Auth;

use Cartwright\ImapMcp\Auth\IpRange;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpRange::class)]
final class IpRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'v4 inside /24' => ['203.0.113.0/24', '203.0.113.42', true];
        yield 'v4 outside /24' => ['203.0.113.0/24', '203.0.114.1', false];
        yield 'v4 /32 by bare address' => ['198.51.100.7', '198.51.100.7', true];
        yield 'v4 bare address other host' => ['198.51.100.7', '198.51.100.8', false];
        yield 'v4 non-byte prefix inside' => ['198.51.100.0/26', '198.51.100.63', true];
        yield 'v4 non-byte prefix outside' => ['198.51.100.0/26', '198.51.100.64', false];
        yield 'v4 /0 matches all v4' => ['0.0.0.0/0', '192.0.2.1', true];
        yield 'v4 host bits in network are ignored' => ['203.0.113.99/24', '203.0.113.1', true];
        yield 'v4-mapped v6 client' => ['203.0.113.0/24', '::ffff:203.0.113.5', true];
        yield 'v6 inside /32' => ['2001:db8::/32', '2001:db8:1234::1', true];
        yield 'v6 outside /32' => ['2001:db8::/32', '2001:db9::1', false];
        yield 'v6 /128' => ['2001:db8::1', '2001:db8::1', true];
        yield 'v6 range does not match v4' => ['::/0', '192.0.2.1', false];
        yield 'v4 range does not match v6' => ['0.0.0.0/0', '2001:db8::1', false];
        yield 'empty client' => ['0.0.0.0/0', '', false];
        yield 'garbage client' => ['0.0.0.0/0', 'not-an-ip', false];
    }

    #[DataProvider('cases')]
    public function testContains(string $range, string $ip, bool $expected): void
    {
        self::assertSame($expected, IpRange::parse($range)->contains($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'hostname' => ['example.com'];
        yield 'v4 prefix too long' => ['203.0.113.0/33'];
        yield 'v6 prefix too long' => ['2001:db8::/129'];
        yield 'negative prefix' => ['203.0.113.0/-1'];
        yield 'non-numeric prefix' => ['203.0.113.0/abc'];
        yield 'leading zero prefix' => ['203.0.113.0/024'];
        yield 'out of range octet' => ['203.0.113.256'];
    }

    #[DataProvider('invalid')]
    public function testInvalidNotationIsRejected(string $notation): void
    {
        $this->expectException(InvalidArgumentException::class);

        IpRange::parse($notation);
    }
}

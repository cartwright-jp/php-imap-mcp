<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Auth;

use Cartwright\ImapMcp\Auth\Lockout;
use Cartwright\ImapMcp\Auth\LockoutSettings;
use Cartwright\ImapMcp\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Lockout::class)]
#[CoversClass(LockoutSettings::class)]
final class LockoutTest extends TestCase
{
    private const IP = '203.0.113.10';

    private TempDirectory $dir;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->dir = new TempDirectory();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testBanStartsAtMaxFailuresAndEndsAfterBanTime(): void
    {
        $lockout = $this->lockout(new LockoutSettings(3, 600, 900));

        self::assertFalse($lockout->recordFailure(self::IP));
        self::assertFalse($lockout->recordFailure(self::IP));
        self::assertNull($lockout->bannedFor(self::IP));
        self::assertTrue($lockout->recordFailure(self::IP));
        self::assertSame(900, $lockout->bannedFor(self::IP));

        $this->now += 899;
        self::assertSame(1, $lockout->bannedFor(self::IP));
        $this->now += 1;
        self::assertNull($lockout->bannedFor(self::IP));
    }

    public function testFailuresOutsideTheWindowAreForgotten(): void
    {
        $lockout = $this->lockout(new LockoutSettings(3, 600, 900));

        $lockout->recordFailure(self::IP);
        $lockout->recordFailure(self::IP);
        $this->now += 601;
        self::assertFalse($lockout->recordFailure(self::IP));
        self::assertNull($lockout->bannedFor(self::IP));
    }

    public function testOtherIpsAreNotAffected(): void
    {
        $lockout = $this->lockout(new LockoutSettings(1, 600, 900));

        self::assertTrue($lockout->recordFailure(self::IP));
        self::assertNull($lockout->bannedFor('203.0.113.11'));
    }

    public function testResetForgetsFailures(): void
    {
        $lockout = $this->lockout(new LockoutSettings(2, 600, 900));

        $lockout->recordFailure(self::IP);
        $lockout->reset(self::IP);
        self::assertFalse($lockout->recordFailure(self::IP));
    }

    public function testStateFileDoesNotContainTheIp(): void
    {
        $lockout = $this->lockout(new LockoutSettings());
        $lockout->recordFailure(self::IP);

        $files = $this->dir->glob('lockout/*.json');
        self::assertCount(1, $files);
        self::assertStringNotContainsString(self::IP, basename($files[0]));
    }

    public function testCorruptStateFileIsTreatedAsEmpty(): void
    {
        $lockout = $this->lockout(new LockoutSettings(2, 600, 900));
        $lockout->recordFailure(self::IP);
        $files = $this->dir->glob('lockout/*.json');
        file_put_contents($files[0], '{broken');

        self::assertNull($lockout->bannedFor(self::IP));
        self::assertFalse($lockout->recordFailure(self::IP));
    }

    private function lockout(LockoutSettings $settings): Lockout
    {
        return new Lockout($this->dir->path . '/lockout', $settings, fn (): int => $this->now);
    }
}

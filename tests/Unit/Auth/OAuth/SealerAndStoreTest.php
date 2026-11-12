<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Auth\OAuth;

use Cartwright\ImapMcp\Auth\OAuth\IdStore;
use Cartwright\ImapMcp\Auth\OAuth\Sealer;
use Cartwright\ImapMcp\Tests\Support\TempDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sealer::class)]
#[CoversClass(IdStore::class)]
final class SealerAndStoreTest extends TestCase
{
    public function testSealedClaimsRoundTripAndAreNotReadable(): void
    {
        $sealer = new Sealer(str_repeat('k', 32));

        $token = $sealer->seal(Sealer::ACCESS_TOKEN, ['sub' => 'user@example.com', 'pwd' => 'secret-pw', 'exp' => 5]);

        self::assertStringStartsWith('pim_at_', $token);
        self::assertStringNotContainsString('secret-pw', $token);
        $raw = base64_decode(strtr(substr($token, 7), '-_', '+/'), true);
        self::assertIsString($raw);
        self::assertStringNotContainsString('secret-pw', $raw);
        $claims = ['sub' => 'user@example.com', 'pwd' => 'secret-pw', 'exp' => 5];
        self::assertSame($claims, $sealer->open(Sealer::ACCESS_TOKEN, $token));
        self::assertNotSame($token, $sealer->seal(Sealer::ACCESS_TOKEN, $claims), 'a fresh nonce every time');
    }

    public function testATokenOfOneTypeCannotBeUsedAsAnother(): void
    {
        $sealer = new Sealer(str_repeat('k', 32));
        $refresh = $sealer->seal(Sealer::REFRESH_TOKEN, ['a' => 1]);

        self::assertNull($sealer->open(Sealer::ACCESS_TOKEN, $refresh));
        // Even with the prefix swapped, the type is bound as associated data.
        self::assertNull($sealer->open(Sealer::ACCESS_TOKEN, 'pim_at_' . substr($refresh, 7)));
    }

    public function testChangedOrForeignTokensAreRejected(): void
    {
        $sealer = new Sealer(str_repeat('k', 32));
        $token = $sealer->seal(Sealer::ACCESS_TOKEN, ['a' => 1]);
        $last = $token[strlen($token) - 2];
        $changed = substr($token, 0, -2) . ($last === 'A' ? 'B' : 'A') . substr($token, -1);

        self::assertNull($sealer->open(Sealer::ACCESS_TOKEN, $changed));
        self::assertNull((new Sealer(str_repeat('x', 32)))->open(Sealer::ACCESS_TOKEN, $token), 'another key');
        self::assertNull($sealer->open(Sealer::ACCESS_TOKEN, 'pim_at_'));
        self::assertNull($sealer->open(Sealer::ACCESS_TOKEN, 'pim_at_!!!'));
        self::assertNull($sealer->open(Sealer::ACCESS_TOKEN, 'pim_at_' . str_repeat('A', 5000)));
    }

    public function testKeyMustBe32Bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Sealer('short');
    }

    public function testGeneratedKeyIsUsable(): void
    {
        $key = Sealer::generateKey();

        self::assertStringStartsWith('base64:', $key);
        $sealer = new Sealer((string) base64_decode(substr($key, 7), true));
        $sealed = $sealer->seal(Sealer::REFRESH_TOKEN, ['a' => 1]);
        self::assertSame(['a' => 1], $sealer->open(Sealer::REFRESH_TOKEN, $sealed));
        self::assertStringNotContainsString(substr($key, 7), print_r($sealer, true));
    }

    public function testIdStoreMarksOnceUntilExpiry(): void
    {
        $dir = new TempDirectory();
        $now = 1000;
        $store = new IdStore($dir->path, function () use (&$now): int {
            return $now;
        });

        self::assertTrue($store->markOnce(IdStore::USED_CODES, 'code-1', 1300));
        self::assertFalse($store->markOnce(IdStore::USED_CODES, 'code-1', 1300), 'second use is detected');
        self::assertTrue($store->isMarked(IdStore::USED_CODES, 'code-1'));
        self::assertFalse($store->isMarked(IdStore::USED_REFRESH_TOKENS, 'code-1'), 'namespaces are separate');
        self::assertSame([], array_filter(
            $dir->glob('used-codes/*'),
            static fn (string $f): bool => str_contains($f, 'code-1'),
        ), 'ids are stored hashed');

        $now = 1300;
        self::assertFalse($store->isMarked(IdStore::USED_CODES, 'code-1'));
        self::assertTrue($store->markOnce(IdStore::USED_CODES, 'code-1', 1600), 'an expired record is replaced');
        $now = 2000;
        $store->sweep(IdStore::USED_CODES);
        self::assertSame([], $dir->glob('used-codes/*'));
        $dir->remove();
    }
}

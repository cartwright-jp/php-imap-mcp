<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Auth;

use Cartwright\ImapMcp\Auth\AuthenticationFailed;
use Cartwright\ImapMcp\Auth\TokenAuthenticator;
use Cartwright\ImapMcp\Auth\TokenEntry;
use Cartwright\ImapMcp\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenAuthenticator::class)]
#[CoversClass(TokenEntry::class)]
#[CoversClass(AuthenticationFailed::class)]
final class TokenAuthenticatorTest extends TestCase
{
    public function testValidTokenReturnsItsEntry(): void
    {
        $entry = $this->authenticator()->authenticate('Bearer ' . TestConfig::TOKEN);

        self::assertSame('default', $entry->accountId);
        self::assertSame('current', $entry->label);
    }

    public function testSchemeIsCaseInsensitive(): void
    {
        self::assertSame('current', $this->authenticator()->authenticate('bearer ' . TestConfig::TOKEN)->label);
    }

    public function testSeveralTokensCanBeValidForRotation(): void
    {
        self::assertSame('rotated', $this->authenticator()->authenticate('Bearer ' . TestConfig::OTHER_TOKEN)->label);
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function failures(): iterable
    {
        yield 'no header' => [null, AuthenticationFailed::MISSING];
        yield 'empty header' => ['  ', AuthenticationFailed::MISSING];
        yield 'basic auth' => ['Basic dXNlcjpwYXNz', AuthenticationFailed::MALFORMED];
        yield 'bearer without token' => ['Bearer ', AuthenticationFailed::MALFORMED];
        yield 'token without scheme' => [TestConfig::TOKEN, AuthenticationFailed::MALFORMED];
        yield 'two tokens' => ['Bearer a b', AuthenticationFailed::MALFORMED];
        yield 'invalid characters' => ['Bearer abc"def', AuthenticationFailed::MALFORMED];
        yield 'wrong token' => ['Bearer wrong-token', AuthenticationFailed::INVALID];
        yield 'hash instead of token' => ['Bearer ' . hash('sha256', TestConfig::TOKEN), AuthenticationFailed::INVALID];
        yield 'token with extra character' => ['Bearer ' . TestConfig::TOKEN . 'x', AuthenticationFailed::INVALID];
    }

    #[DataProvider('failures')]
    public function testFailures(?string $header, string $reason): void
    {
        try {
            $this->authenticator()->authenticate($header);
            self::fail('Expected AuthenticationFailed');
        } catch (AuthenticationFailed $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testGeneratedTokensAreLongRandomAndAccepted(): void
    {
        $token = TokenAuthenticator::generateToken();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertNotSame($token, TokenAuthenticator::generateToken());
        $entry = new TokenEntry(substr(TokenEntry::hashOf($token), 7), 'default');
        self::assertSame($entry, (new TokenAuthenticator([$entry]))->authenticate('Bearer ' . $token));
    }

    private function authenticator(): TokenAuthenticator
    {
        return new TokenAuthenticator([
            new TokenEntry(hash('sha256', TestConfig::TOKEN), 'default', 'current'),
            new TokenEntry(hash('sha256', TestConfig::OTHER_TOKEN), 'default', 'rotated'),
        ]);
    }
}

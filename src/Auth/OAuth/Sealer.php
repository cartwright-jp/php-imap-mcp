<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

use InvalidArgumentException;
use JsonException;
use SodiumException;

/**
 * Seals claims into an opaque token with the server key (XChaCha20-Poly1305 AEAD, SPEC.md 7).
 *
 * Access tokens, refresh tokens, authorization codes and login-form requests all use this, each with its
 * own type: the type is a visible prefix and is also bound as associated data, so one kind of token can
 * never be used as another. The client can neither read nor change the contents.
 */
final class Sealer
{
    public const ACCESS_TOKEN = 'at';
    public const REFRESH_TOKEN = 'rt';
    public const AUTHORIZATION_CODE = 'ac';
    public const LOGIN_REQUEST = 'rq';

    private const PREFIX = 'pim_';
    private const AAD = 'php-imap-mcp/v1/';
    private const MAX_TOKEN_LENGTH = 4096;

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('The OAuth key must be 32 bytes');
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '***'];
    }

    /**
     * A new random key, as written in config.php (oauth.key).
     */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function seal(string $type, array $claims): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            self::AAD . $type,
            $nonce,
            $this->key,
        );

        return self::PREFIX . $type . '_' . rtrim(strtr(base64_encode($nonce . $cipher), '+/', '-_'), '=');
    }

    /**
     * The claims, or null when the token is not a valid token of this type (forged, changed, other key).
     * Expiry is not checked here.
     *
     * @return array<string, mixed>|null
     */
    public function open(string $type, string $token): ?array
    {
        $prefix = self::PREFIX . $type . '_';
        if (strlen($token) > self::MAX_TOKEN_LENGTH || !str_starts_with($token, $prefix)) {
            return null;
        }
        $raw = base64_decode(strtr(substr($token, strlen($prefix)), '-_', '+/'), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($raw === false || strlen($raw) <= $nonceLength) {
            return null;
        }
        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($raw, $nonceLength),
                self::AAD . $type,
                substr($raw, 0, $nonceLength),
                $this->key,
            );
        } catch (SodiumException) {
            return null;
        }
        if ($plain === false) {
            return null;
        }
        try {
            $claims = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($claims) || array_is_list($claims)) {
            return null;
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }
}

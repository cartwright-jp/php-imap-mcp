<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Checks that the test mail server from docker-compose.yml is reachable over IMAPS.
 *
 * Skipped when the server is not running, unless IMAP_TEST_REQUIRED=1 (set in CI).
 */
#[CoversNothing]
final class TestMailServerTest extends TestCase
{
    public function testImapsGreeting(): void
    {
        $host = self::env('IMAP_TEST_HOST', '127.0.0.1');
        $port = self::env('IMAP_TEST_PORT', '3993');

        // The test server uses a self-signed certificate. Production code must verify certificates (SPEC.md 7).
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            sprintf('ssl://%s:%s', $host, $port),
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            $message = sprintf(
                'Test mail server is not reachable at %s:%s (%s). Run: docker compose up -d',
                $host,
                $port,
                $errstr,
            );
            if (self::env('IMAP_TEST_REQUIRED', '0') === '1') {
                self::fail($message);
            }
            self::markTestSkipped($message);
        }

        stream_set_timeout($stream, 5);
        $greeting = fgets($stream);
        fwrite($stream, "a1 LOGOUT\r\n");
        fclose($stream);

        self::assertIsString($greeting);
        self::assertStringStartsWith('* OK', $greeting);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}

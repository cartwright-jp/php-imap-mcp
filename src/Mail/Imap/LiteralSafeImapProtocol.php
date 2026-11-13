<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Mail\Imap;

use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\RuntimeException;

/**
 * webklex's ImapProtocol, but a literal ({n}) waits for the "+" continuation past untagged responses.
 *
 * A server may send untagged data at any time (RFC 9051 7), and Courier-IMAP (XServer) does so before the
 * continuation of an APPEND to the open folder ("* 4 EXISTS", then "+ OK"). webklex expects "+ " as the very
 * next line and gives up, so save_draft failed there.
 */
class LiteralSafeImapProtocol extends ImapProtocol
{
    /**
     * @param array<mixed> $tokens strings, or [ "{n}", literal ] pairs from escapeString()
     */
    public function sendRequest(string $command, array $tokens = [], ?string &$tag = null): Response
    {
        if ($tag === null || $tag === '') {
            $this->noun++;
            $tag = 'TAG' . $this->noun;
        }
        $line = $tag . ' ' . $command;
        $response = new Response($this->noun, $this->debug);

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $this->write($response, $line . ' ' . self::stringOf($token[0] ?? ''));
                $this->awaitContinuation($response, $tag);
                $line = self::stringOf($token[1] ?? '');
            } else {
                $line .= ' ' . self::stringOf($token);
            }
        }
        $this->write($response, $line);

        return $response;
    }

    private function awaitContinuation(Response $response, string $tag): void
    {
        // Bounded so a misbehaving server cannot keep us here; the stream timeout bounds each line.
        for ($i = 0; $i < 1000; $i++) {
            $next = $this->nextLine($response);
            if (str_starts_with($next, '+')) {
                return;
            }
            if (!str_starts_with($next, '* ')) {
                break; // a tagged NO/BAD (or anything else) instead of the continuation
            }
        }
        throw new RuntimeException('failed to send literal string');
    }

    private static function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

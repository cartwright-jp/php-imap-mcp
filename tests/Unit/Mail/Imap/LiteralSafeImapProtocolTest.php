<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Imap;

use Cartwright\ImapMcp\Mail\Imap\LiteralSafeImapProtocol;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config as WebklexConfig;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Exceptions\RuntimeException;

#[CoversClass(LiteralSafeImapProtocol::class)]
final class LiteralSafeImapProtocolTest extends TestCase
{
    private const MESSAGE = "Subject: test\r\n\r\nline 1\r\nline 2\r\n";
    /** Untagged data (new mail in the selected folder) before the continuation, as RFC 9051 allows. */
    private const UNTAGGED_FIRST = "* 4 EXISTS\r\n* 1 RECENT\r\n+ OK\r\n"
        . "TAG1 OK [APPENDUID 779520765 41] APPEND Ok.\r\n";

    /** @var resource|null */
    private $server = null;
    /** webklex logs out (and waits for a reply) on destruction; tearDown detaches the stream first. */
    private ?ImapProtocol $imap = null;

    public function testAppendWaitsForTheContinuationPastUntaggedResponses(): void
    {
        $imap = $this->connected(new LiteralSafeImapProtocol(WebklexConfig::make([])), self::UNTAGGED_FIRST);

        $lines = $imap->appendMessage('INBOX.Drafts', self::MESSAGE, ['\\Draft', '\\Seen'])->validatedData();

        self::assertIsArray($lines);
        self::assertMatchesRegularExpression('/\[APPENDUID 779520765 41\]/', (string) json_encode($lines));
        self::assertSame(
            'TAG1 APPEND "INBOX.Drafts" (\\Draft \\Seen) {' . strlen(self::MESSAGE) . "}\r\n" . self::MESSAGE . "\r\n",
            $this->sent(),
        );
    }

    public function testAContinuationRightAwayStillWorks(): void
    {
        $imap = $this->connected(
            new LiteralSafeImapProtocol(WebklexConfig::make([])),
            "+ go ahead\r\nTAG1 OK APPEND completed\r\n",
        );

        self::assertIsArray($imap->appendMessage('Drafts', self::MESSAGE)->validatedData());
    }

    public function testATaggedRefusalInsteadOfTheContinuationFails(): void
    {
        $imap = $this->connected(
            new LiteralSafeImapProtocol(WebklexConfig::make([])),
            "* 4 EXISTS\r\nTAG1 NO [TRYCREATE] No such folder\r\n",
        );

        $this->expectException(RuntimeException::class);
        $imap->appendMessage('Missing', self::MESSAGE);
    }

    public function testPlainWebklexFailsOnTheSameReply(): void
    {
        // Documents why the subclass exists; if webklex fixes this, the subclass can go.
        $imap = $this->connected(new ImapProtocol(WebklexConfig::make([])), self::UNTAGGED_FIRST);

        $this->expectException(RuntimeException::class);
        $imap->appendMessage('INBOX.Drafts', self::MESSAGE, ['\\Draft', '\\Seen']);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }
        if ($this->imap !== null) {
            if (is_resource($this->imap->stream)) {
                fclose($this->imap->stream);
            }
            $this->imap->stream = false;
            $this->imap = null;
        }
    }

    /**
     * @template T of ImapProtocol
     *
     * @param T $imap
     *
     * @return T
     */
    private function connected(ImapProtocol $imap, string $serverSays): ImapProtocol
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$client, $this->server] = $pair;
        fwrite($this->server, $serverSays);
        $imap->stream = $client;
        $this->imap = $imap;

        return $imap;
    }

    private function sent(): string
    {
        self::assertIsResource($this->server);
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }
}

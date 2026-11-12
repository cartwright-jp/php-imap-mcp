<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Integration;

use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Mail\Deadline;
use Cartwright\ImapMcp\Mail\Imap\ImapMailbox;
use Cartwright\ImapMcp\Mail\Imap\ResponseParser;
use Cartwright\ImapMcp\Mail\MailException;
use Cartwright\ImapMcp\Mail\MessageDetail;
use Cartwright\ImapMcp\Mail\MessageSummary;
use Cartwright\ImapMcp\Mail\Mime\BodyPart;
use Cartwright\ImapMcp\Mail\SearchCriteria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config as WebklexConfig;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

/**
 * ImapMailbox against GreenMail with the Japanese fixtures (SPEC.md 11).
 *
 * Every run uses a fresh folder with a Japanese name, so runs do not affect each other.
 * Skipped when the server is not running, unless IMAP_TEST_REQUIRED=1 (set in CI).
 */
#[CoversClass(ImapMailbox::class)]
#[CoversClass(ResponseParser::class)]
#[CoversClass(BodyPart::class)]
final class ImapMailboxTest extends TestCase
{
    private const FIXTURES = ['iso-2022-jp', 'shift_jis', 'html-only', 'attachment', 'euc-jp', 'reply'];

    private static string $folder = '';
    /** @var array<string, int> fixture name => UID */
    private static array $uids = [];

    private ImapMailbox $mailbox;

    public static function setUpBeforeClass(): void
    {
        $imap = self::admin();
        if ($imap === null) {
            return;
        }
        self::$folder = 'テスト受信箱-' . bin2hex(random_bytes(3));
        $raw = ImapMailbox::encodeName(self::$folder);
        $imap->createFolder($raw)->validatedData();
        foreach (self::FIXTURES as $i => $name) {
            $eml = (string) file_get_contents(dirname(__DIR__) . "/fixtures/mail/$name.eml");
            $imap->appendMessage($raw, $eml)->validatedData();
            self::$uids[$name] = $i + 1; // a new folder starts at UID 1
        }
        $imap->logout();
    }

    protected function setUp(): void
    {
        if (self::$folder === '') {
            $message = 'Test mail server is not reachable. Run: docker compose up -d';
            if (getenv('IMAP_TEST_REQUIRED') === '1') {
                self::fail($message);
            }
            self::markTestSkipped($message);
        }
        $this->mailbox = new ImapMailbox(self::account(), 5, false);
    }

    protected function tearDown(): void
    {
        if (isset($this->mailbox)) {
            $this->mailbox->close();
        }
    }

    public function testListFoldersDecodesJapaneseNamesAndCounts(): void
    {
        $folders = $this->mailbox->listFolders();
        $byName = [];
        foreach ($folders as $folder) {
            $byName[$folder->name] = $folder;
        }

        self::assertSame('INBOX', $folders[0]->name);
        self::assertSame('inbox', $folders[0]->specialUse);
        self::assertArrayHasKey(self::$folder, $byName);
        self::assertSame(count(self::FIXTURES), $byName[self::$folder]->total);
        self::assertSame(count(self::FIXTURES), $byName[self::$folder]->unread);
        self::assertTrue($byName[self::$folder]->selectable);
    }

    public function testSearchWithJapaneseTermsAcrossCharsets(): void
    {
        $subject = $this->mailbox->search(self::$folder, new SearchCriteria(subject: '見積書'));
        self::assertSame([self::$uids['attachment']], $subject->uids);
        self::assertGreaterThan(0, $subject->uidValidity);

        // The ISO-2022-JP original and the UTF-8 reply both contain the phrase.
        $text = $this->mailbox->search(self::$folder, new SearchCriteria(text: '打ち合わせ'));
        self::assertSame([self::$uids['iso-2022-jp'], self::$uids['reply']], $text->uids);

        $from = $this->mailbox->search(self::$folder, new SearchCriteria(from: 'billing@example.com'));
        self::assertSame([self::$uids['shift_jis']], $from->uids);

        $all = $this->mailbox->search(self::$folder, new SearchCriteria());
        self::assertCount(count(self::FIXTURES), $all->uids);
    }

    public function testSummariesDecodeHeadersAndSnippets(): void
    {
        $uids = array_values(self::$uids);
        $summaries = $this->mailbox->summaries(self::$folder, $uids, 200, Deadline::in(25));
        $byUid = [];
        foreach ($summaries as $summary) {
            $byUid[$summary->uid] = $summary;
        }
        self::assertSame($uids, array_map(static fn (MessageSummary $s): int => $s->uid, $summaries));

        $iso = $byUid[self::$uids['iso-2022-jp']];
        self::assertSame('打ち合わせの日程について', $iso->subject);
        self::assertSame('佐藤 花子 <hanako@example.com>', $iso->from[0]->toString());
        self::assertSame('山田 太郎 <test@example.com>', $iso->to[0]->toString());
        self::assertStringStartsWith('山田様 いつもお世話になっております。', $iso->snippet);
        self::assertSame('2026-11-09T10:15:00+09:00', $iso->date?->format(DATE_ATOM));
        self::assertFalse($iso->seen);
        self::assertFalse($iso->hasAttachments);

        $sjis = $byUid[self::$uids['shift_jis']];
        self::assertSame('【請求書】①11月分～', $sjis->subject);
        self::assertStringContainsString('①ご確認ください～', $sjis->snippet);

        self::assertStringStartsWith('こんにちは 世界 価格は<100円> & 送料無料', $byUid[self::$uids['html-only']]->snippet);
        self::assertSame('日本語の本文です。', $byUid[self::$uids['euc-jp']]->snippet);

        $attachment = $byUid[self::$uids['attachment']];
        self::assertTrue($attachment->hasAttachments);
        self::assertSame('見積書を添付します。 ご確認をお願いします。', $attachment->snippet);
    }

    public function testSnippetLengthIsLimited(): void
    {
        $summary = $this->mailbox->summaries(self::$folder, [self::$uids['iso-2022-jp']], 5, Deadline::in(25))[0];

        self::assertSame('山田様 い', $summary->snippet);
    }

    public function testIso2022JpMessageReadsWithoutMojibake(): void
    {
        $message = $this->message('iso-2022-jp');

        self::assertSame('打ち合わせの日程について', $message->subject);
        self::assertSame("山田様\n\nいつもお世話になっております。\n来週の打ち合わせの件でご連絡しました。\n\n佐藤", $message->body);
        self::assertSame(MessageDetail::FORMAT_TEXT, $message->bodyFormat);
        self::assertFalse($message->bodyTruncated);
        self::assertSame('iso2022jp-1@example.com', $message->messageId);
    }

    public function testHtmlOnlyMessageIsConverted(): void
    {
        $message = $this->message('html-only');

        self::assertSame(MessageDetail::FORMAT_HTML, $message->bodyFormat);
        self::assertStringContainsString("価格は<100円> & 送料無料\n- 一つ目\n- 二つ目\nリンク (https://example.com/)", $message->body);
        self::assertStringNotContainsString('alert', $message->body);
        self::assertStringNotContainsString('color', $message->body);
    }

    public function testAttachmentsAreListedButNotDownloaded(): void
    {
        $message = $this->message('attachment');

        self::assertSame("見積書を添付します。\nご確認をお願いします。", $message->body, 'the plain alternative is used');
        self::assertSame(['test@example.com', '経理 <accounting@example.com>'], array_map(
            static fn ($a): string => $a->toString(),
            $message->to,
        ));
        self::assertSame('boss@example.com', $message->cc[0]->email);
        self::assertCount(2, $message->attachments);
        self::assertSame('見積書.pdf', $message->attachments[0]->filename);
        self::assertSame('application/pdf', $message->attachments[0]->contentType);
        self::assertGreaterThan(4000, $message->attachments[0]->size);
        self::assertSame('meeting notes.txt', $message->attachments[1]->filename);
        self::assertStringNotContainsString('attached notes', $message->body, 'text attachments are not body text');
    }

    public function testThreadingHeadersOfAReply(): void
    {
        $message = $this->message('reply');

        self::assertSame('reply-1@example.com', $message->messageId);
        self::assertSame('iso2022jp-1@example.com', $message->inReplyTo);
        self::assertSame(['root-0@example.com', 'iso2022jp-1@example.com'], $message->references);
    }

    public function testBodyIsTruncatedAtMaxChars(): void
    {
        $message = $this->mailbox->message(self::$folder, self::$uids['iso-2022-jp'], 3, false, null);

        self::assertNotNull($message);
        self::assertSame('山田様', $message->body);
        self::assertTrue($message->bodyTruncated);
    }

    public function testReadingDoesNotMarkAsSeenUnlessAsked(): void
    {
        $uid = self::$uids['euc-jp'];
        $this->mailbox->message(self::$folder, $uid, 100, false, null);
        self::assertFalse($this->summary($uid)->seen);

        $message = $this->mailbox->message(self::$folder, $uid, 100, true, null);
        self::assertNotNull($message);
        self::assertTrue($message->seen);
        self::assertTrue($this->summary($uid)->seen);
    }

    public function testUnknownUidIsNull(): void
    {
        self::assertNull($this->mailbox->message(self::$folder, 9999, 100, false, null));
    }

    public function testChangedUidValidityIsDetected(): void
    {
        $current = $this->mailbox->search(self::$folder, new SearchCriteria())->uidValidity;

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('have changed');

        $this->mailbox->message(self::$folder, 1, 100, false, $current + 1);
    }

    public function testUnknownFolder(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage("Folder '存在しないフォルダ' does not exist");

        $this->mailbox->search('存在しないフォルダ', new SearchCriteria());
    }

    public function testWrongPasswordIsALoginError(): void
    {
        $account = new Account('default', 'test@example.com', 'wrong-password', self::host(), self::port(), 'ssl');
        $mailbox = new ImapMailbox($account, 5, false);
        $logged = ini_set('error_log', '/dev/null');

        try {
            $mailbox->listFolders();
            self::fail('Expected MailException');
        } catch (MailException $e) {
            self::assertStringContainsString('rejected the login', $e->getMessage());
            self::assertStringNotContainsString('wrong-password', $e->getMessage());
        } finally {
            ini_set('error_log', (string) $logged);
        }
    }

    public function testSelfSignedCertificateIsRejectedByDefault(): void
    {
        $mailbox = new ImapMailbox(self::account(), 5); // verification on, as in production
        $logged = ini_set('error_log', '/dev/null');

        try {
            $mailbox->listFolders();
            self::fail('Expected MailException');
        } catch (MailException $e) {
            self::assertStringContainsString('Could not connect', $e->getMessage());
        } finally {
            ini_set('error_log', (string) $logged);
        }
    }

    private function message(string $fixture): MessageDetail
    {
        $message = $this->mailbox->message(self::$folder, self::$uids[$fixture], 20000, false, null);
        self::assertNotNull($message);

        return $message;
    }

    private function summary(int $uid): MessageSummary
    {
        $mailbox = new ImapMailbox(self::account(), 5, false);
        try {
            return $mailbox->summaries(self::$folder, [$uid], 0, Deadline::in(25))[0];
        } finally {
            $mailbox->close();
        }
    }

    private static function account(): Account
    {
        return new Account('default', 'test@example.com', 'test-password', self::host(), self::port(), 'ssl');
    }

    /**
     * A raw connection for setting up fixtures. The test server's certificate is self-signed.
     */
    private static function admin(): ?ImapProtocol
    {
        try {
            $imap = new ImapProtocol(WebklexConfig::make([]), false, 'ssl');
            $imap->setConnectionTimeout(5);
            $imap->connect(self::host(), self::port());
            $imap->login('test@example.com', 'test-password')->validatedData();

            return $imap;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function host(): string
    {
        $host = getenv('IMAP_TEST_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    private static function port(): int
    {
        $port = getenv('IMAP_TEST_PORT');

        return is_string($port) && ctype_digit($port) ? (int) $port : 3993;
    }
}

<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Tools;

use Cartwright\ImapMcp\Auth\Account;
use Cartwright\ImapMcp\Mail\Address;
use Cartwright\ImapMcp\Mail\FolderInfo;
use Cartwright\ImapMcp\Mail\MailException;
use Cartwright\ImapMcp\Mail\MessageDetail;
use Cartwright\ImapMcp\Mail\Mime\DraftBuilder;
use Cartwright\ImapMcp\Mail\Mime\HeaderBlock;
use Cartwright\ImapMcp\Policy\MailPolicy;
use Cartwright\ImapMcp\Tests\Support\FakeMailbox;
use Cartwright\ImapMcp\Tests\Support\MailFixtures;
use Cartwright\ImapMcp\Tools\Arguments;
use Cartwright\ImapMcp\Tools\InvalidToolArguments;
use Cartwright\ImapMcp\Tools\MailOutput;
use Cartwright\ImapMcp\Tools\SaveDraftTool;
use Cartwright\ImapMcp\Tools\ToolResult;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaveDraftTool::class)]
#[CoversClass(Arguments::class)]
#[CoversClass(DraftBuilder::class)]
#[CoversClass(MailOutput::class)]
#[CoversClass(ToolResult::class)]
final class SaveDraftToolTest extends TestCase
{
    private FakeMailbox $mailbox;

    protected function setUp(): void
    {
        $this->mailbox = new FakeMailbox();
        $this->mailbox->uidValidity['Drafts'] = 300;
        $this->mailbox->folders = [
            new FolderInfo('INBOX', '.', 'inbox', true),
            new FolderInfo('Drafts', '.', 'drafts', true),
        ];
    }

    public function testNewDraftIsSavedToTheDetectedDraftsFolder(): void
    {
        $out = $this->structured($this->tool()->call([
            'to' => ['佐藤 花子 <hanako@example.com>'],
            'cc' => ['boss@example.com'],
            'subject' => '来週の打ち合わせ',
            'body' => "佐藤様\n\n来週の打ち合わせの件です。",
        ]));

        self::assertSame('Drafts', $out['folder']);
        self::assertSame(42, $out['uid']);
        self::assertSame('山田 太郎 <test@example.com>', $out['from']);
        self::assertSame(['佐藤 花子 <hanako@example.com>'], $out['to']);
        self::assertSame(['boss@example.com'], $out['cc']);
        self::assertNull($out['in_reply_to']);
        self::assertStringContainsString('Nothing was sent', $out['note']);

        $appended = $this->mailbox->appended[0];
        self::assertSame('Drafts', $appended['folder']);
        self::assertSame($out['message_id'], $appended['messageId']);
        $headers = new HeaderBlock(explode("\r\n\r\n", $appended['message'], 2)[0]);
        self::assertSame('来週の打ち合わせ', $headers->text('Subject'));
    }

    public function testConfiguredDraftsFolderWins(): void
    {
        $this->mailbox->uidValidity['INBOX.Drafts'] = 301;

        $out = $this->structured($this->tool('INBOX.Drafts')->call(['to' => ['a@example.com'], 'body' => 'x']));

        self::assertSame('INBOX.Drafts', $out['folder']);
    }

    public function testReplyIsThreadedAndDefaultsToTheSenderAndRe(): void
    {
        $this->mailbox->details['INBOX'][7] = MailFixtures::detail(7);

        $out = $this->structured($this->tool()->call([
            'in_reply_to_uid' => 7,
            'in_reply_to_uidvalidity' => 100,
            'body' => '承知しました。',
        ]));

        self::assertSame(['佐藤 花子 <hanako@example.com>'], $out['to']);
        self::assertSame('Re: 打ち合わせの日程について', $out['subject']);
        self::assertSame('msg-1@example.com', $out['in_reply_to']);
        self::assertSame(['root-0@example.com', 'msg-1@example.com'], $out['references']);
        self::assertSame(1, $this->mailbox->messageCalls[0]['maxChars'], 'only the headers are needed');
        self::assertFalse($this->mailbox->messageCalls[0]['markSeen']);
        self::assertSame(100, $this->mailbox->messageCalls[0]['uidValidity']);

        $headers = new HeaderBlock(explode("\r\n\r\n", $this->mailbox->appended[0]['message'], 2)[0]);
        self::assertSame(['msg-1@example.com'], $headers->messageIds('In-Reply-To'));
        self::assertSame(['root-0@example.com', 'msg-1@example.com'], $headers->messageIds('References'));
    }

    public function testReplyPrefersReplyToAndDoesNotDoubleRe(): void
    {
        $original = MailFixtures::detail(7);
        $this->mailbox->details['INBOX'][7] = new MessageDetail(
            7,
            100,
            $original->date,
            $original->from,
            $original->to,
            [],
            [new Address('support@example.com', 'サポート')],
            'Re：お問い合わせ',
            null,
            null,
            [],
            true,
            false,
            '',
            MessageDetail::FORMAT_TEXT,
            false,
            [],
        );

        $out = $this->structured($this->tool()->call(['in_reply_to_uid' => 7, 'subject' => null, 'body' => 'x']));

        self::assertSame(['サポート <support@example.com>'], $out['to']);
        self::assertSame('Re：お問い合わせ', $out['subject']);
        self::assertNull($out['in_reply_to'], 'no Message-ID in the original');
        self::assertSame([], $out['references']);
    }

    public function testExplicitRecipientsAndSubjectOverrideTheReplyDefaults(): void
    {
        $this->mailbox->details['INBOX'][7] = MailFixtures::detail(7);

        $out = $this->structured($this->tool()->call([
            'in_reply_to_uid' => 7,
            'to' => ['other@example.com'],
            'subject' => '別件',
            'body' => 'x',
        ]));

        self::assertSame(['other@example.com'], $out['to']);
        self::assertSame('別件', $out['subject']);
        self::assertSame('msg-1@example.com', $out['in_reply_to']);
    }

    public function testMissingOriginalIsAToolError(): void
    {
        $result = $this->tool()->call(['in_reply_to_uid' => 99, 'body' => 'x']);

        self::assertTrue($result->isError);
        self::assertSame([], $this->mailbox->appended);
    }

    public function testReplyFromAFolderOutsideThePolicyIsRefused(): void
    {
        $result = $this->tool(null, new MailPolicy(['INBOX']))->call([
            'in_reply_to_uid' => 1,
            'in_reply_to_folder' => 'Private',
            'body' => 'x',
        ]);

        self::assertTrue($result->isError);
        self::assertSame([], $this->mailbox->messageCalls);
    }

    public function testNoDraftsFolderIsAToolError(): void
    {
        $this->mailbox->folders = [new FolderInfo('INBOX', '.', 'inbox', true)];

        $result = $this->tool()->call(['to' => ['a@example.com'], 'body' => 'x']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('folders.drafts', $result->text);
    }

    public function testServerWithoutUidReportStillSucceeds(): void
    {
        $this->mailbox->nextUid = null;

        self::assertNull($this->structured($this->tool()->call(['to' => ['a@example.com'], 'body' => 'x']))['uid']);
    }

    public function testMailErrorIsAToolError(): void
    {
        $this->mailbox->failWith = MailException::unavailable();

        self::assertTrue($this->tool()->call(['to' => ['a@example.com'], 'body' => 'x'])->isError);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no body' => [['to' => ['a@example.com']], "'body'"];
        yield 'no recipient and no reply' => [['body' => 'x'], "'to' needs at least one recipient"];
        yield 'invalid address' => [
            ['to' => ['not an address'], 'body' => 'x'],
            "'to[0]' is not a valid email address",
        ];
        yield 'header injection in address' => [
            ['to' => ["a@example.com\r\nBcc: evil@example.com"], 'body' => 'x'],
            'control characters',
        ];
        yield 'header injection in subject' => [
            ['to' => ['a@example.com'], 'subject' => "x\r\nBcc: evil@example.com", 'body' => 'x'],
            'control characters',
        ];
        yield 'to as a string' => [['to' => 'a@example.com', 'body' => 'x'], "'to' must be a list"];
        yield 'too many recipients' => [
            ['to' => array_fill(0, 30, 'a@example.com'), 'cc' => array_fill(0, 21, 'b@example.com'), 'body' => 'x'],
            'At most 50 recipients',
        ];
        yield 'body too long' => [
            ['to' => ['a@example.com'], 'body' => str_repeat('あ', 100_001)],
            "'body' must be at most",
        ];
        yield 'control character in body' => [['to' => ['a@example.com'], 'body' => "x\x1b[31m"], 'control characters'];
        yield 'bcc is not supported' => [
            ['to' => ['a@example.com'], 'bcc' => ['b@example.com'], 'body' => 'x'],
            'Unknown argument: bcc',
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalid')]
    public function testInvalidArguments(array $arguments, string $message): void
    {
        $this->expectException(InvalidToolArguments::class);
        $this->expectExceptionMessage($message);

        $this->tool()->call($arguments);
    }

    public function testBodyMayContainNewlinesAndTabs(): void
    {
        self::assertFalse($this->tool()->call(['to' => ['a@example.com'], 'body' => "a\r\n\tb"])->isError);
    }

    public function testAuditRecordsRecipientsAndSubjectButNotTheBody(): void
    {
        $audit = $this->tool()->auditArguments([
            'to' => ['a@example.com'],
            'subject' => '件名',
            'body' => '秘密の本文',
            'in_reply_to_uid' => 7,
        ]);

        self::assertSame(
            ['to' => ['a@example.com'], 'cc' => [], 'subject' => '件名', 'body_chars' => 5, 'in_reply_to_uid' => 7],
            $audit,
        );
        self::assertStringNotContainsString('秘密', serialize($audit));
    }

    public function testDefinitionSaysNothingIsSent(): void
    {
        $definition = $this->tool()->definition();

        self::assertStringContainsString('NOT sent', $definition['description']);
        self::assertFalse($definition['annotations']['readOnlyHint']);
        self::assertFalse($definition['annotations']['destructiveHint']);
        self::assertArrayNotHasKey('bcc', $definition['inputSchema']['properties']);
    }

    private function tool(?string $draftsFolder = null, ?MailPolicy $policy = null): SaveDraftTool
    {
        return new SaveDraftTool(
            $this->mailbox,
            new Account('default', 'test@example.com', 'x', 'imap.example.com', 993, 'ssl', '山田 太郎', $draftsFolder),
            $policy ?? new MailPolicy(),
            new DateTimeZone('Asia/Tokyo'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function structured(ToolResult $result): array
    {
        self::assertFalse($result->isError, $result->text);
        self::assertNotNull($result->structured);

        return $result->structured;
    }
}

<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Mime;

use Cartwright\ImapMcp\Mail\Imap\ResponseParser;
use Cartwright\ImapMcp\Mail\Mime\BodyPart;
use Cartwright\ImapMcp\Mail\Mime\HeaderDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * BODYSTRUCTURE strings as returned by GreenMail for tests/fixtures/mail.
 */
#[CoversClass(BodyPart::class)]
#[CoversClass(HeaderDecoder::class)]
final class BodyPartTest extends TestCase
{
    private const ATTACHMENT = '((("text" "plain" ("charset" "UTF-8") NIL NIL "base64" 168 4 NIL NIL NIL)'
        . '("text" "html" ("charset" "UTF-8") NIL NIL "base64" 129 3 NIL NIL NIL) '
        . '"alternative" ("boundary" "inner") NIL NIL)'
        . '("APPLICATION" "PDF" ("name" "=?UTF-8?B?6KaL56mN5pu4LnBkZg==?=") NIL NIL "base64" 4296 NIL '
        . '("attachment" ("filename*" "UTF-8\'\'%E8%A6%8B%E7%A9%8D%E6%9B%B8.pdf")) NIL)'
        . '("text" "plain" ("charset" "UTF-8" "name" "meeting notes.txt") NIL NIL "7bit" 179 4 NIL '
        . '("attachment" ("filename" "meeting notes.txt")) NIL) "mixed" ("boundary" "outer") NIL NIL)';

    public function testMultipartWithAttachments(): void
    {
        $root = $this->structure(self::ATTACHMENT);

        self::assertTrue($root->isMultipart());
        $text = $root->textParts();
        self::assertCount(1, $text);
        self::assertSame('1.1', $text[0]->section);
        self::assertSame('text/plain', $text[0]->mimeType());
        self::assertSame('base64', $text[0]->encoding);
        self::assertSame('UTF-8', $text[0]->charset());

        $attachments = $root->attachments();
        self::assertCount(2, $attachments);
        self::assertSame(['2', 'application/pdf', '見積書.pdf', 4296], [
            $attachments[0]->section,
            $attachments[0]->mimeType(),
            $attachments[0]->filename(),
            $attachments[0]->size,
        ]);
        self::assertSame('meeting notes.txt', $attachments[1]->filename());
    }

    public function testSinglePartUsesTheTextSection(): void
    {
        $root = $this->structure('("text" "plain" ("charset" "ISO-2022-JP") NIL NIL "7bit" 508 15 NIL NIL NIL)');

        self::assertSame('TEXT', $root->textParts()[0]->section);
        self::assertSame([], $root->attachments());
    }

    public function testHtmlOnlyAlternativeFallsBackToHtml(): void
    {
        $root = $this->structure(
            '(("text" "html" ("charset" "UTF-8") NIL NIL "quoted-printable" 10 1 NIL NIL NIL)'
            . '("image" "png" ("name" "logo.png") "<logo>" NIL "base64" 100 NIL '
            . '("inline" ("filename" "logo.png")) NIL) "related")',
        );

        self::assertSame(['1'], array_map(static fn (BodyPart $p): string => $p->section, $root->textParts()));
        self::assertSame('logo.png', $root->attachments()[0]->filename());
    }

    public function testRfc2231ContinuationsAndForwardedMessage(): void
    {
        $root = $this->structure(
            '(("text" "plain" NIL NIL NIL "7bit" 10 1 NIL NIL NIL)'
            . '("application" "octet-stream" NIL NIL NIL "base64" 20 NIL '
            . '("attachment" ("filename*0*" "UTF-8\'\'%E8%A6%8B" "filename*1*" "%E7%A9%8D.txt")) NIL)'
            . '("message" "rfc822" NIL NIL NIL "7bit" 30 (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL) '
            . '("text" "plain" NIL NIL NIL "7bit" 5 1 NIL NIL NIL) 3 NIL NIL NIL) "mixed")',
        );

        $attachments = $root->attachments();
        self::assertSame('見積.txt', $attachments[0]->filename());
        self::assertSame('message/rfc822', $attachments[1]->mimeType());
        self::assertCount(1, $root->textParts(), 'the forwarded message is an attachment, not body text');
    }

    private function structure(string $bodyStructure): BodyPart
    {
        $tokens = ResponseParser::parse($bodyStructure);
        self::assertIsArray($tokens[0]);

        return BodyPart::fromBodyStructure($tokens[0]);
    }
}

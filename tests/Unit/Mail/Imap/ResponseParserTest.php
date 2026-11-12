<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Imap;

use Cartwright\ImapMcp\Mail\Imap\ResponseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseParser::class)]
final class ResponseParserTest extends TestCase
{
    public function testFetchWithQuotedValuesBeforeParenthesesAndNil(): void
    {
        $tokens = ResponseParser::parse(
            '1 FETCH (FLAGS (\Seen \Flagged) RFC822.SIZE 179 BODYSTRUCTURE ("text" "plain" ("charset" "UTF-8" '
            . '"name" "meeting notes.txt") NIL NIL "7bit" 179 4 NIL '
            . '("attachment" ("filename" "meeting notes.txt")) NIL) UID 7)',
        );

        self::assertSame('1', $tokens[0]);
        self::assertSame('FETCH', $tokens[1]);
        self::assertIsArray($tokens[2]);
        $data = ResponseParser::pairs($tokens[2]);
        self::assertSame(['\Seen', '\Flagged'], $data['FLAGS']);
        self::assertSame('7', $data['UID']);
        self::assertSame(
            ['text', 'plain', ['charset', 'UTF-8', 'name', 'meeting notes.txt'], null, null, '7bit', '179', '4', null,
                ['attachment', ['filename', 'meeting notes.txt']], null],
            $data['BODYSTRUCTURE'],
        );
    }

    public function testLiteralsKeepTheirBytesExactly(): void
    {
        $header = "Subject: (not a list) \"quoted\"\r\nFrom: a@example.com\r\n\r\n";
        $line = '2 FETCH (UID 9 BODY[HEADER.FIELDS (SUBJECT FROM)] {' . strlen($header) . "}\r\n" . $header . ")\r\n";

        $data = ResponseParser::pairs(ResponseParser::parse($line)[2]);

        self::assertSame($header, $data['BODY[HEADER.FIELDS (SUBJECT FROM)]']);
        self::assertSame('9', $data['UID']);
    }

    public function testPartialBodySectionIsOneAtom(): void
    {
        $data = ResponseParser::pairs(ResponseParser::parse("3 FETCH (UID 1 BODY[1.2]<0> {3}\r\nabc)")[2]);

        self::assertSame('abc', $data['BODY[1.2]<0>']);
    }

    public function testLiteralWithoutASpaceBeforeIt(): void
    {
        // GreenMail sends partial bodies like this.
        $data = ResponseParser::pairs(ResponseParser::parse("1 FETCH (UID 1 BODY[TEXT]<0>{3}\r\nabc)")[2]);

        self::assertSame('abc', $data['BODY[TEXT]<0>']);
    }

    public function testEscapesInQuotedStrings(): void
    {
        self::assertSame(['a "b" \\c'], ResponseParser::parse('"a \"b\" \\\\c"'));
    }

    public function testNestedEmptyLists(): void
    {
        self::assertSame(['FLAGS', []], ResponseParser::parse('FLAGS ()'));
    }
}

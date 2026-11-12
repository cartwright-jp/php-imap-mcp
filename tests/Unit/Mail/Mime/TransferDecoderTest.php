<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Mime;

use Cartwright\ImapMcp\Mail\Mime\TransferDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransferDecoder::class)]
final class TransferDecoderTest extends TestCase
{
    public function testBase64WithLineBreaks(): void
    {
        self::assertSame('日本語の本文', TransferDecoder::decode(chunk_split(base64_encode('日本語の本文'), 8, "\r\n"), 'BASE64'));
    }

    public function testTruncatedBase64DecodesTheCompleteGroups(): void
    {
        $encoded = base64_encode('abcdefgh');

        self::assertSame('abcdef', TransferDecoder::decode(substr($encoded, 0, 10), 'base64', true));
    }

    public function testQuotedPrintable(): void
    {
        self::assertSame("日本\nnext", TransferDecoder::decode("=E6=97=A5=E6=9C=\r\n=AC\nnext", 'quoted-printable'));
    }

    public function testTruncatedQuotedPrintableDropsAHalfEscape(): void
    {
        self::assertSame('ab', TransferDecoder::decode('ab=E', 'quoted-printable', true));
    }

    public function testOtherEncodingsAreUnchanged(): void
    {
        self::assertSame("raw\r\n", TransferDecoder::decode("raw\r\n", '8bit'));
        self::assertSame('raw', TransferDecoder::decode('raw', null));
    }
}

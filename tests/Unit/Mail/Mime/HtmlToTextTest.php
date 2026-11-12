<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Tests\Unit\Mail\Mime;

use Cartwright\ImapMcp\Mail\Mime\HtmlToText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlToText::class)]
final class HtmlToTextTest extends TestCase
{
    public function testConvertsStructureAndEntities(): void
    {
        $html = '<html><head><title>T</title><style>p{color:red}</style><script>alert(1)</script></head>'
            . '<body><p>こんにちは&nbsp;世界</p><p>価格は&lt;100円&gt; &amp; 送料無料</p>'
            . '<ul><li>一つ目</li><li>二つ目</li></ul><!-- hidden -->以上<br>終わり</body></html>';

        self::assertSame(
            "こんにちは 世界\n価格は<100円> & 送料無料\n- 一つ目\n- 二つ目\n以上\n終わり",
            HtmlToText::convert($html),
        );
    }

    public function testLinksKeepTheirUrl(): void
    {
        self::assertSame(
            'こちら (https://example.com/login) / https://example.com/ / info@example.com',
            HtmlToText::convert(
                '<a href="https://example.com/login">こちら</a>'
                . ' / <a href=\'https://example.com/\'>https://example.com/</a>'
                . ' / <a href="mailto:info@example.com">info@example.com</a>',
            ),
        );
    }

    public function testUnclosedScriptIsDropped(): void
    {
        self::assertSame('本文', HtmlToText::convert('本文<script>evil()'));
    }

    public function testDeclaredCharset(): void
    {
        self::assertSame(
            'Shift_JIS',
            HtmlToText::declaredCharset('<meta http-equiv="Content-Type" content="text/html; charset=Shift_JIS">'),
        );
        self::assertSame('utf-8', HtmlToText::declaredCharset('<meta charset="utf-8">'));
        self::assertNull(HtmlToText::declaredCharset('<p>none</p>'));
    }
}

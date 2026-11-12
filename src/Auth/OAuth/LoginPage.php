<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Auth\OAuth;

use Cartwright\ImapMcp\Http\Response;

/**
 * HTML of the login page and of authorization errors (SPEC.md 7). Every value is escaped; no scripts,
 * no external resources, and the page cannot be framed.
 */
final class LoginPage
{
    /**
     * @param string $redirectOrigin where the browser goes after login (allowed by form-action)
     */
    public static function form(
        string $clientName,
        string $redirectHost,
        string $redirectOrigin,
        string $request,
        string $email = '',
        ?string $error = null,
        int $status = 200,
    ): Response {
        $body = sprintf(
            '<h1>メールアカウントでログイン</h1>'
            . '<p><strong>%s</strong> がこのメールボックスへのアクセスを求めています。'
            . 'ログインすると <strong>%s</strong> に戻ります。</p>'
            . '%s'
            . '<form method="post" autocomplete="on">'
            . '<input type="hidden" name="request" value="%s">'
            . '<label>メールアドレス<input type="email" name="email" value="%s" required autocomplete="username"></label>'
            . '<label>パスワード<input type="password" name="password" required autocomplete="current-password"></label>'
            . '<button type="submit">ログイン</button>'
            . '</form>'
            . '<p class="note">パスワードはこのサーバーとメールサーバーの間でだけ使われ、%s には渡されません。'
            . 'このサーバーからメールが送信されることはありません。</p>',
            self::e($clientName),
            self::e($redirectHost),
            $error === null ? '' : '<p class="error" role="alert">' . self::e($error) . '</p>',
            self::e($request),
            self::e($email),
            self::e($clientName),
        );

        return Response::html($status, self::page('ログイン', $body), self::headers($redirectOrigin));
    }

    public static function error(string $message, int $status = 400): Response
    {
        $body = '<h1>ログインを続けられません</h1><p class="error">' . self::e($message) . '</p>'
            . '<p>接続元のアプリに戻り、最初からやり直してください。</p>';

        return Response::html($status, self::page('エラー', $body), self::headers(null));
    }

    /**
     * @return array<string, string>
     */
    private static function headers(?string $redirectOrigin): array
    {
        // form-action also governs the redirect after the form is posted, so the client's origin is allowed.
        $formAction = "'self'" . ($redirectOrigin === null ? '' : ' ' . $redirectOrigin);

        return [
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action "
                . $formAction . "; frame-ancestors 'none'; base-uri 'none'",
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];
    }

    private static function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . self::e($title) . ' - php-imap-mcp</title><style>'
            . 'body{font-family:system-ui,sans-serif;max-width:28rem;margin:2rem auto;padding:0 1rem;line-height:1.6;'
            . 'color:#1f2328;background:#fff}h1{font-size:1.3rem}label{display:block;margin:1rem 0}'
            . 'input{display:block;width:100%;box-sizing:border-box;padding:.6rem;font-size:1rem;margin-top:.3rem;'
            . 'border:1px solid #8c959f;border-radius:6px}button{width:100%;padding:.7rem;font-size:1rem;border:0;'
            . 'border-radius:6px;background:#1f6feb;color:#fff}.error{color:#cf222e}'
            . '.note{font-size:.85rem;color:#57606a}'
            . '@media (prefers-color-scheme:dark){body{color:#e6edf3;background:#0d1117}'
            . 'input{background:#161b22;color:#e6edf3;border-color:#30363d}.note{color:#8b949e}.error{color:#ff7b72}}'
            . '</style></head><body>' . $body . '</body></html>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}

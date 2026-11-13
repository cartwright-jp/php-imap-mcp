<?php

declare(strict_types=1);

namespace Cartwright\ImapMcp\Http;

/**
 * Minimal HTTP request: method, case-insensitive headers and raw body.
 */
final class Request
{
    /** @var array<string, string> header name (lowercase) => value */
    private array $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        array $headers,
        public readonly string $body,
        public readonly bool $bodyTooLarge = false,
        /** REMOTE_ADDR. X-Forwarded-For is ignored because any client can set it. */
        public readonly string $remoteAddr = '',
        /** URL path without the query string, e.g. /mcp/authorize. */
        public readonly string $path = '/',
        /** Raw query string without '?'. */
        public readonly string $queryString = '',
        /** Arrived over HTTPS. fromGlobals() reads it from the web server; constructed requests default to true. */
        public readonly bool $secure = true,
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    /**
     * Builds the request from PHP globals. Works under mod_php, CGI and FastCGI.
     *
     * @param positive-int $maxBodyBytes
     */
    public static function fromGlobals(int $maxBodyBytes): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }
        // Some hosts (e.g. XServer with CGI) pass Authorization only via .htaccess SetEnvIf / RewriteRule.
        if (!isset($headers['AUTHORIZATION'])) {
            foreach (['REDIRECT_HTTP_AUTHORIZATION', 'HTTP_AUTHORIZATION'] as $key) {
                $value = $_SERVER[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $headers['AUTHORIZATION'] = $value;
                    break;
                }
            }
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $body = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        $body = $body === false ? '' : $body;
        $tooLarge = strlen($body) > $maxBodyBytes;
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = is_string($uri) ? $uri : '/';
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');

        return new self(
            is_string($method) ? strtoupper($method) : 'GET',
            $headers,
            $tooLarge ? '' : $body,
            $tooLarge,
            is_string($remoteAddr) ? $remoteAddr : '',
            $path === '' ? '/' : $path,
            $query,
            self::isHttps($_SERVER),
        );
    }

    /**
     * Whether the web server received the request over TLS. Only the server's own variables count: forwarded
     * headers (X-Forwarded-Proto and the like) can be sent by any client. On XServer the front nginx sets HTTPS
     * and overwrites X-Forwarded-Proto.
     *
     * @param array<mixed> $server $_SERVER
     */
    public static function isHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return ($server['REQUEST_SCHEME'] ?? '') === 'https';
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * Query parameters. A parameter given more than once, or as an array, is reported as null so callers can
     * reject it (OAuth: parameters MUST NOT be included more than once).
     */
    public function query(string $name): ?string
    {
        return self::single($this->queryString, $name);
    }

    /**
     * application/x-www-form-urlencoded body parameters, with the same rule as query().
     */
    public function form(string $name): ?string
    {
        return self::single($this->body, $name);
    }

    /**
     * Whether the parameter appears more than once in the query string or form body.
     */
    public function isRepeated(string $name, bool $inBody = false): bool
    {
        return self::count($inBody ? $this->body : $this->queryString, $name) > 1;
    }

    public function cookie(string $name): ?string
    {
        foreach (explode(';', $this->header('Cookie') ?? '') as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2 && $parts[0] === $name) {
                return rawurldecode($parts[1]);
            }
        }

        return null;
    }

    private static function single(string $encoded, string $name): ?string
    {
        if (self::count($encoded, $name) !== 1) {
            return null;
        }
        foreach (explode('&', $encoded) as $pair) {
            $parts = explode('=', $pair, 2);
            if (urldecode($parts[0]) === $name) {
                return urldecode($parts[1] ?? '');
            }
        }

        return null;
    }

    private static function count(string $encoded, string $name): int
    {
        $count = 0;
        foreach ($encoded === '' ? [] : explode('&', $encoded) as $pair) {
            if (urldecode(explode('=', $pair, 2)[0]) === $name) {
                $count++;
            }
        }

        return $count;
    }
}

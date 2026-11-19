# Contributing

English | [日本語](CONTRIBUTING.ja.md)

Thank you for your interest in php-imap-mcp. Issues and pull requests are welcome, in English or Japanese.

**Security issues:** do not open a public issue. Follow [SECURITY.md](SECURITY.md).

## Before you start

- The specification is [docs/SPEC.md](docs/SPEC.md) (in Japanese). It is the source of truth: a feature that is not in it is discussed in an issue first, and the specification is updated before the code.
- Some changes are out of scope by design and will not be accepted:
  - sending mail (SMTP or any other way), or adding a mail-sending library
  - deleting mail
  - loosening a security default without discussion (for example enabling write tools by default, or weakening the IP restriction or the lockout)
- New runtime dependencies are discussed in an issue first. The server runs on shared hosting, so the dependency list is kept small.

For anything larger than a small fix, please open an issue first so we can agree on the approach.

## Development setup

You need PHP 8.1 or later (with the iconv, json, mbstring, openssl and sodium extensions), Composer, and Docker for the integration tests.

```sh
composer install
composer check          # code style, PHPStan and unit tests: must pass for every change
```

| Command | What it does |
| --- | --- |
| `composer cs` / `composer cs:fix` | Check / fix the code style (PSR-12, `declare(strict_types=1);` in every file) |
| `composer stan` | PHPStan level 8 with strict rules |
| `composer test` | Unit tests (`tests/Unit`) |
| `composer test:integration` | Integration tests against the test mail servers |
| `composer serve` | Development server at `http://127.0.0.1:18080/` (reads `config.php` or `PHP_IMAP_MCP_CONFIG`) |

### Integration tests

The tests run against two IMAP servers in Docker, and must pass on both:

- Courier-IMAP on `127.0.0.1:3993` (the server XServer uses, strict about the rules)
- Dovecot on `127.0.0.1:3994` (the most widely used IMAP server)

```sh
docker compose up -d --wait
bash tests/bin/wait-for-mail-server.sh
IMAP_TEST_PORT=3994 bash tests/bin/wait-for-mail-server.sh
IMAP_TEST_REQUIRED=1 composer test:integration
IMAP_TEST_REQUIRED=1 IMAP_TEST_PORT=3994 composer test:integration
docker compose down
```

The test account on both is `test@example.com` / `test-password`.

### End-to-end smoke test

```sh
dir=$(mktemp -d)
token=$(php tests/e2e/make-config.php "$dir")
PHP_IMAP_MCP_CONFIG="$dir/config.php" php -S 127.0.0.1:18080 -t public public/index.php &
MCP_TOKEN="$token" composer test:e2e
bash tests/e2e/oauth-smoke.sh
```

## Coding rules

- Compatible with PHP 8.1: no syntax or functions from 8.2 or later. CI runs PHP 8.1 to 8.4, and `composer.lock` is resolved for PHP 8.1.
- `declare(strict_types=1);` in every file, PSR-12, PHPStan level 8.
- Do not use ext-imap. IMAP goes through the low-level `ImapProtocol` of `webklex/php-imap`, and MIME is built with `symfony/mime`.
- `public/` contains only `index.php` and `.htaccess`. Code, configuration and logs stay outside the public directory.
- Never put passwords, tokens or stack traces in responses, and never log passwords, tokens or message bodies.
- Treat email content as untrusted data.
- Write tests first where you can. Mail-handling changes need fixtures; Japanese fixtures (ISO-2022-JP, Shift_JIS, HTML-only, Japanese folder names, attachments) are especially welcome.
- Changes to OAuth update the requirement table in [docs/oauth-checklist.md](docs/oauth-checklist.md). Changes to deployment (`public/`, the sample configuration, `bin/`) update [docs/deploy-xserver.md](docs/deploy-xserver.md).

## Tests and examples must not contain real data

Do not commit real credentials, real email addresses, tokens or real messages, including in tests and fixtures. Use `example.com`, `svXXXXX.xserver.jp` and `203.0.113.0/24` in examples.

## Pull requests

- Keep each pull request focused on one change, and describe what it changes and why.
- Make sure `composer check` passes. CI also runs the integration tests on both mail servers.
- Add an entry under `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md) for changes users will notice.
- If you change README, SECURITY or CONTRIBUTING, update both the English file and the Japanese `*.ja.md` file. If you can only write one language, say so in the pull request and the other will be updated.

## License

By contributing, you agree that your contributions are licensed under the [Apache License 2.0](LICENSE), as described in section 5 of the license.

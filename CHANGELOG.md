# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Changes that break existing configuration files raise the major version (from 1.0 on).

## [Unreleased]

## [0.2.0] - 2026-11-20

### Added

- `save_draft` can quote the original email below a reply: `quote: "full"` (the default `none` keeps the 0.1 behaviour). The server copies the original text with `> ` on each line under a Gmail-style Japanese header line (`2026年11月19日(木) 10:51 Name <address>:`), so the AI never rewrites it. `max_quote_chars` limits the quote (default 20000, at most `policy.body_max_chars`); a cut quote ends with `（以下省略）`. The response gains `quoted`, `quote` and `quote_truncated`; the audit log records only the method and the number of quoted characters.

## [0.1.0] - 2026-11-19

First public release.

### Added

- Remote MCP server in plain PHP for shared hosting: no daemon, one IMAP connection per request, no session state. MCP protocol version 2026-07-28, with 2025-11-25 clients supported.
- Tools: `list_folders`, `search_emails`, `get_email` (read-only) and `save_draft` (saves to the Drafts folder; replies get `In-Reply-To` and `References`). There is no way to send or delete mail.
- Japanese mail support: ISO-2022-JP, Shift_JIS (CP932 extensions), HTML-only messages converted to text, Japanese folder names (modified UTF-7). Only the text parts are fetched; attachments are listed by name, type and size.
- Sign-in with the mail account (OAuth 2.1 with PKCE, pre-registered clients): the server checks the address and password with a real IMAP login and seals them into the tokens it issues. No mail password is stored on the server.
- OAuth discovery: Protected Resource Metadata (RFC 9728), Authorization Server Metadata (RFC 8414), resource indicators (RFC 8707).
- Defences: IP allow-list (Anthropic's outbound ranges by default), Origin check, lockout after repeated failed logins or client authentication, audit log without passwords, tokens or message bodies, email content wrapped as untrusted data.
- `bin/check-config.php`, `bin/generate-key.php`, `bin/generate-client-secret.php`, `bin/revoke-grant.php`.
- Tested with Courier-IMAP and Dovecot, on PHP 8.1 to 8.4.

[Unreleased]: https://github.com/cartwright-jp/php-imap-mcp/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/cartwright-jp/php-imap-mcp/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/cartwright-jp/php-imap-mcp/releases/tag/v0.1.0

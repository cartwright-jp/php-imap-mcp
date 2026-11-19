# php-imap-mcp

English | [日本語](README.ja.md)

A remote [MCP](https://modelcontextprotocol.io/) server in plain PHP that lets Claude read, search and draft email in your own-domain mailbox over IMAP. Copy it to a shared hosting (rental server) account and it runs: no daemon, no extra server, no database.

- **Read and draft, never send.** The AI can list folders, search, read messages and save drafts. It cannot send or delete mail. A person reviews every draft and sends it from their own mail app.
- **Sign in with the mail account.** Users sign in with their mail address and password on the server's login page (OAuth). No mail password is stored on the server.
- **Built for shared hosting.** One IMAP connection per request, no session state. Works with Apache and `.htaccess`, PHP 8.1 or later, and no ext-imap.
- **Japanese mail works.** ISO-2022-JP, Shift_JIS, HTML-only messages and Japanese folder names are handled correctly.

> [!WARNING]
> This software gives an AI full read access to a mailbox, and email content can contain instructions written by anyone who sends you mail (prompt injection). Read [Security](#security) before you connect a mailbox, and consider a dedicated mailbox for the AI.

## Tools

| Tool | What it does |
| --- | --- |
| `list_folders` | Folders with their special use (inbox, sent, drafts…), total and unread counts |
| `search_emails` | Searches one folder by sender, recipient, subject, text, date or unread state; newest first, with a short preview |
| `get_email` | One message: headers, the body as text (HTML converted), attachment names and sizes (not their contents). Does not mark it as read unless asked |
| `save_draft` | Saves a plain-text draft to the Drafts folder. For a reply, sets `In-Reply-To` and `References` so it stays in the thread |

Each tool can be turned off in the configuration, and `policy.allowed_folders` limits which folders can be read.

Not supported, by design: sending mail, deleting mail, push notifications of new mail (IMAP IDLE needs a long-running process), protocols other than IMAP.

## How it works

```
Claude (web, desktop, mobile)
   │  HTTPS, from Anthropic's cloud
   ▼
your-domain/mcp/   ── PHP, one request at a time ──▶  IMAP server (TLS)
```

1. You add the server as a custom connector in claude.ai. Claude opens the server's login page.
2. You sign in with your mail address and password. The server checks them with a real IMAP login.
3. The server encrypts ("seals") the address and password into the access token it issues, with a key only the server has. It keeps no password store.
4. For each request, the server opens the token, connects to IMAP, does the work and disconnects.

## Requirements

- PHP 8.1 or later with the `iconv`, `json`, `mbstring`, `openssl` and `sodium` extensions
- Apache with `.htaccess` and `mod_rewrite`, served over HTTPS
- An IMAP server that accepts TLS (port 993, or STARTTLS)
- SSH access and Composer to install it

Tested on a Japanese shared hosting service (Apache, Courier-IMAP) with claude.ai on the web and the mobile apps. The automated tests run on PHP 8.1 to 8.4 against Courier-IMAP and Dovecot.

## Installation

A detailed, step-by-step [deployment guide](docs/deploy-xserver.md) is available in Japanese. The outline below works on similar hosts. Replace `example.com`, `imap.example.com` and the paths with your own.

1. **Install it outside the public directory.**

   ```sh
   git clone https://github.com/cartwright-jp/php-imap-mcp.git ~/php-imap-mcp
   cd ~/php-imap-mcp
   composer install --no-dev --optimize-autoloader
   ```

2. **Configure.** Copy the sample, keep it private and create the secrets:

   ```sh
   cp config.example.php config.php && chmod 600 config.php
   php bin/generate-key.php            # → auth.oauth.key
   php bin/generate-client-secret.php  # hash → auth.oauth.clients[].secret_hash; keep the secret for claude.ai
   ```

   In `config.php`, set at least `auth.oauth.resource` (the MCP URL, for example `https://example.com/mcp/`), `auth.oauth.imap.host` (for example `imap.example.com`) and `auth.oauth.allowed_email_domains`. Unknown keys and unsafe values are rejected, so a typo cannot silently turn a restriction off.

3. **Check the configuration and the IMAP login** (the password is asked for and not stored):

   ```sh
   php bin/check-config.php --login you@example.com
   ```

4. **Publish the entry point.** Copy `public/index.php` and `public/.htaccess` to the public directory (for example `public_html/mcp/`) and set `APP_ROOT` in that `index.php` to the install path. Only these two files are public.

5. **Make the OAuth metadata visible at the site root.** Add the rewrite rule from [section 5 of the guide](docs/deploy-xserver.md#5-サイト直下からメタデータを見えるようにする) to the top of the site's root `.htaccess`.

6. **Connect from claude.ai.** Settings → Connectors → Add custom connector. Enter the MCP URL, then under advanced settings the OAuth client ID (`claude` in the sample) and the client secret from step 2. Connect, and sign in on the login page that opens.

The MCP endpoint only accepts requests from `auth.allowed_ips`, which defaults to [Anthropic's outbound IP ranges](https://platform.claude.com/docs/en/api/ip-addresses). Clients that connect from your own computer, such as Claude Code, need your IP address there too. The login page is not IP-restricted, because it opens in your browser.

To update, fetch the new version and run `composer install --no-dev --optimize-autoloader` again. Copy the two `public/` files again when they change (and set `APP_ROOT` again). `config.php` and the `var/` directory are kept.

## Security

Read this before connecting a real mailbox. To report a vulnerability, see [SECURITY.md](SECURITY.md).

**Use a dedicated mailbox for the AI.** The AI can read everything in the mailbox it signs in to. Create a separate account and forward only the mail the AI should handle to it.

**Prompt injection.** Anyone who can send you an email can write instructions in it. The server marks email content as untrusted data and tells the AI not to follow it, but it cannot guarantee that the AI ignores it. What limits the damage:

- The server cannot send or delete mail. A malicious email cannot make it forward your mail anywhere.
- Drafts are only drafts. Always check the recipients and the content before you send one.
- Other connectors in the same conversation are not limited by this server. If Claude can also send messages or write to other services there, injected instructions could use those.

**What the server protects.**

- Mail passwords are not stored on the server. They are sealed in tokens with the server's key (libsodium). An attacker needs both the key and a token to recover a password.
- The login page accepts only the mail domains you list, and repeated failed logins lock out the IP address and the mail address for a while.
- The MCP endpoint is limited to the allowed IP ranges and checks the `Origin` header. IMAP is always TLS with certificate verification.
- The audit log records each tool call (time, account, tool, a summary of the arguments, result, duration). It never records passwords, tokens or message bodies. For drafts it records recipients, subject and body length.
- Sign-ins can be revoked one by one with `php bin/revoke-grant.php <grant>`, or all at once by replacing the key.

## Status

Version 0.1, used daily by the author on a shared hosting service. Planned next (0.2): changing read and flagged state, moving messages (both off by default), attachment details. Later: a downloadable zip with dependencies included, and a browser-based setup screen. See [CHANGELOG.md](CHANGELOG.md).

Known limitations:

- Drafts have the mail address as `From`, without a display name. Some mail apps (for example Outlook) send the draft with this `From` as is.
- Tested on only one shared hosting service so far. Reports from other hosts are welcome.

## Contributing

Issues and pull requests are welcome, in English or Japanese. See [CONTRIBUTING.md](CONTRIBUTING.md). The specification is [docs/SPEC.md](docs/SPEC.md) (in Japanese).

## License

[Apache License 2.0](LICENSE). Copyright 2026 Kazuki Aruga (Cartwright).

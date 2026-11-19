# Security Policy

English | [日本語](SECURITY.ja.md)

php-imap-mcp gives an AI client access to a mailbox, so security reports are welcome and taken seriously.

## Supported versions

Only the latest release receives security fixes. Before 1.0, fixes are released as a new 0.x version; there are no backports to older releases.

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub's private vulnerability reporting:
**[Report a vulnerability](https://github.com/cartwright-jp/php-imap-mcp/security/advisories/new)** (the "Security" tab of the repository).

Do not open a public issue, pull request or discussion for a vulnerability. Reports in English or Japanese are both fine.

Helpful details:

- the affected version or commit
- the steps to reproduce, or a proof of concept
- the impact you expect (what an attacker can read, write or bypass)
- your server setup, if it matters (PHP version, IMAP server, web server, relevant settings)

Do not include real passwords, access tokens or the contents of real emails in a report.

## What to expect

This project is maintained by one person, so responses are on a best-effort basis. The aim is to:

- acknowledge the report within 7 days
- agree on the severity and a fix plan with you in the private advisory
- publish a fixed release and a GitHub security advisory, crediting you unless you prefer otherwise; a CVE is requested when it applies

Please give a reasonable time to release a fix before disclosing the issue publicly.

## Scope

In scope, for example:

- bypassing authentication, the OAuth flow, the IP restrictions, the Origin checks or the lockout
- reading or decrypting a password from the sealed tokens or authorization codes without the server's key
- reading folders outside `policy.allowed_folders`, or using tools that are disabled in the configuration
- header injection in saved drafts
- leaking passwords, tokens, server paths or stack traces in responses or logs
- any way to make the server send mail (it has no sending path by design)

Known limitations, not treated as vulnerabilities:

- An attacker who steals both the server's encryption key and a token can recover the mail password in that token.
- Prompt injection through email content. The server marks email content as untrusted data, but it cannot stop the AI from being misled by it. This is why the server cannot send mail and why a person reviews every draft before sending it. Using a dedicated mailbox for the AI is recommended.
- Weaknesses of the hosting environment itself (the web server, PHP or the IMAP server), or a setup that does not follow the documented one.

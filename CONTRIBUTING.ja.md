# 開発に参加する

[English](CONTRIBUTING.md) | 日本語

php-imap-mcp に関心を持っていただき、ありがとうございます。Issue とプルリクエストは、日本語でも英語でも歓迎します。

この文書は [CONTRIBUTING.md](CONTRIBUTING.md)（英語）の翻訳です。内容が食い違うときは英語版が優先します。

**セキュリティの問題:** 公開の Issue にしないでください。[SECURITY.ja.md](SECURITY.ja.md) の手順に従ってください。

## 始める前に

- 仕様は [docs/SPEC.md](docs/SPEC.md) を正とします。仕様にない機能は、まず Issue で相談し、仕様を先に更新してからコードを変えます。
- 次の変更は、設計上の範囲外なので受け付けません。
  - メールの送信（SMTP に限らず、どの方法でも）や、送信用ライブラリの追加
  - メールの削除
  - 相談なしにセキュリティの初期値を緩めること（書き込み系ツールを初期有効にする、IP 制限やロックアウトを弱める、など）
- 実行時の依存を増やす場合は、まず Issue で相談してください。共用サーバーで動かすため、依存は少なく保ちます。

小さな修正より大きな変更は、進め方を揃えるために、先に Issue を立ててください。

## 開発環境

PHP 8.1 以上（iconv、json、mbstring、openssl、sodium の拡張）、Composer、結合テスト用の Docker が必要です。

```sh
composer install
composer check          # コードスタイル、PHPStan、単体テスト。変更のたびに必ず通す
```

| コマンド | 内容 |
| --- | --- |
| `composer cs` / `composer cs:fix` | コードスタイル（PSR-12、全ファイルに `declare(strict_types=1);`）の検査 / 自動修正 |
| `composer stan` | PHPStan level 8（strict-rules つき） |
| `composer test` | 単体テスト（`tests/Unit`） |
| `composer test:integration` | テスト用メールサーバーを使った結合テスト |
| `composer serve` | 開発用サーバーを `http://127.0.0.1:18080/` で起動する（`config.php` か `PHP_IMAP_MCP_CONFIG` の設定を読む） |

### 結合テスト

Docker 上の2つの IMAP サーバーで実行し、**両方で通す**必要があります。

- Courier-IMAP（`127.0.0.1:3993`）: XServer と同じ種類のサーバー。規則に厳しい
- Dovecot（`127.0.0.1:3994`）: 世界で最も多く使われている IMAP サーバー

```sh
docker compose up -d --wait
bash tests/bin/wait-for-mail-server.sh
IMAP_TEST_PORT=3994 bash tests/bin/wait-for-mail-server.sh
IMAP_TEST_REQUIRED=1 composer test:integration
IMAP_TEST_REQUIRED=1 IMAP_TEST_PORT=3994 composer test:integration
docker compose down
```

テスト用アカウントは、どちらも `test@example.com` / `test-password` です。

### E2E のスモークテスト

```sh
dir=$(mktemp -d)
token=$(php tests/e2e/make-config.php "$dir")
PHP_IMAP_MCP_CONFIG="$dir/config.php" php -S 127.0.0.1:18080 -t public public/index.php &
MCP_TOKEN="$token" composer test:e2e
bash tests/e2e/oauth-smoke.sh
```

## コーディング規約

- PHP 8.1 互換にする。8.2 以降の構文や関数は使わない。CI は PHP 8.1〜8.4 で動かし、`composer.lock` は PHP 8.1 で解決する。
- 全ファイルに `declare(strict_types=1);`。PSR-12、PHPStan level 8。
- ext-imap は使わない。IMAP は `webklex/php-imap` の低水準層（`ImapProtocol`）を通し、MIME は `symfony/mime` で作る。
- `public/` に置くのは `index.php` と `.htaccess` だけ。コード、設定、ログは公開ディレクトリの外に置く。
- 応答にパスワード、トークン、スタックトレースを含めない。ログにパスワード、トークン、メールの本文を記録しない。
- メールの内容は信頼できないデータとして扱う。
- できるだけテストを先に書く。メールの処理を変えるときはフィクスチャを用意する。日本語のフィクスチャ（ISO-2022-JP、Shift_JIS、HTML のみ、日本語のフォルダ名、添付付き）は特に歓迎します。
- OAuth を変えたら [docs/oauth-checklist.md](docs/oauth-checklist.md) の要件の表を、配置に関わるもの（`public/`、設定の見本、`bin/`）を変えたら [docs/deploy-xserver.md](docs/deploy-xserver.md) を更新する。

## テストや例に実際のデータを入れない

実際の認証情報、メールアドレス、トークン、メールを、テストやフィクスチャも含めてコミットしないでください。例には `example.com`、`svXXXXX.xserver.jp`、`203.0.113.0/24` を使います。

## プルリクエスト

- 1つのプルリクエストには1つの変更だけを入れ、何をなぜ変えるのかを書いてください。
- `composer check` が通ることを確かめてください。CI では、2つのメールサーバーでの結合テストも動きます。
- 利用者に見える変更は、[CHANGELOG.md](CHANGELOG.md) の `[Unreleased]` に英語で書き足してください。
- README、SECURITY、CONTRIBUTING を変えるときは、英語のファイルと日本語の `*.ja.md` の両方を更新してください。片方の言語しか書けなければ、プルリクエストにそう書いてもらえれば、もう片方はこちらで直します。

## ライセンス

貢献していただいた内容は、[Apache License 2.0](LICENSE) の第5条のとおり、このライセンスのもとで提供されるものとします。

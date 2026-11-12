# XServer への配置と実環境での検証（M6）

php-imap-mcp を XServer に置き、claude.ai のカスタムコネクタからつなぐまでの手順と、M6（SPEC.md 12章）で確かめる項目をまとめる。例の値（`ACCOUNT`、`example.com`、`svXXXXX.xserver.jp`）は自分の環境に読み替える。

確かめた結果は、最後の「確認項目」に書き込み、SPEC.md 12章の未決事項に反映する。

## 0. 先に知っておくこと

claude.ai の公式ドキュメント（2026-11-12 に確認）による前提:

- claude.ai（Web、デスクトップ、スマホ）のコネクタは、Anthropic のサーバーから MCP サーバーにつなぐ。送信元の IPv4 は `160.79.104.0/21` と `153.61.208.0/20`（[IP アドレス](https://platform.claude.com/docs/en/api/ip-addresses)。「予告なく変えない」とされている）。IPv6 には対応しない
- OAuth のコールバック URL は `https://claude.ai/api/mcp/auth_callback`（[Authentication](https://claude.com/docs/connectors/building/authentication)）
- PKCE（S256）を必ず使い、`resource` には MCP の URL を末尾のスラッシュなしで送る
- アクセストークンの期限の5分前か、401 を受けたときに、リフレッシュトークンで自動的に更新する
- トークンのエンドポイントは10秒（リフレッシュは30秒）で時間切れになる
- 認可サーバーのメタデータは `/.well-known/oauth-authorization-server` を先に、次に `/.well-known/openid-configuration` を探す。MCP の URL にパス（`/mcp/`）があるときにどの場所を探すかは書かれていないので、サイト直下からも見えるようにしておく（手順 5）
- 別のホストへのリダイレクト（301、302 など）があると Authorization ヘッダーが落ちて失敗する。`http://` から `https://` への転送や、`www` の有無の転送に注意する

## 1. XServer の準備

1. サーバーパネルの「SSH設定」で SSH を有効にし、公開鍵を登録する（ポートは 10022）
2. 「PHP Ver.切替」で、MCP を置くドメインの PHP を 8.1 以上にする（8.3 推奨）
3. 「SSL設定」で、そのドメインに無料独自SSLが入っていることを確かめる
4. SSH でつなぎ、コマンドラインの PHP を確かめる。Web の PHP とコマンドラインの PHP は別物で、`php` が古い版を指すことがある

   ```sh
   php -v
   ls /usr/bin/php*        # 版ごとのコマンド（php8.3 など）があればそれを使う
   php -m | grep -i sodium # sodium 拡張が必要
   ```

   以下、ここで決めた PHP のコマンドを `php` と書く。

## 2. ファイルを置く

リポジトリは非公開なので、手元（WSL）で固めて送る。

```sh
# 手元の WSL で、リポジトリの直下から
git archive --format=tar.gz -o /tmp/php-imap-mcp.tar.gz main
scp -P 10022 /tmp/php-imap-mcp.tar.gz ACCOUNT@svXXXXX.xserver.jp:~/
```

```sh
# XServer で
mkdir ~/php-imap-mcp && tar xzf ~/php-imap-mcp.tar.gz -C ~/php-imap-mcp && rm ~/php-imap-mcp.tar.gz
cd ~/php-imap-mcp
```

依存を入れる。XServer に Composer がなければ、`~/bin` に入れる（[公式の手順](https://getcomposer.org/download/)）。

```sh
composer install --no-dev --optimize-autoloader
```

Composer が使えないときは、手元の WSL で `composer install --no-dev` をした `vendor/` ごと送ってもよい（`composer.lock` は PHP 8.1 で解決してあるので、XServer の PHP 8.1 以上で動く）。

本体は `~/php-imap-mcp/`（公開ディレクトリの外）に置く。公開ディレクトリに置くのは、手順 4 の2ファイルだけ。

## 3. 設定を書く

```sh
cd ~/php-imap-mcp
cp config.example.php config.php && chmod 600 config.php
php bin/generate-key.php            # auth.oauth.key に書く
php bin/generate-client-secret.php  # ハッシュを auth.oauth.clients[].secret_hash に、シークレットは claude.ai に入力する（控えておく）
```

`config.php` で書き換えるところ:

| キー | 値 |
|---|---|
| `auth.oauth.resource` | `https://example.com/mcp/`（claude.ai に入力する URL と完全に同じにする） |
| `auth.oauth.imap.host` | `svXXXXX.xserver.jp`（サーバーパネルの「サーバー情報」にあるホスト名） |
| `auth.oauth.allowed_email_domains` | ログインを許すメールのドメイン |
| `auth.oauth.clients[0].redirect_uris` | `https://claude.ai/api/mcp/auth_callback`（見本のまま） |
| `auth.allowed_ips` | Anthropic の送信元（見本のまま）。手順 6 の確認の間だけ、自宅の IP を足す |

確かめる（パスワードを尋ねられる。保存はしない）:

```sh
php bin/check-config.php --login you@example.com
```

`login OK` と下書きフォルダの名前が出れば、IMAP は使える。下書きフォルダが `NOT FOUND` なら、`auth.oauth.folders.drafts` にフォルダ名を書く。

## 4. 公開ディレクトリに置く

```sh
mkdir ~/example.com/public_html/mcp
cp ~/php-imap-mcp/public/index.php ~/php-imap-mcp/public/.htaccess ~/example.com/public_html/mcp/
```

`~/example.com/public_html/mcp/index.php` の `APP_ROOT` を本体の場所に書き換える:

```php
const APP_ROOT = '/home/ACCOUNT/php-imap-mcp';
```

## 5. サイト直下からメタデータを見えるようにする

`~/example.com/public_html/.htaccess`（なければ作る）の**先頭**に、次を足す。WordPress などの既存の記述は消さない。

```apache
# php-imap-mcp: OAuth discovery at the site root (RFC 8414, RFC 9728)
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^\.well-known/(oauth-authorization-server|openid-configuration|oauth-protected-resource)/mcp/?$ mcp/index.php [L]
</IfModule>
```

`index.php` は元のパスを見て振り分けるので、転送先は `mcp/index.php` だけでよい。

## 6. 外から確かめる

`auth.allowed_ips` に自宅の IP を一時的に足してから、手元で実行する。期待する結果と違えば、そこで止めて原因を調べる。

```sh
u=https://example.com

# MCP 本体: 401 と、メタデータの場所を示すヘッダー
curl -si -X POST "$u/mcp/" -H 'Content-Type: application/json' -d '{}' | grep -iE '^HTTP|^www-authenticate'

# 保護リソースのメタデータ（2か所）: "resource":"https://example.com/mcp/"
curl -s "$u/mcp/.well-known/oauth-protected-resource"
curl -s "$u/.well-known/oauth-protected-resource/mcp"

# 認可サーバーのメタデータ（3か所とも同じ JSON）: "issuer":"https://example.com/mcp"
curl -s "$u/.well-known/oauth-authorization-server/mcp"
curl -s "$u/.well-known/openid-configuration/mcp"
curl -s "$u/mcp/.well-known/openid-configuration"

# http から https への転送がドメインをまたがないこと（Location が https://example.com/... であること）
curl -si "http://example.com/mcp/" | grep -iE '^HTTP|^location'
```

ブラウザ（スマホの回線など、許可していない IP でもよい）で `https://example.com/mcp/authorize` を開き、「ログインを続けられません」というエラー画面が出ること（ログイン画面が IP 制限の外にあることの確認）。

確かめ終わったら、`auth.allowed_ips` から自宅の IP を外し、許可していない IP から MCP 本体が `403` になることを確かめる:

```sh
curl -si -X POST "$u/mcp/" -d '{}' | head -1   # HTTP/1.1 403
```

## 7. claude.ai に登録する

1. claude.ai の「設定」→「コネクタ」→「カスタムコネクタを追加」
2. 名前（例: メール）と、URL `https://example.com/mcp/` を入力する
3. 「詳細設定」を開き、OAuth クライアント ID に `claude`（`config.php` の `clients[].id`）、クライアントシークレットに手順 3 で控えた値を入力する
4. 「接続」を押すと、ログイン画面が開く。メールアドレスとパスワードを入力する
5. claude.ai に戻り、コネクタが「接続済み」になれば成功

失敗したときは、`~/php-imap-mcp/var/logs/audit-YYYY-MM-DD.jsonl` の最後の数行を見る（`oauth_client_failed` はクライアント ID かシークレットの誤り、`oauth_grant_failed` はコードや `resource` の不一致、`auth_failed` はトークンの問題）。ログにパスワードとトークンは記録されない。

## 8. 確認項目

結果を書き込み、SPEC.md 12章に反映する。

**XServer**

- [ ] コマンドラインの PHP の版と、sodium 拡張の有無（手順 1）
- [ ] Web の PHP で sodium が使えること（手順 6 の MCP 本体が `500` でなく `401` になれば使えている）
- [ ] `public/.htaccess` の `RewriteRule` が動くこと（手順 6 の `/mcp/.well-known/…` が JSON を返す）
- [ ] Authorization ヘッダーが PHP に届くこと（手順 7 で接続した後、ツールが動けば届いている）
- [ ] サイト直下の `.htaccess` の転送が動くこと（手順 6 の `/.well-known/…/mcp`）
- [ ] 国外IPアクセス制限など、XServer 側のアクセス制限が Anthropic からの接続を妨げないこと
- [ ] PHP の実行時間制限に当たらないこと（大きなメールの `get_email`、`search_emails` の上限件数で）

**claude.ai の OAuth**

- [ ] クライアント ID とシークレットの事前登録で接続できる（手順 7）
- [ ] コールバック URL が `https://claude.ai/api/mcp/auth_callback` で合っている
- [ ] どの場所の認可サーバーのメタデータが使われたか（アクセスログで確かめる。サイト直下の転送が要らないと分かれば、手順 5 を任意にできる）
- [ ] 1時間（`access_ttl_sec`）以上たってから使っても、ログインし直さずに動く（監査ログに `oauth_token` の refresh が記録される）
- [ ] Web で接続したコネクタが、スマホのアプリでもそのまま使える
- [ ] `php bin/revoke-grant.php <grant>` で取り消すと、次の利用でログインを求められる
- [ ] claude.ai が使う MCP 仕様の版（監査ログの `tools/call` の `protocol` と `clientInfo`）

**受け入れ基準（SPEC.md 11章）**

- [ ] `list_folders`: 日本語のフォルダ名が正しく出る
- [ ] `search_emails`: 日本語の件名・差出人で検索できる
- [ ] `get_email`: ISO-2022-JP、Shift_JIS、HTML だけのメールが文字化けせずに読める。添付ファイルは名前と大きさだけが出る
- [ ] `save_draft`: 下書きフォルダに保存され、メールソフト（Thunderbird、Roundcube、スマホのメールアプリなど）で開ける
- [ ] 保存した下書きをメールソフトから送ったとき、差出人の表示がどうなるか（SPEC.md 12章の表示名の検討に使う）
- [ ] ログイン画面でパスワードを続けて間違えると、ロックアウトされる
- [ ] 監査ログに `tools/call` が記録され、パスワード、トークン、本文が含まれない

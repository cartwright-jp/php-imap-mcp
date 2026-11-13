# OAuth 要件チェックリスト

SPEC.md 7章の方針（OAuth を自前で実装し、要件を一覧にして項目ごとにテストを用意する）に基づく対応表。要件の出典は、MCP の認可仕様（2026-07-28版。Authorization、Authorization Server Discovery、Client Registration、Security Considerations）、OAuth 2.1（draft-ietf-oauth-v2-1）、OAuth 2.0 Security BCP（RFC 9700）。

テストの場所の略記:

- Flow: `tests/Unit/Auth/OAuth/OAuthFlowTest.php`
- Sealer: `tests/Unit/Auth/OAuth/SealerAndStoreTest.php`
- Config: `tests/Unit/Config/OAuthConfigTest.php`（共通部分は `ConfigParserTest.php`）
- IMAP: `tests/Integration/OAuthImapFlowTest.php`
- E2E: `tests/e2e/oauth-smoke.sh`

## 発見（Discovery）

| 要件 | 出典 | 実装 | テスト |
| --- | --- | --- | --- |
| Protected Resource Metadata を提供し、`authorization_servers` を1件以上含める | MCP Discovery（MUST）、RFC 9728 | `Metadata::protectedResource` | Flow `testDiscoveryDocuments`、E2E |
| 401 の `WWW-Authenticate` に `resource_metadata` を入れる | MCP Discovery、RFC 9728 5.1 | `AccessTokenAuthenticator::challenge` | Flow `testMcpWithoutTokenPointsToTheMetadata`、E2E |
| `WWW-Authenticate` に `scope` を入れる | MCP Scope Selection（SHOULD） | 同上（`scope="mail"`） | 同上 |
| 認可サーバーのメタデータ（RFC 8414）を、クライアントが試す場所に置く。`issuer` は取得に使った識別子と一致させる | MCP Discovery（MUST） | `<issuer>/.well-known/openid-configuration`（MCPディレクトリ内）と `/.well-known/oauth-authorization-server/<path>`（サイト直下からの転送時）で同じ文書を返す | Flow `testDiscoveryDocuments`、E2E |
| `code_challenge_methods_supported` を必ず含める（ないとクライアントは中止する） | MCP Security（MUST） | `["S256"]` | Flow `testDiscoveryDocuments`、E2E |
| `iss` を返すなら `authorization_response_iss_parameter_supported: true` | RFC 9207 2.3（MUST） | `Metadata::authorizationServer` | Flow `testDiscoveryDocuments` |
| `offline_access` を `scopes_supported` に入れない | MCP（SHOULD NOT） | スコープは `mail` だけ | Flow `testDiscoveryDocuments` |

## 認可エンドポイント（ログイン画面）

| 要件 | 出典 | 実装 | テスト |
| --- | --- | --- | --- |
| リダイレクト URI は事前登録と完全一致で照合する | MCP Security（MUST）、OAuth 2.1 | `OAuthClient::allowsRedirectUri` | Flow `testErrorsBeforeTheRedirectUriIsTrustedAreShownNotRedirected` |
| クライアントとリダイレクト先を確かめる前のエラーはリダイレクトしない（オープンリダイレクタにしない） | OAuth 2.1 4.1.2.1、7.12 | `AuthorizeEndpoint::show` がエラー画面を返す | 同上、E2E |
| リダイレクト URI は https か localhost | MCP Security（MUST） | 設定の検証で拒否 | Config `http redirect` |
| PKCE は S256 だけ。`plain` と PKCE なしは拒否 | OAuth 2.1 4.1.1、MCP Security | `AuthorizeEndpoint::show` | Flow `testInvalidRequestsAreRedirectedWithAnError`、E2E |
| `resource` が自サーバーでなければ拒否 | RFC 8707、MCP | `invalid_target` | Flow `testInvalidRequestsAreRedirectedWithAnError` |
| `resource` は末尾のスラッシュ、scheme とホストの大文字小文字を区別せずに比べる（claude.ai はスラッシュなしで送る） | RFC 8707、claude.ai の Authentication | `OAuthSettings::isOwnResource` | Flow `testCanonicalResourceWithoutTrailingSlashAndSecretInTheBody`、`testRefreshForAnotherResourceOrScopeIsRefused` |
| 認可応答に `iss` を入れる（エラー応答も） | RFC 9207、MCP（SHOULD） | `AuthorizeEndpoint::redirect` | Flow `testLoginTokenExchangeAndMcpCall`、`testInvalidRequestsAreRedirectedWithAnError` |
| `state` をそのまま返す | OAuth 2.1 | 同上 | 同上 |
| パラメータの重複を拒否する | OAuth 2.1 3.1 | `Request::isRepeated` | Flow `testRepeatedParametersAreRejected` |
| ログイン CSRF を防ぐ | OAuth 2.1 7.6、RFC 9700 | ログイン要求を封印し、同じ値の HttpOnly Cookie と照合。Origin は自分と一致するものだけ（`null` となしは拒否）。そのためログイン画面は `Referrer-Policy: same-origin`（M7 の見直しで強化） | Flow `testLoginWithoutTheCookieOrFromAnotherOriginIsRefused`、`testTheLoginFormSendsItsOriginButNoReferrerToOtherSites`、`testTamperedLoginRequestIsRefused` |
| ログインの入力に制御文字を許さない（IMAP のコマンドを途中で切らせない） | — | `AuthorizeEndpoint::login`（M7 の見直しで追加） | Flow `testControlCharactersInThePasswordAreRefusedBeforeTheImapServerIsAsked` |
| クリックジャッキングを防ぐ | OAuth 2.1 7.7 | `frame-ancestors 'none'`、`X-Frame-Options: DENY` | Flow `testLoginTokenExchangeAndMcpCall`、E2E |
| リダイレクト先のホスト名を表示する | MCP Security（MUST、CIMD の文脈） | ログイン画面に表示 | Flow `testLoginTokenExchangeAndMcpCall` |
| ログインの試行回数を制限する（IP 単位とメールアドレス単位） | SPEC 7章 | `Lockout`（`login-ip:`、`login-mail:`） | Flow `testWrongPasswordShowsTheFormAgainAndRepeatedFailuresLockOut` |
| ログインできるメールドメインを絞る | SPEC 7章 | `allowed_email_domains`（必須） | Flow `testOnlyAllowedMailDomainsMaySignIn`、Config |
| 本人確認は実際の IMAP ログイン | SPEC 7章 | `CredentialVerifier` | IMAP 2件 |
| ログイン画面の有効期限 | — | 封印したログイン要求に10分の期限 | Flow `testExpiredCodesRefreshTokensAndLoginFormsAreRefused` |
| 認可コードは短命 | OAuth 2.1 4.1.2 | 5分 | 同上 |

## トークンエンドポイント

| 要件 | 出典 | 実装 | テスト |
| --- | --- | --- | --- |
| 認可コードは1回だけ使える | OAuth 2.1 4.1.3（MUST） | `IdStore::markOnce`（ファイルの排他作成で、競合しても1回） | Flow `testCodeWorksOnce`、Sealer `testIdStoreMarksOnceUntilExpiry` |
| 使用済みの認可コードが再び使われたら、そのコードで発行したトークンを取り消す | OAuth 2.1 4.1.3（SHOULD） | ログイン全体を取り消す（M7 の見直しで追加） | Flow `testAReusedCodeRevokesTheTokensIssuedFromIt` |
| PKCE の検証（code_verifier の形式と S256） | OAuth 2.1 4.1.3 | `TokenEndpoint::exchangeCode` | Flow `testWrongVerifierIsRejectedWithoutBurningTheCode` |
| 認可コードは発行先のクライアントとリダイレクト URI に結び付く | OAuth 2.1 4.1.3 | 同上 | Flow `testCodeIsBoundToRedirectUriClientAndResource` |
| `resource` の検証 | RFC 8707 | `invalid_target` | 同上、`testRefreshForAnotherResourceOrScopeIsRefused` |
| 機密クライアントの認証（Basic と POST の両方、片方だけ） | OAuth 2.1 2.4 | `TokenEndpoint::authenticateClient`（シークレットは SHA-256 のハッシュで照合） | Flow `testConfidentialClientMustAuthenticate`、E2E |
| 公開クライアントは PKCE だけで使える | OAuth 2.1 2.1 | `secret_hash => null` | Flow `testPublicClientUsesPkceWithoutSecret` |
| リフレッシュトークンの入れ替え（使うたびに新しいものを発行） | OAuth 2.1 4.3.1、MCP Security（公開クライアントは MUST） | `TokenEndpoint::refresh` | Flow `testRefreshRotatesTokens` |
| 使用済みのリフレッシュトークンが再び使われたら、そのログイン全体を取り消す | RFC 9700 4.14.2 | `refreshReused` | Flow `testReusedRefreshTokenRevokesTheWholeGrant` |
| リフレッシュのたびに延長する（`refresh_ttl_days`） | SPEC 7章（2026-11-12決定） | `TokenIssuer::issue` | Flow `testRefreshRotatesTokens`、Config `testRefreshDaysAreConfigurable` |
| リフレッシュ時に IMAP ログインを確かめ、パスワード変更で終わらせる | SPEC 7章 | `CredentialVerifier` | Flow `testChangedMailPasswordEndsTheGrantAtRefresh`、IMAP |
| メールサーバーの一時的な障害でリフレッシュトークンを失わない | — | 使用済みの記録は IMAP の確認の後 | Flow `testUnreachableMailServerDoesNotCostTheRefreshToken` |
| 応答に `Cache-Control: no-store` | OAuth 2.1 3.2.3 | `Response::send` と `Pragma: no-cache` | （全応答に付与） |
| アクセストークンは短命 | MCP Security（SHOULD） | 初期1時間（`access_ttl_sec`） | Config `testDefaultsAndDerivedUrls` |

## リソースサーバー（MCP エンドポイント）

| 要件 | 出典 | 実装 | テスト |
| --- | --- | --- | --- |
| 自分宛て（audience）のトークンだけを受け付ける | MCP Security（MUST）、RFC 8707 | `AccessTokenAuthenticator`（`aud` を照合） | Flow `testTokensForOtherResourcesOrOfOtherTypesAreRejected` |
| 期限切れ・改ざん・別の種類のトークンを拒否し、401 で返す | MCP（MUST）、OAuth 2.1 5.2 | 同上（種類は接頭辞と AEAD の関連データの両方で区別） | 同上、Sealer `testATokenOfOneTypeCannotBeUsedAsAnother`、`testChangedOrForeignTokensAreRejected` |
| トークンは Authorization ヘッダーだけ（クエリ文字列では受けない） | MCP（MUST） | Bearer ヘッダーだけを読む | Flow 全般 |
| 他のサーバーのトークンを受け付けない・中継しない | MCP（MUST NOT） | 自分で封印したトークンだけを開ける | Sealer `testChangedOrForeignTokensAreRejected` |
| トークンの失敗ではロックアウトしない（封をしたトークンは当てられず、接続元は claude.ai の利用者全員が共有する IP） | SPEC 7章（M7 の見直しで決定） | `Guard::check` は監査ログに記録するだけ。ロックアウト中の IP は `checkSource` で拒否 | GuardTest `testFailedTokensAreLoggedButDoNotLockTheIpOut`、`testAnIpBannedElsewhereIsRefusedWithoutLookingAtTheToken` |
| 取り消したログインのトークンを拒否する | SPEC 7章 | `IdStore::REVOKED_GRANTS`、`bin/revoke-grant.php` | Flow `testReusedRefreshTokenRevokesTheWholeGrant` |
| 設定から削除したクライアントのトークンを拒否する | — | `AccessTokenAuthenticator` | Flow `testRemovedClientLosesAccessAtOnce` |

## 通信と配置

| 要件 | 出典 | 実装 | テスト |
| --- | --- | --- | --- |
| すべてのエンドポイントは HTTPS | MCP Security（MUST） | `resource` は https 必須（localhost のテストだけ http を許し、警告） | Config `http resource`、`testLocalhostIsAllowedForTestingWithAWarning` |
| HTTP で届いたリクエストは拒否する（転送しない） | MCP Security、OAuth 2.1 1.5 | `Application::handle`、`Request::isHttps`（Web サーバーの変数だけを見る） | Flow `testPlainHttpIsRefusedOnEveryRoute`、`testPlainHttpWorksWhenTheResourceItselfIsHttpForLocalTesting`、Request `testHttpsIsReadFromTheWebServersVariables` |
| ログイン画面以外は接続元 IP で制限する | SPEC 7章 | `Guard::checkSource`（authorize だけ通さない） | Flow `testOnlyTheLoginPageIsOpenToEveryIp` |
| パスワードを設定ファイルに残さない | SPEC 7章 | `accounts` と `auth.tokens` があれば起動を拒否 | ConfigParserTest `removed: accounts`、`removed: static tokens` |
| パスワード・トークン・鍵をログや画面に出さない | SPEC 7章 | 監査ログは grant と client だけ。`__debugInfo` で鍵を隠す | Flow `testLoginTokenExchangeAndMcpCall`、Sealer `testGeneratedKeyIsUsable`、Config `testDefaultsAndDerivedUrls` |

## 実装しないもの（理由）

| 項目 | 出典での扱い | 理由 |
| --- | --- | --- |
| Dynamic Client Registration | MAY、非推奨 | クライアントは事前登録（SPEC 7章） |
| Client ID Metadata Documents | SHOULD | 必要と分かってから検討する（SSRF 対策などが必要になるため）。M6 で claude.ai が事前登録で動くかを確認する |
| スコープによる段階的な権限付与 | — | スコープは `mail` の1つだけ。使えるツールは `tools.*` の設定で決める |

## 公開（M7）前の見直しで重点的に確認すること

- ログイン画面: CSRF、Cookie の属性、Origin の判定、エラー表示の内容
- 封印トークン: 鍵の扱い、関連データによる種類の区別、期限の判定
- リフレッシュ: 入れ替えと再使用の検知、取り消しの持続期間
- `.htaccess`: すべてのパスを index.php に渡すことと、ログイン画面だけ IP 制限から外すこと（XServer の実機で確認する）

### 見直しの結果（2026-11-13）

- ログイン画面: CSRF は Cookie と照合していたが、Origin の確認は `Referrer-Policy: no-referrer` のためにブラウザが `Origin: null` を送り、実際には働いていなかった。`same-origin` に変え、自分の Origin だけを受け付けるようにした（共用ドメインでは隣のサイトが Cookie を置ける可能性があるため、両方で確かめる）。パスワードの制御文字（CR、NUL など）を断るようにした。Cookie の属性（`Secure`、`HttpOnly`、`SameSite=Lax`、MCP のディレクトリに限定）とエラー表示（理由の詳細やパスワードを出さない）は問題なし
- 封印トークン: XChaCha20-Poly1305、種類を関連データでも区別、期限は `Claims` で整数として確かめる。鍵は `__debugInfo` で隠す。問題なし。鍵を作り直すと発行済みのトークンはすべて使えなくなる
- リフレッシュ: 入れ替えと再使用の検知、取り消しの記録の期間（`refresh_ttl_days`）は問題なし。使用済みの認可コードの再使用でもログインを取り消すようにした
- ロックアウト: MCP のエンドポイントのトークンの失敗を数えていたため、claude.ai の再接続（トークンなしの要求）や、他の claude.ai 利用者が送るでたらめなトークンで、Anthropic の IP が締め出されうる状態だった。数えないようにした（人間の判断、SPEC 7章）。メールアドレス単位のログインの制限は、他人がわざと失敗すると15分ログインできなくなる代わりに総当たりを防ぐもので、接続済みのコネクタには影響しない。このまま残す
- `.htaccess`: すべてのパスを index.php に渡すことは XServer で確認済み。ログイン画面だけ IP 制限から外す `<If>` は任意の設定で、XServer では未確認

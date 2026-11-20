# 東丸 / azuma.rss7.net 構築・配備メモ（2026-11-20）

## 目的・公開先
- ロリポップのサブドメイン：`azuma.rss7.net`
- 公開（アップロード）フォルダ：`azuma`
- FTP/ファイルマネージャーでの展開先：`/azuma/` （サーバー側のルートに基づく）
- サイトのトップ：`/azuma/index.html` → `https://azuma.rss7.net/`
- 釣果情報：`https://azuma.rss7.net/catch/`
- 読取API：`https://azuma.rss7.net/line-api/feed.php`
- LINE受信：`https://azuma.rss7.net/line-api/webhook.php`
- 管理画面：`https://azuma.rss7.net/line-api/admin/`

**未完了**：ロリポップ画面のサブドメイン「更新」の完了確認、無料SSL、FTPアップロード、LINE公式アカウントとWebhookの本番接続、実機テスト。

## 既存サイト保護ルール
- `rss7.net`、`chat.rss7.net`、その他の既存サブドメイン・既存公開フォルダ・DNSには触らない。
- 本ブランチ `feat/wsc-line-lolipop-staging-20261119` はロリポップ向けの**コピー**。ドラフトPR #17はGitHub Pages本番へマージしない。
- 本家：`https://oosaka0123-sudo.github.io/wakayama-saika-club/`。5ページのヘッダー・フッター・デザインをコピー側でも保つ。
- 釣果ページの見出しは「釣果情報」のみ。写真は必須、コメント・投稿者名は任意、承認は初回会員登録だけ。
- コピーは必ず `.htaccess` の `X-Robots-Tag: noindex, nofollow` を確認する。GitHub Pages本家の5ページはindex可能なままとする。
- ステージングコピーに既存GitHub所有権確認ファイル（`google*.html`、`BingSiteAuth.xml` 等）を含めない。

## PC04での安全な配備パッケージ作成
1. このブランチをPC04に取得。
2. `powershell -ExecutionPolicy Bypass -File tools/package-azuma.ps1` を実行。
3. 生成物 `dist/azuma-lolipop.zip` は **ファイルを取り出して** `/azuma/` へアップロード。ZIPそのものや親フォルダを入れ子で置かない。
4. `index.html`、`catch/index.html`、`line-api/webhook.php`、`line-api/data/.htaccess` が正しい階層にあるか確認。秘密情報・既存投稿データはZIPに含めない。
5. 最初は誤公開防止のため、本番連携を一切設定せず画面・リンク・静的写真の表示だけを確認。

## SSLを最初に有効にする
- ロリポップの「セキュリティ → 独自SSL証明書導入」で `azuma.rss7.net` を選び、**無料の独自SSL**を設定する。
- 有効化されなければDNSとドメインの向き先を調べる。既存DNSレコードは無断で変更しない。
- `https://azuma.rss7.net/` にアクセスできるまでLINEと管理画面を接続しない。
- ロリポップのサブドメイン編集画面で「SSL証明書 未契約」と表示されていても、**無料SSL設定が済んだ証拠にはならない**。上記専用ページで確認する。

## 本番LINE接続（秘密情報はサーバー内のみ）
- 専用LINE公式アカウントのMessaging APIを有効化。
- サーバー上でのみ `line-api/config.sample.php` を `line-api/config.local.php` にコピー。
- Channel secret、access token、32字以上の管理トークンをサーバーへ記録し、GitHubやチャットに貼らない。
- `public_base_url` は `https://azuma.rss7.net/line-api`。
- `line-api/data/` と `line-api/uploads/` の必要な書込権限を確認。PHP 8.3、GD・cURL・mbstring・fileinfo を確認する。
- 公開前に `/line-api/data/state.json` と `/line-api/config.local.php` がHTTP経由で読めないことを必ず検証。
- 非会員の投稿拒否、初回会員登録、写真1枚投稿、GPS/EXIF除去、コメント任意、削除、連投をテスト。
- APIの本番公開が成功した後、**別PRで**GitHub Pages本家の釣果表示を `js/catch-config.js` 経由で連携する。LINEシークレットはフロントエンドに書かない。

## 公開判定
- GET `https://azuma.rss7.net/`：HTMLが表示され、レスポンスに `X-Robots-Tag: noindex, nofollow` が存在する。
- GET `https://azuma.rss7.net/catch/`：見出しが「釣果情報」で、ヘッダー/フッターが本家と揃う。
- GET `https://azuma.rss7.net/line-api/feed.php`：設定完了後JSONを返す。
- `config.local.php`、`state.json`：HTTP 403等で**閲覧不可**。
- HTTPS未設定・アクセス制限不良・PHP拡張不足のいずれかがある場合はLINEのWebhookを有効化しない。

このメモは設計・配備手順であり、実サーバーに配備・確認した記録ではありません。

# 和歌山雑賀クラブ「釣果情報」LINE連携 — 配備手順と安全設計

## 現在地（2026-11-19）
- 最新公式サイト main をコピーした **独立したPC04作業ブランチ**。
- GitHub Pages本番、チャットホーム、rss7.net、DNSは変更しない。
- 釣果ページのタイトル / H1 は「釣果情報」。
- LINE公式アカウント未開設、ロリポップ公開先未確定。外部本番接続は行っていない。
- PR #17 は Draft。正式な配備までmainへマージしない。

## 2つのURLを扱うルール
- クラブの公式閲覧先は従来の GitHub Pages URL のまま。
- ロリポップのコピーは別URL（アドレス欄では同じドメインにできない）。
- ロリポップ側コピーの HTTP Header `X-Robots-Tag: noindex, nofollow` はルートの .htaccess で配信する。ロリポップ公開後はヘッダーが正しく出ることを必ず確認する。
- 公式GitHub Pages の既存5ページの `index,follow` とcanonicalはそのまま維持し、重複サイトによるSEO事故を避ける。
- ロリポップ側で投稿した写真は `line-api/feed.php` のJSONを通じて、将来はGitHub Pagesでも表示できる（公開HTTPS URL確定後に設定）。

## ディレクトリ構成
- `catch/index.html` 釣果情報ページ
- `index.html` 最新釣果3件（コピー側のみ）
- `css/catch.css` + `js/catch.js` + `js/catch-home.js` 表示処理
- `line-api/webhook.php` LINEの署名検証・会員限定画像受信
- `line-api/lib.php` 排他制御つき保存処理・画像の再エンコード（EXIF/GPSを除去）
- `line-api/feed.php` 公開写真と任意テキストを返す読み取りAPI
- `line-api/admin/index.php` 管理キーでログイン、会員初期登録/解除と誤投稿削除
- `line-api/delete.php` 管理用POST API（代替手段）
- `line-api/config.sample.php` 設定例、`config.local.php` はGit管理対象外
- `line-api/data/` 個人識別子と投稿データ。HTTPアクセス遮断
- `line-api/uploads/` 再生成したJPEG写真。PHP等の実行を禁止

## 投稿者が使う手順
1. 専用LINE公式アカウントを友だち追加し、初回だけ `登録：山田` のように送る。
2. 管理者が **最初の会員登録のみ** 確認・許可（投稿ごとの承認は不要）。
3. 釣果写真を送るだけで公開。投稿者名やコメントは省略可。
4. 追加したい場合は写真の前後15分以内に、同じトークで次のテキストを送る。
   - `コメント：良型マダイが釣れました`
   - `投稿者：山田`
5. 写真を複数送った場合は1枚ずつ別投稿となる。まとめアルバム表示は未実装。
6. 写真とコメントは **インターネットで誰でも閲覧可能**。事前に投稿者の周知と同意が必要。

## 本番接続手順
1. LINE公式アカウントを新規開設し、LINE Official Account ManagerでMessaging APIを有効化。
2. ロリポップの既存サイト・既存ドメインの公開フォルダを調査。**既存ファイルを上書きせず**、専用のHTTPS公開先と隔離フォルダを用意する。
3. PHP8.3以上、cURL/mbstring/fileinfo/GD、HTTP HTTPSと .htaccess の可否を確認。
4. 配備ディレクトリの `data/` と `uploads/` が書き込め、Webから `data/state.json` と `config.local.php` が読めないことを確認。
5. ロリポップ側だけ `config.sample.php` のコピー `config.local.php` を作り、LINEチャネルシークレット・アクセストークン・管理者キー（32文字以上）・`public_base_url` を登録。**秘密情報をGitHubにpush/チャットに貼らない。**
6. LINE管理画面のWebhook URLへ `https://(独立のホストまたはサブディレクトリ)/line-api/webhook.php` を指定し検証。
7. 管理画面 `line-api/admin/` へHTTPSでログインし、友だち追加した会員を初回認証。
8. 実際にLINEへ写真送信 → 投稿表示 → EXIFがないこと → 取り消し → 非会員からの拒否 → スマホ表示を実機テスト。
9. API URLが確定後、公式GitHub Pagesから `feed.php` を読み込む場合、`js/catch-config.js` に **公開URLのみ** を設定して別PRとしてレビューし本番公開。

## 注意
- Noindexが正しく返ることをロリポップ側のHTTPレスポンスヘッダーで確認するまで、コピーを一般公開しない。
- 本番のフォーム・DB・ログ・アップロードができていない状態で動作確認済みと宣言しない。
- 投稿ごとの管理者承認なし。初回の本人確認と誤投稿削除のみ管理操作。
- 予備の失敗時リトライ、画像容量制限、迷惑投稿のレート制限は本番負荷に応じて追加。

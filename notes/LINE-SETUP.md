# WSC 釣果LINE 自動連携 — ロリポップ設置準備

## 変更範囲と未実施事項
- このフォルダは和歌山雑賀クラブ公開GitHub Pagesのコピー。複製側だけ新機能を追加している。
- 既存の chat.rss7.net、rss7.net、kansai.rss7.net とDNSやWebルートを変更しない。
- 現状は **PC04上のステージングのみ**。ロリポップへのアップロードとLINE実接続はまだ。
- 2つのサイトで検索競合しないよう、複製HTMLは noindex としている。公式GitHub Pagesは変更なし。
- GitHub PagesとロリポップのURLは同じにはならない。公開後は既存GitHub Pages側で報告一覧を表示する場合、JSからロリポップの読み取りAPIを呼ぶ。

## 構成
- catch/index.html … 釣果一覧画面（同じヘッダー、メニュー、フッター）
- index.html … 最新釣果3件と「釣果一覧を見る」
- js/catch*.js … 受信APIの読み込み。危険な投稿HTMLを実行しない。
- line-api/feed.php … 公開写真と任意テキストのJSON配信
- line-api/webhook.php … LINE署名検証、会員限定受信、LINE画像取得、投稿保存
- line-api/delete.php … 管理者トークン認証で誤投稿の削除
- line-api/data/ … Webhook履歴と投稿者IDの非公開記録
- line-api/uploads/ … 拡張子/MIME制限した画像のみ

## LINE送信方法
- 会員が釣果写真を送信すると、即時（サーバー処理後）自動掲載。投稿ごとの管理者承認なし。
- コメントと投稿者名は省略可能。
- 写真の前後15分以内に、同じLINEから次のテキストを送ると最新写真の情報を更新する。
  - コメント: 良型のマダイが釣れました
  - 投稿者: 山田
- 一行の自由文だけならコメントとして扱い、LINE登録名を無断公開しない。
- 複数写真は1枚ごとに別投稿として扱う（アルバム合成は未対応）。

## 本番接続で必要な操作
1. LINE公式アカウントを新規作成し、Messaging APIチャネルを設定する。
2. ロリポップの **独立した新規公開先** と有効なHTTPS URLを確認する。既存ドメインのサイトファイルは上書きしない。
3. 受信サーバーにロリポップのPHPを有効化し、\`line-api/data/\` と \ `line-api/uploads/\` の書き込み権限を確認する。
4. server上に\`line-api/config.local.php\` を \ `config.sample.php\` から作り、チャネルシークレット・アクセストークン・公開APIのHTTPS URL・管理者トークンを設定する。**GitHubやチャットに秘密情報を貼らない。**
5. 会員のLINE User IDは友だち追加Webhookで非公開データに候補として記録できる。本人確認したIDのみ\`allowed_user_ids\`へ設定する。投稿ごとの承認は不要。
6. LINE Developersに\`https://<separate-domain-or-path>/line-api/webhook.php\`をWebhookとして設定し、検証送信を行う。
7. 専用LINEから実際に画像送信 → feed.phpの表示 → トップ3件 / catch/全件表示 → 削除テスト → スマホ表示を確認する。
8. 必要なら公式GitHub Pages側に catch/ の静的画面とJSを移し、\`js/catch-config.js\` にロリポップのHTTPS feed URLだけを設定。秘密情報はJSに置かない。

## 注意
- 公式LINEでの送受信条件、LINEによる署名検証、CORS（GitHub Pages起点）、写真の掲載同意が必要。
- LINEアカウントやロリポップ受信用URLが未確定のため、現段階では実際の自動掲載は動作しない。
- ロリポップのPHP設定とCORS/WAFの実機動作は公開後に確認する。
- 管理者削除は保護されたPOST APIで可能。操作UIは後続で設置する。

# 釣果情報（LINE釣果投稿システム）

和歌山雑賀クラブ専用。東丸（azuma.rss7.net）や他サイトとは完全に別のシステムです。

```
会員のLINE → LINE公式アカウント「和歌山雑賀クラブ 釣果投稿」(@146kabxb)
          → ロリポップ saika.rss7.net/line-api/webhook.php（署名検証・会員確認・保存）
          → 「送信」で即時掲載（管理画面 line-api/admin/ で編集・非公開・削除）
          → line-api/feed.php（掲載中だけのJSON）
          → GitHub Pages「釣果情報」/catch/ が読み込んで表示
```

## フォルダ構成

| パス | 役割 | 公開範囲 |
|---|---|---|
| `catch/index.html` `css/catch.css` `js/catch.js` | 釣果情報ページ（GitHub Pages） | 公開 |
| `js/catch-config.js` | APIの公開URLとLINE友だち追加URL（秘密情報なし） | 公開 |
| `line-api/webhook.php` | LINE Webhook受付 | LINEのみが利用 |
| `line-api/feed.php` | 掲載中の投稿のJSON | 公開（CORSは公式サイトのみ） |
| `line-api/photo.php` | 写真配信。掲載中のみ一般公開、それ以外は管理者のみ | 条件付き |
| `line-api/admin/` | 管理画面（パスワード認証・CSRF対策） | 管理者のみ |
| `line-api/setup.php` | 初回設定（setup.key がある間だけ1回使える） | 初回のみ |
| `line-api/tick.php` | 送信忘れ下書きの自動確定（情報は返さない） | 公開（50秒に1回まで） |
| `line-api/lib/` | 共通処理 | Web遮断 |
| `line-api/private/` | 設定・投稿データ・写真 | Web遮断（Git管理外） |

`_config.yml` で `line-api/` `tests/` はGitHub Pagesに出力しません。

## 秘密情報の扱い

- Channel secret / アクセストークン / 管理パスワードは **サーバーの `line-api/private/config.local.php` にだけ** 保存（`setup.php` から本人が入力。パスワードはハッシュ化）。
- GitHub・フロントエンドJS・チャットには書かない。`.gitignore` で `line-api/private/*` を除外済み。
- アクセストークンを入力しない場合、サーバーがチャネルID＋シークレットから30日トークンを自動発行・更新します。
- LINEのユーザーIDは保存しません（HMACで匿名化した識別子のみ）。確定前の下書きの間だけ、自動確定通知のために一時保持します。

## 投稿の流れ（LINE側）

0. 初回だけクラブから案内された合言葉をそのままLINEで送る（「登録」は不要。未設定のあいだは誰も投稿できない）
1. 写真（1〜5枚）・文章を送る → 「下書き」に貯まる（初回だけ案内を返信）。下書きは非公開
2. 「送信」と送る → **即時掲載**し、掲載メッセージを1回返信
3. 送信を忘れても、最後の操作から1時間で自動確定→掲載（このときだけプッシュ通知を1通）
   - Webhookが来なくても動くよう `tick.php` を定期呼び出し（GitHub Actions 15分毎）＋公開API表示時にも予備実行
   - ロリポップのcronは5枠すべて既存サイトで使用中のため使わない
4. 「取消」で送信前の内容を破棄、「使い方」で説明を返信

## 迷惑投稿対策

- 会員登録制（合言葉）。未登録ユーザーの写真・文章は保存しない。合言葉の失敗は1人1日5回まで
- 管理画面「会員」で個別に投稿停止／再開
- 管理画面「設定」で自動掲載をOFFにすると、以後は承認待ちに入る（非常停止）
- 1人1日10件、1分間20操作まで。サイト全体で1日50件を超えた分は承認待ち
- 画像は形式・サイズ・画素数を検証し再エンコード。画像以外は拒否

文章は自動で項目に振り分けます（管理画面で修正可）：
`投稿者：〇〇` → 投稿者名、`11月20日` → 釣行日、`マダイ 3匹`（数＋匹/尾/本/kg/cm等）→ 魚種、その他 → コメント。

## 状態

`draft`（送信前・非公開）→ `published`（掲載中）/ `rejected`（非公開）。
`pending`（承認待ち）は自動掲載OFF・上限超過・旧仕様の投稿のみ。管理画面で承認するまで公開しません。
削除は確認チェック必須で、写真ファイルも消えます。

## セキュリティ

- 生ボディでの `x-line-signature` 検証（不一致は401）・`webhookEventId` で重複排除（再送対応）
- 画像は形式（JPEG/PNG/WebP）・サイズ（20MB）・画素数を検証し、GDで再エンコード（EXIF/GPS除去、向き補正、長辺2400px/1000px）。オリジナルは非公開領域に保管。保存名はランダム。
- 送信前・承認待ち・非公開の写真はURLを知っていても表示不可（管理者ログイン時のみ）。
- 管理画面：パスワード（12文字以上）・セッション（HttpOnly/Secure/SameSite=Strict、2時間で失効）・CSRFトークン・ログイン5回失敗で15分ロック・CSP・noindex
- 出力は全てエスケープ（XSS対策）。フロントは textContent で描画。

## 配備（ロリポップ）

1. `line-api/` フォルダを、釣りクラブ専用の公開フォルダへアップロード（`private/` の中身は空のまま）
2. `line-api/private/setup.key` に32文字以上のランダム文字列を置く
3. `https://(ホスト)/line-api/setup.php?key=(その文字列)` を開き、Channel ID・Channel secret・管理パスワードを入力
4. `https://(ホスト)/line-api/private/data/store.json` 等が **403** になることを確認
5. LINE Developers の Webhook URL に `https://(ホスト)/line-api/webhook.php` を設定し「検証」→「Webhookの利用」ON。応答メッセージ（自動応答）はOFF。
6. `js/catch-config.js` に `WSC_CATCH_API` と友だち追加URLを設定してGitHub Pagesへ反映

## テスト

```
python3 -I tests/test_line_api.py   # PHP 8.x と Pillow が必要。77項目
```
LINE APIへの通信は `tests/http-stub.php` で偽装するため、本番には一切接続しません。

<?php
/**
 * 初回設定（1回限り）
 * private/setup.key が存在し、URLの key が一致する場合だけ表示。
 * 管理者本人がLINEのチャネル情報と管理パスワードをブラウザで入力し、サーバー内の非公開ファイルに保存する。
 * 保存後は setup.key を削除し、このページは二度と使えなくなる。
 */
declare(strict_types=1);
define('WSC_APP', true);
require __DIR__ . '/lib/bootstrap.php';

wsc_send_security_headers(true);
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');

$keyFile = wsc_path('setup.key');
$given = (string)($_GET['key'] ?? $_POST['key'] ?? '');
$expected = is_file($keyFile) ? trim((string)file_get_contents($keyFile)) : '';
if (wsc_is_configured() || strlen($expected) < 32 || !hash_equals($expected, $given)) { http_response_code(404); echo 'Not Found'; exit; }

$checks = [
    'PHP 8.0以上' => PHP_VERSION_ID >= 80000,
    'cURL' => function_exists('curl_init'),
    'GD（写真の変換）' => function_exists('imagecreatefromstring') && function_exists('imagejpeg'),
    'mbstring' => function_exists('mb_substr'),
    'fileinfo' => class_exists('finfo'),
    'EXIF（写真の向き補正・任意）' => function_exists('exif_read_data'),
    'HTTPS接続' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
];
try { wsc_ensure_dirs(); $checks['非公開フォルダへの書き込み'] = is_writable(wsc_path('data')); } catch (Throwable $e) { $checks['非公開フォルダへの書き込み'] = false; }

$error = '';
$done = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $channelId = trim((string)($_POST['channel_id'] ?? ''));
    $secret = trim((string)($_POST['channel_secret'] ?? ''));
    $token = trim((string)($_POST['channel_access_token'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');
    if (!preg_match('/^\d{6,20}$/', $channelId)) $error = 'Channel ID は数字で入力してください。';
    elseif (!preg_match('/^[0-9a-f]{32}$/', $secret)) $error = 'Channel secret は32文字の英数字です。';
    elseif ($token !== '' && !preg_match('/^[A-Za-z0-9+\/=._\-]{20,400}$/', $token)) $error = 'チャネルアクセストークンの形式が正しくありません（空欄でも動きます）。';
    elseif (mb_strlen($pw) < 12) $error = '管理パスワードは12文字以上にしてください。';
    elseif (!hash_equals($pw, $pw2)) $error = '確認用パスワードが一致しません。';
    else {
        $config = [
            'channel_id' => $channelId,
            'channel_secret' => $secret,
            'channel_access_token' => $token,
            'admin_password_hash' => password_hash($pw, PASSWORD_DEFAULT),
            'allowed_origins' => ['https://oosaka0123-sudo.github.io'],
            'public_base_url' => rtrim(wsc_base_url(), '/'),
            'site_catch_url' => 'https://oosaka0123-sudo.github.io/wakayama-saika-club/catch/',
        ];
        $php = "<?php\n// 自動生成（setup.php）。GitHubに置かない・共有しない。\nreturn " . var_export($config, true) . ";\n";
        $tmp = wsc_config_file() . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === strlen($php) && (@chmod($tmp, 0600) || true) && rename($tmp, wsc_config_file())) {
            @unlink($keyFile);
            $info = wsc_bot_info();
            $done = $info ? ('LINEとの接続を確認しました：' . (string)($info['displayName'] ?? '') . '（' . (string)($info['basicId'] ?? '') . '）')
                          : '設定は保存しましたが、LINEとの接続確認に失敗しました。Channel ID / secret を確認してください（管理画面からは変更できないため、担当者に連絡してください）。';
        } else {
            @unlink($tmp);
            $error = '設定ファイルを保存できませんでした。フォルダの書き込み権限を確認してください。';
        }
    }
}
?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>初期設定｜釣果投稿</title>
<style>body{font-family:"Hiragino Kaku Gothic ProN","Noto Sans JP",Meiryo,sans-serif;max-width:640px;margin:0 auto;padding:20px;color:#12232e;line-height:1.7}h1{font-size:22px}label{display:block;font-weight:700;margin-top:14px}input{width:100%;padding:10px;font:inherit;border:1px solid #b9c5cc;box-sizing:border-box}button{margin-top:18px;padding:12px 20px;font:inherit;font-weight:700;background:#fa7916;border:0;cursor:pointer}.ok{color:#16612b}.ng{color:#b42318}.box{background:#f3f5f5;padding:12px 16px}.err{background:#fde8e6;padding:10px 14px}</style></head><body>
<h1>和歌山雑賀クラブ 釣果投稿 — 初期設定</h1>
<?php if ($done !== null): ?>
<p class="box"><?= wsc_h($done) ?></p>
<p>このページは無効になりました。<a href="admin/">管理画面へ</a></p>
<?php else: ?>
<div class="box"><b>サーバー確認</b><ul><?php foreach ($checks as $name => $ok): ?><li class="<?= $ok ? 'ok' : 'ng' ?>"><?= $ok ? '✓' : '✗' ?> <?= wsc_h($name) ?></li><?php endforeach; ?></ul></div>
<?php if ($error): ?><p class="err"><?= wsc_h($error) ?></p><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="key" value="<?= wsc_h($given) ?>">
<label>Channel ID（LINE Developers → チャネル基本設定）<input name="channel_id" inputmode="numeric" required></label>
<label>Channel secret（同じ画面）<input name="channel_secret" required></label>
<label>チャネルアクセストークン（長期・任意。空欄なら自動発行）<input name="channel_access_token"></label>
<label>管理画面のパスワード（12文字以上）<input type="password" name="password" autocomplete="new-password" required></label>
<label>パスワード（確認）<input type="password" name="password2" autocomplete="new-password" required></label>
<button type="submit">保存する</button>
</form>
<p>入力した値はこのサーバーの非公開フォルダにだけ保存されます。</p>
<?php endif; ?>
</body></html>

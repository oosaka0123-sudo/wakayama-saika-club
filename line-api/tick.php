<?php
/**
 * 定期実行用：送信忘れの下書き（最終操作から1時間）を確定・掲載する。
 * 呼び出しには X-WSC-Tick-Token ヘッダーが必要（GitHub Actions の Secrets から送る）。
 * サーバーにはトークンのSHA-256ハッシュだけを非公開フォルダ（tick.key）に置く。情報は返さない。
 */
declare(strict_types=1);
define('WSC_APP', true);
require __DIR__ . '/lib/bootstrap.php';
header('Cache-Control: no-store');
if (!wsc_is_configured()) wsc_json(503, ['ok' => false]);
$keyFile = wsc_path('tick.key');
$expected = is_file($keyFile) ? strtolower(trim((string)file_get_contents($keyFile))) : '';
$given = (string)($_SERVER['HTTP_X_WSC_TICK_TOKEN'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $expected) || $given === '' || !hash_equals($expected, hash('sha256', $given))) {
    usleep(200000);
    wsc_json(403, ['ok' => false]);
}
try {
    wsc_tick();
    wsc_json(200, ['ok' => true]);
} catch (Throwable $e) {
    wsc_log('tick error: ' . $e->getMessage());
    wsc_json(500, ['ok' => false]);
}

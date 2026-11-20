<?php
/** 定期実行用：送信忘れの下書き（最終操作から1時間）を承認待ちへ確定する。情報は返さない。 */
declare(strict_types=1);
define('WSC_APP', true);
require __DIR__ . '/lib/bootstrap.php';
header('Cache-Control: no-store');
if (!wsc_is_configured()) wsc_json(503, ['ok' => false]);
try {
    wsc_tick();
    wsc_json(200, ['ok' => true]);
} catch (Throwable $e) {
    wsc_log('tick error: ' . $e->getMessage());
    wsc_json(500, ['ok' => false]);
}

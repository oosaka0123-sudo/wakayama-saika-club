<?php
/** 写真配信：掲載中の投稿の写真だけを一般公開。未承認写真は管理者ログイン時のみ表示。 */
declare(strict_types=1);
define('WSC_APP', true);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';

$id = (string)($_GET['p'] ?? '');
$index = (int)($_GET['i'] ?? -1);
$size = (string)($_GET['s'] ?? 'm');
if (!wsc_valid_id($id) || $index < 0 || $index >= WSC_MAX_PHOTOS || !in_array($size, ['m', 'l'], true)) {
    http_response_code(404); exit;
}
$state = wsc_read_state();
$post = $state['posts'][$id] ?? null;
$photo = $post['photos'][$index] ?? null;
if (!$post || !$photo) { http_response_code(404); exit; }

$public = ($post['status'] ?? '') === 'published' && empty($photo['hidden']);
if (!$public) {
    wsc_session_start();
    if (!wsc_admin_logged_in()) { http_response_code(404); exit; }
}
$path = wsc_photo_path((string)$photo['file'], $size);
if ($path === null) { http_response_code(404); exit; }

wsc_send_security_headers();
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header($public ? 'Cache-Control: public, max-age=600' : 'Cache-Control: private, no-store');
header('Cross-Origin-Resource-Policy: cross-origin');
readfile($path);

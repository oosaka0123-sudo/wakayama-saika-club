<?php
/** 公開用JSON API：承認済み（published）の投稿だけを返す。LINE識別子などは含めない。 */
declare(strict_types=1);
define('WSC_APP', true);
require __DIR__ . '/lib/bootstrap.php';

$origins = ['https://oosaka0123-sudo.github.io'];
try { $origins = (array)wsc_config()['allowed_origins']; } catch (Throwable $e) {}
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$cors = [];
if ($origin !== '' && in_array($origin, $origins, true)) {
    $cors = ['Access-Control-Allow-Origin: ' . $origin, 'Vary: Origin', 'Access-Control-Allow-Methods: GET, OPTIONS'];
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); foreach ($cors as $h) header($h); exit; }
if ($method !== 'GET' && $method !== 'HEAD') wsc_json(405, ['error' => 'Method not allowed'], $cors);

try {
    if (!wsc_is_configured()) wsc_json(200, ['posts' => [], 'status' => 'preparing'], array_merge($cors, ['Cache-Control: no-store']));
    $state = wsc_read_state();
    $published = array_filter($state['posts'], function ($p) { return ($p['status'] ?? '') === 'published'; });
    uasort($published, function ($a, $b) { return strcmp(wsc_sort_key($b), wsc_sort_key($a)); });
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
    $base = wsc_base_url();
    $posts = [];
    foreach (array_slice($published, 0, $limit) as $p) $posts[] = wsc_public_post($p, $base);
    wsc_json(200, ['posts' => $posts, 'count' => count($posts), 'generated_at' => wsc_now()],
        array_merge($cors, ['Cache-Control: public, max-age=60']));
} catch (Throwable $e) {
    wsc_log('feed error: ' . $e->getMessage());
    wsc_json(503, ['error' => 'Temporarily unavailable'], array_merge($cors, ['Cache-Control: no-store']));
}

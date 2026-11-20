<?php
declare(strict_types=1);
define('WSC_API_INTERNAL', true);
require __DIR__.'/lib.php';
try {
    $config = wsc_config();
    $base = rtrim((string)($config['public_base_url'] ?? ''), '/');
    if (!preg_match('/^https:\/\/[^\s]+$/', $base)) throw new RuntimeException('Public HTTPS base URL is required.');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && $origin === (string)($config['github_origin'] ?? '')) {
        header('Access-Control-Allow-Origin: '.$origin);
        header('Vary: Origin');
    }
    header('Cache-Control: public, max-age=30');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $posts = wsc_state(function (array &$state) { return $state['posts']; });
    $public = [];
    foreach (array_slice($posts, 0, 100) as $post) {
        if (!isset($post['image'], $post['id']) || !preg_match('/^uploads\/[a-f0-9]{24}\.(jpg|png|webp)$/', $post['image'])) continue;
        $public[] = [
            'id'=>$post['id'],
            'image'=>$base.'/'.$post['image'],
            'date'=>$post['date'] ?? '',
            'author'=>$post['author'] ?? '',
            'comment'=>$post['comment'] ?? ''
        ];
    }
    echo json_encode(['posts'=>$public], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    wsc_response(503, ['error'=>'釣果システムは準備中です']);
}

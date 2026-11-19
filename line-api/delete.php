<?php
declare(strict_types=1);
define('WSC_API_INTERNAL', true);
require __DIR__.'/lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wsc_response(405, ['error'=>'Method not allowed']);
try {
    $config = wsc_config();
    $expected = (string)($config['admin_token'] ?? '');
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($expected === '' || !hash_equals('Bearer '.$expected, $header)) wsc_response(403, ['error'=>'Not authorized']);
    $req = json_decode(file_get_contents('php://input'), true);
    $id = is_array($req) ? (string)($req['id'] ?? '') : '';
    if (!preg_match('/^[a-f0-9]{20}$/', $id)) wsc_response(400, ['error'=>'Invalid post ID']);
    $removed = wsc_state(function (array &$state) use ($id) {
        foreach ($state['posts'] as $i=>$post) {
            if (($post['id'] ?? '') === $id) {
                $entry = $post['image'];
                array_splice($state['posts'], $i, 1);
                return $entry;
            }
        }
        return '';
    });
    if ($removed && preg_match('/^uploads\/[a-f0-9]{24}\.(jpg|png|webp)$/', $removed)) {
        @unlink(__DIR__.'/'.$removed);
    }
    wsc_response(200, ['deleted'=>(bool)$removed]);
} catch (Throwable $e) {
    wsc_response(503, ['error'=>'Temporary failure']);
}

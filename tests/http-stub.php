<?php
// テスト専用：LINE APIへの通信を偽装し、呼び出しを記録する（本番には配置しない）
function wsc_test_http_stub(string $method, string $url, array $headers, ?string $body): array {
    $log = getenv('WSC_TEST_HTTP_LOG') ?: sys_get_temp_dir() . '/wsc-http.log';
    file_put_contents($log, json_encode(['m' => $method, 'u' => $url, 'b' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    if (strpos($url, '/v2/oauth/accessToken') !== false) return [200, json_encode(['access_token' => 'test-token', 'expires_in' => 2592000])];
    if (preg_match('#/message/(\d+)/content$#', $url, $m)) {
        $fixture = getenv('WSC_TEST_FIXTURES') . '/' . $m[1] . '.jpg';
        return is_file($fixture) ? [200, file_get_contents($fixture)] : [404, ''];
    }
    if (strpos($url, '/v2/bot/info') !== false) return [200, json_encode(['displayName' => '和歌山雑賀クラブ 釣果投稿', 'basicId' => '@test'])];
    return [200, '{}'];
}

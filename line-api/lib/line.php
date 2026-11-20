<?php
/** LINE Messaging API 呼び出し（署名検証・トークン・返信・画像取得） */
declare(strict_types=1);
if (!defined('WSC_APP')) { http_response_code(403); exit; }

/** 生のリクエストボディで x-line-signature を検証する。 */
function wsc_verify_signature(string $rawBody, string $signature, string $channelSecret): bool
{
    if ($channelSecret === '' || $signature === '') return false;
    $expected = base64_encode(hash_hmac('sha256', $rawBody, $channelSecret, true));
    return hash_equals($expected, $signature);
}

/** @return array{0:int,1:string} */
function wsc_http(string $method, string $url, array $headers, ?string $body = null, int $timeout = 15, int $maxBytes = 0): array
{
    if (getenv('WSC_TEST_HTTP_STUB')) {
        // テスト時はネットワークに出ない
        return wsc_test_http_stub($method, $url, $headers, $body);
    }
    $ch = curl_init($url);
    if (!$ch) return [0, ''];
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    if ($maxBytes > 0) {
        $opts[CURLOPT_NOPROGRESS] = false;
        $opts[CURLOPT_PROGRESSFUNCTION] = function ($r, $dlTotal, $dl) use ($maxBytes) { return $dl > $maxBytes ? 1 : 0; };
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($res) ? $res : ''];
}

/** 設定に長期トークンがあればそれを使い、なければチャネルID＋シークレットで短期トークン（30日）を発行・キャッシュ。 */
function wsc_access_token(bool $forceRefresh = false): string
{
    $config = wsc_config();
    $static = trim((string)$config['channel_access_token']);
    if ($static !== '') return $static;
    if (!$forceRefresh) {
        $cached = wsc_state(function (array &$s) { return $s['token']; }, false);
        if (is_array($cached) && !empty($cached['value']) && (int)($cached['expires'] ?? 0) > time() + 86400) {
            return (string)$cached['value'];
        }
    }
    [$code, $res] = wsc_http('POST', 'https://api.line.me/v2/oauth/accessToken',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'client_credentials', 'client_id' => (string)$config['channel_id'], 'client_secret' => (string)$config['channel_secret']]));
    $data = json_decode($res, true);
    if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
        wsc_log('token issue failed: HTTP ' . $code);
        throw new RuntimeException('Cannot obtain access token');
    }
    $value = (string)$data['access_token'];
    $expires = time() + (int)($data['expires_in'] ?? 2592000);
    wsc_state(function (array &$s) use ($value, $expires) { $s['token'] = ['value' => $value, 'expires' => $expires]; });
    return $value;
}

function wsc_line_api(string $path, array $payload): bool
{
    try {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = wsc_access_token($attempt === 1);
            [$code, $res] = wsc_http('POST', 'https://api.line.me' . $path,
                ['Content-Type: application/json', 'Authorization: Bearer ' . $token], $body, 10);
            if ($code === 200) return true;
            if ($code !== 401) break;
        }
        wsc_log('LINE API ' . $path . ' failed: HTTP ' . $code . ' ' . substr($res, 0, 200));
    } catch (Throwable $e) {
        wsc_log('LINE API ' . $path . ' error: ' . $e->getMessage());
    }
    return false;
}

function wsc_text_message(string $text, array $quickReplies = []): array
{
    $m = ['type' => 'text', 'text' => $text];
    if ($quickReplies) {
        $items = [];
        foreach ($quickReplies as $label => $send) {
            $items[] = ['type' => 'action', 'action' => ['type' => 'message', 'label' => $label, 'text' => $send]];
        }
        $m['quickReply'] = ['items' => $items];
    }
    return $m;
}

/** 返信（無料・通数にカウントされない）。失敗しても投稿データには影響させない。 */
function wsc_reply(string $replyToken, array $messages): bool
{
    if ($replyToken === '' || !$messages) return false;
    return wsc_line_api('/v2/bot/message/reply', ['replyToken' => $replyToken, 'messages' => array_slice($messages, 0, 5)]);
}

/** プッシュ（無料プランの月間通数にカウント）。下書きの自動確定通知でのみ使う。 */
function wsc_push(string $lineUserId, array $messages): bool
{
    if ($lineUserId === '' || !$messages) return false;
    return wsc_line_api('/v2/bot/message/push', ['to' => $lineUserId, 'messages' => array_slice($messages, 0, 5)]);
}

/** 画像メッセージの本体を取得（最大20MB）。 */
function wsc_fetch_content(string $messageId): ?string
{
    if (!preg_match('/^\d{1,30}$/', $messageId)) return null;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = wsc_access_token($attempt === 1);
        [$code, $bytes] = wsc_http('GET', 'https://api-data.line.me/v2/bot/message/' . $messageId . '/content',
            ['Authorization: Bearer ' . $token], null, 30, 20 * 1024 * 1024);
        if ($code === 200 && strlen($bytes) > 0) return $bytes;
        if ($code !== 401) break;
    }
    wsc_log('content fetch failed: HTTP ' . $code);
    return null;
}

function wsc_bot_info(): ?array
{
    try {
        [$code, $res] = wsc_http('GET', 'https://api.line.me/v2/bot/info', ['Authorization: Bearer ' . wsc_access_token()], null, 10);
        $data = json_decode($res, true);
        return $code === 200 && is_array($data) ? $data : null;
    } catch (Throwable $e) {
        return null;
    }
}

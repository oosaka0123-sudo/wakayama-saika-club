<?php
declare(strict_types=1);
define('WSC_API_INTERNAL', true);
require __DIR__.'/lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wsc_response(405, ['error'=>'Method not allowed']);
try {
    $config = wsc_config();
    $secret = (string)($config['channel_secret'] ?? '');
    $token = (string)($config['channel_access_token'] ?? '');
    if ($secret === '' || $token === '') wsc_response(503, ['error'=>'Not configured']);
    $raw = file_get_contents('php://input');
    $given = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
    if (!is_string($raw) || !is_string($given) ||
        !hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), $given)) {
        wsc_response(403, ['error'=>'Signature verification failed']);
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !isset($payload['events']) || !is_array($payload['events'])) {
        wsc_response(400, ['error'=>'Bad request']);
    }
    foreach ($payload['events'] as $event) {
        if (!is_array($event)) continue;
        $id = (string)($event['webhookEventId'] ?? '');
        $source = $event['source'] ?? [];
        $user = is_array($source) ? (string)($source['userId'] ?? '') : '';
        if ($user === '' || $id === '' || strlen($id)>128 || wsc_event_seen($id)) continue;
        $members = $config['allowed_user_ids'] ?? [];
        if (!is_array($members) || !in_array($user, $members, true)) {
            // Collect friend additions privately for one-time member enrollment.
            if (($event['type'] ?? '') === 'follow') {
                wsc_state(function (array &$state) use ($user, $id) {
                    $state['candidates'][$user] = date(DATE_ATOM);
                    wsc_mark_seen($state, $id);
                });
            }
            continue;
        }
        $message = $event['message'] ?? [];
        $kind = is_array($message) ? (string)($message['type'] ?? '') : '';
        if (($event['type'] ?? '') !== 'message') continue;
        if ($kind === 'text') {
            $parts = wsc_text_parts((string)($message['text'] ?? ''));
            wsc_state(function (array &$state) use ($id, $user, $parts) {
                if (isset($state['seen'][$id])) return;
                $attached = false;
                foreach ($state['posts'] as &$post) {
                    if (($post['_user'] ?? '') === $user && time() - (int)($post['_created'] ?? 0) < 900) {
                        if ($parts['author'] !== '') $post['author'] = $parts['author'];
                        if ($parts['comment'] !== '') $post['comment'] = $parts['comment'];
                        $attached = true;
                        break;
                    }
                }
                unset($post);
                if (!$attached) $state['drafts'][$user] = ['parts'=>$parts,'time'=>time()];
                wsc_mark_seen($state, $id);
            });
            continue;
        }
        if ($kind !== 'image') continue;
        $messageId = (string)($message['id'] ?? '');
        if (!preg_match('/^\d{1,30}$/', $messageId)) continue;
        $curl = curl_init('https://api-data.line.me/v2/bot/message/'.$messageId.'/content');
        if (!$curl) continue;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false
        ]);
        $bytes = curl_exec($curl);
        $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($bytes) || $code !== 200 || strlen($bytes)>10*1024*1024 || strlen($bytes)<128) continue;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extensions = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
        if (!isset($extensions[$mime])) continue;
        $name = bin2hex(random_bytes(12)).'.'.$extensions[$mime];
        $filepath = __DIR__.'/uploads/'.$name;
        if (file_put_contents($filepath, $bytes, LOCK_EX) !== strlen($bytes)) continue;
        chmod($filepath, 0644);
        $stored = wsc_state(function (array &$state) use ($id, $user, $name) {
            if (isset($state['seen'][$id])) return false;
            $draft = $state['drafts'][$user] ?? [];
            $parts = isset($draft['time']) && time() - (int)$draft['time'] < 900
                ? ($draft['parts'] ?? []) : [];
            unset($state['drafts'][$user]);
            $state['posts'][] = [
                'id'=>bin2hex(random_bytes(10)),
                'image'=>'uploads/'.$name,
                'date'=>date(DATE_ATOM),
                'author'=>(string)($parts['author'] ?? ''),
                'comment'=>(string)($parts['comment'] ?? ''),
                '_user'=>$user,
                '_created'=>time()
            ];
            usort($state['posts'], function($a,$b){ return strcmp($b['date'], $a['date']); });
            wsc_mark_seen($state, $id);
            return true;
        });
        if (!$stored) @unlink($filepath);
    }
    wsc_response(200, ['ok'=>true]);
} catch (Throwable $e) {
    wsc_response(503, ['error'=>'Temporary processing failure']);
}

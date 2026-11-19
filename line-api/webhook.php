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
        $message = $event['message'] ?? [];
        $kind = is_array($message) ? (string)($message['type'] ?? '') : '';
        $members = $config['allowed_user_ids'] ?? [];
        $legacyAllowed = is_array($members) && in_array($user, $members, true);
        $registered = wsc_state(function (array &$state) use ($user) {
            return !empty($state['allowed'][$user]);
        });
        if (!$legacyAllowed && !$registered) {
            // A friend addition or voluntary "登録：名前" creates a PRIVATE request,
            // not a public report. The administrator authorizes the member once.
            $eventType = (string)($event['type'] ?? '');
            $nickname = '';
            if ($eventType === 'message' && $kind === 'text') {
                $text = trim((string)($message['text'] ?? ''));
                if (preg_match('/^登録\s*[:：]\s*(.{1,40})$/u', $text, $matches)) {
                    $nickname = mb_substr(trim($matches[1]), 0, 40);
                }
            }
            if ($eventType === 'follow' || $nickname !== '') {
                wsc_state(function (array &$state) use ($user, $id, $nickname) {
                    if (isset($state['seen'][$id])) return;
                    $existing = $state['candidates'][$user] ?? [];
                    $previous = is_array($existing) ? (string)($existing['name'] ?? '') : '';
                    $state['candidates'][$user] = [
                        'name'=>$nickname !== '' ? $nickname : $previous,
                        'seen_at'=>date(DATE_ATOM)
                    ];
                    wsc_mark_seen($state, $id);
                });
            }
            continue;
        }
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
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) continue;
        $clean = wsc_public_photo($bytes);
        if ($clean === null) continue; // Reject if re-encoding fails: never expose unfiltered metadata.
        $name = bin2hex(random_bytes(12)).'.jpg';
        $filepath = __DIR__.'/uploads/'.$name;
        if (file_put_contents($filepath, $clean, LOCK_EX) !== strlen($clean)) continue;
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

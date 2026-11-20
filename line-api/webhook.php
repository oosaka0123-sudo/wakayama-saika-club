<?php
/**
 * LINE Webhook 受付
 * 1) 生のボディで署名検証 → 失敗は 401 で拒否
 * 2) webhookEventId で重複排除（LINEの再送にも対応）
 * 3) 写真・文章を「下書き」に貯め、「送信」で承認待ち(pending)に確定
 */
declare(strict_types=1);
define('WSC_APP', true);
require __DIR__ . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wsc_json(405, ['error' => 'Method not allowed']);

$raw = file_get_contents('php://input');   // 署名検証前に一切加工しない
if (!is_string($raw) || strlen($raw) > 1024 * 1024) wsc_json(413, ['error' => 'Payload too large']);

try {
    $config = wsc_config();
} catch (Throwable $e) {
    wsc_json(503, ['error' => 'Not configured']);
}

$signature = (string)($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '');
if (!wsc_verify_signature($raw, $signature, (string)$config['channel_secret'])) {
    wsc_log('rejected: invalid signature from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    wsc_json(401, ['error' => 'Invalid signature']);
}

$payload = json_decode($raw, true);
if (!is_array($payload) || !isset($payload['events']) || !is_array($payload['events'])) {
    wsc_json(400, ['error' => 'Bad request']);
}

$failed = false;
foreach ($payload['events'] as $event) {
    if (!is_array($event)) continue;
    try {
        wsc_handle_event($event);
    } catch (Throwable $e) {
        $failed = true;
        wsc_log('event error: ' . $e->getMessage());
    }
}
// 処理に失敗したイベントがあれば 500 を返し、LINEの再送（有効時）で再処理させる。重複は eventId で防ぐ。
wsc_json($failed ? 500 : 200, ['ok' => !$failed]);

function wsc_is_command(string $text, array $commands): bool
{
    $t = trim(mb_convert_kana($text, 'as'));
    $t = trim($t, " 　。.!！");
    return in_array($t, $commands, true);
}

function wsc_handle_event(array $event): void
{
    $eventId = (string)($event['webhookEventId'] ?? '');
    if ($eventId === '' || strlen($eventId) > 64 || !preg_match('/^[A-Za-z0-9_\-]+$/', $eventId)) return;
    $source = is_array($event['source'] ?? null) ? $event['source'] : [];
    if (($source['type'] ?? '') !== 'user') return;        // グループ・トークルームは対象外
    $lineUser = (string)($source['userId'] ?? '');
    if (!preg_match('/^U[0-9a-f]{32}$/', $lineUser)) return;
    $userKey = wsc_user_key($lineUser);
    $replyToken = (string)($event['replyToken'] ?? '');
    $type = (string)($event['type'] ?? '');
    $message = is_array($event['message'] ?? null) ? $event['message'] : [];
    $kind = (string)($message['type'] ?? '');

    $alreadySeen = wsc_state(function (array &$s) use ($eventId) { return isset($s['seen'][$eventId]); }, false);
    if ($alreadySeen) return;

    // 画像はロックの外で取得・保存（時間がかかるため）
    $photo = null; $photoRejected = false;
    if ($type === 'message' && $kind === 'image') {
        $bytes = wsc_fetch_content((string)($message['id'] ?? ''));
        if ($bytes === null) throw new RuntimeException('image download failed');   // 再送で再試行
        $photo = wsc_store_photo($bytes);
        if ($photo === null) $photoRejected = true;
    }

    $replies = [];
    $pushTo = [];
    wsc_state(function (array &$s) use ($eventId, $type, $kind, $message, $userKey, $lineUser, &$photo, $photoRejected, &$replies, &$pushTo) {
        if (isset($s['seen'][$eventId])) {             // 並行実行で先に処理済み
            if ($photo) wsc_delete_photo_files($photo['file']);
            $photo = null;
            return;
        }
        wsc_mark_seen($s, $eventId);

        // 自分の放置下書きは返信で、他人の放置下書きはプッシュで受付通知
        $own = $s['drafts'][$userKey] ?? null;
        if ($own && isset($s['posts'][$own]) && (int)$s['posts'][$own]['last_activity'] <= time() - WSC_DRAFT_IDLE_SECONDS
            && !($type === 'message' && $kind === 'text' && wsc_is_command((string)($message['text'] ?? ''), WSC_CMD_CANCEL))) {
            if (wsc_submit_draft($s, $userKey)) $replies = array_merge($replies, wsc_msg_received());
        }
        $pushTo = array_values(array_diff(wsc_sweep_idle_drafts($s), [$lineUser]));

        if ($type === 'follow') { $replies = array_merge($replies, wsc_msg_welcome()); return; }
        if ($type === 'unfollow') { wsc_cancel_draft($s, $userKey); return; }
        if ($type !== 'message') return;

        if ($kind === 'text') {
            $text = (string)($message['text'] ?? '');
            if (wsc_is_command($text, WSC_CMD_SUBMIT)) {
                $done = wsc_submit_draft($s, $userKey);
                $replies = array_merge($replies, $done ? wsc_msg_received() : wsc_msg_nothing_to_submit());
                return;
            }
            if (wsc_is_command($text, WSC_CMD_CANCEL)) {
                wsc_cancel_draft($s, $userKey);
                $replies = array_merge($replies, wsc_msg_cancelled());
                return;
            }
            if (wsc_is_command($text, WSC_CMD_HELP)) { $replies = array_merge($replies, wsc_msg_help()); return; }
        } elseif ($kind !== 'image') {
            if ($kind !== 'sticker') $replies = array_merge($replies, wsc_msg_unsupported());
            return;
        }
        if ($kind === 'image' && $photoRejected) { $replies = array_merge($replies, wsc_msg_photo_rejected()); return; }

        // 下書きへ追加
        $draftId = $s['drafts'][$userKey] ?? null;
        $isNew = false;
        if (!$draftId || !isset($s['posts'][$draftId])) {
            if (wsc_submits_today($s, $userKey) >= WSC_DAILY_SUBMIT_LIMIT) {
                if ($photo) wsc_delete_photo_files($photo['file']);
                $photo = null;
                $replies = array_merge($replies, wsc_msg_daily_limit());
                return;
            }
            $draft = wsc_new_draft($userKey, $lineUser);
            $s['posts'][$draft['id']] = $draft;
            $s['drafts'][$userKey] = $draft['id'];
            $draftId = $draft['id'];
            $isNew = true;
        }
        $post = &$s['posts'][$draftId];
        $post['last_activity'] = time();
        $post['updated_at'] = wsc_now();
        $post['_line_user'] = $lineUser;
        if ($kind === 'text') {
            $post['texts'][] = wsc_clip((string)($message['text'] ?? ''), 1000);
            if (count($post['texts']) > 20) $post['texts'] = array_slice($post['texts'], -20);
        } else {
            if (count($post['photos']) >= WSC_MAX_PHOTOS) {
                wsc_delete_photo_files($photo['file']);
                $photo = null;
                if (empty($post['notice_sent']['limit'])) {
                    $post['notice_sent']['limit'] = true;
                    $replies = array_merge($replies, wsc_msg_photo_limit());
                }
                return;
            }
            $post['photos'][] = ['file' => $photo['file'], 'w' => $photo['w'], 'h' => $photo['h'], 'hidden' => false, 'received_at' => wsc_now()];
        }
        if ($isNew) $replies = array_merge($replies, wsc_msg_draft_started());
    });

    // 保存が完了してから返信（返信に失敗しても投稿は残る）
    if ($replies) wsc_reply((string)($event['replyToken'] ?? ''), $replies);
    foreach ($pushTo as $to) wsc_push($to, wsc_msg_received());
}

<?php
/**
 * LINE Webhook 受付
 * 1) 生のボディで署名検証 → 失敗は 401 で拒否
 * 2) webhookEventId で重複排除（LINEの再送にも対応）
 * 3) 未登録者は合言葉だけで登録。登録後は［名前（任意）→写真→コメント］で、コメント送信時に自動掲載
 *    （コメントなしは「送信する」ボタン。写真が1枚もない投稿は掲載しない）
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

function wsc_msg_for(array $post): array
{
    return ($post['status'] ?? '') === 'published' ? wsc_msg_published() : wsc_msg_received_pending();
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
    $type = (string)($event['type'] ?? '');
    $message = is_array($event['message'] ?? null) ? $event['message'] : [];
    $kind = (string)($message['type'] ?? '');

    $pre = wsc_state(function (array &$s) use ($eventId, $userKey) {
        return ['seen' => isset($s['seen'][$eventId]), 'member' => wsc_is_member($s, $userKey)];
    }, false);
    if ($pre['seen']) return;

    // 画像は会員のときだけ、ロックの外で取得・保存（会員以外の写真は一切保存しない）
    $photo = null; $photoRejected = false;
    if ($type === 'message' && $kind === 'image' && $pre['member']) {
        $bytes = wsc_fetch_content((string)($message['id'] ?? ''));
        if ($bytes === null) throw new RuntimeException('image download failed');   // 再送で再試行
        $photo = wsc_store_photo($bytes);
        if ($photo === null) $photoRejected = true;
    }

    $replies = [];
    $pushTo = [];
    wsc_state(function (array &$s) use ($eventId, $type, $kind, $message, $userKey, $lineUser, &$photo, $photoRejected, &$replies, &$pushTo) {
        $dropPhoto = function () use (&$photo) { if ($photo) wsc_delete_photo_files($photo['file']); $photo = null; };
        if (isset($s['seen'][$eventId])) { $dropPhoto(); return; }   // 並行実行で先に処理済み
        wsc_mark_seen($s, $eventId);

        // 他の人の放置下書きは自動確定してプッシュ通知
        foreach (wsc_sweep_idle_drafts($s) as $n) if ($n[0] !== $lineUser) $pushTo[] = $n;

        if ($type === 'follow') { $replies = wsc_msg_welcome(); return; }
        if ($type === 'unfollow') { wsc_cancel_draft($s, $userKey); return; }
        if ($type !== 'message') return;

        // 連打・荒らし対策（1分あたりの操作数）
        if (wsc_rate_limited($s, $userKey)) {
            $dropPhoto();
            if (empty($s['rate_notice'][$userKey]) || $s['rate_notice'][$userKey] < time() - 600) {
                $s['rate_notice'][$userKey] = time();
                $replies = wsc_msg_too_fast();
            }
            return;
        }

        $text = $kind === 'text' ? (string)($message['text'] ?? '') : '';

        // 初回登録は合言葉だけでOK。旧方式「登録 合言葉」も受け付ける。
        // 登録済み会員の普通のコメントは、合言葉として解釈しない。
        if ($kind === 'text' && !wsc_is_member($s, $userKey)
            && !wsc_is_command($text, WSC_CMD_HELP)
            && !wsc_is_command($text, WSC_CMD_SUBMIT)
            && !wsc_is_command($text, WSC_CMD_CANCEL)
            && trim($text) !== '' && !preg_match('/\\R/u', $text) && mb_strlen($text) <= 100) {
            $reg = wsc_parse_registration($text) ?? ['pass' => trim($text), 'name' => ''];
            if (wsc_is_blocked($s, $userKey)) { $replies = wsc_msg_blocked(); return; }
            if (wsc_is_member($s, $userKey)) { $replies = wsc_msg_registered(); return; }
            if (!wsc_passphrase_set($s)) { $replies = wsc_msg_registration_closed(); return; }
            $today = date('Y-m-d');
            $fails = (int)($s['regfail'][$today][$userKey] ?? 0);
            if ($fails >= WSC_REG_FAIL_LIMIT) { $replies = wsc_msg_wrong_passphrase(true); return; }
            if (password_verify(wsc_normalize_passphrase($reg['pass']), (string)wsc_setting($s, 'passphrase_hash', ''))) {
                $s['members'][$userKey] = ['registered_at' => wsc_now(), 'name' => $reg['name'], 'blocked' => false];
                $replies = wsc_msg_registered();
            } else {
                $s['regfail'] = [$today => (array)($s['regfail'][$today] ?? [])];
                $s['regfail'][$today][$userKey] = $fails + 1;
                $replies = wsc_msg_wrong_passphrase($fails + 1 >= WSC_REG_FAIL_LIMIT);
            }
            return;
        }

        if ($kind === 'text' && wsc_is_command($text, WSC_CMD_HELP)) { $replies = wsc_msg_help(); return; }

        // ここから先は会員のみ
        if (!wsc_is_member($s, $userKey)) {
            $dropPhoto();
            if ($kind === 'sticker') return;
            $replies = wsc_is_blocked($s, $userKey) ? wsc_msg_blocked() : wsc_msg_need_registration();
            return;
        }

        // 自分の放置下書き（1時間）は先に確定
        $own = $s['drafts'][$userKey] ?? null;
        if ($own && isset($s['posts'][$own]) && (int)$s['posts'][$own]['last_activity'] <= time() - WSC_DRAFT_IDLE_SECONDS
            && !($kind === 'text' && wsc_is_command($text, WSC_CMD_CANCEL))) {
            if ($done = wsc_finalize_idle_draft($s, $userKey)) $replies = array_merge($replies, wsc_msg_for($done));
        }

        if ($kind === 'text') {
            if (wsc_is_command($text, WSC_CMD_SUBMIT)) {
                $ownDraft = $s['drafts'][$userKey] ?? null;
                $done = wsc_submit_draft($s, $userKey);   // 写真がなければ null（公開しない）
                if ($done) {
                    $replies = array_merge($replies, wsc_msg_for($done));
                } elseif ($ownDraft && isset($s['posts'][$ownDraft])) {
                    $replies = array_merge($replies, wsc_msg_need_photo());
                } elseif ($recent = wsc_recent_submitted_post($s, $userKey)) {
                    // コメントで自動掲載された後に「送信する」を押された場合など。二重投稿しない。
                    $replies = array_merge($replies, ($recent['status'] ?? '') === 'published' ? wsc_msg_already_published() : wsc_msg_already_received());
                } else {
                    $replies = array_merge($replies, wsc_msg_nothing_to_submit());
                }
                return;
            }
            if (wsc_is_command($text, WSC_CMD_CANCEL)) {
                wsc_cancel_draft($s, $userKey);
                $replies = array_merge($replies, wsc_msg_cancelled());
                return;
            }
        } elseif ($kind !== 'image') {
            if ($kind !== 'sticker') $replies = array_merge($replies, wsc_msg_unsupported());
            return;
        }
        if ($kind === 'image' && $photoRejected) { $replies = array_merge($replies, wsc_msg_photo_rejected()); return; }

        // 下書きがない状態で名前だけが届いた場合は、名前を覚えて写真を待つ（写真なしでは投稿を作らない）。
        if ($kind === 'text' && empty($s['drafts'][$userKey]) && ($name = wsc_parse_name_message($text)) !== null) {
            $s['members'][$userKey]['pending_name'] = $name;
            $s['members'][$userKey]['pending_name_at'] = time();
            $replies = array_merge($replies, wsc_msg_name_greeting($name));
            return;
        }

        // 写真のみを先に掲載した人が、その後にコメントを送った場合は
        // 直近60分の同じ写真投稿に一度だけ追記する（高齢の会員向け）。
        // すでに別の下書きを始めている場合やコメント済みの投稿は変更しない。
        if ($kind === 'text' && trim($text) !== '' && empty($s['drafts'][$userKey])) {
            $recentId = null;
            $recentTime = 0;
            foreach ($s['posts'] as $pid => $prev) {
                if (($prev['user_key'] ?? '') !== $userKey
                    || ($prev['status'] ?? '') !== 'published'
                    || empty($prev['photos'])
                    || trim((string)($prev['comment'] ?? '')) !== '') continue;
                $when = strtotime((string)($prev['submitted_at'] ?? '')) ?: 0;
                if ($when < time() - 3600 || $when > time() || $when <= $recentTime) continue;
                $recentId = $pid;
                $recentTime = $when;
            }
            if ($recentId !== null) {
                $prev = &$s['posts'][$recentId];
                $prev['texts'][] = wsc_clip($text, 1000);
                if (count($prev['texts']) > 20) $prev['texts'] = array_slice($prev['texts'], -20);
                $parsed = wsc_parse_texts($prev['texts']);
                foreach (['fish', 'caught_on', 'author'] as $field) {
                    if (trim((string)($prev[$field] ?? '')) === '' && $parsed[$field] !== '') {
                        $prev[$field] = $parsed[$field];
                    }
                }
                // 数量だけの文章も空欄にせず、コメントとしてそのまま残す。
                $prev['comment'] = $parsed['comment'] !== '' ? $parsed['comment'] : wsc_clip($text, 600);
                $prev['updated_at'] = wsc_now();
                unset($prev);
                $replies = array_merge($replies, [wsc_text_message(
                    "先ほどの釣果情報にコメントを追加しました。\nホームページで確認できます。"
                )]);
                return;
            }
        }

        // 下書きへ追加
        $draftId = $s['drafts'][$userKey] ?? null;
        $isNew = false;
        if (!$draftId || !isset($s['posts'][$draftId])) {
            if (wsc_submits_today($s, $userKey) >= WSC_DAILY_SUBMIT_LIMIT) {
                $dropPhoto();
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
        if ($isNew) {
            // 先に送られた名前（12時間以内）を投稿者として引き継ぐ。1回使ったら消す。
            $m = $s['members'][$userKey] ?? [];
            if (!empty($m['pending_name'])) {
                if ((int)($m['pending_name_at'] ?? 0) >= time() - WSC_NAME_KEEP_SECONDS) {
                    $post['texts'][] = '投稿者：' . $m['pending_name'];
                    $post['author'] = (string)$m['pending_name'];
                }
                unset($s['members'][$userKey]['pending_name'], $s['members'][$userKey]['pending_name_at']);
            }
        }
        if ($kind === 'text') {
            $post['texts'][] = wsc_clip($text, 1000);
            if (count($post['texts']) > 20) $post['texts'] = array_slice($post['texts'], -20);
            if (!empty($post['photos'])) {
                // 写真のあとのコメントは、そのまま確定して掲載（「送信する」を押させない）。
                unset($post);
                $done = wsc_submit_draft($s, $userKey);
                $replies = array_merge($replies, $done ? wsc_msg_for($done) : wsc_msg_need_photo());
                return;
            }
            $replies = array_merge($replies, wsc_msg_text_wait_photo());
            return;
        } else {
            if (count($post['photos']) >= WSC_MAX_PHOTOS) {
                $dropPhoto();
                if (empty($post['notice_sent']['limit'])) {
                    $post['notice_sent']['limit'] = true;
                    $replies = array_merge($replies, wsc_msg_photo_limit());
                }
                return;
            }
            $post['photos'][] = ['file' => $photo['file'], 'w' => $photo['w'], 'h' => $photo['h'], 'hidden' => false, 'received_at' => wsc_now()];
            $photo = null;
        }
        // LINEのクイック返信は次のメッセージで消える。写真を受け取るたびに「送信する」を付け直す。
        $replies = array_merge($replies, wsc_msg_photo_received());
    });

    // 保存が完了してから返信（返信に失敗しても投稿は残る）
    if ($replies) wsc_reply((string)($event['replyToken'] ?? ''), $replies);
    wsc_notify_submitted($pushTo);
}

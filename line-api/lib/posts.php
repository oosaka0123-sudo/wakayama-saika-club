<?php
/** 投稿（下書き→承認待ち→掲載/非公開）の操作と、LINEのテキストからの項目抽出。 */
declare(strict_types=1);
if (!defined('WSC_APP')) { http_response_code(403); exit; }

function wsc_now(): string { return date('c'); }

function wsc_clip(string $s, int $len): string
{
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '');
    return mb_substr($s, 0, $len);
}

/** 「11月20日」「2026/11/20」「11/20」などを YYYY-MM-DD に。年がなければ未来日にならない年を補う。 */
function wsc_parse_date(string $text, ?int $now = null): ?string
{
    $now = $now ?? time();
    $t = mb_convert_kana($text, 'n');
    $y = null;
    if (preg_match('/(20\d{2})\s*[年\/\-.]\s*(\d{1,2})\s*[月\/\-.]\s*(\d{1,2})\s*日?/u', $t, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/(?<!\d)(\d{1,2})\s*(?:月|\/)\s*(\d{1,2})\s*日?(?!\d)/u', $t, $m)) {
        [$mo, $d] = [(int)$m[1], (int)$m[2]];
    } else {
        return null;
    }
    if ($y === null) {
        $y = (int)date('Y', $now);
        if (checkdate($mo, $d, $y) && mktime(0, 0, 0, $mo, $d, $y) > $now + 2 * 86400) $y--;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** 送られたテキストから魚種・釣行日・投稿者・コメントを推定（管理画面で修正可能）。 */
function wsc_parse_texts(array $texts, ?int $now = null): array
{
    $fields = ['fish' => '', 'caught_on' => '', 'author' => ''];
    $comment = [];
    $labels = [
        'author' => '(?:投稿者|お名前|名前|ニックネーム|ペンネーム)',
        'fish' => '(?:魚種|魚|釣果)',
        'caught_on' => '(?:釣行日|日付|日にち)',
        'comment' => '(?:コメント|ひとこと|一言)',
    ];
    foreach ($texts as $text) {
        foreach (preg_split('/\R/u', (string)$text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $matched = false;
            foreach ($labels as $key => $re) {
                if (preg_match('/^' . $re . '\s*[:：]\s*(.*)$/u', $line, $m)) {
                    $v = trim($m[1]);
                    if ($key === 'comment') { if ($v !== '') $comment[] = $v; }
                    elseif ($key === 'caught_on') { $fields['caught_on'] = wsc_parse_date($v, $now) ?? $fields['caught_on']; if (!wsc_parse_date($v, $now) && $v !== '') $comment[] = $line; }
                    else { $fields[$key] = $v; }
                    $matched = true;
                    break;
                }
            }
            if ($matched) continue;
            if ($fields['caught_on'] === '' && mb_strlen($line) <= 16 && ($d = wsc_parse_date($line, $now)) !== null) {
                $fields['caught_on'] = $d;
                continue;
            }
            if ($fields['fish'] === '' && mb_strlen($line) <= 30 && preg_match('/\d+\s*(匹|尾|本|枚|杯|kg|ｋｇ|キロ|cm|ｃｍ|センチ)/u', mb_convert_kana($line, 'n'))) {
                $fields['fish'] = $line;
                continue;
            }
            $comment[] = $line;
        }
    }
    return [
        'fish' => wsc_clip($fields['fish'], 60),
        'caught_on' => $fields['caught_on'],
        'author' => wsc_clip($fields['author'], 30),
        'comment' => wsc_clip(implode("\n", $comment), 600),
    ];
}

function wsc_new_draft(string $userKey, string $lineUserId): array
{
    return [
        'id' => wsc_random_id(10), 'status' => 'draft', 'user_key' => $userKey,
        '_line_user' => $lineUserId,            // 確定通知のためだけに一時保持。確定時に消す。
        'created_at' => wsc_now(), 'updated_at' => wsc_now(), 'last_activity' => time(),
        'texts' => [], 'photos' => [], 'fish' => '', 'caught_on' => '', 'comment' => '', 'author' => '',
        'notice_sent' => [],
    ];
}

function wsc_post_has_content(array $post): bool
{
    return !empty($post['photos']) || trim(implode('', $post['texts'] ?? [])) !== '';
}

/** 下書きを確定する（通常は即時掲載）。返り値：確定した投稿（なければ null）。 */
function wsc_submit_draft(array &$state, string $userKey): ?array
{
    $id = $state['drafts'][$userKey] ?? null;
    if (!$id || !isset($state['posts'][$id])) { unset($state['drafts'][$userKey]); return null; }
    // 写真がない下書きは掲載しない（氏名・文章だけの投稿を防ぐ）。下書きは残して写真を待つ。
    if (empty($state['posts'][$id]['photos'])) return null;
    unset($state['drafts'][$userKey]);
    $post = &$state['posts'][$id];
    $parsed = wsc_parse_texts($post['texts'] ?? []);
    foreach ($parsed as $k => $v) $post[$k] = $v;
    $post['submitted_at'] = wsc_now();
    $post['updated_at'] = wsc_now();
    // 送信確定と同時に自動掲載。自動掲載停止中・サイト全体の上限超過・停止中の会員は承認待ちにする。
    if (wsc_can_auto_publish($state, (string)($post['user_key'] ?? ''))) {
        $post['status'] = 'published';
        $post['published_at'] = wsc_now();
        $today = date('Y-m-d');
        $state['daily'] = [$today => (int)($state['daily'][$today] ?? 0) + 1];
    } else {
        $post['status'] = 'pending';
    }
    $copy = $post;
    unset($post['_line_user']);
    $state['submits'][$userKey][] = time();
    $state['submits'][$userKey] = array_values(array_filter($state['submits'][$userKey], function ($t) { return $t > time() - 86400; }));
    return $copy;
}

/** 放置された下書き（最終操作から1時間）を確定（＝掲載）。通知用に [[LINEユーザーID, 状態]] を返す。 */
function wsc_sweep_idle_drafts(array &$state, ?int $now = null): array
{
    $now = $now ?? time();
    $notify = [];
    foreach (array_keys($state['drafts']) as $userKey) {
        $id = $state['drafts'][$userKey];
        $post = $state['posts'][$id] ?? null;
        if (!$post) { unset($state['drafts'][$userKey]); continue; }
        if ((int)($post['last_activity'] ?? 0) > $now - WSC_DRAFT_IDLE_SECONDS) continue;
        $done = wsc_finalize_idle_draft($state, (string)$userKey);
        if ($done && !empty($done['_line_user'])) $notify[] = [(string)$done['_line_user'], (string)$done['status']];
    }
    return $notify;
}

/** 放置された下書きの後始末。写真があれば確定、写真がなければ（掲載できないので）破棄する。 */
function wsc_finalize_idle_draft(array &$state, string $userKey): ?array
{
    $done = wsc_submit_draft($state, $userKey);
    if (!$done && isset($state['drafts'][$userKey])) wsc_cancel_draft($state, $userKey);
    return $done;
}

/** 直近1時間以内に確定した、この会員の最新の投稿（二重送信の案内用）。 */
function wsc_recent_submitted_post(array $state, string $userKey): ?array
{
    $best = null; $bestTime = 0;
    foreach ($state['posts'] as $p) {
        if (($p['user_key'] ?? '') !== $userKey || !in_array($p['status'] ?? '', ['published', 'pending'], true)) continue;
        $when = strtotime((string)($p['submitted_at'] ?? '')) ?: 0;
        if ($when >= time() - 3600 && $when >= $bestTime) { $best = $p; $bestTime = $when; }
    }
    return $best;
}

/**
 * 掲載直後の文章の追記先を探す。見つからなければ null。
 *  ・写真だけで掲載し、まだコメントが空の投稿：60分以内
 *  ・コメントで自動掲載した投稿（auto_comment_at）：10分以内（コメントが分割されて届いた場合）
 */
function wsc_find_append_target(array $state, string $userKey): ?string
{
    $best = null; $bestTime = 0;
    foreach ($state['posts'] as $pid => $p) {
        if (($p['user_key'] ?? '') !== $userKey || ($p['status'] ?? '') !== 'published' || empty($p['photos'])) continue;
        $when = strtotime((string)($p['submitted_at'] ?? '')) ?: 0;
        $viaComment = (int)($p['auto_comment_at'] ?? 0) >= time() - 600;
        $photoOnly = trim((string)($p['comment'] ?? '')) === '' && $when >= time() - 3600;
        if (!$viaComment && !$photoOnly) continue;
        if ($when > time() || $when <= $bestTime) continue;
        $best = (string)$pid; $bestTime = $when;
    }
    return $best;
}

const WSC_NAME_KEEP_SECONDS = 43200;  // 先に送られた名前を覚えておく時間（12時間）

/**
 * 「山田」「山田太郎」「まこ」「山田さん」「名前：山田」のような名前だけの短いメッセージから名前を取り出す。
 * 魚種・挨拶・コメントらしいものは null（名前と断定しない）。
 */
function wsc_parse_name_message(string $text): ?string
{
    $t = trim(mb_convert_kana($text, 's'));
    if ($t === '' || preg_match('/\R/u', $t) || mb_strlen($t) > 40) return null;
    $labeled = false;
    if (preg_match('/^(?:投稿者|お名前|名前|ニックネーム|ペンネーム)\s*[:：]\s*(\S.*)$/u', $t, $m)) { $t = trim($m[1]); $labeled = true; }
    $t = trim(preg_replace('/(?:さん|さま|様|くん|君|ちゃん)$/u', '', $t) ?? '');
    if ($t === '' || preg_match('/[<>&"\'\\\\]/u', $t)) return null;
    if ($labeled) return wsc_clip($t, 30) ?: null;
    // 「堺」「辻」のような漢字1文字の名字は名前として受け付ける（魚・返事などの1文字は除く）。
    if (mb_strlen($t) === 1) {
        if (!preg_match('/^\p{Han}$/u', $t)) return null;
        $one = '鯛鯵鰺鯖鰯鰤鱸鮃鰈鯒鱚鯊鮎鮭鱒鰻鯉鮪鰹鰆鮫蛸烏蟹魚釣済了可否是無有雨晴曇風波潮海船竿糸餌朝昼夕夜今';
        return mb_strpos($one, $t) === false ? $t : null;
    }
    if (mb_strlen($t) > 8) return null;
    if (!preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}ー々A-Za-z]+(?: [\p{Han}\p{Hiragana}\p{Katakana}ー々A-Za-z]+)?$/u', $t)) return null;
    if (preg_match('/釣|魚|大漁|不漁|ありがとう|お疲れ|です|ます|でした|ました|ください|よろしく|すみません|おはよう|こんにち|こんばん/u', $t)) return null;
    $stop = ['はい', 'いいえ', 'ok', 'りょうかい', '了解', 'なし', 'ボウズ', '坊主', 'マダイ', 'タイ', 'クロダイ', 'チヌ', 'アジ', 'サバ', 'イワシ', 'ブリ',
        'ハマチ', 'メジロ', 'カンパチ', 'ヒラメ', 'スズキ', 'グレ', 'メバル', 'カサゴ', 'ガシラ', 'キス', 'ハゼ', 'イカ', 'アオリイカ', 'タコ', 'サゴシ', 'サワラ',
        'タチウオ', 'ヒラマサ', 'シーバス', 'サヨリ', 'カワハギ', 'ウマヅラ', 'アコウ', 'キジハタ', 'ハタ', 'ヤズ', 'ワラサ', 'イサキ', 'アマダイ', 'ガッシー'];
    if (in_array(mb_strtolower($t), array_map('mb_strtolower', $stop), true)) return null;
    return wsc_clip($t, 30);
}

function wsc_cancel_draft(array &$state, string $userKey): bool
{
    $id = $state['drafts'][$userKey] ?? null;
    unset($state['drafts'][$userKey]);
    if (!$id || !isset($state['posts'][$id])) return false;
    foreach ($state['posts'][$id]['photos'] ?? [] as $p) wsc_delete_photo_files((string)$p['file']);
    unset($state['posts'][$id]);
    return true;
}

function wsc_delete_post(array &$state, string $id): bool
{
    if (!isset($state['posts'][$id])) return false;
    foreach ($state['posts'][$id]['photos'] ?? [] as $p) wsc_delete_photo_files((string)$p['file']);
    foreach ($state['drafts'] as $k => $d) if ($d === $id) unset($state['drafts'][$k]);
    unset($state['posts'][$id]);
    return true;
}

function wsc_submits_today(array $state, string $userKey): int
{
    return count(array_filter($state['submits'][$userKey] ?? [], function ($t) { return $t > time() - 86400; }));
}

/**
 * 放置下書きの自動確定（Webhookが来なくても動くよう、定期呼び出し・公開APIから実行）。
 * 50秒に1回までに制限。確定した件数を返し、受付通知はプッシュで送る。
 */
function wsc_tick(): int
{
    $stamp = wsc_path('data/tick.stamp');
    if (is_file($stamp) && filemtime($stamp) > time() - 50) return 0;
    @touch($stamp);
    // 期限切れの下書きがあるときだけ書き込みロックを取る
    $due = wsc_state(function (array &$s) {
        foreach ($s['drafts'] as $id) {
            if (isset($s['posts'][$id]) && (int)($s['posts'][$id]['last_activity'] ?? 0) <= time() - WSC_DRAFT_IDLE_SECONDS) return true;
        }
        return false;
    }, false);
    if (!$due) return 0;
    $notify = wsc_state(function (array &$s) { return wsc_sweep_idle_drafts($s); });
    wsc_notify_submitted($notify);
    return count($notify);
}


/** 自動確定の通知（プッシュ）。 */
function wsc_notify_submitted(array $notify): void
{
    foreach ($notify as [$to, $status]) wsc_push($to, $status === 'published' ? wsc_msg_published() : wsc_msg_received_pending());
}

// ---------------------------------------------------------------- 会員（投稿できる人）の管理

function wsc_setting(array $state, string $key, $default = null)
{
    return $state['settings'][$key] ?? $default;
}

/** 合言葉が設定済みか。未設定のあいだは誰も登録・投稿できない（安全側）。 */
function wsc_passphrase_set(array $state): bool
{
    return (string)wsc_setting($state, 'passphrase_hash', '') !== '';
}

function wsc_is_member(array $state, string $userKey): bool
{
    $m = $state['members'][$userKey] ?? null;
    return is_array($m) && empty($m['blocked']);
}

function wsc_is_blocked(array $state, string $userKey): bool
{
    return !empty($state['members'][$userKey]['blocked']);
}

function wsc_can_auto_publish(array $state, string $userKey): bool
{
    if (!wsc_setting($state, 'auto_publish', true)) return false;
    if (!wsc_is_member($state, $userKey)) return false;
    $today = date('Y-m-d');
    return (int)($state['daily'][$today] ?? 0) < WSC_SITE_DAILY_PUBLISH;
}

/** 合言葉の照合（大文字小文字・全角半角・前後空白を区別しない）。 */
function wsc_normalize_passphrase(string $p): string
{
    return mb_strtolower(trim(mb_convert_kana($p, 'asKV')));
}

/** 「登録 合言葉」「登録：合言葉」を解析。該当しなければ null。 */
function wsc_parse_registration(string $text): ?array
{
    $t = trim(mb_convert_kana($text, 's'));
    if (!preg_match('/^(?:登録|会員登録)\s*[:：]?\s*(\S+)(?:\s+(.{1,30}))?$/u', $t, $m)) return null;
    return ['pass' => $m[1], 'name' => isset($m[2]) ? wsc_clip($m[2], 30) : ''];
}

/** 1分あたりの操作数制限。超えたら true（この操作は無視する）。 */
function wsc_rate_limited(array &$state, string $userKey): bool
{
    $now = time();
    $list = array_values(array_filter((array)($state['rate'][$userKey] ?? []), function ($t) use ($now) { return $t > $now - 60; }));
    $list[] = $now;
    $state['rate'][$userKey] = $list;
    if (count($state['rate']) > 500) {
        foreach ($state['rate'] as $k => $v) if (max((array)$v ?: [0]) < $now - 60) unset($state['rate'][$k]);
    }
    return count($list) > WSC_EVENTS_PER_MINUTE;
}

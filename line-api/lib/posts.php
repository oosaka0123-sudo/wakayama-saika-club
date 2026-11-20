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

/** 下書きを確定して承認待ちにする。返り値：確定した投稿（なければ null）。 */
function wsc_submit_draft(array &$state, string $userKey): ?array
{
    $id = $state['drafts'][$userKey] ?? null;
    unset($state['drafts'][$userKey]);
    if (!$id || !isset($state['posts'][$id])) return null;
    $post = &$state['posts'][$id];
    if (!wsc_post_has_content($post)) { unset($state['posts'][$id]); return null; }
    $parsed = wsc_parse_texts($post['texts'] ?? []);
    foreach ($parsed as $k => $v) $post[$k] = $v;
    $post['status'] = 'pending';
    $post['submitted_at'] = wsc_now();
    $post['updated_at'] = wsc_now();
    $copy = $post;
    unset($post['_line_user']);
    $state['submits'][$userKey][] = time();
    $state['submits'][$userKey] = array_values(array_filter($state['submits'][$userKey], function ($t) { return $t > time() - 86400; }));
    return $copy;
}

/** 放置された下書き（最終操作から1時間）を承認待ちへ。通知用に [LINEユーザーID] を返す。 */
function wsc_sweep_idle_drafts(array &$state, ?int $now = null): array
{
    $now = $now ?? time();
    $notify = [];
    foreach (array_keys($state['drafts']) as $userKey) {
        $id = $state['drafts'][$userKey];
        $post = $state['posts'][$id] ?? null;
        if (!$post) { unset($state['drafts'][$userKey]); continue; }
        if ((int)($post['last_activity'] ?? 0) > $now - WSC_DRAFT_IDLE_SECONDS) continue;
        $done = wsc_submit_draft($state, (string)$userKey);
        if ($done && !empty($done['_line_user'])) $notify[] = (string)$done['_line_user'];
    }
    return $notify;
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

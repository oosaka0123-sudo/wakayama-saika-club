<?php
/**
 * 和歌山雑賀クラブ 釣果投稿 — 共通処理
 * 設定・保存・ログ・HTTP応答。Webから直接実行されないよう WSC_APP 定義を必須にする。
 */
declare(strict_types=1);
if (!defined('WSC_APP')) { http_response_code(403); exit; }

const WSC_STATUSES = ['draft', 'pending', 'published', 'rejected'];
const WSC_MAX_PHOTOS = 5;
const WSC_DRAFT_IDLE_SECONDS = 3600;      // 送信忘れの下書きは1時間後に自動で「承認待ち」へ
const WSC_DAILY_SUBMIT_LIMIT = 10;      // 1人あたり1日の投稿上限
const WSC_EVENTS_PER_MINUTE = 20;       // 1人あたり1分間の操作上限（連打・荒らし対策）
const WSC_SITE_DAILY_PUBLISH = 50;      // サイト全体の1日の自動掲載上限（超えた分は承認待ち）
const WSC_REG_FAIL_LIMIT = 5;           // 合言葉の失敗は1人1日5回まで

/**
 * 非公開領域（設定・投稿データ・写真）。
 * 本番は公開フォルダの外 saika/catch-private（= line-api の3階層上の catch-private）を使う。
 * 見つからない場合のみ line-api/private（.htaccessで遮断）を使う。
 */
function wsc_private_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $outside = dirname(__DIR__, 3) . '/catch-private';
    $dir = is_dir($outside) ? $outside : dirname(__DIR__) . '/private';
    return $dir;
}

function wsc_path(string $relative): string
{
    return wsc_private_dir() . '/' . ltrim($relative, '/');
}

function wsc_ensure_dirs(): void
{
    foreach (['data', 'photos', 'originals', 'logs'] as $d) {
        $p = wsc_path($d);
        if (!is_dir($p) && !@mkdir($p, 0700, true) && !is_dir($p)) {
            throw new RuntimeException('Cannot create private directory');
        }
    }
}

function wsc_config_file(): string
{
    return wsc_path('config.local.php');
}

function wsc_is_configured(): bool
{
    return is_file(wsc_config_file());
}

function wsc_config(): array
{
    static $config = null;
    if ($config !== null) return $config;
    if (!wsc_is_configured()) throw new RuntimeException('Not configured');
    $loaded = require wsc_config_file();
    if (!is_array($loaded)) throw new RuntimeException('Invalid configuration');
    $config = $loaded + [
        'channel_id' => '',
        'channel_secret' => '',
        'channel_access_token' => '',
        'admin_password_hash' => '',
        'allowed_origins' => ['https://oosaka0123-sudo.github.io'],
        'public_base_url' => '',
        'site_catch_url' => 'https://oosaka0123-sudo.github.io/wakayama-saika-club/catch/',
    ];
    return $config;
}

function wsc_log(string $message): void
{
    try {
        wsc_ensure_dirs();
        $line = date('c') . ' ' . preg_replace('/[\r\n]+/', ' ', $message) . "\n";
        @file_put_contents(wsc_path('logs/app.log'), $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('WSC: ' . $message);
    }
}

function wsc_send_security_headers(bool $html = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    if ($html) {
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    }
}

function wsc_json(int $status, array $payload, array $extraHeaders = []): void
{
    http_response_code($status);
    wsc_send_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    foreach ($extraHeaders as $h) header($h);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wsc_empty_state(): array
{
    return ['version' => 2, 'posts' => [], 'drafts' => [], 'seen' => [], 'submits' => [], 'token' => [],
            'members' => [], 'settings' => [], 'rate' => [], 'regfail' => [], 'daily' => []];
}

/**
 * 排他ロックで状態ファイルを読み書きする。コールバックが例外を投げた場合は書き込まない。
 * @template T
 * @param callable(array&):T $callback
 * @return T
 */
function wsc_state(callable $callback, bool $write = true)
{
    wsc_ensure_dirs();
    $file = wsc_path('data/store.json');
    $handle = fopen($file, 'c+');
    if (!$handle) throw new RuntimeException('Cannot open store');
    try {
        if (!flock($handle, $write ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Cannot lock store');
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($state)) $state = wsc_empty_state();
        $state += wsc_empty_state();
        $before = $state;
        $result = $callback($state);
        if ($write && $state !== $before) {
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) throw new RuntimeException('Cannot encode store');
            // バックアップを残してから上書き（破損時の復旧用）
            @copy($file, $file . '.bak');
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) === false || !fflush($handle)) {
                throw new RuntimeException('Cannot write store');
            }
        }
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function wsc_read_state(): array
{
    return wsc_state(function (array &$s) { return $s; }, false);
}

function wsc_random_id(int $bytes = 10): string
{
    return bin2hex(random_bytes($bytes));
}

function wsc_valid_id(string $id): bool
{
    return (bool)preg_match('/^[a-f0-9]{16,40}$/', $id);
}

/** LINEユーザーIDは保存せず、チャネルシークレットでHMAC化した識別子だけを残す。 */
function wsc_user_key(string $lineUserId): string
{
    $secret = (string)(wsc_config()['channel_secret'] ?? '');
    return substr(hash_hmac('sha256', 'user:' . $lineUserId, $secret), 0, 24);
}

function wsc_mark_seen(array &$state, string $eventId): void
{
    $state['seen'][$eventId] = time();
    if (count($state['seen']) > 3000) {
        asort($state['seen']);
        $state['seen'] = array_slice($state['seen'], -2000, null, true);
    }
}

/** 投稿を公開用の安全な形に変換（LINE識別子・内部情報は含めない）。 */
function wsc_public_post(array $post, string $baseUrl): array
{
    $photos = [];
    foreach (($post['photos'] ?? []) as $i => $photo) {
        if (!empty($photo['hidden'])) continue;
        $q = 'p=' . rawurlencode((string)$post['id']) . '&i=' . (int)$i;
        $photos[] = [
            'm' => $baseUrl . 'photo.php?' . $q . '&s=m',
            'l' => $baseUrl . 'photo.php?' . $q . '&s=l',
            'w' => (int)($photo['w'] ?? 0),
            'h' => (int)($photo['h'] ?? 0),
        ];
    }
    $out = ['id' => (string)$post['id'], 'photos' => $photos, 'posted_at' => (string)($post['submitted_at'] ?? $post['created_at'] ?? '')];
    foreach (['fish', 'caught_on', 'comment', 'author'] as $field) {
        $v = trim((string)($post[$field] ?? ''));
        if ($v !== '') $out[$field] = $v;
    }
    return $out;
}

function wsc_sort_key(array $post): string
{
    $date = (string)($post['caught_on'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = substr((string)($post['submitted_at'] ?? $post['created_at'] ?? ''), 0, 10);
    return $date . '|' . (string)($post['submitted_at'] ?? $post['created_at'] ?? '');
}

/** このAPIの公開ベースURL（末尾 / ）。設定がなければリクエストから推定。 */
function wsc_base_url(): string
{
    $configured = '';
    try { $configured = (string)(wsc_config()['public_base_url'] ?? ''); } catch (Throwable $e) {}
    if ($configured !== '') return rtrim($configured, '/') . '/';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    if (substr($dir, -6) === '/admin') $dir = substr($dir, 0, -6);
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function wsc_h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

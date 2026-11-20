<?php
/** 管理者認証（セッション・CSRF・ログイン試行制限） */
declare(strict_types=1);
if (!defined('WSC_APP')) { http_response_code(403); exit; }

const WSC_SESSION_IDLE = 7200;

function wsc_cookie_path(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    if (substr($dir, -6) === '/admin') $dir = substr($dir, 0, -6);
    return ($dir === '' ? '' : $dir) . '/';
}

function wsc_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    wsc_ensure_dirs();
    $dir = wsc_path('data/sessions');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    session_name('WSCADMIN');
    session_set_cookie_params(['lifetime' => 0, 'path' => wsc_cookie_path(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)WSC_SESSION_IDLE);
    session_start();
}

function wsc_admin_logged_in(): bool
{
    if (empty($_SESSION['wsc_admin']) || (int)($_SESSION['wsc_last'] ?? 0) < time() - WSC_SESSION_IDLE) return false;
    $_SESSION['wsc_last'] = time();
    return true;
}

function wsc_csrf_token(): string
{
    if (empty($_SESSION['wsc_csrf'])) $_SESSION['wsc_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['wsc_csrf'];
}

function wsc_csrf_ok(): bool
{
    $given = (string)($_POST['csrf'] ?? '');
    return $given !== '' && !empty($_SESSION['wsc_csrf']) && hash_equals((string)$_SESSION['wsc_csrf'], $given);
}

function wsc_login_attempts_file(): string { return wsc_path('data/login_attempts.json'); }

function wsc_login_locked(string $ip): bool
{
    $data = json_decode((string)@file_get_contents(wsc_login_attempts_file()), true);
    $list = array_filter((array)($data[$ip] ?? []), function ($t) { return $t > time() - 900; });
    return count($list) >= 5;
}

function wsc_login_failed(string $ip): void
{
    $file = wsc_login_attempts_file();
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) $data = [];
    foreach ($data as $k => $v) {
        $data[$k] = array_values(array_filter((array)$v, function ($t) { return $t > time() - 900; }));
        if (!$data[$k]) unset($data[$k]);
    }
    $data[$ip][] = time();
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function wsc_try_login(string $password): bool
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (wsc_login_locked($ip)) return false;
    $hash = (string)(wsc_config()['admin_password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        wsc_login_failed($ip);
        usleep(400000);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['wsc_admin'] = true;
    $_SESSION['wsc_last'] = time();
    $_SESSION['wsc_csrf'] = bin2hex(random_bytes(32));
    return true;
}

function wsc_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
}

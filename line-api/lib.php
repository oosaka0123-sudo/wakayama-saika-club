<?php
declare(strict_types=1);
if (!defined('WSC_API_INTERNAL')) { http_response_code(403); exit; }

function wsc_config(): array {
    $file = __DIR__.'/config.local.php';
    if (!is_file($file)) throw new RuntimeException('LINE configuration is not installed.');
    $config = require $file;
    if (!is_array($config)) throw new RuntimeException('Invalid configuration.');
    return $config;
}
function wsc_response(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function wsc_state(callable $callback) {
    $dir = __DIR__.'/data';
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Data directory is unavailable.');
    $handle = fopen($dir.'/state.json', 'c+');
    if (!$handle) throw new RuntimeException('Data file is unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock data.');
        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = $raw ? json_decode($raw, true) : null;
        if (!is_array($state)) $state = ['posts'=>[], 'seen'=>[], 'drafts'=>[], 'candidates'=>[]];
        foreach (['posts','seen','drafts','candidates'] as $field) {
            if (!isset($state[$field]) || !is_array($state[$field])) $state[$field] = [];
        }
        $value = $callback($state);
        $json = json_encode($state, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new RuntimeException('Cannot encode data.');
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) === false) throw new RuntimeException('Cannot persist data.');
        fflush($handle);
        flock($handle, LOCK_UN);
        return $value;
    } finally {
        fclose($handle);
    }
}
function wsc_event_seen(string $id): bool {
    return (bool)wsc_state(function (array &$state) use ($id) {
        return isset($state['seen'][$id]);
    });
}
function wsc_mark_seen(array &$state, string $id): void {
    $state['seen'][$id] = time();
    if (count($state['seen']) > 1500) {
        asort($state['seen']);
        $state['seen'] = array_slice($state['seen'], -1000, null, true);
    }
}
/**
 * Re-encode a LINE photo to a fresh JPEG. Drops GPS/EXIF metadata and
 * enforces sane image dimensions before writing anything publicly.
 * Requires PHP GD (supported by the host, verified again on deployment).
 */
function wsc_public_photo(string $bytes): ?string {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return null;
    $size = @getimagesizefromstring($bytes);
    if (!is_array($size) || count($size) < 2) return null;
    $w = (int)$size[0];
    $h = (int)$size[1];
    if ($w < 64 || $h < 64 || $w > 7000 || $h > 7000 || $w * $h > 20000000) return null;
    $image = @imagecreatefromstring($bytes);
    if (!($image instanceof GdImage)) return null;
    $scale = min(1.0, 1920 / max($w, $h));
    $targetW = max(1, (int)round($w * $scale));
    $targetH = max(1, (int)round($h * $scale));
    $target = imagecreatetruecolor($targetW, $targetH);
    if (!$target) { imagedestroy($image); return null; }
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefill($target, 0, 0, $white);
    if (!imagecopyresampled($target, $image, 0, 0, 0, 0, $targetW, $targetH, $w, $h)) {
        imagedestroy($target);
        imagedestroy($image);
        return null;
    }
    ob_start();
    $ok = imagejpeg($target, null, 82);
    $data = ob_get_clean();
    imagedestroy($target);
    imagedestroy($image);
    return $ok && is_string($data) && strlen($data) > 128 ? $data : null;
}

function wsc_text_parts(string $text): array {
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    $author = '';
    $comment = [];
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\s*(?:投稿者|お名前|名前)\s*[:：]\s*(.*)$/u', $line, $m)) {
            $author = mb_substr(trim($m[1]), 0, 40);
        } elseif (preg_match('/^\s*コメント\s*[:：]\s*(.*)$/u', $line, $m)) {
            $comment[] = $m[1];
        } elseif (trim($line) !== '') {
            $comment[] = $line;
        }
    }
    return ['author'=>$author, 'comment'=>mb_substr(trim(implode("\n", $comment)), 0, 300)];
}

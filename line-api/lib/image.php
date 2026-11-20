<?php
/** 写真の検証・保存。公開用はGDで再エンコードしてEXIF/GPSを完全に除去する。 */
declare(strict_types=1);
if (!defined('WSC_APP')) { http_response_code(403); exit; }

const WSC_IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const WSC_IMAGE_MAX_BYTES = 20 * 1024 * 1024;

function wsc_image_mime(string $bytes): ?string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    return is_string($mime) && isset(WSC_IMAGE_MIMES[$mime]) ? $mime : null;
}

function wsc_resize(GdImage $src, int $maxEdge): GdImage
{
    $w = imagesx($src); $h = imagesy($src);
    $scale = min(1.0, $maxEdge / max($w, $h));
    $tw = max(1, (int)round($w * $scale)); $th = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    return $dst;
}

/**
 * 写真を保存。オリジナル（非公開・管理者のみ）と、メタデータ除去済みの L(2400px)/M(1000px) を作る。
 * @return array{file:string,w:int,h:int}|null
 */
function wsc_store_photo(string $bytes): ?array
{
    if (strlen($bytes) < 100 || strlen($bytes) > WSC_IMAGE_MAX_BYTES) return null;
    $mime = wsc_image_mime($bytes);
    if ($mime === null) return null;
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info)) return null;
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w < 32 || $h < 32 || $w > 12000 || $h > 12000 || $w * $h > 60000000) return null;
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('GD extension is required');
    $img = @imagecreatefromstring($bytes);
    if (!($img instanceof GdImage)) return null;

    // スマホ写真の向き（EXIF Orientation）を反映してから再エンコード
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $o = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot !== 0) { $r = imagerotate($img, $rot, 0); if ($r instanceof GdImage) { $img = $r; } }
    }

    wsc_ensure_dirs();
    $name = wsc_random_id(12);
    $orig = wsc_path('originals/' . $name . '.' . WSC_IMAGE_MIMES[$mime]);
    if (file_put_contents($orig, $bytes, LOCK_EX) !== strlen($bytes)) return null;

    $ok = true;
    $large = wsc_resize($img, 2400);
    $ok = $ok && imagejpeg($large, wsc_path('photos/' . $name . '_l.jpg'), 88);
    $lw = imagesx($large); $lh = imagesy($large);
    $medium = wsc_resize($img, 1000);
    $ok = $ok && imagejpeg($medium, wsc_path('photos/' . $name . '_m.jpg'), 82);
    if (!$ok) { wsc_delete_photo_files($name); return null; }
    return ['file' => $name, 'w' => $lw, 'h' => $lh];
}

function wsc_photo_path(string $name, string $size): ?string
{
    if (!preg_match('/^[a-f0-9]{24}$/', $name) || !in_array($size, ['m', 'l'], true)) return null;
    $p = wsc_path('photos/' . $name . '_' . $size . '.jpg');
    return is_file($p) ? $p : null;
}

function wsc_delete_photo_files(string $name): void
{
    if (!preg_match('/^[a-f0-9]{24}$/', $name)) return;
    foreach (glob(wsc_path('photos/' . $name . '_*.jpg')) ?: [] as $f) @unlink($f);
    foreach (glob(wsc_path('originals/' . $name . '.*')) ?: [] as $f) @unlink($f);
}

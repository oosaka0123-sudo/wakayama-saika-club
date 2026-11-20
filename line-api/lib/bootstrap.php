<?php
declare(strict_types=1);
if (!defined('WSC_APP')) { http_response_code(403); exit; }
date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
require __DIR__ . '/core.php';
require __DIR__ . '/line.php';
require __DIR__ . '/image.php';
require __DIR__ . '/posts.php';
require __DIR__ . '/messages.php';

<?php
declare(strict_types=1);
define('WSC_APP', true);
date_default_timezone_set('Asia/Tokyo');
require __DIR__ . '/../line-api/lib/core.php';
require __DIR__ . '/../line-api/lib/posts.php';
function assert_cap(bool $condition, string $label): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS: {$label}\n";
}
$today = date('Y-m-d');
$state = [
  'settings' => ['auto_publish' => true],
  'members' => ['member1' => ['blocked' => false], 'member2' => ['blocked' => true]],
  'daily' => [$today => 99]
];
assert_cap(WSC_SITE_DAILY_PUBLISH === 100, 'club-wide automatic publishing cap is 100');
assert_cap(WSC_DAILY_SUBMIT_LIMIT === 10, 'member daily submission limit remains 10');
assert_cap(wsc_can_auto_publish($state, 'member1'), '99 submitted today permits next auto post');
$state['daily'][$today] = 100;
assert_cap(!wsc_can_auto_publish($state, 'member1'), '100 submissions today go to review queue');
$state['daily'][$today] = 0;
assert_cap(!wsc_can_auto_publish($state, 'member2'), 'blocked members cannot auto publish');
assert_cap(!wsc_can_auto_publish($state, 'unknown'), 'unknown users cannot auto publish');
$state['settings']['auto_publish'] = false;
assert_cap(!wsc_can_auto_publish($state, 'member1'), 'admin stop still overrides auto publication');
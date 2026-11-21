<?php
declare(strict_types=1);
define('WSC_APP', true);
date_default_timezone_set('Asia/Tokyo');
require __DIR__ . '/../line-api/lib/posts.php';
function assert_limit(int $got, int $expected, string $label): void {
    if ($got !== $expected) { fwrite(STDERR, "FAIL {$label}: expected {$expected}, got {$got}\n"); exit(1); }
    echo "PASS: {$label}\n";
}
function jst(string $time): int { return (new DateTimeImmutable($time, new DateTimeZone('Asia/Tokyo')))->getTimestamp(); }
$now = jst('2026-11-21 09:37:00');
$yesterday = jst('2026-11-20 19:37:00');
$state = ['submits' => ['sample' => array_fill(0, 10, $yesterday)]];
assert_limit(wsc_submits_today($state, 'sample', $now), 0, 'yesterday posts do not block next morning');
$state['submits']['sample'][] = jst('2026-11-21 00:00:00');
$state['submits']['sample'][] = jst('2026-11-21 09:30:00');
assert_limit(wsc_submits_today($state, 'sample', $now), 2, 'midnight and same-day posts count');
assert_limit(wsc_submits_today($state, 'sample', jst('2026-11-20 23:59:59')), 10, 'yesterday limit remains before midnight');
assert_limit(count(wsc_today_submit_times($state['submits']['sample'], $now)), 2, 'old records removed on next submission');
assert_limit(wsc_submits_today($state, 'another', $now), 0, 'per-member quota');
$state['submits']['sample'][] = jst('2026-11-21 23:59:59');
assert_limit(wsc_submits_today($state, 'sample', $now), 2, 'ignore future timestamps');
assert_limit(wsc_submits_today($state, 'sample', jst('2026-11-22 00:00:00')), 0, 'next calendar day resets');
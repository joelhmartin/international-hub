<?php
// Plain-PHP test runner for pure helpers. Run: php tests/run.php
error_reporting(E_ALL);
require __DIR__ . '/../includes/class-afm-vimeo.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) {
        echo "   expected: " . var_export($expected, true) . "\n";
        echo "   actual:   " . var_export($actual, true) . "\n";
    }
}

// --- Anchor_FM_Vimeo::parse_id ---
check('bare numeric id', Anchor_FM_Vimeo::parse_id('123456789'), '123456789');
check('vimeo.com/<id>', Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789'), '123456789');
check('player.vimeo.com', Anchor_FM_Vimeo::parse_id('https://player.vimeo.com/video/123456789'), '123456789');
check('channel url', Anchor_FM_Vimeo::parse_id('https://vimeo.com/channels/staff/123456789'), '123456789');
check('url with hash/query', Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789?h=abc#t=1'), '123456789');
check('trailing slash', Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789/'), '123456789');
check('private id with hash path', Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789/abcdef0123'), '123456789');
check('garbage returns empty', Anchor_FM_Vimeo::parse_id('not a video'), '');
check('empty returns empty', Anchor_FM_Vimeo::parse_id(''), '');

require __DIR__ . '/../includes/class-afm-watch-math.php';

// apply_progress($existing, $point_seconds, $delta_seconds, $duration_seconds)
// $existing = ['furthest_seconds'=>int,'total_seconds'=>int]; returns merged + percent.
$start = ['furthest_seconds' => 0, 'total_seconds' => 0];

$r = Anchor_FM_Watch_Math::apply_progress($start, 30, 30, 100);
check('first beat furthest', $r['furthest_seconds'], 30);
check('first beat total', $r['total_seconds'], 30);
check('first beat percent', $r['percent'], 30);

$r2 = Anchor_FM_Watch_Math::apply_progress($r, 10, 10, 100); // user scrubbed back, watched 10 more
check('scrub keeps furthest', $r2['furthest_seconds'], 30);
check('scrub adds to total', $r2['total_seconds'], 40);

// Oversized delta (seek-induced) is clamped to a sane per-beat ceiling (<= 60s).
$r3 = Anchor_FM_Watch_Math::apply_progress($start, 90, 5000, 100);
check('delta clamped', $r3['total_seconds'], 60);
check('furthest tracks point', $r3['furthest_seconds'], 90);

// total never exceeds duration; percent caps at 100.
$r4 = Anchor_FM_Watch_Math::apply_progress(['furthest_seconds'=>100,'total_seconds'=>100], 100, 50, 100);
check('total capped at duration', $r4['total_seconds'], 100);
check('percent capped', $r4['percent'], 100);

// zero/garbage duration => percent 0, no divide-by-zero
$r5 = Anchor_FM_Watch_Math::apply_progress($start, 5, 5, 0);
check('zero duration percent', $r5['percent'], 0);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);

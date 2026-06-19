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

require __DIR__ . '/../includes/class-afm-user-import.php';

// --- Anchor_FM_User_Import::sanitize_username ---
check('sanitize lowercases', Anchor_FM_User_Import::sanitize_username('J.Smith'), 'j.smith');
check('sanitize strips spaces/symbols', Anchor_FM_User_Import::sanitize_username('Mary O\'Brien!'), 'maryobrien');
check('sanitize keeps dot/dash/underscore', Anchor_FM_User_Import::sanitize_username('a.b-c_d'), 'a.b-c_d');

// --- Anchor_FM_User_Import::derive_username ---
check('derive basic', Anchor_FM_User_Import::derive_username('Jane', 'Smith'), 'j.smith');
check('derive trims', Anchor_FM_User_Import::derive_username('  Bob ', ' Lee '), 'b.lee');
check('derive lowercases', Anchor_FM_User_Import::derive_username('AL', 'CAPONE'), 'a.capone');

// --- Anchor_FM_User_Import::make_unique ---
$none = function ($n) { return false; };
check('unique passthrough', Anchor_FM_User_Import::make_unique('j.smith', $none), 'j.smith');

$taken = ['j.smith' => true, 'j.smith2' => true];
$exists = function ($n) use ($taken) { return isset($taken[$n]); };
check('unique suffixes past taken', Anchor_FM_User_Import::make_unique('j.smith', $exists), 'j.smith3');

// --- Anchor_FM_User_Import::is_header_row ---
check('header detected by email', Anchor_FM_User_Import::is_header_row(['username','first name','last name','email']), true);
check('non-header not detected', Anchor_FM_User_Import::is_header_row(['jsmith','Jane','Smith','jane@x.com']), false);

// --- Anchor_FM_User_Import::parse (positional, no header) ---
$p = Anchor_FM_User_Import::parse("jsmith,Jane,Smith,jane@x.com\n,Bob,Lee,bob@x.com\n");
check('positional no header flag', $p['header_detected'], false);
check('positional row count', count($p['rows']), 2);
check('positional username', $p['rows'][0]['username'], 'jsmith');
check('positional first', $p['rows'][0]['first_name'], 'Jane');
check('positional email', $p['rows'][0]['email'], 'jane@x.com');
check('positional blank username kept', $p['rows'][1]['username'], '');
check('positional line number', $p['rows'][1]['line'], 2);

// --- parse with header in a different column order ---
$h = Anchor_FM_User_Import::parse("email,first name,last name\njane@x.com,Jane,Smith\n");
check('header detected flag', $h['header_detected'], true);
check('header maps email', $h['rows'][0]['email'], 'jane@x.com');
check('header maps first', $h['rows'][0]['first_name'], 'Jane');
check('header missing username empty', $h['rows'][0]['username'], '');
check('header data line number', $h['rows'][0]['line'], 2);

// --- blank lines skipped ---
$b = Anchor_FM_User_Import::parse("jsmith,Jane,Smith,jane@x.com\n\n   \n,Bob,Lee,bob@x.com\n");
check('blank lines skipped', count($b['rows']), 2);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);

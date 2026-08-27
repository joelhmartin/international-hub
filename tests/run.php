<?php
// Plain-PHP test runner for pure helpers. Run: php tests/run.php
error_reporting(E_ALL);

/*
 * Minimal stubs for the handful of WordPress functions Anchor_FM_Vimeo touches,
 * so fetch_meta()'s response handling is testable without a WP bootstrap.
 * $GLOBALS['afm_http_stub'] is the canned response for the next wp_remote_get.
 */
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message;
        public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_remote_get($url, $args = []) {
    $GLOBALS['afm_http_last_url'] = $url;
    $stub = isset($GLOBALS['afm_http_stub']) ? $GLOBALS['afm_http_stub'] : null;
    return $stub === null ? new WP_Error('http_request_failed', 'No stub set') : $stub;
}
function wp_remote_retrieve_response_code($res) { return isset($res['response']['code']) ? $res['response']['code'] : 0; }
function wp_remote_retrieve_body($res) { return isset($res['body']) ? $res['body'] : ''; }

require __DIR__ . '/../includes/class-afm-vimeo.php';
require __DIR__ . '/../includes/class-afm-range.php';
require __DIR__ . '/../includes/class-afm-coverage.php';

$failures = 0;

// A PHP warning emitted before wp_send_json corrupts the AJAX response on any
// host with display_errors on, so the suite has to be able to fail on one.
set_error_handler(function ($no, $str, $file, $line) {
    global $failures; $failures++;
    echo "[FAIL] PHP warning: $str at $file:$line\n";
    return true;
});

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

// URL forms that previously 400'd because parse_id could not read them.
check('manage/videos dashboard url', Anchor_FM_Vimeo::parse_id('https://vimeo.com/manage/videos/123456789'), '123456789');
check('ondemand url', Anchor_FM_Vimeo::parse_id('https://vimeo.com/ondemand/someshow/123456789'), '123456789');
check('album url', Anchor_FM_Vimeo::parse_id('https://vimeo.com/album/12345/video/123456789'), '123456789');
check('groups url', Anchor_FM_Vimeo::parse_id('https://vimeo.com/groups/foo/videos/123456789'), '123456789');
check('iframe embed code', Anchor_FM_Vimeo::parse_id('<iframe src="https://player.vimeo.com/video/123456789?h=abcdef0123&title=0" width="640"></iframe>'), '123456789');
check('bare id with whitespace', Anchor_FM_Vimeo::parse_id('  123456789  '), '123456789');
check('http (not https)', Anchor_FM_Vimeo::parse_id('http://vimeo.com/123456789'), '123456789');
check('www subdomain', Anchor_FM_Vimeo::parse_id('https://www.vimeo.com/123456789'), '123456789');

// --- Anchor_FM_Vimeo::parse_ref (id + unlisted hash) ---
check('ref: bare id has no hash', Anchor_FM_Vimeo::parse_ref('123456789'), ['id' => '123456789', 'hash' => '']);
check('ref: public url has no hash', Anchor_FM_Vimeo::parse_ref('https://vimeo.com/123456789'), ['id' => '123456789', 'hash' => '']);
check('ref: unlisted path hash', Anchor_FM_Vimeo::parse_ref('https://vimeo.com/123456789/abcdef0123'), ['id' => '123456789', 'hash' => 'abcdef0123']);
check('ref: unlisted query hash', Anchor_FM_Vimeo::parse_ref('https://vimeo.com/123456789?h=abcdef0123'), ['id' => '123456789', 'hash' => 'abcdef0123']);
check('ref: player url query hash', Anchor_FM_Vimeo::parse_ref('https://player.vimeo.com/video/123456789?h=abcdef0123'), ['id' => '123456789', 'hash' => 'abcdef0123']);
check('ref: iframe embed keeps hash', Anchor_FM_Vimeo::parse_ref('<iframe src="https://player.vimeo.com/video/123456789?h=abcdef0123&title=0"></iframe>'), ['id' => '123456789', 'hash' => 'abcdef0123']);
check('ref: manage url has no hash', Anchor_FM_Vimeo::parse_ref('https://vimeo.com/manage/videos/123456789'), ['id' => '123456789', 'hash' => '']);
check('ref: trailing slash is not a hash', Anchor_FM_Vimeo::parse_ref('https://vimeo.com/123456789/'), ['id' => '123456789', 'hash' => '']);
check('ref: garbage is empty', Anchor_FM_Vimeo::parse_ref('not a video'), ['id' => '', 'hash' => '']);

// --- Anchor_FM_Vimeo::split_refs (bulk paste) ---
check('split: commas', Anchor_FM_Vimeo::split_refs('123456789, 987654321'), ['123456789', '987654321']);
check('split: newlines', Anchor_FM_Vimeo::split_refs("123456789\n987654321"), ['123456789', '987654321']);
check('split: mixed + blanks', Anchor_FM_Vimeo::split_refs("123456789,\n\n 987654321 ,"), ['123456789', '987654321']);
check('split: empty', Anchor_FM_Vimeo::split_refs('   '), []);
check('split: single', Anchor_FM_Vimeo::split_refs('https://vimeo.com/123456789'), ['https://vimeo.com/123456789']);

// --- Anchor_FM_Vimeo::video_url ---
check('video_url: public', Anchor_FM_Vimeo::video_url('123456789'), 'https://vimeo.com/123456789');
check('video_url: unlisted appends hash', Anchor_FM_Vimeo::video_url('123456789', 'abcdef0123'), 'https://vimeo.com/123456789/abcdef0123');
check('video_url: no id', Anchor_FM_Vimeo::video_url(''), '');

// --- Anchor_FM_Vimeo::fetch_meta ---
// Body captured from the live oEmbed API for vimeo.com/22439234.
$real_body = '{"type":"video","version":"1.0","provider_name":"Vimeo","title":"The Mountain","author_name":"TSO Photography","duration":185,"thumbnail_url":"https:\/\/i.vimeocdn.com\/video\/145027281-cf3e3e047a52e2210b26bbcf42fcde909a80a7dd023a757b95af01936d065ec0-d_295x166?region=us","video_id":22439234}';

$GLOBALS['afm_http_stub'] = ['response' => ['code' => 200], 'body' => $real_body];
check('fetch_meta: reads title', Anchor_FM_Vimeo::fetch_meta('22439234')['title'], 'The Mountain');
check('fetch_meta: reads thumbnail', Anchor_FM_Vimeo::fetch_meta('22439234')['thumbnail_url'], 'https://i.vimeocdn.com/video/145027281-cf3e3e047a52e2210b26bbcf42fcde909a80a7dd023a757b95af01936d065ec0-d_295x166?region=us');
Anchor_FM_Vimeo::fetch_meta('123456789', 'abcdef0123');
check('fetch_meta: hash reaches the endpoint', strpos($GLOBALS['afm_http_last_url'], rawurlencode('https://vimeo.com/123456789/abcdef0123')) !== false, true);

// 404 is ambiguous on the live API: it answers 404 both for a missing video and
// for a real one whose owner blocks embedding. The message must not claim the
// video doesn't exist.
$GLOBALS['afm_http_stub'] = ['response' => ['code' => 404], 'body' => '404 Not Found'];
$e404 = Anchor_FM_Vimeo::fetch_meta('76979871');
check('fetch_meta: 404 is a WP_Error', is_wp_error($e404), true);
check('fetch_meta: 404 does not claim deletion', strpos($e404->get_error_message(), 'blocks embedding') !== false, true);

$GLOBALS['afm_http_stub'] = ['response' => ['code' => 403], 'body' => ''];
check('fetch_meta: 403 mentions the hash', strpos(Anchor_FM_Vimeo::fetch_meta('1')->get_error_message(), 'hash') !== false, true);

$GLOBALS['afm_http_stub'] = ['response' => ['code' => 500], 'body' => ''];
check('fetch_meta: 500 is an error', is_wp_error(Anchor_FM_Vimeo::fetch_meta('1')), true);

$GLOBALS['afm_http_stub'] = ['response' => ['code' => 200], 'body' => 'not json'];
check('fetch_meta: junk body is an error', is_wp_error(Anchor_FM_Vimeo::fetch_meta('1')), true);

$GLOBALS['afm_http_stub'] = ['response' => ['code' => 200], 'body' => '{"title":"No thumb here"}'];
check('fetch_meta: missing thumbnail is not fatal', Anchor_FM_Vimeo::fetch_meta('1')['thumbnail_url'], '');

$GLOBALS['afm_http_stub'] = null;
check('fetch_meta: transport failure surfaces', is_wp_error(Anchor_FM_Vimeo::fetch_meta('1')), true);
check('fetch_meta: empty id never calls out', is_wp_error(Anchor_FM_Vimeo::fetch_meta('')), true);

check('fallback_title', Anchor_FM_Vimeo::fallback_title('123456789'), 'Vimeo video 123456789');

require __DIR__ . '/../includes/class-afm-watch-math.php';

// furthest_point($prev_furthest, $point_seconds, $duration_seconds)
// The furthest position ever reached. No longer drives percent — that comes
// from Anchor_FM_Coverage — but is still recorded.
check('furthest first beat', Anchor_FM_Watch_Math::furthest_point(0, 30, 100), 30);
check('furthest keeps max when user scrubs back', Anchor_FM_Watch_Math::furthest_point(30, 10, 100), 30);
check('furthest advances', Anchor_FM_Watch_Math::furthest_point(30, 90, 100), 90);
check('furthest clamped to duration', Anchor_FM_Watch_Math::furthest_point(0, 500, 100), 100);
check('furthest unknown duration keeps point', Anchor_FM_Watch_Math::furthest_point(0, 500, 0), 500);
check('furthest negative point ignored', Anchor_FM_Watch_Math::furthest_point(20, -5, 100), 20);
check('furthest negative previous treated as zero', Anchor_FM_Watch_Math::furthest_point(-3, 10, 100), 10);
check('furthest never goes backwards when duration shrinks', Anchor_FM_Watch_Math::furthest_point(500, 20, 100), 500);

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
check('single token not header', Anchor_FM_User_Import::is_header_row(['login','Jane','Smith','jane@x.com']), false);
check('one header key not enough', Anchor_FM_User_Import::is_header_row(['Last','Bob','Lee','bob@x.com']), false);
check('two distinct header keys is header', Anchor_FM_User_Import::is_header_row(['email','first name']), true);

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

// --- Anchor_FM_User_Import::normalize_email ---
check('normalize email', Anchor_FM_User_Import::normalize_email('  Jane@X.COM '), 'jane@x.com');

// --- Anchor_FM_User_Import::validate ---
$ok = Anchor_FM_User_Import::validate(['first_name'=>'Jane','last_name'=>'Smith','email'=>'jane@x.com','username'=>'']);
check('valid row ok', $ok['ok'], true);
check('valid row no error', $ok['error'], '');

$bad_email = Anchor_FM_User_Import::validate(['first_name'=>'Jane','last_name'=>'Smith','email'=>'not-an-email','username'=>'']);
check('bad email not ok', $bad_email['ok'], false);
check('bad email message', $bad_email['error'], 'Invalid or missing email');

$no_first = Anchor_FM_User_Import::validate(['first_name'=>'','last_name'=>'Smith','email'=>'jane@x.com','username'=>'']);
check('missing first not ok', $no_first['ok'], false);
check('missing first message', $no_first['error'], 'Missing first name');

$no_last = Anchor_FM_User_Import::validate(['first_name'=>'Jane','last_name'=>'','email'=>'jane@x.com','username'=>'']);
check('missing last not ok', $no_last['ok'], false);
check('missing last message', $no_last['error'], 'Missing last name');

require __DIR__ . '/../includes/class-afm-copy-namer.php';

// --- Anchor_FM_Copy_Namer::split_extension ---
check('split pdf base', Anchor_FM_Copy_Namer::split_extension('report.pdf')[0], 'report');
check('split pdf ext', Anchor_FM_Copy_Namer::split_extension('report.pdf')[1], '.pdf');
check('split no ext folder', Anchor_FM_Copy_Namer::split_extension('My Folder')[1], '');
check('split dotfile no ext', Anchor_FM_Copy_Namer::split_extension('.htaccess')[1], '');
check('split double ext base', Anchor_FM_Copy_Namer::split_extension('archive.tar.gz')[0], 'archive.tar');
check('split double ext ext', Anchor_FM_Copy_Namer::split_extension('archive.tar.gz')[1], '.gz');

// --- Anchor_FM_Copy_Namer::add_copy_suffix ---
check('suffix first', Anchor_FM_Copy_Namer::add_copy_suffix('Report'), 'Report (copy)');
check('suffix second', Anchor_FM_Copy_Namer::add_copy_suffix('Report (copy)'), 'Report (copy 2)');
check('suffix third', Anchor_FM_Copy_Namer::add_copy_suffix('Report (copy 2)'), 'Report (copy 3)');

// --- Anchor_FM_Copy_Namer::next_copy_name ---
check('next file keeps ext', Anchor_FM_Copy_Namer::next_copy_name('report.pdf', true), 'report (copy).pdf');
check('next folder no ext', Anchor_FM_Copy_Namer::next_copy_name('My Folder', false), 'My Folder (copy)');

// --- Anchor_FM_Copy_Namer::resolve_unique ---
check('resolve no collision unchanged', Anchor_FM_Copy_Namer::resolve_unique('report.pdf', ['other.pdf'], true, false), 'report.pdf');
check('resolve forced duplicate', Anchor_FM_Copy_Namer::resolve_unique('report.pdf', [], true, true), 'report (copy).pdf');
check('resolve collide bumps', Anchor_FM_Copy_Namer::resolve_unique('report.pdf', ['report (copy).pdf'], true, true), 'report (copy 2).pdf');
check('resolve case-insensitive collision', Anchor_FM_Copy_Namer::resolve_unique('Report.PDF', ['report.pdf'], true, false), 'Report (copy).PDF');
check('resolve folder forced', Anchor_FM_Copy_Namer::resolve_unique('Docs', ['Docs (copy)'], false, true), 'Docs (copy 2)');

// --- Anchor_FM_Range::parse ---
// A 5000-byte file for every case below unless stated otherwise.
check('range absent', Anchor_FM_Range::parse('', 5000), null);
check('range not bytes unit', Anchor_FM_Range::parse('items=0-99', 5000), null);
check('range garbage', Anchor_FM_Range::parse('bytes=abc', 5000), null);
check('range no dash', Anchor_FM_Range::parse('bytes=100', 5000), null);
check('range multi rejected', Anchor_FM_Range::parse('bytes=0-99,200-299', 5000), null);
check('range reversed rejected', Anchor_FM_Range::parse('bytes=900-100', 5000), null);
check('range zero-byte file', Anchor_FM_Range::parse('bytes=0-', 0), null);

check('range open-ended from zero', Anchor_FM_Range::parse('bytes=0-', 5000),
    ['satisfiable' => true, 'start' => 0, 'end' => 4999]);
check('range open-ended from offset', Anchor_FM_Range::parse('bytes=1000-', 5000),
    ['satisfiable' => true, 'start' => 1000, 'end' => 4999]);
check('range explicit window', Anchor_FM_Range::parse('bytes=500-999', 5000),
    ['satisfiable' => true, 'start' => 500, 'end' => 999]);
check('range end clamped to EOF', Anchor_FM_Range::parse('bytes=1000-99999', 5000),
    ['satisfiable' => true, 'start' => 1000, 'end' => 4999]);
check('range single byte', Anchor_FM_Range::parse('bytes=0-0', 5000),
    ['satisfiable' => true, 'start' => 0, 'end' => 0]);
check('range last byte', Anchor_FM_Range::parse('bytes=4999-', 5000),
    ['satisfiable' => true, 'start' => 4999, 'end' => 4999]);
check('range case-insensitive unit', Anchor_FM_Range::parse('BYTES=0-99', 5000),
    ['satisfiable' => true, 'start' => 0, 'end' => 99]);
check('range tolerates whitespace', Anchor_FM_Range::parse('bytes= 500 - 999 ', 5000),
    ['satisfiable' => true, 'start' => 500, 'end' => 999]);

// Suffix ranges: "the last N bytes". Players use these to read the container
// index (e.g. an MP4 moov atom written at the end of the file).
check('range suffix', Anchor_FM_Range::parse('bytes=-500', 5000),
    ['satisfiable' => true, 'start' => 4500, 'end' => 4999]);
check('range suffix larger than file', Anchor_FM_Range::parse('bytes=-99999', 5000),
    ['satisfiable' => true, 'start' => 0, 'end' => 4999]);
check('range suffix zero unsatisfiable', Anchor_FM_Range::parse('bytes=-0', 5000),
    ['satisfiable' => false]);

check('range start past EOF', Anchor_FM_Range::parse('bytes=5000-', 5000),
    ['satisfiable' => false]);
check('range start well past EOF', Anchor_FM_Range::parse('bytes=99999-100000', 5000),
    ['satisfiable' => false]);

// --- Anchor_FM_Watch_Math::resume_point ---
check('resume normal midpoint', Anchor_FM_Watch_Math::resume_point(300, 1200, false), 300);
check('resume below minimum', Anchor_FM_Watch_Math::resume_point(9, 1200, false), 0);
check('resume at minimum kept', Anchor_FM_Watch_Math::resume_point(10, 1200, false), 10);
check('resume ended clears', Anchor_FM_Watch_Math::resume_point(300, 1200, true), 0);
check('resume within end pad', Anchor_FM_Watch_Math::resume_point(1190, 1200, false), 0);
check('resume exactly at end pad', Anchor_FM_Watch_Math::resume_point(1185, 1200, false), 0);
check('resume just before end pad', Anchor_FM_Watch_Math::resume_point(1184, 1200, false), 1184);
check('resume past duration clamps then pads to zero', Anchor_FM_Watch_Math::resume_point(9999, 1200, false), 0);
check('resume negative point', Anchor_FM_Watch_Math::resume_point(-5, 1200, false), 0);
// Duration 0 means the player never reported a length. The end-pad rule cannot
// apply, so we keep whatever position we have.
check('resume unknown duration keeps point', Anchor_FM_Watch_Math::resume_point(300, 0, false), 300);
check('resume unknown duration below minimum', Anchor_FM_Watch_Math::resume_point(4, 0, false), 0);
// A video shorter than the end pad is always "finished" wherever you stop.
check('resume very short video', Anchor_FM_Watch_Math::resume_point(11, 12, false), 0);
// Unknown duration cannot clamp against length, so a hostile/buggy client
// could otherwise post a value too large for the INT(10) UNSIGNED column.
check('resume unknown duration clamps at 24h', Anchor_FM_Watch_Math::resume_point(999999999, 0, false), 86400);
check('resume unknown duration at clamp boundary', Anchor_FM_Watch_Math::resume_point(86400, 0, false), 86400);
check('resume unknown duration just under clamp', Anchor_FM_Watch_Math::resume_point(86399, 0, false), 86399);
// The clamp applies after the duration clamp, so a long-but-known duration
// still resumes normally below 24h.
check('resume clamp does not disturb known duration', Anchor_FM_Watch_Math::resume_point(4000, 7200, false), 4000);

// --- Anchor_FM_Watch_Math::is_resume_stale ---
$afm_now = strtotime('2026-08-17 12:00:00');
check('stale 29 days is fresh', Anchor_FM_Watch_Math::is_resume_stale('2026-07-19 12:00:00', $afm_now), false);
check('stale 1 day is fresh', Anchor_FM_Watch_Math::is_resume_stale('2026-08-16 12:00:00', $afm_now), false);
// Boundary: exactly 30 days old is still returned; 30 days plus a second is not.
check('stale exactly 30 days is fresh', Anchor_FM_Watch_Math::is_resume_stale('2026-07-18 12:00:00', $afm_now), false);
check('stale 30 days plus a second', Anchor_FM_Watch_Math::is_resume_stale('2026-07-18 11:59:59', $afm_now), true);
check('stale 31 days', Anchor_FM_Watch_Math::is_resume_stale('2026-07-17 12:00:00', $afm_now), true);
// Fail closed on anything we cannot read.
check('stale empty string', Anchor_FM_Watch_Math::is_resume_stale('', $afm_now), true);
check('stale zero date', Anchor_FM_Watch_Math::is_resume_stale('0000-00-00 00:00:00', $afm_now), true);
check('stale garbage', Anchor_FM_Watch_Math::is_resume_stale('not a date', $afm_now), true);
check('stale custom ttl', Anchor_FM_Watch_Math::is_resume_stale('2026-08-10 12:00:00', $afm_now, 5), true);

// --- Anchor_FM_Coverage ---
// Ranges are HALF-OPEN [from, to): mark(0,5) covers seconds 0,1,2,3,4.

// The scenario this whole feature exists for, stated literally:
// five seconds at the intro, five at the end of a 120s video, = 10 seconds.
$cov = Anchor_FM_Coverage::mark('', 0, 5);
$cov = Anchor_FM_Coverage::mark($cov, 115, 120);
check('coverage two disjoint stretches', Anchor_FM_Coverage::count_set($cov), 10);
$cov = Anchor_FM_Coverage::mark($cov, 0, 5); // re-watch the intro
check('coverage rewatch adds nothing', Anchor_FM_Coverage::count_set($cov), 10);
check('coverage percent of 120s video', Anchor_FM_Coverage::percent(10, 120), 8);

check('coverage empty bitset', Anchor_FM_Coverage::count_set(''), 0);
check('coverage single range', Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark('', 0, 5)), 5);
check('coverage adjacent ranges do not double count',
    Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark(Anchor_FM_Coverage::mark('', 0, 5), 5, 10)), 10);
check('coverage overlapping ranges merge',
    Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark(Anchor_FM_Coverage::mark('', 0, 10), 5, 15)), 15);
check('coverage identical range twice',
    Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark(Anchor_FM_Coverage::mark('', 3, 9), 3, 9)), 6);
check('coverage reversed range rejected', Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark('', 9, 3)), 0);
check('coverage zero-length range rejected', Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark('', 5, 5)), 0);
check('coverage negative from clamps to zero', Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark('', -10, 5)), 5);
check('coverage byte boundary 7 to 9', Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark('', 7, 9)), 2);
check('coverage spans many bytes', Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark('', 0, 100)), 100);
check('coverage cap truncates tail', Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark('', 86395, 90000)), 5);
check('coverage entirely beyond cap', Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark('', 90000, 90010)), 0);

// mark_segments must survive whatever a client sends it.
check('coverage segments applied',
    Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark_segments('', [[0,5],[10,15]])), 10);
check('coverage segments empty list',
    Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark_segments('', [])), 0);
check('coverage segments skip malformed',
    Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark_segments('', [[0,5], 'x', [3], null, [10,15]])), 10);
check('coverage segments non-array input',
    Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark_segments('', 'nope')), 0);
check('coverage segments skip object shaped',
    Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark_segments('', [['from'=>0,'to'=>5],[10,15]])), 5);
check('coverage segments skip string keys only',
    Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark_segments('', [['a'=>1,'b'=>2]])), 0);

// percent
check('percent zero duration', Anchor_FM_Coverage::percent(50, 0), 0);
check('percent half', Anchor_FM_Coverage::percent(60, 120), 50);
check('percent floors', Anchor_FM_Coverage::percent(59, 120), 49);
check('percent exact hundred', Anchor_FM_Coverage::percent(120, 120), 100);
check('percent clamps above hundred', Anchor_FM_Coverage::percent(200, 120), 100);
check('percent zero coverage', Anchor_FM_Coverage::percent(0, 120), 0);
check('percent negative coverage', Anchor_FM_Coverage::percent(-5, 120), 0);

// --- Anchor_FM_Media_Progress::clamp_segments ---
require __DIR__ . '/../includes/class-afm-media-progress.php';

check('clamp trims a segment past the duration',
    Anchor_FM_Media_Progress::clamp_segments([[0, 86400]], 120), [[0, 120]]);
check('clamp leaves an in-range segment alone',
    Anchor_FM_Media_Progress::clamp_segments([[10, 40]], 120), [[10, 40]]);
check('clamp leaves a segment ending exactly at the duration',
    Anchor_FM_Media_Progress::clamp_segments([[0, 120]], 120), [[0, 120]]);
check('clamp trims every offending segment',
    Anchor_FM_Media_Progress::clamp_segments([[0, 500], [10, 20], [30, 999]], 120),
    [[0, 120], [10, 20], [30, 120]]);
check('clamp with unknown duration is a no-op',
    Anchor_FM_Media_Progress::clamp_segments([[0, 86400]], 0), [[0, 86400]]);
check('clamp passes malformed entries through untouched',
    Anchor_FM_Media_Progress::clamp_segments([['a', 'b'], [0, 500]], 120), [['a', 'b'], [0, 120]]);
check('clamp on a non-array returns it unchanged',
    Anchor_FM_Media_Progress::clamp_segments('nope', 120), 'nope');
check('clamped segment marks only the real duration',
    Anchor_FM_Coverage::count_set(Anchor_FM_Coverage::mark_segments('',
        Anchor_FM_Media_Progress::clamp_segments([[0, 86400]], 120))), 120);

// --- Anchor_FM_Permission_Policy ---
require __DIR__ . '/../includes/class-afm-permission-policy.php';

$policy = [
    'operator' => 'any',
    'rules' => [
        [
            'operator' => 'all',
            'conditions' => [
                ['type' => 'role', 'role' => 'member'],
                ['type' => 'date', 'start' => '2026-08-01', 'end' => '2026-08-31'],
            ],
        ],
        [
            'operator' => 'all',
            'conditions' => [
                ['type' => 'role', 'role' => 'staff'],
                ['type' => 'date', 'start' => '2026-09-01', 'end' => '2026-09-30'],
            ],
        ],
    ],
];
check('permission policy role and date match',
    Anchor_FM_Permission_Policy::evaluate($policy, ['userId' => 7, 'roles' => ['member']], '2026-08-15'), true);
check('permission policy date blocks matching role',
    Anchor_FM_Permission_Policy::evaluate($policy, ['userId' => 7, 'roles' => ['member']], '2026-09-15'), false);
check('permission policy alternate role rule matches',
    Anchor_FM_Permission_Policy::evaluate($policy, ['userId' => 7, 'roles' => ['staff']], '2026-09-15'), true);

$all_policy = [
    'operator' => 'all',
    'rules' => [
        ['operator' => 'all', 'conditions' => [['type' => 'role', 'role' => 'member']]],
        ['operator' => 'all', 'conditions' => [['type' => 'user', 'userId' => '7']]],
    ],
];
check('permission policy top-level all passes',
    Anchor_FM_Permission_Policy::evaluate($all_policy, ['userId' => 7, 'roles' => ['member']], '2026-08-15'), true);
check('permission policy top-level all fails one rule',
    Anchor_FM_Permission_Policy::evaluate($all_policy, ['userId' => 8, 'roles' => ['member']], '2026-08-15'), false);

$any_rule_policy = [
    'operator' => 'any',
    'rules' => [
        [
            'operator' => 'any',
            'conditions' => [
                ['type' => 'role', 'role' => 'member'],
                ['type' => 'user', 'userId' => '9'],
            ],
        ],
    ],
];
check('permission policy any condition passes on user',
    Anchor_FM_Permission_Policy::evaluate($any_rule_policy, ['userId' => 9, 'roles' => []], '2026-08-15'), true);

$normalized_policy = Anchor_FM_Permission_Policy::normalize([
    'operator' => 'all',
    'rules' => [
        [
            'operator' => 'all',
            'conditions' => [
                ['type' => 'role', 'role' => 'administrator'],
                ['type' => 'role', 'role' => 'Customer+Role'],
                ['type' => 'date', 'start' => '2026-10-31', 'end' => '2026-10-01'],
            ],
        ],
    ],
], ['customerrole']);
check('permission policy normalize filters admin and swaps dates', $normalized_policy, [
    'operator' => 'all',
    'rules' => [
        [
            'operator' => 'all',
            'conditions' => [
                ['type' => 'role', 'role' => 'customerrole'],
                ['type' => 'date', 'start' => '2026-10-01', 'end' => '2026-10-31'],
            ],
        ],
    ],
]);

// --- Anchor_FM_Permission_Index ---
require __DIR__ . '/../includes/class-afm-permission-index.php';

$idx = new Anchor_FM_Permission_Index();
$idx->add_folder(1, 0, 0);
$idx->add_folder(2, 1, 0);
$idx->add_folder(3, 2, 77);
$idx->add_permission('folder', 2, 'role', 'centre', 'view');
$idx->add_permission('folder', 2, 'role', 'centre', 'manage');   // not view: must not surface
$idx->add_permission('folder', 2, 'user', '42', 'manage');
$idx->add_permission('file',  9, 'group', 'anything', 'view');   // unknown subject type
$idx->add_policy('folder', 2, 'view', '{"operator":"all"}');

check('index: folder ancestry', $idx->folder(3), ['parent' => 2, 'owner' => 77]);
check('index: unknown folder is null', $idx->folder(999), null);
check('index: root parent is 0', $idx->folder(1), ['parent' => 0, 'owner' => 0]);

check('index: user capabilities are unfiltered by capability',
    $idx->user_capabilities('folder', 2, '42'), ['manage']);
check('index: user miss returns empty', $idx->user_capabilities('folder', 2, '43'), []);

// The query being mirrored filters capability = 'view'; a role row granting
// 'manage' was never returned by it, and must not start being returned now.
check('index: role lookup keeps the view-only filter',
    $idx->role_view_capabilities('folder', 2, ['centre']), ['view']);
check('index: role miss returns empty',
    $idx->role_view_capabilities('folder', 2, ['subscriber']), []);
check('index: no roles returns empty',
    $idx->role_view_capabilities('folder', 2, []), []);
check('index: unrelated entity returns empty',
    $idx->role_view_capabilities('folder', 7, ['centre']), []);

// MySQL compared these keys under a _ci PAD SPACE collation. Matching that is
// the contract: stricter would revoke access the site currently grants.
check('index: role match is case-insensitive',
    $idx->role_view_capabilities('folder', 2, ['CENTRE']), ['view']);
check('index: role match ignores trailing spaces',
    $idx->role_view_capabilities('folder', 2, ['centre  ']), ['view']);
check('index: leading space is NOT ignored',
    $idx->role_view_capabilities('folder', 2, [' centre']), []);

check('index: unknown subject types are dropped',
    $idx->user_capabilities('file', 9, 'anything'), []);
check('index: policy round-trips', $idx->policy('folder', 2, 'view'), '{"operator":"all"}');
check('index: policy miss is null', $idx->policy('folder', 2, 'manage'), null);
check('index: policy miss on other entity is null', $idx->policy('file', 2, 'view'), null);

// entity_type is part of the key: a file and a folder sharing an id must not
// bleed into each other, which is the same collision the delete path guards.
$idx2 = new Anchor_FM_Permission_Index();
$idx2->add_permission('folder', 5, 'role', 'centre', 'view');
check('index: file and folder ids do not collide',
    $idx2->role_view_capabilities('file', 5, ['centre']), []);

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);

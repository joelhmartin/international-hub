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

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);

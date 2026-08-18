# Video Resume Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every playable video in the file manager remembers where each logged-in user stopped, resumes there on any device, and forgets positions older than 30 days.

**Architecture:** The existing `wp_anchor_fm_video_views` table gains a `source` discriminator and a `resume_seconds` column, so one table, one progress endpoint, and one expiry job serve both Vimeo videos and uploaded video files. Uploaded files get a native `<video>` player, which requires adding HTTP Range support to the file stream endpoint first. All fiddly logic (range parsing, resume rules, staleness) lives in pure static helpers covered by the existing plain-PHP test runner.

**Tech Stack:** WordPress plugin (PHP 7.4+), `$wpdb` + `dbDelta`, jQuery, Vimeo Player SDK, HTML5 `<video>`, WP-Cron. Tests: `php tests/run.php` (plain PHP, no WP bootstrap, WordPress functions stubbed).

**Spec:** `docs/superpowers/specs/2026-08-17-video-resume-design.md`

## Global Constraints

- **Test command is `php tests/run.php`** from the repo root. It prints `ALL PASS` and exits 0, or `N FAILURE(S)` and exits 1. There is no PHPUnit, no WP bootstrap. Only pure static helpers are testable.
- **Assertions use the existing `check($label, $actual, $expected)` helper**, which compares with `===`. Types must match exactly (`0` is not `'0'`).
- **Table names come from `self::table($suffix)`** → `$wpdb->prefix . 'anchor_fm_' . $suffix`. Never hardcode `wp_`.
- **All AJAX handlers** start with `$this->require_nonce();` then `if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);`. Responses go through `$this->json_success($data)` / `$this->json_error($msg, $code)`.
- **Times are WordPress local time.** Rows are written with `current_time('mysql')`. Any comparison must use `current_time('mysql')` or `current_time('timestamp')` — never `NOW()`, `time()`, or `date()`. Mixing the two silently shifts every expiry by the site's UTC offset.
- **`source` is exactly `'vimeo'` or `'file'`.** Any other value is rejected with a 400.
- **Resume constants:** minimum 10 seconds, end-pad 15 seconds, TTL 30 days.
- **Version bumps in two places** whenever the schema changes: the `Version:` plugin header (line 5, drives GitHub update detection per `PLUGIN-UPDATES.md`) and `const VERSION` (line 17, drives `maybe_upgrade_db()`). Both go to `2.11.0`.
- **Commit after every task.** Small, frequent commits.

---

### Task 1: Byte-range header parser

Pure parsing of an HTTP `Range` header. No WordPress, no filesystem. This is the highest-risk logic in the feature and it is 100% unit testable.

**Files:**
- Create: `includes/class-afm-range.php`
- Modify: `tests/run.php` (append cases + one `require`)

**Interfaces:**
- Consumes: nothing.
- Produces: `Anchor_FM_Range::parse(string $header, int $filesize)` returning one of exactly three shapes:
  - `null` — no range, malformed, or multi-range. **Caller serves the full body with `200`.**
  - `['satisfiable' => false]` — range starts past EOF. **Caller sends `416`.**
  - `['satisfiable' => true, 'start' => int, 'end' => int]` — inclusive byte window. **Caller sends `206`.**

- [ ] **Step 1: Write the failing tests**

Append to `tests/run.php`, immediately before the final `echo $failures === 0 ...` line:

```php
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
```

Add the require alongside the existing ones near the top of `tests/run.php` (after `require __DIR__ . '/../includes/class-afm-vimeo.php';`):

```php
require __DIR__ . '/../includes/class-afm-range.php';
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: fatal error — `Failed opening required .../class-afm-range.php`.

- [ ] **Step 3: Write the implementation**

Create `includes/class-afm-range.php`:

```php
<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

/**
 * Parses a single HTTP Range header. Deliberately conservative: anything it
 * does not fully understand returns null, which tells the caller to serve the
 * whole file with a 200 rather than risk sending a wrong byte window.
 */
class Anchor_FM_Range {

    /**
     * @param string $header   raw Range header value, e.g. "bytes=500-999"
     * @param int    $filesize total size of the file on disk
     * @return array|null  null => serve full body (200)
     *                     ['satisfiable'=>false] => 416
     *                     ['satisfiable'=>true,'start'=>int,'end'=>int] => 206
     */
    public static function parse($header, $filesize) {
        $filesize = (int) $filesize;
        if ($filesize <= 0) return null;

        $header = trim((string) $header);
        if ($header === '') return null;
        if (stripos($header, 'bytes=') !== 0) return null;

        $spec = trim(substr($header, 6));
        if ($spec === '') return null;

        // Multi-range ("bytes=0-99,200-299") requires a multipart/byteranges
        // body. No video element needs it, so fall back to the full body.
        if (strpos($spec, ',') !== false) return null;

        $dash = strpos($spec, '-');
        if ($dash === false) return null;

        $from = trim(substr($spec, 0, $dash));
        $to   = trim(substr($spec, $dash + 1));

        // Suffix form: "-500" means the LAST 500 bytes, not "from 0 to 500".
        if ($from === '') {
            if ($to === '' || !ctype_digit($to)) return null;
            $n = (int) $to;
            if ($n <= 0) return ['satisfiable' => false];
            if ($n >= $filesize) {
                return ['satisfiable' => true, 'start' => 0, 'end' => $filesize - 1];
            }
            return ['satisfiable' => true, 'start' => $filesize - $n, 'end' => $filesize - 1];
        }

        if (!ctype_digit($from)) return null;
        $start = (int) $from;
        if ($start >= $filesize) return ['satisfiable' => false];

        if ($to === '') {
            return ['satisfiable' => true, 'start' => $start, 'end' => $filesize - 1];
        }
        if (!ctype_digit($to)) return null;

        $end = (int) $to;
        if ($end < $start) return null;
        if ($end > $filesize - 1) $end = $filesize - 1;

        return ['satisfiable' => true, 'start' => $start, 'end' => $end];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: `ALL PASS`, exit 0. Every pre-existing check must still pass.

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-range.php tests/run.php
git commit -m "feat: add HTTP Range header parser with unit tests"
```

---

### Task 2: Resume rules and staleness check

Pure logic for "what position is worth remembering" and "is this position too old". Both live in the existing watch-math class next to `apply_progress`.

**Files:**
- Modify: `includes/class-afm-watch-math.php`
- Modify: `tests/run.php` (append cases)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Anchor_FM_Watch_Math::RESUME_MIN_SECONDS` = `10`
  - `Anchor_FM_Watch_Math::RESUME_END_PAD_SECONDS` = `15`
  - `Anchor_FM_Watch_Math::RESUME_TTL_DAYS` = `30`
  - `Anchor_FM_Watch_Math::SECONDS_PER_DAY` = `86400`
  - `Anchor_FM_Watch_Math::resume_point(int $point_seconds, int $duration_seconds, bool $ended) : int`
  - `Anchor_FM_Watch_Math::is_resume_stale(string $last_viewed_at, int $now_ts, int $ttl_days = 30) : bool`

- [ ] **Step 1: Write the failing tests**

Append to `tests/run.php` before the final `echo` line:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: fatal error — `Call to undefined method Anchor_FM_Watch_Math::resume_point()`.

- [ ] **Step 3: Write the implementation**

In `includes/class-afm-watch-math.php`, add these constants next to the existing `MAX_BEAT_SECONDS`:

```php
    /** Positions below this are not worth remembering. */
    const RESUME_MIN_SECONDS = 10;

    /** This close to the end counts as finished — resuming here is worse than restarting. */
    const RESUME_END_PAD_SECONDS = 15;

    /** How long a saved position survives without being touched. */
    const RESUME_TTL_DAYS = 30;

    /**
     * WordPress defines DAY_IN_SECONDS, but tests/run.php has no WP bootstrap.
     * A class constant keeps the math identical in both environments without
     * polluting the global namespace.
     */
    const SECONDS_PER_DAY = 86400;
```

Then add these two methods to the class, after `apply_progress()`:

```php
    /**
     * The playhead position worth storing for resume, or 0 for "start over".
     *
     * Deliberately separate from furthest_seconds: resume answers "where did
     * you stop", furthest answers "how far did you ever get". Rewinding and
     * quitting must not read as completion in the watch report.
     *
     * @param int  $point_seconds    current playhead
     * @param int  $duration_seconds total length, 0 when unknown
     * @param bool $ended            player fired its ended event
     * @return int
     */
    public static function resume_point($point_seconds, $duration_seconds, $ended) {
        if ($ended) return 0;

        $point    = max(0, (int) $point_seconds);
        $duration = max(0, (int) $duration_seconds);

        if ($duration > 0 && $point > $duration) {
            $point = $duration;
        }
        if ($point < self::RESUME_MIN_SECONDS) return 0;

        // Unknown duration means the end-pad rule cannot be evaluated; keep
        // the position rather than silently discarding it.
        if ($duration > 0 && $point >= ($duration - self::RESUME_END_PAD_SECONDS)) {
            return 0;
        }
        return $point;
    }

    /**
     * Whether a stored position has aged out. Fails closed: anything we cannot
     * parse is treated as stale, so a bad timestamp can never pin a position
     * open forever.
     *
     * Exactly $ttl_days old is NOT stale; one second older is.
     *
     * @param string $last_viewed_at MySQL datetime in WordPress local time
     * @param int    $now_ts         timestamp in the SAME clock, i.e. current_time('timestamp')
     * @param int    $ttl_days
     * @return bool
     */
    public static function is_resume_stale($last_viewed_at, $now_ts, $ttl_days = self::RESUME_TTL_DAYS) {
        if (!is_string($last_viewed_at)) return true;
        $last_viewed_at = trim($last_viewed_at);
        if ($last_viewed_at === '' || strpos($last_viewed_at, '0000-00-00') === 0) return true;

        $ts = strtotime($last_viewed_at);
        if ($ts === false) return true;

        return ((int) $now_ts - $ts) > ((int) $ttl_days * self::SECONDS_PER_DAY);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: `ALL PASS`, exit 0. The pre-existing `apply_progress` checks must be untouched and still passing.

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-watch-math.php tests/run.php
git commit -m "feat: add resume position rules and 30-day staleness check"
```

---

### Task 3: Schema migration

Add the `source` discriminator and `resume_seconds` to the views table, and swap the unique key so a Vimeo video and an uploaded file can share an id without colliding.

**Files:**
- Modify: `anchor-private-file-manager.php` — line 5 (`Version:` header), line 17 (`const VERSION`), `ensure_videos_table()` (line ~404), `maybe_upgrade_db()` (line ~447)

**Interfaces:**
- Consumes: nothing.
- Produces: `wp_anchor_fm_video_views` with columns `source VARCHAR(10) NOT NULL DEFAULT 'vimeo'` and `resume_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0`, and `UNIQUE KEY source_video_user (source, video_id, user_id)`. The old `UNIQUE KEY video_user` is gone.

- [ ] **Step 1: Bump both version numbers**

Line 5: `* Version: 2.10.1` → `* Version: 2.11.0`
Line 17: `const VERSION = '2.10.1';` → `const VERSION = '2.11.0';`

Both matter. The header drives GitHub update detection (see `PLUGIN-UPDATES.md`); the constant drives `maybe_upgrade_db()`. Bumping only one leaves the schema unmigrated on live sites.

- [ ] **Step 2: Add the columns and the new key to the dbDelta definition**

In `ensure_videos_table()`, replace the `{$views}` CREATE TABLE block with:

```php
        dbDelta("
            CREATE TABLE {$views} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                source VARCHAR(10) NOT NULL DEFAULT 'vimeo',
                video_id BIGINT(20) UNSIGNED NOT NULL,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                furthest_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
                total_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
                resume_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
                percent TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
                sessions INT(10) UNSIGNED NOT NULL DEFAULT 0,
                first_viewed_at DATETIME NOT NULL,
                last_viewed_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source_video_user (source, video_id, user_id),
                KEY video_id (video_id),
                KEY user_id (user_id)
            ) {$charset_collate};
        ");

        self::drop_legacy_views_key();
```

`DEFAULT 'vimeo'` is what makes this a zero-migration change: every existing row is already correct, no backfill needed. `video_id` keeps its name — it now holds either a `videos.id` or a `files.id`, disambiguated by `source`.

- [ ] **Step 3: Add the guarded key drop**

`dbDelta` adds columns and adds new indexes reliably, but it will not drop the old `video_user` unique key. Add this private static method to the class, immediately after `ensure_videos_table()`:

```php
    /**
     * Drop the pre-2.11.0 UNIQUE KEY (video_id, user_id).
     *
     * It is replaced by (source, video_id, user_id); leaving it in place would
     * wrongly forbid a file row and a Vimeo row that happen to share an id.
     * Runs only after dbDelta has created the replacement, so the table is
     * never left without a uniqueness guard. Idempotent — safe to run twice.
     */
    private static function drop_legacy_views_key() {
        global $wpdb;
        $views = self::table('video_views');

        $has_new = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'source_video_user'",
            $views
        ));
        if ($has_new < 1) return; // replacement missing — leave the old key alone

        $has_old = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'video_user'",
            $views
        ));
        if ($has_old > 0) {
            $wpdb->query("ALTER TABLE {$views} DROP INDEX video_user");
        }
    }
```

- [ ] **Step 4: Verify the migration on a real site**

There is no automated coverage for schema work — the test runner has no WordPress. Verify by hand:

```
# In the site's shell (WP-CLI):
wp option update anchor_fm_db_version 0
wp eval 'do_action("init");'
wp db query "DESCRIBE wp_anchor_fm_video_views;"
wp db query "SHOW INDEX FROM wp_anchor_fm_video_views;"
```

Expected: `source` and `resume_seconds` columns present; `source_video_user` unique index present; `video_user` absent. Existing rows still readable with `source = 'vimeo'`:

```
wp db query "SELECT source, COUNT(*) FROM wp_anchor_fm_video_views GROUP BY source;"
```

Run the eval a second time and confirm it does not error — the drop must be idempotent.

- [ ] **Step 5: Run the test suite (regression check)**

Run: `php tests/run.php`
Expected: `ALL PASS`. Nothing in this task touches pure helpers, but the suite must stay green.

- [ ] **Step 6: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: add source and resume_seconds to video_views, swap unique key"
```

---

### Task 4: HTTP Range support in the stream endpoint

Without this a `<video>` element cannot seek, and Safari will not play uploaded video at all. Prerequisite for Task 7.

**Files:**
- Modify: `anchor-private-file-manager.php` — `require_once` block (line ~13), `ajax_stream()` (line ~2819)

**Interfaces:**
- Consumes: `Anchor_FM_Range::parse()` from Task 1.
- Produces: `anchor_fm_stream` responding `200` (no range), `206` + `Content-Range` (valid range), or `416` + `Content-Range: bytes */N` (unsatisfiable), always with `Accept-Ranges: bytes`.

- [ ] **Step 1: Require the new class**

Add to the `require_once` block at the top of `anchor-private-file-manager.php`, after the existing requires:

```php
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-range.php';
```

- [ ] **Step 2: Add the chunked streaming helper**

Add this private static method to the class, immediately before `ajax_stream()`:

```php
    /**
     * Write an inclusive byte window of a file to the output stream.
     *
     * readfile() would pull the whole file into the output buffer, which a
     * multi-hundred-megabyte video will not survive. This reads in bounded
     * chunks and flushes as it goes.
     */
    private static function stream_file_range($path, $start, $end) {
        // Discard WordPress's output buffering, or the body accumulates in
        // memory instead of streaming.
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @set_time_limit(0);

        $fh = @fopen($path, 'rb');
        if (!$fh) return;

        if ($start > 0) { fseek($fh, $start); }

        $remaining = ($end - $start) + 1;
        $chunk = 262144; // 256KB

        while ($remaining > 0 && !feof($fh)) {
            $read = ($remaining > $chunk) ? $chunk : $remaining;
            $buf = fread($fh, $read);
            if ($buf === false || $buf === '') break;
            echo $buf;
            $remaining -= strlen($buf);
            flush();
        }
        fclose($fh);
    }
```

- [ ] **Step 3: Rewrite the response tail of `ajax_stream()`**

Replace everything in `ajax_stream()` from the `$disp = $disposition === 'inline' ...` line through the final `exit;` with:

```php
        $disp = $disposition === 'inline' ? 'inline' : 'attachment';
        $filename = sanitize_file_name($file->original_name);
        $size = (int) filesize($path);

        $raw_range = isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : '';
        $range = Anchor_FM_Range::parse($raw_range, $size);

        // One playthrough issues dozens of range requests. Log only the
        // opening one, or the activity table fills with noise.
        $is_opening = ($range === null) || (isset($range['start']) && (int) $range['start'] === 0);
        if ($is_opening) {
            $this->log_activity($user_id, $disp === 'inline' ? 'preview_file' : 'download_file', 'file', $file_id, []);
        }

        nocache_headers();
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $file->mime_type);
        header('Content-Disposition: ' . $disp . '; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        if (is_array($range) && empty($range['satisfiable'])) {
            header('Content-Range: bytes */' . $size);
            status_header(416);
            exit;
        }

        if ($range === null) {
            header('Content-Length: ' . $size);
            if ($size > 0) {
                self::stream_file_range($path, 0, $size - 1);
            }
            exit;
        }

        $start = (int) $range['start'];
        $end   = (int) $range['end'];

        status_header(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . (($end - $start) + 1));
        self::stream_file_range($path, $start, $end);
        exit;
```

- [ ] **Step 4: Verify against a real site**

Upload an mp4 through the file manager, open the viewer, and copy the `inlineUrl` from the `anchor_fm_preview` AJAX response (browser devtools → Network). Then, with a logged-in session cookie:

```bash
# Full body: expect 200, Accept-Ranges: bytes, Content-Length == file size
curl -sI -b "$COOKIE" "$INLINE_URL"

# Range: expect 206 and "Content-Range: bytes 0-99/<size>", Content-Length: 100
curl -sI -b "$COOKIE" -H 'Range: bytes=0-99' "$INLINE_URL"

# Suffix range: expect 206 covering the last 100 bytes
curl -sI -b "$COOKIE" -H 'Range: bytes=-100' "$INLINE_URL"

# Unsatisfiable: expect 416 and "Content-Range: bytes */<size>"
curl -sI -b "$COOKIE" -H 'Range: bytes=99999999-' "$INLINE_URL"

# Byte-exactness: this must equal the first 100 bytes of the original file
curl -s -b "$COOKIE" -H 'Range: bytes=0-99' "$INLINE_URL" | md5
head -c 100 /path/to/original.mp4 | md5
```

Then confirm the logging fix: play the video, seek around, and check that the activity table gained roughly one row, not dozens:

```
wp db query "SELECT COUNT(*) FROM wp_anchor_fm_activity WHERE action='preview_file' AND entity_id=<file_id>;"
```

Confirm downloads of a non-video file (a PDF) still work unchanged.

- [ ] **Step 5: Run the test suite (regression check)**

Run: `php tests/run.php`
Expected: `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: serve files with HTTP Range support and chunked streaming"
```

---

### Task 5: Generalized progress and resume endpoints

One set of endpoints serving both sources, plus the read path that applies the 30-day staleness rule.

**Files:**
- Create: `includes/class-afm-media-progress.php`
- Modify: `anchor-private-file-manager.php` — `require_once` block, action registration (line ~69), `ajax_vimeo_progress()` (line ~2337)

**Interfaces:**
- Consumes: `Anchor_FM_Watch_Math::apply_progress()`, `::resume_point()`, `::is_resume_stale()` (Task 2); the `source`/`resume_seconds` schema (Task 3).
- Produces:
  - `Anchor_FM_Media_Progress::valid_source(string $source) : bool`
  - `Anchor_FM_Media_Progress::record(string $table, string $source, int $item_id, int $user_id, int $point, int $delta, int $duration, bool $ended, bool $is_new_session, string $now) : void`
  - `Anchor_FM_Media_Progress::read_resume(string $table, string $source, int $item_id, int $user_id, int $now_ts) : int`
  - AJAX action `anchor_fm_media_progress` — POST `source`, `item_id`, `point`, `delta`, `duration`, `ended`, `new_session` → `{saved: true}`
  - AJAX action `anchor_fm_media_resume` — POST `source`, `item_id` → `{resumeSeconds: int}`
  - AJAX action `anchor_fm_vimeo_progress` — kept, delegates with `source='vimeo'`, reads `video_id`

- [ ] **Step 1: Create the shared progress class**

Create `includes/class-afm-media-progress.php`:

```php
<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

/**
 * Read/write of per-user playback progress, shared by Vimeo videos and
 * uploaded video files.
 *
 * The table name is injected rather than resolved here, because table() is a
 * private static on the main plugin class. Permission checks stay in the AJAX
 * handlers, which is where the capability helpers live.
 */
class Anchor_FM_Media_Progress {

    const SOURCE_VIMEO = 'vimeo';
    const SOURCE_FILE  = 'file';

    public static function valid_source($source) {
        return in_array($source, [self::SOURCE_VIMEO, self::SOURCE_FILE], true);
    }

    /**
     * Fold one heartbeat into the user's row, creating it if needed.
     *
     * @param string $now MySQL datetime from current_time('mysql')
     */
    public static function record($table, $source, $item_id, $user_id, $point, $delta, $duration, $ended, $is_new_session, $now) {
        global $wpdb;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT furthest_seconds, total_seconds, sessions FROM {$table}
             WHERE source = %s AND video_id = %d AND user_id = %d",
            $source, $item_id, $user_id
        ), ARRAY_A);

        $merged = Anchor_FM_Watch_Math::apply_progress(
            $existing ?: ['furthest_seconds' => 0, 'total_seconds' => 0],
            $point, $delta, $duration
        );
        $resume = Anchor_FM_Watch_Math::resume_point($point, $duration, $ended);

        if ($existing) {
            $sessions = (int) $existing['sessions'] + ($is_new_session ? 1 : 0);
            $wpdb->update(
                $table,
                [
                    'furthest_seconds' => $merged['furthest_seconds'],
                    'total_seconds'    => $merged['total_seconds'],
                    'resume_seconds'   => $resume,
                    'percent'          => $merged['percent'],
                    'sessions'         => $sessions,
                    'last_viewed_at'   => $now,
                ],
                ['source' => $source, 'video_id' => $item_id, 'user_id' => $user_id],
                ['%d','%d','%d','%d','%d','%s'],
                ['%s','%d','%d']
            );
        } else {
            $wpdb->insert(
                $table,
                [
                    'source'           => $source,
                    'video_id'         => $item_id,
                    'user_id'          => $user_id,
                    'furthest_seconds' => $merged['furthest_seconds'],
                    'total_seconds'    => $merged['total_seconds'],
                    'resume_seconds'   => $resume,
                    'percent'          => $merged['percent'],
                    'sessions'         => 1,
                    'first_viewed_at'  => $now,
                    'last_viewed_at'   => $now,
                ],
                ['%s','%d','%d','%d','%d','%d','%d','%d','%s','%s']
            );
        }
    }

    /**
     * The user's saved position, or 0 when there is none or it has aged out.
     *
     * The staleness check here — not the cron job — is what actually enforces
     * the 30-day rule. WP-Cron only fires on page loads, and a private portal
     * can sit idle for weeks.
     *
     * @param int $now_ts current_time('timestamp'), matching how rows are written
     */
    public static function read_resume($table, $source, $item_id, $user_id, $now_ts) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT resume_seconds, last_viewed_at FROM {$table}
             WHERE source = %s AND video_id = %d AND user_id = %d",
            $source, $item_id, $user_id
        ));

        if (!$row) return 0;
        if (Anchor_FM_Watch_Math::is_resume_stale((string) $row->last_viewed_at, $now_ts)) return 0;

        return (int) $row->resume_seconds;
    }
}
```

- [ ] **Step 2: Require the class and register the actions**

Add to the `require_once` block at the top of `anchor-private-file-manager.php`:

```php
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-media-progress.php';
```

In the constructor, alongside the existing `wp_ajax_anchor_fm_vimeo_progress` registration (line ~69), add:

```php
        add_action('wp_ajax_anchor_fm_media_progress', [$this, 'ajax_media_progress']);
        add_action('wp_ajax_anchor_fm_media_resume', [$this, 'ajax_media_resume']);
```

Leave the existing `wp_ajax_anchor_fm_vimeo_progress` registration in place.

- [ ] **Step 3: Replace `ajax_vimeo_progress()` with the shared implementation**

Replace the whole of `ajax_vimeo_progress()` (line ~2337) with these four methods:

```php
    /**
     * Resolve and authorize a (source, item) pair for the current user.
     * Sends a JSON error and exits on anything invalid.
     */
    private function require_media_access($source, $item_id, $user_id) {
        if (!Anchor_FM_Media_Progress::valid_source($source)) $this->json_error('Bad source');
        if ($item_id <= 0) $this->json_error('Missing item_id');

        $ok = ($source === Anchor_FM_Media_Progress::SOURCE_VIMEO)
            ? $this->can_user_view_video($user_id, $item_id)
            : $this->can_user_view_file($user_id, $item_id);

        if (!$ok) $this->json_error('Forbidden', 403);
    }

    private function handle_progress($source, $item_id) {
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $this->require_media_access($source, $item_id, $user_id);

        Anchor_FM_Media_Progress::record(
            self::table('video_views'),
            $source,
            $item_id,
            $user_id,
            isset($_POST['point']) ? (int) $_POST['point'] : 0,
            isset($_POST['delta']) ? (int) $_POST['delta'] : 0,
            isset($_POST['duration']) ? (int) $_POST['duration'] : 0,
            !empty($_POST['ended']),
            !empty($_POST['new_session']),
            current_time('mysql')
        );

        $this->json_success(['saved' => true]);
    }

    public function ajax_media_progress() {
        $this->require_nonce();
        $source  = isset($_POST['source']) ? sanitize_key((string) $_POST['source']) : '';
        $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
        $this->handle_progress($source, $item_id);
    }

    /**
     * Back-compat shim. file-manager.js is cache-busted by filemtime, but a
     * browser holding the old bundle across a plugin update would otherwise
     * fail every heartbeat. Remove one release after 2.11.0.
     */
    public function ajax_vimeo_progress() {
        $this->require_nonce();
        $item_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
        $this->handle_progress(Anchor_FM_Media_Progress::SOURCE_VIMEO, $item_id);
    }

    public function ajax_media_resume() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $source  = isset($_POST['source']) ? sanitize_key((string) $_POST['source']) : '';
        $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

        $this->require_media_access($source, $item_id, $user_id);

        $this->json_success([
            'resumeSeconds' => Anchor_FM_Media_Progress::read_resume(
                self::table('video_views'),
                $source,
                $item_id,
                $user_id,
                current_time('timestamp')
            ),
        ]);
    }
```

Note the `current_time('timestamp')` in `ajax_media_resume` — it must match the clock `current_time('mysql')` writes with. Using `time()` here would shift every expiry by the site's UTC offset.

- [ ] **Step 4: Verify against a real site**

Play a Vimeo video past 30 seconds, close the viewer, then check the row was written with a resume point and the correct source:

```
wp db query "SELECT source, video_id, user_id, resume_seconds, furthest_seconds, percent FROM wp_anchor_fm_video_views ORDER BY last_viewed_at DESC LIMIT 5;"
```

Expected: one row, `source = 'vimeo'`, `resume_seconds` roughly where you stopped.

Then confirm the staleness read path by ageing the row by hand:

```
wp db query "UPDATE wp_anchor_fm_video_views SET last_viewed_at = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE video_id=<id>;"
```

Call the resume endpoint (devtools console, on a page where the file manager is loaded):

```js
jQuery.post(AnchorFM.ajax, {action:'anchor_fm_media_resume', nonce:AnchorFM.nonce, source:'vimeo', item_id:<id>}).then(console.log)
```

Expected: `resumeSeconds: 0` even though the column still holds a non-zero value — the read-time check is doing the work. Reset `last_viewed_at` to now and confirm the real value comes back.

Also confirm permission enforcement: as a user without access to the folder, the same call must return HTTP 403.

- [ ] **Step 5: Run the test suite (regression check)**

Run: `php tests/run.php`
Expected: `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-afm-media-progress.php anchor-private-file-manager.php
git commit -m "feat: generalized media progress and resume endpoints for both sources"
```

---

### Task 6: Vimeo resume in the browser

Refactor the existing tracking into a player-agnostic adapter (Task 7 reuses it), then seek on open and show the resume bar.

**Files:**
- Modify: `assets/js/file-manager.js` — `mountVimeoPlayer()` (line ~833), `startVideoTracking()` (line ~845), `flushProgress()` (line ~861), `stopVideoTracking()` (line ~877)
- Modify: `assets/css/file-manager.css`

**Interfaces:**
- Consumes: `anchor_fm_media_progress`, `anchor_fm_media_resume` (Task 5).
- Produces (used by Task 7):
  - `vimeoAdapter(player)` and `nativeAdapter(el)` → `{onTimeUpdate, onPause, onEnded, getDuration, seek, unload}`
  - `startMediaTracking(adapter, source, itemId)`
  - `applyResume(adapter, source, itemId)`
  - `renderResumeBar(seconds, onStartOver)`
  - `stopVideoTracking()` — unchanged name, now source-agnostic

- [ ] **Step 1: Replace the tracking block with the adapter version**

Replace everything from `let trackState = null;` through the end of `stopVideoTracking()` with:

```js
    let trackState = null;
    let activeAdapter = null;

    // Vimeo's SDK and HTMLMediaElement expose the same four things under
    // different names. One adapter each keeps the heartbeat logic single-copy.
    function vimeoAdapter(player) {
        return {
            onTimeUpdate: cb => player.on('timeupdate', d => cb(Math.floor((d && d.seconds) || 0))),
            onPause: cb => player.on('pause', cb),
            onEnded: cb => player.on('ended', cb),
            getDuration: () => player.getDuration().then(d => Math.floor(d || 0)).catch(() => 0),
            seek: t => { try { player.setCurrentTime(t); } catch (e) {} },
            unload: () => { try { if (player.unload) player.unload(); } catch (e) {} },
        };
    }

    function nativeAdapter(el) {
        return {
            onTimeUpdate: cb => el.addEventListener('timeupdate', () => cb(Math.floor(el.currentTime || 0))),
            onPause: cb => el.addEventListener('pause', cb),
            onEnded: cb => el.addEventListener('ended', cb),
            // duration is NaN until metadata loads and Infinity for streams.
            getDuration: () => Promise.resolve(isFinite(el.duration) ? Math.floor(el.duration) : 0),
            seek: t => { try { el.currentTime = t; } catch (e) {} },
            unload: () => { try { el.pause(); el.removeAttribute('src'); el.load(); } catch (e) {} },
        };
    }

    function startMediaTracking(adapter, source, itemId) {
        trackState = {
            source: source,
            itemId: itemId,
            lastTime: 0,
            accum: 0,
            duration: 0,
            newSession: true,
            ended: false,
        };
        activeAdapter = adapter;

        adapter.getDuration().then(d => { if (trackState) trackState.duration = d || 0; });

        adapter.onTimeUpdate(function (t) {
            if (!trackState) return;
            const delta = t - trackState.lastTime;
            // Guards against seek jumps counting as watched time. Native
            // timeupdate fires ~4x/sec, so most deltas are 0 or 1.
            if (delta > 0 && delta <= 2) trackState.accum += delta;
            trackState.lastTime = t;
            if (trackState.accum >= 10) flushProgress(false);
        });

        adapter.onPause(function () { flushProgress(false); });

        adapter.onEnded(function () {
            if (trackState) trackState.ended = true;
            flushProgress(true);
        });
    }

    function flushProgress(force) {
        if (!trackState) return;
        if (!force && trackState.accum <= 0) return;

        const payload = {
            source: trackState.source,
            item_id: trackState.itemId,
            point: trackState.lastTime,
            delta: trackState.accum,
            duration: trackState.duration,
            ended: trackState.ended ? 1 : 0,
            new_session: trackState.newSession ? 1 : 0,
        };
        trackState.accum = 0;
        trackState.newSession = false;
        api('anchor_fm_media_progress', payload);
    }

    // Seek to the user's saved position and tell them we did. A silent jump
    // into the middle of a video reads as a bug.
    function applyResume(adapter, source, itemId) {
        api('anchor_fm_media_resume', { source: source, item_id: itemId })
            .done(res => {
                if (!res || !res.success) return;
                const sec = Number(res.data.resumeSeconds) || 0;
                if (sec <= 0) return;

                adapter.seek(sec);
                if (trackState) trackState.lastTime = sec;

                renderResumeBar(sec, function () {
                    adapter.seek(0);
                    if (trackState) {
                        trackState.lastTime = 0;
                        trackState.accum = 0;
                        // Persist the reset immediately, so closing without
                        // watching does not restore the old position.
                        api('anchor_fm_media_progress', {
                            source: source, item_id: itemId,
                            point: 0, delta: 0, duration: trackState.duration,
                            ended: 0, new_session: 0,
                        });
                    }
                });
            });
    }

    function renderResumeBar(seconds, onStartOver) {
        const $bar = $(`<div class="afm__resumeBar">
                <span class="afm__resumeText">Resuming from ${esc(fmtMMSS(seconds))}</span>
                <button type="button" class="afm__resumeReset">Start over</button>
                <button type="button" class="afm__resumeClose" aria-label="Dismiss">&times;</button>
            </div>`);

        $bar.find('.afm__resumeReset').on('click', function () {
            if (typeof onStartOver === 'function') onStartOver();
            $bar.remove();
        });
        $bar.find('.afm__resumeClose').on('click', function () { $bar.remove(); });

        // Insert above the player, not inside it — the file viewer's stage is a
        // black overflow:hidden box and the bar would be buried in it.
        $modalBody.find('.afm__vplayer, .afm__viewerStage').first().before($bar);
    }

    function stopVideoTracking() {
        flushProgress(true);
        if (activeAdapter) activeAdapter.unload();
        activeAdapter = null;
        activePlayer = null;
        trackState = null;
    }
```

`fmtMMSS()` already exists just below this block — no need to define it.

- [ ] **Step 2: Wire the Vimeo mount to the adapter**

Replace `mountVimeoPlayer()` with:

```js
    function mountVimeoPlayer(elId, vimeoId, videoId, vimeoHash) {
        if (!window.Vimeo || !window.Vimeo.Player) return;
        const opts = { id: Number(vimeoId), responsive: true };
        // Unlisted videos need their privacy hash to embed; without it the
        // player only works where the domain is whitelisted on Vimeo.
        if (vimeoHash) opts.h = String(vimeoHash);
        activePlayer = new window.Vimeo.Player(elId, opts);

        const adapter = vimeoAdapter(activePlayer);
        startMediaTracking(adapter, 'vimeo', videoId);
        applyResume(adapter, 'vimeo', videoId);
    }
```

- [ ] **Step 3: Add the resume bar styles**

Append to `assets/css/file-manager.css`:

```css
/* Resume notice shown over a player that opened at a saved position. */
.afm__resumeBar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
    padding: 8px 12px;
    border-radius: 6px;
    background: #1e293b;
    color: #f1f5f9;
    font-size: 13px;
}
.afm__resumeText { flex: 1 1 auto; }
.afm__resumeReset {
    flex: 0 0 auto;
    background: transparent;
    border: 1px solid #64748b;
    color: #f1f5f9;
    border-radius: 4px;
    padding: 3px 10px;
    cursor: pointer;
    font-size: 12px;
}
.afm__resumeReset:hover { background: #334155; }
.afm__resumeClose {
    flex: 0 0 auto;
    background: transparent;
    border: 0;
    color: #94a3b8;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    padding: 0 2px;
}
.afm__resumeClose:hover { color: #f1f5f9; }
```

- [ ] **Step 4: Verify against a real site**

1. Open a Vimeo video, watch past 30 seconds, close the viewer.
2. Reopen it. Expected: the player starts at roughly where you stopped, and the bar reads `Resuming from 0:34`.
3. Click **Start over**. Expected: player jumps to 0, bar disappears. Close and reopen — no resume bar, starts at 0.
4. Watch a video to the end. Close and reopen. Expected: starts at 0, no bar (the `ended` rule cleared it).
5. Watch only 5 seconds, close, reopen. Expected: starts at 0, no bar (below the 10-second minimum).
6. **Cross-device check** — the actual requirement: watch 40 seconds in Chrome, then open the same video in a different browser (or a private window) logged in as the same user. Expected: it resumes at 0:40.
7. Confirm the admin Watch History panel still renders and its percentages are unchanged.

- [ ] **Step 5: Commit**

```bash
git add assets/js/file-manager.js assets/css/file-manager.css
git commit -m "feat: resume Vimeo playback at the saved position with a resume bar"
```

---

### Task 7: Native player for uploaded video files

**Files:**
- Modify: `anchor-private-file-manager.php` — `ajax_preview()` (line ~2752)
- Modify: `assets/js/file-manager.js` — `openFileViewer()` (line ~756)
- Modify: `assets/css/file-manager.css`

**Interfaces:**
- Consumes: Range support (Task 4), the progress/resume endpoints (Task 5), `nativeAdapter` / `startMediaTracking` / `applyResume` (Task 6).
- Produces: `anchor_fm_preview` returning `preview.type === 'video'` for video mimes; the viewer rendering a tracked `<video>` element.

- [ ] **Step 1: Add the video preview type**

In `ajax_preview()`, extend the type branch:

```php
        if (strpos($mime, 'image/') === 0) {
            $type = 'image';
        } elseif ($mime === 'application/pdf') {
            $type = 'pdf';
        } elseif (strpos($mime, 'video/') === 0) {
            // Covers every video extension on the upload allow-list
            // (mp4, mov, m4v, webm). Browser-level playability varies —
            // .mov in particular fails in Firefox — so the client falls back
            // to the download view on a media error.
            $type = 'video';
        } elseif (in_array($mime, ['text/plain', 'text/csv', 'application/json'], true)) {
            $type = 'text';
        }
```

- [ ] **Step 2: Render and track the player**

In `openFileViewer()`, add a `video` branch to the preview chain, before the `else`:

```js
            } else if (prev.type === 'video') {
                body += `<div class="afm__viewerStage afm__viewerStage--video">
                    <video id="afmFPlayer_${esc(file.id)}" class="afm__viewerVideo"
                           controls preload="metadata" playsinline
                           src="${esc(prev.inlineUrl)}"></video>
                </div>`;
```

Then, after the `openViewerModal(...)` call at the end of `openFileViewer()`, mount the player:

```js
            if (prev.type === 'video') {
                mountFilePlayer(file.id, prev.downloadUrl);
            }
```

Add this function next to `mountVimeoPlayer()`:

```js
    function mountFilePlayer(fileId, downloadUrl) {
        const el = document.getElementById('afmFPlayer_' + fileId);
        if (!el) return;

        // .mov is on the upload allow-list but Firefox generally will not play
        // it. Rather than leaving a dead black box, fall back to exactly the
        // pre-player behaviour: the no-preview block plus Download.
        el.addEventListener('error', function () {
            const dl = downloadUrl
                ? `<a class="afm__btn afm__btn--primary" href="${esc(downloadUrl)}"><span class="dashicons dashicons-download"></span> Download</a>`
                : '';
            $(el).closest('.afm__viewerStage').replaceWith(
                `<div class="afm__viewerNone">
                    <span class="dashicons dashicons-format-video"></span>
                    <div>This video can't be played in your browser.</div>
                    <div class="afm__viewerNoneAction">${dl}</div>
                </div>`
            );
            trackState = null;
            activeAdapter = null;
        });

        const adapter = nativeAdapter(el);
        startMediaTracking(adapter, 'file', fileId);

        // duration is NaN until metadata arrives; resume only once we can
        // evaluate the near-end rule and the seek will actually stick.
        el.addEventListener('loadedmetadata', function () {
            if (trackState) trackState.duration = isFinite(el.duration) ? Math.floor(el.duration) : 0;
            applyResume(adapter, 'file', fileId);
        }, { once: true });
    }
```

- [ ] **Step 3: Confirm the close path needs no change**

No edit required here — this step is a verification, not a change.

`closeModal()` calls `stopVideoTracking()` unconditionally at `assets/js/file-manager.js:481`, with no gate on viewer type. Because Task 6 made `stopVideoTracking()` source-agnostic (it drives `activeAdapter`, not the Vimeo player directly), the file player's final heartbeat and teardown already fire on close.

Confirm by reading line 481 and checking the call is not wrapped in a video-type condition. If a later edit has gated it, remove the gate.

- [ ] **Step 4: Add player styles**

Append to `assets/css/file-manager.css`:

```css
.afm__viewerStage--video {
    display: block;
    background: #000;
    border-radius: 6px;
    overflow: hidden;
}
.afm__viewerVideo {
    display: block;
    width: 100%;
    max-height: 70vh;
    background: #000;
}
.afm__viewerNoneAction { margin-top: 12px; }
```

- [ ] **Step 5: Verify against a real site**

1. Upload an `.mp4`. Open it. Expected: a player with native controls, not "No preview available".
2. **Seek forward with the scrubber.** Expected: it seeks. This is the direct test of Task 4 — before Range support it would stall or restart.
3. **Test in Safari.** Expected: it plays. Safari is the browser that hard-fails without `206`.
4. Watch past 30 seconds, close, reopen. Expected: resumes with the bar.
5. Cross-device: same file, different browser, same user. Expected: resumes.
6. Upload a `.mov` and open it in Firefox. Expected: either it plays, or it degrades to the "can't be played" block with a working Download button — never a dead black box.
7. Open a PDF, an image, and a `.txt`. Expected: all unchanged.
8. Check the row landed with the right source:

```
wp db query "SELECT source, video_id, resume_seconds, percent FROM wp_anchor_fm_video_views WHERE source='file';"
```

- [ ] **Step 6: Commit**

```bash
git add anchor-private-file-manager.php assets/js/file-manager.js assets/css/file-manager.css
git commit -m "feat: native player with resume for uploaded video files"
```

---

### Task 8: 30-day expiry cron

The read-time check from Task 5 already enforces the rule. This job stops stale values from sitting in the column indefinitely.

**Files:**
- Modify: `anchor-private-file-manager.php` — constants block, constructor, `activate()`, end of file (line ~3540)

**Interfaces:**
- Consumes: `Anchor_FM_Watch_Math::RESUME_TTL_DAYS` (Task 2), the `resume_seconds` column (Task 3).
- Produces: cron hook `anchor_fm_prune_resume` running daily; `Anchor_Private_File_Manager::deactivate()`.

- [ ] **Step 1: Add the cron constant and hook**

Add to the constants block near `OPT_DB_VERSION` (line ~26):

```php
    const CRON_PRUNE_RESUME = 'anchor_fm_prune_resume';
```

In the constructor, alongside the other `add_action` calls:

```php
        add_action(self::CRON_PRUNE_RESUME, [$this, 'cron_prune_resume']);
```

And immediately after `$this->maybe_upgrade_db();` (line ~91), self-heal the schedule so sites that upgrade rather than reactivate still get it:

```php
        if (!wp_next_scheduled(self::CRON_PRUNE_RESUME)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_PRUNE_RESUME);
        }
```

- [ ] **Step 2: Add the prune job**

Add this public method to the class, next to the other AJAX/cron handlers:

```php
    /**
     * Zero out playback positions that have aged past the TTL.
     *
     * Housekeeping only — the authoritative expiry is the staleness check on
     * the read path, because WP-Cron fires on page loads and a private portal
     * can go untouched for weeks.
     *
     * Batched so a large table is never held under one long write lock.
     */
    public function cron_prune_resume() {
        global $wpdb;
        $views = self::table('video_views');
        $ttl   = (int) Anchor_FM_Watch_Math::RESUME_TTL_DAYS;
        $batch = 5000;

        for ($i = 0; $i < 10; $i++) {
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$views} SET resume_seconds = 0
                 WHERE resume_seconds > 0
                   AND last_viewed_at < DATE_SUB(%s, INTERVAL %d DAY)
                 LIMIT %d",
                current_time('mysql'), $ttl, $batch
            ));
            if ($affected === false || (int) $affected < $batch) break;
        }
    }
```

`current_time('mysql')` rather than `NOW()`: rows are written in WordPress local time, and `NOW()` is the database server's clock. On a site whose timezone is not UTC, mixing them shifts every expiry by the offset.

- [ ] **Step 3: Schedule on activation, clear on deactivation**

At the end of `activate()`, add:

```php
        if (!wp_next_scheduled(self::CRON_PRUNE_RESUME)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_PRUNE_RESUME);
        }
```

Add a static deactivate method to the class:

```php
    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_PRUNE_RESUME);
    }
```

And register it at the bottom of the file, next to the existing activation hook (line ~3540). The plugin has no deactivation hook today, so this line is new:

```php
register_deactivation_hook(__FILE__, ['Anchor_Private_File_Manager', 'deactivate']);
```

- [ ] **Step 4: Verify against a real site**

```
# The event is scheduled
wp cron event list | grep anchor_fm_prune_resume

# Seed a stale row and a fresh one
wp db query "UPDATE wp_anchor_fm_video_views SET resume_seconds=120, last_viewed_at=DATE_SUB(NOW(), INTERVAL 45 DAY) WHERE id=<stale_id>;"
wp db query "UPDATE wp_anchor_fm_video_views SET resume_seconds=120, last_viewed_at=NOW() WHERE id=<fresh_id>;"

# Run it now
wp cron event run anchor_fm_prune_resume

# Stale row -> resume_seconds 0; fresh row -> still 120.
# Watch stats must be untouched on BOTH rows.
wp db query "SELECT id, resume_seconds, furthest_seconds, total_seconds, percent, sessions FROM wp_anchor_fm_video_views WHERE id IN (<stale_id>,<fresh_id>);"
```

The `furthest_seconds` / `total_seconds` / `percent` / `sessions` check is the important one: only the resume point may be cleared, so the admin Watch History report stays accurate.

Then deactivate and reactivate the plugin and confirm the event unschedules and reschedules:

```
wp plugin deactivate anchor-private-file-manager && wp cron event list | grep anchor_fm_prune_resume
wp plugin activate anchor-private-file-manager && wp cron event list | grep anchor_fm_prune_resume
```

- [ ] **Step 5: Run the test suite (regression check)**

Run: `php tests/run.php`
Expected: `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: daily cron pruning resume positions older than 30 days"
```

---

### Task 9: Admin watch history for uploaded videos

The admin Watch History panel currently only works for Vimeo. Extend it to the `file` source so the new player is not a blind spot in reporting.

**Files:**
- Modify: `anchor-private-file-manager.php` — `ajax_vimeo_history()` (line ~2390)
- Modify: `assets/js/file-manager.js` — `renderVideoViewer()` (line ~822), `loadVideoHistory()` (line ~888), `openFileViewer()` (line ~756)

**Interfaces:**
- Consumes: the `source` column (Task 3), `Anchor_FM_Media_Progress::valid_source()` (Task 5), `mountFilePlayer` (Task 7).
- Produces: `anchor_fm_vimeo_history` accepting `source` + `item_id`; `loadVideoHistory(source, itemId)`.

- [ ] **Step 1: Filter the history query by source**

Replace the body of `ajax_vimeo_history()` from the `$video_id = ...` line through the `$rows = $wpdb->get_results(...)` call with:

```php
        $source  = isset($_POST['source']) ? sanitize_key((string) $_POST['source']) : Anchor_FM_Media_Progress::SOURCE_VIMEO;
        $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

        // Back-compat with callers still sending video_id.
        if ($item_id <= 0 && isset($_POST['video_id'])) {
            $item_id = (int) $_POST['video_id'];
        }

        if (!Anchor_FM_Media_Progress::valid_source($source)) $this->json_error('Bad source');
        if ($item_id <= 0) $this->json_error('Missing item_id');

        global $wpdb;
        $views = self::table('video_views');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, furthest_seconds, total_seconds, percent, sessions, last_viewed_at
             FROM {$views} WHERE source = %s AND video_id = %d ORDER BY last_viewed_at DESC LIMIT 500",
            $source, $item_id
        ));
```

The existing administrator check above it stays exactly as-is.

- [ ] **Step 2: Pass the source from the client**

Change `loadVideoHistory` to take both arguments:

```js
    function loadVideoHistory(source, itemId) {
        api('anchor_fm_vimeo_history', { source: source, item_id: itemId }).then(res => {
```

The rest of the function body is unchanged.

Update the Vimeo call site in `renderVideoViewer()`:

```js
        if (AnchorFM.isAdmin) loadVideoHistory('vimeo', videoId);
```

- [ ] **Step 3: Show the panel in the file video viewer**

In `openFileViewer()`, inside the `prev.type === 'video'` path, append the history container to `body` just as `renderVideoViewer()` does — add this right after the video-stage string is appended:

```js
                if (AnchorFM.isAdmin) {
                    body += `<div class="afm__vhistory" data-afm-video-history><div class="afm__sectionTitle">Watch history</div><div class="afm__vhistoryBody">Loading…</div></div>`;
                }
```

And next to the `mountFilePlayer(...)` call added in Task 7:

```js
                if (AnchorFM.isAdmin) loadVideoHistory('file', file.id);
```

- [ ] **Step 4: Verify against a real site**

1. As an administrator, open an uploaded mp4 that at least one user has watched. Expected: the Watch History panel lists that user with a sensible percentage.
2. Open a Vimeo video. Expected: its history is unchanged and does **not** include rows from files that share an id — this is the check that the `source` filter and the new unique key are both working.
3. As a non-administrator, open both. Expected: no history panel, and a direct call to `anchor_fm_vimeo_history` returns 403.

- [ ] **Step 5: Run the test suite (regression check)**

Run: `php tests/run.php`
Expected: `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add anchor-private-file-manager.php assets/js/file-manager.js
git commit -m "feat: watch history for uploaded video files, filtered by source"
```

---

## Done criteria

- `php tests/run.php` prints `ALL PASS`.
- A Vimeo video and an uploaded mp4 both resume where the user stopped, on a different browser/device, logged in as the same user.
- Seeking works in an uploaded video, including in Safari.
- A finished video, a video watched under 10 seconds, and a position older than 30 days all start from the beginning.
- Clearing a resume position never changes `furthest_seconds`, `total_seconds`, `percent`, or `sessions`.
- A `.mov` that the browser cannot decode falls back to the download view.

# True Watch Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the watch percentage reflect how much of a video was actually played, not how far the scrubber was dragged, and show each user their own progress as a ring on listing rows.

**Architecture:** Coverage is stored as one bit per second of video in a BLOB column. Marking a range twice sets the same bits, so re-watching contributes nothing and two disjoint 5-second stretches total exactly 10 seconds. The browser stops reporting "furthest point + elapsed" and instead reports the segments it actually played. Percent and covered-time are derived on write and stored, so reads never decode the bitset.

**Tech Stack:** WordPress plugin (PHP 7.4+), `$wpdb` + `dbDelta`, jQuery, HTML5 `<video>` + Vimeo Player SDK, inline SVG. Tests: `php tests/run.php` (plain PHP, no WP bootstrap).

**Spec:** `docs/superpowers/specs/2026-08-21-watch-coverage-design.md`

## Global Constraints

- **Test command is `php tests/run.php`** from the repo root — prints `ALL PASS` / exits 0, or `N FAILURE(S)` / exits 1. No PHPUnit, no WordPress bootstrap, **no JavaScript test harness at all**. Only pure static helpers are testable. The suite currently has **152 checks**.
- Assertions use `check($label, $actual, $expected)`, comparing with `===`. Types must match exactly (`0` is not `'0'`, `false` is not `0`).
- **Ranges are half-open `[from, to)`.** A segment from `t=0` to `t=5` covers seconds 0,1,2,3,4 — five seconds. This is what makes "5 seconds at the intro plus 5 at the end equals 10" come out exactly right, and what stops `0–5` followed by `5–10` from double-counting second 5. Every range in this plan uses this convention.
- Bit `N` lives in byte `intdiv(N, 8)` at position `N % 8`, counted from the least-significant bit.
- Coverage is capped at `Anchor_FM_Coverage::MAX_SECONDS` = `86400` (24h), matching `Anchor_FM_Watch_Math::RESUME_MAX_SECONDS`.
- **Times are WordPress local time** (`current_time()`), never `NOW()` / `time()` / `date()`. The one legitimate `time()` is in `wp_schedule_event`.
- Table names come from `self::table($suffix)`. Never hardcode `wp_`.
- **Two version numbers must both read `2.12.0`**: the `Version:` plugin header (line 5, drives GitHub update detection) and `const VERSION` (line 19, drives `maybe_upgrade_db()`).
- **`resume_seconds` and the resume feature must not change behaviour.** They are independent of coverage.
- The listing badge shows only the requesting user's own progress. No endpoint accepts a caller-supplied `user_id`.
- Commit after every task.

---

### Task 1: Coverage bitset

Pure bit manipulation — no WordPress, no I/O, no database. This is the heart of the feature and it is fully unit-testable, so it is built first and completely.

**Files:**
- Create: `includes/class-afm-coverage.php`
- Modify: `tests/run.php` (add a `require` and the checks below)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Anchor_FM_Coverage::MAX_SECONDS` = `86400`
  - `Anchor_FM_Coverage::mark(string $bits, int $from, int $to) : string` — sets bits for `[from, to)`, returns the new bitset
  - `Anchor_FM_Coverage::mark_segments(string $bits, array $segments) : string` — applies many `[from, to]` pairs, skipping malformed entries
  - `Anchor_FM_Coverage::count_set(string $bits) : int` — distinct seconds covered
  - `Anchor_FM_Coverage::percent(int $covered_seconds, int $duration_seconds) : int` — 0–100

- [ ] **Step 1: Write the failing tests**

Add the require near the other requires at the top of `tests/run.php`:

```php
require __DIR__ . '/../includes/class-afm-coverage.php';
```

Append these checks immediately before the final `echo $failures === 0 ? ... ;` line:

```php
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

// percent
check('percent zero duration', Anchor_FM_Coverage::percent(50, 0), 0);
check('percent half', Anchor_FM_Coverage::percent(60, 120), 50);
check('percent floors', Anchor_FM_Coverage::percent(59, 120), 49);
check('percent exact hundred', Anchor_FM_Coverage::percent(120, 120), 100);
check('percent clamps above hundred', Anchor_FM_Coverage::percent(200, 120), 100);
check('percent zero coverage', Anchor_FM_Coverage::percent(0, 120), 0);
check('percent negative coverage', Anchor_FM_Coverage::percent(-5, 120), 0);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: fatal error — `Failed opening required .../class-afm-coverage.php`.

- [ ] **Step 3: Write the implementation**

Create `includes/class-afm-coverage.php`:

```php
<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

/**
 * Which seconds of a video a user has actually played, as a bitset.
 *
 * One bit per second: bit N set means second N was played. Marking the same
 * range twice sets the same bits, so re-watching contributes nothing — the
 * "you can't count those seconds again" rule is a property of the structure
 * rather than logic layered on top.
 *
 * Ranges are HALF-OPEN [from, to): mark(0, 5) covers seconds 0,1,2,3,4. That
 * is what makes two disjoint five-second stretches total exactly ten, and
 * what stops 0-5 followed by 5-10 from counting second 5 twice.
 *
 * Size is bounded by duration no matter how erratically someone scrubs:
 * ceil(duration / 8) bytes. A 15-minute video is 113 bytes.
 */
class Anchor_FM_Coverage {

    /** Longest video we will track, in seconds. Matches RESUME_MAX_SECONDS. */
    const MAX_SECONDS = 86400;

    /**
     * Set the bits for [from, to). Out-of-range input is clamped; a reversed
     * or empty range leaves the bitset untouched.
     *
     * @param string $bits existing bitset (binary string; '' for none)
     * @return string the updated bitset
     */
    public static function mark($bits, $from, $to) {
        $bits = is_string($bits) ? $bits : '';
        $from = (int) $from;
        $to   = (int) $to;

        if ($from < 0) $from = 0;
        if ($to > self::MAX_SECONDS) $to = self::MAX_SECONDS;
        if ($to <= $from) return $bits;

        $needed = intdiv($to - 1, 8) + 1;
        if (strlen($bits) < $needed) {
            $bits = str_pad($bits, $needed, "\0");
        }

        for ($n = $from; $n < $to; $n++) {
            $i = intdiv($n, 8);
            $bits[$i] = chr(ord($bits[$i]) | (1 << ($n % 8)));
        }
        return $bits;
    }

    /**
     * Apply a list of [from, to] pairs. Anything that is not a pair of
     * numbers is skipped rather than trusted — this input arrives from the
     * browser.
     *
     * @param mixed $segments expected array of [from, to]
     */
    public static function mark_segments($bits, $segments) {
        $bits = is_string($bits) ? $bits : '';
        if (!is_array($segments)) return $bits;

        foreach ($segments as $seg) {
            if (!is_array($seg) || count($seg) < 2) continue;
            if (!is_numeric($seg[0]) || !is_numeric($seg[1])) continue;
            $bits = self::mark($bits, (int) $seg[0], (int) $seg[1]);
        }
        return $bits;
    }

    /** How many distinct seconds are covered. */
    public static function count_set($bits) {
        if (!is_string($bits) || $bits === '') return 0;

        $count = 0;
        $len = strlen($bits);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($bits[$i]);
            // Brian Kernighan: clears the lowest set bit each pass.
            while ($b) { $b &= $b - 1; $count++; }
        }
        return $count;
    }

    /** Whole-percent coverage, 0-100. Unknown duration yields 0, not a divide by zero. */
    public static function percent($covered_seconds, $duration_seconds) {
        $covered  = (int) $covered_seconds;
        $duration = (int) $duration_seconds;
        if ($duration <= 0 || $covered <= 0) return 0;

        $pct = (int) floor(($covered / $duration) * 100);
        if ($pct > 100) return 100;
        if ($pct < 0) return 0;
        return $pct;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: `ALL PASS`. Every pre-existing check must still pass — this task adds a file and adds checks, it changes nothing existing.

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-coverage.php tests/run.php
git commit -m "feat: add per-second watch coverage bitset with unit tests"
```

---

### Task 2: Retire percent-from-furthest

`apply_progress()` derives `percent` from `furthest_seconds` and accumulates `total_seconds` from a client-reported `delta`. Both become wrong under coverage: percent now comes from the bitset, and `total_seconds` becomes the covered-seconds count. What remains of that function is a single `max()`, so it is replaced by a function that says what it does.

**Files:**
- Modify: `includes/class-afm-watch-math.php`
- Modify: `tests/run.php:124-149` (replace the `apply_progress` block)

**Interfaces:**
- Consumes: nothing.
- Produces: `Anchor_FM_Watch_Math::furthest_point(int $prev_furthest, int $point_seconds, int $duration_seconds) : int`
- Removes: `Anchor_FM_Watch_Math::apply_progress()` and `Anchor_FM_Watch_Math::MAX_BEAT_SECONDS` (the per-beat delta clamp has nothing left to clamp).
- Unchanged and still required: `resume_point()`, `is_resume_stale()`, `RESUME_MIN_SECONDS`, `RESUME_END_PAD_SECONDS`, `RESUME_TTL_DAYS`, `RESUME_MAX_SECONDS`, `SECONDS_PER_DAY`.

- [ ] **Step 1: Replace the failing tests**

In `tests/run.php`, delete the whole block from the comment `// apply_progress($existing, ...` through `check('zero duration percent', $r5['percent'], 0);` (currently lines 124-149) and put this in its place:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: fatal error — `Call to undefined method Anchor_FM_Watch_Math::furthest_point()`.

- [ ] **Step 3: Write the implementation**

In `includes/class-afm-watch-math.php`:

Delete the `MAX_BEAT_SECONDS` constant and the entire `apply_progress()` method. Add in their place:

```php
    /**
     * The furthest position the viewer has ever reached.
     *
     * Kept as a factual record only. It deliberately does NOT drive the
     * watch percentage any more: dragging the scrubber to the end moves this
     * value without playing a frame, which is exactly the bug coverage
     * tracking exists to fix. Percent comes from Anchor_FM_Coverage.
     */
    public static function furthest_point($prev_furthest, $point_seconds, $duration_seconds) {
        $prev     = max(0, (int) $prev_furthest);
        $point    = max(0, (int) $point_seconds);
        $duration = max(0, (int) $duration_seconds);

        $furthest = max($prev, $point);
        if ($duration > 0 && $furthest > $duration) {
            $furthest = $duration;
        }
        return $furthest;
    }
```

Leave `resume_point()`, `is_resume_stale()` and every constant other than `MAX_BEAT_SECONDS` exactly as they are.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: `ALL PASS`. The resume and coverage checks must all still pass.

- [ ] **Step 5: Verify nothing else calls the removed API**

Run: `grep -rn "apply_progress\|MAX_BEAT_SECONDS" --include="*.php" --include="*.js" .`
Expected: matches only in `docs/` (historical specs and plans). If any match appears in `includes/`, `tests/`, `assets/` or `anchor-private-file-manager.php`, that call site is broken and must be fixed before committing — Task 4 rewrites the one in `class-afm-media-progress.php`, so a match there is expected at this point and is fixed in that task.

- [ ] **Step 6: Commit**

```bash
git add includes/class-afm-watch-math.php tests/run.php
git commit -m "refactor: replace apply_progress with furthest_point, drop percent-from-furthest"
```

---

### Task 3: Schema and the one-time reset

**Files:**
- Modify: `anchor-private-file-manager.php` — line 5 (`Version:` header), line 19 (`const VERSION`), `ensure_videos_table()`, `maybe_upgrade_db()`

**Interfaces:**
- Consumes: nothing.
- Produces: `wp_anchor_fm_video_views` with `watched_bits MEDIUMBLOB NULL` and `duration_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0`; all pre-existing rows reset to `percent = 0`, `total_seconds = 0`, `watched_bits = NULL`.

- [ ] **Step 1: Bump both version numbers**

Line 5: `* Version: 2.11.1` → `* Version: 2.12.0`
Line 19: `const VERSION = '2.11.1';` → `const VERSION = '2.12.0';`

Both matter. The header drives GitHub update detection; the constant drives `maybe_upgrade_db()`.

- [ ] **Step 2: Add the columns**

In `ensure_videos_table()`, in the `{$views}` CREATE TABLE block, add these two lines immediately after the `resume_seconds` line:

```sql
                watched_bits MEDIUMBLOB NULL,
                duration_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
```

Leave every other column, the `source_video_user` unique key, and the `{$videos}` block exactly as they are. `MEDIUMBLOB` holds 16 MB — vastly more than the 10.8 KB cap needs, and dbDelta handles it as a plain column add.

- [ ] **Step 3: Add the one-time reset**

Coverage cannot be reconstructed from `furthest_seconds`, so existing percentages are cleared rather than seeded with a guess. Add this private static method next to `drop_legacy_views_key()`:

```php
    /**
     * Clear pre-coverage watch percentages.
     *
     * Before 2.12.0 `percent` measured the furthest point the scrubber
     * reached, and `total_seconds` counted time elapsed in the player. Neither
     * can be converted into "which seconds were actually played", so both are
     * reset rather than carried forward as an invented number. Everyone reads
     * 0% until they watch again — intended, and chosen deliberately over
     * seeding coverage as 0..furthest_seconds, which would have preserved the
     * look of the report by fabricating data.
     *
     * resume_seconds, furthest_seconds, sessions and the timestamps are left
     * untouched.
     */
    private static function reset_pre_coverage_watch_stats() {
        global $wpdb;
        $views = self::table('video_views');
        $wpdb->query("UPDATE {$views} SET percent = 0, total_seconds = 0, watched_bits = NULL");
    }
```

- [ ] **Step 4: Run it once, on upgrade only**

Replace the body of `maybe_upgrade_db()` with:

```php
    private function maybe_upgrade_db() {
        $installed = (string) get_option(self::OPT_DB_VERSION, '0');
        if (version_compare($installed, self::VERSION, '<')) {
            // Whether this site predates coverage tracking. Must be captured
            // before the option is bumped, and applied only once.
            $pre_coverage = version_compare($installed, '2.12.0', '<');

            self::ensure_links_table();
            $views_ok = self::ensure_videos_table();

            if ($views_ok && $pre_coverage) {
                self::reset_pre_coverage_watch_stats();
            }
            if ($views_ok) {
                update_option(self::OPT_DB_VERSION, self::VERSION);
            }
        }
    }
```

The `$views_ok` gate is pre-existing behaviour from 2.11.1 and must be preserved: the version is bumped only when `ensure_videos_table()` confirms the schema is right, so a failed `dbDelta` retries on the next request instead of wedging permanently. The reset is inside that same gate, so it never runs against a half-migrated table.

- [ ] **Step 5: Run the test suite (regression check)**

Run: `php tests/run.php`
Expected: `ALL PASS`. Nothing here is unit-testable; the suite must simply stay green.

- [ ] **Step 6: Verify the migration against a real site**

You have no WordPress here. Record these commands in your report for a human to run against a restored database copy:

```bash
wp option update anchor_fm_db_version 0
wp eval 'do_action("init");'
wp db query "DESCRIBE wp_anchor_fm_video_views;"
wp db query "SELECT COUNT(*) total, SUM(percent) sum_pct, SUM(total_seconds) sum_secs, SUM(watched_bits IS NOT NULL) with_bits FROM wp_anchor_fm_video_views;"
wp db query "SELECT SUM(resume_seconds) resume_kept, SUM(furthest_seconds) furthest_kept, SUM(sessions) sessions_kept FROM wp_anchor_fm_video_views;"
wp option get anchor_fm_db_version
```

Expect: `watched_bits` and `duration_seconds` columns present; `sum_pct`, `sum_secs` and `with_bits` all `0`; `resume_kept`, `furthest_kept` and `sessions_kept` all unchanged from before; and `anchor_fm_db_version` reading `2.12.0`. Run `wp eval` a second time and confirm it is a clean no-op.

- [ ] **Step 7: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: add coverage columns and reset pre-coverage watch stats"
```

---

### Task 4: Server write path

**Files:**
- Modify: `includes/class-afm-media-progress.php` — `record()`
- Modify: `anchor-private-file-manager.php` — the `require_once` block, `handle_progress()`

**Interfaces:**
- Consumes: `Anchor_FM_Coverage::mark_segments()`, `::count_set()`, `::percent()` (Task 1); `Anchor_FM_Watch_Math::furthest_point()` (Task 2); the `watched_bits` / `duration_seconds` columns (Task 3).
- Produces: `Anchor_FM_Media_Progress::record($table, $source, $item_id, $user_id, array $segments, $point, $duration, $ended, $is_new_session, $now, $reset = false) : bool` — note `$delta` is gone and `$segments` takes its place, earlier in the argument list.
- The `anchor_fm_media_progress` endpoint now accepts a `segments` POST field: a JSON-encoded array of `[from, to]` pairs. `delta` is no longer read.

- [ ] **Step 1: Require the coverage class**

Add to the `require_once` block at the top of `anchor-private-file-manager.php`:

```php
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-coverage.php';
```

- [ ] **Step 2: Rewrite `record()`**

Replace the whole of `Anchor_FM_Media_Progress::record()` with:

```php
    /**
     * Fold one heartbeat into the user's row, creating it if needed.
     *
     * @param array  $segments [[from, to], ...] half-open second ranges played
     *                         since the last beat
     * @param string $now      MySQL datetime from current_time('mysql')
     * @return bool false only when the underlying $wpdb call returned exactly
     *              false. See the note on the return of the write below.
     */
    public static function record($table, $source, $item_id, $user_id, $segments, $point, $duration, $ended, $is_new_session, $now, $reset = false) {
        global $wpdb;

        $point    = max(0, (int) $point);
        $duration = max(0, (int) $duration);
        $segments = is_array($segments) ? $segments : [];

        // A heartbeat carrying nothing at all is not a save. This is the shape
        // produced by opening and closing a video before metadata arrives;
        // writing it would clobber a real position and percentage with zeros.
        // "Start over" ($reset) and finishing ($ended) are real events and
        // still write, as does any beat that actually played something.
        if ($point <= 0 && empty($segments) && !$ended && !$reset) {
            return true; // nothing to record, and not a failure
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT furthest_seconds, sessions, watched_bits, duration_seconds FROM {$table}
             WHERE source = %s AND video_id = %d AND user_id = %d",
            $source, $item_id, $user_id
        ), ARRAY_A);

        // Keep the longest duration we have ever been told. A beat sent before
        // metadata loads reports 0, and that must not erase a known duration.
        $known_duration = $existing ? (int) $existing['duration_seconds'] : 0;
        if ($duration > $known_duration) {
            $known_duration = $duration;
        }

        $bits = $existing && $existing['watched_bits'] !== null ? (string) $existing['watched_bits'] : '';
        $bits = Anchor_FM_Coverage::mark_segments($bits, $segments);

        $covered  = Anchor_FM_Coverage::count_set($bits);
        $percent  = Anchor_FM_Coverage::percent($covered, $known_duration);
        $furthest = Anchor_FM_Watch_Math::furthest_point(
            $existing ? (int) $existing['furthest_seconds'] : 0,
            $point,
            $known_duration
        );
        $resume = Anchor_FM_Watch_Math::resume_point($point, $known_duration, $ended);

        if ($existing) {
            $sessions = (int) $existing['sessions'] + ($is_new_session ? 1 : 0);
            $result = $wpdb->update(
                $table,
                [
                    'furthest_seconds' => $furthest,
                    'total_seconds'    => $covered,
                    'resume_seconds'   => $resume,
                    'percent'          => $percent,
                    'sessions'         => $sessions,
                    'watched_bits'     => $bits,
                    'duration_seconds' => $known_duration,
                    'last_viewed_at'   => $now,
                ],
                ['source' => $source, 'video_id' => $item_id, 'user_id' => $user_id],
                ['%d','%d','%d','%d','%d','%s','%d','%s'],
                ['%s','%d','%d']
            );
        } else {
            $result = $wpdb->insert(
                $table,
                [
                    'source'           => $source,
                    'video_id'         => $item_id,
                    'user_id'          => $user_id,
                    'furthest_seconds' => $furthest,
                    'total_seconds'    => $covered,
                    'resume_seconds'   => $resume,
                    'percent'          => $percent,
                    'sessions'         => 1,
                    'watched_bits'     => $bits,
                    'duration_seconds' => $known_duration,
                    'first_viewed_at'  => $now,
                    'last_viewed_at'   => $now,
                ],
                ['%s','%d','%d','%d','%d','%d','%d','%d','%s','%d','%s','%s']
            );
        }

        // $wpdb->update() returns int 0 when the WHERE matched but no column
        // changed — a routine no-op heartbeat. That 0 is SUCCESS, and must be
        // compared with === false, never a loose/falsy check: 0 == false in
        // PHP, so !$result would wrongly flag every no-op as a failed save.
        return $result !== false;
    }
```

Note the `%s` format for `watched_bits` in both arrays — `$wpdb` sends binary strings as `%s`, and the placeholder order must line up column-for-column with the data array above it. Count them: the update has 8 data entries and 8 formats; the insert has 12 and 12.

- [ ] **Step 3: Read `segments` in the endpoint**

In `anchor-private-file-manager.php`, in `handle_progress()`, replace the `record()` call arguments so `segments` replaces `delta`:

```php
        $raw_segments = isset($_POST['segments']) ? (string) wp_unslash($_POST['segments']) : '';
        $segments = $raw_segments !== '' ? json_decode($raw_segments, true) : [];
        if (!is_array($segments)) $segments = [];

        $saved = Anchor_FM_Media_Progress::record(
            self::table('video_views'),
            $source,
            $item_id,
            $user_id,
            $segments,
            isset($_POST['point']) ? (int) $_POST['point'] : 0,
            isset($_POST['duration']) ? (int) $_POST['duration'] : 0,
            !empty($_POST['ended']),
            !empty($_POST['new_session']),
            current_time('mysql'),
            !empty($_POST['reset'])
        );
```

`wp_unslash` matters: WordPress adds slashes to `$_POST`, which would corrupt the JSON. `mark_segments()` validates every pair, so a malformed payload degrades to "no coverage recorded" rather than an error.

Leave `require_nonce()`, the login check, `require_media_access()` and the `$saved === false` → `json_error('Could not save progress', 500)` handling exactly as they are.

- [ ] **Step 4: Run the test suite and lint**

Run: `php -l anchor-private-file-manager.php && php -l includes/class-afm-media-progress.php && php tests/run.php`
Expected: both lints clean, `ALL PASS`.

- [ ] **Step 5: Verify no stale `delta` references remain**

Run: `grep -rn "'delta'\|\$delta\|delta:" --include="*.php" --include="*.js" includes/ assets/ anchor-private-file-manager.php`
Expected: matches only in `assets/js/file-manager.js`, which Task 5 rewrites. Any match in PHP is a leftover and must be removed now.

- [ ] **Step 6: Commit**

```bash
git add anchor-private-file-manager.php includes/class-afm-media-progress.php
git commit -m "feat: derive watch percent from played segments, not furthest point"
```

---

### Task 5: Browser reports what it played

This is the change that actually fixes the reported bug. The client stops saying "I am at 90 seconds and 10 seconds elapsed" and starts saying "I played seconds 0-5 and 128-133."

**Files:**
- Modify: `assets/js/file-manager.js` — `startMediaTracking()`, `flushProgress()`, and the Start Over handler inside `applyResume()`

**Interfaces:**
- Consumes: the `anchor_fm_media_progress` endpoint's new `segments` field (Task 4).
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Track segments instead of elapsed time**

In `startMediaTracking()`, extend the initial `trackState` object with three fields — keep every existing field:

```js
            segments: [],
            segStart: null,
            segEnd: null,
```

Then replace the `onTimeUpdate` handler body with:

```js
        adapter.onTimeUpdate(function (t) {
            if (!trackState) return;

            if (trackState.segStart === null) {
                // First tick of a new stretch of playback.
                trackState.segStart = t;
                trackState.segEnd = t;
            } else {
                const gap = t - trackState.segEnd;
                if (gap >= 0 && gap <= 2) {
                    // Playback advanced normally (timeupdate fires ~4x/sec, so
                    // even at 4x speed a tick advances about a second).
                    if (gap > 0) trackState.accum += gap;
                    trackState.segEnd = t;
                } else {
                    // The playhead jumped — a seek. Close the stretch we were
                    // in and start a new one where we landed. This is the line
                    // that stops scrubbing from counting as watching.
                    closeSegment();
                    trackState.segStart = t;
                    trackState.segEnd = t;
                }
            }

            trackState.lastTime = t;
            if (trackState.accum >= 10) flushProgress(false);
        });
```

Add `closeSegment()` next to `flushProgress()`:

```js
    // Push the stretch currently being played into the pending list. Segments
    // are half-open [start, end): playing from t=0 to t=5 means seconds 0-4
    // were seen, which is five seconds, not six.
    function closeSegment() {
        if (!trackState) return;
        if (trackState.segStart === null) return;
        if (trackState.segEnd > trackState.segStart) {
            trackState.segments.push([trackState.segStart, trackState.segEnd]);
        }
        trackState.segStart = null;
        trackState.segEnd = null;
    }
```

- [ ] **Step 2: Send segments instead of delta**

Replace `flushProgress()` with:

```js
    function flushProgress(force, reset) {
        if (!trackState) return;

        // Capture the stretch in progress, so closing mid-play doesn't lose it.
        closeSegment();

        if (!force && trackState.segments.length === 0) return;

        const payload = {
            source: trackState.source,
            item_id: trackState.itemId,
            segments: JSON.stringify(trackState.segments),
            point: trackState.lastTime,
            duration: trackState.duration,
            ended: trackState.ended ? 1 : 0,
            new_session: trackState.newSession ? 1 : 0,
            reset: reset ? 1 : 0,
        };
        trackState.segments = [];
        trackState.accum = 0;
        trackState.newSession = false;

        // Playback may still be running; resume the open stretch where we are
        // so the next tick continues rather than starting a spurious segment.
        trackState.segStart = trackState.lastTime;
        trackState.segEnd = trackState.lastTime;

        api('anchor_fm_media_progress', payload);
    }
```

- [ ] **Step 3: Keep Start Over consistent**

In the Start Over handler inside `applyResume()`, add a segment reset alongside the existing state reset, immediately before the `flushProgress(true)` call:

```js
                    trackState.segments = [];
                    trackState.segStart = null;
                    trackState.segEnd = null;
```

Start Over rewinds the playhead; it must not bank the stretch the user was sitting in as freshly watched. The existing `lastTime`/`accum`/`ended` resets and the `flushProgress(true)` call stay exactly as they are.

- [ ] **Step 4: Verify it parses and the suite is green**

Run: `node --check assets/js/file-manager.js && php tests/run.php`
Expected: parses clean, `ALL PASS`.

- [ ] **Step 5: Write the trace (there is no JS test harness)**

Put this in your report, worked through against your actual code, not restated from this plan:

1. Open a 120-second video, play 0→5, close. What segments are sent, and what does `count_set` produce server-side?
2. Play 0→5, drag to 115, play to 120, close. Show that two segments are sent, that the drag itself contributes nothing, and that coverage is 10 — the client's original complaint.
3. Play 0→5, then replay 0→5. Show coverage stays 5.
4. Drag straight to the end without playing. Show that `segments` is empty and percent stays 0.
5. Play past a `flushProgress` boundary (accum reaches 10) and confirm no second is lost or double counted at the seam.

- [ ] **Step 6: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: report played segments instead of elapsed time"
```

---

### Task 6: Progress ring on listing rows

**Files:**
- Modify: `anchor-private-file-manager.php` — `ajax_list()` (around line 1555)
- Modify: `assets/js/file-manager.js` — row rendering (around lines 384-392), the row cache mapping (around line 345)
- Modify: `assets/css/file-manager.css`

**Interfaces:**
- Consumes: the `percent` column maintained by Task 4.
- Produces: `ajax_list()` returns `watchPercent` (int 0-100, or absent) on video entries and on file entries whose mime starts with `video/`.

- [ ] **Step 1: Fetch coverage for the whole folder in one query**

Add this private method to the main class, next to `get_video_row()`:

```php
    /**
     * The current user's watch percentage for a batch of items, keyed
     * "<source>:<id>". One query for the whole folder — never one per row.
     *
     * Only ever reads the requesting user's own rows; no caller-supplied
     * user id is accepted anywhere in this path.
     */
    private function watch_percent_map($video_ids, $file_ids) {
        global $wpdb;
        $user_id = get_current_user_id();
        if ($user_id <= 0) return [];

        $video_ids = array_values(array_filter(array_map('intval', (array) $video_ids)));
        $file_ids  = array_values(array_filter(array_map('intval', (array) $file_ids)));
        if (!$video_ids && !$file_ids) return [];

        $views  = self::table('video_views');
        $where  = [];
        $params = [$user_id];

        if ($video_ids) {
            $where[] = "(source = %s AND video_id IN (" . implode(',', array_fill(0, count($video_ids), '%d')) . "))";
            $params[] = Anchor_FM_Media_Progress::SOURCE_VIMEO;
            foreach ($video_ids as $id) { $params[] = $id; }
        }
        if ($file_ids) {
            $where[] = "(source = %s AND video_id IN (" . implode(',', array_fill(0, count($file_ids), '%d')) . "))";
            $params[] = Anchor_FM_Media_Progress::SOURCE_FILE;
            foreach ($file_ids as $id) { $params[] = $id; }
        }

        $sql = "SELECT source, video_id, percent FROM {$views}
                WHERE user_id = %d AND (" . implode(' OR ', $where) . ")";

        $rows = $wpdb->get_results(call_user_func_array([$wpdb, 'prepare'], array_merge([$sql], $params)));

        $map = [];
        foreach ((array) $rows as $r) {
            $map[$r->source . ':' . (int) $r->video_id] = (int) $r->percent;
        }
        return $map;
    }
```

- [ ] **Step 2: Attach it to the listing response**

In `ajax_list()`, after both the file rows and the video rows have been collected into their output arrays but before `json_success(...)`, add:

The assembled arrays in `ajax_list()` are named `$file_list` and `$video_list`
(alongside `$link_list` and `$subfolders`). `$video_list` is built inside a
conditional block, so guard for it not being set:

```php
        $video_ids = [];
        if (!empty($video_list)) {
            foreach ($video_list as $v) { $video_ids[] = (int) $v['id']; }
        }

        $file_ids = [];
        foreach ($file_list as $f) {
            if (strpos((string) $f['mime'], 'video/') === 0) { $file_ids[] = (int) $f['id']; }
        }

        $watch = $this->watch_percent_map($video_ids, $file_ids);

        if (!empty($video_list)) {
            foreach ($video_list as $i => $v) {
                $key = Anchor_FM_Media_Progress::SOURCE_VIMEO . ':' . (int) $v['id'];
                if (isset($watch[$key])) { $video_list[$i]['watchPercent'] = $watch[$key]; }
            }
        }
        foreach ($file_list as $i => $f) {
            if (strpos((string) $f['mime'], 'video/') !== 0) continue;
            $key = Anchor_FM_Media_Progress::SOURCE_FILE . ':' . (int) $f['id'];
            if (isset($watch[$key])) { $file_list[$i]['watchPercent'] = $watch[$key]; }
        }
```

Insert this after both lists are fully built and before the `json_success(...)`
call, so the added keys are included in the response. Do not rename either
array.

- [ ] **Step 3: Carry the value into the row objects**

In `assets/js/file-manager.js`, where rows are built from the list response (around line 345), add `watchPercent` to both the video and file mappings:

```js
        (list.files || []).forEach(f => rows.push({ kind: 'file', id: f.id, name: f.name, mime: f.mime, size: f.size, createdAt: f.createdAt, watchPercent: f.watchPercent }));
```

and the equivalent on the `list.videos` line, preserving every field already listed there.

- [ ] **Step 4: Render the ring**

Add this helper next to `iconForMime()`:

```js
    // Small progress ring. r=8 so the circumference is 2*pi*8 = 50.27; the
    // dash offset is the unfilled remainder.
    function watchRing(pct) {
        const p = Math.max(0, Math.min(100, Number(pct)));
        if (!isFinite(p)) return '';
        const c = 50.27;
        const offset = (c * (100 - p) / 100).toFixed(2);
        const label = p + '% watched';
        return `<span class="afm__watchRing" title="${esc(label)}" aria-label="${esc(label)}">
            <svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true" focusable="false">
                <circle class="afm__watchRingTrack" cx="10" cy="10" r="8" fill="none" stroke-width="3"></circle>
                <circle class="afm__watchRingFill" cx="10" cy="10" r="8" fill="none" stroke-width="3"
                        stroke-dasharray="${c}" stroke-dashoffset="${offset}"
                        transform="rotate(-90 10 10)"></circle>
            </svg><span class="afm__watchPct">${esc(p)}%</span></span>`;
    }
```

In the row template (around line 384), inside the `afm__rowName` cell and after the name text, add:

```js
${(typeof item.watchPercent === 'number') ? watchRing(item.watchPercent) : ''}
```

Putting it in the name cell keeps the existing grid columns and the sortable header untouched.

- [ ] **Step 5: Style it**

Append to `assets/css/file-manager.css`:

```css
/* Per-user watch progress on a listing row. */
.afm__watchRing {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-left: 8px;
    vertical-align: middle;
    flex: 0 0 auto;
}
.afm__watchRingTrack { stroke: #d8dee4; }
.afm__watchRingFill  { stroke: #0f766e; stroke-linecap: round; }
.afm__watchPct {
    font-size: 11px;
    line-height: 1;
    color: #64748b;
    font-variant-numeric: tabular-nums;
}
```

- [ ] **Step 6: Verify**

Run: `php -l anchor-private-file-manager.php && node --check assets/js/file-manager.js && php tests/run.php`
Expected: lints clean, `ALL PASS`.

Record for a human, since there is no browser here:

- A folder of videos shows a ring only on rows you have watched, with the number matching the admin Watch History for the same video.
- A second user sees their own percentages, not yours.
- Non-video files and folders show no ring at all.
- Watch a video, reopen the folder, and confirm the ring moved.
- Confirm the folder listing still issues one coverage query, not one per row — check `SAVEQUERIES` output or the query count in a profiler.

- [ ] **Step 7: Commit**

```bash
git add anchor-private-file-manager.php assets/js/file-manager.js assets/css/file-manager.css
git commit -m "feat: per-user watch progress ring on listing rows"
```

---

## Done criteria

- `php tests/run.php` prints `ALL PASS`.
- Dragging the scrubber to the end of an unwatched video leaves the percentage at 0.
- Watching 5 seconds at the start and 5 at the end of a 120-second video reports 10 seconds covered and 8%.
- Re-watching those same seconds does not change the number.
- Existing records read 0% after upgrade; `resume_seconds`, `furthest_seconds`, `sessions` and the timestamps survive.
- Resume still works: reopening a part-watched video returns to where it stopped.
- Listing rows show the viewer's own progress ring, and only for videos.

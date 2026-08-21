# True Watch Coverage — Design

**Date:** 2026-08-21
**Status:** Approved design, ready for implementation planning

## Problem

The watch percentage measures how far the scrubber has been dragged, not how
much of the video was actually played. Dragging to the end reports 100% without
watching a frame. On a clinical training portal where the report is used to see
who has completed material, that number is not just imprecise — it is wrong in
the direction that matters.

The required behaviour, in the client's words: watch 5 seconds at the intro and
5 seconds at the end and you have watched 10 seconds, not 100%. Re-watching the
same 5 seconds adds nothing.

## Current state (verified against the code)

- `Anchor_FM_Watch_Math::apply_progress()` computes
  `percent = floor(furthest_seconds / duration * 100)`
  (`includes/class-afm-watch-math.php`). `furthest_seconds` is the maximum
  playhead position ever reported, so any seek raises it permanently.
- The browser sends `point` (current playhead) and `delta` (seconds elapsed
  since the last beat) from `flushProgress()`
  (`assets/js/file-manager.js`). Neither describes *which* part of the video was
  played.
- `total_seconds` accumulates `delta`, so it counts wall-clock time in the
  player rather than distinct video watched.
- `ajax_list()` (`anchor-private-file-manager.php:1555`) returns folders, files,
  links and videos in one query per type per folder. It returns no watch data.
- Row markup is built in one template literal around
  `assets/js/file-manager.js:384-392` (`afm__rowName`, `afm__rowKind`,
  `afm__rowSize`, `afm__rowModified`, `afm__rowActions`).
- `resume_seconds` is separate and already correct; this work must not disturb
  it.

## Design

### 1. Coverage as a bitset

A new `watched_bits` BLOB column on `wp_anchor_fm_video_views`, one bit per
second of video: bit *N* set means second *N* was played.

- Marking a range twice sets the same bits, so re-watching contributes nothing.
  The client's rule is a property of the structure, not logic layered on top.
- Two disjoint 5-second stretches produce exactly 10 set bits.
- Size is bounded by duration regardless of scrubbing behaviour: a 15-minute
  video is 900 bits (113 bytes); the 24-hour cap is 10.8 KB.

**Why not a list of intervals.** Intervals are more readable in the database,
but their size grows with how erratically someone scrubs, and a determined
scrubber can fragment a row without bound. A bitset cannot exceed
`ceil(duration / 8)` bytes no matter what the user does.

**Why seconds, not milliseconds.** The client suggested millisecond
granularity. Seconds are 1000× smaller and answer the same question — whether a
second of content was seen. Millisecond precision would add nothing a viewer
could perceive.

**Cap:** ranges are clamped to `Anchor_FM_Watch_Math::RESUME_MAX_SECONDS`
(86400), reusing the bound already applied to resume positions.

### 2. Derived values are stored, not recomputed on read

On every write the bitset is updated, then:

- `total_seconds` = number of set bits (distinct seconds covered)
- `percent` = `floor(total_seconds / duration_seconds * 100)`, clamped 0–100

`duration_seconds` becomes a stored column so the server can compute percent
without a client present.

**Reads never touch the bitset.** The listing badge and the admin report read
the same integer columns they read today. Only the heartbeat write path decodes
and re-encodes bits.

### 3. What the browser sends

`flushProgress()` currently sends `point` and `delta`. It will additionally send
`segments`: an array of `[start, end]` pairs, in whole seconds, covering what
was actually played since the last beat — for example `[[0,5],[128,133]]`.

Segment construction, on each `timeupdate`:

- if the new whole-second time advanced contiguously from the last (the existing
  `delta > 0 && delta <= 2` window), extend the open segment
- otherwise the playhead jumped — a seek — so close the open segment and open a
  new one at the new position
- `flushProgress` sends the accumulated segments and clears them

The existing 2-second contiguity guard is reused as the seek detector rather
than replaced. It holds across playback rates: `timeupdate` fires roughly four
times a second, so even at 4× speed each tick advances about one second, while a
real seek jumps far further.

`point`, `duration`, `ended`, `new_session` and `reset` keep their current
meanings. `delta` is no longer used to compute anything and is dropped.

**Fidelity limit, accepted:** `timeupdate` is sampled, so the final fraction of
a second before a pause or seek may not be marked. Coverage is therefore a
slight undercount, never an overcount. Undercounting is the right direction for
a completion report.

### 4. Percent semantics in the admin report

`percent` becomes true coverage. The adjacent time column becomes covered
seconds rather than time elapsed in the player, so leaving a paused tab open no
longer accumulates watch time.

`furthest_seconds` stays in the table as a factual record of how far a viewer
reached, but no longer drives any displayed value.

### 5. The row badge

`ajax_list()` gains **one** additional query per folder — not per row — fetching
the current user's `percent` for every video in that folder:

```sql
SELECT source, video_id, percent FROM {views}
WHERE user_id = %d AND (
      (source = 'vimeo' AND video_id IN (…))
   OR (source = 'file'  AND video_id IN (…)))
```

Rows render a small inline-SVG progress ring with the percentage inside, and a
`title` of "79% watched". Only rows that are videos — Vimeo entries, and files
whose mime starts with `video/` — get a badge; everything else renders nothing.

Each user sees only their own progress. The endpoint filters on
`get_current_user_id()` and accepts no user id from the caller.

### 6. Reset on upgrade

Coverage cannot be reconstructed from `furthest_seconds` — the old model never
recorded which parts were watched. Per the client's decision, existing records
start from zero rather than inheriting a guess:

- `percent` and `total_seconds` are set to 0 for all rows
- `watched_bits` starts NULL
- `furthest_seconds`, `sessions`, `first_viewed_at`, `last_viewed_at` and
  `resume_seconds` are left untouched

The visible consequence is intended and should not surprise anyone: the watch
report reads 0% for everybody until people watch again. The alternative —
seeding coverage as `0..furthest_seconds` — would have preserved the appearance
of the report by inventing data, and the client chose accuracy over continuity.

Schema change, so this ships as **2.12.0**.

## Code structure

- **`includes/class-afm-coverage.php`** (new) — pure bitset operations, no
  WordPress, no I/O.
- **`includes/class-afm-media-progress.php`** — write path calls the coverage
  helpers; read path unchanged.
- **`includes/class-afm-watch-math.php`** — `apply_progress()` stops deriving
  `percent` from `furthest_seconds`; that responsibility moves to coverage.
- **`anchor-private-file-manager.php`** — schema, reset, the listing query,
  version bump.
- **`assets/js/file-manager.js`** — segment construction, payload, badge markup.
- **`assets/css/file-manager.css`** — ring styles.

## Testing

`tests/run.php` is plain PHP with no WordPress bootstrap, so the bitset logic is
placed in `Anchor_FM_Coverage` specifically to be fully testable:

- `mark($bits, $from, $to, $cap)` — returns the updated bitset
- `count_set($bits)` — distinct seconds covered
- `percent($covered, $duration)`

Cases, including the client's own scenario stated literally:

- mark 0–5, then mark the last 5 seconds → 10 covered
- mark 0–5 a second time → still 10 (idempotence)
- overlapping ranges merge without double counting
- adjacent ranges (`0–5` then `5–10`) do not double count second 5
- reversed input (`from > to`) is rejected without corrupting the bitset
- negative and out-of-range values are clamped
- a range beyond the 86400 cap is truncated
- NULL / empty bitset behaves as zero coverage
- percent with `duration = 0` (unknown) returns 0 rather than dividing by zero
- percent clamps to 100 when coverage meets or exceeds duration
- byte-boundary correctness: a range spanning bits 7→8 sets both

The client-side segment builder has no test harness in this repo. It gets a
written trace and a manual verification step instead.

## Risks

| Risk | Mitigation |
|---|---|
| Bitset grows unexpectedly | Bounded by `ceil(duration/8)`; 24-hour cap enforced on write |
| Report reads 0% after upgrade and looks broken | Intended and chosen; call it out in the release note |
| `timeupdate` sampling loses sub-second tails | Accepted; undercounts, never overcounts |
| Badge query becomes N+1 | One query per folder, ids batched |
| Percent divides by an unknown duration | Guarded; returns 0 until a duration is known |
| Resume regressions | `resume_seconds` is not touched by this work; covered by existing tests |

## Out of scope

- Backfilling coverage for past viewing — impossible from the stored data.
- Showing other users' progress on listing rows; the badge is per-viewer.
- Heatmaps or per-segment reporting in the admin panel.
- Audio files, which still have no player.

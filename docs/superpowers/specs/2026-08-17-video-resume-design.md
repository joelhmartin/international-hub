# Video Resume (Cross-Device Playback Memory) — Design

**Date:** 2026-08-17
**Status:** Approved design, ready for implementation planning

## Problem

A user watching a video in the private file manager has no way to stop and pick
up later. Closing the viewer loses their place. The requirement is that every
playable video remembers where each logged-in user stopped, that the position
follows the user across devices, and that a position older than 30 days is
forgotten.

## Current state (verified against the code)

- **Vimeo videos** are the only playable video today. `mountVimeoPlayer()`
  (`assets/js/file-manager.js:833`) mounts a Vimeo player and
  `startVideoTracking()` heartbeats watched time to `anchor_fm_vimeo_progress`
  (`anchor-private-file-manager.php:2337`).
- **`wp_anchor_fm_video_views`** already stores per-user `furthest_seconds`,
  `total_seconds`, `percent`, `sessions`, `first_viewed_at`, `last_viewed_at`,
  keyed `UNIQUE (video_id, user_id)`. The data is already server-side and
  therefore already cross-device. **Nothing seeks the player on load**, so the
  raw material for resume exists but the feature does not.
- **Uploaded video files** (`mp4`, `mov`, `m4v`, `webm` are on the upload
  allow-list at `anchor-private-file-manager.php:1869`) have **no player at
  all**. `openFileViewer()` renders "No preview available" plus a Download
  button.
- **`ajax_stream()`** (`anchor-private-file-manager.php:2819`) serves whole
  files: `Content-Length` + `readfile()`, no `Accept-Ranges`, no `206`.
- `maybe_upgrade_db()` (line 447) is the version-gated schema upgrade vehicle.
  `register_activation_hook` exists (line 3540); there is **no** deactivation
  hook.
- `tests/run.php` is a plain-PHP runner for pure helpers, with WordPress
  functions stubbed. No WP bootstrap, so only pure logic is testable.

## Scope

**In scope**

1. Resume for Vimeo videos.
2. A native player for uploaded video files, with the same tracking and resume.
3. HTTP Range support in the file stream endpoint (prerequisite for #2).
4. 30-day expiry of saved positions.

**Explicitly out of scope**

- Audio files (`mp3`, `wav`, `m4a`), which are on the allow-list and would work
  with identical machinery. Natural follow-on, not requested.
- Any resume indicator on folder listing rows (e.g. a "12:04 left" badge).
- Aggregate Vimeo-side analytics via the Vimeo API token.

## Key constraint: Range requests

`ajax_stream()` cannot currently back an HTML `<video>` element:

- A `<video>` seeks by issuing `Range: bytes=…` and expecting `206 Partial
  Content`. Against a `200` full-body response, seeking is unreliable or
  broken.
- **Safari refuses to play video at all** without `206` responses.

Therefore resume on uploaded files is impossible without Range support. This is
a hard prerequisite, and the largest single piece of the work.

Two consequences follow from adding it:

- `ajax_stream()` calls `log_activity()` on every request. Once a player issues
  range requests, one playthrough would write dozens of `preview_file` activity
  rows. Logging must happen only on the opening request (no `Range` header, or
  a range starting at byte 0).
- `readfile()` of a whole file must become a bounded `fread`/`echo` loop over
  the requested byte window, so a large video does not load into memory.

## Design

### 1. Data model

Extend the existing `video_views` table rather than adding a parallel one for
files. A parallel table would duplicate the progress endpoint, the admin
history renderer, the expiry job, and the watch math.

Changes to `wp_anchor_fm_video_views`:

| Column | Type | Purpose |
|---|---|---|
| `source` | `VARCHAR(10) NOT NULL DEFAULT 'vimeo'` | `'vimeo'` or `'file'` |
| `resume_seconds` | `INT(10) UNSIGNED NOT NULL DEFAULT 0` | last playhead position |

And the unique key changes:

```
UNIQUE KEY video_user (video_id, user_id)
  -> UNIQUE KEY source_video_user (source, video_id, user_id)
```

Notes:

- The `DEFAULT 'vimeo'` means **existing rows are already correct**. No data
  migration, no backfill.
- The unique-key change prevents a collision between `videos.id = 5` and
  `files.id = 5`, which are unrelated entities.
- `dbDelta` reliably adds columns but does not reliably replace an existing
  unique key. The key swap is one explicit guarded `ALTER TABLE`, run from
  `maybe_upgrade_db()` behind the version check, and written to be safe to run
  twice (check `information_schema.STATISTICS` for the old key before
  dropping).
- The table keeps its `video_views` name. An uploaded mp4 is a video; renaming
  would be churn with no benefit.

**`resume_seconds` stays separate from `furthest_seconds`.** They answer
different questions: resume is *where you stopped*, `furthest_seconds` is *how
far you ever got*. Conflating them would make "rewind, then quit" register as
completion in the admin watch report.

**Cross-device requires no new work.** The row is keyed
`(source, video_id, user_id)` in MySQL, not localStorage. Any device where the
user is logged in reads the same row. This is a property of the existing
design, stated here so it is not mistaken for a gap.

### 2. Resume rules (pure logic)

Saved on the heartbeat that already fires (~every 10 seconds of watched time),
plus on `pause`, on `ended`, and on viewer close.

Three rules, all implemented in `Anchor_FM_Watch_Math` so they are unit
testable:

- **Finished → forget.** On `ended`, or when the playhead is within 15 seconds
  of the end, `resume_seconds` is set to 0. Reopening a video 3 seconds from
  the credits is worse than starting over.
- **Barely started → ignore.** A position under 10 seconds is not worth
  remembering; store 0.
- **Otherwise** store the current playhead.

### 3. Resume UX

On open, the player seeks to the saved position **and** shows a small
dismissible bar: `Resuming from 4:12 · Start over`. "Start over" seeks to 0 and
clears the stored position.

A silent jump into the middle of a video reads as a bug to the person watching.
The bar is what makes the behavior legible.

### 4. 30-day expiry — two layers

**Layer 1 (the guarantee): read-time staleness check.** When the resume point
is read, if `last_viewed_at` is more than 30 days old, return 0 regardless of
what the column holds.

**Layer 2 (housekeeping): a daily WP-Cron job.**

```sql
UPDATE {views} SET resume_seconds = 0
WHERE resume_seconds > 0 AND last_viewed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
LIMIT 5000
```

Up to 10 batches per run (50,000 rows), stopping early once a batch affects
fewer rows than the limit. Batching avoids holding a long write lock on a large
table.

The ordering matters and is deliberate: **WP-Cron is not a reliable timer.** It
fires on page loads, and this is a private client portal that can sit untouched
for days. If the cron were the only mechanism, a user returning after two
months of site quiet would be resumed into a position that should have expired.
The read-time check makes the 30-day rule actually true; the cron merely keeps
the column tidy so stale values do not accumulate.

The cron event is scheduled on activation and **unscheduled on deactivation** —
this requires adding a `register_deactivation_hook`, which the plugin does not
currently have.

Only the resume point is cleared. `furthest_seconds`, `total_seconds`,
`percent`, `sessions` and the timestamps are preserved, so the admin Watch
History report remains accurate indefinitely.

### 5. Uploaded-file player

**Stream endpoint.** `ajax_stream()` gains Range support:

- Always emit `Accept-Ranges: bytes`.
- No `Range` header → `200`, whole file, as today.
- Valid single range → `206`, `Content-Range: bytes start-end/total`,
  `Content-Length: end-start+1`, bounded read loop.
- Unsatisfiable range (start beyond EOF) → `416` with
  `Content-Range: bytes */total`.
- Multi-range (`bytes=0-99,200-299`) → not supported; fall back to `200` full
  body. Legal, and no browser video player needs it.
- Suffix range (`bytes=-500`, meaning the last 500 bytes) → supported;
  players use it to read the moov atom / container index.
- `log_activity()` only on the opening request.

**Preview type.** `ajax_preview()` returns `type => 'video'` when the mime
starts with `video/` and the extension is one the browser can play.

**Player.** The viewer renders
`<video controls preload="metadata" playsinline>` pointed at the existing
nonce-signed inline stream URL. Native controls; no custom chrome.

**`.mov` fallback.** `.mov` is on the upload allow-list, and while H.264 `.mov`
plays in Safari and Chrome, Firefox generally will not play it. The player is
rendered for `.mov` anyway, with a `<video>` `error` handler that swaps the
player for the existing "No preview available" block plus the Download button.
Degrades to exactly today's behavior rather than showing a dead black box.

**Tracking.** Mirrors the Vimeo path: `timeupdate` accumulates watched
seconds, flushing every ~10s and on `pause` / `ended` / close, against the same
generalized endpoint with `source: 'file'`.

### 6. Endpoints

The progress endpoint is generalized to take `source` + `item_id`:

- `anchor_fm_media_progress` — write a heartbeat (both sources).
- `anchor_fm_media_resume` — read the resume point, with the staleness check
  applied. **Both sources use this dedicated endpoint** rather than riding
  along on the item-fetch responses. `anchor_fm_preview` is always called
  before a file player mounts and could carry the value, but
  `openVideoViewer()` (`assets/js/file-manager.js:802`) serves Vimeo videos
  straight from the row cache and frequently makes *no* server call before
  mounting — so Vimeo needs a dedicated read regardless. One endpoint for both
  keeps a single code path; the extra round trip for files overlaps with the
  player's own metadata load and costs nothing perceptible.
- `anchor_fm_vimeo_progress` — **kept registered as an alias for one release**,
  delegating to the new handler with `source='vimeo'`. `file-manager.js` is
  cache-busted by `filemtime`, but a browser holding a cached bundle across a
  plugin update would otherwise get a hard failure on every heartbeat. Cheap
  insurance.
- `anchor_fm_vimeo_history` — extended to accept `source`, so the admin Watch
  History panel works for uploaded videos too.

Permission checks are unchanged in character: `can_user_view_video()` for
`source='vimeo'`, `can_user_view_file()` for `source='file'`. Every endpoint
keeps the existing nonce + logged-in + per-item capability checks. A user can
only ever read or write their own `user_id` row — the resume point is never
addressable by another user's id.

### 7. Code structure

The main plugin file is 3541 lines. New work lands in focused includes rather
than growing it further:

- **`includes/class-afm-range.php`** (new) — pure byte-range header parsing.
- **`includes/class-afm-media-progress.php`** (new) — progress/resume endpoint
  handlers and the shared read/write logic for both sources.
- **`includes/class-afm-watch-math.php`** (existing) — extended with the resume
  rules and the staleness check.

This is targeted improvement in the code being touched, not general
refactoring. Unrelated parts of the monolith are left alone.

## Testing

The placement above is chosen so that every piece of fiddly logic is a pure
function the existing `tests/run.php` can cover with no WordPress bootstrap:

**`Anchor_FM_Range::parse($header, $filesize)`**
- `bytes=0-` → whole file
- `bytes=500-999` → exact window
- `bytes=-500` → suffix range, last 500 bytes
- `bytes=1000-99999` on a 5000-byte file → clamped to EOF
- `bytes=99999-` on a 5000-byte file → unsatisfiable (`416`)
- `bytes=0-99,200-299` → multi-range rejected, signals full-body fallback
- malformed / garbage / empty → null, signals full-body fallback
- zero-byte file edge case

**`Anchor_FM_Watch_Math` resume rules**
- position under 10s → 0
- position within 15s of the end → 0
- `ended` → 0
- normal mid-video position → stored verbatim
- position past duration → clamped
- unknown duration (0) → near-end rule cannot apply, position stored
- existing `apply_progress` cases continue to pass unchanged

**Staleness check**
- `last_viewed_at` 29 days ago → position returned
- 31 days ago → 0
- exactly 30 days → documented boundary, tested
- empty/invalid timestamp → 0 (fail closed)

WordPress-glue behavior (dbDelta migration, the `ALTER`, cron scheduling, the
actual `206` responses, player mounting) is verified manually against a real
site, since the harness cannot bootstrap WordPress.

## Risks

| Risk | Mitigation |
|---|---|
| Unique-key `ALTER` fails or double-runs on some hosts | Guard on `information_schema`; make it idempotent; version-gated |
| WP-Cron never fires on a quiet portal | Read-time staleness check is the actual guarantee |
| Stale cached JS after update | `anchor_fm_vimeo_progress` alias kept for one release |
| Large video + range loop exhausts memory | Bounded chunked read, explicit output-buffer handling |
| `.mov` unplayable in Firefox | `error` handler falls back to the current download-only view |
| Range logging floods the activity table | Log only the opening request |

## Files touched

- `anchor-private-file-manager.php` — schema columns + key `ALTER`,
  `ajax_stream()` range support, `ajax_preview()` video type, endpoint
  registration, cron schedule/prune, deactivation hook, `VERSION` bump
- `includes/class-afm-range.php` (new)
- `includes/class-afm-media-progress.php` (new)
- `includes/class-afm-watch-math.php` — resume rules, staleness
- `assets/js/file-manager.js` — native player, tracking, resume seek, resume bar
- `assets/css/file-manager.css` — player and resume-bar styles
- `tests/run.php` — new cases above

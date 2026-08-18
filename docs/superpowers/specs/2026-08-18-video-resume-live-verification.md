# Video Resume — Live-Site Verification Checklist

**Branch:** `feat/video-resume` (15 commits, `c196057..b4a34c5`)
**Spec:** `2026-08-17-video-resume-design.md` · **Plan:** `../plans/2026-08-17-video-resume.md`

Nothing below could be executed while building this: the development
environment has no WordPress install and no browser. Every item was verified by
static reasoning and by the pure-helper unit suite (152 checks, `ALL PASS`);
these are the assertions that genuinely require a running site. Ordered by risk.

## 1. The migration, against a restored copy of a real client database

The one step that cannot be undone in place. Run it on a restore, not on prod.

```bash
wp db query "DESCRIBE wp_anchor_fm_video_views;"
wp db query "SHOW INDEX FROM wp_anchor_fm_video_views;"
wp db query "SELECT source, COUNT(*) FROM wp_anchor_fm_video_views GROUP BY source;"
wp option get anchor_fm_db_version
```

Expect: `source` and `resume_seconds` columns present; `source_video_user`
UNIQUE present; **`video_user` absent**; every pre-existing row carrying
`source = 'vimeo'`; and `anchor_fm_db_version` reading `2.11.0` **only if all of
the above is true**.

That last condition is deliberate — the version now bumps only when the new
index exists, so a failed `dbDelta` retries on the next request instead of
wedging permanently. If the version is stuck below `2.11.0`, the migration is
still retrying and the index did not get created; investigate before shipping.

Re-run the upgrade path twice and confirm the second run is a clean no-op.

## 2. Concurrent streaming against the host's real PHP worker count

**The most likely way this branch takes a client site down.** Every byte of
every uploaded video is served by a PHP worker, and responses are `no-store`, so
each seek re-fetches through PHP rather than the browser cache.

Play three or four uploaded videos at once, scrub each timeline hard, and watch
PHP-FPM active processes plus whether the *rest of the site* stays responsive.
Managed WordPress hosting typically runs 4–8 workers.

See "Open decision" below — there is a one-line change that largely fixes this,
but it has a privacy tradeoff that is yours to make.

## 3. Safari (macOS and iOS): play and seek an uploaded mp4

Safari refuses to play video at all without `206 Partial Content` responses.
That premise is the whole reason HTTP Range support was added; this is where it
gets tested for real.

## 4. Non-video files still work — PDF, image, text, large ZIP

`ajax_stream()` and `ajax_preview()` serve every file type, not just video. Two
things changed underneath them: responses can now be `206` where they were
always `200`, and the streaming helper clears all active PHP output buffers.

Check a PDF in Chrome's viewer especially — it is the most-used preview type in
this portal and it will now receive ranged responses. Truncated files or a
`Content-Length` mismatch would point at a gzip module or caching plugin
buffering `admin-ajax.php`.

## 5. Firefox + `.mov`

Expect either playback, or the fallback block with a **working** Download
button — never a dead black box. After the final fixes, a mid-playback network
drop should show a *network* message, not the "can't be played in your browser"
codec message.

## 6. Vimeo resume actually seeks

`applyResume` calls `setCurrentTime` without awaiting `player.ready()`. The SDK
is expected to queue it; confirm against the bundled player version rather than
assuming.

## 7. Cron scheduled itself after an auto-update

```bash
wp cron event list | grep anchor_fm_prune_resume
```

Auto-update never fires the activation hook, so scheduling also happens from the
normal request path. This confirms that worked.

Then seed a stale row and a fresh one and run it:

```bash
wp db query "UPDATE wp_anchor_fm_video_views SET resume_seconds=120, last_viewed_at=DATE_SUB(NOW(), INTERVAL 45 DAY) WHERE id=<stale_id>;"
wp db query "UPDATE wp_anchor_fm_video_views SET resume_seconds=120, last_viewed_at=NOW() WHERE id=<fresh_id>;"
wp cron event run anchor_fm_prune_resume
wp db query "SELECT id, resume_seconds, furthest_seconds, total_seconds, percent, sessions FROM wp_anchor_fm_video_views WHERE id IN (<stale_id>,<fresh_id>);"
```

**The important assertion is the second half of that last query:**
`furthest_seconds`, `total_seconds`, `percent` and `sessions` must be unchanged
on *both* rows. Only the resume point may be cleared, or the admin Watch History
report starts losing history.

## 8. The feature itself, end to end

- Watch an uploaded video past 30s, close, reopen → resumes, with the bar.
- **Cross-device:** same video, different browser or device, same login → resumes.
- Watch to the end, reopen → starts at 0, no bar.
- Watch 5 seconds, reopen → starts at 0 (below the 10-second minimum).
- "Start over" → jumps to 0; close and reopen → still 0.
- **Re-watch a finished video** and close mid-way → the new position saves.
- Open a video and close it immediately → a previously saved position survives.
- Admin Watch History lists viewers for both an uploaded file and a Vimeo video,
  and a non-admin sees no history panel at all.

## 9. Deletion does not cross sources

The defect this most recently fixed. Given a Vimeo video and an uploaded file
that happen to share a numeric id, delete the Vimeo one and confirm the file's
watch history survives — then the reverse. Also delete a folder containing both.

## 10. A session longer than the stream nonce window

Leave a tab open overnight and seek. The nonce is per-file and time-limited; the
failure would be a mid-stream 403.

---

## Open decision for you

**Relax the cache header on inline video?** Right now `ajax_stream()` sends
`no-store`, so the browser caches nothing and every seek costs a PHP worker
(item 2). Changing inline responses to `private, max-age=0, must-revalidate`
would let the browser reuse byte ranges and largely removes the worker-exhaustion
risk — but it also puts private client documents into the on-disk browser cache,
where they persist after logout and survive on shared machines.

That is a real privacy/performance tradeoff for a product called Private File
Manager, so it was left alone rather than decided unilaterally. A middle option
exists: apply the cacheable header only to `video/*` inline responses, limiting
disk-cache exposure to video while leaving documents `no-store`.

## Known follow-up (parked, one line)

`ensure_videos_table()` reports whether `source_video_user` **exists**, but not
whether the legacy `video_user` key was actually **dropped**. If the `DROP INDEX`
fails on some host — privileges, lock timeout — the version bumps anyway, the
legacy `UNIQUE (video_id, user_id)` survives, and progress saves then fail
permanently for any id that collides across sources, with no retry and no
symptom other than resume not working.

Rare trigger, and `2.11.0` has not shipped, so nothing is stamped yet. The
hardening is one line, and cannot wedge a healthy site:

```php
return $has_new && !self::views_index_exists('video_user');
```

Item 1 above detects the condition if it occurs.

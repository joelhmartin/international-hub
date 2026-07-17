# Vimeo bulk import, title autofill, and URL parse fix

Date: 2026-07-17
Status: Approved

## Problem

Adding a video returns HTTP 400. Root cause confirmed from the response body
(`{"success":false,"data":{"message":"Could not read a Vimeo ID from that
input"}}`): `Anchor_FM_Vimeo::parse_id()` recognizes only `vimeo.com/` followed
by nothing, `video/`, `channels/<x>/`, or `groups/<x>/videos/`. Dashboard URLs
(`vimeo.com/manage/videos/<id>`), `ondemand/`, `album/<x>/video/<id>`, and
pasted iframe embed codes all fall through to `return ''`, and
`ajax_vimeo_add()` 400s at anchor-private-file-manager.php:2113.

Two contributing defects found while tracing:

1. `file-manager.js:1025` uses `.then(res => ...)` on a jQuery deferred.
   `wp_send_json_error(..., 400)` *rejects* that deferred, and a
   single-argument `.then()` only handles fulfillment — so the error notice at
   :1027 is unreachable. The plugin computed a correct diagnosis and discarded
   it, leaving a bare console trace.
2. Unlisted videos live at `vimeo.com/<id>/<hash>` and need the hash to embed.
   `parse_id` discards it and there is no column to store it. Playback works
   today only because the site domain is whitelisted in Vimeo's embed privacy
   settings. oEmbed will not resolve an unlisted video without the hash, so
   title autofill is impossible without storing it.

`Anchor_FM_Vimeo::embed_url()` is dead code — zero callers. Playback goes
through the Vimeo JS SDK at `file-manager.js:779`.

## Design

### 1. Parse

`parse_ref($input): ['id' => string, 'hash' => string]`. Recognizes bare ids,
`vimeo.com/<id>`, `/video/`, `/channels/<x>/`, `/groups/<x>/videos/`,
`/manage/videos/<id>`, `/ondemand/<x>/<id>`, `/album/<x>/video/<id>`, unlisted
`vimeo.com/<id>/<hash>`, `?h=<hash>`, and `<iframe src="...">` embed codes.
`parse_id` stays as a thin wrapper returning `['id']` so the eight existing
tests in `tests/run.php:19-27` keep their meaning. Delete `embed_url`.

### 2. Schema

Add to the `videos` CREATE TABLE (:407):
- `vimeo_hash VARCHAR(64) NOT NULL DEFAULT ''`
- `thumbnail_url VARCHAR(255) NOT NULL DEFAULT ''`

Bump `VERSION` 2.9.18 -> 2.10.0; `maybe_upgrade_db()`/dbDelta handles the ALTER
on existing installs. Pass `h` to the SDK at `file-manager.js:779`.

### 3. Metadata fetch

`Anchor_FM_Vimeo::fetch_meta($id, $hash)` — the plugin's first outbound HTTP
call. `wp_remote_get` on `https://vimeo.com/api/oembed.json?url=...` (no API key;
the hash resolves unlisted videos), 5s timeout. Returns
`['title', 'thumbnail_url']` or `WP_Error`.

**A fetch failure is never fatal.** The row still imports, title falls back to
`Vimeo video <id>`, and the review UI flags it.

### 4. Endpoints

- `anchor_fm_vimeo_resolve` (new, read-only, writes nothing): takes the pasted
  blob, splits on commas **and** newlines, parses each, fetches metadata,
  returns per-entry `{id, hash, title, thumbnail_url, error}`.
- `anchor_fm_vimeo_add` (extended): accepts an array of `{vimeo, hash, title}`
  and inserts in one pass, reporting per-row success. Partial failure is
  normal — 18 of 20 import, 2 report why. Retains single-video shape for
  backward compatibility.

### 5. Modal

Textarea + **Fetch** -> review list: one row per video with thumbnail, editable
title pre-filled from Vimeo, unparseable entries flagged inline rather than
silently dropped -> **Add**.

### 6. Grid

Thumbnails render in video rows (`file-manager.js:319`, `:1558`).

**Accepted tradeoff:** stored thumbnail URLs hotlink Vimeo's CDN and expire. A
re-fetch path may be needed later; mirroring locally is the durable fix and is
out of scope.

### 7. Error handling

`file-manager.js:1025` `.then()` -> `.done()/.fail()`, so a 400 shows its
message. Audit sibling handlers for the same single-argument `.then()` pattern.

## Testing

`tests/run.php` is a standalone PHP runner (no WP bootstrap), so it covers pure
functions only: every `parse_ref` form above, including `manage/videos`, iframe
embeds, and unlisted hash extraction. Existing `parse_id` cases must stay green.
`fetch_meta` and the ajax handlers need WP and are verified manually.

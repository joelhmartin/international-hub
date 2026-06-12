# Anchor Private File Manager — Finder Rework + Vimeo Video Support

**Date:** 2026-06-12
**Status:** Approved design (pre-implementation)
**Plugin version at design time:** 2.9.16

## Goal

Reshape the Documents/Files experience from a card grid into a standard,
macOS-Finder-style file manager: row-based listing, expand-in-place, double-click
to isolate a folder, always-global search, right-click context actions, popup
previews, and first-class Vimeo video items with admin-only per-user watch
history.

## Scope

**In scope:** the Documents/Files view only — the row list, search, previews,
context menu, video support, watch history, and the chosen quality-of-life adds.

**Out of scope (left exactly as-is):** the Orders, Downloads, Account, Security,
and admin Product Docs tabs; the WooCommerce/order drawer; the permissions data
model and storage layout (reused unchanged); the update-checker mechanism.

## Decisions locked during brainstorming

| Decision | Choice |
| --- | --- |
| Layout | Keep the left folder-tree sidebar; replace the card grid with Finder rows |
| Scope | Files view only |
| Video add method | Admin pastes a Vimeo URL/ID (no file-to-Vimeo upload) |
| Vimeo account | One account owns all videos; a token will be supplied |
| Watch history | Tracked per-user by us via the Vimeo Player SDK + a new DB table |
| Extras (v1) | Clickable breadcrumbs, multi-select + bulk actions, keyboard navigation |
| QoL (v1) | Inline rename (admin-only), upload progress bar, copy share link, request-access |
| Deferred | Row thumbnails; Vimeo aggregate stats overlay |

---

## 1. Finder-style row list (replaces the card grid)

Replaces `renderGrid()` / `.afm__grid` / `.afm__card` with a row list. The left
sidebar folder tree and breadcrumbs stay.

**Columns:** Name · Kind · Size · Modified · (trailing kebab).

```
Name                         Kind        Size     Modified
▾ 📁 Contracts                Folder      —        May 3
    📄 NDA.pdf                PDF         1.2 MB   Jun 1
    🎬 Onboarding Intro       Video       —        Jun 9
  📁 Media                    Folder      —        May 1
  🔗 Pricing sheet            Link        —        Apr 2
```

**Behaviors:**

- **Expand-in-place:** the ▸ disclosure triangle on a folder row loads that
  folder's children via `anchor_fm_list` and injects indented child rows directly
  beneath, without changing the current root. Indentation scales with depth.
  Expanded state is tracked per folder id so re-renders preserve it.
- **Double-click a folder** = isolate/drill-in: that folder becomes the current
  root (existing `loadFolder` behavior), breadcrumbs update.
- **Single-click** selects a row (highlight). **Double-click a file/video** opens
  the popup viewer (§4 / §5). **Double-click a link** opens it in a new tab.
- **Sortable columns:** clicking the Name / Kind / Size / Modified header sorts
  the current listing; folders always group above files/links/videos. Sort is a
  client-side reorder of the loaded rows (and of injected children within their
  group).
- Items render in four kinds: folder, file, link, video. The list builder must
  handle all four uniformly.

**Markup/CSS:** new classes under the existing `afm__` block, e.g.
`afm__list`, `afm__row`, `afm__row--folder|file|link|video`, `afm__rowCell`,
`afm__rowName`, `afm__rowDisclosure`, `afm__listHead`. The card classes are
retired from the files panel (kept only where other tabs still use them).

## 2. Always-global search (replaces the folder-scoped filter)

Today search is a client-side substring filter over the currently-loaded folder.
Replace with a server search spanning everything the user can see.

**New endpoint `anchor_fm_search`:**
- Params: `term` (string).
- Searches folders, files, links, and videos by name/title across the whole tree,
  filtered to entities the current user may view (reusing
  `can_user_view_folder` / `can_user_view_file` / `can_user_view_link` and the new
  video view check).
- Returns flat results, each with: kind, id, name, `folder_id`, and a precomputed
  breadcrumb path string ("Docs › Contracts").
- Reasonable result cap (e.g. 200) with a "refine your search" note when exceeded.

**Frontend:** the search box always searches from the top (placeholder changes
from "Search in folder…" to "Search all documents…"). While a term is present the
main pane shows a flat results list; each result shows a faint enclosing-path line
and supports the same context menu. Clearing the box returns to the normal browse
view at the current folder. Debounce input (~250 ms).

## 3. Right-click context menu + "Show in enclosing folder"

- Add a `contextmenu` (right-click) handler on rows that opens the existing menu
  component (`openMenu`) with the row's context — in addition to the kebab button.
- Menu gains **"Show in enclosing folder"**: navigates to the item's parent
  folder (drills in via `loadFolder`), expands the sidebar tree to it, and briefly
  flashes/highlights the target row. This is the bridge from a search hit to where
  the item actually lives, and is useful during normal browsing too.
- Menu contents by kind (admin vs viewer gating preserved from today):
  - Folder: Open, Show in enclosing folder, Rename*, Permissions*, Download,
    Move to top*, Delete*.
  - File: Open (preview), Show in enclosing folder, Copy share link, Rename*,
    Permissions*, Delete*.
  - Video: Play, Show in enclosing folder, Copy share link, Rename*,
    Permissions*, Delete*.
  - Link: Open, Show in enclosing folder, Edit*, Delete*.
  - (* = admin / manage capability only.)

## 4. Popup previews (replaces the inline drawer)

Previews move from the right slide-in drawer into a centered **modal viewer**,
reusing and widening the existing `.afm__modal` component (new modifier
`afm__modal--viewer`).

- **PDF** → embedded `<iframe>` in the modal body, with a **Download** button.
- **Image** → shown large, with Download.
- **Text** → excerpt in a `<pre>`, with Download.
- **No preview** types → icon + Download.
- Metadata (type, size, uploaded by, date) shows beneath the preview.
- Existing `anchor_fm_preview` / `anchor_fm_stream` endpoints are reused
  unchanged; only the rendering surface changes (drawer → modal).
- The file drawer is retired. The separate Orders drawer is untouched (out of
  scope).

## 5. Vimeo video items

### Data
New table mirroring the existing `links` table:

```
wp_anchor_fm_videos
  id            BIGINT UNSIGNED PK AUTO_INCREMENT
  folder_id     BIGINT UNSIGNED NOT NULL DEFAULT 0   (KEY)
  vimeo_id      VARCHAR(32)  NOT NULL                  -- numeric Vimeo id
  title         VARCHAR(255) NOT NULL
  created_by    BIGINT UNSIGNED NOT NULL DEFAULT 0
  created_at    DATETIME NOT NULL
  updated_at    DATETIME NOT NULL
```

Created via `dbDelta` in a new `ensure_videos_table()`, invoked from `activate()`
and from `maybe_upgrade_db()` (bump `self::VERSION`).

### Permissions
A user may view a video if they can view its folder (same rule as links):
`can_user_view_video($user_id, $video_id)` → `can_user_view_folder(folder)`.
Manage (add/rename/delete) requires admin / manage on the folder, same as links.

### Add (admin)
"New Video" toolbar action + dialog: paste a **Vimeo URL or numeric ID** plus a
title. A parser extracts the numeric id from any common Vimeo URL form
(`vimeo.com/123`, `player.vimeo.com/video/123`, `vimeo.com/channels/x/123`, bare
id). Endpoint `anchor_fm_vimeo_add` (admin + manage-folder gated) inserts the row.
Rename via `anchor_fm_vimeo_update`; delete via `anchor_fm_vimeo_delete` (also
deletes its watch-history rows).

### List & viewer
- In the row list a video shows with a 🎬 icon, Kind = "Video".
- Clicking/double-clicking opens the **modal viewer** embedding the Vimeo player
  with full standard controls, via the official Vimeo Player SDK (player.js).
- **No download** for videos.
- The `anchor_fm_list` response gains a `videos` array alongside `files`/`links`.

## 6. Watch history (admin-only, per-user)

Vimeo's API cannot identify WordPress users, so per-user history is tracked by us.

### Data
```
wp_anchor_fm_video_views
  id               BIGINT UNSIGNED PK AUTO_INCREMENT
  video_id         BIGINT UNSIGNED NOT NULL          (KEY)
  user_id          BIGINT UNSIGNED NOT NULL          (KEY)
  furthest_seconds INT UNSIGNED NOT NULL DEFAULT 0    -- deepest point reached
  total_seconds    INT UNSIGNED NOT NULL DEFAULT 0    -- cumulative watched
  percent          TINYINT UNSIGNED NOT NULL DEFAULT 0
  sessions         INT UNSIGNED NOT NULL DEFAULT 0
  first_viewed_at  DATETIME NOT NULL
  last_viewed_at   DATETIME NOT NULL
  UNIQUE KEY video_user (video_id, user_id)
```

### Tracking
- The Vimeo Player SDK fires `timeupdate`/`ended`. A throttled JS heartbeat
  (~every 10 s of playback and on pause/ended) posts to `anchor_fm_vimeo_progress`
  with `video_id`, current time, watched-delta, and duration.
- The endpoint upserts the current user's row: `furthest_seconds` = max(old, point
  reached); `total_seconds` += clamped delta (so scrubbing/seek does not inflate
  it); `percent` from furthest/duration; `sessions` increments once per player
  open; timestamps updated. Requires login + nonce + video view permission.

### Display
- Beneath the player in the viewer popup, **admins only** see a watch-history
  table: Display name · furthest % · total watched (m:ss) · last watched date,
  sorted by `last_viewed_at` desc. Endpoint `anchor_fm_vimeo_history`
  (admin-gated) returns rows joined to user display names. Non-admins see only the
  player.

### Vimeo token
- A Vimeo access token is stored via a new settings field (with `.env`
  `VIMEO_ACCESS_TOKEN` fallback, mirroring the existing GitHub-token pattern).
- Per-user history needs **no** Vimeo API call, so it works regardless of the
  token's plan/scope. The token is stored now to optionally layer in Vimeo
  aggregate stats later (deferred).

## 7. Quality-of-life additions (v1)

- **Clickable breadcrumbs:** each crumb navigates to that folder (currently
  display-only).
- **Multi-select + bulk actions:** shift/ctrl-click selects multiple rows; a bulk
  action bar offers Download / Delete / Move (each action still permission-checked
  per item). Selection clears on navigation.
- **Keyboard navigation:** ↑/↓ move the active row, →/← expand/collapse a folder,
  Enter opens/drills, Space previews, Esc closes popups/menus.
- **Inline rename (admin-only):** double-click a name or press F2 to rename
  folders/files/videos in place. Non-admins cannot rename (the affordance is not
  shown). Reuses `anchor_fm_rename_folder` and a file-rename path; videos use
  `anchor_fm_vimeo_update`.
- **Upload progress bar:** real per-file progress + status during uploads, via
  XHR `progress` events; drag-and-drop already supported.
- **Copy share link:** context-menu action copies a direct URL to a file/video.
  Opening it still enforces permissions — a user without the required role is
  blocked. (No new sharing token model; the link points at the item and the
  existing permission checks gate access.)

## 8. Request Access (for blocked users)

When a user lands on a file/video they cannot view (e.g. followed a shared link
without the role), the blocked state shows a **"Request Access"** button.

- Endpoint `anchor_fm_request_access`: emails a **configurable recipient** with
  the requester's name/email and the requested item's name/path.
- **Settings page** gains a "Request access recipient" email field, **defaulting
  to `tiffany@tmjtherapycentre.com`** (the site admin address).
- Rate-limit per user/item to avoid mail spam (e.g. one request per item per hour).

## 9. Naming / namespacing requirement (avoid theme collisions)

The site's theme `functions.php` already contains unrelated Vimeo/video logic.
All new identifiers introduced here must be distinctive enough not to collide:

- **PHP:** all logic stays inside the `Anchor_Private_File_Manager` class (already
  isolated). New AJAX actions use the `anchor_fm_vimeo_*` / `anchor_fm_*` prefix.
  No new global functions; no generic names like `vimeo_*` in global scope.
- **JS:** all code stays inside the existing IIFE closure — no new globals, no
  generic global function names. The localized config object stays under the
  existing `AnchorFM` namespace (add video fields there).
- **CSS:** new classes under the existing `afm__` block; video-specific classes
  namespaced as `afm__video*` / `afm__vplayer*` so they can't match theme rules.
- **Data attributes:** video rows/controls use a dedicated `data-afm-video*`
  namespace.
- **Script handles:** the Vimeo Player SDK is enqueued under a unique handle
  (e.g. `anchor-fm-vimeo-player`) so it can't be dequeued/duplicated by theme code.
- **DB tables:** `wp_anchor_fm_videos` / `wp_anchor_fm_video_views` (already
  prefixed by `{$wpdb->prefix}anchor_fm_`).

---

## New / changed surface (summary)

**New DB tables:** `anchor_fm_videos`, `anchor_fm_video_views`.

**New AJAX endpoints:**
- `anchor_fm_search` — global search.
- `anchor_fm_vimeo_add` / `anchor_fm_vimeo_update` / `anchor_fm_vimeo_delete`.
- `anchor_fm_vimeo_progress` — watch-progress upsert.
- `anchor_fm_vimeo_history` — admin watch-history rows.
- `anchor_fm_request_access` — access-request email.
- (file rename endpoint if one does not already exist.)

**Changed endpoints:** `anchor_fm_list` adds a `videos[]` array.

**New settings:** Vimeo access token; request-access recipient email
(default `tiffany@tmjtherapycentre.com`).

**Frontend rewrite:** `assets/js/file-manager.js` (grid → rows, expand-in-place,
global search, context menu, popup viewer, video player + tracking, multi-select,
keyboard nav, inline rename, upload progress) and `assets/css/file-manager.css`
(row list, viewer modal, video styles). `account-documents.js` touched only where
it coordinates the files tab.

## Testing approach

- PHP: permission checks for each new endpoint (viewer vs admin), Vimeo-id parsing
  for all URL forms, progress upsert math (furthest vs total, scrub doesn't
  inflate), request-access rate limiting and recipient resolution.
- JS: row rendering for all four kinds, expand-in-place state, global-search
  results + "show in enclosing folder", popup viewer per type, video heartbeat
  throttle, multi-select/bulk gating, keyboard nav, admin-only history visibility.
- Manual: paste each Vimeo URL form; confirm watch history populates per WP user;
  confirm a non-permitted user is blocked and Request Access emails the recipient;
  confirm new CSS/JS/handles do not disturb the theme's existing Vimeo logic.

## Deferred (documented future work)

- Row thumbnails (image/video poster frames in the Name column).
- Vimeo aggregate stats overlay (total plays / avg completion) via the stored
  token.
- Sharing-token model (time-limited links) — current "copy share link" relies on
  existing permission gating, not signed tokens.

# Copy / Paste / Duplicate — Design

**Date:** 2026-06-19
**Plugin:** Anchor Private File Manager (`anchor-private-file-manager.php`)
**Branch:** feature/finder-rework-video

## Problem

The file manager can move items (drag, `ajax_move_file`, `ajax_move_folder`) but cannot
copy them. Admins want to duplicate an item in place (with a `(copy)` suffix) and to
copy/paste items into another folder, across all item kinds.

## Goals

- Copy any item — **file, link, video, or folder (recursive)** — into a target folder.
- **Duplicate in place**: a one-step copy into the same folder, name gets a `(copy)` suffix.
- **Copy / paste**: copy item(s) to an in-memory clipboard, then paste into a chosen folder.
- Reach the feature from four surfaces: the row context menu, keyboard shortcuts
  (Cmd/Ctrl+C / +V / +D), the existing multi-select bulk bar, and modifier-drag.
- Resolve name collisions with a predictable `(copy)` / `(copy 2)` scheme (extension-aware
  for files).

## Non-Goals

- No "cut" — moving already exists (drag / move handlers). The clipboard is copy-only.
- No cross-session or server-side clipboard. The clipboard is in-memory JS state for the
  current page session.
- No permission cloning. Copies inherit the destination folder's permissions (decision
  below); no `wp_anchor_fm_permissions` rows are copied.
- No copying across different WordPress sites / installs.

## Decisions (from brainstorming)

1. **Item kinds:** all four — files, videos, links, and folders (recursive).
2. **Permissions:** the copy gets **no explicit permission rows**; it inherits whatever the
   destination folder grants (same as move/upload). Folder recursion therefore copies
   structure + files only, never permission rows.
3. **Triggers:** all four surfaces — row context menu, keyboard shortcuts, bulk toolbar,
   and modifier-drag (hold Ctrl/Alt while dragging to copy instead of move).

## Architecture

One backend operation, four front-end triggers. A single AJAX action
`anchor_fm_copy_items` performs every copy. **Duplicate** = copy with
`target_folder_id` equal to the item's current folder. **Paste** = copy into the folder
the user chose. The pure name-collision logic is isolated in a testable helper class so
it can be exercised by the existing plain-PHP runner (`php tests/run.php`); the recursion,
disk, and DB work stays in the main plugin file (WordPress-dependent, manually verified).

### Data model (existing tables, unchanged)

- **folders**: `id, parent_id, name, owner_user_id, is_private, created_by, created_at, updated_at`
- **files**: `id, folder_id, original_name, stored_name, mime_type, size, sha1, uploader_user_id, created_at`
- **links**: `id, folder_id, title, url, created_by, created_at, updated_at`
- **videos**: `id, folder_id, vimeo_id, title, created_by, created_at, updated_at`

Files live on disk at `<storage>/<folder_id>/<stored_name>` (see
`get_file_path_on_disk`). No schema changes are required.

### 1. Pure naming helper (`includes/class-afm-copy-namer.php`)

A WordPress-free class, unit-tested via `tests/run.php`:

- `split_extension(string $name): array` — returns `[base, ext]` where `ext` includes the
  leading dot (or `''`). Only treats a trailing `.<alnum>` (1–10 chars) as an extension;
  names like `My Folder` or `.htaccess` yield `''`.
- `add_copy_suffix(string $base): string` — `Report` → `Report (copy)`;
  `Report (copy)` → `Report (copy 2)`; `Report (copy 2)` → `Report (copy 3)`.
- `next_copy_name(string $name, bool $is_file): string` — applies `add_copy_suffix` to the
  base (extension-aware when `$is_file`), re-joining the extension.
- `resolve_unique(string $desired, array $existing, bool $is_file, bool $force_copy): string`
  — case-insensitive collision check against `$existing` (the sibling names already in the
  target). If `$force_copy` (same-folder duplicate) or `$desired` collides, repeatedly call
  `next_copy_name` until the result is free; otherwise return `$desired` unchanged. A safety
  cap bounds the loop.

### 2. Backend copy operation (`anchor-private-file-manager.php`)

Register `add_action('wp_ajax_anchor_fm_copy_items', [$this, 'ajax_copy_items'])` and
require the helper at the top alongside the other `includes/` requires.

`ajax_copy_items()`:
1. `require_nonce()`; `is_user_logged_in()` (401).
2. Read `items` (JSON array of `{kind, id}`; `kind` ∈ `file|link|video|folder`) and
   `target_folder_id` (int).
3. Validate the target folder exists and the user `can_user_upload_to_folder()` it (403).
4. Per item, enforce capability matching move semantics:
   - file → `can_user_manage_file`; link → `can_user_manage_link`;
     video → `can_user_manage_video`; folder → administrator only (mirrors
     `ajax_move_folder`). All copies also require upload rights on the target.
5. For a **folder** copy, reject pasting into itself or a descendant
   (`is_descendant` / id-equality), to prevent infinite recursion.
6. Pre-count a folder copy's tree (folders + files + links + videos). If it exceeds a cap
   (e.g. 2000 nodes or depth 50), abort that item with an `error` result before copying
   anything for it.
7. Copy each item (helpers below), collecting a per-item result
   `{kind, sourceId, status: copied|error, newId?, message?}`. A single item's failure
   (e.g. source file missing on disk) marks that item `error` and continues with the rest.
8. `log_activity(user, 'copy_items', 'folder'|'mixed', target_folder_id, summary)`.
9. `json_success(['copied'=>int, 'errors'=>int, 'items'=>[...], 'targetFolderId'=>int])`.

Copy helpers (private):
- `copy_file_row($file, $target_folder_id, $existing_names)` — `wp_unique_filename` in the
  target dir for a fresh `stored_name`; `copy()` the bytes (after `ensure_upload_storage`
  and target-dir/.htaccess/index.php creation as `ajax_move_file` does);
  `resolve_unique` the `original_name`; insert a `files` row (`uploader_user_id` = current
  user, fresh `created_at`, copied `mime_type`/`size`/`sha1`).
- `copy_link_row` / `copy_video_row` — `resolve_unique` the `title`; insert the row into
  the target folder (video keeps the same `vimeo_id`).
- `copy_folder_tree($folder, $target_parent_id, $existing_names)` — `resolve_unique` the
  folder `name`; insert a new `folders` row under `$target_parent_id`; then recurse over
  child folders, files, links, and videos into the new folder. Children keep their original
  names (the new subtree is self-contained, so only top-level name resolution is needed).

For collision checks, "existing names" is the set of sibling display names already in the
destination (folder names + file `original_name` + link/video `title`), gathered once per
destination and updated as each item is inserted so a multi-item paste doesn't self-collide.

### 3. Front-end (`assets/js/file-manager.js`)

- **Clipboard state:** `state.clipboard = { items: [{kind, id, name}] }`, copy-only,
  cleared on demand. Populated from `state.selectedRows` (multi-select) or a single row.
- **Context menu (`buildRowMenu`):** add admin-only `Duplicate` and `Copy` for every
  manageable kind, and `Paste` shown when `state.clipboard.items.length` and the current
  view is a folder. `Paste` targets the currently open folder; `Duplicate` copies into the
  item's current folder.
- **Keyboard:** Cmd/Ctrl+C copies the current selection to the clipboard; Cmd/Ctrl+V pastes
  into the current folder; Cmd/Ctrl+D duplicates the selection in place. Handlers are
  ignored when focus is in an input/textarea/select or a modal is open.
- **Bulk bar:** when `selectedRows` is non-empty, expose `Copy` (and `Duplicate`) in the
  existing selection toolbar; after Copy, `Paste` becomes available in any folder.
- **Modifier-drag:** in the existing drag-drop drop handler, if Ctrl/Alt is held, call
  `anchor_fm_copy_items` instead of the move action.
- **After a copy:** reload the current folder, clear transient busy state, and surface a
  short result (copied count, and any per-item errors) using the existing message/toast
  affordance.

A single JS `copyItems(items, targetFolderId)` helper wraps the AJAX call and the reload,
shared by all four triggers.

## Data Flow

```
[context menu | Cmd-C/V/D | bulk bar | Ctrl-drag]
   → copyItems(items, targetFolderId) → POST anchor_fm_copy_items
   → ajax_copy_items: auth → validate target → per-item capability
        → (folder) reject self/descendant + size/depth cap
        → copy each (file: disk copy + row; link/video: row; folder: recurse)
        → activity log
   → JSON {copied, errors, items, targetFolderId}
   → JS reloads folder + shows result
```

## Error Handling

- **Request-level** (bad nonce, not logged in, target missing or not writable, folder into
  itself/descendant, oversized folder tree): `json_error` / per-item `error`; nothing
  partial is left for that item where avoidable.
- **Item-level** (source gone, disk `copy()` fails, DB insert fails): that item is reported
  `error` with a message; siblings still copy. Partial success is normal and fully reported.
- **Naming:** collisions never fail — they resolve to a `(copy)` variant. The suffix loop is
  capped to avoid pathological inputs.

## Testing

The repo's plain-PHP runner (`php tests/run.php`) covers WordPress-free logic; the rest is
documented manual verification on a live WP install.

- **Naming helper (unit):**
  - `split_extension`: `report.pdf` → `['report', '.pdf']`; `My Folder` → `['My Folder', '']`;
    `.htaccess` → `['.htaccess', '']`; `archive.tar.gz` → `['archive.tar', '.gz']`.
  - `add_copy_suffix`: `Report` → `Report (copy)`; `Report (copy)` → `Report (copy 2)`;
    `Report (copy 2)` → `Report (copy 3)`.
  - `next_copy_name` (file): `report.pdf` → `report (copy).pdf`.
  - `resolve_unique`: no collision + not forced → unchanged; forced (duplicate) → first
    `(copy)`; collides with `Report (copy).pdf` present → `Report (copy 2).pdf`;
    case-insensitive collision is detected.
- **Manual (documented):** duplicate each kind in place → `(copy)` appears; paste a file
  into another folder → bytes present on disk, row correct, inherits destination access;
  recursively copy a folder tree → structure + files duplicated, permissions inherit
  destination; paste a folder into its own descendant is rejected; copying a folder whose
  source file is missing reports that item as `error` while others succeed; modifier-drag
  copies (item remains in the source folder); keyboard shortcuts don't fire while typing.

## Files Touched

- `includes/class-afm-copy-namer.php` — new pure naming/collision helper.
- `tests/run.php` — unit checks for the naming helper.
- `anchor-private-file-manager.php` — require the helper; register
  `wp_ajax_anchor_fm_copy_items`; `ajax_copy_items()` and the per-kind copy helpers;
  reuse `is_descendant`, `can_user_*`, `get_*_row`, `get_file_path_on_disk`,
  `ensure_upload_storage`, `log_activity`.
- `assets/js/file-manager.js` — clipboard state, `copyItems()` helper, context-menu items,
  keyboard handlers, bulk-bar buttons, modifier-drag branch.
- `assets/css/file-manager.css` — any minor styles for new menu/bar affordances (reuse
  existing `afm__*` classes where possible).

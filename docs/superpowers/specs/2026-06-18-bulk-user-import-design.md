# Bulk User Import — Design

**Date:** 2026-06-18
**Plugin:** Anchor Private File Manager (`anchor-private-file-manager.php`)
**Branch:** feature/finder-rework-video

## Problem

The plugin has no way to create users in bulk. Admins must add users one at a time
through WordPress's native Users screen. We want admins to upload a CSV from inside
the plugin's own UI, auto-create the users, assign them a WordPress role, and
optionally email each new user a link to set their password — covering everything a
normal single-user creation does.

## Goals

- Bulk-create WordPress users from a CSV uploaded inside the plugin's front-end UI.
- Assign one WordPress **role** to the whole batch, chosen at import time.
- Optionally notify each new user by email (WordPress's standard set-password link).
- Require only first name, last name, and email per row; username is optional and
  derived when absent.
- Report the outcome of every row (created / skipped / error) back to the admin.

## Non-Goals

- No per-row role or per-row folder/file permission assignment. "Rules" here means a
  single WordPress role for the batch. (Folder/file permissions via
  `wp_anchor_fm_permissions` are out of scope.)
- No editing or deleting of existing users.
- No wp-admin screen. The feature lives only in the plugin's front-end shortcode output.
- No emailing of plaintext passwords. Passwords are auto-generated and never shown;
  users set their own via the standard WordPress link.

## Decisions (from brainstorming)

1. **"Rules" = WordPress role**, one role for the entire batch, chosen in the import UI.
2. **Email = WordPress-standard set-password link**, toggled by a checkbox (default on).
3. **Location = admin-only tab inside the front-end portal** the shortcode renders
   (`render_documents_portal`), not the wp-admin Users menu.

## Architecture

The portal is rendered by `render_documents_portal()` (shortcodes
`anchor_file_manager`, `anchor_account_portal`, `anchor_documents_portal`). It already
has admin-only sidebar nav tabs gated by `current_user_can('administrator')` — the
"Product Docs" tab (`data-apfm-tab="product-docs"` / `data-apfm-panel="product-docs"`)
is the template to follow. The front end talks to `admin-ajax.php` through the JS
`api(action, data)` helper (nonce `AnchorFM.nonce`, action `anchor_fm_nonce`), and all
admin handlers gate on `require_nonce()` + `current_user_can('administrator')`.

### 1. Front-end UI (`render_documents_portal` markup + `assets/js/file-manager.js`)

- **Sidebar nav item** (admin-only, mirrors Product Docs):
  `<button data-apfm-tab="users">` labeled "Add Users", dashicon `dashicons-groups`.
- **Panel** (admin-only): `<div data-apfm-panel="users">` containing:
  - CSV file input (`accept=".csv,text/csv"`).
  - **Role** `<select>` populated from `AnchorFM.roles` (already localized; excludes
    administrator). Default selection: WordPress default role
    (`get_option('default_role')`) when present in the list, else the first option.
  - **Checkbox** "Email new users a link to set their password" — checked by default.
  - **Import** button (`data-afm-action="bulk-import-users"`).
  - **Results region** (empty until import returns): a summary line
    ("X created, Y skipped, Z errors") plus a table of per-row results
    (row #, username, email, status, message).
- **JS behavior:** on Import, build a `FormData` (CSV file + `role` + `send_email` +
  `nonce`) and POST to `admin-ajax.php` using the existing file-upload `$.ajax` pattern
  (the upload code around `file-manager.js` is the template, since `api()` is
  form-encoded and we need multipart). Disable the button while in flight, then render
  the returned report. Client does light pre-flight only (a file is selected); the
  server is the source of truth for parsing and validation.

### 2. Backend handler (`anchor-private-file-manager.php`)

- **Register** `add_action('wp_ajax_anchor_fm_bulk_import_users', [$this, 'ajax_bulk_import_users'])`
  alongside the other `wp_ajax_anchor_fm_*` registrations.
- **`ajax_bulk_import_users()`**:
  1. `require_nonce()`; `is_user_logged_in()` else 401; `current_user_can('administrator')`
     else 403 — identical to existing admin handlers.
  2. Read `$_FILES['csv']`. Validate presence, upload error, size (cap, e.g. 2 MB), and
     extension/mime (`.csv` / `text/csv` / `text/plain`).
  3. Validate `role` against `get_editable_roles_for_permissions()` keys (administrator
     rejected). Read `send_email` as boolean.
  4. Parse with `fgetcsv`. **Header detection:** if the first row contains a cell whose
     normalized value is a known header (`username`, `first name`/`first_name`/`first`,
     `last name`/`last_name`/`last`, `email`), treat row 1 as a header and map columns by
     name; otherwise treat all rows as positional in the default order
     `username, first name, last name, email`.
  5. Enforce a max row count (e.g. 1000); reject the file with a clear error if exceeded.
  6. For each data row:
     - Trim/sanitize: `sanitize_text_field` for names, `sanitize_email` for email,
       `sanitize_user` for username.
     - Skip blank rows silently.
     - **Validate:** first name, last name, and a valid email (`is_email`) are required;
       otherwise record an `error` row with the reason.
     - **Username:** if empty, derive `strtolower( first_initial . '.' . last_name )`,
       then `sanitize_user`. Ensure uniqueness against `username_exists` and against
       usernames already assigned earlier in this batch, appending `2`, `3`, … on
       collision. If a username is supplied but already taken, also suffix to make it
       unique (so the row still succeeds) — record the final username in the report.
     - **Duplicate email:** if `email_exists` (already in WP) or the email already
       appeared earlier in this CSV, record a `skipped` row ("email already exists") —
       do not create a duplicate.
     - **Create:** `wp_generate_password(16)`, then `wp_insert_user([...])` with
       `user_login`, `user_email`, `user_pass`, `first_name`, `last_name`,
       `display_name = "First Last"`, `role`. On `WP_Error`, record an `error` row.
     - **Notify:** if `send_email`, call `wp_new_user_notification($user_id, null, 'user')`
       (sends WordPress's standard set-password email to the user only).
     - Record a `created` row (row #, final username, email).
  7. Insert one `wp_anchor_fm_activity` row with action `bulk_import` and a meta JSON
     summary (counts), matching the plugin's activity-logging pattern.
  8. `json_success([ 'created' => …, 'skipped' => …, 'errors' => …, 'rows' => [...] ])`.

## Data Flow

```
Admin → "Add Users" tab → select CSV, pick role, toggle email → Import
  → POST multipart (csv, role, send_email, nonce) → admin-ajax.php
  → ajax_bulk_import_users(): auth → parse → per-row create/skip/error
       → optional wp_new_user_notification per created user
       → activity log
  → JSON report → JS renders summary + per-row table
```

## Error Handling

- **Request-level** (auth, missing/oversized/wrong-type file, invalid role, too many
  rows): `json_error` with a clear message; nothing is created.
- **Row-level** (missing fields, bad email, `wp_insert_user` failure): that row is
  marked `error` with a reason; other rows still process. Partial success is normal and
  fully reported.
- **Duplicates:** existing/in-file duplicate emails → `skipped` (not an error). Username
  collisions are auto-resolved by suffixing, never fatal.

## Testing

The repo has a `tests/` directory. Cover the pure logic that does not require a live
WordPress runtime, and document manual checks for the rest.

- **Username derivation:** `Jane`/`Smith` → `j.smith`; collision → `j.smith2`; supplied
  username preserved; supplied-but-taken username suffixed.
- **CSV parsing:** positional order parsed correctly; header row detected and mapped by
  name; blank rows skipped; ragged/short rows produce row-level errors, not a crash.
- **Validation:** missing first/last/email and malformed email yield `error` rows;
  in-file and existing duplicate emails yield `skipped` rows.
- **Manual (documented):** end-to-end import in WP — users created with correct role,
  set-password email sent when checked and suppressed when unchecked, report accurate.

## Files Touched

- `anchor-private-file-manager.php` — register AJAX action; `ajax_bulk_import_users()`
  and CSV/username helpers; admin-only nav item + panel markup in
  `render_documents_portal()`; add any new i18n strings to the `AnchorFM` localize block.
- `assets/js/file-manager.js` — tab wiring, panel rendering, import POST + report.
- `assets/css/file-manager.css` — minimal styles for the panel and results table
  (reuse existing `afm__*` classes where possible).
- `tests/` — unit tests for username derivation, CSV parsing, and validation.

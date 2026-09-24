# User Manager + Configurable Branding — Design

**Date:** 2026-09-24
**Plugin:** Anchor Private File Manager (`anchor-private-file-manager.php`), 2.14.0 → 2.15.0
**Branch:** `feature/user-manager`

## Problem

The plugin is being installed on a second site (TMJ & Sleep Therapy Centre of St. Louis)
as a patient portal. Two things block that:

1. **International branding is hardcoded.** The sidebar logo is a literal
   `tmjtherapycentre.com` favicon URL (`render_documents_portal`), and the
   request-access recipient defaults to `tiffany@tmjtherapycentre.com`
   (`DEFAULT_REQUEST_ACCESS_EMAIL`). On STL, access requests would email International.
2. **User management is CSV-only and add-only.** The clinic needs to add one patient
   at a time, set passwords explicitly (including one shared starting password), change
   a patient's role (condition group), reset passwords and remove patients — without
   going to wp-admin.

This reverses one non-goal of `2026-06-18-bulk-user-import-design.md`: admins may now
set passwords explicitly. Emailing plaintext passwords remains out.

## Goals

- Logo configurable in settings; International keeps working with zero reconfiguration.
- Request-access recipient keeps `tiffany@…` on existing installs only; fresh installs
  default to the site admin email.
- A **Users** tab (renamed from "Add Users") that lists users with search, role filter
  and pagination, plus add / change role / set password / send reset / remove.
- Explicit passwords on single add and CSV import (per-row column + batch default).

## Non-Goals

- Login page (the site owner builds it).
- Non-administrator "portal manager" capability; everything stays administrator-gated.
- Per-patient watch rollup report.
- Editing name/email of other users (wp-admin still covers that).
- Emailing passwords in any form.

## 1. Branding

**Setting `anchor_fm_portal_logo`** (string URL, `esc_url_raw` on save), added to the
existing settings page as "Portal logo URL" with a description stating the fallback.

Resolution, in a new `portal_logo_url()` method:
1. The setting, if non-empty.
2. `get_site_icon_url(96)`, if non-empty.
3. `''` → the `<img>` is not rendered at all.

The hardcoded International favicon URL is deleted.

**Request-access recipient.**
- `DEFAULT_REQUEST_ACCESS_EMAIL` constant is removed. The default becomes
  `get_option('admin_email')`, used both by `get_request_access_email()` and the
  `register_setting` sanitize fallback.
- `maybe_upgrade_db()` gains a one-time step, gated like `$pre_coverage`, but also
  host-gated via `is_legacy_international_host()` (host equals `tmjtherapycentre.com`
  or ends with `.tmjtherapycentre.com`): when the installed DB version is not `'0'`,
  is `< 2.15.0`, and the host matches, store `tiffany@tmjtherapycentre.com` if the
  option has never been stored, and store the legacy portal-logo URL if the logo
  option has never been stored AND `get_site_icon_url(96)` is empty. The host gate
  matters because other sites may also have run pre-2.15 releases of this plugin —
  without it they would inherit International's hardcoded values instead of the new
  admin-email / site-icon defaults. Fresh installs go through `activate()`, which
  stamps the DB version to 2.15.0 only after schema work succeeds, so the step never
  fires there (and a failed fresh activation retries with `$installed === '0'`).

## 2. Server: user-management endpoints

All new handlers: `require_nonce()`, logged in, `current_user_can('administrator')`,
same as `ajax_bulk_import_users`. Registered in the constructor next to it.

| Action | Input | Behavior |
|---|---|---|
| `anchor_fm_users_list` | `search`, `role`, `page` | `get_users` with `search` over login/email/display_name, optional `role`, `number` 25, `paged`, `count_total`. Returns `users[]`, `total`, `page`, `pages`. Each user: `id`, `displayName`, `email`, `username`, `roles[]`, `registered`, `lastWatched` (nullable), `manageable` (bool). |
| `anchor_fm_user_create` | `first_name`, `last_name`, `email`, `username?`, `role`, `password?`, `send_email` | Same validation/derivation as a CSV row (see §4). Returns the shaped user. |
| `anchor_fm_user_set_role` | `user_id`, `role` | `$u->set_role($role)`. |
| `anchor_fm_user_set_password` | `user_id`, `password` | `wp_set_password`. Password never echoed. |
| `anchor_fm_user_send_reset` | `user_id` | Same email as `ajax_ap_send_reset`, extracted into a shared `send_password_reset_email(WP_User)` used by both. |
| `anchor_fm_user_delete` | `user_id` | `require_once ABSPATH . 'wp-admin/includes/user.php'`; `wp_delete_user($id, get_current_user_id())` (content reassigned to the acting admin). |

**`lastWatched`** comes from one grouped query per page:
`SELECT user_id, MAX(last_viewed_at) FROM video_views WHERE user_id IN (…) GROUP BY user_id`.

**Guardrails (server-enforced):**
- A target is *manageable* only if it exists, is not an administrator, and is not the
  current user. `set_role`, `set_password`, `send_reset`, `delete` return 403 otherwise.
  The list still shows unmanageable users, with `manageable: false`.
- Roles must be in `get_editable_roles_for_permissions()` (never `administrator`).
- Passwords: trimmed, ≥ 10 characters — the same rule as `ajax_ap_change_password`,
  which is refactored to use the shared validator.
- Passwords never appear in `log_activity` meta, JSON responses or emails.
- Every mutation calls `log_activity` (`user_create`, `user_set_role`,
  `user_set_password`, `user_send_reset`, `user_delete`).

**Cleanup on delete.** A `deleted_user` hook (fires for this endpoint and for wp-admin
deletions) deletes the user's rows from `permissions` (`subject_type='user'`,
`subject_key=<id>`) and `video_views` (`user_id=<id>`). User conditions inside
`permission_policies` JSON are left alone: they match nothing once the user is gone,
and MySQL does not reuse user IDs.

## 3. CSV import changes

- `Anchor_FM_User_Import::header_key` recognizes `password` / `pass` / `pwd`.
  Default headerless column order becomes `username, first name, last name, email, password`
  (5th column optional, so existing 4-column files parse unchanged).
- `ajax_bulk_import_users` accepts `default_password` (optional). Per-row password wins,
  then `default_password`, then `wp_generate_password(16, true, false)`.
- A row whose supplied password fails validation is reported `error`
  ("Password must be at least 10 characters") and not created. An invalid
  `default_password` rejects the whole request up front.
- Report rows gain nothing password-related.

## 4. Shared rules: `includes/class-afm-user-admin.php`

WordPress-free, loaded by the plugin and by `tests/run.php`:

- `validate_password($pw)` → `['ok'=>bool,'error'=>string]`; `MIN_PASSWORD_LENGTH = 10`.
- `resolve_password($row_pw, $default_pw)` → `['source'=>'row'|'default'|'generate','password'=>string]`
  (empty string for `generate`; caller generates).
- `is_manageable(array $target_roles, $target_id, $actor_id)` → bool.
- `valid_role($role, array $valid_keys)` → bool (rejects `administrator` even if listed).

`Anchor_FM_User_Import::validate` is reused by single create; single create goes through
the same row pipeline as a CSV row (validate → duplicate-email check → username
derive/make_unique → password resolve → `wp_insert_user`), extracted into one private
`create_portal_user(array $row, $role, $default_password, $send_email)` method that both
endpoints call. No second implementation.

## 5. Front end

**Markup (`render_documents_portal`).** Nav item renamed "Users". The panel becomes:
a toolbar (search input, role filter `<select>`, "Add person" and "Import CSV" buttons),
a table (Name, Email, Role, Added, Last watched, actions menu), and pagination
(Prev / "Page X of Y" / Next). The old inline import form moves into a popup.

**`assets/js/user-manager.js`** (new, enqueued after `file-manager.js`, admin only).
Owns the Users panel. It uses the existing modal markup (`[data-afm-modal]`), so the
modal open/close helpers `file-manager.js` already has are exposed on a small
`window.AnchorFMModal` object (`open(title, bodyHtml, primaryLabel, onPrimary)`,
`close()`, `busy(bool)`) instead of being duplicated. The existing bulk-import JS moves
out of `file-manager.js` into this file.

- Loads the list the first time the Users tab opens; search is debounced 300 ms.
- **Add person** popup: first, last, email, username (placeholder shows derivation),
  role, password (with a "Generate" button filling a random 16-char value, shown so the
  admin can copy it), "Email a welcome / set-password link" checkbox (default on).
- **Row actions** (only when `manageable`): Change role (popup with select),
  Set password (popup, password + Generate), Email reset link (immediate, toast),
  Remove (confirm popup naming the user and email).
- **Import CSV** popup: file, role, "Password for everyone without one" (optional),
  email checkbox; results table as today. Refreshes the list after import.
- Errors come from `data.message` as elsewhere.

**CSS** in `file-manager.css`: table, toolbar and pagination styles using the existing
`afm__` tokens; narrow screens stack the table rows.

## 6. Testing

- `tests/run.php`: `Anchor_FM_User_Admin` (password boundary at 9/10 chars, trimming,
  resolve order, manageable for admin/self/other, role validation) and parser
  (password column by header and by 5th position, 4-column files unchanged).
- `php -l` on every PHP file; `node --check` on the new JS.
- No local WordPress exists, so live behavior can't be executed here; the PR includes a
  manual verification checklist for staging (add, import with shared password, log in
  as patient, role change hides folder, delete clears permissions, International logo
  and request-access email unchanged after update).

## Release

Bump `Version:` header and `const VERSION` to 2.15.0. The plugin auto-updates on
International after a tag, so the branding migration must be correct before tagging.

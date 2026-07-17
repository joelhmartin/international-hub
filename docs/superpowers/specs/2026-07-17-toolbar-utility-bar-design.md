# Toolbar rework: admin utility bar

Date: 2026-07-17
Status: Approved

## Problem

The file manager header is one crowded row: title, breadcrumbs, search, Refresh,
New link, New video, Upload. New folder is stranded in the sidebar
(`.afm__sidebarActions`), away from every other create action. The admin-only
actions render as full-weight buttons competing with Upload, the one action that
matters to ordinary users.

## Design

Two rows replace the single `.afm__toolbar`.

**Row 1 — `.afm__utilityBar`** (new). Right-aligned. `New folder`, `New link`,
`New video` render as `.afm__utilityLink`: transparent background, no border,
dashicon + label, `var(--afm-faint)` text going to `var(--afm-text)` with
`text-decoration: underline` on hover. `Upload` keeps `.afm__btn--primary` and
sits at the far right as the single visual anchor.

**Row 2 — `.afm__toolbar`** (existing, simplified). `File Manager` title +
breadcrumbs left; search takes `flex: 1` with `max-width: 420px`.

## Gating

The whole bar is wrapped in `current_user_can('administrator')` and is not
rendered for anyone else.

| Action | Gate | Tabs |
| --- | --- | --- |
| New folder / New link / New video | `current_user_can('administrator')` | Files |
| Upload | `current_user_can('administrator')` + folder selected | Files |

**Correction to an earlier draft of this spec**, which claimed Upload was
capability-gated and that a non-admin with manage rights could upload. It
cannot. `can_user_upload_to_folder()` (anchor-private-file-manager.php:1320) is
`return user_can($user_id, 'administrator');` — it ignores `$folder_id`
entirely, and all four upload entry points (:1906, :2499, :2582) go through it.
Upload has always been administrator-only server-side. The claim came from
reading the JS capability ladder without checking the server gate behind it.

That mismatch was a live bug, not a triviality: `get_effective_capability()`
returns `'manage'` to a non-admin **folder owner** (:973), so the JS enabled
their Upload button and drag-and-drop overlay, and every attempt collected a
403. All upload affordances now go through one `canUploadHere()` helper that
leads with the role check, matching the server.

Refresh is removed outright — reloading the page does the same job.

The `:has()` rule still earns its place: it collapses the bar on tabs where the
files-only children are hidden, so an admin on the Account tab sees no empty
strip and no stray border.

## Files

- `anchor-private-file-manager.php` — `.afm__utilityBar` markup ~line 643;
  remove `.afm__sidebarActions` block (630-637); move New link / New video /
  Upload / Refresh out of `.afm__toolbarRight`.
- `assets/css/file-manager.css` — `.afm__utilityBar`, `.afm__utilityLink`;
  search `flex: 1`; retire `.afm__toolbarRight`, `.afm__sidebarActions`.
- `assets/js/account-documents.js` — `updateToolbar()`: add new-folder to the
  `$filesOnly` set; remove `$refreshBtn` and the refresh click handler.
- `assets/js/file-manager.js` — `canUploadHere()`; drop the now-orphaned
  `anchorfm:refresh` listener.

Every `data-afm-action` value is unchanged, so the delegated handlers in
`file-manager.js` keep working untouched.

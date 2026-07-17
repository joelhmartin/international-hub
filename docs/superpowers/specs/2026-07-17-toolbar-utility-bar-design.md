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

**Row 1 — `.afm__utilityBar`** (new). Right-aligned. `Refresh`, `New folder`,
`New link`, `New video` render as `.afm__utilityLink`: transparent background,
no border, dashicon + label, `var(--afm-faint)` text going to `var(--afm-text)`
with `text-decoration: underline` on hover. `Upload` keeps `.afm__btn--primary`
and sits at the far right as the single visual anchor.

**Row 2 — `.afm__toolbar`** (existing, simplified). `File Manager` title +
breadcrumbs left; search takes `flex: 1` with `max-width: 420px`.

## Gating

Per-item, preserving today's behavior exactly. No user gains or loses a
permission.

| Action | Gate | Tabs |
| --- | --- | --- |
| Refresh | none | all |
| New folder / New link / New video | `current_user_can('administrator')` | Files |
| Upload | folder capability (`canUpload`, rank >= 3) | Files |

Upload is **capability**-gated, not admin-gated: a non-admin with manage rights
on a folder can upload today and must keep that. This is why the bar is not
wrapped in a single `current_user_can('administrator')` check.

The bar hides when it has no visible children, via
`.afm__utilityBar:has(> *:not([hidden])) { display: flex }` with a
`display: none` default — so a read-only viewer sees no empty bar or stray
border.

Drop the current rule at `account-documents.js:76` that hides Refresh whenever
you are inside a folder on the Files tab — today that means admins lose Refresh
the moment they open a folder. Refresh reloads the current view, whatever it is.

## Files

- `anchor-private-file-manager.php` — `.afm__utilityBar` markup ~line 643;
  remove `.afm__sidebarActions` block (630-637); move New link / New video /
  Upload / Refresh out of `.afm__toolbarRight`.
- `assets/css/file-manager.css` — `.afm__utilityBar`, `.afm__utilityLink`;
  search `flex: 1`; retire `.afm__toolbarRight`, `.afm__sidebarActions`.
- `assets/js/account-documents.js` — `updateToolbar()`: add new-folder to the
  `$filesOnly` set, drop the Refresh-in-folder rule.

Every `data-afm-action` value is unchanged, so the delegated handlers in
`file-manager.js` keep working untouched. Markup and CSS only.

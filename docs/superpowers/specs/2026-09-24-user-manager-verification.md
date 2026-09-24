# User Manager 2.15.0 — Staging Verification

Run on a staging copy with the plugin updated from 2.14.x, logged in as an administrator.

## Branding
- [ ] International staging: sidebar logo shows — the site icon if one is set; if none is set, the migration stores the legacy International favicon as the Portal logo URL and the sidebar shows that. Settings → Anchor File Manager → Request-access recipient reads tiffany@tmjtherapycentre.com.
  - Note: the 2.15.0 pin is host-gated (`tmjtherapycentre.com` or a `*.tmjtherapycentre.com` subdomain). Run this check on the production-host upgrade itself, or on a staging copy that is actually served at a tmjtherapycentre.com host — a staging copy reachable on any other host (e.g. a generic Kinsta staging domain) will NOT get the pin and will correctly show the site admin email instead.
- [ ] Fresh install (STL staging): recipient field is blank (placeholder shows the admin email) and requests go to the site admin email; setting a Portal logo URL changes the sidebar image; clearing it falls back to the site icon.

## Users tab
- [ ] Tab reads "Users"; list loads with Added and Last watched columns; administrators and yourself show "—" for actions.
- [ ] Search by partial email finds the user; role filter narrows; Prev/Next page when >25 users.
- [ ] Add person with no password + email checked → user receives the WP set-password email.
- [ ] Add person with password "Welcome2026!" → can log in with it immediately.
- [ ] Add person with a 9-character password → error "Password must be at least 10 characters", no user created.
- [ ] Add person with an existing email → "Email already exists".
- [ ] Import a 4-column CSV → users created with generated passwords.
- [ ] Import with "Password for everyone" set → each user logs in with it; a row with its own password uses that one.
- [ ] Import with a 5-character batch password → whole import refused, nobody created.
- [ ] Change role → the user loses/gains the matching folder on next load.
- [ ] Set password → the user logs in with the new one.
- [ ] Email reset → email arrives.
- [ ] Remove → user gone from list and wp-admin; their rows gone from wp_anchor_fm_permissions (subject_type='user') and wp_anchor_fm_video_views.
- [ ] Deleting a user in wp-admin also clears those rows.
- [ ] Open "Add person", switch to the Account tab, return to Documents, open a folder's Permissions and Save → permissions save normally (no stray user-manager handler).
- [ ] As a non-admin: no Users tab; POSTing anchor_fm_users_list returns 403.

## Roles (2.16.0)
- [ ] Users → Roles → add "TMJ patient": it appears in the list with 0 users, and — without reloading — in the Users role filter, Add person, Import CSV, Change role, and a folder's Permissions popup (role checkboxes and rule role picker).
- [ ] In Add person, "+ New role" creates a role and selects it; saving assigns it.
- [ ] Rename a role: the new name shows in the table's Role column and all pickers immediately; users keep the role and folder access.
- [ ] Delete is disabled while users hold the role; after moving them, delete works, the role disappears from every picker, and its rows are gone from wp_anchor_fm_permissions (subject_type='role').
- [ ] Creating "Subscriber" / "Administrator" / a duplicate name is refused with "A role with that name already exists."
- [ ] Core and WooCommerce roles never appear in the Roles popup; International's popup starts empty.
- [ ] A user given a portal role can log in and see only the folders shared with that role, and cannot reach wp-admin screens beyond their profile.

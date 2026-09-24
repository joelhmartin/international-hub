# User Manager + Configurable Branding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the portal's logo and request-access recipient configurable (without changing International's behavior), and replace the CSV-only "Add Users" tab with a full user manager that supports explicit passwords.

**Architecture:** Pure, WordPress-free rules go in `includes/class-afm-user-admin.php` and the existing `class-afm-user-import.php`, both covered by `tests/run.php`. The AJAX handlers in `anchor-private-file-manager.php` share one `create_portal_user()` row pipeline for single add and CSV import. A new `assets/js/user-manager.js` owns the Users panel and reuses the existing modal through a small `window.AnchorFMUI` object exposed by `file-manager.js`.

**Tech Stack:** WordPress plugin, PHP 8.1 (CI) / 8.4 (local), jQuery, plain-PHP test runner `tests/run.php`, Node VM test `tests/client-expand.js`.

**Spec:** `docs/superpowers/specs/2026-09-24-user-manager-and-branding-design.md`

## Global Constraints

- Version becomes `2.15.0` in BOTH the `Version:` header and `const VERSION` (they must match).
- Every new AJAX handler: `$this->require_nonce()`, `is_user_logged_in()`, `current_user_can('administrator')`, else `json_error` 401/403.
- Minimum password length: 10 characters, after trim. Error text exactly: `Password must be at least 10 characters`.
- Passwords never appear in `log_activity` meta, JSON responses, or emails.
- Assignable roles = `get_editable_roles_for_permissions()` keys; `administrator` is never assignable.
- A target user is manageable only if it exists, is not an administrator, and is not the current user.
- International's preserved request-access email is exactly `tiffany@tmjtherapycentre.com`.
- No local WordPress exists: nothing here can be executed against WP. Verify with `php -l`, `php tests/run.php`, `node --check`, `node tests/client-expand.js`.
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Push only with `git push origin feature/user-manager`, never bare `git push`.

## Review Focus

1. **Existing 4-column CSV files** (no password) must import exactly as before: generated password, no errors. → Task 2 test `4-column file has empty password`.
2. **A row password shorter than 10** must fail only that row, not the batch; a short *batch default* must reject the whole request before any user is created. → Task 1 tests on `validate_password` / `resolve_password` + Task 3 handler ordering.
3. **Admin acting on themself or another admin** (role change, password, delete) must 403 even if the UI is bypassed. → Task 1 `is_manageable` tests; Task 4 uses `manageable_target()` in every mutation.
4. **International after auto-update**: logo still shows (site icon fallback) and access requests still go to tiffany@. → Task 5 migration gated on installed version ≠ `'0'` and option absent.
5. **Opening a user popup, then switching tabs** closes the modal and must not leave a stale external handler that fires on the next file-manager modal. → Task 6 clears `externalHandler` in `closeModal()`; covered by the added `client-expand.js` check.

---

## File Structure

| File | Responsibility |
|---|---|
| `includes/class-afm-user-admin.php` (new) | Pure rules: password validation/resolution, manageability, role validity |
| `includes/class-afm-user-import.php` (modify) | CSV parsing gains a `password` column |
| `anchor-private-file-manager.php` (modify) | Settings, migration, AJAX endpoints, `create_portal_user`, `deleted_user` cleanup, markup |
| `assets/js/file-manager.js` (modify) | Expose `window.AnchorFMUI`; external modal handler; remove the import code that moves |
| `assets/js/account-documents.js` (modify) | Trigger `anchorfm:showUsers` when the Users tab opens |
| `assets/js/user-manager.js` (new) | Users panel: list, search, filter, pagination, popups |
| `assets/css/file-manager.css` (modify) | Users toolbar/table/pagination styles |
| `tests/run.php`, `tests/client-expand.js` (modify) | New checks |

---

### Task 1: Pure user-admin rules

**Files:**
- Create: `includes/class-afm-user-admin.php`
- Modify: `anchor-private-file-manager.php:14` (add require)
- Test: `tests/run.php` (append before the final `echo`)

**Interfaces:**
- Produces:
  - `Anchor_FM_User_Admin::MIN_PASSWORD_LENGTH` = `10`
  - `Anchor_FM_User_Admin::validate_password($pw): array{ok:bool, error:string}`
  - `Anchor_FM_User_Admin::resolve_password($row_pw, $default_pw): array{source:'row'|'default'|'generate', password:string}` (password is trimmed; `''` when `generate`)
  - `Anchor_FM_User_Admin::is_manageable(array $target_roles, $target_id, $actor_id): bool`
  - `Anchor_FM_User_Admin::valid_role($role, array $valid_keys): bool`

- [ ] **Step 1: Write the failing tests** — append to `tests/run.php` just before `echo $failures === 0 ...`:

```php
require __DIR__ . '/../includes/class-afm-user-admin.php';

// --- Anchor_FM_User_Admin::validate_password ---
check('pw: 9 chars rejected', Anchor_FM_User_Admin::validate_password('123456789')['ok'], false);
check('pw: 10 chars ok', Anchor_FM_User_Admin::validate_password('1234567890')['ok'], true);
check('pw: error text', Anchor_FM_User_Admin::validate_password('short')['error'], 'Password must be at least 10 characters');
check('pw: trimmed before length', Anchor_FM_User_Admin::validate_password('  12345678  ')['ok'], false);
check('pw: empty rejected', Anchor_FM_User_Admin::validate_password('')['ok'], false);

// --- Anchor_FM_User_Admin::resolve_password ---
check('resolve: row wins', Anchor_FM_User_Admin::resolve_password(' rowpass123 ', 'default1234'), ['source' => 'row', 'password' => 'rowpass123']);
check('resolve: default when row blank', Anchor_FM_User_Admin::resolve_password('  ', 'default1234'), ['source' => 'default', 'password' => 'default1234']);
check('resolve: generate when both blank', Anchor_FM_User_Admin::resolve_password('', ''), ['source' => 'generate', 'password' => '']);
check('resolve: null inputs generate', Anchor_FM_User_Admin::resolve_password(null, null), ['source' => 'generate', 'password' => '']);

// --- Anchor_FM_User_Admin::is_manageable ---
check('manage: other subscriber ok', Anchor_FM_User_Admin::is_manageable(['subscriber'], 7, 1), true);
check('manage: self refused', Anchor_FM_User_Admin::is_manageable(['subscriber'], 1, 1), false);
check('manage: administrator refused', Anchor_FM_User_Admin::is_manageable(['editor', 'administrator'], 7, 1), false);
check('manage: missing id refused', Anchor_FM_User_Admin::is_manageable(['subscriber'], 0, 1), false);

// --- Anchor_FM_User_Admin::valid_role ---
check('role: listed ok', Anchor_FM_User_Admin::valid_role('tmj_patient', ['subscriber', 'tmj_patient']), true);
check('role: unlisted refused', Anchor_FM_User_Admin::valid_role('editor', ['subscriber']), false);
check('role: administrator refused even if listed', Anchor_FM_User_Admin::valid_role('administrator', ['administrator']), false);
check('role: empty refused', Anchor_FM_User_Admin::valid_role('', ['subscriber']), false);
```

- [ ] **Step 2: Run to verify failure**

Run: `php tests/run.php | tail -3`
Expected: fatal "Failed opening required ... class-afm-user-admin.php".

- [ ] **Step 3: Implement** `includes/class-afm-user-admin.php`:

```php
<?php
/**
 * Pure (WordPress-free) rules for managing portal users from the front end:
 * password policy, which password a new user gets, and which accounts an
 * admin may act on. Kept free of WP calls so tests/run.php can cover it.
 */
class Anchor_FM_User_Admin {

    const MIN_PASSWORD_LENGTH = 10;

    public static function validate_password($pw) {
        $pw = trim((string) $pw);
        if (strlen($pw) < self::MIN_PASSWORD_LENGTH) {
            return ['ok' => false, 'error' => 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters'];
        }
        return ['ok' => true, 'error' => ''];
    }

    /** Row password beats the batch default; neither means the caller generates one. */
    public static function resolve_password($row_pw, $default_pw) {
        $row_pw = trim((string) $row_pw);
        if ($row_pw !== '') return ['source' => 'row', 'password' => $row_pw];
        $default_pw = trim((string) $default_pw);
        if ($default_pw !== '') return ['source' => 'default', 'password' => $default_pw];
        return ['source' => 'generate', 'password' => ''];
    }

    public static function is_manageable(array $target_roles, $target_id, $actor_id) {
        $target_id = (int) $target_id;
        if ($target_id <= 0) return false;
        if ($target_id === (int) $actor_id) return false;
        return !in_array('administrator', array_map('strtolower', $target_roles), true);
    }

    public static function valid_role($role, array $valid_keys) {
        $role = (string) $role;
        if ($role === '' || $role === 'administrator') return false;
        return in_array($role, $valid_keys, true);
    }
}
```

And in `anchor-private-file-manager.php`, after the `class-afm-user-import.php` require (line 14):

```php
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-user-admin.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `php tests/run.php | tail -1 && php -l includes/class-afm-user-admin.php`
Expected: `ALL PASS`, `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-user-admin.php tests/run.php anchor-private-file-manager.php
git commit -m "feat: pure rules for front-end user management"
```

---

### Task 2: CSV password column

**Files:**
- Modify: `includes/class-afm-user-import.php` (`header_key`, `parse`)
- Test: `tests/run.php` (after the existing `parse` checks, ~line 193)

**Interfaces:**
- Produces: every row from `Anchor_FM_User_Import::parse()` now has a `password` key (string, trimmed, `''` when absent). Headerless column order: `username, first_name, last_name, email, password`.

- [ ] **Step 1: Write the failing tests** — insert after `check('blank lines skipped', ...)`:

```php
// --- password column ---
check('4-column file has empty password', $p['rows'][0]['password'], '');
$pp = Anchor_FM_User_Import::parse("jsmith,Jane,Smith,jane@x.com,Secret12345\n");
check('5th positional column is password', $pp['rows'][0]['password'], 'Secret12345');
$ph = Anchor_FM_User_Import::parse("email,first name,last name,password\njane@x.com,Jane,Smith,Secret12345\n");
check('password header mapped', $ph['rows'][0]['password'], 'Secret12345');
check('pwd header alias', Anchor_FM_User_Import::parse("email,pwd\njane@x.com,Secret12345\n")['rows'][0]['password'], 'Secret12345');
check('header without password column', $h['rows'][0]['password'], '');
```

- [ ] **Step 2: Run to verify failure**

Run: `php tests/run.php | grep -E "FAIL|password" | head`
Expected: FAIL lines for the password checks (undefined index warning counts as a failure).

- [ ] **Step 3: Implement.** In `header_key`, add before `default:`:

```php
            case 'password':
            case 'pass':
            case 'pwd':
                return 'password';
```

In `parse`, change the defaults and the row template:

```php
        $default_cols = ['username', 'first_name', 'last_name', 'email', 'password'];
```

```php
            $row = ['line' => $i + 1, 'username' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'password' => ''];
```

Update the class docblock line "CSV parsing" to "CSV parsing (username, first, last, email, optional password)".

- [ ] **Step 4: Run to verify pass**

Run: `php tests/run.php | tail -1`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-user-import.php tests/run.php
git commit -m "feat: optional password column in user CSV import"
```

---

### Task 3: Shared user-creation pipeline, explicit passwords in import, shared reset email

**Files:**
- Modify: `anchor-private-file-manager.php` — `ajax_bulk_import_users` (~4166), `ajax_ap_change_password` (~4479), `ajax_ap_send_reset` (~4498); add private methods next to `ajax_bulk_import_users`.

**Interfaces:**
- Consumes: Task 1 `Anchor_FM_User_Admin::*`, Task 2 row `password` key.
- Produces (used by Task 4):
  - `private function create_portal_user(array $row, $role, $default_password, $send_email, array &$batch_usernames, array &$seen_emails)` → `['status'=>'created'|'skipped'|'error', 'message'=>string, 'username'=>string, 'email'=>string, 'user_id'=>int]`. `$row` keys: `username, first_name, last_name, email, password`. Caller has already validated `$role` and `$default_password`.
  - `private function send_password_reset_email(WP_User $user)` → `true|WP_Error`.

- [ ] **Step 1: Add `create_portal_user`** directly above `ajax_bulk_import_users`. This is the existing loop body moved, plus password resolution:

```php
    /**
     * One user through the same pipeline whether they came from a CSV row or
     * the "Add person" form: sanitize, validate, de-duplicate, derive a unique
     * username, resolve the password, insert, optionally notify.
     */
    private function create_portal_user(array $row, $role, $default_password, $send_email, array &$batch_usernames, array &$seen_emails) {
        $row = array_merge(['username' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'password' => ''], $row);
        $row['first_name'] = sanitize_text_field($row['first_name']);
        $row['last_name']  = sanitize_text_field($row['last_name']);
        $row['email']      = Anchor_FM_User_Import::normalize_email($row['email']);
        $row['username']   = Anchor_FM_User_Import::sanitize_username($row['username']);
        $out = ['status' => 'error', 'message' => '', 'username' => $row['username'], 'email' => $row['email'], 'user_id' => 0];

        $v = Anchor_FM_User_Import::validate($row);
        if (!$v['ok']) { $out['message'] = $v['error']; return $out; }

        $pw = Anchor_FM_User_Admin::resolve_password($row['password'], $default_password);
        if ($pw['source'] === 'row') {
            $pv = Anchor_FM_User_Admin::validate_password($pw['password']);
            if (!$pv['ok']) { $out['message'] = $pv['error']; return $out; }
        }
        $password = $pw['source'] === 'generate' ? wp_generate_password(16, true, false) : $pw['password'];

        if (isset($seen_emails[$row['email']]) || email_exists($row['email'])) {
            $out['status'] = 'skipped';
            $out['message'] = 'Email already exists';
            return $out;
        }

        $base = $row['username'] !== '' ? $row['username'] : Anchor_FM_User_Import::derive_username($row['first_name'], $row['last_name']);
        $username = Anchor_FM_User_Import::make_unique($base, function ($name) use ($batch_usernames) {
            return isset($batch_usernames[$name]) || username_exists($name);
        });
        $out['username'] = $username;

        $user_id = wp_insert_user([
            'user_login'   => $username,
            'user_email'   => $row['email'],
            'user_pass'    => $password,
            'first_name'   => $row['first_name'],
            'last_name'    => $row['last_name'],
            'display_name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'role'         => $role,
        ]);
        if (is_wp_error($user_id)) { $out['message'] = $user_id->get_error_message(); return $out; }

        $batch_usernames[$username] = true;
        $seen_emails[$row['email']] = true;
        if ($send_email) {
            wp_new_user_notification($user_id, null, 'user');
        }
        $out['status'] = 'created';
        $out['user_id'] = (int) $user_id;
        return $out;
    }
```

- [ ] **Step 2: Rewrite `ajax_bulk_import_users`** to use it. Keep the role/CSV checks as they are; replace the role check with `Anchor_FM_User_Admin::valid_role`, add the default-password check before parsing, and replace the whole `foreach ($rows as $row) { ... }` loop:

```php
        $role = isset($_POST['role']) ? sanitize_key((string) $_POST['role']) : '';
        if (!Anchor_FM_User_Admin::valid_role($role, array_column($this->get_editable_roles_for_permissions(), 'key'))) {
            $this->json_error('Please choose a valid role.');
        }
        $send_email = !empty($_POST['send_email']) && $_POST['send_email'] !== '0';

        // A bad batch default would fail every row the same way; refuse up front.
        $default_password = isset($_POST['default_password']) ? trim((string) wp_unslash($_POST['default_password'])) : '';
        if ($default_password !== '') {
            $dv = Anchor_FM_User_Admin::validate_password($default_password);
            if (!$dv['ok']) $this->json_error($dv['error']);
        }
```

```php
        foreach ($rows as $row) {
            $r = $this->create_portal_user($row, $role, $default_password, $send_email, $batch_usernames, $seen_emails);
            if ($r['status'] === 'created') $created++;
            elseif ($r['status'] === 'skipped') $skipped++;
            else $errors++;
            $report[] = ['line' => (int) $row['line'], 'username' => $r['username'], 'email' => $r['email'], 'status' => $r['status'], 'message' => $r['message']];
        }
```

Add `'default_password' => $default_password !== ''` (a boolean, never the value) to the existing `log_activity(... 'bulk_import' ...)` meta.

- [ ] **Step 3: Shared password rule in `ajax_ap_change_password`.** Replace the `strlen($new) < 10` block with:

```php
        $pv = Anchor_FM_User_Admin::validate_password($new);
        if (!$pv['ok']) {
            $this->json_error($pv['error'], 400);
        }
```

- [ ] **Step 4: Extract `send_password_reset_email`.** Add above `ajax_ap_send_reset`:

```php
    /** The password-reset email the Security tab and the Users manager both send. */
    private function send_password_reset_email(WP_User $user) {
        if (empty($user->user_email)) return new WP_Error('no_email', 'No email on account');
        $key = get_password_reset_key($user);
        if (is_wp_error($key)) return $key;

        $reset_url = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login), 'login');
        $subject = sprintf('[%s] Password reset', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = "A password reset was requested for your account.\n\n";
        $message .= "Reset your password:\n{$reset_url}\n\n";
        $message .= "If you didn’t request this, you can ignore this email.\n";

        return wp_mail($user->user_email, $subject, $message) ? true : new WP_Error('mail_failed', 'Could not send the email right now.');
    }
```

and make `ajax_ap_send_reset`:

```php
    public function ajax_ap_send_reset() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user = wp_get_current_user();
        $sent = $this->send_password_reset_email($user);
        if (is_wp_error($sent)) $this->json_error($sent->get_error_message(), 400);
        $this->log_activity((int) $user->ID, 'send_password_reset', 'user', (int) $user->ID, []);
        $this->json_success(['sent' => true]);
    }
```

(Behavior change, intentional: a failed `wp_mail` now reports an error instead of claiming success.)

- [ ] **Step 5: Verify**

Run: `php -l anchor-private-file-manager.php && php tests/run.php | tail -1 && grep -n "password" anchor-private-file-manager.php | grep -i "log_activity"`
Expected: no syntax errors, `ALL PASS`, and the grep shows no line passing a password *value* into `log_activity`.

- [ ] **Step 6: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: explicit passwords in CSV import via a shared user-creation pipeline"
```

---

### Task 4: User-management endpoints and delete cleanup

**Files:**
- Modify: `anchor-private-file-manager.php` — constructor hooks (~line 93), new methods after `ajax_bulk_import_users`.

**Interfaces:**
- Consumes: Task 3 `create_portal_user`, `send_password_reset_email`; Task 1 rules.
- Produces AJAX actions (Task 6 calls these; all POST, `nonce` = `AnchorFM.nonce`):
  - `anchor_fm_users_list` `{search, role, page}` → `{users:[UserShape], total:int, page:int, pages:int}`
  - `anchor_fm_user_create` `{first_name, last_name, email, username, role, password, send_email:'1'|'0'}` → `{user: UserShape}`
  - `anchor_fm_user_set_role` `{user_id, role}` → `{user: UserShape}`
  - `anchor_fm_user_set_password` `{user_id, password}` → `{saved: true}`
  - `anchor_fm_user_send_reset` `{user_id}` → `{sent: true}`
  - `anchor_fm_user_delete` `{user_id}` → `{deleted: true}`
  - `UserShape` = `{id:int, displayName, email, username, roles:string[], registered:'Y-m-d H:i:s', lastWatched:string|null, manageable:bool}`

- [ ] **Step 1: Register hooks** in the constructor after the `anchor_fm_bulk_import_users` line:

```php
        add_action('wp_ajax_anchor_fm_users_list', [$this, 'ajax_users_list']);
        add_action('wp_ajax_anchor_fm_user_create', [$this, 'ajax_user_create']);
        add_action('wp_ajax_anchor_fm_user_set_role', [$this, 'ajax_user_set_role']);
        add_action('wp_ajax_anchor_fm_user_set_password', [$this, 'ajax_user_set_password']);
        add_action('wp_ajax_anchor_fm_user_send_reset', [$this, 'ajax_user_send_reset']);
        add_action('wp_ajax_anchor_fm_user_delete', [$this, 'ajax_user_delete']);
        add_action('deleted_user', [$this, 'on_deleted_user']);
```

- [ ] **Step 2: Add helpers** after `ajax_bulk_import_users`:

```php
    private function require_admin_ajax() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        if (!current_user_can('administrator')) $this->json_error('Forbidden', 403);
    }

    private function assignable_role_keys() {
        return array_column($this->get_editable_roles_for_permissions(), 'key');
    }

    private function user_is_manageable(WP_User $u) {
        return Anchor_FM_User_Admin::is_manageable((array) $u->roles, (int) $u->ID, get_current_user_id())
            && !user_can($u, 'administrator');
    }

    /** The posted user_id as a WP_User the current admin may act on, or a 403/404. */
    private function manageable_target() {
        $id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $u = $id > 0 ? get_user_by('id', $id) : false;
        if (!$u) $this->json_error('User not found', 404);
        if (!$this->user_is_manageable($u)) $this->json_error('This account cannot be changed here.', 403);
        return $u;
    }

    /** user_id => latest last_viewed_at, in one query for a page of users. */
    private function last_watched_map(array $user_ids) {
        $user_ids = array_values(array_filter(array_map('intval', $user_ids)));
        if (!$user_ids) return [];
        global $wpdb;
        $views = self::table('video_views');
        $in = implode(',', $user_ids); // ints only, cast above
        $rows = $wpdb->get_results("SELECT user_id, MAX(last_viewed_at) AS last FROM {$views} WHERE user_id IN ({$in}) GROUP BY user_id");
        $out = [];
        foreach ((array) $rows as $r) $out[(int) $r->user_id] = $r->last;
        return $out;
    }

    private function shape_user(WP_User $u, array $last_watched) {
        return [
            'id' => (int) $u->ID,
            'displayName' => $u->display_name,
            'email' => $u->user_email,
            'username' => $u->user_login,
            'roles' => array_values((array) $u->roles),
            'registered' => $u->user_registered,
            'lastWatched' => isset($last_watched[(int) $u->ID]) ? $last_watched[(int) $u->ID] : null,
            'manageable' => $this->user_is_manageable($u),
        ];
    }
```

- [ ] **Step 3: Add the endpoints**:

```php
    public function ajax_users_list() {
        $this->require_admin_ajax();
        $search = isset($_POST['search']) ? trim(sanitize_text_field((string) $_POST['search'])) : '';
        $role = isset($_POST['role']) ? sanitize_key((string) $_POST['role']) : '';
        $page = max(1, isset($_POST['page']) ? (int) $_POST['page'] : 1);
        $per_page = 25;

        $args = [
            'number' => $per_page,
            'paged' => $page,
            'count_total' => true,
            'orderby' => 'registered',
            'order' => 'DESC',
        ];
        if ($search !== '') {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }
        if ($role !== '' && in_array($role, $this->assignable_role_keys(), true)) {
            $args['role'] = $role;
        }

        $q = new WP_User_Query($args);
        $users = (array) $q->get_results();
        $last = $this->last_watched_map(array_map(function ($u) { return $u->ID; }, $users));
        $total = (int) $q->get_total();

        $this->json_success([
            'users' => array_map(function ($u) use ($last) { return $this->shape_user($u, $last); }, $users),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $per_page)),
        ]);
    }

    public function ajax_user_create() {
        $this->require_admin_ajax();
        $role = isset($_POST['role']) ? sanitize_key((string) $_POST['role']) : '';
        if (!Anchor_FM_User_Admin::valid_role($role, $this->assignable_role_keys())) {
            $this->json_error('Please choose a valid role.');
        }
        $row = [
            'username' => isset($_POST['username']) ? (string) wp_unslash($_POST['username']) : '',
            'first_name' => isset($_POST['first_name']) ? (string) wp_unslash($_POST['first_name']) : '',
            'last_name' => isset($_POST['last_name']) ? (string) wp_unslash($_POST['last_name']) : '',
            'email' => isset($_POST['email']) ? (string) wp_unslash($_POST['email']) : '',
            'password' => isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '',
        ];
        $send_email = !empty($_POST['send_email']) && $_POST['send_email'] !== '0';
        $batch = []; $seen = [];
        $r = $this->create_portal_user($row, $role, '', $send_email, $batch, $seen);
        if ($r['status'] !== 'created') {
            $this->json_error($r['message'] ?: 'Could not create the user.');
        }
        $this->log_activity(get_current_user_id(), 'user_create', 'user', $r['user_id'], [
            'role' => $role, 'emailed' => $send_email, 'explicit_password' => trim($row['password']) !== '',
        ]);
        $u = get_user_by('id', $r['user_id']);
        $this->json_success(['user' => $this->shape_user($u, [])]);
    }

    public function ajax_user_set_role() {
        $this->require_admin_ajax();
        $u = $this->manageable_target();
        $role = isset($_POST['role']) ? sanitize_key((string) $_POST['role']) : '';
        if (!Anchor_FM_User_Admin::valid_role($role, $this->assignable_role_keys())) {
            $this->json_error('Please choose a valid role.');
        }
        $u->set_role($role);
        $this->log_activity(get_current_user_id(), 'user_set_role', 'user', (int) $u->ID, ['role' => $role]);
        $fresh = get_user_by('id', (int) $u->ID);
        $this->json_success(['user' => $this->shape_user($fresh, $this->last_watched_map([(int) $u->ID]))]);
    }

    public function ajax_user_set_password() {
        $this->require_admin_ajax();
        $u = $this->manageable_target();
        $pw = isset($_POST['password']) ? trim((string) wp_unslash($_POST['password'])) : '';
        $pv = Anchor_FM_User_Admin::validate_password($pw);
        if (!$pv['ok']) $this->json_error($pv['error']);
        wp_set_password($pw, (int) $u->ID);
        $this->log_activity(get_current_user_id(), 'user_set_password', 'user', (int) $u->ID, []);
        $this->json_success(['saved' => true]);
    }

    public function ajax_user_send_reset() {
        $this->require_admin_ajax();
        $u = $this->manageable_target();
        $sent = $this->send_password_reset_email($u);
        if (is_wp_error($sent)) $this->json_error($sent->get_error_message(), 400);
        $this->log_activity(get_current_user_id(), 'user_send_reset', 'user', (int) $u->ID, []);
        $this->json_success(['sent' => true]);
    }

    public function ajax_user_delete() {
        $this->require_admin_ajax();
        $u = $this->manageable_target();
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $id = (int) $u->ID;
        $email = $u->user_email;
        if (!wp_delete_user($id, get_current_user_id())) $this->json_error('Could not remove the user.', 500);
        $this->log_activity(get_current_user_id(), 'user_delete', 'user', $id, ['email' => $email]);
        $this->json_success(['deleted' => true]);
    }

    /**
     * Fires for deletions from here and from wp-admin. User conditions inside
     * permission_policies JSON are left alone: they match nobody once the user
     * is gone, and MySQL does not reuse user IDs.
     */
    public function on_deleted_user($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) return;
        $wpdb->delete(self::table('permissions'), ['subject_type' => 'user', 'subject_key' => (string) $user_id], ['%s', '%s']);
        $wpdb->delete(self::table('video_views'), ['user_id' => $user_id], ['%d']);
    }
```

- [ ] **Step 4: Verify**

Run: `php -l anchor-private-file-manager.php && php tests/run.php | tail -1 && grep -c "require_admin_ajax();" anchor-private-file-manager.php`
Expected: no syntax errors, `ALL PASS`, count `6`.

Also confirm the `video_views` column is named `user_id`: `grep -n "user_id BIGINT" anchor-private-file-manager.php` must show it inside the `video_views` CREATE TABLE. If it is named differently, use that name in `last_watched_map` and `on_deleted_user`.

- [ ] **Step 5: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: list, add, re-role, reset and remove portal users from the front end"
```

---

### Task 5: Configurable branding with International preserved

**Files:**
- Modify: `anchor-private-file-manager.php` — constants (~line 45-47), `get_request_access_email` (~184), `register_settings` (~199), `render_settings_page` (~222), `maybe_upgrade_db` (~648), markup at ~806.

**Interfaces:**
- Produces: option `anchor_fm_portal_logo` (`const OPT_PORTAL_LOGO`), method `private function portal_logo_url(): string`.

- [ ] **Step 1: Constants.** Replace `const DEFAULT_REQUEST_ACCESS_EMAIL = 'tiffany@tmjtherapycentre.com';` with:

```php
    const OPT_PORTAL_LOGO = 'anchor_fm_portal_logo';
    /**
     * International ran on this hardcoded default before it became a setting.
     * Only the 2.15.0 upgrade uses it, to pin that behavior on existing installs.
     */
    const LEGACY_REQUEST_ACCESS_EMAIL = 'tiffany@tmjtherapycentre.com';
```

- [ ] **Step 2: Request-access default → admin email.**

```php
    private function get_request_access_email() {
        $admin = (string) get_option('admin_email');
        $email = sanitize_email((string) get_option(self::OPT_REQUEST_ACCESS_EMAIL, $admin));
        return $email ?: $admin;
    }
```

In `register_settings`, the request-access sanitize callback and default become:

```php
            'sanitize_callback' => function ($v) {
                $v = sanitize_email((string) $v);
                return $v ?: (string) get_option('admin_email');
            },
            'default' => (string) get_option('admin_email'),
```

and register the logo:

```php
        register_setting('anchor_private_file_manager', self::OPT_PORTAL_LOGO, [
            'type' => 'string',
            'sanitize_callback' => function ($v) { return esc_url_raw(trim((string) $v)); },
            'default' => '',
        ]);
```

In `render_settings_page`, change the request-access input's value to `get_option(self::OPT_REQUEST_ACCESS_EMAIL, get_option('admin_email'))`, and add a row before it:

```php
                    <tr>
                        <th scope="row">Portal logo URL</th>
                        <td>
                            <input type="url" class="regular-text" name="<?php echo esc_attr(self::OPT_PORTAL_LOGO); ?>" value="<?php echo esc_attr(get_option(self::OPT_PORTAL_LOGO, '')); ?>">
                            <p class="description">Shown in the portal sidebar. Leave blank to use the site icon (Settings → General); if there is no site icon, no logo is shown.</p>
                        </td>
                    </tr>
```

- [ ] **Step 3: `portal_logo_url()`** next to `get_request_access_email`:

```php
    private function portal_logo_url() {
        $url = (string) get_option(self::OPT_PORTAL_LOGO, '');
        if ($url !== '') return $url;
        return (string) get_site_icon_url(96);
    }
```

Replace the hardcoded `<img class="afm__brandMark" src="https://tmjtherapycentre.com/..." ...></img>` with:

```php
                        <?php $logo = $this->portal_logo_url(); if ($logo !== '') : ?>
                        <img class="afm__brandMark" src="<?php echo esc_url($logo); ?>" alt="" aria-hidden="true">
                        <?php endif; ?>
```

- [ ] **Step 4: Migration.** In `maybe_upgrade_db`, next to `$pre_coverage`:

```php
            // International relied on the old hardcoded recipient and favicon;
            // pin them on that host only (other sites may also have run 2.14).
            // Fresh installs never reach this: activate() stamps the version
            // only after schema work succeeds.
            if ($installed !== '0' && version_compare($installed, '2.15.0', '<')
                && self::is_legacy_international_host()) {
                if (get_option(self::OPT_REQUEST_ACCESS_EMAIL, null) === null) {
                    update_option(self::OPT_REQUEST_ACCESS_EMAIL, self::LEGACY_REQUEST_ACCESS_EMAIL);
                }
                if (get_option(self::OPT_PORTAL_LOGO, null) === null && get_site_icon_url(96) === '') {
                    update_option(self::OPT_PORTAL_LOGO, self::LEGACY_PORTAL_LOGO);
                }
            }
```

- [ ] **Step 5: Verify**

Run: `php -l anchor-private-file-manager.php && grep -n "tmjtherapycentre" anchor-private-file-manager.php`
Expected: no syntax errors; the only hits are `LEGACY_REQUEST_ACCESS_EMAIL` and the pre-existing storage comment near line 3870.

- [ ] **Step 6: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: portal logo setting and admin-email access default, keeping International's recipient"
```

---

### Task 6: Users panel front end

**Files:**
- Modify: `assets/js/file-manager.js` (modal helpers ~505-536, `handleModalPrimary` ~1538, bulk-import block ~2082-2155, end of the ready callback)
- Modify: `assets/js/account-documents.js` (`switchTab` ~111)
- Create: `assets/js/user-manager.js`
- Modify: `anchor-private-file-manager.php` (users panel markup ~935-965, nav label ~822, enqueue in `do_enqueue_assets`, `i18n.addUsers`)
- Modify: `assets/css/file-manager.css` (append)
- Test: `tests/client-expand.js`

**Interfaces:**
- Consumes: Task 4 AJAX actions and `UserShape`; existing `AnchorFM.roles` (`[{key,label}]`), `AnchorFM.defaultRole`, `AnchorFM.isAdmin`.
- Produces: `window.AnchorFMUI = { api(action, data) → jqXHR, esc(s) → string, toast(msg), errMessage(jqXHR, res, fallback) → string, modal: { open(title, bodyHtml, primaryLabel, onPrimary($body)), close(), busy(bool, label?), body() → jQuery } }`; root event `anchorfm:showUsers`.

- [ ] **Step 1: External modal handler in `file-manager.js`.** Add `let externalModalHandler = null;` next to `const state = {`. In `closeModal()` add `externalModalHandler = null;` after `state.modalPayload = null;`. At the top of `handleModalPrimary()`:

```js
        if (state.modalMode === 'external') {
            if (typeof externalModalHandler === 'function') externalModalHandler($modalBody);
            return;
        }
```

At the end of the ready callback (just before the final `});`), expose:

```js
    // Shared UI for sibling scripts (user-manager.js) so they reuse this
    // modal, request helper and toast instead of carrying their own.
    window.AnchorFMUI = {
        api, esc, toast, errMessage,
        modal: {
            open(title, bodyHtml, primaryLabel, onPrimary) {
                closeModal();
                $modalTitle.text(title);
                $modalBody.html(bodyHtml);
                setModalPrimary(primaryLabel || 'Save', 'external', null);
                externalModalHandler = onPrimary;
                openModal();
            },
            close: closeModal,
            busy: setModalBusy,
            body: () => $modalBody,
        },
    };
```

- [ ] **Step 2: Test the handler reset in `tests/client-expand.js`.** Read the existing harness to find how it obtains `$root` handlers and triggers clicks; add a check using that same mechanism:

```js
// Review focus 5: a closed external modal must not fire on the next save.
{
    const ui = sandbox.window.AnchorFMUI;
    check('AnchorFMUI exposed', typeof ui === 'object' && typeof ui.modal.open, 'function');
    let fired = 0;
    ui.modal.open('T', '<div></div>', 'Go', () => { fired++; });
    ui.modal.close();
    // Trigger the modal-primary click through the harness's own click helper here.
    check('closed external handler does not fire', fired, 0);
}
```

Replace the comment line with the harness's actual click-dispatch call for `[data-afm-action="modal-primary"]`. If the stubbed jQuery cannot dispatch delegated clicks, keep only the `AnchorFMUI exposed` check and say so in the task report.

Run: `node tests/client-expand.js | tail -3` — Expected: `ALL PASS`.

- [ ] **Step 3: Move the bulk-import JS.** Delete from `file-manager.js` the block starting `// --- Bulk user import (admin) ---` through the end of the `[data-afm-action="bulk-import-users"]` click handler, and any call to `populateImportRoles()` (`grep -n populateImportRoles assets/js/file-manager.js` — remove each call). Its behavior is re-implemented in Step 5.

- [ ] **Step 4: Markup.** In `render_documents_portal`, rename the nav label `Add Users` → `Users`, and replace the whole `data-apfm-panel="users"` div content with:

```php
                        <div class="afm__panel aap__panel" data-apfm-panel="users" data-afm-panel="users">
                            <div class="afm__users" data-afm-users>
                                <div class="afm__usersBar">
                                    <label class="afm__search afm__usersSearch">
                                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                        <input type="search" placeholder="<?php esc_attr_e('Search name, email or username…', 'anchor-private-file-manager'); ?>" data-afm-users-search>
                                    </label>
                                    <select class="afm__select afm__usersRole" data-afm-users-role></select>
                                    <button type="button" class="afm__btn afm__btn--secondary" data-afm-action="users-import">
                                        <span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
                                        <?php esc_html_e('Import CSV', 'anchor-private-file-manager'); ?>
                                    </button>
                                    <button type="button" class="afm__btn afm__btn--primary" data-afm-action="users-add">
                                        <span class="dashicons dashicons-plus" aria-hidden="true"></span>
                                        <?php esc_html_e('Add person', 'anchor-private-file-manager'); ?>
                                    </button>
                                </div>
                                <div class="afm__usersTable" data-afm-users-table></div>
                                <div class="afm__usersPager" data-afm-users-pager></div>
                            </div>
                        </div>
```

In `do_enqueue_assets`, after the `anchor-documents-portal` enqueue:

```php
        if (current_user_can('administrator')) {
            $um_path = plugin_dir_path(__FILE__) . 'assets/js/user-manager.js';
            wp_enqueue_script(
                'anchor-fm-user-manager',
                plugin_dir_url(__FILE__) . 'assets/js/user-manager.js',
                ['jquery', 'anchor-file-manager'],
                file_exists($um_path) ? (string) filemtime($um_path) : self::VERSION,
                true
            );
        }
```

Change `'addUsers' => __('Add Users', ...)` to `'users' => __('Users', ...)`; `grep -n "addUsers" assets/js/*.js` and update any reader to `users`.

- [ ] **Step 5: `account-documents.js`.** In `switchTab`, after the `product-docs` trigger:

```js
        if (tab === 'users') {
            $root.trigger('anchorfm:showUsers');
        }
```

- [ ] **Step 6: Create `assets/js/user-manager.js`:**

```js
/*
 * Users panel (administrators only): list, search, filter and page portal
 * users; add one person; import a CSV; change role, set password, email a
 * reset link, remove. Reuses file-manager.js's modal and helpers through
 * window.AnchorFMUI rather than carrying copies.
 */
jQuery(function ($) {
    const $root = $('[data-afm]');
    const $panel = $root.find('[data-afm-users]');
    const UI = window.AnchorFMUI;
    if (!$panel.length || !UI || !window.AnchorFM || !AnchorFM.isAdmin) return;

    const { api, esc, toast, errMessage, modal } = UI;
    const roles = Array.isArray(AnchorFM.roles) ? AnchorFM.roles : [];
    const roleLabel = key => (roles.find(r => r.key === key) || {}).label || key;
    const st = { search: '', role: '', page: 1, pages: 1, loaded: false, users: [] };
    let searchTimer = null;

    function roleOptions(selected) {
        return roles.map(r => `<option value="${esc(r.key)}"${r.key === selected ? ' selected' : ''}>${esc(r.label)}</option>`).join('');
    }

    function genPassword() {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        const buf = new Uint32Array(16);
        window.crypto.getRandomValues(buf);
        return Array.from(buf, n => chars[n % chars.length]).join('');
    }

    function passwordField(label, help) {
        return `<div class="afm__formRow">
            <label class="afm__label">${esc(label)}</label>
            <div class="afm__pwRow">
                <input type="text" class="afm__input" data-um-password autocomplete="new-password">
                <button type="button" class="afm__btn afm__btn--ghost" data-um-generate>Generate</button>
            </div>
            <div class="afm__help">${esc(help)}</div>
        </div>`;
    }

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d) ? '—' : d.toLocaleDateString();
    }

    function modalError($body, msg) {
        let $n = $body.find('[data-um-notice]');
        if (!$n.length) $n = $('<div class="afm__notice afm__notice--error" data-um-notice></div>').appendTo($body);
        $n.text(msg).prop('hidden', false);
    }

    function load() {
        $panel.find('[data-afm-users-table]').html('<div class="afm__skeleton"></div>');
        api('anchor_fm_users_list', { search: st.search, role: st.role, page: st.page })
            .done(res => {
                if (!res || !res.success) { renderError(errMessage(null, res, 'Could not load users.')); return; }
                st.users = res.data.users || [];
                st.pages = res.data.pages || 1;
                st.page = res.data.page || 1;
                renderTable(res.data.total || 0);
            })
            .fail(xhr => renderError(errMessage(xhr, null, 'Could not load users.')));
    }

    function renderError(msg) {
        $panel.find('[data-afm-users-table]').html(`<div class="afm__empty">${esc(msg)}</div>`);
        $panel.find('[data-afm-users-pager]').empty();
    }

    function renderTable(total) {
        if (!st.users.length) {
            renderError(st.search || st.role ? 'No users match.' : 'No users yet.');
            return;
        }
        const rows = st.users.map(u => `
            <tr data-um-id="${u.id}">
                <td data-label="Name"><strong>${esc(u.displayName || u.username)}</strong><div class="afm__muted">${esc(u.username)}</div></td>
                <td data-label="Email">${esc(u.email)}</td>
                <td data-label="Role">${esc((u.roles || []).map(roleLabel).join(', ') || '—')}</td>
                <td data-label="Added">${esc(fmtDate(u.registered))}</td>
                <td data-label="Last watched">${esc(u.lastWatched ? fmtDate(u.lastWatched) : 'Never')}</td>
                <td class="afm__usersActions">${u.manageable ? `
                    <button type="button" class="afm__linkBtn" data-um-act="role">Role</button>
                    <button type="button" class="afm__linkBtn" data-um-act="password">Password</button>
                    <button type="button" class="afm__linkBtn" data-um-act="reset">Email reset</button>
                    <button type="button" class="afm__linkBtn afm__linkBtn--danger" data-um-act="remove">Remove</button>`
                    : '<span class="afm__muted">—</span>'}</td>
            </tr>`).join('');
        $panel.find('[data-afm-users-table]').html(`
            <table class="afm__table">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Added</th><th>Last watched</th><th></th></tr></thead>
                <tbody>${rows}</tbody>
            </table>`);
        $panel.find('[data-afm-users-pager]').html(`
            <button type="button" class="afm__btn afm__btn--ghost" data-um-page="-1" ${st.page <= 1 ? 'disabled' : ''}>Prev</button>
            <span>Page ${st.page} of ${st.pages} · ${total} user${total === 1 ? '' : 's'}</span>
            <button type="button" class="afm__btn afm__btn--ghost" data-um-page="1" ${st.page >= st.pages ? 'disabled' : ''}>Next</button>`);
    }

    function userById(id) { return st.users.find(u => u.id === id); }

    // --- Add person ---
    function openAdd() {
        modal.open('Add person', `
            <div class="afm__formRow"><label class="afm__label">First name</label><input type="text" class="afm__input" data-um-first></div>
            <div class="afm__formRow"><label class="afm__label">Last name</label><input type="text" class="afm__input" data-um-last></div>
            <div class="afm__formRow"><label class="afm__label">Email</label><input type="email" class="afm__input" data-um-email></div>
            <div class="afm__formRow"><label class="afm__label">Username (optional)</label><input type="text" class="afm__input" data-um-username placeholder="e.g. j.smith"></div>
            <div class="afm__formRow"><label class="afm__label">Role</label><select class="afm__select" data-um-role>${roleOptions(AnchorFM.defaultRole || '')}</select></div>
            ${passwordField('Password (optional)', 'At least 10 characters. Leave blank to generate one they never see.')}
            <label class="afm__check"><input type="checkbox" data-um-send checked> Email a welcome / set-password link</label>
        `, 'Add person', $b => {
            modal.busy(true, 'Adding…');
            api('anchor_fm_user_create', {
                first_name: $b.find('[data-um-first]').val(),
                last_name: $b.find('[data-um-last]').val(),
                email: $b.find('[data-um-email]').val(),
                username: $b.find('[data-um-username]').val(),
                role: $b.find('[data-um-role]').val(),
                password: $b.find('[data-um-password]').val(),
                send_email: $b.find('[data-um-send]').is(':checked') ? '1' : '0',
            }).done(res => {
                if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not add this person.')); return; }
                modal.close();
                toast(`Added ${res.data.user.displayName || res.data.user.email}`);
                st.page = 1; load();
            }).fail(xhr => modalError($b, errMessage(xhr, null, 'Could not add this person.')))
              .always(() => modal.busy(false, 'Add person'));
        });
    }

    // --- Import CSV ---
    function openImport() {
        modal.open('Import users from CSV', `
            <p class="afm__importHint">Columns in this order: username, first name, last name, email, password. A header row is optional; username and password are optional.</p>
            <div class="afm__formRow"><label class="afm__label">CSV file</label><input type="file" accept=".csv,text/csv,text/plain" data-um-file></div>
            <div class="afm__formRow"><label class="afm__label">Role</label><select class="afm__select" data-um-role>${roleOptions(AnchorFM.defaultRole || '')}</select></div>
            ${passwordField('Password for everyone without one (optional)', 'Rows with their own password keep it. If both are blank, a password is generated.')}
            <label class="afm__check"><input type="checkbox" data-um-send checked> Email new users a link to set their password</label>
            <div class="afm__importResults" data-um-results hidden></div>
        `, 'Import', $b => {
            const file = ($b.find('[data-um-file]')[0] || {}).files;
            if (!file || !file[0]) { modalError($b, 'Please choose a CSV file first.'); return; }
            const data = new FormData();
            data.append('action', 'anchor_fm_bulk_import_users');
            data.append('nonce', AnchorFM.nonce);
            data.append('role', $b.find('[data-um-role]').val() || '');
            data.append('default_password', $b.find('[data-um-password]').val() || '');
            data.append('send_email', $b.find('[data-um-send]').is(':checked') ? '1' : '0');
            data.append('csv', file[0], file[0].name);
            modal.busy(true, 'Importing…');
            $.ajax({ url: AnchorFM.ajax, method: 'POST', data, processData: false, contentType: false })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Import failed.')); return; }
                    renderImportResults($b, res.data);
                    load();
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Import failed.')))
                .always(() => modal.busy(false, 'Import'));
        });
    }

    function renderImportResults($b, res) {
        const rows = Array.isArray(res.rows) ? res.rows : [];
        const body = rows.map(r => `<tr class="afm__importRow afm__importRow--${esc(r.status)}"><td>${esc(String(r.line || ''))}</td><td>${esc(r.username || '')}</td><td>${esc(r.email || '')}</td><td>${esc(r.status || '')}</td><td>${esc(r.message || '')}</td></tr>`).join('');
        $b.find('[data-um-notice]').prop('hidden', true);
        $b.find('[data-um-results]').html(
            `<div class="afm__importSummary">${esc(`${res.created || 0} created, ${res.skipped || 0} skipped, ${res.errors || 0} error(s)`)}</div>`
            + `<table class="afm__importTable"><thead><tr><th>#</th><th>Username</th><th>Email</th><th>Status</th><th>Message</th></tr></thead><tbody>${body}</tbody></table>`
        ).prop('hidden', false);
    }

    // --- Row actions ---
    function openRole(u) {
        modal.open(`Change role — ${u.displayName}`, `
            <div class="afm__formRow"><label class="afm__label">Role</label><select class="afm__select" data-um-role>${roleOptions((u.roles || [])[0] || '')}</select></div>
            <div class="afm__help">Folder access follows role, so this changes what they can see.</div>
        `, 'Save', $b => {
            modal.busy(true, 'Saving…');
            api('anchor_fm_user_set_role', { user_id: u.id, role: $b.find('[data-um-role]').val() })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not change the role.')); return; }
                    modal.close(); toast('Role updated'); load();
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Could not change the role.')))
                .always(() => modal.busy(false, 'Save'));
        });
    }

    function openPassword(u) {
        modal.open(`Set password — ${u.displayName}`, passwordField('New password', 'At least 10 characters. Share it with them yourself; it is not emailed.'), 'Set password', $b => {
            modal.busy(true, 'Saving…');
            api('anchor_fm_user_set_password', { user_id: u.id, password: $b.find('[data-um-password]').val() })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not set the password.')); return; }
                    modal.close(); toast('Password set');
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Could not set the password.')))
                .always(() => modal.busy(false, 'Set password'));
        });
    }

    function sendReset(u) {
        api('anchor_fm_user_send_reset', { user_id: u.id })
            .done(res => toast(res && res.success ? `Reset link sent to ${u.email}` : errMessage(null, res, 'Could not send the email.')))
            .fail(xhr => toast(errMessage(xhr, null, 'Could not send the email.')));
    }

    function openRemove(u) {
        modal.open('Remove user', `<div class="afm__help">Remove <strong>${esc(u.displayName)}</strong> (${esc(u.email)})? Their account, folder access and watch history are deleted. This cannot be undone.</div>`, 'Remove', $b => {
            modal.busy(true, 'Removing…');
            api('anchor_fm_user_delete', { user_id: u.id })
                .done(res => {
                    if (!res || !res.success) { modalError($b, errMessage(null, res, 'Could not remove the user.')); return; }
                    modal.close(); toast('User removed');
                    if (st.users.length === 1 && st.page > 1) st.page--;
                    load();
                })
                .fail(xhr => modalError($b, errMessage(xhr, null, 'Could not remove the user.')))
                .always(() => modal.busy(false, 'Remove'));
        });
    }

    // --- Wiring ---
    $panel.find('[data-afm-users-role]').html('<option value="">All roles</option>' + roleOptions(''));

    $root.on('anchorfm:showUsers', () => { if (!st.loaded) { st.loaded = true; load(); } });
    $panel.on('input', '[data-afm-users-search]', function () {
        clearTimeout(searchTimer);
        const v = String($(this).val() || '').trim();
        searchTimer = setTimeout(() => { st.search = v; st.page = 1; load(); }, 300);
    });
    $panel.on('change', '[data-afm-users-role]', function () { st.role = String($(this).val() || ''); st.page = 1; load(); });
    $panel.on('click', '[data-um-page]', function () {
        st.page = Math.min(st.pages, Math.max(1, st.page + Number($(this).data('um-page'))));
        load();
    });
    $panel.on('click', '[data-afm-action="users-add"]', openAdd);
    $panel.on('click', '[data-afm-action="users-import"]', openImport);
    $panel.on('click', '[data-um-act]', function () {
        const u = userById(Number($(this).closest('[data-um-id]').data('um-id')));
        if (!u) return;
        const act = String($(this).data('um-act'));
        if (act === 'role') openRole(u);
        else if (act === 'password') openPassword(u);
        else if (act === 'reset') sendReset(u);
        else if (act === 'remove') openRemove(u);
    });
    // Generate buttons live inside the shared modal, outside $panel.
    $root.on('click', '[data-um-generate]', function () {
        $(this).closest('.afm__pwRow').find('[data-um-password]').val(genPassword()).trigger('select');
    });
});
```

Before relying on them, check these class names exist in `file-manager.css`: `afm__linkBtn`, `afm__muted`, `afm__notice--error`, `afm__skeleton`, `afm__check`, `afm__table`. For any that don't, add a minimal rule in Step 7 rather than inventing a parallel name.

- [ ] **Step 7: CSS** — append to `assets/css/file-manager.css`:

```css
/* Users panel */
.afm__usersBar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: var(--afm-gap); }
.afm__usersSearch { flex: 1 1 240px; }
.afm__usersRole { flex: 0 0 auto; width: auto; }
.afm__usersTable { overflow-x: auto; }
.afm__usersTable .afm__table { width: 100%; border-collapse: collapse; font-size: 14px; }
.afm__usersTable th, .afm__usersTable td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--afm-border); vertical-align: top; }
.afm__usersTable th { color: var(--afm-muted); font-weight: 600; }
.afm__usersActions { white-space: nowrap; text-align: right; }
.afm__usersPager { display: flex; justify-content: flex-end; align-items: center; gap: 10px; margin-top: var(--afm-gap); color: var(--afm-muted); }
.afm__pwRow { display: flex; gap: 8px; }
.afm__pwRow .afm__input { flex: 1; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
@media (max-width: 720px) {
  .afm__usersTable thead { display: none; }
  .afm__usersTable tr { display: block; padding: 8px 0; border-bottom: 1px solid var(--afm-border); }
  .afm__usersTable td { display: flex; justify-content: space-between; gap: 12px; border: 0; padding: 4px 0; }
  .afm__usersTable td[data-label]::before { content: attr(data-label); color: var(--afm-muted); }
  .afm__usersActions { justify-content: flex-start; flex-wrap: wrap; white-space: normal; }
}
```

Plus minimal rules for any class Step 6's check found missing.

- [ ] **Step 8: Verify**

Run: `node --check assets/js/user-manager.js && node --check assets/js/file-manager.js && node --check assets/js/account-documents.js && node tests/client-expand.js | tail -1 && php -l anchor-private-file-manager.php && php tests/run.php | tail -1 && grep -n "bulk-import-users\|populateImportRoles\|data-afm-import" assets/js/file-manager.js anchor-private-file-manager.php`
Expected: all syntax checks pass, both suites `ALL PASS`, final grep prints nothing.

- [ ] **Step 9: Commit**

```bash
git add assets/js/user-manager.js assets/js/file-manager.js assets/js/account-documents.js assets/css/file-manager.css anchor-private-file-manager.php tests/client-expand.js
git commit -m "feat: Users panel with search, add person, CSV import popup and row actions"
```

---

### Task 7: Version bump and staging checklist

**Files:**
- Modify: `anchor-private-file-manager.php:5` and `:22`
- Create: `docs/superpowers/specs/2026-09-24-user-manager-verification.md`

- [ ] **Step 1:** Set `Version: 2.15.0` in the header and `const VERSION = '2.15.0';`. Run `grep -n "2.15.0\|2.14.0" anchor-private-file-manager.php` — both markers read 2.15.0; the migration's `'2.15.0'` literal is expected; no `2.14.0` remains except in comments.

- [ ] **Step 2:** Write the verification checklist (no local WP, so this is what staging must confirm):

```markdown
# User Manager 2.15.0 — Staging Verification

Run on a staging copy with the plugin updated from 2.14.x, logged in as an administrator.

## Branding
- [ ] International staging: sidebar logo shows (site icon), Settings → Anchor File Manager → Request-access recipient reads tiffany@tmjtherapycentre.com.
- [ ] Fresh install (STL staging): recipient defaults to the site admin email; setting a Portal logo URL changes the sidebar image; clearing it falls back to the site icon.

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
```

- [ ] **Step 3: Final suite and commit**

Run: `php tests/run.php | tail -1 && node tests/client-expand.js | tail -1 && for f in anchor-private-file-manager.php includes/*.php; do php -l "$f" | grep -v "No syntax"; done`
Expected: `ALL PASS` twice, no lint output.

```bash
git add anchor-private-file-manager.php docs/superpowers/specs/2026-09-24-user-manager-verification.md
git commit -m "chore: 2.15.0 and staging verification checklist"
```

Do not tag or push; the human decides when to open the PR and release.

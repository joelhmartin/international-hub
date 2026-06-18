# Bulk User Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin upload a CSV from inside the plugin's front-end UI to bulk-create WordPress users, assign them one role, and optionally email each a set-password link.

**Architecture:** Pure parsing/validation logic lives in a new testable helper class `includes/class-afm-user-import.php` (exercised by the existing plain-PHP runner `tests/run.php`). A new admin-only AJAX handler `ajax_bulk_import_users()` in the main plugin file does the WordPress side (file read, role check, `wp_insert_user`, notifications, activity log). The UI is an admin-only "Add Users" tab in the front-end portal rendered by `render_documents_portal()`, mirroring the existing "Product Docs" tab; the import button posts a multipart form via the existing `$.ajax` upload pattern.

**Tech Stack:** PHP (WordPress plugin), `$wpdb`, `wp_insert_user`/`wp_new_user_notification`, jQuery front-end (`assets/js/file-manager.js`), plain-PHP test runner (`php tests/run.php`).

## Global Constraints

- Pure helper class MUST NOT call any WordPress function (the test runner has no WP runtime). Use PHP built-ins only: `str_getcsv`, `preg_*`, `filter_var(..., FILTER_VALIDATE_EMAIL)`, `strtolower`, `trim`.
- All new AJAX handlers gate exactly like existing ones: `$this->require_nonce();` then `is_user_logged_in()` (401) then `current_user_can('administrator')` (403).
- Nonce action constant: `self::NONCE_ACTION` = `'anchor_fm_nonce'`; JS uses `AnchorFM.nonce`.
- Responses use `$this->json_success($data)` / `$this->json_error($message, $code)`.
- "Rules" = a single WordPress **role** for the whole batch (validated against `get_editable_roles_for_permissions()`; never `administrator`).
- Passwords are auto-generated and never shown. Notification = WordPress standard set-password link via `wp_new_user_notification($id, null, 'user')`.
- Required per row: first name, last name, valid email. Username optional → derived `firstInitial . '.' . lastName`, lowercased/sanitized, uniquified.
- Class name: `Anchor_FM_User_Import`. Constant `MAX_ROWS = 1000`. Max upload size 2 MB.
- Follow existing code style (4-space indent, `afm__*` CSS classes, `data-afm-*` / `data-apfm-*` hooks).

---

### Task 1: Username helpers in the pure import class

**Files:**
- Create: `includes/class-afm-user-import.php`
- Test: `tests/run.php` (append checks)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Anchor_FM_User_Import::sanitize_username(string $raw): string` — lowercases, keeps only `[a-z0-9._-]`.
  - `Anchor_FM_User_Import::derive_username(string $first, string $last): string` — `sanitize_username(firstInitial . '.' . last)`.

- [ ] **Step 1: Write the failing tests** — append to `tests/run.php` (before the final `echo $failures...` line):

```php
require __DIR__ . '/../includes/class-afm-user-import.php';

// --- Anchor_FM_User_Import::sanitize_username ---
check('sanitize lowercases', Anchor_FM_User_Import::sanitize_username('J.Smith'), 'j.smith');
check('sanitize strips spaces/symbols', Anchor_FM_User_Import::sanitize_username('Mary O\'Brien!'), 'maryobrien');
check('sanitize keeps dot/dash/underscore', Anchor_FM_User_Import::sanitize_username('a.b-c_d'), 'a.b-c_d');

// --- Anchor_FM_User_Import::derive_username ---
check('derive basic', Anchor_FM_User_Import::derive_username('Jane', 'Smith'), 'j.smith');
check('derive trims', Anchor_FM_User_Import::derive_username('  Bob ', ' Lee '), 'b.lee');
check('derive lowercases', Anchor_FM_User_Import::derive_username('AL', 'CAPONE'), 'a.capone');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL (fatal: class `Anchor_FM_User_Import` not found, or the new checks fail).

- [ ] **Step 3: Create the class with the two helpers**

Create `includes/class-afm-user-import.php`:

```php
<?php
/**
 * Pure (WordPress-free) helpers for bulk user import: CSV parsing,
 * username derivation, and row validation. No WP functions here so the
 * plain-PHP test runner (tests/run.php) can exercise it.
 */
class Anchor_FM_User_Import {

    const MAX_ROWS = 1000;

    /** Lowercase and keep only characters allowed in a username. */
    public static function sanitize_username($raw) {
        $raw = strtolower(trim((string) $raw));
        return preg_replace('/[^a-z0-9._\-]/', '', $raw);
    }

    /** Derive a username base from a first + last name: first initial + '.' + last. */
    public static function derive_username($first, $last) {
        $first = trim((string) $first);
        $last  = trim((string) $last);
        $initial = $first === '' ? '' : substr($first, 0, 1);
        return self::sanitize_username($initial . '.' . $last);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: PASS (ends with `ALL PASS`).

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-user-import.php tests/run.php
git commit -m "feat: username derivation helpers for bulk user import"
```

---

### Task 2: Uniqueness resolver

**Files:**
- Modify: `includes/class-afm-user-import.php`
- Test: `tests/run.php` (append checks)

**Interfaces:**
- Consumes: `sanitize_username` (Task 1).
- Produces: `Anchor_FM_User_Import::make_unique(string $base, callable $exists): string` — returns `$base` if `$exists($base)` is false; otherwise appends `2`, `3`, … until `$exists` returns false. `$exists` is `function(string $name): bool`.

- [ ] **Step 1: Write the failing tests** — append to `tests/run.php` (after the Task 1 checks):

```php
// --- Anchor_FM_User_Import::make_unique ---
$none = function ($n) { return false; };
check('unique passthrough', Anchor_FM_User_Import::make_unique('j.smith', $none), 'j.smith');

$taken = ['j.smith' => true, 'j.smith2' => true];
$exists = function ($n) use ($taken) { return isset($taken[$n]); };
check('unique suffixes past taken', Anchor_FM_User_Import::make_unique('j.smith', $exists), 'j.smith3');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL (`make_unique` not defined).

- [ ] **Step 3: Add `make_unique`** — inside the class, after `derive_username`:

```php
    /**
     * Return a username not rejected by $exists($name) (true = taken).
     * Appends 2,3,... to $base until a free name is found.
     */
    public static function make_unique($base, callable $exists) {
        $base = $base === '' ? 'user' : $base;
        if (!$exists($base)) {
            return $base;
        }
        $i = 2;
        while ($exists($base . $i)) {
            $i++;
            if ($i > self::MAX_ROWS + 2) {
                break; // safety valve; never expected to hit
            }
        }
        return $base . $i;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: PASS (`ALL PASS`).

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-user-import.php tests/run.php
git commit -m "feat: unique-username resolver for bulk user import"
```

---

### Task 3: CSV parsing with header detection

**Files:**
- Modify: `includes/class-afm-user-import.php`
- Test: `tests/run.php` (append checks)

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `Anchor_FM_User_Import::is_header_row(array $cells): bool` — true if any cell (lowercased/trimmed) is a known header token.
  - `Anchor_FM_User_Import::parse(string $raw): array` — returns `['header_detected' => bool, 'rows' => array]` where each row is `['line' => int, 'username' => string, 'first_name' => string, 'last_name' => string, 'email' => string]`. `line` is the 1-based source line number. Blank lines are skipped. Default positional order when no header: `username, first name, last name, email`. When a header is detected, columns map by name; unknown headers are ignored; missing columns become `''`.

- [ ] **Step 1: Write the failing tests** — append to `tests/run.php`:

```php
// --- Anchor_FM_User_Import::is_header_row ---
check('header detected by email', Anchor_FM_User_Import::is_header_row(['username','first name','last name','email']), true);
check('non-header not detected', Anchor_FM_User_Import::is_header_row(['jsmith','Jane','Smith','jane@x.com']), false);

// --- Anchor_FM_User_Import::parse (positional, no header) ---
$p = Anchor_FM_User_Import::parse("jsmith,Jane,Smith,jane@x.com\n,Bob,Lee,bob@x.com\n");
check('positional no header flag', $p['header_detected'], false);
check('positional row count', count($p['rows']), 2);
check('positional username', $p['rows'][0]['username'], 'jsmith');
check('positional first', $p['rows'][0]['first_name'], 'Jane');
check('positional email', $p['rows'][0]['email'], 'jane@x.com');
check('positional blank username kept', $p['rows'][1]['username'], '');
check('positional line number', $p['rows'][1]['line'], 2);

// --- parse with header in a different column order ---
$h = Anchor_FM_User_Import::parse("email,first name,last name\njane@x.com,Jane,Smith\n");
check('header detected flag', $h['header_detected'], true);
check('header maps email', $h['rows'][0]['email'], 'jane@x.com');
check('header maps first', $h['rows'][0]['first_name'], 'Jane');
check('header missing username empty', $h['rows'][0]['username'], '');
check('header data line number', $h['rows'][0]['line'], 2);

// --- blank lines skipped ---
$b = Anchor_FM_User_Import::parse("jsmith,Jane,Smith,jane@x.com\n\n   \n,Bob,Lee,bob@x.com\n");
check('blank lines skipped', count($b['rows']), 2);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL (`is_header_row` / `parse` not defined).

- [ ] **Step 3: Add header detection and parsing** — inside the class, after `make_unique`:

```php
    /** Canonical column key for a header cell, or '' if unrecognized. */
    private static function header_key($cell) {
        $c = strtolower(trim((string) $cell));
        $c = str_replace(['_', '-'], ' ', $c);
        $c = preg_replace('/\s+/', ' ', $c);
        switch ($c) {
            case 'username':
            case 'user name':
            case 'login':
                return 'username';
            case 'first name':
            case 'first':
            case 'firstname':
            case 'given name':
                return 'first_name';
            case 'last name':
            case 'last':
            case 'lastname':
            case 'surname':
            case 'family name':
                return 'last_name';
            case 'email':
            case 'email address':
            case 'e mail':
                return 'email';
            default:
                return '';
        }
    }

    /** True if the row looks like a header (any recognized header cell). */
    public static function is_header_row($cells) {
        foreach ((array) $cells as $cell) {
            if (self::header_key($cell) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse raw CSV text into canonical rows.
     * @return array ['header_detected'=>bool, 'rows'=>array]
     */
    public static function parse($raw) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        $default_cols = ['username', 'first_name', 'last_name', 'email'];

        // Find the first non-blank line to test for a header.
        $header_detected = false;
        $col_map = $default_cols; // index => canonical key
        $first_idx = null;
        foreach ($lines as $i => $line) {
            if (trim($line) !== '') { $first_idx = $i; break; }
        }
        if ($first_idx !== null) {
            $cells = str_getcsv($lines[$first_idx]);
            if (self::is_header_row($cells)) {
                $header_detected = true;
                $col_map = [];
                foreach ($cells as $idx => $cell) {
                    $col_map[$idx] = self::header_key($cell); // '' for unknown
                }
            }
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') { continue; }
            if ($header_detected && $i === $first_idx) { continue; } // skip header line
            $cells = str_getcsv($line);
            $row = ['line' => $i + 1, 'username' => '', 'first_name' => '', 'last_name' => '', 'email' => ''];
            foreach ($cells as $idx => $val) {
                $key = isset($col_map[$idx]) ? $col_map[$idx] : '';
                if ($key !== '' && isset($row[$key])) {
                    $row[$key] = trim((string) $val);
                }
            }
            $rows[] = $row;
        }

        return ['header_detected' => $header_detected, 'rows' => $rows];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: PASS (`ALL PASS`).

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-user-import.php tests/run.php
git commit -m "feat: CSV parsing with header detection for bulk user import"
```

---

### Task 4: Row validation and email normalization

**Files:**
- Modify: `includes/class-afm-user-import.php`
- Test: `tests/run.php` (append checks)

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `Anchor_FM_User_Import::normalize_email(string $raw): string` — `strtolower(trim($raw))`.
  - `Anchor_FM_User_Import::validate(array $row): array` — returns `['ok' => bool, 'error' => string]`. Requires non-empty `first_name`, non-empty `last_name`, and a valid `email` (`filter_var(... FILTER_VALIDATE_EMAIL)`). Error message order: email first, then first name, then last name.

- [ ] **Step 1: Write the failing tests** — append to `tests/run.php`:

```php
// --- Anchor_FM_User_Import::normalize_email ---
check('normalize email', Anchor_FM_User_Import::normalize_email('  Jane@X.COM '), 'jane@x.com');

// --- Anchor_FM_User_Import::validate ---
$ok = Anchor_FM_User_Import::validate(['first_name'=>'Jane','last_name'=>'Smith','email'=>'jane@x.com','username'=>'']);
check('valid row ok', $ok['ok'], true);
check('valid row no error', $ok['error'], '');

$bad_email = Anchor_FM_User_Import::validate(['first_name'=>'Jane','last_name'=>'Smith','email'=>'not-an-email','username'=>'']);
check('bad email not ok', $bad_email['ok'], false);
check('bad email message', $bad_email['error'], 'Invalid or missing email');

$no_first = Anchor_FM_User_Import::validate(['first_name'=>'','last_name'=>'Smith','email'=>'jane@x.com','username'=>'']);
check('missing first not ok', $no_first['ok'], false);
check('missing first message', $no_first['error'], 'Missing first name');

$no_last = Anchor_FM_User_Import::validate(['first_name'=>'Jane','last_name'=>'','email'=>'jane@x.com','username'=>'']);
check('missing last not ok', $no_last['ok'], false);
check('missing last message', $no_last['error'], 'Missing last name');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL (`normalize_email` / `validate` not defined).

- [ ] **Step 3: Add validation helpers** — inside the class, after `parse`:

```php
    /** Lowercase + trim an email. */
    public static function normalize_email($raw) {
        return strtolower(trim((string) $raw));
    }

    /**
     * Validate a canonical row. Returns ['ok'=>bool, 'error'=>string].
     * Checks email, then first name, then last name.
     */
    public static function validate($row) {
        $email = isset($row['email']) ? trim((string) $row['email']) : '';
        $first = isset($row['first_name']) ? trim((string) $row['first_name']) : '';
        $last  = isset($row['last_name']) ? trim((string) $row['last_name']) : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid or missing email'];
        }
        if ($first === '') {
            return ['ok' => false, 'error' => 'Missing first name'];
        }
        if ($last === '') {
            return ['ok' => false, 'error' => 'Missing last name'];
        }
        return ['ok' => true, 'error' => ''];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: PASS (`ALL PASS`).

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-user-import.php tests/run.php
git commit -m "feat: row validation + email normalization for bulk user import"
```

---

### Task 5: Backend AJAX handler

**Files:**
- Modify: `anchor-private-file-manager.php` (require the class near line 11; register the AJAX action near line 64; add the handler method near the other `ajax_*` methods, e.g. after `ajax_user_search()` ~line 2521)

**Interfaces:**
- Consumes: all `Anchor_FM_User_Import::*` from Tasks 1–4; existing `require_nonce()`, `json_error()`, `json_success()`, `get_editable_roles_for_permissions()`, `log_activity()`.
- Produces: AJAX action `anchor_fm_bulk_import_users` returning `{ created:int, skipped:int, errors:int, rows:[{line,username,email,status,message}] }` where `status` ∈ `created|skipped|error`.

- [ ] **Step 1: Require the helper class** — in `anchor-private-file-manager.php`, immediately after the `class-afm-watch-math.php` require (line 11):

```php
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-user-import.php';
```

- [ ] **Step 2: Register the AJAX action** — after the `wp_ajax_anchor_fm_user_search` registration (~line 64):

```php
        add_action('wp_ajax_anchor_fm_bulk_import_users', [$this, 'ajax_bulk_import_users']);
```

- [ ] **Step 3: Add the handler method** — after `ajax_user_search()` (~line 2521), before `get_editable_roles_for_permissions()`:

```php
    public function ajax_bulk_import_users() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        if (!current_user_can('administrator')) $this->json_error('Forbidden', 403);

        // Role (one for the whole batch; administrator not allowed).
        $role = isset($_POST['role']) ? sanitize_key((string) $_POST['role']) : '';
        $valid_roles = array_column($this->get_editable_roles_for_permissions(), 'key');
        if ($role === '' || !in_array($role, $valid_roles, true)) {
            $this->json_error('Please choose a valid role.');
        }
        $send_email = !empty($_POST['send_email']) && $_POST['send_email'] !== '0';

        // Uploaded CSV.
        if (empty($_FILES['csv']) || !isset($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            $this->json_error('No CSV file was uploaded.');
        }
        if ((int) $_FILES['csv']['size'] > 2 * 1024 * 1024) {
            $this->json_error('CSV file is too large (max 2 MB).');
        }
        $raw = file_get_contents($_FILES['csv']['tmp_name']);
        if ($raw === false || trim($raw) === '') {
            $this->json_error('The CSV file is empty.');
        }

        $parsed = Anchor_FM_User_Import::parse($raw);
        $rows = $parsed['rows'];
        if (count($rows) > Anchor_FM_User_Import::MAX_ROWS) {
            $this->json_error(sprintf('Too many rows (%d). Maximum is %d.', count($rows), Anchor_FM_User_Import::MAX_ROWS));
        }

        $created = 0; $skipped = 0; $errors = 0;
        $report = [];
        $seen_emails = [];
        $batch_usernames = [];

        foreach ($rows as $row) {
            $line = (int) $row['line'];
            $row['first_name'] = sanitize_text_field($row['first_name']);
            $row['last_name']  = sanitize_text_field($row['last_name']);
            $row['email']      = Anchor_FM_User_Import::normalize_email($row['email']);
            $row['username']   = Anchor_FM_User_Import::sanitize_username($row['username']);

            $v = Anchor_FM_User_Import::validate($row);
            if (!$v['ok']) {
                $errors++;
                $report[] = ['line' => $line, 'username' => $row['username'], 'email' => $row['email'], 'status' => 'error', 'message' => $v['error']];
                continue;
            }

            // Duplicate email: existing WP user or repeated within this CSV.
            if (isset($seen_emails[$row['email']]) || email_exists($row['email'])) {
                $skipped++;
                $report[] = ['line' => $line, 'username' => $row['username'], 'email' => $row['email'], 'status' => 'skipped', 'message' => 'Email already exists'];
                continue;
            }

            // Username: supplied or derived; made unique vs WP + this batch.
            $base = $row['username'] !== '' ? $row['username'] : Anchor_FM_User_Import::derive_username($row['first_name'], $row['last_name']);
            $username = Anchor_FM_User_Import::make_unique($base, function ($name) use ($batch_usernames) {
                return isset($batch_usernames[$name]) || username_exists($name);
            });

            $password = wp_generate_password(16, true, false);
            $user_id = wp_insert_user([
                'user_login'   => $username,
                'user_email'   => $row['email'],
                'user_pass'    => $password,
                'first_name'   => $row['first_name'],
                'last_name'    => $row['last_name'],
                'display_name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'role'         => $role,
            ]);

            if (is_wp_error($user_id)) {
                $errors++;
                $report[] = ['line' => $line, 'username' => $username, 'email' => $row['email'], 'status' => 'error', 'message' => $user_id->get_error_message()];
                continue;
            }

            $batch_usernames[$username] = true;
            $seen_emails[$row['email']] = true;
            $created++;

            if ($send_email) {
                wp_new_user_notification($user_id, null, 'user');
            }

            $report[] = ['line' => $line, 'username' => $username, 'email' => $row['email'], 'status' => 'created', 'message' => ''];
        }

        $this->log_activity(get_current_user_id(), 'bulk_import', 'user', 0, [
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
            'role'    => $role,
            'emailed' => $send_email,
        ]);

        $this->json_success([
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
            'rows'    => $report,
        ]);
    }
```

- [ ] **Step 4: Lint the PHP**

Run: `php -l includes/class-afm-user-import.php && php -l anchor-private-file-manager.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 5: Re-run pure tests to confirm nothing broke**

Run: `php tests/run.php`
Expected: PASS (`ALL PASS`).

- [ ] **Step 6: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: bulk user import AJAX handler"
```

---

### Task 6: Front-end UI — nav tab, panel, and localized data

**Files:**
- Modify: `anchor-private-file-manager.php` — `wp_localize_script('anchor-file-manager', 'AnchorFM', ...)` block (~line 504); the sidebar `<nav>` in `render_documents_portal()` (after the `product-docs` nav button, ~line 602); the `<section class="afm__content">` panels (after the `data-apfm-panel="product-docs"` panel's closing `</div>`).

**Interfaces:**
- Consumes: existing `AnchorFM.roles` (already localized, excludes administrator).
- Produces: localized `AnchorFM.defaultRole` (string) and `AnchorFM.i18n.addUsers`; DOM hooks `data-apfm-tab="users"`, `data-apfm-panel="users"`, `data-afm-import-file`, `data-afm-import-role`, `data-afm-import-email`, `data-afm-action="bulk-import-users"`, `data-afm-import-results`.

- [ ] **Step 1: Add localized data** — in the `AnchorFM` localize array, add `defaultRole` (after the `roles` entry ~line 515) and an `addUsers` i18n string (inside the `i18n` array):

```php
            'roles' => $this->get_editable_roles_for_permissions(),
            'defaultRole' => (string) get_option('default_role'),
```

In the `i18n` array (after `'productDocs' => ...`):

```php
                'addUsers' => __('Add Users', 'anchor-private-file-manager'),
```

- [ ] **Step 2: Add the sidebar nav button** — immediately after the `product-docs` nav `<?php endif; ?>` (the block ending ~line 602), add:

```php
                        <?php if (current_user_can('administrator')) : ?>
                        <button type="button" class="aap__navItem" data-apfm-tab="users">
                            <span class="dashicons dashicons-groups" aria-hidden="true"></span>
                            <?php esc_html_e('Add Users', 'anchor-private-file-manager'); ?>
                        </button>
                        <?php endif; ?>
```

- [ ] **Step 3: Add the panel** — immediately after the closing `</div>` of the `data-apfm-panel="product-docs"` panel (and its `<?php endif; ?>`), add:

```php
                        <?php if (current_user_can('administrator')) : ?>
                        <div class="afm__panel aap__panel" data-apfm-panel="users" data-afm-panel="users">
                            <div class="afm__cardBox afm__userImport">
                                <div class="afm__sectionTitle"><?php esc_html_e('Bulk import users', 'anchor-private-file-manager'); ?></div>
                                <p class="afm__importHint">
                                    <?php esc_html_e('Upload a CSV with columns in this order: username, first name, last name, email. A header row is optional. Username is optional — when blank it becomes the first initial, a period, then the last name (e.g. j.smith). Passwords are generated automatically.', 'anchor-private-file-manager'); ?>
                                </p>
                                <div class="afm__formRow">
                                    <label class="afm__label" for="afm-import-file"><?php esc_html_e('CSV file', 'anchor-private-file-manager'); ?></label>
                                    <input type="file" id="afm-import-file" class="afm__importFile" accept=".csv,text/csv,text/plain" data-afm-import-file>
                                </div>
                                <div class="afm__formRow">
                                    <label class="afm__label" for="afm-import-role"><?php esc_html_e('Assign role', 'anchor-private-file-manager'); ?></label>
                                    <select id="afm-import-role" class="afm__select" data-afm-import-role></select>
                                </div>
                                <label class="afm__check">
                                    <input type="checkbox" data-afm-import-email checked>
                                    <?php esc_html_e('Email new users a link to set their password', 'anchor-private-file-manager'); ?>
                                </label>
                                <div class="afm__formActions">
                                    <button type="button" class="afm__btn afm__btn--primary" data-afm-action="bulk-import-users">
                                        <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                                        <?php esc_html_e('Import users', 'anchor-private-file-manager'); ?>
                                    </button>
                                </div>
                                <div class="afm__importResults" data-afm-import-results hidden></div>
                            </div>
                        </div>
                        <?php endif; ?>
```

- [ ] **Step 4: Lint the PHP**

Run: `php -l anchor-private-file-manager.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: Add Users tab markup + localized role data"
```

---

### Task 7: Front-end JS — populate roles, handle import, render report

**Files:**
- Modify: `assets/js/file-manager.js` (add helpers + a delegated click handler near the other `$root.on('click', ...)` bindings ~line 1316; call `populateImportRoles()` during init)
- Modify: `assets/js/account-documents.js` (add a `users` entry to the `titleMap` in `updateTitle`, ~line 63)

**Interfaces:**
- Consumes: `AnchorFM.roles`, `AnchorFM.defaultRole`, `AnchorFM.nonce`, `AnchorFM.ajax`, `AnchorFM.isAdmin`, the `esc()` helper (already defined in file-manager.js), AJAX action `anchor_fm_bulk_import_users` (Task 5), DOM hooks (Task 6).
- Produces: none (terminal UI behavior).

- [ ] **Step 1: Add the import helpers and handler** — in `assets/js/file-manager.js`, near the other delegated click bindings (~line 1316), add:

```js
    // --- Bulk user import (admin) ---
    function populateImportRoles() {
        const $sel = $root.find('[data-afm-import-role]');
        if (!$sel.length) return;
        const roles = Array.isArray(AnchorFM.roles) ? AnchorFM.roles : [];
        const def = AnchorFM.defaultRole || '';
        $sel.html(roles.map(function (r) {
            const sel = r.key === def ? ' selected' : '';
            return `<option value="${esc(r.key)}"${sel}>${esc(r.label)}</option>`;
        }).join(''));
    }

    function showImportMessage(msg) {
        $root.find('[data-afm-import-results]')
            .html(`<div class="afm__importSummary afm__importSummary--error">${esc(msg)}</div>`)
            .prop('hidden', false);
    }

    function renderImportResults(res) {
        const rows = Array.isArray(res.rows) ? res.rows : [];
        const summary = `${res.created || 0} created, ${res.skipped || 0} skipped, ${res.errors || 0} error(s)`;
        const body = rows.map(function (r) {
            return `<tr class="afm__importRow afm__importRow--${esc(r.status)}">`
                + `<td>${esc(String(r.line || ''))}</td>`
                + `<td>${esc(r.username || '')}</td>`
                + `<td>${esc(r.email || '')}</td>`
                + `<td>${esc(r.status || '')}</td>`
                + `<td>${esc(r.message || '')}</td>`
                + `</tr>`;
        }).join('');
        $root.find('[data-afm-import-results]').html(
            `<div class="afm__importSummary">${esc(summary)}</div>`
            + `<table class="afm__importTable"><thead><tr>`
            + `<th>#</th><th>Username</th><th>Email</th><th>Status</th><th>Message</th>`
            + `</tr></thead><tbody>${body}</tbody></table>`
        ).prop('hidden', false);
    }

    $root.on('click', '[data-afm-action="bulk-import-users"]', function (e) {
        e.preventDefault();
        if (!AnchorFM.isAdmin) return;
        const $btn = $(this);
        const fileInput = $root.find('[data-afm-import-file]')[0];
        const file = fileInput && fileInput.files && fileInput.files[0];
        if (!file) { showImportMessage('Please choose a CSV file first.'); return; }

        const data = new FormData();
        data.append('action', 'anchor_fm_bulk_import_users');
        data.append('nonce', AnchorFM.nonce);
        data.append('role', $root.find('[data-afm-import-role]').val() || '');
        data.append('send_email', $root.find('[data-afm-import-email]').is(':checked') ? '1' : '0');
        data.append('csv', file, file.name);

        $btn.prop('disabled', true);
        $root.addClass('afm--busy');
        $.ajax({ url: AnchorFM.ajax, method: 'POST', data, processData: false, contentType: false })
            .done(function (resp) {
                if (resp && resp.success) {
                    renderImportResults(resp.data);
                } else {
                    showImportMessage((resp && resp.data && resp.data.message) || 'Import failed.');
                }
            })
            .fail(function (xhr) {
                const msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                showImportMessage(msg || 'Import failed.');
            })
            .always(function () {
                $btn.prop('disabled', false);
                $root.removeClass('afm--busy');
            });
    });
```

- [ ] **Step 2: Call `populateImportRoles()` on init** — find where the IIFE runs its initial setup (where other one-time init runs, e.g. just before or after the existing initial `loadFolder`/bootstrap call). Add:

```js
    if (AnchorFM.isAdmin) populateImportRoles();
```

- [ ] **Step 3: Add the tab title** — in `assets/js/account-documents.js`, inside the `titleMap` object in `updateTitle` (after the `'product-docs'` line, ~line 63), add:

```js
            users: (fm && fm.i18n && fm.i18n.addUsers) ? fm.i18n.addUsers : 'Add Users',
```

- [ ] **Step 4: Syntax-check the JS**

Run: `node --check assets/js/file-manager.js && node --check assets/js/account-documents.js`
Expected: no output, exit 0 (template literals and arrow functions are valid ES2015+; `node --check` accepts them).

- [ ] **Step 5: Commit**

```bash
git add assets/js/file-manager.js assets/js/account-documents.js
git commit -m "feat: wire Add Users tab — role select, import POST, results table"
```

---

### Task 8: Styles for the import panel

**Files:**
- Modify: `assets/css/file-manager.css` (append)

**Interfaces:**
- Consumes: existing `afm__*` design tokens/classes.
- Produces: none.

- [ ] **Step 1: Append styles** — add to the end of `assets/css/file-manager.css`:

```css
/* --- Bulk user import --- */
.afm__userImport { max-width: 760px; }
.afm__importHint { margin: 0 0 16px; opacity: 0.8; font-size: 13px; line-height: 1.5; }
.afm__importFile { display: block; }
.afm__check { display: flex; align-items: center; gap: 8px; margin: 12px 0; font-size: 14px; }
.afm__formActions { margin-top: 16px; }
.afm__importResults { margin-top: 20px; }
.afm__importSummary { font-weight: 600; margin-bottom: 10px; }
.afm__importSummary--error { color: #b32d2e; }
.afm__importTable { width: 100%; border-collapse: collapse; font-size: 13px; }
.afm__importTable th,
.afm__importTable td { text-align: left; padding: 6px 8px; border-bottom: 1px solid rgba(0,0,0,0.08); }
.afm__importTable th { font-weight: 600; }
.afm__importRow--created td:nth-child(4) { color: #1a7f37; font-weight: 600; }
.afm__importRow--skipped td:nth-child(4) { color: #9a6700; font-weight: 600; }
.afm__importRow--error td:nth-child(4) { color: #b32d2e; font-weight: 600; }
```

- [ ] **Step 2: Verify the CSS file still parses** (no tool needed — visual check that braces balance):

Run: `node -e "const c=require('fs').readFileSync('assets/css/file-manager.css','utf8');const o=(c.match(/{/g)||[]).length,x=(c.match(/}/g)||[]).length;if(o!==x){console.error('brace mismatch',o,x);process.exit(1)}console.log('braces balanced',o)"`
Expected: `braces balanced <n>`.

- [ ] **Step 3: Commit**

```bash
git add assets/css/file-manager.css
git commit -m "feat: styles for bulk user import panel"
```

---

## Manual Verification (after all tasks)

These require a live WordPress install (no automated WP harness in this repo). The implementer should ask the site owner to run them and report results — do not assume they pass.

1. **Tab visible to admins only:** Log in as an administrator, open a page with the
   `[anchor_file_manager]` (or `[anchor_account_portal]` / `[anchor_documents_portal]`)
   shortcode. Confirm an **Add Users** item appears in the sidebar; confirm a
   non-admin user does NOT see it.
2. **Happy path:** Upload a CSV `jsmith,Jane,Smith,jane@example.com` with a role
   selected and the email box checked. Confirm one user is created with that role,
   `display_name` "Jane Smith", and the report shows `created`. Confirm Jane receives a
   WordPress "set your password" email.
3. **Derived username:** Upload a row with a blank username (`,Bob,Lee,bob@example.com`).
   Confirm the created username is `b.lee` (or `b.lee2` if taken).
4. **Header row:** Upload a CSV whose first row is `email,first name,last name` and
   confirm columns map correctly and the header row is not imported as a user.
5. **Duplicates skipped:** Re-upload the same CSV; confirm existing emails are reported
   `skipped` ("Email already exists") and no duplicate users are created.
6. **Validation errors:** Upload a row with a bad email and a row missing a last name;
   confirm each is reported `error` with the right message while valid rows still import.
7. **No email option:** Uncheck the email box, import a new user, confirm NO email is sent.
8. **Activity log:** Confirm a `bulk_import` row was written to the activity table with
   the correct counts.

## Self-Review Notes

- **Spec coverage:** CSV upload (Tasks 3,5,6,7) · required name+email, optional username
  (Tasks 3,4) · default column order + header detection (Task 3) · username derivation
  rule (Task 1) · auto-generated passwords (Task 5) · WP role assignment for the batch
  (Tasks 5,6,7) · optional notify email = set-password link (Task 5) · admin-only tab in
  the shortcode UI (Task 6) · per-row report (Tasks 5,7) · activity log (Task 5) ·
  duplicate/limit handling (Task 5) · tests (Tasks 1–4). All spec sections covered.
- **Type consistency:** report rows use keys `line, username, email, status, message`
  consistently in the handler (Task 5) and the JS renderer (Task 7). `status` values
  `created|skipped|error` match the CSS modifier classes (Task 8). `make_unique`'s
  `$exists` predicate signature matches both the test closures (Task 2) and the handler
  closure (Task 5).

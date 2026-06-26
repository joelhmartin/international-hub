# Copy / Paste / Duplicate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins duplicate items in place (with a `(copy)` suffix) and copy/paste items — files, links, videos, and whole folder trees — into another folder, from the context menu, keyboard, bulk bar, and modifier-drag.

**Architecture:** One AJAX action `anchor_fm_copy_items(items[], target_folder_id)` performs every copy (duplicate = copy into the same folder). Pure name-collision logic lives in a WordPress-free, unit-tested helper `includes/class-afm-copy-namer.php` (run via `php tests/run.php`); the recursion, disk, and DB work lives in `anchor-private-file-manager.php`; four thin front-end triggers in `assets/js/file-manager.js` all call one `copyItems()` helper.

**Tech Stack:** PHP (WordPress plugin), `$wpdb`, `wp_unique_filename`/`copy()` for files, jQuery front-end, plain-PHP test runner (`php tests/run.php`).

## Global Constraints

- The naming helper `includes/class-afm-copy-namer.php` MUST NOT call any WordPress function (the test runner has no WP runtime). PHP built-ins only (`preg_match`, `strtolower`, `str` funcs).
- Copies get **no permission rows** — they inherit the destination folder (do not copy `wp_anchor_fm_permissions`).
- `(copy)` suffix scheme: `Report` → `Report (copy)` → `Report (copy 2)` → `Report (copy 3)`. For files the suffix goes on the base name, preserving the extension: `report.pdf` → `report (copy).pdf`. Same-folder duplicate always adds `(copy)`; cross-folder paste keeps the name and only suffixes on collision. Collision checks are case-insensitive.
- AJAX gating mirrors existing handlers: `$this->require_nonce()` then `is_user_logged_in()` (401). Per-item capability: file → `can_user_manage_file`; link → `can_user_manage_link`; video → `can_user_manage_video`; folder → `current_user_can('administrator')`. All copies also require `can_user_upload_to_folder($user_id, $target)`.
- Reject copying a folder into itself or a descendant. Cap a folder copy at `COPY_MAX_NODES = 2000` and `COPY_MAX_DEPTH = 50`.
- Per-item failures (missing source file, failed disk copy, DB error) are reported as `error` results; sibling items still copy. Response shape: `{ copied:int, errors:int, items:[{kind,sourceId,status,newId?,message?}], targetFolderId:int }` via `$this->json_success(...)`.
- Item `kind` ∈ `file|link|video|folder`. Clipboard is in-memory JS only (copy-only). Existing helpers to reuse: `self::table()`, `get_file_row/get_folder_row/get_link_row/get_video_row`, `get_file_path_on_disk`, `get_storage_dir`, `ensure_upload_storage`, `is_descendant`, `can_user_*`, `log_activity`, plus the JS `api()`, `loadFolder()`, `bootstrap()`, `toast()`, `state.selectedRows`, `state.currentFolderId`.
- Follow existing style (PHP 4-space indent; JS jQuery `$root`, template literals, `esc()`).

---

### Task 1: Naming helper — extension split + copy suffix

**Files:**
- Create: `includes/class-afm-copy-namer.php`
- Test: `tests/run.php` (append checks before the final `echo $failures...`/`exit(...)`)

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Anchor_FM_Copy_Namer::split_extension(string $name): array` → `[base, ext]`, `ext` includes the leading dot or `''`. Only a trailing `.<1-10 alnum>` counts; a leading-dot name like `.htaccess` has no extension.
  - `Anchor_FM_Copy_Namer::add_copy_suffix(string $base): string` → appends/bumps `(copy)` on a base (no extension handling).

- [ ] **Step 1: Write the failing tests** — append to `tests/run.php`:

```php
require __DIR__ . '/../includes/class-afm-copy-namer.php';

// --- Anchor_FM_Copy_Namer::split_extension ---
check('split pdf base', Anchor_FM_Copy_Namer::split_extension('report.pdf')[0], 'report');
check('split pdf ext', Anchor_FM_Copy_Namer::split_extension('report.pdf')[1], '.pdf');
check('split no ext folder', Anchor_FM_Copy_Namer::split_extension('My Folder')[1], '');
check('split dotfile no ext', Anchor_FM_Copy_Namer::split_extension('.htaccess')[1], '');
check('split double ext base', Anchor_FM_Copy_Namer::split_extension('archive.tar.gz')[0], 'archive.tar');
check('split double ext ext', Anchor_FM_Copy_Namer::split_extension('archive.tar.gz')[1], '.gz');

// --- Anchor_FM_Copy_Namer::add_copy_suffix ---
check('suffix first', Anchor_FM_Copy_Namer::add_copy_suffix('Report'), 'Report (copy)');
check('suffix second', Anchor_FM_Copy_Namer::add_copy_suffix('Report (copy)'), 'Report (copy 2)');
check('suffix third', Anchor_FM_Copy_Namer::add_copy_suffix('Report (copy 2)'), 'Report (copy 3)');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL (class `Anchor_FM_Copy_Namer` not found).

- [ ] **Step 3: Create the class**

Create `includes/class-afm-copy-namer.php`:

```php
<?php
/**
 * Pure (WordPress-free) name-collision helpers for copy/paste/duplicate.
 * No WP functions here so tests/run.php can exercise it.
 */
class Anchor_FM_Copy_Namer {

    const MAX_SUFFIX = 1000;

    /** Split into [base, ext]; ext includes the leading dot, or '' if none. */
    public static function split_extension($name) {
        $name = (string) $name;
        if (preg_match('/^(.+)(\.[A-Za-z0-9]{1,10})$/', $name, $m)) {
            return [$m[1], $m[2]];
        }
        return [$name, ''];
    }

    /** Append or bump a "(copy)" suffix on a base (no extension handling). */
    public static function add_copy_suffix($base) {
        $base = (string) $base;
        if (preg_match('/^(.*) \(copy(?: (\d+))?\)$/', $base, $m)) {
            $stem = $m[1];
            $n = (isset($m[2]) && $m[2] !== '') ? (int) $m[2] : 1;
            return $stem . ' (copy ' . ($n + 1) . ')';
        }
        return $base . ' (copy)';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: PASS (`ALL PASS`).

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-copy-namer.php tests/run.php
git commit -m "feat: copy-namer extension split + copy suffix"
```

---

### Task 2: Naming helper — next name + unique resolver

**Files:**
- Modify: `includes/class-afm-copy-namer.php`
- Test: `tests/run.php` (append checks)

**Interfaces:**
- Consumes: `split_extension`, `add_copy_suffix` (Task 1).
- Produces:
  - `Anchor_FM_Copy_Namer::next_copy_name(string $name, bool $is_file): string` — applies `add_copy_suffix` to the base, preserving a file extension when `$is_file`.
  - `Anchor_FM_Copy_Namer::resolve_unique(string $desired, array $existing, bool $is_file, bool $force_copy): string` — case-insensitive collision check against `$existing`; if `$force_copy` or `$desired` collides, repeatedly `next_copy_name` until free; else return `$desired`.

- [ ] **Step 1: Write the failing tests** — append to `tests/run.php`:

```php
// --- Anchor_FM_Copy_Namer::next_copy_name ---
check('next file keeps ext', Anchor_FM_Copy_Namer::next_copy_name('report.pdf', true), 'report (copy).pdf');
check('next folder no ext', Anchor_FM_Copy_Namer::next_copy_name('My Folder', false), 'My Folder (copy)');

// --- Anchor_FM_Copy_Namer::resolve_unique ---
check('resolve no collision unchanged', Anchor_FM_Copy_Namer::resolve_unique('report.pdf', ['other.pdf'], true, false), 'report.pdf');
check('resolve forced duplicate', Anchor_FM_Copy_Namer::resolve_unique('report.pdf', [], true, true), 'report (copy).pdf');
check('resolve collide bumps', Anchor_FM_Copy_Namer::resolve_unique('report.pdf', ['report (copy).pdf'], true, true), 'report (copy 2).pdf');
check('resolve case-insensitive collision', Anchor_FM_Copy_Namer::resolve_unique('Report.PDF', ['report.pdf'], true, false), 'Report (copy).PDF');
check('resolve folder forced', Anchor_FM_Copy_Namer::resolve_unique('Docs', ['Docs (copy)'], false, true), 'Docs (copy 2)');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/run.php`
Expected: FAIL (`next_copy_name` / `resolve_unique` not defined).

- [ ] **Step 3: Add the two methods** — inside the class, after `add_copy_suffix`:

```php
    /** Apply add_copy_suffix, preserving a file extension when $is_file. */
    public static function next_copy_name($name, $is_file) {
        if ($is_file) {
            list($base, $ext) = self::split_extension($name);
            return self::add_copy_suffix($base) . $ext;
        }
        return self::add_copy_suffix((string) $name);
    }

    /**
     * Resolve a unique name against existing sibling names (case-insensitive).
     * If $force_copy, always apply at least one "(copy)"; then bump until free.
     */
    public static function resolve_unique($desired, array $existing, $is_file, $force_copy) {
        $taken = [];
        foreach ($existing as $e) {
            $taken[strtolower((string) $e)] = true;
        }
        $candidate = (string) $desired;
        if ($force_copy) {
            $candidate = self::next_copy_name($candidate, $is_file);
        }
        $i = 0;
        while (isset($taken[strtolower($candidate)]) && $i < self::MAX_SUFFIX) {
            $candidate = self::next_copy_name($candidate, $is_file);
            $i++;
        }
        return $candidate;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php tests/run.php`
Expected: PASS (`ALL PASS`).

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-copy-namer.php tests/run.php
git commit -m "feat: copy-namer next-name + unique resolver"
```

---

### Task 3: Backend — require helper + simple-kind copy helpers

**Files:**
- Modify: `anchor-private-file-manager.php` (require near line 11; add three private methods near the other copy/move helpers, e.g. after `copy_view_permissions` ~line 1145)

**Interfaces:**
- Consumes: `Anchor_FM_Copy_Namer::resolve_unique` (Task 2); existing `self::table()`, `get_current_user_id()`, `current_time()`.
- Produces (private methods on the main class):
  - `gather_existing_names(int $folder_id): array` — display names of direct children of `$folder_id` (folder names + file `original_name` + link/video `title`).
  - `copy_link_row(object $link, int $target_folder_id, array &$existing, bool $force_copy): int` — inserts a copied link; returns new id; appends the chosen title to `$existing`.
  - `copy_video_row(object $video, int $target_folder_id, array &$existing, bool $force_copy): int` — same for a video (keeps `vimeo_id`).

- [ ] **Step 1: Require the helper** — after the `class-afm-user-import.php` require (~line 12):

```php
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-copy-namer.php';
```

- [ ] **Step 2: Add the three helpers** — after `copy_view_permissions()` (~line 1145):

```php
    /** Display names of direct children of a folder (for collision checks). */
    private function gather_existing_names($folder_id) {
        global $wpdb;
        $folder_id = (int) $folder_id;
        $names = [];
        $folders = self::table('folders');
        $files = self::table('files');
        $links = self::table('links');
        $videos = self::table('videos');
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT name FROM {$folders} WHERE parent_id = %d", $folder_id)) as $n) { $names[] = $n; }
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT original_name FROM {$files} WHERE folder_id = %d", $folder_id)) as $n) { $names[] = $n; }
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT title FROM {$links} WHERE folder_id = %d", $folder_id)) as $n) { $names[] = $n; }
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT title FROM {$videos} WHERE folder_id = %d", $folder_id)) as $n) { $names[] = $n; }
        return $names;
    }

    /** Copy a link row into a target folder. Returns new link id. */
    private function copy_link_row($link, $target_folder_id, array &$existing, $force_copy) {
        global $wpdb;
        $title = Anchor_FM_Copy_Namer::resolve_unique($link->title, $existing, false, $force_copy);
        $now = current_time('mysql');
        $wpdb->insert(self::table('links'), [
            'folder_id'  => (int) $target_folder_id,
            'title'      => $title,
            'url'        => $link->url,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%s','%s','%d','%s','%s']);
        $existing[] = $title;
        return (int) $wpdb->insert_id;
    }

    /** Copy a video row (same vimeo_id) into a target folder. Returns new video id. */
    private function copy_video_row($video, $target_folder_id, array &$existing, $force_copy) {
        global $wpdb;
        $title = Anchor_FM_Copy_Namer::resolve_unique($video->title, $existing, false, $force_copy);
        $now = current_time('mysql');
        $wpdb->insert(self::table('videos'), [
            'folder_id'  => (int) $target_folder_id,
            'vimeo_id'   => $video->vimeo_id,
            'title'      => $title,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%s','%s','%d','%s','%s']);
        $existing[] = $title;
        return (int) $wpdb->insert_id;
    }
```

- [ ] **Step 3: Lint**

Run: `php -l includes/class-afm-copy-namer.php && php -l anchor-private-file-manager.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Confirm pure tests still pass**

Run: `php tests/run.php`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: copy helpers for links, videos, sibling-name gathering"
```

---

### Task 4: Backend — file copy helper (disk + row)

**Files:**
- Modify: `anchor-private-file-manager.php` (add a private method after `copy_video_row`)

**Interfaces:**
- Consumes: `Anchor_FM_Copy_Namer::resolve_unique`; existing `get_file_path_on_disk`, `get_storage_dir`, `ensure_upload_storage`.
- Produces: `copy_file_row(object $file, int $target_folder_id, array &$existing, bool $force_copy): int|WP_Error` — physically copies the stored file into the target folder dir with a fresh `stored_name`, inserts a `files` row with a collision-resolved `original_name`, appends it to `$existing`. Returns new file id, or `WP_Error` if the source is missing or the disk copy fails.

- [ ] **Step 1: Add the helper** — after `copy_video_row()`:

```php
    /** Copy a file (disk bytes + DB row) into a target folder. Returns new id or WP_Error. */
    private function copy_file_row($file, $target_folder_id, array &$existing, $force_copy) {
        global $wpdb;
        $src_path = $this->get_file_path_on_disk($file);
        if (!file_exists($src_path) || !is_readable($src_path)) {
            return new WP_Error('source_missing', 'Source file is missing on disk');
        }

        self::ensure_upload_storage();
        $target_dir = trailingslashit($this->get_storage_dir()) . (int) $target_folder_id;
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
            $htaccess = $target_dir . '/.htaccess';
            if (!file_exists($htaccess)) { @file_put_contents($htaccess, "Deny from all\n"); }
            $index = $target_dir . '/index.php';
            if (!file_exists($index)) { @file_put_contents($index, "<?php\n// Silence is golden.\n"); }
        }

        $stored = wp_unique_filename($target_dir, $file->stored_name);
        $dest = trailingslashit($target_dir) . $stored;
        if (!@copy($src_path, $dest)) {
            return new WP_Error('copy_failed', 'Could not copy file on disk');
        }

        $original = Anchor_FM_Copy_Namer::resolve_unique($file->original_name, $existing, true, $force_copy);
        $wpdb->insert(self::table('files'), [
            'folder_id'        => (int) $target_folder_id,
            'original_name'    => $original,
            'stored_name'      => $stored,
            'mime_type'        => $file->mime_type,
            'size'             => (int) $file->size,
            'sha1'             => $file->sha1,
            'uploader_user_id' => get_current_user_id(),
            'created_at'       => current_time('mysql'),
        ], ['%d','%s','%s','%s','%d','%s','%d','%s']);
        $existing[] = $original;
        return (int) $wpdb->insert_id;
    }
```

- [ ] **Step 2: Lint**

Run: `php -l anchor-private-file-manager.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: file copy helper (disk bytes + row)"
```

---

### Task 5: Backend — recursive folder copy + size/depth guards

**Files:**
- Modify: `anchor-private-file-manager.php` (add two constants on the class and two private methods after `copy_file_row`)

**Interfaces:**
- Consumes: `copy_file_row`, `copy_link_row`, `copy_video_row` (Tasks 3–4); `Anchor_FM_Copy_Namer::resolve_unique`.
- Produces:
  - class constants `COPY_MAX_NODES = 2000`, `COPY_MAX_DEPTH = 50`.
  - `count_folder_tree(int $folder_id, int $depth = 0): int` — total nodes (this folder + files + links + videos + recursively child folders); short-circuits once over `COPY_MAX_NODES` or `COPY_MAX_DEPTH`.
  - `copy_folder_tree(object $folder, int $target_parent_id, array &$existing, bool $force_copy, int $depth = 0): int|WP_Error` — creates the new folder (collision-resolved name) then recursively copies child folders, files, links, and videos into it. Children keep their original names.

- [ ] **Step 1: Add the constants** — near the top of the class, beside other `const` declarations (e.g. after `const NONCE_ACTION = 'anchor_fm_nonce';`):

```php
    const COPY_MAX_NODES = 2000;
    const COPY_MAX_DEPTH = 50;
```

- [ ] **Step 2: Add the two methods** — after `copy_file_row()`:

```php
    /** Count nodes (folder+files+links+videos) in a subtree; short-circuits past the cap. */
    private function count_folder_tree($folder_id, $depth = 0) {
        if ($depth > self::COPY_MAX_DEPTH) { return PHP_INT_MAX; }
        global $wpdb;
        $folder_id = (int) $folder_id;
        $count = 1; // this folder
        $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM " . self::table('files') . " WHERE folder_id = %d", $folder_id));
        $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM " . self::table('links') . " WHERE folder_id = %d", $folder_id));
        $count += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM " . self::table('videos') . " WHERE folder_id = %d", $folder_id));
        $children = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . self::table('folders') . " WHERE parent_id = %d", $folder_id));
        foreach ((array) $children as $cid) {
            $count += $this->count_folder_tree((int) $cid, $depth + 1);
            if ($count > self::COPY_MAX_NODES) { return $count; }
        }
        return $count;
    }

    /** Recursively copy a folder into $target_parent_id. Returns new folder id or WP_Error. */
    private function copy_folder_tree($folder, $target_parent_id, array &$existing, $force_copy, $depth = 0) {
        if ($depth > self::COPY_MAX_DEPTH) { return new WP_Error('too_deep', 'Folder nesting too deep to copy'); }
        global $wpdb;

        $name = Anchor_FM_Copy_Namer::resolve_unique($folder->name, $existing, false, $force_copy);
        $now = current_time('mysql');
        $wpdb->insert(self::table('folders'), [
            'parent_id'     => (int) $target_parent_id,
            'name'          => $name,
            'owner_user_id' => 0,
            'is_private'    => 0,
            'created_by'    => get_current_user_id(),
            'created_at'    => $now,
            'updated_at'    => $now,
        ], ['%d','%s','%d','%d','%d','%s','%s']);
        $new_id = (int) $wpdb->insert_id;
        $existing[] = $name;

        // The new folder starts empty, so children never need forcing; track their
        // chosen names so two children with the same name don't collide.
        $child_existing = [];
        $src_id = (int) $folder->id;

        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('folders') . " WHERE parent_id = %d", $src_id)) as $sf) {
            $this->copy_folder_tree($sf, $new_id, $child_existing, false, $depth + 1);
        }
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('files') . " WHERE folder_id = %d", $src_id)) as $f) {
            $this->copy_file_row($f, $new_id, $child_existing, false);
        }
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('links') . " WHERE folder_id = %d", $src_id)) as $l) {
            $this->copy_link_row($l, $new_id, $child_existing, false);
        }
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::table('videos') . " WHERE folder_id = %d", $src_id)) as $v) {
            $this->copy_video_row($v, $new_id, $child_existing, false);
        }
        return $new_id;
    }
```

- [ ] **Step 3: Lint**

Run: `php -l anchor-private-file-manager.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: recursive folder copy with size/depth guards"
```

---

### Task 6: Backend — `ajax_copy_items` dispatcher + registration

**Files:**
- Modify: `anchor-private-file-manager.php` (register the action near the other `wp_ajax_anchor_fm_*` registrations ~line 47; add the public method after `ajax_move_folder()` ~line 2203)

**Interfaces:**
- Consumes: `gather_existing_names`, `copy_file_row`, `copy_link_row`, `copy_video_row`, `copy_folder_tree`, `count_folder_tree`, `COPY_MAX_NODES` (Tasks 3–5); existing `get_*_row`, `can_user_*`, `is_descendant`, `log_activity`, `require_nonce`, `json_error`, `json_success`.
- Produces: AJAX action `anchor_fm_copy_items` returning `{ copied:int, errors:int, items:[{kind,sourceId,status,newId?,message?}], targetFolderId:int }`.

- [ ] **Step 1: Register the action** — beside `wp_ajax_anchor_fm_move_folder` (~line 47):

```php
        add_action('wp_ajax_anchor_fm_copy_items', [$this, 'ajax_copy_items']);
```

- [ ] **Step 2: Add the handler** — after `ajax_move_folder()` (~line 2203):

```php
    public function ajax_copy_items() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $target = isset($_POST['target_folder_id']) ? (int) $_POST['target_folder_id'] : 0;
        $raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
        $items = json_decode((string) $raw, true);
        if (!is_array($items) || !$items) $this->json_error('No items to copy');
        if ($target <= 0) $this->json_error('Missing target folder');

        if (!$this->get_folder_row($target)) $this->json_error('Target folder not found', 404);
        if (!$this->can_user_upload_to_folder($user_id, $target)) $this->json_error('Forbidden', 403);

        $existing = $this->gather_existing_names($target);
        $results = [];
        $copied = 0;
        $errors = 0;

        foreach ($items as $it) {
            $kind = isset($it['kind']) ? sanitize_key((string) $it['kind']) : '';
            $id = isset($it['id']) ? (int) $it['id'] : 0;
            $res = ['kind' => $kind, 'sourceId' => $id, 'status' => 'error', 'message' => ''];

            if ($id <= 0 || !in_array($kind, ['file', 'link', 'video', 'folder'], true)) {
                $res['message'] = 'Invalid item';
                $errors++; $results[] = $res; continue;
            }

            $new = null;
            if ($kind === 'file') {
                $row = $this->get_file_row($id);
                if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
                if (!$this->can_user_manage_file($user_id, $id)) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
                $new = $this->copy_file_row($row, $target, $existing, ((int) $row->folder_id === $target));
            } elseif ($kind === 'link') {
                $row = $this->get_link_row($id);
                if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
                if (!$this->can_user_manage_link($user_id, $id)) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
                $new = $this->copy_link_row($row, $target, $existing, ((int) $row->folder_id === $target));
            } elseif ($kind === 'video') {
                $row = $this->get_video_row($id);
                if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
                if (!$this->can_user_manage_video($user_id, $id)) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
                $new = $this->copy_video_row($row, $target, $existing, ((int) $row->folder_id === $target));
            } else { // folder
                if (!current_user_can('administrator')) { $res['message'] = 'Forbidden'; $errors++; $results[] = $res; continue; }
                $row = $this->get_folder_row($id);
                if (!$row) { $res['message'] = 'Not found'; $errors++; $results[] = $res; continue; }
                if ($id === $target || $this->is_descendant($target, $id)) {
                    $res['message'] = 'Cannot copy a folder into itself or its own subfolder';
                    $errors++; $results[] = $res; continue;
                }
                if ($this->count_folder_tree($id) > self::COPY_MAX_NODES) {
                    $res['message'] = 'Folder is too large to copy';
                    $errors++; $results[] = $res; continue;
                }
                $new = $this->copy_folder_tree($row, $target, $existing, ((int) $row->parent_id === $target));
            }

            if (is_wp_error($new)) {
                $res['message'] = $new->get_error_message();
                $errors++; $results[] = $res; continue;
            }
            $res['status'] = 'copied';
            $res['newId'] = (int) $new;
            $copied++;
            $results[] = $res;
        }

        $this->log_activity($user_id, 'copy_items', 'folder', $target, [
            'copied' => $copied,
            'errors' => $errors,
            'count'  => count($items),
        ]);
        $this->json_success([
            'copied'         => $copied,
            'errors'         => $errors,
            'items'          => $results,
            'targetFolderId' => $target,
        ]);
    }
```

- [ ] **Step 3: Lint**

Run: `php -l anchor-private-file-manager.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: anchor_fm_copy_items AJAX dispatcher"
```

---

### Task 7: Front-end — clipboard state, `copyItems()`, context-menu actions

**Files:**
- Modify: `assets/js/file-manager.js` (add `clipboard: null` to the `state` object ~line 38–57; add helpers near the other top-level functions; add menu items in `buildRowMenu` ~line 1187; add action cases in the `$menu.on('click', ...)` `ctx.kind` block ~line 1032)

**Interfaces:**
- Consumes: `api()`, `loadFolder()`, `bootstrap()`, `toast()`, `state.selectedRows`, `state.currentFolderId`, `refreshSelectionUI()`, `AnchorFM.isAdmin`, AJAX action `anchor_fm_copy_items` (Task 6).
- Produces: `state.clipboard`, `clipboardItems()`, `setClipboard(items)`, `selectionAsItems()`, `copyItems(items, targetFolderId)`; context-menu actions `copy`, `duplicate`, `paste-into`.

- [ ] **Step 1: Add `clipboard` to state** — in the `state` object literal, add a line:

```js
        clipboard: null,
```

- [ ] **Step 2: Add the clipboard/copy helpers** — near the other top-level helper functions (e.g. just below `rowKey`, ~line 248):

```js
    function clipboardItems() {
        return (state.clipboard && Array.isArray(state.clipboard.items)) ? state.clipboard.items : [];
    }
    function setClipboard(items) {
        state.clipboard = { items: items.slice() };
        if (typeof toast === 'function') {
            toast(items.length + ' item' + (items.length === 1 ? '' : 's') + ' copied');
        }
    }
    function selectionAsItems() {
        return Array.from(state.selectedRows).map(function (k) {
            const p = String(k).split(':');
            return { kind: p[0], id: Number(p[1]) };
        });
    }
    function copyItems(items, targetFolderId) {
        if (!items || !items.length) return;
        $root.addClass('afm--busy');
        return api('anchor_fm_copy_items', {
            items: JSON.stringify(items),
            target_folder_id: targetFolderId
        }).done(function (res) {
            if (res && res.success) {
                const d = res.data || {};
                let msg = (d.copied || 0) + ' copied';
                if (d.errors) { msg += ', ' + d.errors + ' failed'; }
                if (typeof toast === 'function') toast(msg);
                bootstrap();
                loadFolder(state.currentFolderId);
            } else {
                const m = res && res.data && res.data.message;
                if (typeof toast === 'function') toast(m || 'Copy failed');
            }
        }).always(function () { $root.removeClass('afm--busy'); });
    }
```

- [ ] **Step 3: Add menu items** — in `buildRowMenu`, inside the `if (AnchorFM.isAdmin) {` block, before the `rename`/`edit-link` lines:

```js
            if (kind === 'folder' && clipboardItems().length) {
                items.push({ action: 'paste-into', icon: 'clipboard', label: 'Paste here' });
            }
            items.push({ action: 'duplicate', icon: 'admin-page', label: 'Duplicate' });
            items.push({ action: 'copy', icon: 'admin-page', label: 'Copy' });
```

- [ ] **Step 4: Handle the actions** — in the `$menu.on('click', ...)` handler, inside `if (ctx.kind) {`, after the `copy-share-link` line (~line 1047):

```js
            if (action === 'copy') { setClipboard([{ kind: k, id: vid }]); return; }
            if (action === 'duplicate') { copyItems([{ kind: k, id: vid }], state.currentFolderId); return; }
            if (action === 'paste-into') { copyItems(clipboardItems(), vid); return; }
```

- [ ] **Step 5: Syntax-check**

Run: `node --check assets/js/file-manager.js`
Expected: no output, exit 0.

- [ ] **Step 6: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: clipboard state + copyItems + context-menu copy/duplicate/paste"
```

---

### Task 8: Front-end — keyboard shortcuts (C / V / D)

**Files:**
- Modify: `assets/js/file-manager.js` (add a `keydown` handler near the other top-level `$root.on(...)` / `$(document).on(...)` bindings)

**Interfaces:**
- Consumes: `clipboardItems`, `setClipboard`, `selectionAsItems`, `copyItems`, `state.selectedRows`, `state.currentFolderId`, `AnchorFM.isAdmin` (Task 7).
- Produces: none (terminal behavior).

- [ ] **Step 1: Add the handler** — near the other document-level bindings (e.g. after the clipboard helpers from Task 7, but as a `$(document).on('keydown', ...)`):

```js
    $(document).on('keydown', function (e) {
        if (!AnchorFM.isAdmin) return;
        if (!(e.ctrlKey || e.metaKey)) return;
        // Don't hijack typing or shortcuts while a field/modal is focused.
        const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
        if ($root.find('.afm__modal').is(':visible')) return;
        if (!$root.is(':visible')) return;

        const key = String(e.key || '').toLowerCase();
        if (key === 'c') {
            const items = selectionAsItems();
            if (items.length) { e.preventDefault(); setClipboard(items); }
        } else if (key === 'v') {
            const items = clipboardItems();
            if (items.length && state.currentFolderId > 0) { e.preventDefault(); copyItems(items, state.currentFolderId); }
        } else if (key === 'd') {
            const items = selectionAsItems();
            if (items.length && state.currentFolderId > 0) { e.preventDefault(); copyItems(items, state.currentFolderId); }
        }
    });
```

- [ ] **Step 2: Syntax-check**

Run: `node --check assets/js/file-manager.js`
Expected: no output, exit 0.

- [ ] **Step 3: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: keyboard copy/paste/duplicate (Ctrl/Cmd C/V/D)"
```

---

### Task 9: Front-end — bulk bar Copy / Duplicate

**Files:**
- Modify: `assets/js/file-manager.js` (`renderBulkBar` ~line 1677; the `[data-afm-bulk]` click handler ~line 1700)

**Interfaces:**
- Consumes: `setClipboard`, `selectionAsItems`, `copyItems`, `state.selectedRows`, `state.currentFolderId`, `refreshSelectionUI`, `AnchorFM.isAdmin` (Task 7).
- Produces: bulk actions `copy` and `duplicate`.

- [ ] **Step 1: Add bulk buttons** — in `renderBulkBar`, extend the `adminBtns` string so admins get Copy + Duplicate alongside Delete:

```js
        const adminBtns = AnchorFM.isAdmin
            ? `<button type="button" class="afm__btn afm__btn--secondary" data-afm-bulk="copy">Copy</button>
               <button type="button" class="afm__btn afm__btn--secondary" data-afm-bulk="duplicate">Duplicate</button>
               <button type="button" class="afm__btn afm__btn--danger" data-afm-bulk="delete">Delete</button>`
            : '';
```

- [ ] **Step 2: Handle the bulk actions** — in the `$root.on('click', '[data-afm-bulk]', ...)` handler, after the `clear` branch (~line 1703):

```js
        if (op === 'copy') { setClipboard(selectionAsItems()); state.selectedRows.clear(); refreshSelectionUI(); return; }
        if (op === 'duplicate') { copyItems(selectionAsItems(), state.currentFolderId); state.selectedRows.clear(); refreshSelectionUI(); return; }
```

- [ ] **Step 3: Syntax-check**

Run: `node --check assets/js/file-manager.js`
Expected: no output, exit 0.

- [ ] **Step 4: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: bulk-bar copy + duplicate"
```

---

### Task 10: Front-end — modifier-drag copies instead of moves

**Files:**
- Modify: `assets/js/file-manager.js` (`handleDropOnFolder` ~line 2007; the two `drop` bindings that call it ~line 1985 and ~line 1994; optionally the `dragstart` handlers ~line 1919–1956 to set `effectAllowed = 'copyMove'`)

**Interfaces:**
- Consumes: `copyItems`, `flashDrop`, `dragFileId`, `dragFolderId`, `AnchorFM.isAdmin` (Task 7 + existing drag state).
- Produces: none (terminal behavior).

- [ ] **Step 1: Pass the modifier into the drop handler** — change the row-drop binding (~line 1985) to read the modifier and pass it:

```js
    $root.on('drop', '[data-afm-folder-card], [data-afm-open-folder], [data-afm-row-kind="folder"]', function (e) {
        if (!AnchorFM.isAdmin) return;
        if (!dragFileId && !dragFolderId) return;
        e.preventDefault();
        const folderId = Number($(this).data('afm-folder-card') || $(this).data('afm-open-folder') || $(this).data('afm-row-id'));
        if (productDocsFolderId && folderId === productDocsFolderId) return;
        const copyMode = !!(e.originalEvent && (e.originalEvent.ctrlKey || e.originalEvent.altKey));
        handleDropOnFolder($(this), folderId, copyMode);
    });
```

- [ ] **Step 2: Same for the document-level drop** (~line 1994):

```js
    $(document).on('drop', function (e) {
        if (!AnchorFM.isAdmin) return;
        if (!dragFileId && !dragFolderId) return;
        const el = document.elementFromPoint(e.clientX, e.clientY);
        const $target = $(el).closest('[data-afm-folder-card], [data-afm-open-folder], [data-afm-row-kind="folder"]');
        if ($target.length) {
            e.preventDefault();
            const folderId = Number($target.data('afm-folder-card') || $target.data('afm-open-folder') || $target.data('afm-row-id'));
            if (productDocsFolderId && folderId === productDocsFolderId) return;
            const copyMode = !!(e.originalEvent && (e.originalEvent.ctrlKey || e.originalEvent.altKey));
            handleDropOnFolder($target, folderId, copyMode);
        }
    });
```

- [ ] **Step 3: Branch `handleDropOnFolder` on copy mode** — replace the function (~line 2007) with:

```js
    function handleDropOnFolder($el, folderId, copyMode) {
        $el.removeClass('is-drop');
        if (copyMode) {
            const items = [];
            if (dragFileId) { items.push({ kind: 'file', id: dragFileId }); }
            else if (dragFolderId) {
                if (folderId === dragFolderId) return;
                items.push({ kind: 'folder', id: dragFolderId });
            }
            if (items.length) { flashDrop($el); copyItems(items, folderId); }
            return;
        }
        if (dragFileId) {
            api('anchor_fm_move_file', { file_id: dragFileId, folder_id: folderId }).done(res => {
                if (res && res.success) {
                    flashDrop($el);
                    loadFolder(state.currentFolderId);
                }
            });
        } else if (dragFolderId) {
            if (folderId === dragFolderId) return;
            api('anchor_fm_move_folder', { folder_id: dragFolderId, target_folder_id: folderId }).done(res => {
                if (res && res.success) {
                    flashDrop($el);
                    bootstrap();
                    loadFolder(folderId);
                }
            });
        }
    }
```

- [ ] **Step 4: Let the browser show a copy cursor** — in each of the three `dragstart` handlers (~line 1925, ~line 1937, ~line 1952), change `effectAllowed = 'move'` to:

```js
            e.originalEvent.dataTransfer.effectAllowed = 'copyMove';
```

- [ ] **Step 5: Syntax-check**

Run: `node --check assets/js/file-manager.js`
Expected: no output, exit 0.

- [ ] **Step 6: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: hold Ctrl/Alt while dragging to copy instead of move"
```

---

## Manual Verification (after all tasks)

Requires a live WordPress install (no WP test harness in this repo). Ask the site owner to run these and report results — do not assume they pass.

1. **Duplicate in place:** Right-click a file → Duplicate → a `filename (copy).ext` appears in the same folder; the bytes exist on disk (open/download it). Repeat → `(copy 2)`.
2. **Each kind duplicates:** Duplicate a link, a video, and a folder; confirm `(copy)` naming and that the video copy plays (same Vimeo id) and the link copy opens.
3. **Copy / paste across folders:** Copy a file, open another folder, Paste here (folder row menu) or Ctrl/Cmd+V → file appears with its original name (no suffix) and inherits the destination folder's access.
4. **Recursive folder copy:** Copy a folder containing subfolders + files → the whole tree is duplicated, files present on disk, permissions inherit the destination.
5. **Self/descendant guard:** Copy a folder, then try to paste it into itself or one of its own subfolders → reported as an error, nothing created.
6. **Per-item resilience:** With a folder whose source file is missing on disk, copy it → that file reports `error` while the rest copy.
7. **Bulk bar:** Select 2+ items → Copy, then Paste elsewhere; and Duplicate → copies appear in the current folder.
8. **Keyboard:** Ctrl/Cmd+C / +V / +D behave as above and do NOT fire while typing in a text field or while a modal is open.
9. **Modifier-drag:** Drag a file/folder onto another folder while holding Ctrl/Alt → it is COPIED (original remains in the source); without the modifier it still MOVES.
10. **Non-admin:** A non-admin manager can copy a file they manage but cannot copy a folder (admin-only).

## Self-Review Notes

- **Spec coverage:** all four kinds (Tasks 3–6) · duplicate-in-place `(copy)` (Tasks 1–2 force_copy; dispatcher passes `folder_id===target`) · cross-folder paste keeps name, suffixes on collision (resolve_unique, Task 2) · inherit destination permissions (no perm-row copying anywhere) · context menu / keyboard / bulk bar / modifier-drag (Tasks 7–10) · self/descendant + size/depth guards (Tasks 5–6) · per-item error reporting (Task 6) · unit tests for naming (Tasks 1–2). All spec sections covered.
- **Type consistency:** copy helpers share the signature `(row, target_folder_id, &$existing, force_copy)` and return `int|WP_Error`; the dispatcher passes `((int)$row->folder_id === $target)` (or `parent_id` for folders) as `force_copy`. JS `copyItems(items, targetFolderId)` posts `items` as a JSON string + `target_folder_id`, matching the handler's `json_decode($_POST['items'])` + `(int)$_POST['target_folder_id']`. Item objects use `{kind, id}` everywhere; report rows use `{kind, sourceId, status, newId?, message?}`.

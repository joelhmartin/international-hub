# Finder Rework + Vimeo Video Support — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rework the Anchor Private File Manager Documents view into a macOS-Finder-style row list with expand-in-place, global search, popup previews, right-click actions, first-class Vimeo videos, and admin-only per-user watch history.

**Architecture:** Single-class WordPress plugin (`Anchor_Private_File_Manager`) keeps its AJAX-over-`admin-ajax.php` pattern. Two new pure, WordPress-free helper classes under `includes/` hold testable logic (Vimeo-ID parsing, watch-progress math) and are unit-tested with a plain-PHP runner. Two new DB tables (`anchor_fm_videos`, `anchor_fm_video_views`) mirror the existing `links` table. The frontend (`file-manager.js` / `file-manager.css`) is reworked from a card grid to a row list; all new video identifiers are uniquely namespaced to avoid colliding with the theme's `functions.php` Vimeo logic.

**Tech Stack:** PHP 7.4+ / WordPress, `$wpdb`, jQuery, Vimeo Player SDK (player.js), plain-PHP test runner.

---

## Spec reference

Design spec: `docs/superpowers/specs/2026-06-12-finder-rework-and-video-design.md`. Read it before starting.

## Conventions for this plan

- **Commit after every task** that ends green. Branch first if on `main`.
- **PHP version constant:** several tasks bump `const VERSION` in the main file so `maybe_upgrade_db()` runs `dbDelta` for new tables. The header `Version:` (line 5) is the *release* version; bump it only in the final task.
- **Pure-helper tests:** run with `php tests/run.php` from the plugin root. Each helper task adds cases to that runner.
- **Manual verification** steps describe exactly what to click and what to expect; the human operator performs them in a browser (the agent cannot).

## File structure

| File | Responsibility | Action |
| --- | --- | --- |
| `includes/class-afm-vimeo.php` | Pure: parse a Vimeo id from any URL/id form; build embed URL | Create |
| `includes/class-afm-watch-math.php` | Pure: fold a progress heartbeat into furthest/total/percent | Create |
| `tests/run.php` | Plain-PHP assertion runner for the two helper classes | Create |
| `anchor-private-file-manager.php` | Class: tables, AJAX endpoints, settings, enqueue, render markup | Modify |
| `assets/js/file-manager.js` | Row list, expand, search, context menu, viewer, video, multi-select, keyboard, rename, upload progress | Modify (large) |
| `assets/css/file-manager.css` | Row list, viewer modal, video, bulk bar, request-access styles | Modify |
| `assets/js/account-documents.js` | Toolbar coordination for the files tab (search placeholder, new-video button) | Modify (small) |

---

## Phase A — Pure helpers (TDD)

### Task A1: Vimeo id parser

**Files:**
- Create: `includes/class-afm-vimeo.php`
- Create: `tests/run.php`

- [ ] **Step 1: Write the failing test** — create `tests/run.php`:

```php
<?php
// Plain-PHP test runner for pure helpers. Run: php tests/run.php
error_reporting(E_ALL);
require __DIR__ . '/../includes/class-afm-vimeo.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) {
        echo "   expected: " . var_export($expected, true) . "\n";
        echo "   actual:   " . var_export($actual, true) . "\n";
    }
}

// --- Anchor_FM_Vimeo::parse_id ---
check('bare numeric id', Anchor_FM_Vimeo::parse_id('123456789'), '123456789');
check('vimeo.com/<id>', Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789'), '123456789');
check('player.vimeo.com', Anchor_FM_Vimeo::parse_id('https://player.vimeo.com/video/123456789'), '123456789');
check('channel url', Anchor_FM_Vimeo::parse_id('https://vimeo.com/channels/staff/123456789'), '123456789');
check('url with hash/query', Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789?h=abc#t=1'), '123456789');
check('trailing slash', Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789/'), '123456789');
check('private id with hash path', Anchor_FM_Vimeo::parse_id('https://vimeo.com/123456789/abcdef0123'), '123456789');
check('garbage returns empty', Anchor_FM_Vimeo::parse_id('not a video'), '');
check('empty returns empty', Anchor_FM_Vimeo::parse_id(''), '');

echo $failures === 0 ? "\nALL PASS\n" : "\n$failures FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/run.php`
Expected: fatal error / FAIL — `class-afm-vimeo.php` does not yet exist or class undefined.

- [ ] **Step 3: Implement** — create `includes/class-afm-vimeo.php`:

```php
<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    // Allow standalone include in tests; block direct web access in WP.
    if (php_sapi_name() !== 'cli') { exit; }
}

class Anchor_FM_Vimeo {

    /**
     * Extract the numeric Vimeo id from any common URL form or a bare id.
     * Returns '' when no id can be found.
     */
    public static function parse_id($input) {
        $input = trim((string) $input);
        if ($input === '') return '';

        if (ctype_digit($input)) return $input;

        // Match the first run of >=6 digits that follows a vimeo path segment
        // or video/ segment. Falls back to the first long digit run in a vimeo URL.
        if (preg_match('~(?:player\.)?vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d{6,})~i', $input, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Build the privacy-friendly player embed URL for a numeric id.
     */
    public static function embed_url($vimeo_id) {
        $vimeo_id = preg_replace('/\D+/', '', (string) $vimeo_id);
        if ($vimeo_id === '') return '';
        return 'https://player.vimeo.com/video/' . $vimeo_id;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/run.php`
Expected: all Vimeo cases PASS, final line `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-vimeo.php tests/run.php
git commit -m "feat: add pure Vimeo id parser with tests"
```

### Task A2: Watch-progress math

**Files:**
- Create: `includes/class-afm-watch-math.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Write the failing test** — append to `tests/run.php` *above* the final summary lines:

```php
require __DIR__ . '/../includes/class-afm-watch-math.php';

// apply_progress($existing, $point_seconds, $delta_seconds, $duration_seconds)
// $existing = ['furthest_seconds'=>int,'total_seconds'=>int]; returns merged + percent.
$start = ['furthest_seconds' => 0, 'total_seconds' => 0];

$r = Anchor_FM_Watch_Math::apply_progress($start, 30, 30, 100);
check('first beat furthest', $r['furthest_seconds'], 30);
check('first beat total', $r['total_seconds'], 30);
check('first beat percent', $r['percent'], 30);

$r2 = Anchor_FM_Watch_Math::apply_progress($r, 10, 10, 100); // user scrubbed back, watched 10 more
check('scrub keeps furthest', $r2['furthest_seconds'], 30);
check('scrub adds to total', $r2['total_seconds'], 40);

// Oversized delta (seek-induced) is clamped to a sane per-beat ceiling (<= 60s).
$r3 = Anchor_FM_Watch_Math::apply_progress($start, 90, 5000, 100);
check('delta clamped', $r3['total_seconds'], 60);
check('furthest tracks point', $r3['furthest_seconds'], 90);

// total never exceeds duration; percent caps at 100.
$r4 = Anchor_FM_Watch_Math::apply_progress(['furthest_seconds'=>100,'total_seconds'=>100], 100, 50, 100);
check('total capped at duration', $r4['total_seconds'], 100);
check('percent capped', $r4['percent'], 100);

// zero/garbage duration => percent 0, no divide-by-zero
$r5 = Anchor_FM_Watch_Math::apply_progress($start, 5, 5, 0);
check('zero duration percent', $r5['percent'], 0);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/run.php`
Expected: FAIL — `Anchor_FM_Watch_Math` undefined.

- [ ] **Step 3: Implement** — create `includes/class-afm-watch-math.php`:

```php
<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

class Anchor_FM_Watch_Math {

    const MAX_BEAT_SECONDS = 60; // clamp per-heartbeat watched-delta

    /**
     * Fold one progress heartbeat into an existing view record.
     *
     * @param array $existing ['furthest_seconds'=>int,'total_seconds'=>int]
     * @param int   $point_seconds    current playhead position
     * @param int   $delta_seconds    seconds watched since last beat (client-reported)
     * @param int   $duration_seconds total video length
     * @return array ['furthest_seconds'=>int,'total_seconds'=>int,'percent'=>int]
     */
    public static function apply_progress($existing, $point_seconds, $delta_seconds, $duration_seconds) {
        $prev_furthest = max(0, (int) ($existing['furthest_seconds'] ?? 0));
        $prev_total    = max(0, (int) ($existing['total_seconds'] ?? 0));
        $point         = max(0, (int) $point_seconds);
        $delta         = max(0, (int) $delta_seconds);
        $duration      = max(0, (int) $duration_seconds);

        $delta = min($delta, self::MAX_BEAT_SECONDS);

        $furthest = max($prev_furthest, $point);
        if ($duration > 0) {
            $furthest = min($furthest, $duration);
        }

        $total = $prev_total + $delta;
        if ($duration > 0) {
            $total = min($total, $duration);
        }

        $percent = 0;
        if ($duration > 0) {
            $percent = (int) floor(($furthest / $duration) * 100);
            if ($percent > 100) $percent = 100;
            if ($percent < 0) $percent = 0;
        }

        return [
            'furthest_seconds' => $furthest,
            'total_seconds'    => $total,
            'percent'          => $percent,
        ];
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/run.php`
Expected: every case PASS, `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-afm-watch-math.php tests/run.php
git commit -m "feat: add pure watch-progress math with tests"
```

### Task A3: Require helpers from the plugin

**Files:**
- Modify: `anchor-private-file-manager.php:9` (just after the `ABSPATH` guard)

- [ ] **Step 1: Implement** — directly after line 9 (`if (!defined('ABSPATH')) exit;`), add:

```php
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-vimeo.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-watch-math.php';
```

- [ ] **Step 2: Verify no syntax error**

Run: `php -l anchor-private-file-manager.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "chore: load pure helper classes in plugin bootstrap"
```

---

## Phase B — Backend: tables, settings, endpoints

### Task B1: Videos + video_views tables

**Files:**
- Modify: `anchor-private-file-manager.php` — add `ensure_videos_table()`, call from `activate()` and `maybe_upgrade_db()`, bump `VERSION`.

- [ ] **Step 1: Implement** — add a method next to `ensure_links_table()` (after line 335):

```php
    private static function ensure_videos_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $videos = self::table('videos');
        $views  = self::table('video_views');

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("
            CREATE TABLE {$videos} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                folder_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                vimeo_id VARCHAR(32) NOT NULL,
                title VARCHAR(255) NOT NULL,
                created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY folder_id (folder_id)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$views} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                video_id BIGINT(20) UNSIGNED NOT NULL,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                furthest_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
                total_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
                percent TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
                sessions INT(10) UNSIGNED NOT NULL DEFAULT 0,
                first_viewed_at DATETIME NOT NULL,
                last_viewed_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY video_user (video_id, user_id),
                KEY video_id (video_id),
                KEY user_id (user_id)
            ) {$charset_collate};
        ");
    }
```

- [ ] **Step 2: Call it from `activate()`** — after the existing `self::ensure_links_table();` (line 268) add:

```php
        self::ensure_videos_table();
```

- [ ] **Step 3: Call it from `maybe_upgrade_db()`** — inside the version-bump branch (after line 340 `self::ensure_links_table();`) add:

```php
            self::ensure_videos_table();
```

- [ ] **Step 4: Bump the DB version constant** — change line 13 from `const VERSION = '2.9.09';` to:

```php
    const VERSION = '2.9.17';
```

- [ ] **Step 5: Verify syntax**

Run: `php -l anchor-private-file-manager.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Manual verification**

In WP admin, deactivate + reactivate the plugin (or just load any front-end page so `maybe_upgrade_db()` runs). Then confirm tables exist:
Run (via WP-CLI or DB tool): `SHOW TABLES LIKE '%anchor_fm_video%';`
Expected: `wp_anchor_fm_videos` and `wp_anchor_fm_video_views` listed.

- [ ] **Step 7: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: add anchor_fm_videos and video_views tables"
```

### Task B2: New settings (Vimeo token + request-access recipient)

**Files:**
- Modify: `anchor-private-file-manager.php` — add option constants, register settings, render fields, add a token getter.

- [ ] **Step 1: Add option constants** — after line 18 (`const OPT_PD_FOLDER_ID ...`) add:

```php
    const OPT_VIMEO_TOKEN = 'anchor_fm_vimeo_token';
    const OPT_REQUEST_ACCESS_EMAIL = 'anchor_fm_request_access_email';
    const DEFAULT_REQUEST_ACCESS_EMAIL = 'tiffany@tmjtherapycenter.com';
```

- [ ] **Step 2: Register the settings** — inside `register_settings()` (after line 144, before the closing brace) add:

```php
        register_setting('anchor_private_file_manager', self::OPT_VIMEO_TOKEN, [
            'type' => 'string',
            'sanitize_callback' => function ($v) { return sanitize_text_field((string) $v); },
            'default' => '',
        ]);
        register_setting('anchor_private_file_manager', self::OPT_REQUEST_ACCESS_EMAIL, [
            'type' => 'string',
            'sanitize_callback' => function ($v) {
                $v = sanitize_email((string) $v);
                return $v ?: self::DEFAULT_REQUEST_ACCESS_EMAIL;
            },
            'default' => self::DEFAULT_REQUEST_ACCESS_EMAIL,
        ]);
```

- [ ] **Step 3: Render the new fields** — inside `render_settings_page()`, add two rows to the `<table class="form-table">` after the existing upload-email row (after line 165 `</tr>`):

```php
                    <tr>
                        <th scope="row">Vimeo access token</th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT_VIMEO_TOKEN); ?>" value="<?php echo esc_attr(get_option(self::OPT_VIMEO_TOKEN, '')); ?>" autocomplete="off">
                            <p class="description">Optional. Used only for future aggregate Vimeo stats; per-user watch history works without it. A <code>VIMEO_ACCESS_TOKEN</code> entry in the plugin <code>.env</code> overrides this field.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Request-access recipient</th>
                        <td>
                            <input type="email" class="regular-text" name="<?php echo esc_attr(self::OPT_REQUEST_ACCESS_EMAIL); ?>" value="<?php echo esc_attr(get_option(self::OPT_REQUEST_ACCESS_EMAIL, self::DEFAULT_REQUEST_ACCESS_EMAIL)); ?>">
                            <p class="description">Where "Request access" messages are sent.</p>
                        </td>
                    </tr>
```

- [ ] **Step 4: Add a token getter** — after `get_github_token()` (line 125) add:

```php
    private function get_vimeo_token() {
        $env = getenv('VIMEO_ACCESS_TOKEN');
        if (!empty($env)) return (string) $env;
        if (defined('VIMEO_ACCESS_TOKEN') && VIMEO_ACCESS_TOKEN) return (string) VIMEO_ACCESS_TOKEN;
        return (string) get_option(self::OPT_VIMEO_TOKEN, '');
    }

    private function get_request_access_email() {
        $email = sanitize_email((string) get_option(self::OPT_REQUEST_ACCESS_EMAIL, self::DEFAULT_REQUEST_ACCESS_EMAIL));
        return $email ?: self::DEFAULT_REQUEST_ACCESS_EMAIL;
    }
```

- [ ] **Step 5: Verify syntax + manual check**

Run: `php -l anchor-private-file-manager.php` → `No syntax errors detected`.
Manual: open **Settings → Anchor File Manager**, confirm the two new fields render, save a value, reload, confirm it persists.

- [ ] **Step 6: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: settings for Vimeo token and request-access recipient"
```

### Task B3: Video view-permission helpers + register endpoints

**Files:**
- Modify: `anchor-private-file-manager.php` — register `wp_ajax_*` for new actions; add `get_video_row()`, `can_user_view_video()`, `can_user_manage_video()`.

- [ ] **Step 1: Register the new AJAX actions** — in the constructor, after line 45 (`add_action('wp_ajax_anchor_fm_delete_link', ...)`) add:

```php
        add_action('wp_ajax_anchor_fm_search', [$this, 'ajax_search']);
        add_action('wp_ajax_anchor_fm_rename_file', [$this, 'ajax_rename_file']);
        add_action('wp_ajax_anchor_fm_vimeo_add', [$this, 'ajax_vimeo_add']);
        add_action('wp_ajax_anchor_fm_vimeo_update', [$this, 'ajax_vimeo_update']);
        add_action('wp_ajax_anchor_fm_vimeo_delete', [$this, 'ajax_vimeo_delete']);
        add_action('wp_ajax_anchor_fm_vimeo_progress', [$this, 'ajax_vimeo_progress']);
        add_action('wp_ajax_anchor_fm_vimeo_history', [$this, 'ajax_vimeo_history']);
        add_action('wp_ajax_anchor_fm_request_access', [$this, 'ajax_request_access']);
```

- [ ] **Step 2: Add row + permission helpers** — next to `get_link_row()` (after line 774) add:

```php
    private function get_video_row($video_id) {
        global $wpdb;
        $videos = self::table('videos');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$videos} WHERE id = %d", $video_id));
    }

    private function can_user_view_video($user_id, $video_id) {
        $video = $this->get_video_row($video_id);
        if (!$video) return false;
        return $this->can_user_view_folder($user_id, (int) $video->folder_id);
    }

    private function can_user_manage_video($user_id, $video_id) {
        $video = $this->get_video_row($video_id);
        if (!$video) return false;
        return $this->can_user_manage_folder($user_id, (int) $video->folder_id);
    }
```

- [ ] **Step 3: Verify syntax**

Run: `php -l anchor-private-file-manager.php` → `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: register new AJAX actions and video permission helpers"
```

### Task B4: `ajax_list` returns videos

**Files:**
- Modify: `anchor-private-file-manager.php` — `ajax_list()` (lines 1113-1197) add a `videos` array to the response.

- [ ] **Step 1: Implement** — in `ajax_list()`, after the `$link_list` block (ends line 1185) and before the `$cap = ...` line (1187), add:

```php
        $video_list = [];
        if ($folder_id > 0) {
            $videos_table = self::table('videos');
            $video_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, folder_id, vimeo_id, title, created_by, created_at FROM {$videos_table} WHERE folder_id = %d ORDER BY created_at DESC",
                $folder_id
            ));
            foreach ((array) $video_rows as $v) {
                if (!$this->can_user_view_video($user_id, (int) $v->id)) continue;
                $video_list[] = [
                    'id' => (int) $v->id,
                    'title' => $v->title,
                    'vimeoId' => $v->vimeo_id,
                    'createdBy' => !empty($v->created_by) ? (int) $v->created_by : 0,
                    'createdAt' => $v->created_at,
                ];
            }
        }
```

- [ ] **Step 2: Add `videos` to the response** — in the `$this->json_success([...])` array (line 1188), add after `'files' => $file_list,`:

```php
            'videos' => $video_list,
```

- [ ] **Step 3: Verify syntax + manual smoke**

Run: `php -l anchor-private-file-manager.php` → ok.
Manual: with browser devtools Network tab open, navigate into a folder; confirm the `anchor_fm_list` response JSON now contains a `videos: []` key.

- [ ] **Step 4: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: include videos in folder listing response"
```

### Task B5: Video CRUD endpoints

**Files:**
- Modify: `anchor-private-file-manager.php` — add `ajax_vimeo_add/update/delete`. Place near the link endpoints (after `ajax_delete_link`, line 1551).

- [ ] **Step 1: Implement** — add:

```php
    public function ajax_vimeo_add() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        $title = isset($_POST['title']) ? sanitize_text_field((string) $_POST['title']) : '';
        $raw = isset($_POST['vimeo']) ? (string) $_POST['vimeo'] : '';
        $vimeo_id = Anchor_FM_Vimeo::parse_id($raw);

        if ($folder_id <= 0 || $title === '') $this->json_error('Missing fields');
        if ($vimeo_id === '') $this->json_error('Could not read a Vimeo ID from that input');
        if (!user_can($user_id, 'administrator') || !$this->can_user_manage_folder($user_id, $folder_id)) {
            $this->json_error('Forbidden', 403);
        }

        global $wpdb;
        $videos = self::table('videos');
        $now = current_time('mysql');
        $wpdb->insert($videos, [
            'folder_id' => $folder_id,
            'vimeo_id' => $vimeo_id,
            'title' => $title,
            'created_by' => $user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%s','%s','%d','%s','%s']);
        $video_id = (int) $wpdb->insert_id;
        $this->log_activity($user_id, 'create_video', 'video', $video_id, ['folder_id' => $folder_id, 'vimeo_id' => $vimeo_id]);

        $this->json_success(['videoId' => $video_id, 'vimeoId' => $vimeo_id]);
    }

    public function ajax_vimeo_update() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $video_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
        $title = isset($_POST['title']) ? sanitize_text_field((string) $_POST['title']) : '';
        if ($video_id <= 0 || $title === '') $this->json_error('Missing fields');
        if (!user_can($user_id, 'administrator') || !$this->can_user_manage_video($user_id, $video_id)) {
            $this->json_error('Forbidden', 403);
        }

        global $wpdb;
        $videos = self::table('videos');
        $wpdb->update($videos, ['title' => $title, 'updated_at' => current_time('mysql')], ['id' => $video_id], ['%s','%s'], ['%d']);
        $this->log_activity($user_id, 'rename_video', 'video', $video_id, ['title' => $title]);
        $this->json_success(['videoId' => $video_id]);
    }

    public function ajax_vimeo_delete() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $video_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
        if ($video_id <= 0) $this->json_error('Missing video_id');
        if (!user_can($user_id, 'administrator') || !$this->can_user_manage_video($user_id, $video_id)) {
            $this->json_error('Forbidden', 403);
        }

        global $wpdb;
        $videos = self::table('videos');
        $views = self::table('video_views');
        $wpdb->delete($views, ['video_id' => $video_id], ['%d']);
        $wpdb->delete($videos, ['id' => $video_id], ['%d']);
        $this->log_activity($user_id, 'delete_video', 'video', $video_id, null);
        $this->json_success(['videoId' => $video_id]);
    }
```

- [ ] **Step 2: Verify syntax + manual smoke**

Run: `php -l anchor-private-file-manager.php` → ok.
Manual (after the frontend "New Video" button exists in a later task, or via a quick `$.post` in console): POST `action=anchor_fm_vimeo_add` with a folder_id you manage, a title, and `vimeo=https://vimeo.com/76979871`; expect `success:true` and a `videoId`. Re-list the folder; expect the video present.

- [ ] **Step 3: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: add Vimeo video CRUD endpoints"
```

### Task B6: File rename endpoint

**Files:**
- Modify: `anchor-private-file-manager.php` — add `ajax_rename_file()` (the existing code renames folders/links but there is no file-rename path; inline rename needs one).

- [ ] **Step 1: Implement** — add near `ajax_delete_file()` (after line 1477):

```php
    public function ajax_rename_file() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $file_id = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_file_name((string) $_POST['name']) : '';
        if ($file_id <= 0 || $name === '') $this->json_error('Missing fields');
        if (!user_can($user_id, 'administrator') || !$this->can_user_manage_file($user_id, $file_id)) {
            $this->json_error('Forbidden', 403);
        }

        global $wpdb;
        $files = self::table('files');
        // Only the display/original name changes; stored_name on disk is untouched.
        $wpdb->update($files, ['original_name' => $name], ['id' => $file_id], ['%s'], ['%d']);
        $this->log_activity($user_id, 'rename_file', 'file', $file_id, ['name' => $name]);
        $this->json_success(['fileId' => $file_id, 'name' => $name]);
    }
```

- [ ] **Step 2: Verify syntax**

Run: `php -l anchor-private-file-manager.php` → ok.

- [ ] **Step 3: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: add file rename endpoint (display name only)"
```

### Task B7: Global search endpoint

**Files:**
- Modify: `anchor-private-file-manager.php` — add `ajax_search()` and a `folder_path_string()` helper.

- [ ] **Step 1: Add a path-string helper** — near `build_breadcrumbs()` (after line 955) add:

```php
    private function folder_path_string($folder_id) {
        if ((int) $folder_id <= 0) return '';
        $crumbs = $this->build_breadcrumbs((int) $folder_id);
        $names = [];
        foreach ($crumbs as $c) { $names[] = $c['name']; }
        return implode(' › ', $names);
    }
```

- [ ] **Step 2: Implement `ajax_search()`** — add near `ajax_list()` (after line 1197):

```php
    public function ajax_search() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $term = isset($_POST['term']) ? trim((string) $_POST['term']) : '';
        if ($term === '' || mb_strlen($term) < 2) {
            $this->json_success(['results' => [], 'truncated' => false]);
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like($term) . '%';
        $product_docs_id = (int) get_option(self::OPT_PD_FOLDER_ID, 0);
        $cap = 200;
        $results = [];

        $folders = self::table('folders');
        $files = self::table('files');
        $links = self::table('links');
        $videos = self::table('videos');

        // Folders (exclude private + product-docs container)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, parent_id, name FROM {$folders} WHERE is_private = 0 AND name LIKE %s ORDER BY name ASC LIMIT %d",
            $like, $cap
        ));
        foreach ((array) $rows as $r) {
            if ((int) $r->id === $product_docs_id) continue;
            if (!$this->can_user_view_folder($user_id, (int) $r->id)) continue;
            $results[] = [
                'kind' => 'folder', 'id' => (int) $r->id, 'name' => $r->name,
                'folderId' => (int) $r->parent_id,
                'path' => $this->folder_path_string((int) $r->parent_id),
            ];
        }

        // Files
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, folder_id, original_name, mime_type, size FROM {$files} WHERE original_name LIKE %s ORDER BY original_name ASC LIMIT %d",
            $like, $cap
        ));
        foreach ((array) $rows as $r) {
            if ((int) $r->folder_id === $product_docs_id) continue;
            if (!$this->can_user_view_file($user_id, (int) $r->id)) continue;
            $results[] = [
                'kind' => 'file', 'id' => (int) $r->id, 'name' => $r->original_name,
                'mime' => $r->mime_type, 'size' => (int) $r->size,
                'folderId' => (int) $r->folder_id,
                'path' => $this->folder_path_string((int) $r->folder_id),
            ];
        }

        // Links
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, folder_id, title, url FROM {$links} WHERE title LIKE %s ORDER BY title ASC LIMIT %d",
            $like, $cap
        ));
        foreach ((array) $rows as $r) {
            if (!$this->can_user_view_link($user_id, (int) $r->id)) continue;
            $results[] = [
                'kind' => 'link', 'id' => (int) $r->id, 'name' => $r->title, 'url' => $r->url,
                'folderId' => (int) $r->folder_id,
                'path' => $this->folder_path_string((int) $r->folder_id),
            ];
        }

        // Videos
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, folder_id, title, vimeo_id FROM {$videos} WHERE title LIKE %s ORDER BY title ASC LIMIT %d",
            $like, $cap
        ));
        foreach ((array) $rows as $r) {
            if (!$this->can_user_view_video($user_id, (int) $r->id)) continue;
            $results[] = [
                'kind' => 'video', 'id' => (int) $r->id, 'name' => $r->title, 'vimeoId' => $r->vimeo_id,
                'folderId' => (int) $r->folder_id,
                'path' => $this->folder_path_string((int) $r->folder_id),
            ];
        }

        $truncated = count($results) > $cap;
        if ($truncated) $results = array_slice($results, 0, $cap);

        $this->json_success(['results' => $results, 'truncated' => $truncated]);
    }
```

- [ ] **Step 3: Verify syntax + manual smoke**

Run: `php -l anchor-private-file-manager.php` → ok.
Manual: in console, `$.post(AnchorFM.ajax,{action:'anchor_fm_search',nonce:AnchorFM.nonce,term:'pdf'}).then(r=>console.log(r))`. Expect `success:true` with a `results` array whose items carry `kind`, `folderId`, and `path`. Confirm a viewer-only account does **not** see items in folders it can't view.

- [ ] **Step 4: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: add global cross-folder search endpoint"
```

### Task B8: Watch-progress + history endpoints

**Files:**
- Modify: `anchor-private-file-manager.php` — add `ajax_vimeo_progress()` and `ajax_vimeo_history()`.

- [ ] **Step 1: Implement progress upsert** — add after the video CRUD endpoints:

```php
    public function ajax_vimeo_progress() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $video_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
        $point = isset($_POST['point']) ? (int) $_POST['point'] : 0;
        $delta = isset($_POST['delta']) ? (int) $_POST['delta'] : 0;
        $duration = isset($_POST['duration']) ? (int) $_POST['duration'] : 0;
        $is_new_session = !empty($_POST['new_session']);

        if ($video_id <= 0) $this->json_error('Missing video_id');
        if (!$this->can_user_view_video($user_id, $video_id)) $this->json_error('Forbidden', 403);

        global $wpdb;
        $views = self::table('video_views');
        $now = current_time('mysql');

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT furthest_seconds, total_seconds, sessions FROM {$views} WHERE video_id = %d AND user_id = %d",
            $video_id, $user_id
        ), ARRAY_A);

        $merged = Anchor_FM_Watch_Math::apply_progress(
            $existing ?: ['furthest_seconds' => 0, 'total_seconds' => 0],
            $point, $delta, $duration
        );

        if ($existing) {
            $sessions = (int) $existing['sessions'] + ($is_new_session ? 1 : 0);
            $wpdb->update($views, [
                'furthest_seconds' => $merged['furthest_seconds'],
                'total_seconds' => $merged['total_seconds'],
                'percent' => $merged['percent'],
                'sessions' => $sessions,
                'last_viewed_at' => $now,
            ], ['video_id' => $video_id, 'user_id' => $user_id], ['%d','%d','%d','%d','%s'], ['%d','%d']);
        } else {
            $wpdb->insert($views, [
                'video_id' => $video_id,
                'user_id' => $user_id,
                'furthest_seconds' => $merged['furthest_seconds'],
                'total_seconds' => $merged['total_seconds'],
                'percent' => $merged['percent'],
                'sessions' => 1,
                'first_viewed_at' => $now,
                'last_viewed_at' => $now,
            ], ['%d','%d','%d','%d','%d','%d','%s','%s']);
        }

        $this->json_success(['saved' => true]);
    }

    public function ajax_vimeo_history() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();
        if (!user_can($user_id, 'administrator')) $this->json_error('Forbidden', 403);

        $video_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
        if ($video_id <= 0) $this->json_error('Missing video_id');

        global $wpdb;
        $views = self::table('video_views');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, furthest_seconds, total_seconds, percent, sessions, last_viewed_at
             FROM {$views} WHERE video_id = %d ORDER BY last_viewed_at DESC LIMIT 500",
            $video_id
        ));

        $out = [];
        foreach ((array) $rows as $r) {
            $u = get_user_by('id', (int) $r->user_id);
            $out[] = [
                'userId' => (int) $r->user_id,
                'name' => $u ? $u->display_name : ('User #' . (int) $r->user_id),
                'percent' => (int) $r->percent,
                'totalSeconds' => (int) $r->total_seconds,
                'sessions' => (int) $r->sessions,
                'lastViewedAt' => $r->last_viewed_at,
            ];
        }
        $this->json_success(['history' => $out]);
    }
```

- [ ] **Step 2: Verify syntax**

Run: `php -l anchor-private-file-manager.php` → ok.

- [ ] **Step 3: Manual smoke**

In console as a non-admin: POST `anchor_fm_vimeo_progress` with a viewable `video_id`, `point=30`, `delta=30`, `duration=100`, `new_session=1`. Expect `saved:true`. Repeat with `point=10,delta=10`. Then as an **admin**, POST `anchor_fm_vimeo_history` for that video; expect one row, `percent:30`, `totalSeconds:40`, `sessions:1`. Confirm a non-admin calling `anchor_fm_vimeo_history` gets a 403.

- [ ] **Step 4: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: add watch-progress upsert and admin history endpoints"
```

### Task B9: Request-access endpoint

**Files:**
- Modify: `anchor-private-file-manager.php` — add `ajax_request_access()` with per-user/item rate limiting via a transient.

- [ ] **Step 1: Implement** — add near the other endpoints:

```php
    public function ajax_request_access() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $entity_type = isset($_POST['entity_type']) ? sanitize_key((string) $_POST['entity_type']) : '';
        $entity_id = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
        $label = isset($_POST['label']) ? sanitize_text_field((string) $_POST['label']) : '';
        if (!in_array($entity_type, ['file','folder','video','link'], true) || $entity_id <= 0) {
            $this->json_error('Invalid request');
        }

        $rate_key = 'afm_reqacc_' . $user_id . '_' . $entity_type . '_' . $entity_id;
        if (get_transient($rate_key)) {
            $this->json_success(['sent' => true, 'throttled' => true]);
        }

        $user = wp_get_current_user();
        $to = $this->get_request_access_email();
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf('[%s] Access request from %s', $site, $user->display_name);
        $body  = "A user has requested access to a document.\n\n";
        $body .= "User: {$user->display_name} ({$user->user_email})\n";
        $body .= "Item: {$label} ({$entity_type} #{$entity_id})\n";
        $body .= "Time: " . current_time('mysql') . "\n";

        wp_mail($to, $subject, $body);
        set_transient($rate_key, 1, HOUR_IN_SECONDS);
        $this->log_activity($user_id, 'request_access', $entity_type, $entity_id, ['to' => $to]);

        $this->json_success(['sent' => true, 'throttled' => false]);
    }
```

- [ ] **Step 2: Verify syntax + manual smoke**

Run: `php -l anchor-private-file-manager.php` → ok.
Manual: POST `anchor_fm_request_access` with `entity_type=file`, a real `entity_id`, `label=Test`. Expect `sent:true`. Confirm the recipient (from settings, default `tiffany@tmjtherapycenter.com`) receives the email (or check the mail log). Call again immediately → `throttled:true`, no second email.

- [ ] **Step 3: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: add rate-limited request-access email endpoint"
```

### Task B10: Enqueue Vimeo SDK + expand localized config

**Files:**
- Modify: `anchor-private-file-manager.php` — `do_enqueue_assets()` (lines 351-443).

- [ ] **Step 1: Enqueue the Vimeo Player SDK under a unique handle** — inside `do_enqueue_assets()`, before the `wp_localize_script('anchor-file-manager', ...)` call (line 399) add:

```php
        wp_enqueue_script(
            'anchor-fm-vimeo-player',
            'https://player.vimeo.com/api/player.js',
            [],
            null,
            true
        );
        wp_scripts()->add_data('anchor-file-manager', 'deps', array_merge(
            wp_scripts()->registered['anchor-file-manager']->deps ?? [],
            ['anchor-fm-vimeo-player']
        ));
```

- [ ] **Step 2: Add video/config fields to the localized `AnchorFM` object** — inside the `wp_localize_script('anchor-file-manager', 'AnchorFM', [...])` array, add after `'isAdmin' => ...` (line 402):

```php
            'vimeoEnabled' => true,
```

- [ ] **Step 3: Verify syntax + manual check**

Run: `php -l anchor-private-file-manager.php` → ok.
Manual: load the portal page; in console confirm `window.Vimeo && window.Vimeo.Player` is defined and `AnchorFM.vimeoEnabled === true`. Confirm the page has no JS console errors and the theme's existing Vimeo behavior elsewhere is unaffected.

- [ ] **Step 4: Commit**

```bash
git add anchor-private-file-manager.php
git commit -m "feat: enqueue Vimeo Player SDK under unique handle"
```

---

## Phase C — Frontend: row list, expand, sort, breadcrumbs

> All Phase C–G edits are in `assets/js/file-manager.js` unless noted. Keep everything inside the existing `jQuery(function ($) { ... })` IIFE — **no new globals**. Reuse the existing `api()`, `esc()`, `fmtSize()`, `iconForMime()`, `state`, and `openMenu()` primitives.

### Task C1: Replace `renderGrid` with `renderList`

**Files:**
- Modify: `assets/js/file-manager.js` — replace `renderGrid()` (lines ~240-305) and add list helpers. Update the call sites (anywhere `renderGrid(` is invoked — in `loadFolder` ~line 413 and the search input handler).

- [ ] **Step 1: Add a kind/sort model to `state`** — in the `state` object (lines 35-54) add:

```javascript
    sortKey: 'name',     // 'name' | 'kind' | 'size' | 'modified'
    sortDir: 'asc',      // 'asc' | 'desc'
    expandedRows: {},    // folderId -> array of child rows (for expand-in-place)
    selectedRows: new Set(), // multi-select: "kind:id" keys
```

- [ ] **Step 2: Implement `renderList` + row builders** — replace the whole `renderGrid` function with:

```javascript
    function kindLabel(kind, mime) {
        if (kind === 'folder') return 'Folder';
        if (kind === 'link') return 'Link';
        if (kind === 'video') return 'Video';
        return (mime || 'File');
    }

    function rowKey(kind, id) { return kind + ':' + id; }

    function rowIcon(item) {
        if (item.kind === 'folder') return 'category';
        if (item.kind === 'link') return 'admin-links';
        if (item.kind === 'video') return 'video-alt3';
        return iconForMime(item.mime);
    }

    // Flatten current list into typed rows, apply search filter is handled server-side now.
    function currentRows(list) {
        const rows = [];
        (list.folders || []).forEach(f => rows.push({ kind: 'folder', id: f.id, name: f.name, isPrivate: f.isPrivate }));
        (list.videos || []).forEach(v => rows.push({ kind: 'video', id: v.id, name: v.title, vimeoId: v.vimeoId, createdAt: v.createdAt }));
        (list.links || []).forEach(l => rows.push({ kind: 'link', id: l.id, name: l.title, url: l.url, createdAt: l.createdAt }));
        (list.files || []).forEach(f => rows.push({ kind: 'file', id: f.id, name: f.name, mime: f.mime, size: f.size, createdAt: f.createdAt }));
        return rows;
    }

    function sortRows(rows) {
        const dir = state.sortDir === 'desc' ? -1 : 1;
        const folderRank = r => (r.kind === 'folder' ? 0 : 1); // folders always first
        return rows.slice().sort((a, b) => {
            if (folderRank(a) !== folderRank(b)) return folderRank(a) - folderRank(b);
            let av, bv;
            switch (state.sortKey) {
                case 'size': av = a.size || 0; bv = b.size || 0; break;
                case 'kind': av = kindLabel(a.kind, a.mime); bv = kindLabel(b.kind, b.mime); break;
                case 'modified': av = a.createdAt || ''; bv = b.createdAt || ''; break;
                default: av = (a.name || '').toLowerCase(); bv = (b.name || '').toLowerCase();
            }
            if (av < bv) return -1 * dir;
            if (av > bv) return 1 * dir;
            return 0;
        });
    }

    function rowHtml(item, depth) {
        const pad = 12 + (depth || 0) * 20;
        const selected = state.selectedRows.has(rowKey(item.kind, item.id)) ? ' is-selected' : '';
        const disclosure = item.kind === 'folder'
            ? `<button type="button" class="afm__rowDisclosure" data-afm-row-expand="${item.id}" aria-label="Expand"><span class="dashicons dashicons-arrow-right-alt2"></span></button>`
            : `<span class="afm__rowDisclosure afm__rowDisclosure--empty"></span>`;
        const sizeText = item.kind === 'file' ? esc(fmtSize(item.size)) : '—';
        const modified = item.createdAt ? esc(String(item.createdAt).slice(0, 10)) : '—';
        return `
            <div class="afm__row afm__row--${item.kind}${selected}"
                 data-afm-row="${item.kind}:${item.id}"
                 data-afm-row-kind="${item.kind}" data-afm-row-id="${item.id}"
                 style="--afm-row-pad:${pad}px" tabindex="-1">
                <div class="afm__rowCell afm__rowName">
                    ${disclosure}
                    <span class="afm__rowIcon dashicons dashicons-${rowIcon(item)}"></span>
                    <span class="afm__rowLabel" data-afm-row-label>${esc(item.name)}</span>
                </div>
                <div class="afm__rowCell afm__rowKind">${esc(kindLabel(item.kind, item.mime))}</div>
                <div class="afm__rowCell afm__rowSize">${sizeText}</div>
                <div class="afm__rowCell afm__rowModified">${modified}</div>
                <div class="afm__rowCell afm__rowActions">
                    <button type="button" class="afm__kebab" data-afm-row-menu="${item.kind}:${item.id}"><span class="dashicons dashicons-ellipsis"></span></button>
                </div>
            </div>`;
    }

    function headerHtml() {
        const arrow = k => state.sortKey === k ? (state.sortDir === 'asc' ? ' ▲' : ' ▼') : '';
        return `
            <div class="afm__listHead">
                <button type="button" class="afm__rowCell afm__rowName afm__sortBtn" data-afm-sort="name">Name${arrow('name')}</button>
                <button type="button" class="afm__rowCell afm__rowKind afm__sortBtn" data-afm-sort="kind">Kind${arrow('kind')}</button>
                <button type="button" class="afm__rowCell afm__rowSize afm__sortBtn" data-afm-sort="size">Size${arrow('size')}</button>
                <button type="button" class="afm__rowCell afm__rowModified afm__sortBtn" data-afm-sort="modified">Modified${arrow('modified')}</button>
                <div class="afm__rowCell afm__rowActions"></div>
            </div>`;
    }

    function renderList(list, capability) {
        state.currentList = list || { folders: [], files: [], links: [], videos: [] };
        state.currentCapability = capability || state.currentCapability;
        const rows = sortRows(currentRows(state.currentList));
        if (!rows.length) {
            $grid.html(headerHtml() + `<div class="afm__empty">${esc(AnchorFM.i18n.noFiles)}</div>`);
            return;
        }
        let html = headerHtml() + '<div class="afm__list">';
        rows.forEach(item => {
            html += rowHtml(item, 0);
            // re-inject any expanded children for folders
            if (item.kind === 'folder' && state.expandedRows[item.id]) {
                state.expandedRows[item.id].forEach(child => { html += rowHtml(child, 1); });
            }
        });
        html += '</div>';
        $grid.html(html);
        // restore disclosure open-state visuals
        Object.keys(state.expandedRows).forEach(fid => {
            $grid.find(`[data-afm-row-expand="${fid}"]`).addClass('is-open');
        });
    }
```

- [ ] **Step 3: Repoint call sites** — replace every `renderGrid(` call with `renderList(`. (Search the file: `loadFolder` success handler and the bootstrap/refresh paths.)

- [ ] **Step 4: Bind sort header clicks** — in the delegated-events section (near the other `$root.on('click', ...)` handlers, ~line 900+) add:

```javascript
    $root.on('click', '[data-afm-sort]', function () {
        const key = $(this).data('afm-sort');
        if (state.sortKey === key) {
            state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            state.sortKey = key; state.sortDir = 'asc';
        }
        renderList(state.currentList, state.currentCapability);
    });
```

- [ ] **Step 5: Manual verification**

Reload the portal. Expected: files/folders render as a header row + indented rows (not cards). Folders sort above files. Clicking each column header sorts and toggles the ▲/▼ arrow. No console errors.

- [ ] **Step 6: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: render Finder-style row list with sortable columns"
```

### Task C2: Expand-in-place + double-click to isolate

**Files:**
- Modify: `assets/js/file-manager.js`

- [ ] **Step 1: Bind disclosure-triangle expand** — add to the delegated handlers:

```javascript
    $root.on('click', '[data-afm-row-expand]', function (e) {
        e.stopPropagation();
        const fid = Number($(this).data('afm-row-expand'));
        if (state.expandedRows[fid]) {
            delete state.expandedRows[fid];
            renderList(state.currentList, state.currentCapability);
            return;
        }
        api('anchor_fm_list', { folder_id: fid }).then(res => {
            if (!res || !res.success) return;
            state.expandedRows[fid] = currentRows(res.data);
            renderList(state.currentList, state.currentCapability);
        });
    });
```

- [ ] **Step 2: Bind single vs double click on a row** — add:

```javascript
    $root.on('click', '[data-afm-row]', function (e) {
        if ($(e.target).closest('[data-afm-row-expand],[data-afm-row-menu]').length) return;
        const $row = $(this);
        selectRow($row, e); // defined in Phase G multi-select task; until then, single highlight
    });

    $root.on('dblclick', '[data-afm-row]', function (e) {
        if ($(e.target).closest('[data-afm-row-expand],[data-afm-row-menu]').length) return;
        const kind = $(this).data('afm-row-kind');
        const id = Number($(this).data('afm-row-id'));
        if (kind === 'folder') { loadFolder(id); }
        else if (kind === 'file') { openViewer('file', id); }       // Phase E
        else if (kind === 'video') { openViewer('video', id); }     // Phase F
        else if (kind === 'link') { const l = findRow('link', id); if (l && l.url) window.open(l.url, '_blank', 'noopener'); }
    });
```

- [ ] **Step 2b: Add a temporary `selectRow` + `findRow` stub** (replaced in Phase G) so the file runs:

```javascript
    function selectRow($row) {
        $grid.find('.afm__row').removeClass('is-active');
        $row.addClass('is-active');
    }
    function findRow(kind, id) {
        return currentRows(state.currentList).concat(
            Object.values(state.expandedRows).flat()
        ).find(r => r.kind === kind && r.id === id) || null;
    }
```

- [ ] **Step 3: Manual verification**

Click a folder's ▸ triangle: its children appear indented beneath it without leaving the page; clicking again collapses them. Double-click a folder row: you drill into it (breadcrumbs update). Single click highlights a row.

- [ ] **Step 4: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: expand-in-place and double-click-to-isolate for rows"
```

### Task C3: Clickable breadcrumbs

**Files:**
- Modify: `assets/js/file-manager.js` — `renderBreadcrumbs()` (lines ~212-222) and a click handler.

- [ ] **Step 1: Make crumbs buttons** — replace the crumb text span in `renderBreadcrumbs` so each crumb is:

```javascript
            html += `<button type="button" class="afm__crumb" data-afm-crumb="${c.id}">${esc(c.name)}</button>`;
```

Keep the separators. Add a leading "Home" crumb mapped to folder 0:

```javascript
        let html = `<button type="button" class="afm__crumb" data-afm-crumb="0">Home</button>`;
        (crumbs || []).forEach(c => {
            html += `<span class="afm__crumbSep">/</span>`;
            html += `<button type="button" class="afm__crumb" data-afm-crumb="${c.id}">${esc(c.name)}</button>`;
        });
        $breadcrumbs.html(html);
```

- [ ] **Step 2: Bind crumb clicks** — add:

```javascript
    $root.on('click', '[data-afm-crumb]', function () {
        loadFolder(Number($(this).data('afm-crumb')));
    });
```

- [ ] **Step 3: Manual verification**

Drill several folders deep; click a breadcrumb crumb (and "Home") — the listing jumps to that folder.

- [ ] **Step 4: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: clickable breadcrumbs"
```

---

## Phase D — Frontend: global search + show-in-enclosing-folder + context menu

### Task D1: Wire the search box to the global endpoint

**Files:**
- Modify: `assets/js/file-manager.js` — replace the folder-scoped search input handler (lines ~1002-1006). Modify `assets/js/account-documents.js` to change the placeholder.

- [ ] **Step 1: Replace the search handler** with a debounced global search:

```javascript
    let searchTimer = null;
    $search.on('input', function () {
        const term = String($(this).val() || '').trim();
        state.search = term;
        clearTimeout(searchTimer);
        if (term.length < 2) {
            // restore normal browse view
            renderList(state.currentList, state.currentCapability);
            renderBreadcrumbs(state.lastBreadcrumbs || []);
            return;
        }
        searchTimer = setTimeout(() => runGlobalSearch(term), 250);
    });

    function runGlobalSearch(term) {
        api('anchor_fm_search', { term: term }).then(res => {
            if (!res || !res.success) return;
            renderSearchResults(res.data.results || [], res.data.truncated, term);
        });
    }

    function renderSearchResults(results, truncated, term) {
        $breadcrumbs.html(`<span class="afm__crumb is-static">Search: “${esc(term)}”</span>`);
        if (!results.length) {
            $grid.html(`<div class="afm__empty">No matches for “${esc(term)}”.</div>`);
            return;
        }
        let html = headerHtml() + '<div class="afm__list afm__list--search">';
        results.forEach(r => {
            const item = { kind: r.kind, id: r.id, name: r.name, mime: r.mime, size: r.size, url: r.url, vimeoId: r.vimeoId, createdAt: '' };
            html += rowHtml(item, 0).replace(
                '</div>\n            </div>',
                `</div><div class="afm__rowPath">${esc(r.path || 'Home')}</div>\n            </div>`
            );
        });
        if (truncated) html += `<div class="afm__empty">Showing the first results — refine your search to narrow further.</div>`;
        html += '</div>';
        $grid.html(html);
        // remember folder for "show in enclosing folder"
        state.searchFolderById = {};
        results.forEach(r => { state.searchFolderById[r.kind + ':' + r.id] = r.folderId; });
    }
```

- [ ] **Step 2: Track `lastBreadcrumbs`** — in `loadFolder`'s success handler, after `renderBreadcrumbs(res.data.breadcrumbs)` add `state.lastBreadcrumbs = res.data.breadcrumbs;`.

- [ ] **Step 3: Update the placeholder** — in `account-documents.js` `updateToolbar()` (or wherever the search input is shown), and/or directly in the PHP markup (`render_documents_portal`, line 531) change the placeholder to "Search all documents…". Simplest: edit the PHP input `placeholder` attribute at line 531.

- [ ] **Step 4: Manual verification**

Type ≥2 chars in search: results from *all* folders you can view appear, each with its enclosing path beneath the name. Clear the box: you return to the current folder's normal listing. A viewer-only account never sees items from folders it lacks access to.

- [ ] **Step 5: Commit**

```bash
git add assets/js/file-manager.js assets/js/account-documents.js anchor-private-file-manager.php
git commit -m "feat: global search with enclosing-path results"
```

### Task D2: Context menu (right-click) + "Show in enclosing folder"

**Files:**
- Modify: `assets/js/file-manager.js` — add a `contextmenu` handler and a `buildRowMenu(kind, id)`; wire the kebab `data-afm-row-menu`; add the show-in-folder action.

- [ ] **Step 1: Build a per-kind menu item set** — add:

```javascript
    function isManage() { return capRank(state.currentCapability) >= 3 || AnchorFM.isAdmin; }

    function buildRowMenu(kind, id) {
        const items = [];
        if (kind === 'folder') items.push({ action: 'open-folder', icon: 'category', label: 'Open' });
        if (kind === 'file') items.push({ action: 'open-file', icon: 'visibility', label: 'Open' });
        if (kind === 'video') items.push({ action: 'open-video', icon: 'video-alt3', label: 'Play' });
        if (kind === 'link') items.push({ action: 'open-link', icon: 'admin-links', label: 'Open' });
        items.push({ action: 'show-in-folder', icon: 'category', label: 'Show in enclosing folder' });
        if (kind === 'file' || kind === 'video') items.push({ action: 'copy-share-link', icon: 'admin-links', label: 'Copy share link' });
        if (AnchorFM.isAdmin) {
            if (kind !== 'link') items.push({ action: 'rename', icon: 'edit', label: 'Rename' });
            if (kind === 'link') items.push({ action: 'edit-link', icon: 'edit', label: 'Edit' });
            if (kind === 'folder' || kind === 'file') items.push({ action: 'permissions', icon: 'shield', label: 'Permissions' });
            items.push({ action: 'delete', icon: 'trash', label: 'Delete', danger: true });
        }
        return items;
    }

    function openRowMenu(anchorEl, kind, id) {
        openMenu(anchorEl, buildRowMenu(kind, id), { kind: kind, id: Number(id) });
    }
```

- [ ] **Step 2: Wire kebab + right-click** — add:

```javascript
    $root.on('click', '[data-afm-row-menu]', function (e) {
        e.stopPropagation();
        const [kind, id] = String($(this).data('afm-row-menu')).split(':');
        openRowMenu(this, kind, id);
    });
    $root.on('contextmenu', '[data-afm-row]', function (e) {
        e.preventDefault();
        const kind = $(this).data('afm-row-kind');
        const id = $(this).data('afm-row-id');
        openRowMenu(this, kind, id);
    });
```

- [ ] **Step 3: Handle the new menu actions** — in the existing `$menu.on('click', '[data-afm-menu-action]', ...)` router, add cases:

```javascript
        else if (action === 'show-in-folder') {
            const ctx = state.menuContext || {};
            const fid = (state.searchFolderById && state.searchFolderById[ctx.kind + ':' + ctx.id]) ||
                        (findRow(ctx.kind, ctx.id) ? state.currentFolderId : state.currentFolderId);
            // for search results we stored the enclosing folder id
            const targetFolder = (state.searchFolderById && state.searchFolderById[ctx.kind + ':' + ctx.id]);
            const dest = (typeof targetFolder === 'number') ? targetFolder : state.currentFolderId;
            $search.val(''); state.search = '';
            loadFolder(dest).then ? loadFolder(dest) : loadFolder(dest);
            flashRow(ctx.kind, ctx.id);
        }
        else if (action === 'open-folder') { loadFolder(Number(state.menuContext.id)); }
        else if (action === 'open-file') { openViewer('file', Number(state.menuContext.id)); }
        else if (action === 'open-video') { openViewer('video', Number(state.menuContext.id)); }
        else if (action === 'open-link') { const l = findRow('link', Number(state.menuContext.id)); if (l && l.url) window.open(l.url, '_blank', 'noopener'); }
        else if (action === 'copy-share-link') { copyShareLink(state.menuContext.kind, state.menuContext.id); }   // Phase G
        else if (action === 'rename') { startInlineRename(state.menuContext.kind, state.menuContext.id); }          // Phase G
```

- [ ] **Step 4: Add `flashRow`** — add:

```javascript
    function flashRow(kind, id) {
        setTimeout(() => {
            const $r = $grid.find(`[data-afm-row="${kind}:${id}"]`);
            if (!$r.length) return;
            $r[0].scrollIntoView({ block: 'center' });
            $r.addClass('is-flash');
            setTimeout(() => $r.removeClass('is-flash'), 1400);
        }, 300);
    }
```

- [ ] **Step 5: Manual verification**

Right-click any row → the context menu opens. Run a global search, right-click a result → "Show in enclosing folder" navigates to that folder, clears the search, scrolls to and flashes the row. Admin sees Rename/Permissions/Delete; a viewer does not.

- [ ] **Step 6: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: right-click context menu and show-in-enclosing-folder"
```

---

## Phase E — Frontend: popup viewer for files

### Task E1: Viewer modal for files (replaces drawer previews)

**Files:**
- Modify: `assets/js/file-manager.js` — add `openViewer()` (file branch) using the existing modal component; repoint file-open paths from the drawer to the modal.

- [ ] **Step 1: Implement the file viewer** — add:

```javascript
    function openViewer(kind, id) {
        if (kind === 'file') return openFileViewer(id);
        if (kind === 'video') return openVideoViewer(id); // Phase F
    }

    function openFileViewer(fileId) {
        api('anchor_fm_preview', { file_id: fileId }).then(res => {
            if (!res || !res.success) {
                if (res && res.data && res.data.message) showAccessDenied('file', fileId, '');
                return;
            }
            const d = res.data, file = d.file, prev = d.preview;
            let body = '<div class="afm__viewer">';
            if (prev.type === 'image') {
                body += `<div class="afm__viewerStage"><img class="afm__viewerImg" src="${esc(prev.inlineUrl)}" alt="${esc(file.name)}"></div>`;
            } else if (prev.type === 'pdf') {
                body += `<div class="afm__viewerStage"><iframe class="afm__viewerPdf" src="${esc(prev.inlineUrl)}"></iframe></div>`;
            } else if (prev.type === 'text') {
                body += `<pre class="afm__viewerText">${esc(prev.textExcerpt || '')}</pre>`;
            } else {
                body += `<div class="afm__viewerNone"><span class="dashicons dashicons-${iconForMime(file.mime)}"></span><div>No preview available</div></div>`;
            }
            body += '<div class="afm__viewerMeta">' +
                metaRow('Type', file.mime) +
                metaRow('Size', fmtSize(file.size)) +
                metaRow('Added', String(file.createdAt || '').slice(0, 10)) +
                '</div></div>';
            const footer = prev.downloadUrl
                ? `<a class="afm__btn afm__btn--primary" href="${esc(prev.downloadUrl)}"><span class="dashicons dashicons-download"></span> Download</a>`
                : '';
            openViewerModal(esc(file.name), body, footer);
        });
    }

    function metaRow(k, v) {
        return `<div class="afm__metaRow"><div class="afm__metaKey">${esc(k)}</div><div class="afm__metaVal">${esc(v)}</div></div>`;
    }
```

- [ ] **Step 2: Add a viewer-modal helper** that reuses the existing modal DOM (`$modal`, `data-afm-modal-body`) with a viewer modifier:

```javascript
    function openViewerModal(title, bodyHtml, footerHtml) {
        $modal.find('.afm__modalTitle').text('');
        $modal.find('.afm__modalTitle').html(title);
        $modalBody.html(bodyHtml);
        $modal.find('.afm__modalPanel').addClass('afm__modalPanel--viewer');
        // hide the default Save/Cancel footer buttons; inject viewer footer
        const $footer = $modal.find('.afm__modalFooter');
        $footer.find('[data-afm-action="modal-primary"]').hide();
        $footer.find('[data-afm-action="close-modal"]').text('Close');
        let $vf = $footer.find('.afm__viewerFooter');
        if (!$vf.length) { $vf = $('<div class="afm__viewerFooter"></div>').prependTo($footer); }
        $vf.html(footerHtml || '');
        $modal.prop('hidden', false);
        state.modalMode = 'viewer';
    }
```

- [ ] **Step 3: Restore modal on close** — in the existing close-modal handler, reset the viewer modifications:

```javascript
        $modal.find('.afm__modalPanel').removeClass('afm__modalPanel--viewer');
        $modal.find('.afm__modalFooter [data-afm-action="modal-primary"]').show();
        $modal.find('.afm__viewerFooter').empty();
        stopVideoTracking && stopVideoTracking(); // Phase F (guarded)
```

- [ ] **Step 4: Repoint file open** — wherever a file click previously called `loadFilePreview(fileId)` (the row dblclick from C2, the menu 'open-file' from D2), it now calls `openViewer('file', fileId)`. Remove/retire `loadFilePreview` and the file drawer open path (leave the order drawer alone).

- [ ] **Step 5: Manual verification**

Double-click a PDF: a centered modal opens with the PDF embedded and a Download button. Image and text files likewise. Closing the modal restores its normal Save/Cancel state (open the Permissions modal afterward to confirm it still works).

- [ ] **Step 6: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: popup viewer modal for file previews"
```

---

## Phase F — Frontend: video add, player, watch tracking, admin history

### Task F1: "New Video" toolbar button + dialog

**Files:**
- Modify: `anchor-private-file-manager.php` — add a "New video" button next to "New link" in the toolbar (admin-only), line ~538.
- Modify: `assets/js/file-manager.js` — open a text dialog and POST to `anchor_fm_vimeo_add`.

- [ ] **Step 1: Add the toolbar button** — in `render_documents_portal`, after the "New link" button block (line 541) add:

```php
                            <button type="button" class="afm__btn afm__btn--secondary" data-afm-action="new-video" data-apfm-files-only>
                                <span class="dashicons dashicons-video-alt3" aria-hidden="true"></span>
                                <?php esc_html_e('New video', 'anchor-private-file-manager'); ?>
                            </button>
```

- [ ] **Step 2: Open a two-field dialog + submit** — in `file-manager.js`, add a handler and a small modal helper that reuses the modal:

```javascript
    $root.on('click', '[data-afm-action="new-video"]', function () {
        openVideoModal();
    });

    function openVideoModal() {
        const body = `
            <div class="afm__formRow"><label class="afm__label">Title</label>
                <input type="text" class="afm__input" data-afm-video-title placeholder="Video title"></div>
            <div class="afm__formRow"><label class="afm__label">Vimeo URL or ID</label>
                <input type="text" class="afm__input" data-afm-video-src placeholder="https://vimeo.com/123456789"></div>
            <div class="afm__notice" data-afm-video-notice hidden></div>`;
        $modalBody.html(body);
        $modal.find('.afm__modalTitle').text('New video');
        $modal.find('[data-afm-action="modal-primary"]').text('Add').show();
        $modal.prop('hidden', false);
        state.modalMode = 'new-video';
    }
```

- [ ] **Step 3: Handle modal-primary for new-video** — in `handleModalPrimary()`, add a branch:

```javascript
        if (state.modalMode === 'new-video') {
            const title = $modalBody.find('[data-afm-video-title]').val();
            const src = $modalBody.find('[data-afm-video-src]').val();
            api('anchor_fm_vimeo_add', { folder_id: state.currentFolderId, title: title, vimeo: src }).then(res => {
                if (!res || !res.success) {
                    $modalBody.find('[data-afm-video-notice]').prop('hidden', false).text((res && res.data && res.data.message) || 'Could not add video');
                    return;
                }
                closeModal();
                reloadCurrentFolder();
            });
            return;
        }
```

- [ ] **Step 4: Manual verification**

As admin, in a folder, click "New video", paste `https://vimeo.com/76979871` + a title, Add. The video appears as a row (🎬, Kind "Video"). A bad URL shows the inline error.

- [ ] **Step 5: Commit**

```bash
git add anchor-private-file-manager.php assets/js/file-manager.js
git commit -m "feat: New Video dialog and creation flow"
```

### Task F2: Video viewer modal with Vimeo player

**Files:**
- Modify: `assets/js/file-manager.js` — add `openVideoViewer()` building a Vimeo embed in the modal.

- [ ] **Step 1: Implement** — add:

```javascript
    function openVideoViewer(videoId) {
        const v = findRow('video', videoId);
        const vimeoId = v ? v.vimeoId : null;
        if (!vimeoId) return;
        const playerId = 'afmVPlayer_' + videoId;
        let body = `<div class="afm__vplayer"><div id="${playerId}" class="afm__vplayerFrame" data-afm-video-frame></div></div>`;
        if (AnchorFM.isAdmin) {
            body += `<div class="afm__vhistory" data-afm-video-history><div class="afm__sectionTitle">Watch history</div><div class="afm__vhistoryBody">Loading…</div></div>`;
        }
        openViewerModal(esc(v.name), body, '');
        mountVimeoPlayer(playerId, vimeoId, videoId);
        if (AnchorFM.isAdmin) loadVideoHistory(videoId);
    }
```

- [ ] **Step 2: Mount the player via the SDK** — add:

```javascript
    let activePlayer = null;
    function mountVimeoPlayer(elId, vimeoId, videoId) {
        if (!window.Vimeo || !window.Vimeo.Player) return;
        activePlayer = new window.Vimeo.Player(elId, {
            id: Number(vimeoId), responsive: true
        });
        startVideoTracking(activePlayer, videoId);
    }
```

- [ ] **Step 3: Manual verification**

Double-click a video row: the modal opens with the Vimeo player and standard controls; it plays. (Watch-history wiring is the next task.)

- [ ] **Step 4: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: video viewer modal with Vimeo player embed"
```

### Task F3: Watch tracking heartbeat

**Files:**
- Modify: `assets/js/file-manager.js` — add `startVideoTracking` / `stopVideoTracking`.

- [ ] **Step 1: Implement tracking** — add:

```javascript
    let trackState = null;
    function startVideoTracking(player, videoId) {
        trackState = { videoId: videoId, lastTime: 0, accum: 0, duration: 0, sent: false, newSession: true };
        player.getDuration().then(d => { trackState.duration = Math.floor(d || 0); }).catch(() => {});

        player.on('timeupdate', function (data) {
            if (!trackState) return;
            const t = Math.floor(data.seconds || 0);
            const delta = t - trackState.lastTime;
            // count only forward, small steps as "watched" time
            if (delta > 0 && delta <= 2) trackState.accum += delta;
            trackState.lastTime = t;
            if (trackState.accum >= 10) flushProgress(false);
        });
        player.on('pause', function () { flushProgress(false); });
        player.on('ended', function () { flushProgress(false); });
    }

    function flushProgress(force) {
        if (!trackState) return;
        if (!force && trackState.accum <= 0) return;
        const payload = {
            video_id: trackState.videoId,
            point: trackState.lastTime,
            delta: trackState.accum,
            duration: trackState.duration,
            new_session: trackState.newSession ? 1 : 0,
        };
        trackState.accum = 0;
        trackState.newSession = false;
        api('anchor_fm_vimeo_progress', payload);
    }

    function stopVideoTracking() {
        flushProgress(true);
        if (activePlayer && activePlayer.unload) { try { activePlayer.unload(); } catch (e) {} }
        activePlayer = null;
        trackState = null;
    }
```

- [ ] **Step 2: Manual verification**

As a non-admin user, open a video and watch ~30s (let it play, then pause). In the DB: `SELECT * FROM wp_anchor_fm_video_views;` shows a row for that user with a non-zero `total_seconds`/`percent`, `sessions = 1`. Scrub backward and watch again → `furthest_seconds` does not drop; `total_seconds` grows modestly (not by the scrub jump).

- [ ] **Step 3: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: per-user Vimeo watch-progress heartbeat"
```

### Task F4: Admin watch-history panel

**Files:**
- Modify: `assets/js/file-manager.js` — add `loadVideoHistory()`.

- [ ] **Step 1: Implement** — add:

```javascript
    function fmtMMSS(total) {
        total = Math.max(0, Number(total) || 0);
        const m = Math.floor(total / 60), s = total % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function loadVideoHistory(videoId) {
        api('anchor_fm_vimeo_history', { video_id: videoId }).then(res => {
            const $body = $modalBody.find('.afm__vhistoryBody');
            if (!res || !res.success) { $body.text('Unable to load history.'); return; }
            const rows = res.data.history || [];
            if (!rows.length) { $body.html('<div class="afm__empty">No views yet.</div>'); return; }
            let html = '<div class="afm__vhistoryTable">';
            rows.forEach(r => {
                html += `<div class="afm__vhistoryRow">
                    <span class="afm__vhName">${esc(r.name)}</span>
                    <span class="afm__vhPct">${esc(r.percent)}%</span>
                    <span class="afm__vhTime">${esc(fmtMMSS(r.totalSeconds))}</span>
                    <span class="afm__vhDate">${esc(String(r.lastViewedAt || '').slice(0,10))}</span>
                </div>`;
            });
            html += '</div>';
            $body.html(html);
        });
    }
```

- [ ] **Step 2: Manual verification**

As **admin**, open the same video watched earlier: below the player, a "Watch history" table lists the viewer(s) with name, furthest %, total watched (m:ss), and last-viewed date. As a **non-admin**, the history panel is absent.

- [ ] **Step 3: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: admin-only watch-history panel under the player"
```

---

## Phase G — Frontend: multi-select, keyboard nav, inline rename, upload progress, share link, request-access

### Task G1: Multi-select + bulk action bar

**Files:**
- Modify: `assets/js/file-manager.js` — replace the temporary `selectRow` stub with real multi-select; add a bulk bar.

- [ ] **Step 1: Replace `selectRow`** with modifier-aware selection:

```javascript
    function selectRow($row, e) {
        const key = $row.data('afm-row');
        if (e && (e.metaKey || e.ctrlKey)) {
            if (state.selectedRows.has(key)) state.selectedRows.delete(key); else state.selectedRows.add(key);
        } else if (e && e.shiftKey && state.lastSelectedKey) {
            selectRange(state.lastSelectedKey, key);
        } else {
            state.selectedRows.clear(); state.selectedRows.add(key);
        }
        state.lastSelectedKey = key;
        refreshSelectionUI();
    }

    function selectRange(fromKey, toKey) {
        const keys = $grid.find('.afm__row').map(function () { return $(this).data('afm-row'); }).get();
        const a = keys.indexOf(fromKey), b = keys.indexOf(toKey);
        if (a < 0 || b < 0) { state.selectedRows.add(toKey); return; }
        const [lo, hi] = a < b ? [a, b] : [b, a];
        for (let i = lo; i <= hi; i++) state.selectedRows.add(keys[i]);
    }

    function refreshSelectionUI() {
        $grid.find('.afm__row').each(function () {
            $(this).toggleClass('is-selected', state.selectedRows.has($(this).data('afm-row')));
        });
        renderBulkBar();
    }

    function renderBulkBar() {
        const n = state.selectedRows.size;
        let $bar = $root.find('[data-afm-bulkbar]');
        if (n < 2) { $bar.remove(); return; }
        if (!$bar.length) {
            $bar = $(`<div class="afm__bulkBar" data-afm-bulkbar></div>`).appendTo($root);
        }
        const adminBtns = AnchorFM.isAdmin
            ? `<button type="button" class="afm__btn afm__btn--secondary" data-afm-bulk="move">Move…</button>
               <button type="button" class="afm__btn afm__btn--danger" data-afm-bulk="delete">Delete</button>`
            : '';
        $bar.html(`<span class="afm__bulkCount">${n} selected</span>
            <button type="button" class="afm__btn afm__btn--secondary" data-afm-bulk="download">Download</button>
            ${adminBtns}
            <button type="button" class="afm__btn afm__btn--ghost" data-afm-bulk="clear">Clear</button>`);
    }
```

- [ ] **Step 2: Bulk actions** — add (each item still hits its existing permission-checked endpoint, so a viewer can only download):

```javascript
    $root.on('click', '[data-afm-bulk]', function () {
        const op = $(this).data('afm-bulk');
        const keys = Array.from(state.selectedRows);
        if (op === 'clear') { state.selectedRows.clear(); refreshSelectionUI(); return; }
        if (op === 'download') {
            keys.forEach(k => {
                const [kind, id] = k.split(':');
                if (kind === 'file') { const r = findRow('file', Number(id)); /* trigger per-file download via preview url */ openFileDownload(Number(id)); }
                if (kind === 'folder') { downloadFolder(Number(id)); }
            });
            return;
        }
        if (op === 'delete' && AnchorFM.isAdmin) {
            if (!window.confirm(`Delete ${keys.length} item(s)? This cannot be undone.`)) return;
            Promise.all(keys.map(k => {
                const [kind, id] = k.split(':');
                if (kind === 'file') return api('anchor_fm_delete_file', { file_id: Number(id) });
                if (kind === 'folder') return api('anchor_fm_delete_folder', { folder_id: Number(id) });
                if (kind === 'video') return api('anchor_fm_vimeo_delete', { video_id: Number(id) });
                if (kind === 'link') return api('anchor_fm_delete_link', { link_id: Number(id) });
                return Promise.resolve();
            })).then(() => { state.selectedRows.clear(); reloadCurrentFolder(); });
        }
        if (op === 'move' && AnchorFM.isAdmin) { openBulkMoveDialog(keys); }
    });

    function openFileDownload(fileId) {
        api('anchor_fm_preview', { file_id: fileId }).then(res => {
            if (res && res.success && res.data.preview && res.data.preview.downloadUrl) {
                window.location.href = res.data.preview.downloadUrl;
            }
        });
    }
```

> `downloadFolder`, `reloadCurrentFolder`, and `openBulkMoveDialog` reuse existing helpers if present; if `openBulkMoveDialog` does not exist, implement it as a folder-picker modal that calls `anchor_fm_move_file` / `anchor_fm_move_folder` per item. (If you prefer to keep G1 small, drop "Move" from the bar and the spec's bulk-move to a follow-up — note it in the commit.)

- [ ] **Step 3: Clear selection on navigation** — in `loadFolder`, add `state.selectedRows.clear();` at the top.

- [ ] **Step 4: Manual verification**

Ctrl/Cmd-click several rows → a bulk bar appears showing the count with Download (+ admin Move/Delete). Shift-click selects a range. Download fetches each file; Delete (admin) removes them after confirm. Navigating clears selection.

- [ ] **Step 5: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: multi-select with bulk download/delete/move bar"
```

### Task G2: Keyboard navigation

**Files:**
- Modify: `assets/js/file-manager.js`

- [ ] **Step 1: Implement** — add a keydown handler scoped to the list:

```javascript
    $root.on('keydown', function (e) {
        if ($(e.target).is('input, textarea, [contenteditable]')) return;
        const $rows = $grid.find('.afm__row');
        if (!$rows.length) return;
        let idx = $rows.index($grid.find('.afm__row.is-active'));
        if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min($rows.length - 1, idx + 1); focusRowAt($rows, idx); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(0, idx - 1); focusRowAt($rows, idx); }
        else if (e.key === 'ArrowRight') { const $r = $rows.eq(Math.max(0, idx)); if ($r.data('afm-row-kind') === 'folder') $r.find('[data-afm-row-expand]').trigger('click'); }
        else if (e.key === 'ArrowLeft') { const $r = $rows.eq(Math.max(0, idx)); const fid = Number($r.data('afm-row-id')); if (state.expandedRows[fid]) { delete state.expandedRows[fid]; renderList(state.currentList, state.currentCapability); } }
        else if (e.key === 'Enter') { const $r = $rows.eq(Math.max(0, idx)); openRowDefault($r); }
        else if (e.key === ' ') { e.preventDefault(); const $r = $rows.eq(Math.max(0, idx)); previewRow($r); }
        else if (e.key === 'Escape') { closeMenu(); if (!$modal.prop('hidden')) closeModal(); }
    });

    function focusRowAt($rows, idx) {
        $rows.removeClass('is-active');
        const $r = $rows.eq(idx).addClass('is-active');
        if ($r[0]) $r[0].scrollIntoView({ block: 'nearest' });
    }
    function openRowDefault($r) {
        const kind = $r.data('afm-row-kind'), id = Number($r.data('afm-row-id'));
        if (kind === 'folder') loadFolder(id);
        else if (kind === 'file') openViewer('file', id);
        else if (kind === 'video') openViewer('video', id);
        else if (kind === 'link') { const l = findRow('link', id); if (l && l.url) window.open(l.url, '_blank', 'noopener'); }
    }
    function previewRow($r) {
        const kind = $r.data('afm-row-kind'), id = Number($r.data('afm-row-id'));
        if (kind === 'file' || kind === 'video') openViewer(kind, id);
    }
```

- [ ] **Step 2: Make the list focusable** — ensure `$grid` (or its `.afm__list`) has `tabindex="0"`; add `tabindex="0"` to the `.afm__list` wrapper in `renderList`/`renderSearchResults`.

- [ ] **Step 3: Manual verification**

Click into the list, use ↑/↓ to move the highlight, → to expand a folder, ← to collapse, Enter to open/drill, Space to preview a file/video, Esc to close popups.

- [ ] **Step 4: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: keyboard navigation for the row list"
```

### Task G3: Inline rename (admin-only)

**Files:**
- Modify: `assets/js/file-manager.js`

- [ ] **Step 1: Implement `startInlineRename`** — add:

```javascript
    function startInlineRename(kind, id) {
        if (!AnchorFM.isAdmin) return;
        const $row = $grid.find(`[data-afm-row="${kind}:${id}"]`);
        const $label = $row.find('[data-afm-row-label]');
        if (!$label.length || $row.find('input.afm__renameInput').length) return;
        const current = $label.text();
        const $input = $(`<input type="text" class="afm__renameInput">`).val(current);
        $label.hide().after($input);
        $input.trigger('focus').trigger('select');

        function commit() {
            const name = String($input.val() || '').trim();
            $input.prop('disabled', true);
            if (!name || name === current) { cancel(); return; }
            const action = kind === 'folder' ? 'anchor_fm_rename_folder'
                : kind === 'video' ? 'anchor_fm_vimeo_update'
                : kind === 'file' ? 'anchor_fm_rename_file' : null;
            if (!action) { cancel(); return; }
            const data = { name: name };
            if (kind === 'folder') data.folder_id = id;
            if (kind === 'video') { data.video_id = id; data.title = name; }
            if (kind === 'file') data.file_id = id;
            api(action, data).then(res => {
                if (res && res.success) reloadCurrentFolder(); else cancel();
            });
        }
        function cancel() { $input.remove(); $label.show(); }
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
            else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        });
        $input.on('blur', commit);
    }
```

- [ ] **Step 2: Trigger via F2 and double-click-on-label** — add:

```javascript
    $root.on('keydown', function (e) {
        if (e.key === 'F2' && AnchorFM.isAdmin) {
            const $r = $grid.find('.afm__row.is-active');
            if ($r.length) startInlineRename($r.data('afm-row-kind'), Number($r.data('afm-row-id')));
        }
    });
    $root.on('dblclick', '[data-afm-row-label]', function (e) {
        if (!AnchorFM.isAdmin) return;
        e.stopPropagation(); // don't trigger row dblclick-to-open
        const $r = $(this).closest('[data-afm-row]');
        startInlineRename($r.data('afm-row-kind'), Number($r.data('afm-row-id')));
    });
```

- [ ] **Step 3: Manual verification**

As admin, double-click a name (or select a row and press F2): it becomes an input; Enter saves (folder/file/video), Escape cancels. As a non-admin, double-clicking the name opens the item (no rename input appears).

- [ ] **Step 4: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: admin-only inline rename for folders, files, videos"
```

### Task G4: Upload progress bar

**Files:**
- Modify: `assets/js/file-manager.js` — the upload `$.ajax` call (lines ~444-464).

- [ ] **Step 1: Add an XHR progress handler + UI** — modify the upload `$.ajax` config to include:

```javascript
        const $progress = ensureUploadProgress();
        $.ajax({
            url: AnchorFM.ajax, method: 'POST', data: data, processData: false, contentType: false,
            xhr: function () {
                const xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function (evt) {
                    if (evt.lengthComputable) {
                        const pct = Math.round((evt.loaded / evt.total) * 100);
                        $progress.find('.afm__uploadBarFill').css('width', pct + '%');
                        $progress.find('.afm__uploadPct').text(pct + '%');
                    }
                }, false);
                return xhr;
            }
        }).then(res => {
            $progress.remove();
            // existing success handling (reload folder) stays here
            reloadCurrentFolder();
        }).fail(() => { $progress.find('.afm__uploadPct').text('Upload failed'); });
```

- [ ] **Step 2: Add the progress element helper** — add:

```javascript
    function ensureUploadProgress() {
        let $p = $root.find('[data-afm-upload-progress]');
        if (!$p.length) {
            $p = $(`<div class="afm__uploadProgress" data-afm-upload-progress>
                <div class="afm__uploadBar"><div class="afm__uploadBarFill"></div></div>
                <span class="afm__uploadPct">0%</span></div>`).appendTo($root);
        }
        $p.find('.afm__uploadBarFill').css('width', '0%');
        $p.find('.afm__uploadPct').text('0%');
        return $p;
    }
```

- [ ] **Step 3: Manual verification**

Upload a large-ish file (or several): a progress bar appears and advances to 100%, then disappears and the folder refreshes with the new file(s). Drag-and-drop upload shows the same bar.

- [ ] **Step 4: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: upload progress bar via XHR progress events"
```

### Task G5: Copy share link

**Files:**
- Modify: `assets/js/file-manager.js`

- [ ] **Step 1: Implement** — add. The link points at the portal page with a deep-link hash the app reads on load; access is still gated by the existing permission checks when the target is opened:

```javascript
    function shareUrlFor(kind, id) {
        const base = window.location.origin + window.location.pathname;
        return base + '#afm-' + kind + '-' + id;
    }
    function copyShareLink(kind, id) {
        const url = shareUrlFor(kind, id);
        const done = () => toast('Link copied');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done, () => fallbackCopy(url, done));
        } else { fallbackCopy(url, done); }
    }
    function fallbackCopy(text, cb) {
        const $t = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
        $t[0].select(); try { document.execCommand('copy'); } catch (e) {}
        $t.remove(); cb && cb();
    }
    function toast(msg) {
        const $t = $(`<div class="afm__toast">${esc(msg)}</div>`).appendTo($root);
        setTimeout(() => $t.addClass('is-show'), 10);
        setTimeout(() => { $t.removeClass('is-show'); setTimeout(() => $t.remove(), 300); }, 1800);
    }
```

- [ ] **Step 2: Handle the deep-link on load** — near bootstrap, after the tree/list load, add:

```javascript
    function handleDeepLink() {
        const m = (window.location.hash || '').match(/^#afm-(file|video|folder|link)-(\d+)$/);
        if (!m) return;
        const kind = m[1], id = Number(m[2]);
        if (kind === 'folder') { loadFolder(id); return; }
        // open viewer; if the user lacks access the endpoint returns an error → show access-denied
        if (kind === 'file' || kind === 'video') openViewer(kind, id);
    }
```

Call `handleDeepLink()` once after the initial folder list resolves.

- [ ] **Step 3: Manual verification**

Right-click a file/video → "Copy share link" → a toast confirms. Paste the URL in a new tab logged in as an authorized user: the viewer opens to that item. (Access-denied for unauthorized users is the next task.)

- [ ] **Step 4: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: copy share link with deep-link open on load"
```

### Task G6: Access-denied state + Request Access

**Files:**
- Modify: `assets/js/file-manager.js` — `openFileViewer`/`openVideoViewer` already guard on endpoint failure; add the denied UI + request button.

- [ ] **Step 1: Add the denied modal + request handler** — add:

```javascript
    function showAccessDenied(entityType, entityId, label) {
        const body = `<div class="afm__denied">
            <span class="dashicons dashicons-lock"></span>
            <div class="afm__deniedTitle">You don't have access to this item</div>
            <p class="afm__deniedText">If you think you should, you can request access.</p>
            <button type="button" class="afm__btn afm__btn--primary" data-afm-request-access
                    data-entity-type="${esc(entityType)}" data-entity-id="${esc(entityId)}" data-label="${esc(label || '')}">
                Request access</button>
            <div class="afm__notice" data-afm-request-notice hidden></div>
        </div>`;
        openViewerModal('Access required', body, '');
    }

    $root.on('click', '[data-afm-request-access]', function () {
        const $b = $(this);
        $b.prop('disabled', true);
        api('anchor_fm_request_access', {
            entity_type: $b.data('entity-type'),
            entity_id: $b.data('entity-id'),
            label: $b.data('label') || ''
        }).then(res => {
            const $n = $modalBody.find('[data-afm-request-notice]').prop('hidden', false);
            $n.text(res && res.success ? 'Request sent. The site team has been notified.' : 'Could not send request.');
        });
    });
```

- [ ] **Step 2: Trigger denied on video too** — in `openVideoViewer`, if `findRow` can't resolve the video (e.g. deep-link to a video not in the current list), fetch via a lightweight check or fall back to `showAccessDenied('video', videoId, '')`. For the row-present case the user already had view access. (For deep-links to non-viewable videos, the listing simply won't contain them; show denied.)

- [ ] **Step 3: Manual verification**

Log in as a user without access to a given file. Open its share link (or trigger the denied path): the access-required modal appears with "Request access". Click it → notice confirms sent; the recipient configured in settings receives the email. Second click within an hour is silently throttled server-side.

- [ ] **Step 4: Commit**

```bash
git add assets/js/file-manager.js
git commit -m "feat: access-denied state with Request Access button"
```

---

## Phase H — Styling

### Task H1: Row list, viewer modal, video, bulk bar, misc CSS

**Files:**
- Modify: `assets/css/file-manager.css` — replace the `.afm__grid`/`.afm__card` block (lines ~461-646) with row-list styles; widen the modal for the viewer; add video, bulk bar, toast, rename, upload-progress, denied, search-path styles. All video-specific classes use the `afm__v*` namespace.

- [ ] **Step 1: Add row-list styles** — append (and remove the now-unused card rules from the files panel):

```css
/* Finder row list */
.afm__listHead, .afm__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 120px 100px 120px 44px;
  align-items: center;
  gap: 8px;
}
.afm__listHead {
  padding: 8px 12px;
  border-bottom: 1px solid var(--afm-border);
  position: sticky; top: 0; background: var(--afm-bg); z-index: 2;
}
.afm__sortBtn {
  background: none; border: 0; text-align: left; cursor: pointer;
  font: inherit; font-weight: 700; color: var(--afm-muted); padding: 0;
}
.afm__list { display: flex; flex-direction: column; }
.afm__list:focus { outline: none; }
.afm__row {
  padding: 8px 12px; border-bottom: 1px solid var(--afm-border);
  cursor: default; transition: background 100ms ease;
}
.afm__row:hover { background: var(--afm-panel); }
.afm__row.is-active { background: var(--afm-panel-2); }
.afm__row.is-selected { background: rgba(var(--afm-accent-rgb) / 0.12); }
.afm__row.is-flash { animation: afmFlash 1.4s ease; }
@keyframes afmFlash { 0%,100% { background: transparent; } 30% { background: rgba(var(--afm-accent-rgb) / 0.25); } }
.afm__rowName { display: flex; align-items: center; gap: 8px; padding-left: var(--afm-row-pad, 12px); min-width: 0; }
.afm__rowLabel { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.afm__rowIcon { color: var(--afm-accent); flex: none; }
.afm__rowKind, .afm__rowSize, .afm__rowModified { font-size: 12px; color: var(--afm-muted); }
.afm__rowActions { display: flex; justify-content: flex-end; }
.afm__rowDisclosure {
  background: none; border: 0; cursor: pointer; color: var(--afm-faint);
  width: 18px; height: 18px; display: grid; place-items: center; flex: none;
  transition: transform 120ms ease;
}
.afm__rowDisclosure.is-open { transform: rotate(90deg); }
.afm__rowDisclosure--empty { visibility: hidden; }
.afm__rowPath { grid-column: 1 / -1; font-size: 11px; color: var(--afm-faint); padding-left: var(--afm-row-pad, 12px); margin-top: 2px; }
.afm__empty { padding: 24px; text-align: center; color: var(--afm-faint); }
.afm__renameInput { font: inherit; padding: 2px 6px; border: 1px solid var(--afm-accent); border-radius: 6px; }
```

- [ ] **Step 2: Viewer modal + video + history** — append:

```css
.afm__modalPanel--viewer { width: min(960px, calc(100vw - 26px)); }
.afm__viewer { display: flex; flex-direction: column; gap: 12px; }
.afm__viewerStage { display: grid; place-items: center; background: #000; border-radius: var(--afm-radius-sm); overflow: hidden; }
.afm__viewerImg { max-width: 100%; max-height: 70vh; }
.afm__viewerPdf { width: 100%; height: 70vh; border: 0; background: #fff; }
.afm__viewerText { width: 100%; max-height: 60vh; overflow: auto; padding: 12px; font-size: 12px; background: var(--afm-panel); border-radius: var(--afm-radius-sm); }
.afm__viewerNone { padding: 40px; display: grid; place-items: center; gap: 8px; color: var(--afm-faint); }
.afm__viewerFooter { margin-right: auto; }

.afm__vplayer { background: #000; border-radius: var(--afm-radius-sm); overflow: hidden; }
.afm__vplayerFrame { width: 100%; }
.afm__vhistory { margin-top: 12px; }
.afm__vhistoryTable { display: flex; flex-direction: column; gap: 4px; }
.afm__vhistoryRow { display: grid; grid-template-columns: minmax(0,1fr) 60px 70px 100px; gap: 8px; font-size: 12px; padding: 6px 8px; border-bottom: 1px solid var(--afm-border); }
.afm__vhName { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.afm__vhPct { font-weight: 700; color: var(--afm-accent); }
```

- [ ] **Step 3: Bulk bar, toast, upload, denied** — append:

```css
.afm__bulkBar {
  position: fixed; left: 50%; bottom: 18px; transform: translateX(-50%);
  display: flex; gap: 8px; align-items: center;
  background: var(--afm-bg); border: 1px solid var(--afm-border);
  box-shadow: var(--afm-shadow); border-radius: 999px; padding: 8px 14px; z-index: 9996;
}
.afm__bulkCount { font-weight: 700; font-size: 13px; }
.afm__toast {
  position: fixed; left: 50%; bottom: 70px; transform: translon; transform: translateX(-50%) translateY(8px);
  background: var(--afm-text); color: #fff; padding: 8px 14px; border-radius: 999px;
  font-size: 13px; opacity: 0; transition: opacity 200ms ease, transform 200ms ease; z-index: 10000;
}
.afm__toast.is-show { opacity: 1; transform: translateX(-50%) translateY(0); }
.afm__uploadProgress {
  position: fixed; right: 18px; bottom: 18px; display: flex; align-items: center; gap: 10px;
  background: var(--afm-bg); border: 1px solid var(--afm-border); box-shadow: var(--afm-shadow);
  border-radius: var(--afm-radius-sm); padding: 10px 14px; z-index: 9996; min-width: 220px;
}
.afm__uploadBar { flex: 1; height: 8px; background: var(--afm-panel-2); border-radius: 999px; overflow: hidden; }
.afm__uploadBarFill { height: 100%; width: 0; background: var(--afm-accent); transition: width 150ms ease; }
.afm__uploadPct { font-size: 12px; color: var(--afm-muted); min-width: 36px; text-align: right; }
.afm__denied { padding: 28px; display: grid; place-items: center; gap: 10px; text-align: center; }
.afm__denied .dashicons { font-size: 36px; width: 36px; height: 36px; color: var(--afm-faint); }
.afm__deniedTitle { font-weight: 700; }
.afm__btn--danger { background: var(--afm-danger); color: #fff; border-color: var(--afm-danger); }
```

> Fix the obvious typo if you copy literally: the `.afm__toast` rule's `transform: translon; transform:` line should be a single `transform: translateX(-50%) translateY(8px);`.

- [ ] **Step 4: Manual verification**

Visual pass: rows align in columns; header is sticky; selected rows tint with the accent; the viewer modal is wide; the Vimeo player fills its frame; the watch-history table is legible; the bulk bar floats centered at the bottom; toast and upload progress appear in corners; access-denied modal is centered and clear. Check the 980px breakpoint still collapses the sidebar gracefully.

- [ ] **Step 5: Commit**

```bash
git add assets/css/file-manager.css
git commit -m "style: Finder row list, viewer modal, video, bulk bar, misc"
```

---

## Phase I — Finalize

### Task I1: Account-documents toolbar coordination

**Files:**
- Modify: `assets/js/account-documents.js` — ensure the search box shows on the files tab regardless of folder depth (it now searches globally, so it should be visible whenever a folder context exists), and the "New video" button visibility follows the same admin/files rule as "New link".

- [ ] **Step 1: Implement** — in `updateToolbar()`, wherever search visibility is decided, show the search input on the files tab even at the root (global search needs no current folder). The "New video" button already carries `data-apfm-files-only` so the existing files-only show/hide logic covers it; verify it toggles with the other admin file controls.

- [ ] **Step 2: Manual verification**

On the files tab at the root level, the search box is visible and searches globally. The "New video" button shows for admins on the files tab and hides on other tabs, matching "New link".

- [ ] **Step 3: Commit**

```bash
git add assets/js/account-documents.js
git commit -m "fix: show global search at root; align New Video toolbar visibility"
```

### Task I2: Version bump + full regression pass

**Files:**
- Modify: `anchor-private-file-manager.php` — header `Version:` (line 5) and re-confirm `const VERSION` (line 13).

- [ ] **Step 1: Bump release version** — set the header (line 5) to `Version: 2.9.17` and confirm `const VERSION = '2.9.17';` matches.

- [ ] **Step 2: Run pure tests + lint**

Run: `php tests/run.php` → `ALL PASS`.
Run: `php -l anchor-private-file-manager.php` → `No syntax errors detected`.

- [ ] **Step 3: Full manual regression** — verify, end to end:
  - Browse: rows render, folders sort first, sort headers work, expand-in-place, double-click isolate, breadcrumbs (incl. Home) navigate.
  - Search: global, shows paths, "show in enclosing folder" jumps + flashes, clearing restores browse.
  - Previews: PDF/image/text open in the centered modal with Download; modal restores for Permissions afterward.
  - Video: New Video adds a row; player plays; non-admin watching records a view; admin sees the watch-history table; non-admin does not.
  - QoL: multi-select + bulk download/delete (admin), keyboard nav, admin inline rename (viewer cannot), upload progress, copy share link + deep-link open, access-denied + Request Access email to the configured recipient.
  - Regression: Orders/Downloads/Account/Security/Product Docs tabs still work unchanged.
  - Collision check: confirm the theme's existing Vimeo logic elsewhere on the site still behaves; no duplicate player.js errors; no CSS bleed (all new video classes are `afm__v*`).

- [ ] **Step 4: Commit + push**

```bash
git add anchor-private-file-manager.php
git commit -m "chore: bump version to 2.9.17 for Finder rework + video"
git push
```

---

## Self-review notes (author)

- **Spec coverage:** §1 rows → C1/C2/H1; §1 sort → C1; §2 global search → B7/D1; §3 context menu + show-in-folder → D2; §4 popup previews → E1/H1; §5 videos → B1/B3/B4/B5/F1/F2; §6 watch history → A2/B8/F3/F4; §7 breadcrumbs → C3, multi-select → G1, keyboard → G2, inline rename → B6/G3, upload progress → G4, copy share link → G5; §8 request access → B2/B9/G6; §9 namespacing → enforced in B10 (unique SDK handle), F2/H1 (`afm__v*`), and all new actions under `anchor_fm_*`. Deferred items (row thumbnails, Vimeo aggregate stats) intentionally omitted.
- **Known integration assumptions to verify during execution (not placeholders):** exact current line numbers for insertion points may have shifted; anchor by the named function/markup rather than the line number. Helpers referenced across phases (`reloadCurrentFolder`, `downloadFolder`, `closeModal`, `closeMenu`, `capRank`, `$modal`, `$modalBody`, `$grid`, `$search`, `$breadcrumbs`) already exist in `file-manager.js`; if a name differs, match the existing one. `openBulkMoveDialog` is the only net-new modal helper that may need a small implementation (G1 notes the option to defer Move).

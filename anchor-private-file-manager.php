<?php
/**
 * Plugin Name: Anchor Private File Manager
 * Description: Secure, modern private file manager with folders, role permissions, previews, and logging.
 * Version: 2.13.3
 * Author: Anchor Corps
 */

if (!defined('ABSPATH')) exit;
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-vimeo.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-watch-math.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-coverage.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-media-progress.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-user-import.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-copy-namer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-range.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-afm-permission-policy.php';

class Anchor_Private_File_Manager {

    const VERSION = '2.13.3';
    const NONCE_ACTION = 'anchor_fm_nonce';
    const COPY_MAX_NODES = 2000;
    const COPY_MAX_DEPTH = 50;
    /**
     * Ceiling on one bulk video import. Each entry costs an outbound oEmbed
     * call, so an unbounded paste could stall the request past PHP's timeout.
     */
    const VIMEO_BULK_MAX = 50;
    const OPT_DB_VERSION = 'anchor_fm_db_version';
    const CRON_PRUNE_RESUME = 'anchor_fm_prune_resume';
    const OPT_EMAIL_ON_UPLOAD = 'anchor_fm_email_on_upload';
    const META_PRODUCT_DOCS = '_anchor_pd_docs';
    const OPT_PD_FOLDER_ID = 'anchor_fm_pd_folder_id';
    const OPT_VIMEO_TOKEN = 'anchor_fm_vimeo_token';
    const OPT_REQUEST_ACCESS_EMAIL = 'anchor_fm_request_access_email';
    const DEFAULT_REQUEST_ACCESS_EMAIL = 'tiffany@tmjtherapycentre.com';

    private static $instance = null;
    private $portal_rendered = false;

    public function __construct() {
        add_shortcode('anchor_file_manager', [$this, 'render_file_manager']);
        add_shortcode('anchor_account_portal', [$this, 'render_account_portal']);
        add_shortcode('anchor_documents_portal', [$this, 'render_documents_portal']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('plugins_loaded', [$this, 'bootstrap_update_checker']);

        add_action('wp_ajax_anchor_fm_bootstrap', [$this, 'ajax_bootstrap']);
        add_action('wp_ajax_anchor_fm_list', [$this, 'ajax_list']);
        add_action('wp_ajax_anchor_fm_create_folder', [$this, 'ajax_create_folder']);
        add_action('wp_ajax_anchor_fm_rename_folder', [$this, 'ajax_rename_folder']);
        add_action('wp_ajax_anchor_fm_delete_folder', [$this, 'ajax_delete_folder']);

        add_action('wp_ajax_anchor_fm_upload', [$this, 'ajax_upload']);
        add_action('wp_ajax_anchor_fm_delete_file', [$this, 'ajax_delete_file']);
        add_action('wp_ajax_anchor_fm_preview', [$this, 'ajax_preview']);
        add_action('wp_ajax_anchor_fm_stream', [$this, 'ajax_stream']);
        add_action('wp_ajax_anchor_fm_move_file', [$this, 'ajax_move_file']);
        add_action('wp_ajax_anchor_fm_move_folder', [$this, 'ajax_move_folder']);
        add_action('wp_ajax_anchor_fm_copy_items', [$this, 'ajax_copy_items']);
        add_action('wp_ajax_anchor_fm_download_folder', [$this, 'ajax_download_folder']);
        add_action('wp_ajax_anchor_fm_create_link', [$this, 'ajax_create_link']);
        add_action('wp_ajax_anchor_fm_update_link', [$this, 'ajax_update_link']);
        add_action('wp_ajax_anchor_fm_delete_link', [$this, 'ajax_delete_link']);

        add_action('wp_ajax_anchor_fm_search', [$this, 'ajax_search']);
        add_action('wp_ajax_anchor_fm_rename_file', [$this, 'ajax_rename_file']);
        add_action('wp_ajax_anchor_fm_vimeo_get', [$this, 'ajax_vimeo_get']);
        add_action('wp_ajax_anchor_fm_vimeo_add', [$this, 'ajax_vimeo_add']);
        add_action('wp_ajax_anchor_fm_vimeo_resolve', [$this, 'ajax_vimeo_resolve']);
        add_action('wp_ajax_anchor_fm_vimeo_update', [$this, 'ajax_vimeo_update']);
        add_action('wp_ajax_anchor_fm_vimeo_delete', [$this, 'ajax_vimeo_delete']);
        add_action('wp_ajax_anchor_fm_vimeo_progress', [$this, 'ajax_vimeo_progress']);
        add_action('wp_ajax_anchor_fm_media_progress', [$this, 'ajax_media_progress']);
        add_action('wp_ajax_anchor_fm_media_resume', [$this, 'ajax_media_resume']);
        add_action('wp_ajax_anchor_fm_vimeo_history', [$this, 'ajax_vimeo_history']);
        add_action('wp_ajax_anchor_fm_request_access', [$this, 'ajax_request_access']);

        add_action('wp_ajax_anchor_fm_get_permissions', [$this, 'ajax_get_permissions']);
        add_action('wp_ajax_anchor_fm_set_permissions', [$this, 'ajax_set_permissions']);
        add_action('wp_ajax_anchor_fm_user_search', [$this, 'ajax_user_search']);
        add_action('wp_ajax_anchor_fm_bulk_import_users', [$this, 'ajax_bulk_import_users']);

        add_action('wp_ajax_anchor_ap_orders', [$this, 'ajax_ap_orders']);
        add_action('wp_ajax_anchor_ap_order', [$this, 'ajax_ap_order']);
        add_action('wp_ajax_anchor_ap_update_profile', [$this, 'ajax_ap_update_profile']);
        add_action('wp_ajax_anchor_ap_change_password', [$this, 'ajax_ap_change_password']);
        add_action('wp_ajax_anchor_ap_send_reset', [$this, 'ajax_ap_send_reset']);
        add_action('wp_ajax_anchor_pd_products', [$this, 'ajax_pd_products']);
        add_action('wp_ajax_anchor_pd_save_docs', [$this, 'ajax_pd_save_docs']);
        add_action('wp_ajax_anchor_pd_my_docs', [$this, 'ajax_pd_my_docs']);
        add_action('wp_ajax_anchor_pd_upload', [$this, 'ajax_pd_upload']);

        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action(self::CRON_PRUNE_RESUME, [$this, 'cron_prune_resume']);

        $this->maybe_upgrade_db();

        if (!wp_next_scheduled(self::CRON_PRUNE_RESUME)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_PRUNE_RESUME);
        }
    }

    public function bootstrap_update_checker() {
        $autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $this->maybe_load_env();

        if (!class_exists('\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
            return;
        }

        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/joelhmartin/international-hub/',
            __FILE__,
            plugin_basename(__FILE__)
        );
        $checker->setBranch('main');

        $token = $this->get_github_token();
        if (!empty($token)) {
            $checker->setAuthentication($token);
        }

        $api = $checker->getVcsApi();
        if ($api && method_exists($api, 'enableReleaseAssets')) {
            $api->enableReleaseAssets();
        }
    }

    private function maybe_load_env() {
        if (!class_exists('\\Dotenv\\Dotenv')) {
            return;
        }

        $root = plugin_dir_path(__FILE__);
        $env_path = $root . '.env';
        if (!file_exists($env_path)) {
            return;
        }

        try {
            $dotenv = \Dotenv\Dotenv::createImmutable($root);
            $dotenv->safeLoad();
        } catch (\Throwable $e) {
            // Best-effort: ignore env load failures.
        }
    }

    private function get_github_token() {
        if (defined('GITHUB_ACCESS_TOKEN') && GITHUB_ACCESS_TOKEN) {
            return (string) GITHUB_ACCESS_TOKEN;
        }
        $env = getenv('GITHUB_ACCESS_TOKEN');
        if (!empty($env)) {
            return (string) $env;
        }
        return '';
    }

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

    public function register_settings_page() {
        add_options_page(
            'Anchor Private File Manager',
            'Anchor File Manager',
            'manage_options',
            'anchor-private-file-manager',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('anchor_private_file_manager', self::OPT_EMAIL_ON_UPLOAD, [
            'type' => 'boolean',
            'sanitize_callback' => function ($value) {
                return (int) (bool) $value;
            },
            'default' => 0,
        ]);
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
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>Anchor File Manager</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('anchor_private_file_manager');
                ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Upload email notifications</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT_EMAIL_ON_UPLOAD); ?>" value="1" <?php checked((int) get_option(self::OPT_EMAIL_ON_UPLOAD, 0), 1); ?>>
                                Send an email to administrators when a file is uploaded
                            </label>
                        </td>
                    </tr>
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
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $folders = self::table('folders');
        $files = self::table('files');
        $perms = self::table('permissions');
        $policies = self::table('permission_policies');
        $activity = self::table('activity');
        // Note: comments intentionally removed in v2.1+; table kept out of new installs.

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("
            CREATE TABLE {$folders} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                parent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                name VARCHAR(190) NOT NULL,
                owner_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                is_private TINYINT(1) NOT NULL DEFAULT 0,
                created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY parent_id (parent_id),
                KEY owner_user_id (owner_user_id)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$files} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                folder_id BIGINT(20) UNSIGNED NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                stored_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                sha1 CHAR(40) NULL,
                uploader_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY folder_id (folder_id),
                KEY uploader_user_id (uploader_user_id)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$perms} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_type VARCHAR(10) NOT NULL,
                entity_id BIGINT(20) UNSIGNED NOT NULL,
                subject_type VARCHAR(10) NOT NULL,
                subject_key VARCHAR(191) NOT NULL,
                capability VARCHAR(10) NOT NULL,
                created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY entity (entity_type, entity_id),
                KEY subject (subject_type, subject_key),
                KEY capability (capability)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$policies} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_type VARCHAR(10) NOT NULL,
                entity_id BIGINT(20) UNSIGNED NOT NULL,
                capability VARCHAR(10) NOT NULL,
                policy LONGTEXT NULL,
                created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY entity_capability (entity_type, entity_id, capability),
                KEY entity (entity_type, entity_id),
                KEY capability (capability)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$activity} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                action VARCHAR(40) NOT NULL,
                entity_type VARCHAR(10) NOT NULL,
                entity_id BIGINT(20) UNSIGNED NOT NULL,
                meta LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY actor_user_id (actor_user_id),
                KEY entity (entity_type, entity_id),
                KEY created_at (created_at)
            ) {$charset_collate};
        ");

        if (get_option(self::OPT_EMAIL_ON_UPLOAD, null) === null) {
            // Disabled by default; keep notification logic available for later.
            add_option(self::OPT_EMAIL_ON_UPLOAD, 0);
        }

        self::ensure_upload_storage();
        self::ensure_product_docs_folder();
        self::ensure_links_table();
        $policies_ok = self::ensure_permission_policies_table();
        // Bump only after the schema work, and only if it succeeded — see
        // maybe_upgrade_db(). A premature bump strands the site on the legacy
        // (video_id, user_id) unique key with no retry.
        if ($policies_ok && self::ensure_videos_table()) {
            update_option(self::OPT_DB_VERSION, self::VERSION);
        }

        if (!wp_next_scheduled(self::CRON_PRUNE_RESUME)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_PRUNE_RESUME);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_PRUNE_RESUME);
    }

    private static function ensure_upload_storage() {
        $base = self::storage_base();
        if (!file_exists($base)) {
            wp_mkdir_p($base);
        }

        $htaccess = $base . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }

        $index = $base . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    private static function table($suffix) {
        global $wpdb;
        return $wpdb->prefix . 'anchor_fm_' . $suffix;
    }

    private static function table_exists($table) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.TABLES
             WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        )) > 0;
    }

    private static function ensure_permission_policies_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $policies = self::table('permission_policies');

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("
            CREATE TABLE {$policies} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_type VARCHAR(10) NOT NULL,
                entity_id BIGINT(20) UNSIGNED NOT NULL,
                capability VARCHAR(10) NOT NULL,
                policy LONGTEXT NULL,
                created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY entity_capability (entity_type, entity_id, capability),
                KEY entity (entity_type, entity_id),
                KEY capability (capability)
            ) {$charset_collate};
        ");

        return self::table_exists($policies);
    }

    private static function ensure_product_docs_folder() {
        $folder_id = (int) get_option(self::OPT_PD_FOLDER_ID, 0);
        if ($folder_id > 0) return $folder_id;

        global $wpdb;
        $folders = self::table('folders');
        $now = current_time('mysql');
        $wpdb->insert($folders, [
            'parent_id' => 0,
            'name' => 'Product Docs',
            'owner_user_id' => 0,
            'is_private' => 0,
            'created_by' => get_current_user_id() ?: 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $folder_id = (int) $wpdb->insert_id;
        update_option(self::OPT_PD_FOLDER_ID, $folder_id);
        return $folder_id;
    }

    private static function ensure_links_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $links = self::table('links');

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("
            CREATE TABLE {$links} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                folder_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                title VARCHAR(255) NOT NULL,
                url TEXT NOT NULL,
                created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY folder_id (folder_id)
            ) {$charset_collate};
        ");
    }

    /**
     * Whether a named index currently exists on the video_views table.
     * Used both to gate dropping the legacy key and to decide whether the
     * stored db version may advance.
     */
    private static function views_index_exists($index_name) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
            self::table('video_views'), $index_name
        )) > 0;
    }

    /**
     * Whether a named column currently exists on the video_views table.
     * The index check alone cannot gate the 2.12.0 upgrade: source_video_user
     * is already present on every 2.11.x site, so it proves nothing about the
     * columns coverage tracking needs.
     */
    private static function views_column_exists($column) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
            self::table('video_views'), $column
        )) > 0;
    }

    /**
     * @return bool True when the (source, video_id, user_id) unique key AND
     *              the coverage columns all exist after this call. False means
     *              dbDelta did not manage to apply the schema on this host,
     *              and the caller MUST NOT record the upgrade as done --
     *              otherwise the migration never retries, every file-source
     *              progress write collides with the legacy key, and every
     *              heartbeat 500s against the missing coverage columns.
     */
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
                vimeo_hash VARCHAR(64) NOT NULL DEFAULT '',
                title VARCHAR(255) NOT NULL,
                thumbnail_url VARCHAR(255) NOT NULL DEFAULT '',
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
                source VARCHAR(10) NOT NULL DEFAULT 'vimeo',
                video_id BIGINT(20) UNSIGNED NOT NULL,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                furthest_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
                total_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
                resume_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
                watched_bits MEDIUMBLOB NULL,
                duration_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
                percent TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
                sessions INT(10) UNSIGNED NOT NULL DEFAULT 0,
                first_viewed_at DATETIME NOT NULL,
                last_viewed_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source_video_user (source, video_id, user_id),
                KEY video_id (video_id),
                KEY user_id (user_id)
            ) {$charset_collate};
        ");

        // Order matters: drop_legacy_views_key() first, so the legacy key is
        // still never dropped before its replacement exists, then confirm the
        // coverage columns actually landed.
        $key_ok = self::drop_legacy_views_key();

        return $key_ok
            && self::views_column_exists('watched_bits')
            && self::views_column_exists('duration_seconds');
    }

    /**
     * Drop the pre-2.11.0 UNIQUE KEY (video_id, user_id).
     *
     * It is replaced by (source, video_id, user_id); leaving it in place would
     * wrongly forbid a file row and a Vimeo row that happen to share an id.
     * Runs only after dbDelta has created the replacement, so the table is
     * never left without a uniqueness guard. Idempotent — safe to run twice.
     *
     * @return bool Whether source_video_user exists, i.e. whether the schema
     *              migration actually landed.
     */
    private static function drop_legacy_views_key() {
        global $wpdb;
        $views = self::table('video_views');

        if (!self::views_index_exists('source_video_user')) {
            return false; // replacement missing — leave the old key alone
        }

        if (self::views_index_exists('video_user')) {
            $wpdb->query("ALTER TABLE {$views} DROP INDEX video_user");
        }
        return true;
    }

    /**
     * Clear pre-coverage watch percentages.
     *
     * Before 2.12.0 `percent` measured the furthest point the scrubber
     * reached, and `total_seconds` counted time elapsed in the player. Neither
     * can be converted into "which seconds were actually played", so both are
     * reset rather than carried forward as an invented number. Everyone reads
     * 0% until they watch again — intended, and chosen deliberately over
     * seeding coverage as 0..furthest_seconds, which would have preserved the
     * look of the report by fabricating data.
     *
     * resume_seconds, furthest_seconds, sessions and the timestamps are left
     * untouched.
     *
     * @return bool False when the UPDATE failed (an unknown column on a host
     *              where dbDelta did not apply cleanly). The caller must not
     *              bump the version on a false, or the reset never retries and
     *              the stale pre-coverage percentages stand forever.
     */
    private static function reset_pre_coverage_watch_stats() {
        global $wpdb;
        $views = self::table('video_views');
        $result = $wpdb->query("UPDATE {$views} SET percent = 0, total_seconds = 0, watched_bits = NULL");
        // query() returns int 0 when no rows matched -- an empty table, which
        // is success. Only an exact false is a failure.
        return $result !== false;
    }

    private function maybe_upgrade_db() {
        $installed = (string) get_option(self::OPT_DB_VERSION, '0');
        if (version_compare($installed, self::VERSION, '<')) {
            // Whether this site predates coverage tracking. Must be captured
            // before the option is bumped, and applied only once.
            $pre_coverage = version_compare($installed, '2.12.0', '<');

            self::ensure_links_table();
            $policies_ok = self::ensure_permission_policies_table();
            $views_ok = self::ensure_videos_table();

            if ($views_ok && $pre_coverage) {
                $views_ok = self::reset_pre_coverage_watch_stats();
            }
            if ($views_ok && $policies_ok) {
                update_option(self::OPT_DB_VERSION, self::VERSION);
            }
        }
    }

    public function enqueue_assets() {
        if (!is_user_logged_in()) return;
        if (!$this->should_enqueue_assets()) return;
        $this->do_enqueue_assets();
    }

    private function do_enqueue_assets() {
        if (wp_script_is('anchor-file-manager', 'enqueued')) return;

        $css_path = plugin_dir_path(__FILE__) . 'assets/css/file-manager.css';
        $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : self::VERSION;
        $js_path = plugin_dir_path(__FILE__) . 'assets/js/file-manager.js';
        $js_ver = file_exists($js_path) ? (string) filemtime($js_path) : self::VERSION;

        $ap_css_path = plugin_dir_path(__FILE__) . 'assets/css/account-portal.css';
        $ap_css_ver = file_exists($ap_css_path) ? (string) filemtime($ap_css_path) : self::VERSION;
        $portal_js_path = plugin_dir_path(__FILE__) . 'assets/js/account-documents.js';
        $portal_js_ver = file_exists($portal_js_path) ? (string) filemtime($portal_js_path) : self::VERSION;

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'anchor-file-manager',
            plugin_dir_url(__FILE__) . 'assets/css/file-manager.css',
            [],
            $css_ver
        );
        wp_enqueue_script(
            'anchor-file-manager',
            plugin_dir_url(__FILE__) . 'assets/js/file-manager.js',
            ['jquery'],
            $js_ver,
            true
        );

        $user = wp_get_current_user();
        $product_docs_id = (int) get_option(self::OPT_PD_FOLDER_ID, 0);
        if ($product_docs_id === 0 && current_user_can('administrator')) {
            $product_docs_id = (int) self::ensure_product_docs_folder();
        }

        wp_enqueue_style(
            'anchor-account-portal',
            plugin_dir_url(__FILE__) . 'assets/css/account-portal.css',
            ['anchor-file-manager'],
            $ap_css_ver
        );
        wp_enqueue_script(
            'anchor-documents-portal',
            plugin_dir_url(__FILE__) . 'assets/js/account-documents.js',
            ['jquery', 'anchor-file-manager'],
            $portal_js_ver,
            true
        );

        wp_enqueue_script(
            'anchor-fm-vimeo-player',
            'https://player.vimeo.com/api/player.js',
            [],
            null,
            true
        );

        wp_localize_script('anchor-file-manager', 'AnchorFM', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'isAdmin' => current_user_can('administrator'),
            'vimeoEnabled' => true,
            'productDocsFolderId' => $product_docs_id,
            'user' => [
                'id' => get_current_user_id(),
                'roles' => array_values((array) $user->roles),
                'displayName' => $user->display_name,
            ],
            'roles' => $this->get_editable_roles_for_permissions(),
            'defaultRole' => (string) get_option('default_role'),
            'i18n' => [
                'title' => __('File Manager', 'anchor-private-file-manager'),
                'upload' => __('Upload', 'anchor-private-file-manager'),
                'newFolder' => __('New folder', 'anchor-private-file-manager'),
                'rename' => __('Rename', 'anchor-private-file-manager'),
                'delete' => __('Delete', 'anchor-private-file-manager'),
                'permissions' => __('Permissions', 'anchor-private-file-manager'),
                'download' => __('Download', 'anchor-private-file-manager'),
                'noFiles' => __('No files here yet.', 'anchor-private-file-manager'),
                'noFolders' => __('No folders.', 'anchor-private-file-manager'),
                'productDocs' => __('Product Docs', 'anchor-private-file-manager'),
                'addUsers' => __('Add Users', 'anchor-private-file-manager'),
            ],
        ]);

        wp_localize_script('anchor-documents-portal', 'AnchorAP', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'user' => [
                'id' => get_current_user_id(),
                'displayName' => $user->display_name,
                'email' => $user->user_email,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
            ],
            'hasWoo' => function_exists('wc_get_orders'),
            'i18n' => [
                'orders' => __('Orders', 'anchor-private-file-manager'),
                'account' => __('Account', 'anchor-private-file-manager'),
                'security' => __('Security', 'anchor-private-file-manager'),
                'downloads' => __('Downloads', 'anchor-private-file-manager'),
                'files' => __('Files', 'anchor-private-file-manager'),
            ],
        ]);
    }

    public function render_file_manager() {
        return $this->render_documents_portal();
    }

    public function render_account_portal() {
        return $this->render_documents_portal();
    }

    public function render_documents_portal() {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to access your documents.</p>';
        }

        if ($this->portal_rendered) {
            return '';
        }
        $this->portal_rendered = true;

        $this->do_enqueue_assets();

        $user = wp_get_current_user();

        ob_start(); ?>
        <div class="afm aap" data-apfm data-afm>
            <div class="afm__frame">
                <aside class="afm__sidebar" aria-label="<?php esc_attr_e('Account navigation and folders', 'anchor-private-file-manager'); ?>">
                    <div class="afm__brand">
                        <img class="afm__brandMark" src="https://tmjtherapycentre.com/wp-content/uploads/2023/02/TMJ_INT_Favicon_96x96.png" aria-hidden="true"></img>
                        <div class="afm__brandText">
                            <div class="afm__brandTitle"><?php esc_html_e('My Account', 'anchor-private-file-manager'); ?></div>
                            <div class="afm__brandSub"><?php echo esc_html($user->display_name); ?></div>
                        </div>
                    </div>
                    <nav class="aap__nav" aria-label="<?php esc_attr_e('Sections', 'anchor-private-file-manager'); ?>">
                        <button type="button" class="aap__navItem is-active" data-apfm-tab="files">
                            <span class="dashicons dashicons-category" aria-hidden="true"></span>
                            <?php esc_html_e('Documents', 'anchor-private-file-manager'); ?>
                        </button>
                        <button type="button" class="aap__navItem" data-apfm-tab="orders">
                            <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                            <?php esc_html_e('Orders', 'anchor-private-file-manager'); ?>
                        </button>
                        <button type="button" class="aap__navItem" data-apfm-tab="downloads">
                            <span class="dashicons dashicons-download" aria-hidden="true"></span>
                            <?php esc_html_e('Downloads', 'anchor-private-file-manager'); ?>
                        </button>
                        <?php if (current_user_can('administrator')) : ?>
                        <button type="button" class="aap__navItem" data-apfm-tab="product-docs">
                            <span class="dashicons dashicons-portfolio" aria-hidden="true"></span>
                            <?php esc_html_e('Product Docs', 'anchor-private-file-manager'); ?>
                        </button>
                        <?php endif; ?>
                        <?php if (current_user_can('administrator')) : ?>
                        <button type="button" class="aap__navItem" data-apfm-tab="users">
                            <span class="dashicons dashicons-groups" aria-hidden="true"></span>
                            <?php esc_html_e('Add Users', 'anchor-private-file-manager'); ?>
                        </button>
                        <?php endif; ?>
                        <button type="button" class="aap__navItem" data-apfm-tab="account">
                            <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                            <?php esc_html_e('Account', 'anchor-private-file-manager'); ?>
                        </button>
                        <button type="button" class="aap__navItem" data-apfm-tab="security">
                            <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                            <?php esc_html_e('Security', 'anchor-private-file-manager'); ?>
                        </button>
                        <a class="aap__navItem aap__navItem--link" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
                            <span class="dashicons dashicons-exit" aria-hidden="true"></span>
                            <?php esc_html_e('Log out', 'anchor-private-file-manager'); ?>
                        </a>
                    </nav>
                    <div class="afm__treeScroll">
                        <div class="afm__tree" data-afm-tree></div>
                    </div>
                    <div class="afm__resizer" data-afm-resizer aria-hidden="true"></div>
                </aside>

                <main class="afm__main" aria-label="<?php esc_attr_e('Account content', 'anchor-private-file-manager'); ?>">
                    <?php
                    /*
                     * Utility bar: admin-only actions, kept out of the way of the
                     * everyday header below. Every action here — including
                     * Upload — is administrator-gated server-side
                     * (can_user_upload_to_folder() is an administrator check),
                     * so no other role has anything to put in this bar and it
                     * is not rendered for them at all.
                     *
                     * The :has() rule in the CSS additionally collapses it on
                     * tabs where its files-only children are hidden, so an
                     * admin on the Account tab gets no empty strip.
                     */
                    ?>
                    <?php if (current_user_can('administrator')) : ?>
                    <div class="afm__utilityBar" data-afm-utility-bar>
                        <button type="button" class="afm__utilityLink" data-afm-action="new-folder" data-apfm-files-only>
                            <span class="dashicons dashicons-plus" aria-hidden="true"></span>
                            <?php esc_html_e('New folder', 'anchor-private-file-manager'); ?>
                        </button>
                        <button type="button" class="afm__utilityLink" data-afm-action="new-link" data-apfm-files-only>
                            <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                            <?php esc_html_e('New link', 'anchor-private-file-manager'); ?>
                        </button>
                        <button type="button" class="afm__utilityLink" data-afm-action="new-video" data-apfm-files-only>
                            <span class="dashicons dashicons-video-alt3" aria-hidden="true"></span>
                            <?php esc_html_e('New video', 'anchor-private-file-manager'); ?>
                        </button>
                        <div class="afm__upload" data-apfm-upload hidden>
                            <input type="file" multiple class="afm__fileInput" data-afm-file-input>
                            <button type="button" class="afm__btn afm__btn--primary" data-afm-action="upload">
                                <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                                <?php esc_html_e('Upload', 'anchor-private-file-manager'); ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <header class="afm__toolbar">
                        <div class="afm__breadcrumbs">
                            <span class="aap__title" data-apfm-title><?php esc_html_e('Documents', 'anchor-private-file-manager'); ?></span>
                            <div class="afm__breadcrumbsTrail" data-afm-breadcrumbs></div>
                        </div>
                        <label class="afm__search" data-apfm-search hidden>
                            <span class="dashicons dashicons-search" aria-hidden="true"></span>
                            <input type="search" placeholder="<?php esc_attr_e('Search all documents…', 'anchor-private-file-manager'); ?>" data-afm-search>
                        </label>
                    </header>

                    <section class="afm__content">
                        <div class="afm__panel is-active" data-apfm-panel="files" data-afm-panel="files">
                            <div class="afm__dropzone" data-afm-dropzone>
                                <div class="afm__dropzoneInner">
                                    <div class="afm__dropIcon dashicons dashicons-cloud-upload" aria-hidden="true"></div>
                                    <div class="afm__dropTitle"><?php esc_html_e('Drop files to upload', 'anchor-private-file-manager'); ?></div>
                                    <div class="afm__dropHint"><?php esc_html_e('Or use the Upload button', 'anchor-private-file-manager'); ?></div>
                                </div>
                            </div>
                            <div class="afm__grid" data-afm-grid></div>
                        </div>

                        <div class="afm__panel aap__panel" data-apfm-panel="orders">
                            <div class="aap__grid" data-aap-orders></div>
                        </div>

                        <div class="afm__panel aap__panel" data-apfm-panel="downloads">
                            <div class="aap__grid" data-aap-downloads></div>
                        </div>

                        <?php if (current_user_can('administrator')) : ?>
                        <div class="afm__panel aap__panel" data-apfm-panel="product-docs" data-afm-panel="product-docs">
                            <div class="afm__twoCol">
                                <div class="afm__cardBox">
                                    <div class="afm__sectionTitle"><?php esc_html_e('Product Documents', 'anchor-private-file-manager'); ?></div>
                                    <div class="afm__grid" data-afm-product-docs></div>
                                </div>
                                <div class="afm__cardBox">
                                    <div class="afm__sectionTitle"><?php esc_html_e('Assign documents to products', 'anchor-private-file-manager'); ?></div>
                                    <div class="afm__formRow">
                                        <label class="afm__label"><?php esc_html_e('Select product', 'anchor-private-file-manager'); ?></label>
                                        <select class="afm__select" data-afm-product-select></select>
                                    </div>
                                    <div class="afm__productDocsManage" data-afm-product-docs-manage></div>
                                    <button type="button" class="afm__btn afm__btn--primary" data-afm-action="save-product-docs">
                                        <span class="dashicons dashicons-saved" aria-hidden="true"></span>
                                        <?php esc_html_e('Save product documents', 'anchor-private-file-manager'); ?>
                                    </button>
                                    <div class="afm__notice" data-afm-product-docs-notice hidden></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

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

                        <div class="afm__panel aap__panel" data-apfm-panel="account">
                            <form class="aap__form" data-aap-profile-form>
                                <div class="aap__formRow">
                                    <label class="aap__label"><?php esc_html_e('First name', 'anchor-private-file-manager'); ?></label>
                                    <input type="text" class="afm__input" name="first_name" value="<?php echo esc_attr($user->first_name); ?>">
                                </div>
                                <div class="aap__formRow">
                                    <label class="aap__label"><?php esc_html_e('Last name', 'anchor-private-file-manager'); ?></label>
                                    <input type="text" class="afm__input" name="last_name" value="<?php echo esc_attr($user->last_name); ?>">
                                </div>
                                <div class="aap__formRow">
                                    <label class="aap__label"><?php esc_html_e('Email', 'anchor-private-file-manager'); ?></label>
                                    <input type="email" class="afm__input" name="user_email" value="<?php echo esc_attr($user->user_email); ?>">
                                </div>
                                <button type="submit" class="afm__btn afm__btn--primary">
                                    <span class="dashicons dashicons-saved" aria-hidden="true"></span>
                                    <?php esc_html_e('Save changes', 'anchor-private-file-manager'); ?>
                                </button>
                                <div class="aap__notice" data-aap-profile-notice hidden></div>
                            </form>
                        </div>

                        <div class="afm__panel aap__panel" data-apfm-panel="security">
                            <div class="aap__stack">
                                <form class="aap__form" data-aap-password-form>
                                    <div class="aap__formRow">
                                        <label class="aap__label"><?php esc_html_e('New password', 'anchor-private-file-manager'); ?></label>
                                        <input type="password" class="afm__input" name="new_password" autocomplete="new-password">
                                    </div>
                                    <button type="submit" class="afm__btn afm__btn--primary">
                                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                                        <?php esc_html_e('Change password', 'anchor-private-file-manager'); ?>
                                    </button>
                                    <div class="aap__notice" data-aap-password-notice hidden></div>
                                </form>

                                <div class="aap__divider"></div>

                                <div class="aap__reset">
                                    <div class="aap__help">
                                        <?php esc_html_e('Send a password reset link to your email.', 'anchor-private-file-manager'); ?>
                                    </div>
                                    <button type="button" class="afm__btn afm__btn--secondary" data-aap-action="send-reset">
                                        <span class="dashicons dashicons-email" aria-hidden="true"></span>
                                        <?php esc_html_e('Email reset link', 'anchor-private-file-manager'); ?>
                                    </button>
                                    <div class="aap__notice" data-aap-reset-notice hidden></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <aside class="afm__drawer" data-afm-drawer aria-label="<?php esc_attr_e('Details', 'anchor-private-file-manager'); ?>">
                        <div class="afm__drawerHeader">
                            <div class="afm__drawerTitle" data-afm-drawer-title><?php esc_html_e('Select a file', 'anchor-private-file-manager'); ?></div>
                            <button type="button" class="afm__iconBtn" data-afm-action="close-drawer" aria-label="<?php esc_attr_e('Close', 'anchor-private-file-manager'); ?>">
                                <span class="dashicons dashicons-no" aria-hidden="true"></span>
                            </button>
                        </div>
                        <div class="afm__drawerBody">
                            <div class="afm__preview" data-afm-preview></div>
                            <div class="afm__meta" data-afm-meta></div>
                            <div class="afm__drawerActions" data-afm-drawer-actions></div>
                        </div>
                    </aside>

                    <aside class="afm__drawer" data-aap-drawer aria-label="<?php esc_attr_e('Order details', 'anchor-private-file-manager'); ?>">
                        <div class="afm__drawerHeader">
                            <div class="afm__drawerTitle" data-aap-drawer-title><?php esc_html_e('Order', 'anchor-private-file-manager'); ?></div>
                            <button type="button" class="afm__iconBtn" data-aap-action="close-drawer" aria-label="<?php esc_attr_e('Close', 'anchor-private-file-manager'); ?>">
                                <span class="dashicons dashicons-no" aria-hidden="true"></span>
                            </button>
                        </div>
                        <div class="afm__drawerBody">
                            <div class="afm__meta" data-aap-order-meta></div>
                            <div class="aap__items" data-aap-order-items></div>
                        </div>
                    </aside>
                </main>
            </div>

            <div class="afm__modal" data-afm-modal hidden>
                <div class="afm__modalBackdrop" data-afm-action="close-modal"></div>
                <div class="afm__modalPanel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Share', 'anchor-private-file-manager'); ?>">
                    <div class="afm__modalHeader">
                        <div class="afm__modalTitle"><?php esc_html_e('Permissions', 'anchor-private-file-manager'); ?></div>
                        <button type="button" class="afm__iconBtn" data-afm-action="close-modal" aria-label="<?php esc_attr_e('Close', 'anchor-private-file-manager'); ?>">
                            <span class="dashicons dashicons-no" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div class="afm__modalBody" data-afm-modal-body></div>
                    <div class="afm__modalFooter">
                        <button type="button" class="afm__btn afm__btn--ghost" data-afm-action="close-modal"><?php esc_html_e('Cancel', 'anchor-private-file-manager'); ?></button>
                        <button type="button" class="afm__btn afm__btn--primary" data-afm-action="modal-primary"><?php esc_html_e('Save', 'anchor-private-file-manager'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function should_enqueue_assets() {
        if (is_admin()) return false;
        if (!is_singular()) return false;
        $post = get_post();
        if (!$post) return false;
        $content = (string) $post->post_content;
        return has_shortcode($content, 'anchor_file_manager')
            || has_shortcode($content, 'anchor_account_portal')
            || has_shortcode($content, 'anchor_documents_portal');
    }
    private function user_can_access_anything($user_id) {
        if (user_can($user_id, 'administrator')) {
            return true;
        }

        global $wpdb;
        $perms = self::table('permissions');
        $user_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$perms} WHERE subject_type = 'user' AND capability = 'view' AND subject_key = %s",
            (string) (int) $user_id
        ));
        if ($user_count > 0) return true;

        $roles = $this->user_roles_lower($user_id);
        $role_keys = $roles ? array_map('sanitize_key', $roles) : [];
        if ($role_keys) {
            $placeholders = implode(',', array_fill(0, count($role_keys), '%s'));
            $query = "SELECT COUNT(1) FROM {$perms} WHERE subject_type = 'role' AND capability = 'view' AND subject_key IN ({$placeholders})";
            $args = array_merge([$query], $role_keys);
            $sql = call_user_func_array([$wpdb, 'prepare'], $args);
            $count = (int) $wpdb->get_var($sql);
            if ($count > 0) return true;
        }

        $policies = self::table('permission_policies');
        $rows = $wpdb->get_col("SELECT policy FROM {$policies} WHERE capability = 'view'");
        foreach ((array) $rows as $raw) {
            $policy = $this->normalize_permission_policy($raw);
            if (Anchor_FM_Permission_Policy::evaluate($policy, $this->permission_policy_context($user_id), current_time('Y-m-d'))) {
                return true;
            }
        }

        return false;
    }

    private function get_storage_dir() {
        return self::storage_base();
    }

    /**
     * Absolute filesystem path of the private file store.
     *
     * SECURITY: this store MUST live outside the web root. The per-folder
     * .htaccess files this plugin writes are inert on Nginx (which Kinsta
     * runs), so a store under wp-content/uploads is served directly to
     * anonymous visitors. Override with the ANCHOR_FM_STORAGE_DIR constant
     * in wp-config.php, or the anchor_fm_storage_dir filter.
     */
    public static function storage_base() {
        if (defined('ANCHOR_FM_STORAGE_DIR') && ANCHOR_FM_STORAGE_DIR) {
            $base = rtrim((string) ANCHOR_FM_STORAGE_DIR, '/\\');
        } else {
            $upload_dir = wp_upload_dir();
            $base = trailingslashit($upload_dir['basedir']) . 'anchor-private-files';
        }
        return (string) apply_filters('anchor_fm_storage_dir', $base);
    }

    private function get_file_path_on_disk($file_row) {
        $folder_part = (int) $file_row->folder_id;
        return trailingslashit($this->get_storage_dir()) . $folder_part . '/' . $file_row->stored_name;
    }

    private function cap_rank($cap) {
        switch ($cap) {
            case 'manage': return 3;
            case 'view': return 1;
            default: return 0;
        }
    }

    private function rank_to_cap($rank) {
        if ($rank >= 3) return 'manage';
        if ($rank >= 1) return 'view';
        return 'none';
    }

    private function user_roles_lower($user_id) {
        $u = get_user_by('id', $user_id);
        if (!$u) return [];
        return array_values(array_map('strtolower', (array) $u->roles));
    }

    private function get_valid_permission_role_keys() {
        $roles = array_keys((array) wp_roles()->roles);
        $roles = array_map('sanitize_key', $roles);
        return array_values(array_filter(array_unique($roles), function ($role) {
            return $role !== '' && $role !== 'administrator';
        }));
    }

    private function normalize_permission_policy($policy) {
        return Anchor_FM_Permission_Policy::normalize($policy, $this->get_valid_permission_role_keys());
    }

    private function permission_policy_context($user_id) {
        return [
            'userId' => (int) $user_id,
            'roles' => array_map('sanitize_key', $this->user_roles_lower($user_id)),
        ];
    }

    private function get_permission_policy($entity_type, $entity_id, $capability = 'view') {
        global $wpdb;
        $policies = self::table('permission_policies');
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT policy FROM {$policies} WHERE entity_type = %s AND entity_id = %d AND capability = %s",
            $entity_type,
            $entity_id,
            $capability
        ));
        return $this->normalize_permission_policy($raw ?: []);
    }

    private function permission_policy_matches($user_id, $entity_type, $entity_id, $capability = 'view') {
        $policy = $this->get_permission_policy($entity_type, $entity_id, $capability);
        if (Anchor_FM_Permission_Policy::rule_count($policy) <= 0) {
            return false;
        }
        return Anchor_FM_Permission_Policy::evaluate(
            $policy,
            $this->permission_policy_context($user_id),
            current_time('Y-m-d')
        );
    }

    private function save_permission_policy($entity_type, $entity_id, $capability, $policy, $user_id) {
        global $wpdb;
        $policies = self::table('permission_policies');
        $policy = $this->normalize_permission_policy($policy);

        if (Anchor_FM_Permission_Policy::rule_count($policy) <= 0) {
            $wpdb->delete($policies, [
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'capability' => $capability,
            ], ['%s','%d','%s']);
            return $policy;
        }

        $now = current_time('mysql');
        $wpdb->replace($policies, [
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'capability' => $capability,
            'policy' => wp_json_encode($policy),
            'created_by' => $user_id,
            'created_at' => $now,
            'updated_by' => $user_id,
            'updated_at' => $now,
        ], ['%s','%d','%s','%s','%d','%s','%d','%s']);

        return $policy;
    }

    private function enrich_permission_policy_for_response(array $policy) {
        foreach ($policy['rules'] as $rule_index => $rule) {
            foreach ($rule['conditions'] as $condition_index => $condition) {
                if (isset($condition['type']) && $condition['type'] === 'user') {
                    $uid = isset($condition['userId']) ? (int) $condition['userId'] : 0;
                    $u = $uid > 0 ? get_user_by('id', $uid) : null;
                    $policy['rules'][$rule_index]['conditions'][$condition_index]['name'] = $u ? $u->display_name : (string) $uid;
                }
            }
        }
        return $policy;
    }

    private function get_folder_row($folder_id) {
        global $wpdb;
        $folders = self::table('folders');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$folders} WHERE id = %d", $folder_id));
    }

    private function get_file_row($file_id) {
        global $wpdb;
        $files = self::table('files');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$files} WHERE id = %d", $file_id));
    }

    private function get_link_row($link_id) {
        global $wpdb;
        $links = self::table('links');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$links} WHERE id = %d", $link_id));
    }

    private function get_video_row($video_id) {
        global $wpdb;
        $videos = self::table('videos');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$videos} WHERE id = %d", $video_id));
    }

    /**
     * The current user's watch percentage for a batch of items, keyed
     * "<source>:<id>". One query for the whole folder — never one per row.
     *
     * Only ever reads the requesting user's own rows; no caller-supplied
     * user id is accepted anywhere in this path.
     */
    private function watch_percent_map($video_ids, $file_ids) {
        global $wpdb;
        $user_id = get_current_user_id();
        if ($user_id <= 0) return [];

        $video_ids = array_values(array_filter(array_map('intval', (array) $video_ids)));
        $file_ids  = array_values(array_filter(array_map('intval', (array) $file_ids)));
        if (!$video_ids && !$file_ids) return [];

        $views  = self::table('video_views');
        $where  = [];
        $params = [$user_id];

        if ($video_ids) {
            $where[] = "(source = %s AND video_id IN (" . implode(',', array_fill(0, count($video_ids), '%d')) . "))";
            $params[] = Anchor_FM_Media_Progress::SOURCE_VIMEO;
            foreach ($video_ids as $id) { $params[] = $id; }
        }
        if ($file_ids) {
            $where[] = "(source = %s AND video_id IN (" . implode(',', array_fill(0, count($file_ids), '%d')) . "))";
            $params[] = Anchor_FM_Media_Progress::SOURCE_FILE;
            foreach ($file_ids as $id) { $params[] = $id; }
        }

        $sql = "SELECT source, video_id, percent FROM {$views}
                WHERE user_id = %d AND (" . implode(' OR ', $where) . ")";

        $rows = $wpdb->get_results(call_user_func_array([$wpdb, 'prepare'], array_merge([$sql], $params)));

        $map = [];
        foreach ((array) $rows as $r) {
            $map[$r->source . ':' . (int) $r->video_id] = (int) $r->percent;
        }
        return $map;
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

    private function get_effective_capability($user_id, $entity_type, $entity_id) {
        if (user_can($user_id, 'administrator')) {
            return 'manage';
        }

        if ($entity_type === 'folder') {
            $folder = $this->get_folder_row($entity_id);
            if (!$folder) return 'none';
            if (!empty($folder->owner_user_id) && (int) $folder->owner_user_id === (int) $user_id) {
                return 'manage';
            }
            return $this->compute_folder_capability($user_id, $folder);
        }

        if ($entity_type === 'file') {
            $file = $this->get_file_row($entity_id);
            if (!$file) return 'none';
            // File-level role permissions override folder inheritance when present.
            $cap = $this->compute_entity_capability_direct($user_id, 'file', $entity_id);
            if ($cap !== 'none') return $cap;
            $folder = $this->get_folder_row((int) $file->folder_id);
            if (!$folder) return 'none';
            return $this->compute_folder_capability($user_id, $folder);
        }

        return 'none';
    }

    private function compute_folder_capability($user_id, $folder_row) {
        $seen = [];
        $best = 0;
        $current = $folder_row;
        $depth = 0;

        while ($current && $depth < 50) {
            $depth++;
            $fid = (int) $current->id;
            if (isset($seen[$fid])) break;
            $seen[$fid] = true;

            if (!empty($current->owner_user_id) && (int) $current->owner_user_id === (int) $user_id) {
                $best = max($best, 3);
                break;
            }

            $direct = $this->compute_entity_capability_direct($user_id, 'folder', $fid);
            $best = max($best, $this->cap_rank($direct));

            if (!empty($current->parent_id)) {
                $current = $this->get_folder_row((int) $current->parent_id);
            } else {
                $current = null;
            }
        }

        return $this->rank_to_cap($best);
    }

    private function compute_entity_capability_direct($user_id, $entity_type, $entity_id) {
        global $wpdb;
        $perms = self::table('permissions');

        $roles = $this->user_roles_lower($user_id);
        $role_keys = $roles ? array_map('sanitize_key', $roles) : [];
        $user_key = (string) (int) $user_id;
        $best = 0;

        // User-specific view permission
        $user_rows = $wpdb->get_col($wpdb->prepare(
            "SELECT capability FROM {$perms} WHERE entity_type = %s AND entity_id = %d AND subject_type = 'user' AND subject_key = %s",
            $entity_type,
            $entity_id,
            $user_key
        ));
        foreach ((array) $user_rows as $cap) {
            $best = max($best, $this->cap_rank($cap));
        }

        if ($this->permission_policy_matches($user_id, $entity_type, $entity_id, 'view')) {
            $best = max($best, $this->cap_rank('view'));
        }

        // Role-specific
        if ($role_keys) {
            $placeholders = implode(',', array_fill(0, count($role_keys), '%s'));
            $query = "SELECT capability FROM {$perms} WHERE entity_type = %s AND entity_id = %d AND subject_type = 'role' AND capability = 'view' AND subject_key IN ({$placeholders})";
            $args = array_merge([$query, $entity_type, $entity_id], $role_keys);
            $sql = call_user_func_array([$wpdb, 'prepare'], $args);
            $role_rows = $wpdb->get_col($sql);
            foreach ((array) $role_rows as $cap) {
                $best = max($best, $this->cap_rank($cap));
            }
        }

        return $this->rank_to_cap($best);
    }

    private function entity_has_view_permissions($entity_type, $entity_id) {
        global $wpdb;
        $perms = self::table('permissions');
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$perms} WHERE entity_type = %s AND entity_id = %d AND capability = 'view'",
            $entity_type,
            $entity_id
        ));
        if ($count > 0) return true;

        $policies = self::table('permission_policies');
        $policy_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$policies} WHERE entity_type = %s AND entity_id = %d AND capability = 'view'",
            $entity_type,
            $entity_id
        ));
        return $policy_count > 0;
    }

    private function copy_view_permissions($from_type, $from_id, $to_type, $to_id, $overwrite = false) {
        global $wpdb;
        $perms = self::table('permissions');
        $policies = self::table('permission_policies');

        if (!$overwrite && $this->entity_has_view_permissions($to_type, $to_id)) {
            return;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT subject_type, subject_key FROM {$perms} WHERE entity_type = %s AND entity_id = %d AND capability = 'view'",
            $from_type,
            $from_id
        ));

        $wpdb->delete($perms, [
            'entity_type' => $to_type,
            'entity_id' => $to_id,
            'capability' => 'view',
        ], ['%s','%d','%s']);
        $wpdb->delete($policies, [
            'entity_type' => $to_type,
            'entity_id' => $to_id,
            'capability' => 'view',
        ], ['%s','%d','%s']);

        $now = current_time('mysql');
        foreach ((array) $rows as $r) {
            $wpdb->insert($perms, [
                'entity_type' => $to_type,
                'entity_id' => $to_id,
                'subject_type' => $r->subject_type,
                'subject_key' => $r->subject_key,
                'capability' => 'view',
                'created_by' => get_current_user_id(),
                'created_at' => $now,
            ], ['%s','%d','%s','%s','%s','%d','%s']);
        }

        $policy = $wpdb->get_var($wpdb->prepare(
            "SELECT policy FROM {$policies} WHERE entity_type = %s AND entity_id = %d AND capability = 'view'",
            $from_type,
            $from_id
        ));
        $policy = $this->normalize_permission_policy($policy ?: []);
        if (Anchor_FM_Permission_Policy::rule_count($policy) > 0) {
            $wpdb->insert($policies, [
                'entity_type' => $to_type,
                'entity_id' => $to_id,
                'capability' => 'view',
                'policy' => wp_json_encode($policy),
                'created_by' => get_current_user_id(),
                'created_at' => $now,
                'updated_by' => get_current_user_id(),
                'updated_at' => $now,
            ], ['%s','%d','%s','%s','%d','%s','%d','%s']);
        }
    }

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
            'folder_id'     => (int) $target_folder_id,
            'vimeo_id'      => $video->vimeo_id,
            // Carry the privacy hash and thumbnail: a copy without the hash
            // would not play for unlisted videos.
            'vimeo_hash'    => isset($video->vimeo_hash) ? (string) $video->vimeo_hash : '',
            'title'         => $title,
            'thumbnail_url' => isset($video->thumbnail_url) ? (string) $video->thumbnail_url : '',
            'created_by'    => get_current_user_id(),
            'created_at'    => $now,
            'updated_at'    => $now,
        ], ['%d','%s','%s','%s','%s','%d','%s','%s']);
        $existing[] = $title;
        return (int) $wpdb->insert_id;
    }

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
        if (!$wpdb->insert_id) {
            @unlink($dest);
            return new WP_Error('db_insert_failed', 'Could not save the copied file');
        }
        $existing[] = $original;
        return (int) $wpdb->insert_id;
    }

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

    private function require_nonce() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    private function json_error($message, $code = 400) {
        wp_send_json_error(['message' => $message], $code);
    }

    private function json_success($data = []) {
        wp_send_json_success($data);
    }

    private function log_activity($actor_user_id, $action, $entity_type, $entity_id, $meta) {
        global $wpdb;
        $activity = self::table('activity');
        $wpdb->insert($activity, [
            'actor_user_id' => $actor_user_id ?: 0,
            'action' => sanitize_key($action),
            'entity_type' => sanitize_key($entity_type),
            'entity_id' => (int) $entity_id,
            'meta' => $meta ? wp_json_encode($meta) : null,
            'created_at' => current_time('mysql'),
        ]);
    }

    private function build_breadcrumbs($folder_id) {
        $crumbs = [];
        $seen = [];
        $current = $this->get_folder_row($folder_id);
        $depth = 0;
        while ($current && $depth < 50) {
            $depth++;
            $id = (int) $current->id;
            if (isset($seen[$id])) break;
            $seen[$id] = true;
            $crumbs[] = ['id' => $id, 'name' => $current->name];
            $current = !empty($current->parent_id) ? $this->get_folder_row((int) $current->parent_id) : null;
        }
        return array_reverse($crumbs);
    }

    private function folder_path_string($folder_id) {
        if ((int) $folder_id <= 0) return '';
        $crumbs = $this->build_breadcrumbs((int) $folder_id);
        $names = [];
        foreach ($crumbs as $c) { $names[] = $c['name']; }
        return implode(' › ', $names);
    }

    private function build_folder_path_names($folder_id) {
        $crumbs = $this->build_breadcrumbs($folder_id);
        $names = [];
        foreach ($crumbs as $c) {
            $names[] = sanitize_title($c['name']) ?: 'folder-' . (int) $c['id'];
        }
        return $names;
    }

    private function can_user_view_folder($user_id, $folder_id) {
        return $this->cap_rank($this->get_effective_capability($user_id, 'folder', $folder_id)) >= 1;
    }

    private function can_user_upload_to_folder($user_id, $folder_id) {
        return user_can($user_id, 'administrator');
    }

    private function can_user_manage_folder($user_id, $folder_id) {
        return $this->cap_rank($this->get_effective_capability($user_id, 'folder', $folder_id)) >= 3;
    }

    private function can_user_view_file($user_id, $file_id) {
        if ($this->cap_rank($this->get_effective_capability($user_id, 'file', $file_id)) >= 1) {
            return true;
        }
        return $this->user_can_view_file_via_product($user_id, $file_id);
    }

    private function can_user_manage_file($user_id, $file_id) {
        return $this->cap_rank($this->get_effective_capability($user_id, 'file', $file_id)) >= 3;
    }

    private function can_user_view_link($user_id, $link_id) {
        $link = $this->get_link_row($link_id);
        if (!$link) return false;
        return $this->can_user_view_folder($user_id, (int) $link->folder_id);
    }

    private function can_user_manage_link($user_id, $link_id) {
        $link = $this->get_link_row($link_id);
        if (!$link) return false;
        return $this->can_user_manage_folder($user_id, (int) $link->folder_id);
    }

    private function notify_upload($file_row, $actor_user_id) {
        // Hard-disabled by default. Toggle via the anchor_fm_enable_upload_email filter if ever needed.
        if (!apply_filters('anchor_fm_enable_upload_email', false)) return;
        if (!get_option(self::OPT_EMAIL_ON_UPLOAD, 0)) return;

        $subject = sprintf('[%s] New file uploaded: %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $file_row->original_name);
        $message = "A new file was uploaded.\n\n";
        $message .= "File: {$file_row->original_name}\n";
        $message .= "MIME: {$file_row->mime_type}\n";
        $message .= "Size: " . size_format((int) $file_row->size) . "\n";
        $message .= "Uploaded by user ID: " . (int) $actor_user_id . "\n";

        $recipients = [];
        foreach (get_users(['role' => 'administrator', 'fields' => ['user_email']]) as $u) {
            if (!empty($u->user_email)) $recipients[] = $u->user_email;
        }

        $recipients = array_values(array_unique(array_filter($recipients)));
        if (!$recipients) return;

        wp_mail($recipients, $subject, $message);
    }

    public function ajax_bootstrap() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $tree = $this->build_folder_tree($user_id);
        $product_docs_id = (int) get_option(self::OPT_PD_FOLDER_ID, 0);
        if ($product_docs_id === 0 && user_can($user_id, 'administrator')) {
            $product_docs_id = (int) self::ensure_product_docs_folder();
        }

        $this->json_success([
            'tree' => $tree,
            'defaultFolderId' => 0,
            'productDocsFolderId' => $product_docs_id,
        ]);
    }

    private function build_folder_tree($user_id) {
        global $wpdb;
        $folders = self::table('folders');
        $all = $wpdb->get_results("SELECT id, parent_id, name, owner_user_id, is_private FROM {$folders} WHERE is_private = 0 ORDER BY name ASC");
        if (!$all) return [];

        $product_docs_id = (int) get_option(self::OPT_PD_FOLDER_ID, 0);

        $by_id = [];
        foreach ($all as $row) {
            if ((int) $row->id === $product_docs_id) {
                continue;
            }
            $by_id[(int) $row->id] = $row;
        }

        $visible = [];
        $memo = [];
        $can_see = function($folder_id) use ($user_id, &$memo, $by_id) {
            $folder_id = (int) $folder_id;
            if (isset($memo[$folder_id])) return $memo[$folder_id];
            if (!isset($by_id[$folder_id])) return $memo[$folder_id] = false;
            $folder = $by_id[$folder_id];
            if (!empty($folder->owner_user_id) && (int) $folder->owner_user_id === (int) $user_id) {
                return $memo[$folder_id] = true;
            }
            $cap = $this->get_effective_capability($user_id, 'folder', $folder_id);
            return $memo[$folder_id] = ($this->cap_rank($cap) >= 1);
        };

        foreach ($by_id as $id => $row) {
            if (!$can_see($id)) continue;
            $visible[$id] = true;
            $parent = !empty($row->parent_id) ? (int) $row->parent_id : 0;
            $depth = 0;
            while ($parent && $depth < 50) {
                $depth++;
                if (!isset($by_id[$parent])) break;
                $visible[$parent] = true;
                $parent = !empty($by_id[$parent]->parent_id) ? (int) $by_id[$parent]->parent_id : 0;
            }
        }

        $children = [];
        foreach ($visible as $id => $_) {
            $row = $by_id[$id];
            $pid = !empty($row->parent_id) ? (int) $row->parent_id : 0;
            if (!isset($children[$pid])) $children[$pid] = [];
            $children[$pid][] = $id;
        }

        $build = function($parent_id) use (&$build, $children, $by_id) {
            $nodes = [];
            foreach (($children[$parent_id] ?? []) as $id) {
                $row = $by_id[$id];
                $nodes[] = [
                    'id' => (int) $row->id,
                    'parentId' => !empty($row->parent_id) ? (int) $row->parent_id : 0,
                    'name' => $row->name,
                    'isPrivate' => (int) $row->is_private === 1,
                    'ownerUserId' => !empty($row->owner_user_id) ? (int) $row->owner_user_id : 0,
                    'isProductDocs' => (int) $row->id === (int) get_option(self::OPT_PD_FOLDER_ID, 0),
                    'children' => $build((int) $row->id),
                ];
            }
            return $nodes;
        };

        return $build(0);
    }

    public function ajax_list() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        if ($folder_id < 0) $this->json_error('Invalid folder_id');
        $product_docs_id = (int) get_option(self::OPT_PD_FOLDER_ID, 0);
        if ($folder_id === $product_docs_id) {
            $this->json_error('Forbidden', 403);
        }
        if ($folder_id > 0 && !$this->can_user_view_folder($user_id, $folder_id)) {
            $this->json_error('Forbidden', 403);
        }

        global $wpdb;
        $folders = self::table('folders');
        $files = self::table('files');

        $subfolders_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT id, parent_id, name, owner_user_id, is_private FROM {$folders} WHERE parent_id = %d AND is_private = 0 ORDER BY name ASC",
            $folder_id
        ));
        $subfolders = [];
        foreach ((array) $subfolders_raw as $f) {
            if ((int) $f->id === $product_docs_id) continue;
            if (!$this->can_user_view_folder($user_id, (int) $f->id)) continue;
            $subfolders[] = [
                'id' => (int) $f->id,
                'name' => $f->name,
                'isPrivate' => (int) $f->is_private === 1,
                'ownerUserId' => !empty($f->owner_user_id) ? (int) $f->owner_user_id : 0,
            ];
        }

        $file_rows = [];
        if ($folder_id > 0) {
            $file_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, folder_id, original_name, mime_type, size, uploader_user_id, created_at FROM {$files} WHERE folder_id = %d ORDER BY created_at DESC",
                $folder_id
            ));
        }
        $file_list = [];
        foreach ((array) $file_rows as $r) {
            if (!$this->can_user_view_file($user_id, (int) $r->id)) continue;
            $file_list[] = [
                'id' => (int) $r->id,
                'name' => $r->original_name,
                'mime' => $r->mime_type,
                'size' => (int) $r->size,
                'uploadedBy' => !empty($r->uploader_user_id) ? (int) $r->uploader_user_id : 0,
                'createdAt' => $r->created_at,
            ];
        }

        $link_list = [];
        if ($folder_id > 0) {
            $links_table = self::table('links');
            $link_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, folder_id, title, url, created_by, created_at FROM {$links_table} WHERE folder_id = %d ORDER BY created_at DESC",
                $folder_id
            ));
            foreach ((array) $link_rows as $l) {
                if (!$this->can_user_view_link($user_id, (int) $l->id)) continue;
                $link_list[] = [
                    'id' => (int) $l->id,
                    'title' => $l->title,
                    'url' => $l->url,
                    'createdBy' => !empty($l->created_by) ? (int) $l->created_by : 0,
                    'createdAt' => $l->created_at,
                ];
            }
        }

        $video_list = [];
        if ($folder_id > 0) {
            $videos_table = self::table('videos');
            $video_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, folder_id, vimeo_id, vimeo_hash, title, thumbnail_url, created_by, created_at FROM {$videos_table} WHERE folder_id = %d ORDER BY created_at DESC",
                $folder_id
            ));
            foreach ((array) $video_rows as $v) {
                if (!$this->can_user_view_video($user_id, (int) $v->id)) continue;
                $video_list[] = [
                    'id' => (int) $v->id,
                    'title' => $v->title,
                    'vimeoId' => $v->vimeo_id,
                    'vimeoHash' => isset($v->vimeo_hash) ? (string) $v->vimeo_hash : '',
                    'thumbnailUrl' => isset($v->thumbnail_url) ? (string) $v->thumbnail_url : '',
                    'createdBy' => !empty($v->created_by) ? (int) $v->created_by : 0,
                    'createdAt' => $v->created_at,
                ];
            }
        }

        $video_ids = [];
        if (!empty($video_list)) {
            foreach ($video_list as $v) { $video_ids[] = (int) $v['id']; }
        }

        $file_ids = [];
        foreach ($file_list as $f) {
            if (strpos((string) $f['mime'], 'video/') === 0) { $file_ids[] = (int) $f['id']; }
        }

        $watch = $this->watch_percent_map($video_ids, $file_ids);

        if (!empty($video_list)) {
            foreach ($video_list as $i => $v) {
                $key = Anchor_FM_Media_Progress::SOURCE_VIMEO . ':' . (int) $v['id'];
                if (isset($watch[$key])) { $video_list[$i]['watchPercent'] = $watch[$key]; }
            }
        }
        foreach ($file_list as $i => $f) {
            if (strpos((string) $f['mime'], 'video/') !== 0) continue;
            $key = Anchor_FM_Media_Progress::SOURCE_FILE . ':' . (int) $f['id'];
            if (isset($watch[$key])) { $file_list[$i]['watchPercent'] = $watch[$key]; }
        }

        $cap = $folder_id === 0 ? (user_can($user_id, 'administrator') ? 'manage' : 'view') : $this->get_effective_capability($user_id, 'folder', $folder_id);
        $this->json_success([
            'folderId' => $folder_id,
            'breadcrumbs' => $folder_id === 0 ? [] : $this->build_breadcrumbs($folder_id),
            'folders' => $subfolders,
            'links' => $link_list,
            'files' => $file_list,
            'videos' => $video_list,
            'capability' => $cap,
            'isProductDocs' => $folder_id === $product_docs_id,
        ]);
    }

    public function ajax_search() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $term = isset($_POST['term']) ? sanitize_text_field((string) $_POST['term']) : '';
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
            "SELECT id, folder_id, title, vimeo_id, vimeo_hash, thumbnail_url FROM {$videos} WHERE title LIKE %s ORDER BY title ASC LIMIT %d",
            $like, $cap
        ));
        foreach ((array) $rows as $r) {
            if (!$this->can_user_view_video($user_id, (int) $r->id)) continue;
            $results[] = [
                'kind' => 'video', 'id' => (int) $r->id, 'name' => $r->title, 'vimeoId' => $r->vimeo_id,
                'vimeoHash' => isset($r->vimeo_hash) ? (string) $r->vimeo_hash : '',
                'thumbnailUrl' => isset($r->thumbnail_url) ? (string) $r->thumbnail_url : '',
                'folderId' => (int) $r->folder_id,
                'path' => $this->folder_path_string((int) $r->folder_id),
            ];
        }

        $truncated = count($results) > $cap;
        if ($truncated) $results = array_slice($results, 0, $cap);

        $this->json_success(['results' => $results, 'truncated' => $truncated]);
    }

    public function ajax_create_folder() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $parent_id = isset($_POST['parent_id']) ? (int) $_POST['parent_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field((string) $_POST['name']) : '';
        if ($parent_id < 0 || $name === '') $this->json_error('Missing fields');

        if (!user_can($user_id, 'administrator')) $this->json_error('Forbidden', 403);

        global $wpdb;
        $folders = self::table('folders');
        $now = current_time('mysql');
        $wpdb->insert($folders, [
            'parent_id' => $parent_id,
            'name' => $name,
            'owner_user_id' => 0,
            'is_private' => 0,
            'created_by' => $user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $folder_id = (int) $wpdb->insert_id;
        $this->log_activity($user_id, 'create_folder', 'folder', $folder_id, ['parent_id' => $parent_id, 'name' => $name]);

        $this->json_success(['folderId' => $folder_id]);
    }

    public function ajax_rename_folder() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field((string) $_POST['name']) : '';
        if ($folder_id <= 0 || $name === '') $this->json_error('Missing fields');

        if (!user_can($user_id, 'administrator')) $this->json_error('Forbidden', 403);

        global $wpdb;
        $folders = self::table('folders');
        $wpdb->update($folders, [
            'name' => $name,
            'updated_at' => current_time('mysql'),
        ], ['id' => $folder_id], ['%s','%s'], ['%d']);
        $this->log_activity($user_id, 'rename_folder', 'folder', $folder_id, ['name' => $name]);

        $this->json_success(['folderId' => $folder_id]);
    }

    public function ajax_delete_folder() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        if ($folder_id <= 0) $this->json_error('Missing folder_id');

        if (!user_can($user_id, 'administrator')) $this->json_error('Forbidden', 403);

        $result = $this->delete_folder_recursive($folder_id, $user_id);
        if (!$result['ok']) {
            $this->json_error($result['message'], 400);
        }

        $this->json_success(['folderId' => $folder_id]);
    }

    private function delete_folder_recursive($folder_id, $actor_user_id) {
        global $wpdb;
        $folders_table = self::table('folders');
        $files_table = self::table('files');
        $perms_table = self::table('permissions');
        $links_table = self::table('links');
        $videos_table = self::table('videos');
        $video_views_table = self::table('video_views');

        $folder = $this->get_folder_row($folder_id);
        if (!$folder) {
            return ['ok' => false, 'message' => 'Folder not found'];
        }

        // Collect folder IDs (folder + all descendants)
        $rows = $wpdb->get_results("SELECT id, parent_id FROM {$folders_table}");
        $children = [];
        foreach ((array) $rows as $r) {
            $pid = (int) $r->parent_id;
            if (!isset($children[$pid])) $children[$pid] = [];
            $children[$pid][] = (int) $r->id;
        }

        $folder_ids = [];
        $stack = [(int) $folder_id];
        $seen = [];
        while ($stack) {
            $id = (int) array_pop($stack);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $folder_ids[] = $id;
            foreach (($children[$id] ?? []) as $cid) {
                $stack[] = (int) $cid;
            }
        }

        if (!$folder_ids) {
            return ['ok' => false, 'message' => 'Nothing to delete'];
        }

        // Fetch and delete files on disk
        $placeholders = implode(',', array_fill(0, count($folder_ids), '%d'));
        $query = "SELECT id, folder_id, stored_name, original_name FROM {$files_table} WHERE folder_id IN ({$placeholders})";
        $args = array_merge([$query], $folder_ids);
        $sql = call_user_func_array([$wpdb, 'prepare'], $args);
        $file_rows = $wpdb->get_results($sql);

        $file_ids = [];
        foreach ((array) $file_rows as $f) {
            $file_ids[] = (int) $f->id;
            $path = trailingslashit($this->get_storage_dir()) . ((int) $f->folder_id) . '/' . $f->stored_name;
            if (file_exists($path) && is_file($path)) {
                @unlink($path);
            }
        }

        // Delete DB rows
        $wpdb->query("START TRANSACTION");
        try {
            if ($file_ids) {
                $fph = implode(',', array_fill(0, count($file_ids), '%d'));
                $wpdb->query(call_user_func_array([$wpdb, 'prepare'], array_merge(
                    ["DELETE FROM {$files_table} WHERE id IN ({$fph})"],
                    $file_ids
                )));

                $wpdb->query(call_user_func_array([$wpdb, 'prepare'], array_merge(
                    ["DELETE FROM {$perms_table} WHERE entity_type = 'file' AND entity_id IN ({$fph})"],
                    $file_ids
                )));

                // Uploaded video files carry watch history too; prune it with
                // the same source discriminator so Vimeo rows are untouched.
                $wpdb->query(call_user_func_array([$wpdb, 'prepare'], array_merge(
                    ["DELETE FROM {$video_views_table} WHERE source = %s AND video_id IN ({$fph})"],
                    [Anchor_FM_Media_Progress::SOURCE_FILE],
                    $file_ids
                )));
            }

            $dph = implode(',', array_fill(0, count($folder_ids), '%d'));
            $wpdb->query(call_user_func_array([$wpdb, 'prepare'], array_merge(
                ["DELETE FROM {$perms_table} WHERE entity_type = 'folder' AND entity_id IN ({$dph})"],
                $folder_ids
            )));

            // Remove links in these folders.
            $wpdb->query(call_user_func_array([$wpdb, 'prepare'], array_merge(
                ["DELETE FROM {$links_table} WHERE folder_id IN ({$dph})"],
                $folder_ids
            )));

            // Remove videos in these folders and their watch-history rows.
            $video_ids = $wpdb->get_col(call_user_func_array([$wpdb, 'prepare'], array_merge(
                ["SELECT id FROM {$videos_table} WHERE folder_id IN ({$dph})"],
                $folder_ids
            )));
            if ($video_ids) {
                $vph = implode(',', array_fill(0, count($video_ids), '%d'));
                // source is load-bearing: without it this also deletes the
                // watch history of uploaded files whose files.id collides
                // with one of these videos.id values.
                $wpdb->query(call_user_func_array([$wpdb, 'prepare'], array_merge(
                    ["DELETE FROM {$video_views_table} WHERE source = %s AND video_id IN ({$vph})"],
                    [Anchor_FM_Media_Progress::SOURCE_VIMEO],
                    array_map('intval', $video_ids)
                )));
                $wpdb->query(call_user_func_array([$wpdb, 'prepare'], array_merge(
                    ["DELETE FROM {$videos_table} WHERE id IN ({$vph})"],
                    array_map('intval', $video_ids)
                )));
            }

            $wpdb->query(call_user_func_array([$wpdb, 'prepare'], array_merge(
                ["DELETE FROM {$folders_table} WHERE id IN ({$dph})"],
                $folder_ids
            )));

            $wpdb->query("COMMIT");
        } catch (\Throwable $e) {
            $wpdb->query("ROLLBACK");
            return ['ok' => false, 'message' => 'Failed to delete folder'];
        }

        // Best-effort: remove now-empty storage dirs for folders deleted.
        foreach ($folder_ids as $fid) {
            $dir = trailingslashit($this->get_storage_dir()) . ((int) $fid);
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }

        $this->log_activity($actor_user_id, 'delete_folder_recursive', 'folder', $folder_id, [
            'deleted_folder_ids' => count($folder_ids),
            'deleted_file_ids' => count($file_ids),
        ]);

        return ['ok' => true, 'message' => 'Deleted'];
    }

    /**
     * Extensions accepted by the uploaders. Anything outside this list is
     * rejected before the file is written, preventing executable payloads
     * (e.g. .php, .phtml) from landing in the web-served uploads directory.
     * Filterable so a site can extend it without touching the plugin.
     */
    private static function allowed_upload_extensions() {
        $exts = [
            // documents
            'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf',
            'odt','ods','odp',
            // images
            'jpg','jpeg','png','gif','webp','bmp','tif','tiff','heic',
            // audio / video
            'mp4','mov','m4v','webm','mp3','wav','m4a',
            // archives
            'zip',
        ];
        $exts = apply_filters('anchor_fm_allowed_upload_extensions', $exts);
        return array_map('strtolower', (array) $exts);
    }

    /**
     * Validate an uploaded file against the extension allow-list. Returns the
     * resolved ['ext' => ..., 'type' => ...] on success, or false when the
     * type is disallowed. The real (content-sniffed) extension from
     * wp_check_filetype_and_ext() is preferred; when that can't determine a
     * type we fall back to the sanitized filename's extension so the
     * allow-list is still enforced (and disallowed types are rejected).
     */
    private function validate_upload_type($tmp, $filename) {
        $ft   = wp_check_filetype_and_ext($tmp, $filename);
        $ext  = !empty($ft['ext'])  ? strtolower($ft['ext'])  : '';
        $type = !empty($ft['type']) ? $ft['type'] : '';
        if ($ext === '') {
            $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        }
        if ($ext === '' || !in_array($ext, self::allowed_upload_extensions(), true)) {
            return false;
        }
        return ['ext' => $ext, 'type' => $type !== '' ? $type : 'application/octet-stream'];
    }

    public function ajax_upload() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        if ($folder_id <= 0) $this->json_error('Missing folder_id');
        if (!$this->can_user_upload_to_folder($user_id, $folder_id)) $this->json_error('Forbidden', 403);

        if (empty($_FILES['files'])) $this->json_error('No files');

        self::ensure_upload_storage();
        $folder_dir = trailingslashit($this->get_storage_dir()) . $folder_id;
        if (!file_exists($folder_dir)) {
            wp_mkdir_p($folder_dir);
            $htaccess = $folder_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Deny from all\n");
            }
            $index = $folder_dir . '/index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, "<?php\n// Silence is golden.\n");
            }
        }

        global $wpdb;
        $files_table = self::table('files');

        $uploaded = [];
        $rejected = [];
        $names = (array) $_FILES['files']['name'];
        $tmp_names = (array) $_FILES['files']['tmp_name'];
        $sizes = (array) $_FILES['files']['size'];
        $errors = (array) $_FILES['files']['error'];

        for ($i = 0; $i < count($names); $i++) {
            if (!isset($tmp_names[$i])) continue;
            if ((int) $errors[$i] !== UPLOAD_ERR_OK) continue;

            $original = (string) $names[$i];
            $tmp = (string) $tmp_names[$i];
            $size = (int) $sizes[$i];

            $sanitized = sanitize_file_name($original);
            $unique = wp_unique_filename($folder_dir, $sanitized);

            $valid = $this->validate_upload_type($tmp, $unique);
            if ($valid === false) {
                $rejected[] = $original;
                continue;
            }
            $mime = $valid['type'];

            $dest = trailingslashit($folder_dir) . $unique;
            if (!@move_uploaded_file($tmp, $dest)) {
                continue;
            }

            $sha1 = @sha1_file($dest) ?: null;
            $now = current_time('mysql');
            $wpdb->insert($files_table, [
                'folder_id' => $folder_id,
                'original_name' => $original,
                'stored_name' => $unique,
                'mime_type' => $mime,
                'size' => $size,
                'sha1' => $sha1,
                'uploader_user_id' => $user_id,
                'created_at' => $now,
            ], ['%d','%s','%s','%s','%d','%s','%d','%s']);

            $file_id = (int) $wpdb->insert_id;
            $row = $this->get_file_row($file_id);
            $uploaded[] = [
                'id' => $file_id,
                'name' => $original,
                'mime' => $mime,
                'size' => $size,
            ];

            $this->log_activity($user_id, 'upload_file', 'file', $file_id, ['folder_id' => $folder_id, 'name' => $original]);
            if ($row) $this->notify_upload($row, $user_id);
        }

        $this->json_success(['uploaded' => $uploaded, 'rejected' => $rejected]);
    }

    public function ajax_delete_file() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $file_id = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
        if ($file_id <= 0) $this->json_error('Missing file_id');
        if (!$this->can_user_manage_file($user_id, $file_id)) $this->json_error('Forbidden', 403);

        $file = $this->get_file_row($file_id);
        if (!$file) $this->json_error('Not found', 404);

        $path = $this->get_file_path_on_disk($file);
        if (file_exists($path)) {
            @unlink($path);
        }

        global $wpdb;
        $files_table = self::table('files');
        $perms_table = self::table('permissions');
        $wpdb->delete($files_table, ['id' => $file_id], ['%d']);
        $wpdb->delete($perms_table, ['entity_type' => 'file', 'entity_id' => $file_id], ['%s','%d']);
        // Uploaded videos accumulate watch history under source='file'.
        // Without this the rows outlive the file forever, keyed to an id
        // that will eventually be reused.
        $wpdb->delete(
            self::table('video_views'),
            ['source' => Anchor_FM_Media_Progress::SOURCE_FILE, 'video_id' => $file_id],
            ['%s','%d']
        );

        $this->log_activity($user_id, 'delete_file', 'file', $file_id, ['name' => $file->original_name]);
        $this->json_success(['fileId' => $file_id]);
    }

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

    public function ajax_create_link() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        if (!current_user_can('administrator')) $this->json_error('Forbidden', 403);

        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        $title = isset($_POST['title']) ? sanitize_text_field((string) $_POST['title']) : '';
        $url = isset($_POST['url']) ? esc_url_raw((string) $_POST['url']) : '';
        if ($folder_id <= 0 || $title === '' || $url === '') $this->json_error('Missing fields');
        if (!$this->can_user_manage_folder($user_id, $folder_id)) $this->json_error('Forbidden', 403);

        global $wpdb;
        $links = self::table('links');
        $now = current_time('mysql');
        $wpdb->insert($links, [
            'folder_id' => $folder_id,
            'title' => $title,
            'url' => $url,
            'created_by' => $user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%s','%s','%d','%s','%s']);
        $link_id = (int) $wpdb->insert_id;

        $this->log_activity($user_id, 'create_link', 'link', $link_id, ['folder_id' => $folder_id, 'title' => $title]);
        $this->json_success(['linkId' => $link_id]);
    }

    public function ajax_update_link() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        if (!current_user_can('administrator')) $this->json_error('Forbidden', 403);

        $link_id = isset($_POST['link_id']) ? (int) $_POST['link_id'] : 0;
        $title = isset($_POST['title']) ? sanitize_text_field((string) $_POST['title']) : '';
        $url = isset($_POST['url']) ? esc_url_raw((string) $_POST['url']) : '';
        if ($link_id <= 0 || $title === '' || $url === '') $this->json_error('Missing fields');
        if (!$this->can_user_manage_link($user_id, $link_id)) $this->json_error('Forbidden', 403);

        global $wpdb;
        $links = self::table('links');
        $wpdb->update($links, [
            'title' => $title,
            'url' => $url,
            'updated_at' => current_time('mysql'),
        ], ['id' => $link_id], ['%s','%s','%s'], ['%d']);

        $this->log_activity($user_id, 'update_link', 'link', $link_id, ['title' => $title]);
        $this->json_success(['linkId' => $link_id]);
    }

    public function ajax_delete_link() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        if (!current_user_can('administrator')) $this->json_error('Forbidden', 403);

        $link_id = isset($_POST['link_id']) ? (int) $_POST['link_id'] : 0;
        if ($link_id <= 0) $this->json_error('Missing link_id');
        if (!$this->can_user_manage_link($user_id, $link_id)) $this->json_error('Forbidden', 403);

        global $wpdb;
        $links = self::table('links');
        $wpdb->delete($links, ['id' => $link_id], ['%d']);

        $this->log_activity($user_id, 'delete_link', 'link', $link_id, null);
        $this->json_success(['linkId' => $link_id]);
    }

    public function ajax_vimeo_get() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $video_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
        if ($video_id <= 0) $this->json_error('Missing video_id');

        $video = $this->get_video_row($video_id);
        if (!$video) $this->json_error('Not found', 404);
        if (!$this->can_user_view_video($user_id, $video_id)) $this->json_error('Forbidden', 403);

        $this->json_success(['video' => [
            'id' => (int) $video->id,
            'title' => $video->title,
            'vimeoId' => $video->vimeo_id,
            'vimeoHash' => isset($video->vimeo_hash) ? (string) $video->vimeo_hash : '',
            'thumbnailUrl' => isset($video->thumbnail_url) ? (string) $video->thumbnail_url : '',
            'folderId' => (int) $video->folder_id,
        ]]);
    }

    /**
     * Resolve a pasted blob of Vimeo references into reviewable entries.
     * Read-only: writes nothing, so the UI can show titles before committing.
     */
    public function ajax_vimeo_resolve() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        $raw = isset($_POST['refs']) ? (string) wp_unslash($_POST['refs']) : '';

        if ($folder_id <= 0) $this->json_error('Pick a folder first');
        if (!user_can($user_id, 'administrator') || !$this->can_user_manage_folder($user_id, $folder_id)) {
            $this->json_error('Forbidden', 403);
        }

        $refs = Anchor_FM_Vimeo::split_refs($raw);
        if (empty($refs)) $this->json_error('Paste at least one Vimeo link or ID');
        if (count($refs) > self::VIMEO_BULK_MAX) {
            $this->json_error(sprintf('Too many at once — %d max, got %d', self::VIMEO_BULK_MAX, count($refs)));
        }

        $entries = [];
        $seen = [];
        foreach ($refs as $ref) {
            $parsed = Anchor_FM_Vimeo::parse_ref($ref);
            if ($parsed['id'] === '') {
                $entries[] = [
                    'input' => $ref, 'vimeoId' => '', 'hash' => '', 'title' => '',
                    'thumbnailUrl' => '', 'error' => 'Could not read a Vimeo ID from this',
                ];
                continue;
            }
            // Same video pasted twice: keep one row rather than silently
            // importing a duplicate.
            if (isset($seen[$parsed['id']])) {
                $entries[] = [
                    'input' => $ref, 'vimeoId' => $parsed['id'], 'hash' => $parsed['hash'],
                    'title' => '', 'thumbnailUrl' => '', 'error' => 'Duplicate of another entry in this list',
                ];
                continue;
            }
            $seen[$parsed['id']] = true;

            $meta = Anchor_FM_Vimeo::fetch_meta($parsed['id'], $parsed['hash']);
            if (is_wp_error($meta)) {
                // Non-fatal: importable, just un-describable. Title falls back
                // and the UI surfaces why so it can be corrected by hand.
                $entries[] = [
                    'input' => $ref, 'vimeoId' => $parsed['id'], 'hash' => $parsed['hash'],
                    'title' => Anchor_FM_Vimeo::fallback_title($parsed['id']),
                    'thumbnailUrl' => '', 'error' => '',
                    'warning' => $meta->get_error_message(),
                ];
                continue;
            }
            $entries[] = [
                'input' => $ref, 'vimeoId' => $parsed['id'], 'hash' => $parsed['hash'],
                'title' => $meta['title'], 'thumbnailUrl' => $meta['thumbnail_url'], 'error' => '',
            ];
        }

        $this->json_success(['entries' => $entries]);
    }

    public function ajax_vimeo_add() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        if ($folder_id <= 0) $this->json_error('Pick a folder first');
        if (!user_can($user_id, 'administrator') || !$this->can_user_manage_folder($user_id, $folder_id)) {
            $this->json_error('Forbidden', 403);
        }

        // Accepts either a bulk `videos` array or the single-video
        // {title, vimeo} shape the old modal posted.
        $incoming = [];
        if (isset($_POST['videos']) && is_array($_POST['videos'])) {
            $incoming = $_POST['videos'];
        } elseif (isset($_POST['vimeo'])) {
            $incoming = [[
                'vimeo' => (string) $_POST['vimeo'],
                'title' => isset($_POST['title']) ? (string) $_POST['title'] : '',
            ]];
        }
        if (empty($incoming)) $this->json_error('Nothing to add');
        if (count($incoming) > self::VIMEO_BULK_MAX) {
            $this->json_error(sprintf('Too many at once — %d max, got %d', self::VIMEO_BULK_MAX, count($incoming)));
        }

        global $wpdb;
        $videos = self::table('videos');
        $now = current_time('mysql');

        $added = [];
        $failed = [];
        foreach ($incoming as $row) {
            if (!is_array($row)) continue;
            $raw    = isset($row['vimeo']) && is_scalar($row['vimeo']) ? (string) wp_unslash($row['vimeo']) : '';
            $title  = isset($row['title']) && is_scalar($row['title']) ? sanitize_text_field((string) wp_unslash($row['title'])) : '';
            $parsed = Anchor_FM_Vimeo::parse_ref($raw);

            if ($parsed['id'] === '') {
                $failed[] = ['input' => $raw, 'message' => 'Could not read a Vimeo ID from that input'];
                continue;
            }
            // An explicit hash from the client wins; it may carry a hash the
            // bare id in `vimeo` cannot express.
            $hash = isset($row['hash']) && is_scalar($row['hash'])
                ? preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($row['hash']))
                : '';
            if ($hash === '') { $hash = $parsed['hash']; }

            // Title is optional now — it defaults to the Vimeo title, and to a
            // readable placeholder when Vimeo can't be reached.
            $thumb = isset($row['thumbnailUrl']) && is_scalar($row['thumbnailUrl'])
                ? esc_url_raw((string) wp_unslash($row['thumbnailUrl']))
                : '';
            if ($title === '') {
                $meta = Anchor_FM_Vimeo::fetch_meta($parsed['id'], $hash);
                if (!is_wp_error($meta)) {
                    $title = sanitize_text_field($meta['title']);
                    if ($thumb === '') { $thumb = esc_url_raw($meta['thumbnail_url']); }
                }
                if ($title === '') { $title = Anchor_FM_Vimeo::fallback_title($parsed['id']); }
            }

            $ok = $wpdb->insert($videos, [
                'folder_id'     => $folder_id,
                'vimeo_id'      => $parsed['id'],
                'vimeo_hash'    => $hash,
                'title'         => $title,
                'thumbnail_url' => $thumb,
                'created_by'    => $user_id,
                'created_at'    => $now,
                'updated_at'    => $now,
            ], ['%d','%s','%s','%s','%s','%d','%s','%s']);

            if ($ok === false) {
                $failed[] = ['input' => $raw, 'message' => 'Database error saving this video'];
                continue;
            }

            $video_id = (int) $wpdb->insert_id;
            $this->log_activity($user_id, 'create_video', 'video', $video_id, ['folder_id' => $folder_id, 'vimeo_id' => $parsed['id']]);
            $added[] = [
                'videoId' => $video_id, 'vimeoId' => $parsed['id'],
                'title' => $title, 'thumbnailUrl' => $thumb,
            ];
        }

        // Every row failing is an error; a partial import is a success that
        // reports its casualties.
        if (empty($added)) {
            $first = !empty($failed) ? $failed[0]['message'] : 'Nothing could be added';
            $this->json_error($first);
        }

        $this->json_success([
            'added'  => $added,
            'failed' => $failed,
            // Single-video callers still read these.
            'videoId' => $added[0]['videoId'],
            'vimeoId' => $added[0]['vimeoId'],
        ]);
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
        // video_views.video_id is polysemous: it holds a videos.id OR a
        // files.id, told apart only by source. Deleting on video_id alone
        // would wipe the watch history of the uploaded file that happens to
        // share this id -- the two tables auto-increment independently, so
        // low ids overlap almost completely.
        $wpdb->delete(
            $views,
            ['source' => Anchor_FM_Media_Progress::SOURCE_VIMEO, 'video_id' => $video_id],
            ['%s','%d']
        );
        $wpdb->delete($videos, ['id' => $video_id], ['%d']);
        $this->log_activity($user_id, 'delete_video', 'video', $video_id, null);
        $this->json_success(['videoId' => $video_id]);
    }

    /**
     * Resolve and authorize a (source, item) pair for the current user.
     * Sends a JSON error and exits on anything invalid.
     */
    private function require_media_access($source, $item_id, $user_id) {
        if (!Anchor_FM_Media_Progress::valid_source($source)) $this->json_error('Bad source');
        if ($item_id <= 0) $this->json_error('Missing item_id');

        $ok = ($source === Anchor_FM_Media_Progress::SOURCE_VIMEO)
            ? $this->can_user_view_video($user_id, $item_id)
            : $this->can_user_view_file($user_id, $item_id);

        if (!$ok) $this->json_error('Forbidden', 403);
    }

    private function handle_progress($source, $item_id) {
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $this->require_media_access($source, $item_id, $user_id);

        $raw_segments = isset($_POST['segments']) ? (string) wp_unslash($_POST['segments']) : '';
        $segments = $raw_segments !== '' ? json_decode($raw_segments, true) : [];
        if (!is_array($segments)) $segments = [];

        $saved = Anchor_FM_Media_Progress::record(
            self::table('video_views'),
            $source,
            $item_id,
            $user_id,
            $segments,
            isset($_POST['point']) ? (int) $_POST['point'] : 0,
            isset($_POST['duration']) ? (int) $_POST['duration'] : 0,
            !empty($_POST['ended']),
            !empty($_POST['new_session']),
            current_time('mysql'),
            !empty($_POST['reset'])
        );

        if ($saved === false) $this->json_error('Could not save progress', 500);

        $this->json_success(['saved' => true]);
    }

    public function ajax_media_progress() {
        $this->require_nonce();
        $source  = isset($_POST['source']) ? sanitize_key((string) $_POST['source']) : '';
        $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
        $this->handle_progress($source, $item_id);
    }

    /**
     * Back-compat shim. file-manager.js is cache-busted by filemtime, but a
     * browser holding the old bundle across a plugin update would otherwise
     * fail every heartbeat. Remove one release after 2.11.0.
     */
    public function ajax_vimeo_progress() {
        $this->require_nonce();
        $item_id = isset($_POST['video_id']) ? (int) $_POST['video_id'] : 0;
        $this->handle_progress(Anchor_FM_Media_Progress::SOURCE_VIMEO, $item_id);
    }

    public function ajax_media_resume() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();

        $source  = isset($_POST['source']) ? sanitize_key((string) $_POST['source']) : '';
        $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

        $this->require_media_access($source, $item_id, $user_id);

        $this->json_success([
            'resumeSeconds' => Anchor_FM_Media_Progress::read_resume(
                self::table('video_views'),
                $source,
                $item_id,
                $user_id,
                current_time('timestamp')
            ),
        ]);
    }

    /**
     * Zero out playback positions that have aged past the TTL.
     *
     * Housekeeping only — the authoritative expiry is the staleness check on
     * the read path, because WP-Cron fires on page loads and a private portal
     * can go untouched for weeks.
     *
     * Batched so a large table is never held under one long write lock.
     */
    public function cron_prune_resume() {
        global $wpdb;
        $views = self::table('video_views');
        $ttl   = (int) Anchor_FM_Watch_Math::RESUME_TTL_DAYS;
        $batch = 5000;

        for ($i = 0; $i < 10; $i++) {
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$views} SET resume_seconds = 0
                 WHERE resume_seconds > 0
                   AND last_viewed_at < DATE_SUB(%s, INTERVAL %d DAY)
                 LIMIT %d",
                current_time('mysql'), $ttl, $batch
            ));
            if ($affected === false || (int) $affected < $batch) break;
        }
    }

    public function ajax_vimeo_history() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        $user_id = get_current_user_id();
        if (!user_can($user_id, 'administrator')) $this->json_error('Forbidden', 403);

        $source  = isset($_POST['source']) ? sanitize_key((string) $_POST['source']) : Anchor_FM_Media_Progress::SOURCE_VIMEO;
        $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

        // Back-compat with callers still sending video_id.
        if ($item_id <= 0 && isset($_POST['video_id'])) {
            $item_id = (int) $_POST['video_id'];
        }

        if (!Anchor_FM_Media_Progress::valid_source($source)) $this->json_error('Bad source');
        if ($item_id <= 0) $this->json_error('Missing item_id');

        global $wpdb;
        $views = self::table('video_views');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, furthest_seconds, total_seconds, percent, sessions, last_viewed_at
             FROM {$views} WHERE source = %s AND video_id = %d ORDER BY last_viewed_at DESC LIMIT 500",
            $source, $item_id
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

        // Resolve the entity and confirm it exists, then confirm the requester
        // genuinely lacks view access. This is the "access denied for a real
        // item" flow — refusing arbitrary ids prevents using it for mail spam.
        $exists = false;
        $can_view = false;
        switch ($entity_type) {
            case 'file':
                $exists = (bool) $this->get_file_row($entity_id);
                $can_view = $exists && $this->can_user_view_file($user_id, $entity_id);
                break;
            case 'folder':
                $exists = (bool) $this->get_folder_row($entity_id);
                $can_view = $exists && $this->can_user_view_folder($user_id, $entity_id);
                break;
            case 'video':
                $exists = (bool) $this->get_video_row($entity_id);
                $can_view = $exists && $this->can_user_view_video($user_id, $entity_id);
                break;
            case 'link':
                $exists = (bool) $this->get_link_row($entity_id);
                $can_view = $exists && $this->can_user_view_link($user_id, $entity_id);
                break;
        }
        if (!$exists) $this->json_error('Not found', 404);
        if ($can_view) {
            // Already has access — nothing to request, nothing to email.
            $this->json_success(['sent' => false, 'alreadyHasAccess' => true]);
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

        $sent = wp_mail($to, $subject, $body);
        if (!$sent) {
            // Don't throttle or report success on delivery failure, so the user can retry.
            $this->json_error('Could not send the request right now. Please try again later.', 500);
        }
        set_transient($rate_key, 1, HOUR_IN_SECONDS);
        $this->log_activity($user_id, 'request_access', $entity_type, $entity_id, ['to' => $to]);

        $this->json_success(['sent' => true, 'throttled' => false]);
    }

    public function ajax_move_file() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $file_id = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
        $target_folder = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        if ($file_id <= 0 || $target_folder <= 0) $this->json_error('Missing fields');

        if (!$this->can_user_manage_file($user_id, $file_id)) $this->json_error('Forbidden', 403);
        if (!$this->can_user_upload_to_folder($user_id, $target_folder)) $this->json_error('Forbidden', 403);

        $file = $this->get_file_row($file_id);
        if (!$file) $this->json_error('Not found', 404);

        $current_path = $this->get_file_path_on_disk($file);
        if (!file_exists($current_path)) $this->json_error('File missing on disk', 404);

        self::ensure_upload_storage();
        $target_dir = trailingslashit($this->get_storage_dir()) . $target_folder;
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
            $htaccess = $target_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Deny from all\n");
            }
            $index = $target_dir . '/index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, "<?php\n// Silence is golden.\n");
            }
        }

        $dest = trailingslashit($target_dir) . $file->stored_name;
        if (!@rename($current_path, $dest)) {
            $this->json_error('Could not move file', 500);
        }

        global $wpdb;
        $files_table = self::table('files');
        $wpdb->update($files_table, [
            'folder_id' => $target_folder,
        ], ['id' => $file_id], ['%d'], ['%d']);

        // Ensure view permissions follow the destination folder (when no explicit file perms exist).
        $this->copy_view_permissions('folder', $target_folder, 'file', $file_id, false);

        $this->log_activity($user_id, 'move_file', 'file', $file_id, ['from' => (int) $file->folder_id, 'to' => $target_folder]);
        $this->json_success(['moved' => true]);
    }

    public function ajax_move_folder() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        if (!current_user_can('administrator')) $this->json_error('Forbidden', 403);

        $folder_id = isset($_POST['folder_id']) ? (int) $_POST['folder_id'] : 0;
        $target_id = isset($_POST['target_folder_id']) ? (int) $_POST['target_folder_id'] : 0;
        if ($folder_id <= 0 || $target_id < 0) $this->json_error('Missing fields');
        if ($folder_id === $target_id) $this->json_error('Cannot move into itself', 400);

        if ($this->is_descendant($target_id, $folder_id)) {
            $this->json_error('Cannot move into a child folder', 400);
        }

        global $wpdb;
        $folders = self::table('folders');
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$folders} WHERE id = %d", $folder_id));
        if (!$exists) $this->json_error('Folder not found', 404);

        $target_exists = $target_id === 0 ? 1 : (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$folders} WHERE id = %d", $target_id));
        if (!$target_exists) $this->json_error('Target not found', 404);

        $wpdb->update($folders, [
            'parent_id' => $target_id,
            'updated_at' => current_time('mysql'),
        ], ['id' => $folder_id], ['%d','%s'], ['%d']);

        $this->log_activity(get_current_user_id(), 'move_folder', 'folder', $folder_id, ['to' => $target_id]);
        $this->json_success(['moved' => true]);
    }

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

    private function is_descendant($folder_id, $possible_ancestor_id) {
        if ($folder_id <= 0 || $possible_ancestor_id <= 0) return false;
        $current = $this->get_folder_row($folder_id);
        $seen = [];
        $depth = 0;
        while ($current && $depth < 100) {
            $depth++;
            $cid = (int) $current->id;
            if (isset($seen[$cid])) break;
            $seen[$cid] = true;
            if ((int) $current->parent_id === (int) $possible_ancestor_id) return true;
            if (!empty($current->parent_id)) {
                $current = $this->get_folder_row((int) $current->parent_id);
            } else {
                $current = null;
            }
        }
        return false;
    }

    public function ajax_download_folder() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $folder_id = isset($_REQUEST['folder_id']) ? (int) $_REQUEST['folder_id'] : 0;
        if ($folder_id <= 0) $this->json_error('Missing folder_id');
        if (!$this->can_user_view_folder($user_id, $folder_id)) $this->json_error('Forbidden', 403);

        global $wpdb;
        $folders_table = self::table('folders');
        $files_table = self::table('files');

        $all_folders = $wpdb->get_results("SELECT id, parent_id, name FROM {$folders_table}");
        $children = [];
        foreach ((array) $all_folders as $row) {
            $pid = (int) $row->parent_id;
            if (!isset($children[$pid])) $children[$pid] = [];
            $children[$pid][] = (int) $row->id;
        }

        $folder_ids = [];
        $stack = [$folder_id];
        $seen = [];
        while ($stack) {
            $id = (int) array_pop($stack);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $folder_ids[] = $id;
            foreach (($children[$id] ?? []) as $cid) {
                $stack[] = $cid;
            }
        }

        $file_rows = [];
        if ($folder_ids) {
            $placeholders = implode(',', array_fill(0, count($folder_ids), '%d'));
            $query = "SELECT id, folder_id, original_name, stored_name, mime_type, size FROM {$files_table} WHERE folder_id IN ({$placeholders})";
            $args = array_merge([$query], $folder_ids);
            $sql = call_user_func_array([$wpdb, 'prepare'], $args);
            $file_rows = $wpdb->get_results($sql);
        }

        if (!$file_rows) {
            $this->json_error('No files to download', 400);
        }

        $tmp = wp_tempnam('anchor-folder-zip');
        if (!$tmp) $this->json_error('Could not create temp file', 500);

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            $this->json_error('Could not create zip', 500);
        }

        $folder_name_parts = $this->build_folder_path_names($folder_id);
        $folder_base = $folder_name_parts ? implode('/', $folder_name_parts) : ('folder-' . $folder_id);

        foreach ($file_rows as $row) {
            if (!$this->can_user_view_file($user_id, (int) $row->id)) continue;
            $path = $this->get_file_path_on_disk($row);
            if (!file_exists($path) || !is_readable($path)) continue;
            $relative_parts = $this->build_folder_path_names((int) $row->folder_id);
            $rel_dir = $relative_parts ? implode('/', $relative_parts) : $folder_base;
            $zip_path = $rel_dir . '/' . $row->original_name;
            $zip->addFile($path, $zip_path);
        }

        $zip->close();

        $download_name = $folder_base . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $download_name . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public function ajax_preview() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $file_id = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
        if ($file_id <= 0) $this->json_error('Missing file_id');
        if (!$this->can_user_view_file($user_id, $file_id)) $this->json_error('Forbidden', 403);

        $file = $this->get_file_row($file_id);
        if (!$file) $this->json_error('Not found', 404);

        // The row alone is not enough to promise a viewer. Every preview type
        // below renders by pointing the browser at ajax_stream(), so if the
        // bytes are unreachable the client paints a blank <iframe>/<img> and a
        // Download button that 404s -- which is how a whole-store outage read
        // to users as "the preview is just broken". Check once, here, and say so.
        $path = $this->get_file_path_on_disk($file);
        $bytes_readable = file_exists($path) && is_readable($path);
        if (!$bytes_readable) {
            self::log_stream_refusal($file_id, 'preview: ' . $this->describe_unreadable_path($path));
        }

        $mime = (string) $file->mime_type;
        $type = 'none';

        if (strpos($mime, 'image/') === 0) {
            $type = 'image';
        } elseif ($mime === 'application/pdf') {
            $type = 'pdf';
        } elseif (strpos($mime, 'video/') === 0) {
            // Covers every video extension on the upload allow-list
            // (mp4, mov, m4v, webm). Browser-level playability varies —
            // .mov in particular fails in Firefox — so the client falls back
            // to the download view on a media error.
            $type = 'video';
        } elseif (in_array($mime, ['text/plain', 'text/csv', 'application/json'], true)) {
            $type = 'text';
        }

        $nonce = wp_create_nonce('anchor_fm_stream_' . $file_id);
        $inline_url = add_query_arg([
            'action' => 'anchor_fm_stream',
            'file_id' => $file_id,
            'disposition' => 'inline',
            'nonce' => $nonce,
        ], admin_url('admin-ajax.php'));
        $download_url = add_query_arg([
            'action' => 'anchor_fm_stream',
            'file_id' => $file_id,
            'disposition' => 'attachment',
            'nonce' => $nonce,
        ], admin_url('admin-ajax.php'));

        $text_excerpt = null;
        if ($type === 'text' && $bytes_readable) {
            $raw = @file_get_contents($path, false, null, 0, 4000);
            if (is_string($raw)) {
                $text_excerpt = wp_strip_all_tags($raw);
            }
        }

        $this->json_success([
            'file' => [
                'id' => (int) $file->id,
                'name' => $file->original_name,
                'mime' => $file->mime_type,
                'size' => (int) $file->size,
                'createdAt' => $file->created_at,
                'uploadedBy' => !empty($file->uploader_user_id) ? (int) $file->uploader_user_id : 0,
            ],
            'preview' => [
                'type' => $bytes_readable ? $type : 'unavailable',
                'available' => $bytes_readable,
                'inlineUrl' => $inline_url,
                'downloadUrl' => $download_url,
                'textExcerpt' => $text_excerpt,
            ],
            'capability' => $this->get_effective_capability($user_id, 'file', $file_id),
        ]);
    }

    /**
     * Write an inclusive byte window of a file to the output stream.
     *
     * readfile() would pull the whole file into the output buffer, which a
     * multi-hundred-megabyte video will not survive. This reads in bounded
     * chunks and flushes as it goes.
     */
    private static function stream_file_range($path, $start, $end) {
        // Discard WordPress's output buffering, or the body accumulates in
        // memory instead of streaming.
        while (ob_get_level() > 0) { @ob_end_clean(); }
        // Finite, not unlimited: a stalled client must not pin a PHP worker
        // forever. 300s is far more than any single 256KB-chunked range needs.
        @set_time_limit(300);

        $requested = ($end - $start) + 1;

        $fh = @fopen($path, 'rb');
        if (!$fh) {
            // Unambiguous failure -- never caused by a client going away,
            // so always worth knowing about. The caller already sent
            // Content-Length for $requested bytes; the client now gets a
            // truncated body with no explanation unless this is logged.
            error_log(sprintf(
                'Anchor FM: stream_file_range() could not open "%s" (requested %d-%d)',
                $path, $start, $end
            ));
            return;
        }

        if ($start > 0) { fseek($fh, $start); }

        $remaining = $requested;
        $written = 0;
        $chunk = 262144; // 256KB

        while ($remaining > 0 && !feof($fh)) {
            $read = ($remaining > $chunk) ? $chunk : $remaining;
            $buf = fread($fh, $read);
            if ($buf === false || $buf === '') break;
            echo $buf;
            $written += strlen($buf);
            $remaining -= strlen($buf);
            flush();
        }
        fclose($fh);

        // A short read here is either a genuinely short/truncated file on
        // disk, or -- far more commonly once video playback is in the
        // picture -- the client aborting mid-stream (every seek and every
        // player teardown kills an in-flight range request). Those look
        // identical at this point: both leave $written < $requested. Only
        // log when the connection is still alive, or normal seeking would
        // flood the error log with non-faults.
        if ($written < $requested && connection_status() === CONNECTION_NORMAL) {
            error_log(sprintf(
                'Anchor FM: stream_file_range() wrote %d of %d requested bytes for "%s" (%d-%d) with connection still open',
                $written, $requested, $path, $start, $end
            ));
        }
    }

    /**
     * Why every refusal in ajax_stream() is logged.
     *
     * The endpoint answers a refusal with a bare status code and no body, so
     * from the browser a store the server cannot read looks exactly like a
     * deleted file, an expired nonce, and a permission denial. On 2026-08-21
     * tmjtherapycentre.com moved its store to a path outside PHP-FPM's
     * open_basedir; file_exists() then returned false for all 768 files, every
     * download and preview on the site 404'd for six days, and not one line
     * was written anywhere that said so. Naming the reason turns the next
     * occurrence into a grep instead of a bisect.
     */
    private static function log_stream_refusal($file_id, $reason) {
        error_log(sprintf(
            'Anchor FM: refused to stream file %d — %s',
            (int) $file_id,
            $reason
        ));
    }

    /** Why the bytes for a row could not be served, for the log. */
    private function describe_unreadable_path($path) {
        $base = self::storage_base();
        return sprintf(
            'bytes unreachable at "%s" (exists=%d readable=%d); store "%s" (is_dir=%d readable=%d); open_basedir="%s"',
            $path,
            (int) file_exists($path),
            (int) is_readable($path),
            $base,
            (int) is_dir($base),
            (int) is_readable($base),
            (string) ini_get('open_basedir')
        );
    }

    public function ajax_stream() {
        if (!is_user_logged_in()) {
            status_header(401);
            exit;
        }

        $file_id = isset($_GET['file_id']) ? (int) $_GET['file_id'] : 0;
        $nonce = isset($_GET['nonce']) ? (string) $_GET['nonce'] : '';
        $disposition = isset($_GET['disposition']) ? (string) $_GET['disposition'] : 'attachment';

        if ($file_id <= 0 || !$nonce || !wp_verify_nonce($nonce, 'anchor_fm_stream_' . $file_id)) {
            self::log_stream_refusal($file_id, 'bad or expired nonce');
            status_header(403);
            exit;
        }

        $user_id = get_current_user_id();
        if (!$this->can_user_view_file($user_id, $file_id)) {
            self::log_stream_refusal($file_id, 'user ' . $user_id . ' lacks view capability');
            status_header(403);
            exit;
        }

        $file = $this->get_file_row($file_id);
        if (!$file) {
            self::log_stream_refusal($file_id, 'no database row');
            status_header(404);
            exit;
        }

        $path = $this->get_file_path_on_disk($file);
        if (!file_exists($path) || !is_readable($path)) {
            self::log_stream_refusal($file_id, $this->describe_unreadable_path($path));
            status_header(404);
            exit;
        }

        $disp = $disposition === 'inline' ? 'inline' : 'attachment';
        $filename = sanitize_file_name($file->original_name);
        $size = (int) filesize($path);

        $raw_range = isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : '';
        $range = Anchor_FM_Range::parse($raw_range, $size);

        // One inline playthrough issues dozens of range requests, so for
        // previews log only the opening one or the activity table fills with
        // noise. Attachments are NOT deduplicated: they generate no range
        // storm, and suppressing them would let `Range: bytes=1-` pull a whole
        // document with zero download_file rows -- an audit-trail hole.
        $is_opening = ($range === null) || (isset($range['start']) && (int) $range['start'] === 0);
        if ($disp === 'attachment' || $is_opening) {
            $this->log_activity($user_id, $disp === 'inline' ? 'preview_file' : 'download_file', 'file', $file_id, []);
        }

        nocache_headers();
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $file->mime_type);
        header('Content-Disposition: ' . $disp . '; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        if (is_array($range) && empty($range['satisfiable'])) {
            header('Content-Range: bytes */' . $size);
            status_header(416);
            exit;
        }

        if ($range === null) {
            header('Content-Length: ' . $size);
            if ($size > 0) {
                self::stream_file_range($path, 0, $size - 1);
            }
            exit;
        }

        $start = (int) $range['start'];
        $end   = (int) $range['end'];

        status_header(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . (($end - $start) + 1));
        self::stream_file_range($path, $start, $end);
        exit;
    }

    public function ajax_get_permissions() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $entity_type = isset($_POST['entity_type']) ? sanitize_key((string) $_POST['entity_type']) : '';
        $entity_id = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
        if (!in_array($entity_type, ['file', 'folder'], true) || $entity_id <= 0) $this->json_error('Missing fields');

        $allowed = $entity_type === 'file'
            ? $this->can_user_manage_file($user_id, $entity_id)
            : $this->can_user_manage_folder($user_id, $entity_id);
        if (!$allowed) $this->json_error('Forbidden', 403);

        global $wpdb;
        $perms = self::table('permissions');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT subject_type, subject_key FROM {$perms} WHERE entity_type = %s AND entity_id = %d AND capability = 'view' ORDER BY subject_type, subject_key",
            $entity_type,
            $entity_id
        ));

        $roles = [];
        $users = [];
        foreach ((array) $rows as $r) {
            if ($r->subject_type === 'role') {
                $roles[] = sanitize_key((string) $r->subject_key);
            } elseif ($r->subject_type === 'user') {
                $uid = (int) $r->subject_key;
                $u = get_user_by('id', $uid);
                $users[] = [
                    'id' => (string) $uid,
                    'name' => $u ? $u->display_name : (string) $uid,
                ];
            }
        }

        $this->json_success([
            'roles' => array_values(array_unique($roles)),
            'users' => $users,
            'policy' => $this->enrich_permission_policy_for_response($this->get_permission_policy($entity_type, $entity_id, 'view')),
        ]);
    }

    public function ajax_set_permissions() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $entity_type = isset($_POST['entity_type']) ? sanitize_key((string) $_POST['entity_type']) : '';
        $entity_id = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
        $roles = isset($_POST['roles']) ? (array) $_POST['roles'] : [];
        $users = isset($_POST['users']) ? (array) $_POST['users'] : [];
        $policy_input = [];
        if (isset($_POST['policy'])) {
            $policy_input = wp_unslash($_POST['policy']);
            if (is_string($policy_input)) {
                $decoded = json_decode($policy_input, true);
                $policy_input = is_array($decoded) ? $decoded : [];
            }
        }
        if (!in_array($entity_type, ['file', 'folder'], true) || $entity_id <= 0) $this->json_error('Missing fields');

        $allowed = $entity_type === 'file'
            ? $this->can_user_manage_file($user_id, $entity_id)
            : $this->can_user_manage_folder($user_id, $entity_id);
        if (!$allowed) $this->json_error('Forbidden', 403);

        $valid_roles = $this->get_valid_permission_role_keys();

        $normalized = [];
        foreach ($roles as $role) {
            $role = sanitize_key((string) $role);
            if (!$role) continue;
            if (!in_array($role, $valid_roles, true)) continue;
            $normalized[] = $role;
        }
        $normalized = array_values(array_unique($normalized));

        $normalized_users = [];
        foreach ($users as $u) {
            $uid = is_array($u) && isset($u['id']) ? (int) $u['id'] : (int) $u;
            if ($uid <= 0) continue;
            $wp_user = get_user_by('id', $uid);
            $normalized_users[$uid] = [
                'id' => (string) $uid,
                'name' => $wp_user ? $wp_user->display_name : (is_array($u) && isset($u['name']) ? sanitize_text_field((string) $u['name']) : (string) $uid),
            ];
        }
        $users = array_values($normalized_users);

        global $wpdb;
        $perms = self::table('permissions');
        $wpdb->delete($perms, [
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'subject_type' => 'role',
            'capability' => 'view',
        ], ['%s','%d','%s','%s']);
        $wpdb->delete($perms, [
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'subject_type' => 'user',
            'capability' => 'view',
        ], ['%s','%d','%s','%s']);

        $now = current_time('mysql');
        foreach ($normalized as $role) {
            $wpdb->insert($perms, [
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'subject_type' => 'role',
                'subject_key' => $role,
                'capability' => 'view',
                'created_by' => $user_id,
                'created_at' => $now,
            ], ['%s','%d','%s','%s','%s','%d','%s']);
        }

        foreach ($users as $u) {
            $uid = (int) $u['id'];
            if ($uid <= 0) continue;
            $wpdb->insert($perms, [
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'subject_type' => 'user',
                'subject_key' => (string) $uid,
                'capability' => 'view',
                'created_by' => $user_id,
                'created_at' => $now,
            ], ['%s','%d','%s','%s','%s','%d','%s']);
        }

        $policy = $this->save_permission_policy($entity_type, $entity_id, 'view', $policy_input, $user_id);

        $this->log_activity($user_id, 'set_permissions', $entity_type, $entity_id, ['roles' => $normalized, 'users' => $users, 'policy' => $policy]);
        $this->json_success([
            'saved' => true,
            'roles' => $normalized,
            'users' => $users,
            'policy' => $this->enrich_permission_policy_for_response($policy),
        ]);
    }

    public function ajax_user_search() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        if (!current_user_can('administrator')) $this->json_error('Forbidden', 403);

        $term = isset($_POST['term']) ? sanitize_text_field((string) $_POST['term']) : '';
        $term = trim($term);
        if ($term === '') $this->json_success(['users' => []]);

        $users = get_users([
            'search' => '*' . $term . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => 10,
            'fields' => ['ID', 'display_name', 'user_email'],
        ]);
        $out = [];
        foreach ((array) $users as $u) {
            $out[] = [
                'id' => (int) $u->ID,
                'displayName' => $u->display_name,
                'email' => $u->user_email,
            ];
        }
        $this->json_success(['users' => $out]);
    }

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

    private function get_editable_roles_for_permissions() {
        $roles = (array) wp_roles()->roles;
        $out = [];
        foreach ($roles as $key => $meta) {
            $key = sanitize_key((string) $key);
            if ($key === 'administrator') continue;
            $out[] = [
                'key' => $key,
                'label' => isset($meta['name']) ? $meta['name'] : $key,
            ];
        }
        return $out;
    }

    private function get_product_docs($product_id) {
        $docs = get_post_meta($product_id, self::META_PRODUCT_DOCS, true);
        if (!is_array($docs)) return [];
        $out = [];
        foreach ($docs as $doc) {
            if (!is_array($doc)) continue;
            $file_id = isset($doc['fileId']) ? (int) $doc['fileId'] : 0;
            if ($file_id <= 0) continue;
            $title = isset($doc['title']) ? sanitize_text_field((string) $doc['title']) : '';
            $expires = isset($doc['expires']) ? sanitize_text_field((string) $doc['expires']) : '';
            $out[] = [
                'fileId' => $file_id,
                'title' => $title !== '' ? $title : '',
                'expires' => $expires,
            ];
        }
        return $out;
    }

    private function doc_is_expired($expires) {
        if (empty($expires)) return false;
        $ts = strtotime($expires . ' 23:59:59');
        if (!$ts) return false;
        return $ts < current_time('timestamp');
    }

    private function user_has_product($user_id, $product_id) {
        if (!function_exists('wc_get_orders')) return false;
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => -1,
            'status' => ['wc-completed', 'wc-processing', 'wc-on-hold'],
        ]);
        if (!$orders) return false;
        foreach ($orders as $order) {
            if (!is_a($order, 'WC_Order')) continue;
            foreach ($order->get_items() as $item) {
                if (!is_a($item, 'WC_Order_Item_Product')) continue;
                $pid = $item->get_product_id();
                $variation = $item->get_variation_id();
                if ((int) $pid === (int) $product_id || (int) $variation === (int) $product_id) {
                    return true;
                }
            }
        }
        return false;
    }

    private function user_can_view_file_via_product($user_id, $file_id) {
        static $map = null;
        if (!function_exists('wc_get_products')) return false;
        if ($map === null) {
            $map = [];
            $products = get_posts([
                'post_type' => 'product',
                'posts_per_page' => -1,
                'meta_key' => self::META_PRODUCT_DOCS,
                'post_status' => 'publish',
            ]);
            foreach ((array) $products as $p) {
                $pid = (int) $p->ID;
                $docs = $this->get_product_docs($pid);
                foreach ($docs as $doc) {
                    $fid = (int) $doc['fileId'];
                    if ($fid <= 0) continue;
                    if (!isset($map[$fid])) $map[$fid] = [];
                    $map[$fid][] = [
                        'product_id' => $pid,
                        'expires' => $doc['expires'],
                    ];
                }
            }
        }
        if (empty($map[$file_id])) return false;
        foreach ($map[$file_id] as $entry) {
            if ($this->doc_is_expired($entry['expires'])) continue;
            if ($this->user_has_product($user_id, (int) $entry['product_id'])) {
                return true;
            }
        }
        return false;
    }

    public function ajax_ap_orders() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        if (!function_exists('wc_get_orders')) $this->json_error('WooCommerce not available', 400);

        $user_id = get_current_user_id();
        $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        $per_page = 20;

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $out = [];
        foreach ((array) $orders as $order) {
            if (!is_a($order, 'WC_Order')) continue;
            $out[] = [
                'id' => (int) $order->get_id(),
                'number' => $order->get_order_number(),
                'status' => $order->get_status(),
                'statusLabel' => function_exists('wc_get_order_status_name') ? wc_get_order_status_name($order->get_status()) : $order->get_status(),
                'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format')) : '',
                'total' => $order->get_total(),
                'totalHtml' => function_exists('wc_price') ? wc_price($order->get_total(), ['currency' => $order->get_currency()]) : (string) $order->get_total(),
                'items' => (int) $order->get_item_count(),
            ];
        }

        $this->json_success(['orders' => $out, 'page' => $page]);
    }

    public function ajax_ap_order() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        if (!function_exists('wc_get_order')) $this->json_error('WooCommerce not available', 400);

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        if ($order_id <= 0) $this->json_error('Missing order_id');

        $order = wc_get_order($order_id);
        if (!$order || !is_a($order, 'WC_Order')) $this->json_error('Not found', 404);

        if ((int) $order->get_customer_id() !== (int) get_current_user_id()) {
            $this->json_error('Forbidden', 403);
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) continue;
            $product = $item->get_product();
            $items[] = [
                'name' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'totalHtml' => function_exists('wc_price') ? wc_price($item->get_total(), ['currency' => $order->get_currency()]) : (string) $item->get_total(),
                'sku' => $product ? (string) $product->get_sku() : '',
            ];
        }

        $this->json_success([
            'order' => [
                'id' => (int) $order->get_id(),
                'number' => $order->get_order_number(),
                'status' => $order->get_status(),
                'statusLabel' => function_exists('wc_get_order_status_name') ? wc_get_order_status_name($order->get_status()) : $order->get_status(),
                'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format')) : '',
                'totalHtml' => function_exists('wc_price') ? wc_price($order->get_total(), ['currency' => $order->get_currency()]) : (string) $order->get_total(),
                'paymentMethod' => (string) $order->get_payment_method_title(),
            ],
            'items' => $items,
        ]);
    }

    public function ajax_ap_update_profile() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $first = isset($_POST['first_name']) ? sanitize_text_field((string) $_POST['first_name']) : '';
        $last = isset($_POST['last_name']) ? sanitize_text_field((string) $_POST['last_name']) : '';
        $email = isset($_POST['user_email']) ? sanitize_email((string) $_POST['user_email']) : '';

        if ($email === '' || !is_email($email)) {
            $this->json_error('Invalid email address', 400);
        }

        $existing = email_exists($email);
        if ($existing && (int) $existing !== (int) $user_id) {
            $this->json_error('Email already in use', 400);
        }

        $res = wp_update_user([
            'ID' => $user_id,
            'user_email' => $email,
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => trim($first . ' ' . $last) ?: wp_get_current_user()->display_name,
        ]);
        if (is_wp_error($res)) {
            $this->json_error($res->get_error_message(), 400);
        }

        $this->log_activity($user_id, 'update_profile', 'user', $user_id, []);
        $this->json_success(['saved' => true]);
    }

    public function ajax_ap_change_password() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user_id = get_current_user_id();
        $new = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $new = trim($new);
        if (strlen($new) < 10) {
            $this->json_error('Password must be at least 10 characters', 400);
        }

        $user = get_user_by('id', $user_id);
        if (!$user) $this->json_error('User not found', 404);

        wp_set_password($new, $user_id);
        $this->log_activity($user_id, 'change_password', 'user', $user_id, []);
        $this->json_success(['saved' => true, 'requiresReauth' => true, 'loginUrl' => wp_login_url()]);
    }

    public function ajax_ap_send_reset() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);

        $user = wp_get_current_user();
        if (!$user || empty($user->user_email)) $this->json_error('No email on account', 400);

        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            $this->json_error($key->get_error_message(), 400);
        }

        $reset_url = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login), 'login');
        $subject = sprintf('[%s] Password reset', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = "A password reset was requested for your account.\n\n";
        $message .= "Reset your password:\n{$reset_url}\n\n";
        $message .= "If you didn’t request this, you can ignore this email.\n";

        wp_mail($user->user_email, $subject, $message);
        $this->log_activity((int) $user->ID, 'send_password_reset', 'user', (int) $user->ID, []);
        $this->json_success(['sent' => true]);
    }

    public function ajax_pd_products() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        if (!current_user_can('manage_woocommerce')) $this->json_error('Forbidden', 403);
        if (!function_exists('wc_get_products')) $this->json_error('WooCommerce not available', 400);

        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'return' => 'objects',
        ]);

        $out = [];
        foreach ((array) $products as $product) {
            $docs = $this->get_product_docs($product->get_id());
            $out[] = [
                'id' => (int) $product->get_id(),
                'name' => $product->get_name(),
                'docs' => array_values(array_map(function ($d) {
                    return [
                        'fileId' => (int) $d['fileId'],
                        'title' => $d['title'],
                        'expires' => $d['expires'],
                    ];
                }, $docs)),
            ];
        }

        $this->json_success(['products' => $out]);
    }

    public function ajax_pd_save_docs() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        if (!current_user_can('manage_woocommerce')) $this->json_error('Forbidden', 403);

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $docs = isset($_POST['docs']) ? (array) $_POST['docs'] : [];
        if ($product_id <= 0) $this->json_error('Missing product_id');

        $clean = [];
        foreach ($docs as $doc) {
            if (!is_array($doc)) continue;
            $file_id = isset($doc['fileId']) ? (int) $doc['fileId'] : 0;
            if ($file_id <= 0) continue;
            $title = isset($doc['title']) ? sanitize_text_field((string) $doc['title']) : '';
            $expires = isset($doc['expires']) ? sanitize_text_field((string) $doc['expires']) : '';
            $clean[] = [
                'fileId' => $file_id,
                'title' => $title,
                'expires' => $expires,
                'fileName' => isset($doc['fileName']) ? sanitize_text_field((string) $doc['fileName']) : '',
            ];
        }

        update_post_meta($product_id, self::META_PRODUCT_DOCS, $clean);
        $this->log_activity(get_current_user_id(), 'save_product_docs', 'product', $product_id, ['count' => count($clean)]);
        $this->json_success(['saved' => true]);
    }

    public function ajax_pd_my_docs() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        if (!function_exists('wc_get_products')) $this->json_error('WooCommerce not available', 400);

        $user_id = get_current_user_id();
        $products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_key' => self::META_PRODUCT_DOCS,
            'post_status' => 'publish',
        ]);

        $docs_out = [];
        foreach ((array) $products as $p) {
            $pid = (int) $p->ID;
            if (!$this->user_has_product($user_id, $pid)) continue;
            $docs = $this->get_product_docs($pid);
            foreach ($docs as $doc) {
                if ($this->doc_is_expired($doc['expires'])) continue;
                $file = $this->get_file_row((int) $doc['fileId']);
                if (!$file) continue;
                $nonce = wp_create_nonce('anchor_fm_stream_' . (int) $file->id);
                $download_url = add_query_arg([
                    'action' => 'anchor_fm_stream',
                    'file_id' => (int) $file->id,
                    'disposition' => 'attachment',
                    'nonce' => $nonce,
                ], admin_url('admin-ajax.php'));
                // A row is not a promise of bytes. Handing out a Download link
                // the stream endpoint will answer with a bare 404 strands the
                // customer on a browser error page, so resolve the file here
                // and let the client render the difference.
                $path = $this->get_file_path_on_disk($file);
                $available = file_exists($path) && is_readable($path);
                if (!$available) {
                    self::log_stream_refusal((int) $file->id, 'product docs: ' . $this->describe_unreadable_path($path));
                }

                $docs_out[] = [
                    'fileId' => (int) $file->id,
                    'title' => $doc['title'] ?: $file->original_name,
                    'product' => get_the_title($pid),
                    'productId' => $pid,
                    'expires' => $doc['expires'],
                    'downloadUrl' => $download_url,
                    'available' => $available,
                ];
            }
        }

        $this->json_success(['docs' => $docs_out]);
    }

    public function ajax_pd_upload() {
        $this->require_nonce();
        if (!is_user_logged_in()) $this->json_error('Unauthorized', 401);
        if (!current_user_can('manage_woocommerce')) $this->json_error('Forbidden', 403);

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        if ($product_id <= 0) $this->json_error('Missing product_id');
        if (empty($_FILES['file'])) $this->json_error('No file');

        $folder_id = self::ensure_product_docs_folder();
        self::ensure_upload_storage();
        $folder_dir = trailingslashit($this->get_storage_dir()) . $folder_id;
        if (!file_exists($folder_dir)) {
            wp_mkdir_p($folder_dir);
            $htaccess = $folder_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Deny from all\n");
            }
            $index = $folder_dir . '/index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, "<?php\n// Silence is golden.\n");
            }
        }

        $file = $_FILES['file'];
        $original = (string) $file['name'];
        $tmp = (string) $file['tmp_name'];
        $size = (int) $file['size'];
        if (!file_exists($tmp) || !is_uploaded_file($tmp)) {
            $this->json_error('Upload failed', 400);
        }

        $sanitized = sanitize_file_name($original);
        $unique = wp_unique_filename($folder_dir, $sanitized);
        $valid = $this->validate_upload_type($tmp, $unique);
        if ($valid === false) {
            $this->json_error('File type not allowed', 415);
        }
        $mime = $valid['type'];
        $dest = trailingslashit($folder_dir) . $unique;

        if (!@move_uploaded_file($tmp, $dest)) {
            $this->json_error('Could not save file', 500);
        }

        global $wpdb;
        $files_table = self::table('files');
        $wpdb->insert($files_table, [
            'folder_id' => $folder_id,
            'original_name' => $original,
            'stored_name' => $unique,
            'mime_type' => $mime,
            'size' => $size,
            'sha1' => @sha1_file($dest) ?: null,
            'uploader_user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);
        $file_id = (int) $wpdb->insert_id;

        $this->log_activity(get_current_user_id(), 'upload_product_doc', 'file', $file_id, ['product_id' => $product_id, 'name' => $original]);

        $this->json_success(['file' => [
            'id' => $file_id,
            'name' => $original,
            'mime' => $mime,
            'size' => $size,
        ]]);
    }
}

register_activation_hook(__FILE__, ['Anchor_Private_File_Manager', 'activate']);
register_deactivation_hook(__FILE__, ['Anchor_Private_File_Manager', 'deactivate']);
Anchor_Private_File_Manager::instance();

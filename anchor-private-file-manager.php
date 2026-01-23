<?php
/**
 * Plugin Name: Anchor Private File Manager
 * Description: Secure, modern private file manager with folders, role permissions, previews, and logging.
 * Version: 2.1.8
 * Author: Anchor Corps
 */

if (!defined('ABSPATH')) exit;

class Anchor_Private_File_Manager {

    const VERSION = '2.1.0';
    const NONCE_ACTION = 'anchor_fm_nonce';
    const OPT_DB_VERSION = 'anchor_fm_db_version';
    const OPT_EMAIL_ON_UPLOAD = 'anchor_fm_email_on_upload';
    const META_PRODUCT_DOCS = '_anchor_pd_docs';
    const OPT_PD_FOLDER_ID = 'anchor_fm_pd_folder_id';

    private static $instance = null;

    public function __construct() {
        add_shortcode('anchor_file_manager', [$this, 'render_file_manager']);
        add_shortcode('anchor_account_portal', [$this, 'render_account_portal']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

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
        add_action('wp_ajax_anchor_fm_download_folder', [$this, 'ajax_download_folder']);

        add_action('wp_ajax_anchor_fm_get_permissions', [$this, 'ajax_get_permissions']);
        add_action('wp_ajax_anchor_fm_set_permissions', [$this, 'ajax_set_permissions']);
        add_action('wp_ajax_anchor_fm_user_search', [$this, 'ajax_user_search']);

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

        update_option(self::OPT_DB_VERSION, self::VERSION);

        self::ensure_upload_storage();
        self::ensure_product_docs_folder();
    }

    private static function ensure_upload_storage() {
        $upload_dir = wp_upload_dir();
        $base = trailingslashit($upload_dir['basedir']) . 'anchor-private-files';
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

    public function enqueue_assets() {
        if (!is_user_logged_in()) return;
        if (!$this->should_enqueue_assets()) return;

        $css_path = plugin_dir_path(__FILE__) . 'assets/css/file-manager.css';
        $js_path = plugin_dir_path(__FILE__) . 'assets/js/file-manager.js';
        $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : self::VERSION;
        $js_ver = file_exists($js_path) ? (string) filemtime($js_path) : self::VERSION;

        $ap_css_path = plugin_dir_path(__FILE__) . 'assets/css/account-portal.css';
        $ap_js_path = plugin_dir_path(__FILE__) . 'assets/js/account-portal.js';
        $ap_css_ver = file_exists($ap_css_path) ? (string) filemtime($ap_css_path) : self::VERSION;
        $ap_js_ver = file_exists($ap_js_path) ? (string) filemtime($ap_js_path) : self::VERSION;

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
        wp_localize_script('anchor-file-manager', 'AnchorFM', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'isAdmin' => current_user_can('administrator'),
            'productDocsFolderId' => $product_docs_id,
            'user' => [
                'id' => get_current_user_id(),
                'roles' => array_values((array) $user->roles),
                'displayName' => $user->display_name,
            ],
            'roles' => $this->get_editable_roles_for_permissions(),
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
            ],
        ]);

        // Account Portal assets (reuses AFM base styles).
        wp_enqueue_style(
            'anchor-account-portal',
            plugin_dir_url(__FILE__) . 'assets/css/account-portal.css',
            ['anchor-file-manager'],
            $ap_css_ver
        );
        wp_enqueue_script(
            'anchor-account-portal',
            plugin_dir_url(__FILE__) . 'assets/js/account-portal.js',
            ['jquery'],
            $ap_js_ver,
            true
        );
        wp_localize_script('anchor-account-portal', 'AnchorAP', [
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
                'files' => __('Files', 'anchor-private-file-manager'),
            ],
        ]);
    }

    public function render_file_manager() {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to access files.</p>';
        }

        // Allow any logged-in user: access is governed by folder/file permissions.
        $user_id = get_current_user_id();

        if (!$this->user_can_access_anything($user_id)) {
            return '<p>You do not have permission to view these files.</p>';
        }

        ob_start(); ?>
        <div id="anchor-file-manager" class="afm" data-afm>
            <div class="afm__frame">
                <aside class="afm__sidebar" aria-label="<?php esc_attr_e('Folders', 'anchor-private-file-manager'); ?>">
                    <div class="afm__brand">
                        <img class="afm__brandMark" src="https://tmjtherapycentre.com/wp-content/uploads/2023/02/TMJ_INT_Favicon_96x96.png" aria-hidden="true"></img>
                        <div class="afm__brandText">
                            <div class="afm__brandTitle"><?php esc_html_e('Centre Files', 'anchor-private-file-manager'); ?></div>
                            <div class="afm__brandSub"><?php esc_html_e('File manager', 'anchor-private-file-manager'); ?></div>
                        </div>
                    </div>
                    <div class="afm__sidebarActions">
                        <button type="button" class="afm__btn afm__btn--ghost" data-afm-action="new-folder">
                            <span class="dashicons dashicons-plus" aria-hidden="true"></span>
                            <?php esc_html_e('New folder', 'anchor-private-file-manager'); ?>
                        </button>
                    </div>
                    <div class="afm__tree" data-afm-tree></div>
                </aside>

                <main class="afm__main" aria-label="<?php esc_attr_e('Files', 'anchor-private-file-manager'); ?>">
                    <header class="afm__toolbar">
                        <div class="afm__breadcrumbs">
                            <div class="afm__breadcrumbsTrail" data-afm-breadcrumbs></div>
                        </div>
                        <div class="afm__toolbarRight">
                            <label class="afm__search">
                                <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                <input type="search" placeholder="<?php esc_attr_e('Search in folder…', 'anchor-private-file-manager'); ?>" data-afm-search>
                            </label>
                            <div class="afm__upload">
                                <input type="file" multiple class="afm__fileInput" data-afm-file-input>
                                <button type="button" class="afm__btn afm__btn--primary" data-afm-action="upload">
                                    <span class="dashicons dashicons-upload" aria-hidden="true"></span>
                                    <?php esc_html_e('Upload', 'anchor-private-file-manager'); ?>
                                </button>
                            </div>
                        </div>
                    </header>

                    <section class="afm__content">
                        <div class="afm__panel is-active" data-afm-panel="files">
                            <div class="afm__dropzone" data-afm-dropzone>
                                <div class="afm__dropzoneInner">
                                    <div class="afm__dropIcon dashicons dashicons-cloud-upload" aria-hidden="true"></div>
                                    <div class="afm__dropTitle"><?php esc_html_e('Drop files to upload', 'anchor-private-file-manager'); ?></div>
                                    <div class="afm__dropHint"><?php esc_html_e('Or use the Upload button', 'anchor-private-file-manager'); ?></div>
                                </div>
                            </div>
                            <div class="afm__grid" data-afm-grid></div>
                        </div>

                        <div class="afm__panel" data-afm-panel="product-docs">
                            <div class="afm__twoCol">
                                <div class="afm__cardBox">
                                    <div class="afm__sectionTitle"><?php esc_html_e('Product Documents', 'anchor-private-file-manager'); ?></div>
                                    <div class="afm__grid" data-afm-product-docs></div>
                                </div>
                                <?php if (current_user_can('administrator')) : ?>
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
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                </main>

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
        return has_shortcode($content, 'anchor_file_manager') || has_shortcode($content, 'anchor_account_portal');
    }

    public function render_account_portal() {
        if (!is_user_logged_in()) {
            return '<p>You must be logged in to access your account.</p>';
        }

        ob_start(); ?>
        <div class="afm aap" data-aap>
            <div class="afm__frame">
                <aside class="afm__sidebar" aria-label="<?php esc_attr_e('Account navigation', 'anchor-private-file-manager'); ?>">
                    <div class="afm__brand">
                        <img class="afm__brandMark" src="https://tmjtherapycentre.com/wp-content/uploads/2023/02/TMJ_INT_Favicon_96x96.png" aria-hidden="true"></img>
                        <div class="afm__brandText">
                            <div class="afm__brandTitle"><?php esc_html_e('My Account', 'anchor-private-file-manager'); ?></div>
                            <div class="afm__brandSub"><?php echo esc_html(wp_get_current_user()->display_name); ?></div>
                        </div>
                    </div>
                    <nav class="aap__nav" aria-label="<?php esc_attr_e('Sections', 'anchor-private-file-manager'); ?>">
                        <button type="button" class="aap__navItem is-active" data-aap-tab="account">
                            <span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
                            <?php esc_html_e('Account', 'anchor-private-file-manager'); ?>
                        </button>
                        <button type="button" class="aap__navItem" data-aap-tab="orders">
                            <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                            <?php esc_html_e('Orders', 'anchor-private-file-manager'); ?>
                        </button>
                        <button type="button" class="aap__navItem" data-aap-tab="downloads">
                            <span class="dashicons dashicons-download" aria-hidden="true"></span>
                            <?php esc_html_e('Downloads', 'anchor-private-file-manager'); ?>
                        </button>
                        <button type="button" class="aap__navItem" data-aap-tab="security">
                            <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                            <?php esc_html_e('Security', 'anchor-private-file-manager'); ?>
                        </button>
                        <a class="aap__navItem aap__navItem--link" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
                            <span class="dashicons dashicons-exit" aria-hidden="true"></span>
                            <?php esc_html_e('Log out', 'anchor-private-file-manager'); ?>
                        </a>
                    </nav>
                </aside>

                <main class="afm__main" aria-label="<?php esc_attr_e('Account content', 'anchor-private-file-manager'); ?>">
                    <header class="afm__toolbar">
                        <div class="afm__breadcrumbs">
                            <span class="aap__title" data-aap-title><?php esc_html_e('Account', 'anchor-private-file-manager'); ?></span>
                        </div>
                        <div class="afm__toolbarRight">
                            <button type="button" class="afm__btn afm__btn--secondary" data-aap-action="refresh">
                                <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                <?php esc_html_e('Refresh', 'anchor-private-file-manager'); ?>
                            </button>
                        </div>
                    </header>

                    <section class="afm__content">
                        <div class="aap__panel is-active" data-aap-panel="orders">
                            <div class="aap__grid" data-aap-orders></div>
                        </div>

                        <div class="aap__panel" data-aap-panel="downloads">
                            <div class="aap__grid" data-aap-downloads></div>
                        </div>

                        <div class="aap__panel" data-aap-panel="account">
                            <form class="aap__form" data-aap-profile-form>
                                <div class="aap__formRow">
                                    <label class="aap__label"><?php esc_html_e('First name', 'anchor-private-file-manager'); ?></label>
                                    <input type="text" class="afm__input" name="first_name" value="<?php echo esc_attr(wp_get_current_user()->first_name); ?>">
                                </div>
                                <div class="aap__formRow">
                                    <label class="aap__label"><?php esc_html_e('Last name', 'anchor-private-file-manager'); ?></label>
                                    <input type="text" class="afm__input" name="last_name" value="<?php echo esc_attr(wp_get_current_user()->last_name); ?>">
                                </div>
                                <div class="aap__formRow">
                                    <label class="aap__label"><?php esc_html_e('Email', 'anchor-private-file-manager'); ?></label>
                                    <input type="email" class="afm__input" name="user_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                                </div>
                                <button type="submit" class="afm__btn afm__btn--primary">
                                    <span class="dashicons dashicons-saved" aria-hidden="true"></span>
                                    <?php esc_html_e('Save changes', 'anchor-private-file-manager'); ?>
                                </button>
                                <div class="aap__notice" data-aap-profile-notice hidden></div>
                            </form>
                        </div>

                        <div class="aap__panel" data-aap-panel="security">
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
                </main>

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
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function user_can_access_anything($user_id) {
        if (user_can($user_id, 'administrator')) {
            return true;
        }

        $roles = $this->user_roles_lower($user_id);
        $role_keys = $roles ? array_map('sanitize_key', $roles) : [];
        if (!$role_keys) return false;

        global $wpdb;
        $perms = self::table('permissions');
        $placeholders = implode(',', array_fill(0, count($role_keys), '%s'));
        $query = "SELECT COUNT(1) FROM {$perms} WHERE subject_type = 'role' AND capability = 'view' AND subject_key IN ({$placeholders})";
        $args = array_merge([$query], $role_keys);
        $sql = call_user_func_array([$wpdb, 'prepare'], $args);
        $count = (int) $wpdb->get_var($sql);
        return $count > 0;
    }

    private function get_storage_dir() {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . 'anchor-private-files';
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
        return $count > 0;
    }

    private function copy_view_permissions($from_type, $from_id, $to_type, $to_id, $overwrite = false) {
        global $wpdb;
        $perms = self::table('permissions');

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

        if (!$rows) return;

        $now = current_time('mysql');
        foreach ($rows as $r) {
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
        $is_admin = user_can($user_id, 'administrator');

        $by_id = [];
        foreach ($all as $row) {
            if ((int) $row->id === $product_docs_id && !$is_admin) {
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
        if ($folder_id === $product_docs_id && !user_can($user_id, 'administrator')) {
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
            if ((int) $f->id === $product_docs_id && !user_can($user_id, 'administrator')) continue;
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

        $cap = $folder_id === 0 ? (user_can($user_id, 'administrator') ? 'manage' : 'view') : $this->get_effective_capability($user_id, 'folder', $folder_id);
        $this->json_success([
            'folderId' => $folder_id,
            'breadcrumbs' => $folder_id === 0 ? [] : $this->build_breadcrumbs($folder_id),
            'folders' => $subfolders,
            'files' => $file_list,
            'capability' => $cap,
            'isProductDocs' => $folder_id === $product_docs_id,
        ]);
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
            }

            $dph = implode(',', array_fill(0, count($folder_ids), '%d'));
            $wpdb->query(call_user_func_array([$wpdb, 'prepare'], array_merge(
                ["DELETE FROM {$perms_table} WHERE entity_type = 'folder' AND entity_id IN ({$dph})"],
                $folder_ids
            )));

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

            $ft = wp_check_filetype_and_ext($tmp, $unique);
            $mime = !empty($ft['type']) ? $ft['type'] : 'application/octet-stream';

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

        $this->json_success(['uploaded' => $uploaded]);
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

        $this->log_activity($user_id, 'delete_file', 'file', $file_id, ['name' => $file->original_name]);
        $this->json_success(['fileId' => $file_id]);
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

        $mime = (string) $file->mime_type;
        $type = 'none';

        if (strpos($mime, 'image/') === 0) {
            $type = 'image';
        } elseif ($mime === 'application/pdf') {
            $type = 'pdf';
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
        if ($type === 'text') {
            $path = $this->get_file_path_on_disk($file);
            if (file_exists($path) && is_readable($path)) {
                $raw = @file_get_contents($path, false, null, 0, 4000);
                if (is_string($raw)) {
                    $text_excerpt = wp_strip_all_tags($raw);
                }
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
                'type' => $type,
                'inlineUrl' => $inline_url,
                'downloadUrl' => $download_url,
                'textExcerpt' => $text_excerpt,
            ],
            'capability' => $this->get_effective_capability($user_id, 'file', $file_id),
        ]);
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
            status_header(403);
            exit;
        }

        $user_id = get_current_user_id();
        if (!$this->can_user_view_file($user_id, $file_id)) {
            status_header(403);
            exit;
        }

        $file = $this->get_file_row($file_id);
        if (!$file) {
            status_header(404);
            exit;
        }

        $path = $this->get_file_path_on_disk($file);
        if (!file_exists($path) || !is_readable($path)) {
            status_header(404);
            exit;
        }

        $disp = $disposition === 'inline' ? 'inline' : 'attachment';
        $filename = sanitize_file_name($file->original_name);

        $this->log_activity($user_id, $disp === 'inline' ? 'preview_file' : 'download_file', 'file', $file_id, []);

        nocache_headers();
        header('Content-Type: ' . $file->mime_type);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . $disp . '; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        @readfile($path);
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
        if (!in_array($entity_type, ['file', 'folder'], true) || $entity_id <= 0) $this->json_error('Missing fields');

        $allowed = $entity_type === 'file'
            ? $this->can_user_manage_file($user_id, $entity_id)
            : $this->can_user_manage_folder($user_id, $entity_id);
        if (!$allowed) $this->json_error('Forbidden', 403);

        $valid_roles = array_keys((array) wp_roles()->roles);
        $valid_roles = array_map('sanitize_key', $valid_roles);

        $normalized = [];
        foreach ($roles as $role) {
            $role = sanitize_key((string) $role);
            if (!$role) continue;
            if (!in_array($role, $valid_roles, true)) continue;
            if ($role === 'administrator') continue;
            $normalized[] = $role;
        }
        $normalized = array_values(array_unique($normalized));

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

        $this->log_activity($user_id, 'set_permissions', $entity_type, $entity_id, ['roles' => $normalized, 'users' => $users]);
        $this->json_success(['saved' => true, 'roles' => $normalized, 'users' => $users]);
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
                $docs_out[] = [
                    'fileId' => (int) $file->id,
                    'title' => $doc['title'] ?: $file->original_name,
                    'product' => get_the_title($pid),
                    'productId' => $pid,
                    'expires' => $doc['expires'],
                    'downloadUrl' => $download_url,
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
        $ft = wp_check_filetype_and_ext($tmp, $unique);
        $mime = !empty($ft['type']) ? $ft['type'] : 'application/octet-stream';
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
Anchor_Private_File_Manager::instance();

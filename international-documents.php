<?php
/*
Plugin Name: International Documents
Description: Attach downloadable documents to products and display them in the My Account downloads tab. Includes a FunnelKit merge tag for order emails, cache bust tools for admin assets, Centre role document sharing, and optional expiry dates per document.
Version: 1.7.1
Author: Joel Martin
*/

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WC_PD_PLUGIN_FILE', __FILE__ );
define( 'WC_PD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$wc_pd_puc_loader = WC_PD_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
if ( ! class_exists( PucFactory::class ) && file_exists( $wc_pd_puc_loader ) ) {
    require_once $wc_pd_puc_loader;
}

if ( class_exists( PucFactory::class ) ) {
    $wc_pd_update_checker = PucFactory::buildUpdateChecker(
        'https://github.com/joelhmartin/international-hub/',
        WC_PD_PLUGIN_FILE,
        'international-documents'
    );
    $wc_pd_update_checker->setBranch( 'main' );

    $wc_pd_token = $_ENV['GITHUB_ACCESS_TOKEN']
        ?? getenv( 'GITHUB_ACCESS_TOKEN' )
        ?: ( defined( 'GITHUB_ACCESS_TOKEN' ) ? GITHUB_ACCESS_TOKEN : null );

    if ( $wc_pd_token ) {
        $wc_pd_update_checker->setAuthentication( $wc_pd_token );
    }

    $wc_pd_vcs = method_exists( $wc_pd_update_checker, 'getVcsApi' ) ? $wc_pd_update_checker->getVcsApi() : null;
    if ( $wc_pd_vcs && method_exists( $wc_pd_vcs, 'enableReleaseAssets' ) ) {
        $wc_pd_vcs->enableReleaseAssets();
    }

    add_filter(
        'upgrader_pre_download',
        function( $reply, $package ) {
            error_log( '[International Documents] pre_download package=' . $package );
            return $reply;
        },
        10,
        2
    );
    add_filter(
        'upgrader_source_selection',
        function( $source ) {
            error_log( '[International Documents] source_selection source=' . $source );
            return $source;
        },
        10,
        1
    );
}

add_action( 'plugins_loaded', function() {
    if ( class_exists( 'WooCommerce' ) ) {
        new WC_Product_Documents();
    }
});

class WC_Product_Documents {

    const BASE_VERSION  = '1.7.1';
    const OPT_ASSET_VER     = 'wc_pd_asset_ver';
    const OPT_CENTRE_DOCS   = 'wc_pd_centre_docs';

    public function __construct() {
        if ( get_option( self::OPT_ASSET_VER ) === false ) {
            add_option( self::OPT_ASSET_VER, self::BASE_VERSION );
        }
        if ( get_option( self::OPT_CENTRE_DOCS ) === false ) {
            add_option( self::OPT_CENTRE_DOCS, [] );
        }

        add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
        add_action( 'admin_init', [ $this, 'save_product_docs' ] );
        add_action( 'admin_init', [ $this, 'save_centre_docs' ] );
        add_action( 'admin_init', [ $this, 'handle_force_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts' ] );
        add_filter( 'woocommerce_customer_get_downloadable_products', [ $this, 'add_docs_to_downloads' ] );
    }

    private function asset_version() : string {
        $ver = get_option( self::OPT_ASSET_VER, self::BASE_VERSION );
        if ( isset( $_GET['pdver'] ) && current_user_can( 'manage_woocommerce' ) ) {
            $ver = sanitize_text_field( wp_unslash( $_GET['pdver'] ) );
        }
        return (string) $ver;
    }

    private function sanitize_expiry_field( $value ) : string {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( $value === '' ) return '';

        $dt = null;

        if ( preg_match( '#^\d{1,2}/\d{1,2}/\d{4}$#', $value ) ) {
            $dt = DateTime::createFromFormat( 'm/d/Y', $value );
        } else {
            $dt = DateTime::createFromFormat( 'Y-m-d', $value );
        }

        if ( ! $dt ) {
            $timestamp = strtotime( $value );
            if ( ! $timestamp ) return '';
            return date( 'Y-m-d', $timestamp );
        }

        return $dt->format( 'Y-m-d' );
    }

    private function format_expiry_display( $value ) : string {
        if ( empty( $value ) ) return '';
        $dt = DateTime::createFromFormat( 'Y-m-d', $value );
        if ( ! $dt ) {
            $timestamp = strtotime( $value );
            if ( ! $timestamp ) return '';
            return date( 'm/d/Y', $timestamp );
        }
        return $dt->format( 'm/d/Y' );
    }

    private function document_is_expired( $doc ) : bool {
        if ( empty( $doc['expires'] ) ) {
            return false;
        }

        $ts = strtotime( $doc['expires'] . ' 23:59:59' );
        if ( ! $ts ) return false;

        return $ts < current_time( 'timestamp' );
    }

    public function admin_scripts( $hook ) {
        if ( $hook !== 'toplevel_page_international-documents' ) return;

        wp_enqueue_media();
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'wp-jquery-ui-dialog' );

        $ver = $this->asset_version();

        wp_enqueue_script(
            'wc-product-docs',
            plugin_dir_url( __FILE__ ) . 'js/wc-product-docs.js',
            [ 'jquery' ],
            $ver,
            true
        );

        wp_enqueue_style(
            'wc-product-docs-css',
            plugin_dir_url( __FILE__ ) . 'css/wc-product-docs.css',
            [],
            $ver
        );
    }

    public function add_admin_page() {
        add_menu_page(
            'International Documents',
            'International Documents',
            'manage_woocommerce',
            'international-documents',
            [ $this, 'render_admin_page' ]
        );
    }

    public function render_admin_page() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'products';
        if ( ! in_array( $active_tab, [ 'products', 'centre' ], true ) ) {
            $active_tab = 'products';
        }

        echo '<div class="wrap"><h1>International Documents</h1>';

        $this->render_asset_controls();

        $tabs = [
            'products' => 'Product Documents',
            'centre'   => 'Centre Documents',
        ];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $slug => $label ) {
            $classes = 'nav-tab';
            if ( $slug === $active_tab ) {
                $classes .= ' nav-tab-active';
            }
            $url = add_query_arg(
                [
                    'page' => 'international-documents',
                    'tab'  => $slug,
                ],
                admin_url( 'admin.php' )
            );
            echo '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</h2>';

        if ( $active_tab === 'centre' ) {
            $this->render_centre_docs_tab();
        } else {
            $this->render_product_docs_tab();
        }

        $this->render_doc_modal();

        echo '</div>';
    }

    private function render_asset_controls() : void {
        echo '<form method="post" style="margin:8px 0;">';
        wp_nonce_field( 'pd_force_assets', 'pd_force_assets_nonce' );
        echo '<input type="hidden" name="pd_force_assets" value="1">';
        submit_button( 'Force refresh admin JS and CSS', 'secondary', 'pd_force_assets_submit', false );
        echo ' <small>Current asset version: <code>' . esc_html( $this->asset_version() ) . '</code></small>';
        echo '</form>';
    }

    private function render_product_docs_tab() : void {
        $products = wc_get_products([
            'status'       => 'publish',
            'stock_status' => 'instock',
            'limit'        => -1,
        ]);

        echo '<form method="post">';
        wp_nonce_field( 'save_product_docs', 'product_docs_nonce' );

        echo '<table class="widefat fixed"><thead><tr><th>Product</th><th>Documents</th></tr></thead><tbody>';

        foreach ( $products as $product ) {
            $pid  = $product->get_id();
            $docs = get_post_meta( $pid, '_product_docs', true );
            if ( ! is_array( $docs ) ) $docs = [];

            echo '<tr>';
            echo '<td style="vertical-align:top;"><strong>' . esc_html( $product->get_name() ) . '</strong></td>';
            echo '<td style="vertical-align:top;">';

            // Existing documents as chips, removable, persisted via hidden inputs
            echo '<ul class="doc-list" data-product="' . esc_attr( $pid ) . '">';
            foreach ( $docs as $doc ) {
                $name         = isset( $doc['name'] ) ? $doc['name'] : '';
                $url          = isset( $doc['url'] )  ? $doc['url']  : '';
                $exp          = isset( $doc['expires'] ) ? $doc['expires'] : '';
                $display_exp  = $this->format_expiry_display( $exp );
                echo '<li class="doc-chip">';
                echo '<span class="chip-label">' . esc_html( $name ) . '</span>';
                if ( $url ) {
                    echo ' <a class="chip-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( basename( $url ) ) . '</a>';
                }
                echo '<button type="button" class="chip-remove" title="Remove" aria-label="Remove">×</button>';
                echo '<input type="hidden" name="doc_name_' . $pid . '[]" value="' . esc_attr( $name ) . '">';
                echo '<input type="hidden" name="doc_url_'  . $pid . '[]" value="' . esc_url( $url )  . '">';
                echo '<div class="chip-expiry">';
                echo '<label>Expires (YYYY-MM-DD)';
                echo '<input type="text" class="doc-date-picker" name="doc_expiry_' . $pid . '[]" value="' . esc_attr( $display_exp ) . '" placeholder="MM/DD/YYYY">';
                echo '</label>';
                echo '</div>';
                echo '</li>';
            }
            echo '</ul>';

            echo '<div class="doc-actions">';
            echo '  <button type="button" class="button button-primary open-doc-modal" data-context="product" data-target="' . esc_attr( $pid ) . '">Upload Document</button>';
            echo '</div>';

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        submit_button( 'Save Product Documents' );
        echo '</form>';
    }

    private function render_centre_docs_tab() : void {
        $docs = get_option( self::OPT_CENTRE_DOCS, [] );
        if ( ! is_array( $docs ) ) {
            $docs = [];
        }

        echo '<p>Documents added here will appear for every user with the Centre role, even if they have not purchased a related product.</p>';

        echo '<form method="post">';
        wp_nonce_field( 'save_centre_docs', 'centre_docs_nonce' );

        echo '<ul class="doc-list" data-product="centre">';
        foreach ( $docs as $doc ) {
            $name        = isset( $doc['name'] ) ? $doc['name'] : '';
            $url         = isset( $doc['url'] )  ? $doc['url']  : '';
            $exp         = isset( $doc['expires'] ) ? $doc['expires'] : '';
            $display_exp = $this->format_expiry_display( $exp );
            echo '<li class="doc-chip">';
            echo '<span class="chip-label">' . esc_html( $name ) . '</span>';
            if ( $url ) {
                echo ' <a class="chip-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( basename( $url ) ) . '</a>';
            }
            echo '<button type="button" class="chip-remove" title="Remove" aria-label="Remove">×</button>';
            echo '<input type="hidden" name="centre_doc_name[]" value="' . esc_attr( $name ) . '">';
            echo '<input type="hidden" name="centre_doc_url[]" value="' . esc_url( $url ) . '">';
            echo '<div class="chip-expiry">';
            echo '<label>Expires (YYYY-MM-DD)';
            echo '<input type="text" class="doc-date-picker" name="centre_doc_expiry[]" value="' . esc_attr( $display_exp ) . '" placeholder="MM/DD/YYYY">';
            echo '</label>';
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';

        echo '<div class="doc-actions">';
        echo '  <button type="button" class="button button-primary open-doc-modal" data-context="centre" data-target="centre">Upload Document</button>';
        echo '</div>';

        submit_button( 'Save Centre Documents' );
        echo '</form>';
    }

    private function render_doc_modal() : void {
        echo '<div id="doc-modal" class="doc-modal" aria-hidden="true">';
        echo '  <div class="doc-modal__overlay" role="presentation"></div>';
        echo '  <div class="doc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="doc-modal-title">';
        echo '      <h2 id="doc-modal-title">Add Document</h2>';
        echo '      <p>Provide a name, choose a file, and optionally set an expiry date (YYYY-MM-DD).</p>';
        echo '      <form id="doc-modal-form">';
        echo '          <label>Document name';
        echo '              <input type="text" id="doc-modal-name" required>';
        echo '          </label>';
        echo '          <div class="doc-modal-upload">';
        echo '              <input type="hidden" id="doc-modal-url">';
        echo '              <button type="button" class="button doc-modal-upload-btn">Choose File</button>';
        echo '              <span class="doc-modal-file">No file selected</span>';
        echo '          </div>';
        echo '          <label>Expiry date (MM/DD/YYYY)';
        echo '              <input type="text" id="doc-modal-expiry" class="doc-date-picker" placeholder="MM/DD/YYYY">';
        echo '          </label>';
        echo '          <div class="doc-modal-actions">';
        echo '              <button type="submit" class="button button-primary">Add Document</button>';
        echo '              <button type="button" class="button doc-modal-cancel">Cancel</button>';
        echo '          </div>';
        echo '      </form>';
        echo '  </div>';
        echo '</div>';
    }

    /**
     * Handle cache bust button
     */
    public function handle_force_assets() {
        if ( ! is_admin() ) return;
        if ( empty( $_POST['pd_force_assets'] ) ) return;
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        if ( ! isset( $_POST['pd_force_assets_nonce'] ) || ! wp_verify_nonce( $_POST['pd_force_assets_nonce'], 'pd_force_assets' ) ) return;

        $new_ver = (string) time();
        update_option( self::OPT_ASSET_VER, $new_ver );

        add_action( 'admin_notices', function() use ( $new_ver ) {
            echo '<div class="notice notice-success is-dismissible"><p>Admin assets version bumped to ' . esc_html( $new_ver ) . '.</p></div>';
        });
    }

    /**
     * Save all products, single submit
     */
    public function save_product_docs() {
        if ( ! isset( $_POST['product_docs_nonce'] ) || ! wp_verify_nonce( $_POST['product_docs_nonce'], 'save_product_docs' ) ) {
            return;
        }

        $products = wc_get_products([
            'status'       => 'publish',
            'stock_status' => 'instock',
            'limit'        => -1,
        ]);

        foreach ( $products as $product ) {
            $pid = $product->get_id();

            // Existing chips that remain
            $names   = isset( $_POST[ 'doc_name_' . $pid ] ) ? (array) $_POST[ 'doc_name_' . $pid ] : [];
            $urls    = isset( $_POST[ 'doc_url_'  . $pid ] ) ? (array) $_POST[ 'doc_url_'  . $pid ] : [];
            $expires = isset( $_POST[ 'doc_expiry_' . $pid ] ) ? (array) $_POST[ 'doc_expiry_' . $pid ] : [];

            $docs = [];
            foreach ( $names as $i => $nm ) {
                $u = $urls[ $i ] ?? '';
                if ( $u === '' ) continue;
                $exp_val = $expires[ $i ] ?? '';
                $docs[] = [
                    'name' => sanitize_text_field( $nm ),
                    'url'  => esc_url_raw( $u ),
                    'expires' => $this->sanitize_expiry_field( $exp_val ),
                ];
            }

            // Newly added rows
            $new_names   = isset( $_POST[ 'doc_new_name_' . $pid ] ) ? (array) $_POST[ 'doc_new_name_' . $pid ] : [];
            $new_urls    = isset( $_POST[ 'doc_new_url_'  . $pid ] ) ? (array) $_POST[ 'doc_new_url_'  . $pid ] : [];
            $new_expires = isset( $_POST[ 'doc_new_expiry_' . $pid ] ) ? (array) $_POST[ 'doc_new_expiry_' . $pid ] : [];

            foreach ( $new_names as $i => $nm ) {
                $u = $new_urls[ $i ] ?? '';
                if ( $nm === '' || $u === '' ) continue;
                $exp_val = $new_expires[ $i ] ?? '';
                $docs[] = [
                    'name' => sanitize_text_field( $nm ),
                    'url'  => esc_url_raw( $u ),
                    'expires' => $this->sanitize_expiry_field( $exp_val ),
                ];
            }

            update_post_meta( $pid, '_product_docs', $docs );
        }

        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>Product documents saved.</p></div>';
        });
    }

    /**
     * Save Centre role documents
     */
    public function save_centre_docs() {
        if ( ! isset( $_POST['centre_docs_nonce'] ) || ! wp_verify_nonce( $_POST['centre_docs_nonce'], 'save_centre_docs' ) ) {
            return;
        }

        $docs       = [];
        $names      = isset( $_POST['centre_doc_name'] ) ? (array) $_POST['centre_doc_name'] : [];
        $urls       = isset( $_POST['centre_doc_url'] ) ? (array) $_POST['centre_doc_url'] : [];
        $expiries   = isset( $_POST['centre_doc_expiry'] ) ? (array) $_POST['centre_doc_expiry'] : [];
        $new_names  = isset( $_POST['centre_doc_new_name'] ) ? (array) $_POST['centre_doc_new_name'] : [];
        $new_urls   = isset( $_POST['centre_doc_new_url'] ) ? (array) $_POST['centre_doc_new_url'] : [];
        $new_expiry = isset( $_POST['centre_doc_new_expiry'] ) ? (array) $_POST['centre_doc_new_expiry'] : [];

        foreach ( $names as $i => $nm ) {
            $u = $urls[ $i ] ?? '';
            if ( $u === '' ) continue;
            $docs[] = [
                'name'    => sanitize_text_field( $nm ),
                'url'     => esc_url_raw( $u ),
                'expires' => $this->sanitize_expiry_field( $expiries[ $i ] ?? '' ),
            ];
        }

        foreach ( $new_names as $i => $nm ) {
            $u = $new_urls[ $i ] ?? '';
            if ( $nm === '' || $u === '' ) continue;
            $docs[] = [
                'name'    => sanitize_text_field( $nm ),
                'url'     => esc_url_raw( $u ),
                'expires' => $this->sanitize_expiry_field( $new_expiry[ $i ] ?? '' ),
            ];
        }

        update_option( self::OPT_CENTRE_DOCS, $docs );

        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>Centre documents saved.</p></div>';
        });
    }

    /**
     * Build items for My Account, Downloads tab
     */
    public function add_docs_to_downloads( $downloads ) {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) return $downloads;

        $orders = wc_get_orders([
            'customer_id' => $user->ID,
            'status'      => [ 'completed', 'processing' ],
            'limit'       => -1,
        ]);

        $product_ids = [];
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $product_ids[] = $item->get_product_id();
            }
        }

        foreach ( array_unique( $product_ids ) as $pid ) {
            $docs = get_post_meta( $pid, '_product_docs', true );
            if ( empty( $docs ) || ! is_array( $docs ) ) continue;

            $product_title = get_the_title( $pid );

            foreach ( $docs as $doc ) {
                if ( empty( $doc['url'] ) ) continue;
                if ( $this->document_is_expired( $doc ) ) continue;

                $downloads[] = [
                    'download_id'         => md5( $pid . '|' . $doc['url'] ),
                    'product_id'          => $pid,
                    'product_name'        => $product_title,                               // ensures Product column shows
                    'download_name'       => ! empty( $doc['name'] ) ? $doc['name'] : $product_title . ' Document',
                    'download_url'        => $doc['url'],
                    'downloads_remaining' => '&#8734;',
                    'access_expires'      => ! empty( $doc['expires'] ) ? strtotime( $doc['expires'] . ' 23:59:59' ) : null,
                ];
            }
        }

        if ( in_array( 'centre', (array) $user->roles, true ) ) {
            $centre_docs = get_option( self::OPT_CENTRE_DOCS, [] );
            if ( is_array( $centre_docs ) ) {
                foreach ( $centre_docs as $doc ) {
                    if ( empty( $doc['url'] ) ) continue;
                    if ( $this->document_is_expired( $doc ) ) continue;

                    $downloads[] = [
                        'download_id'         => md5( 'centre|' . $doc['url'] ),
                        'product_id'          => 0,
                        'product_name'        => __( 'Centre Documents', 'wc-product-docs' ),
                        'download_name'       => ! empty( $doc['name'] ) ? $doc['name'] : __( 'Centre Document', 'wc-product-docs' ),
                        'download_url'        => $doc['url'],
                        'downloads_remaining' => '&#8734;',
                        'access_expires'      => ! empty( $doc['expires'] ) ? strtotime( $doc['expires'] . ' 23:59:59' ) : null,
                    ];
                }
            }
        }

        return $downloads;
    }
}

/**
 * FunnelKit merge tag: {{product_documents}}
 */
add_filter( 'wffn_email_merge_tags', function( $tags ) {
    $tags['product_documents'] = [
        'name'        => __( 'International Documents', 'wc-product-docs' ),
        'description' => __( 'Attached documents for products in the order.', 'wc-product-docs' ),
        'callback'    => function( $order ) {
            if ( ! $order instanceof WC_Order ) return '';

            $html = '<div class="product-documents"><p><strong>International Documents:</strong></p>';

            foreach ( $order->get_items() as $item ) {
                $pid  = $item->get_product_id();
                $docs = get_post_meta( $pid, '_product_docs', true );
                if ( empty( $docs ) || ! is_array( $docs ) ) continue;

                $html .= '<p><em>' . esc_html( get_the_title( $pid ) ) . '</em></p><ul style="margin:6px 0 12px; padding-left:16px;">';
                foreach ( $docs as $doc ) {
                    if ( empty( $doc['url'] ) ) continue;
                    $label = ! empty( $doc['name'] ) ? $doc['name'] : basename( $doc['url'] );
                    $html .= '<li><a href="' . esc_url( $doc['url'] ) . '">' . esc_html( $label ) . '</a></li>';
                }
                $html .= '</ul>';
            }

            if ( trim( strip_tags( $html ) ) === 'International Documents:' ) return '';
            return $html . '</div>';
        },
    ];
    return $tags;
});

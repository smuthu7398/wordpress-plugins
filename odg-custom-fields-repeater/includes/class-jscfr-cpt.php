<?php
/**
 * JSCFR Custom Post Types & Taxonomies
 *
 * Register custom post types and taxonomies via admin UI — no code required.
 * Stores configuration in wp_options, registers on 'init'.
 *
 * @package JSCFR
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_CPT' ) ) {

    final class JSCFR_CPT {

        private static $instance = null;

        const OPT_CPTS  = 'jscfr_custom_post_types';
        const OPT_TAXES = 'jscfr_custom_taxonomies';

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            // Register CPTs and taxonomies early
            add_action( 'init', array( $this, 'register_all' ), 5 );

            // Admin menu
            add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );

            // AJAX handlers
            add_action( 'wp_ajax_jscfr_save_cpt', array( $this, 'ajax_save_cpt' ) );
            add_action( 'wp_ajax_jscfr_delete_cpt', array( $this, 'ajax_delete_cpt' ) );
            add_action( 'wp_ajax_jscfr_save_taxonomy', array( $this, 'ajax_save_taxonomy' ) );
            add_action( 'wp_ajax_jscfr_delete_taxonomy', array( $this, 'ajax_delete_taxonomy' ) );
            add_action( 'wp_ajax_jscfr_get_cpt_config', array( $this, 'ajax_get_cpt_config' ) );
            add_action( 'wp_ajax_jscfr_get_tax_config', array( $this, 'ajax_get_tax_config' ) );

            // Admin assets
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        }

        /* ---------------------------------------------------------- */
        /*  Register CPTs & Taxonomies                                 */
        /* ---------------------------------------------------------- */

        public function register_all() {
            $cpts  = get_option( self::OPT_CPTS, array() );
            $taxes = get_option( self::OPT_TAXES, array() );

            if ( is_array( $cpts ) ) {
                foreach ( $cpts as $cpt ) {
                    if ( empty( $cpt['slug'] ) || ! empty( $cpt['inactive'] ) ) {
                        continue;
                    }
                    $this->register_cpt( $cpt );
                }
            }

            if ( is_array( $taxes ) ) {
                foreach ( $taxes as $tax ) {
                    if ( empty( $tax['slug'] ) || ! empty( $tax['inactive'] ) ) {
                        continue;
                    }
                    $this->register_taxonomy( $tax );
                }
            }
        }

        private function register_cpt( $cpt ) {
            $slug     = sanitize_key( $cpt['slug'] );
            $singular = ! empty( $cpt['singular'] ) ? $cpt['singular'] : ucfirst( $slug );
            $plural   = ! empty( $cpt['plural'] ) ? $cpt['plural'] : $singular . 's';

            $labels = array(
                'name'                  => $plural,
                'singular_name'         => $singular,
                'add_new'               => sprintf( __( 'Add New %s', 'jscfr' ), $singular ),
                'add_new_item'          => sprintf( __( 'Add New %s', 'jscfr' ), $singular ),
                'edit_item'             => sprintf( __( 'Edit %s', 'jscfr' ), $singular ),
                'new_item'              => sprintf( __( 'New %s', 'jscfr' ), $singular ),
                'view_item'             => sprintf( __( 'View %s', 'jscfr' ), $singular ),
                'view_items'            => sprintf( __( 'View %s', 'jscfr' ), $plural ),
                'search_items'          => sprintf( __( 'Search %s', 'jscfr' ), $plural ),
                'not_found'             => sprintf( __( 'No %s found.', 'jscfr' ), strtolower( $plural ) ),
                'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'jscfr' ), strtolower( $plural ) ),
                'all_items'             => sprintf( __( 'All %s', 'jscfr' ), $plural ),
                'archives'              => sprintf( __( '%s Archives', 'jscfr' ), $singular ),
                'attributes'            => sprintf( __( '%s Attributes', 'jscfr' ), $singular ),
                'insert_into_item'      => sprintf( __( 'Insert into %s', 'jscfr' ), strtolower( $singular ) ),
                'uploaded_to_this_item' => sprintf( __( 'Uploaded to this %s', 'jscfr' ), strtolower( $singular ) ),
                'menu_name'             => $plural,
            );

            // Supports
            $supports = array();
            $support_options = array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'trackbacks', 'revisions', 'custom-fields', 'page-attributes', 'author' );
            foreach ( $support_options as $s ) {
                if ( ! empty( $cpt['supports'][ $s ] ) ) {
                    $supports[] = $s;
                }
            }
            if ( empty( $supports ) ) {
                $supports = array( 'title', 'editor' );
            }

            $args = array(
                'labels'              => $labels,
                'public'              => isset( $cpt['public'] ) ? (bool) $cpt['public'] : true,
                'publicly_queryable'  => isset( $cpt['publicly_queryable'] ) ? (bool) $cpt['publicly_queryable'] : true,
                'show_ui'             => isset( $cpt['show_ui'] ) ? (bool) $cpt['show_ui'] : true,
                'show_in_menu'        => isset( $cpt['show_in_menu'] ) ? (bool) $cpt['show_in_menu'] : true,
                'show_in_nav_menus'   => isset( $cpt['show_in_nav_menus'] ) ? (bool) $cpt['show_in_nav_menus'] : true,
                'show_in_admin_bar'   => isset( $cpt['show_in_admin_bar'] ) ? (bool) $cpt['show_in_admin_bar'] : true,
                'show_in_rest'        => isset( $cpt['show_in_rest'] ) ? (bool) $cpt['show_in_rest'] : true,
                'has_archive'         => isset( $cpt['has_archive'] ) ? (bool) $cpt['has_archive'] : true,
                'hierarchical'        => ! empty( $cpt['hierarchical'] ),
                'exclude_from_search' => ! empty( $cpt['exclude_from_search'] ),
                'can_export'          => true,
                'supports'            => $supports,
                'rewrite'             => array(
                    'slug'       => ! empty( $cpt['rewrite_slug'] ) ? sanitize_title( $cpt['rewrite_slug'] ) : $slug,
                    'with_front' => isset( $cpt['rewrite_with_front'] ) ? (bool) $cpt['rewrite_with_front'] : true,
                ),
                'menu_position'       => ! empty( $cpt['menu_position'] ) ? absint( $cpt['menu_position'] ) : null,
                'menu_icon'           => ! empty( $cpt['menu_icon'] ) ? sanitize_text_field( $cpt['menu_icon'] ) : 'dashicons-admin-post',
            );

            // Taxonomies to attach
            if ( ! empty( $cpt['taxonomies'] ) && is_array( $cpt['taxonomies'] ) ) {
                $args['taxonomies'] = array_map( 'sanitize_key', $cpt['taxonomies'] );
            }

            register_post_type( $slug, $args );
        }

        private function register_taxonomy( $tax ) {
            $slug     = sanitize_key( $tax['slug'] );
            $singular = ! empty( $tax['singular'] ) ? $tax['singular'] : ucfirst( $slug );
            $plural   = ! empty( $tax['plural'] ) ? $tax['plural'] : $singular . 's';

            $labels = array(
                'name'                       => $plural,
                'singular_name'              => $singular,
                'search_items'               => sprintf( __( 'Search %s', 'jscfr' ), $plural ),
                'popular_items'              => sprintf( __( 'Popular %s', 'jscfr' ), $plural ),
                'all_items'                  => sprintf( __( 'All %s', 'jscfr' ), $plural ),
                'parent_item'                => sprintf( __( 'Parent %s', 'jscfr' ), $singular ),
                'parent_item_colon'          => sprintf( __( 'Parent %s:', 'jscfr' ), $singular ),
                'edit_item'                  => sprintf( __( 'Edit %s', 'jscfr' ), $singular ),
                'view_item'                  => sprintf( __( 'View %s', 'jscfr' ), $singular ),
                'update_item'                => sprintf( __( 'Update %s', 'jscfr' ), $singular ),
                'add_new_item'               => sprintf( __( 'Add New %s', 'jscfr' ), $singular ),
                'new_item_name'              => sprintf( __( 'New %s Name', 'jscfr' ), $singular ),
                'separate_items_with_commas' => sprintf( __( 'Separate %s with commas', 'jscfr' ), strtolower( $plural ) ),
                'add_or_remove_items'        => sprintf( __( 'Add or remove %s', 'jscfr' ), strtolower( $plural ) ),
                'choose_from_most_used'      => sprintf( __( 'Choose from the most used %s', 'jscfr' ), strtolower( $plural ) ),
                'not_found'                  => sprintf( __( 'No %s found.', 'jscfr' ), strtolower( $plural ) ),
                'menu_name'                  => $plural,
                'back_to_items'              => sprintf( __( '&larr; Back to %s', 'jscfr' ), $plural ),
            );

            $post_types = array();
            if ( ! empty( $tax['post_types'] ) && is_array( $tax['post_types'] ) ) {
                $post_types = array_map( 'sanitize_key', $tax['post_types'] );
            }

            $args = array(
                'labels'             => $labels,
                'public'             => isset( $tax['public'] ) ? (bool) $tax['public'] : true,
                'publicly_queryable' => isset( $tax['publicly_queryable'] ) ? (bool) $tax['publicly_queryable'] : true,
                'show_ui'            => isset( $tax['show_ui'] ) ? (bool) $tax['show_ui'] : true,
                'show_in_menu'       => isset( $tax['show_in_menu'] ) ? (bool) $tax['show_in_menu'] : true,
                'show_in_nav_menus'  => isset( $tax['show_in_nav_menus'] ) ? (bool) $tax['show_in_nav_menus'] : true,
                'show_in_rest'       => isset( $tax['show_in_rest'] ) ? (bool) $tax['show_in_rest'] : true,
                'show_admin_column'  => isset( $tax['show_admin_column'] ) ? (bool) $tax['show_admin_column'] : true,
                'hierarchical'       => isset( $tax['hierarchical'] ) ? (bool) $tax['hierarchical'] : true,
                'show_tagcloud'      => ! empty( $tax['show_tagcloud'] ),
                'rewrite'            => array(
                    'slug'         => ! empty( $tax['rewrite_slug'] ) ? sanitize_title( $tax['rewrite_slug'] ) : $slug,
                    'with_front'   => isset( $tax['rewrite_with_front'] ) ? (bool) $tax['rewrite_with_front'] : true,
                    'hierarchical' => ! empty( $tax['hierarchical'] ),
                ),
                'query_var'          => true,
            );

            register_taxonomy( $slug, $post_types, $args );
        }

        /* ---------------------------------------------------------- */
        /*  Admin menu                                                 */
        /* ---------------------------------------------------------- */

        public function add_menu() {
            add_submenu_page(
                'jscfr-builder',
                __( 'Post Types', 'jscfr' ),
                __( 'Post Types', 'jscfr' ),
                'manage_options',
                'jscfr-post-types',
                array( $this, 'render_page' )
            );
            add_submenu_page(
                'jscfr-builder',
                __( 'Taxonomies', 'jscfr' ),
                __( 'Taxonomies', 'jscfr' ),
                'manage_options',
                'jscfr-taxonomies',
                array( $this, 'render_taxonomies_page' )
            );
        }

        /* ---------------------------------------------------------- */
        /*  Enqueue                                                    */
        /* ---------------------------------------------------------- */

        public function enqueue_assets( $hook ) {
            if ( false === strpos( $hook, 'jscfr-post-types' ) && false === strpos( $hook, 'jscfr-taxonomies' ) ) {
                return;
            }
            wp_enqueue_style( 'jscfr-cpt-css', JSCFR_PLUGIN_URL . 'assets/css/jscfr-cpt.css', array(), JSCFR_VERSION );
            wp_enqueue_script( 'jscfr-cpt-js', JSCFR_PLUGIN_URL . 'assets/js/jscfr-cpt.js', array( 'jquery' ), JSCFR_VERSION, true );
            wp_localize_script( 'jscfr-cpt-js', 'jscfr_cpt', array(
                'ajax_url'       => admin_url( 'admin-ajax.php' ),
                'nonce'          => wp_create_nonce( 'jscfr_cpt_nonce' ),
                'confirm_delete' => __( 'Delete this item? This cannot be undone.', 'jscfr' ),
                'post_types'     => $this->get_all_post_types_list(),
            ) );
        }

        private function get_all_post_types_list() {
            $pts  = get_post_types( array( 'public' => true ), 'objects' );
            $list = array();
            foreach ( $pts as $pt ) {
                $list[ $pt->name ] = $pt->labels->singular_name;
            }
            return $list;
        }

        /* ---------------------------------------------------------- */
        /*  Render: Post Types page                                    */
        /* ---------------------------------------------------------- */

        public function render_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'jscfr' ) );
            }
            $cpts = get_option( self::OPT_CPTS, array() );
            if ( ! is_array( $cpts ) ) $cpts = array();
            ?>
            <div class="wrap jscfr-cpt-wrap">
                <h1><?php esc_html_e( 'Custom Post Types', 'jscfr' ); ?> <button type="button" class="page-title-action" id="jscfr-add-cpt"><?php esc_html_e( 'Add New', 'jscfr' ); ?></button></h1>

                <table class="wp-list-table widefat fixed striped" id="jscfr-cpt-list">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Slug', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Singular', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Plural', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Public', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'REST', 'jscfr' ); ?></th>
                            <th style="width:140px;"><?php esc_html_e( 'Actions', 'jscfr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $cpts ) ) : ?>
                            <tr class="jscfr-no-items"><td colspan="6"><?php esc_html_e( 'No custom post types registered yet.', 'jscfr' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $cpts as $cpt ) : ?>
                                <tr data-slug="<?php echo esc_attr( $cpt['slug'] ); ?>">
                                    <td><strong><?php echo esc_html( $cpt['slug'] ); ?></strong></td>
                                    <td><?php echo esc_html( $cpt['singular'] ); ?></td>
                                    <td><?php echo esc_html( $cpt['plural'] ); ?></td>
                                    <td><?php echo ! empty( $cpt['public'] ) ? '&#10003;' : '&mdash;'; ?></td>
                                    <td><?php echo ! empty( $cpt['show_in_rest'] ) ? '&#10003;' : '&mdash;'; ?></td>
                                    <td>
                                        <button type="button" class="button button-small jscfr-edit-cpt"><?php esc_html_e( 'Edit', 'jscfr' ); ?></button>
                                        <button type="button" class="button button-small button-link-delete jscfr-delete-cpt"><?php esc_html_e( 'Delete', 'jscfr' ); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php $this->render_cpt_form(); ?>
            </div>
            <?php
        }

        private function render_cpt_form() {
            $dashicons = array( 'dashicons-admin-post', 'dashicons-admin-page', 'dashicons-admin-media', 'dashicons-admin-comments', 'dashicons-admin-users', 'dashicons-admin-tools', 'dashicons-admin-settings', 'dashicons-admin-site', 'dashicons-admin-generic', 'dashicons-admin-home', 'dashicons-admin-network', 'dashicons-admin-appearance', 'dashicons-admin-plugins', 'dashicons-format-standard', 'dashicons-format-aside', 'dashicons-format-image', 'dashicons-format-gallery', 'dashicons-format-video', 'dashicons-format-audio', 'dashicons-format-chat', 'dashicons-format-status', 'dashicons-format-quote', 'dashicons-cart', 'dashicons-products', 'dashicons-store', 'dashicons-portfolio', 'dashicons-awards', 'dashicons-businessman', 'dashicons-groups', 'dashicons-tickets-alt', 'dashicons-calendar-alt', 'dashicons-location', 'dashicons-building', 'dashicons-phone', 'dashicons-email-alt', 'dashicons-star-filled', 'dashicons-heart', 'dashicons-book', 'dashicons-tag', 'dashicons-category', 'dashicons-archive', 'dashicons-clipboard', 'dashicons-chart-bar', 'dashicons-list-view', 'dashicons-grid-view', 'dashicons-megaphone', 'dashicons-shield', 'dashicons-car', 'dashicons-food', 'dashicons-hammer', 'dashicons-art', 'dashicons-palmtree', 'dashicons-pets', 'dashicons-games', 'dashicons-money-alt' );
            ?>
            <div id="jscfr-cpt-form-modal" class="jscfr-modal" style="display:none;">
                <div class="jscfr-modal-content">
                    <h2 id="jscfr-cpt-form-title"><?php esc_html_e( 'Add Custom Post Type', 'jscfr' ); ?></h2>
                    <form id="jscfr-cpt-form">
                        <input type="hidden" id="jscfr-cpt-editing" value="" />
                        <table class="form-table">
                            <tr>
                                <th><label for="jscfr-cpt-slug"><?php esc_html_e( 'Slug (key)', 'jscfr' ); ?></label></th>
                                <td><input type="text" id="jscfr-cpt-slug" class="regular-text" maxlength="20" pattern="[a-z0-9_-]+" required /> <p class="description"><?php esc_html_e( 'Lowercase, no spaces. Max 20 chars.', 'jscfr' ); ?></p></td>
                            </tr>
                            <tr>
                                <th><label for="jscfr-cpt-singular"><?php esc_html_e( 'Singular Name', 'jscfr' ); ?></label></th>
                                <td><input type="text" id="jscfr-cpt-singular" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th><label for="jscfr-cpt-plural"><?php esc_html_e( 'Plural Name', 'jscfr' ); ?></label></th>
                                <td><input type="text" id="jscfr-cpt-plural" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Supports', 'jscfr' ); ?></th>
                                <td>
                                    <?php
                                    $support_opts = array(
                                        'title'           => __( 'Title', 'jscfr' ),
                                        'editor'          => __( 'Editor', 'jscfr' ),
                                        'thumbnail'       => __( 'Featured Image', 'jscfr' ),
                                        'excerpt'         => __( 'Excerpt', 'jscfr' ),
                                        'comments'        => __( 'Comments', 'jscfr' ),
                                        'revisions'       => __( 'Revisions', 'jscfr' ),
                                        'author'          => __( 'Author', 'jscfr' ),
                                        'page-attributes' => __( 'Page Attributes', 'jscfr' ),
                                        'trackbacks'      => __( 'Trackbacks', 'jscfr' ),
                                        'custom-fields'   => __( 'Custom Fields', 'jscfr' ),
                                    );
                                    foreach ( $support_opts as $skey => $slabel ) :
                                    ?>
                                        <label style="display:inline-block;margin-right:14px;"><input type="checkbox" class="jscfr-cpt-support" value="<?php echo esc_attr( $skey ); ?>" /> <?php echo esc_html( $slabel ); ?></label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="jscfr-cpt-icon"><?php esc_html_e( 'Menu Icon', 'jscfr' ); ?></label></th>
                                <td>
                                    <select id="jscfr-cpt-icon">
                                        <?php foreach ( $dashicons as $di ) : ?>
                                            <option value="<?php echo esc_attr( $di ); ?>"><?php echo esc_html( str_replace( 'dashicons-', '', $di ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span id="jscfr-cpt-icon-preview" class="dashicons dashicons-admin-post" style="font-size:20px;vertical-align:middle;margin-left:8px;"></span>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="jscfr-cpt-position"><?php esc_html_e( 'Menu Position', 'jscfr' ); ?></label></th>
                                <td><input type="number" id="jscfr-cpt-position" value="25" min="0" max="100" style="width:80px;" /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Visibility', 'jscfr' ); ?></th>
                                <td>
                                    <label><input type="checkbox" id="jscfr-cpt-public" checked /> <?php esc_html_e( 'Public', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-cpt-publicly-queryable" checked /> <?php esc_html_e( 'Publicly Queryable', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-cpt-show-ui" checked /> <?php esc_html_e( 'Show UI', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-cpt-show-in-menu" checked /> <?php esc_html_e( 'Show in Menu', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-cpt-show-in-nav" checked /> <?php esc_html_e( 'Show in Nav Menus', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-cpt-show-in-admin-bar" checked /> <?php esc_html_e( 'Show in Admin Bar', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-cpt-show-in-rest" checked /> <?php esc_html_e( 'Show in REST API (Gutenberg)', 'jscfr' ); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Options', 'jscfr' ); ?></th>
                                <td>
                                    <label><input type="checkbox" id="jscfr-cpt-has-archive" checked /> <?php esc_html_e( 'Has Archive', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-cpt-hierarchical" /> <?php esc_html_e( 'Hierarchical (like Pages)', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-cpt-exclude-search" /> <?php esc_html_e( 'Exclude from Search', 'jscfr' ); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="jscfr-cpt-rewrite-slug"><?php esc_html_e( 'Rewrite Slug', 'jscfr' ); ?></label></th>
                                <td><input type="text" id="jscfr-cpt-rewrite-slug" class="regular-text" placeholder="<?php esc_attr_e( 'Leave empty to use slug', 'jscfr' ); ?>" /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Taxonomies', 'jscfr' ); ?></th>
                                <td id="jscfr-cpt-taxonomies-wrap">
                                    <label><input type="checkbox" class="jscfr-cpt-tax" value="category" /> <?php esc_html_e( 'Category', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" class="jscfr-cpt-tax" value="post_tag" /> <?php esc_html_e( 'Tag', 'jscfr' ); ?></label>
                                    <?php
                                    $custom_taxes = get_option( self::OPT_TAXES, array() );
                                    if ( is_array( $custom_taxes ) ) {
                                        foreach ( $custom_taxes as $ct ) {
                                            if ( ! empty( $ct['slug'] ) ) {
                                                echo '<br /><label><input type="checkbox" class="jscfr-cpt-tax" value="' . esc_attr( $ct['slug'] ) . '" /> ' . esc_html( ! empty( $ct['plural'] ) ? $ct['plural'] : $ct['slug'] ) . '</label>';
                                            }
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Post Type', 'jscfr' ); ?></button>
                            <button type="button" class="button jscfr-modal-cancel"><?php esc_html_e( 'Cancel', 'jscfr' ); ?></button>
                        </p>
                    </form>
                </div>
            </div>
            <?php
        }

        /* ---------------------------------------------------------- */
        /*  Render: Taxonomies page                                    */
        /* ---------------------------------------------------------- */

        public function render_taxonomies_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'jscfr' ) );
            }
            $taxes = get_option( self::OPT_TAXES, array() );
            if ( ! is_array( $taxes ) ) $taxes = array();
            ?>
            <div class="wrap jscfr-cpt-wrap">
                <h1><?php esc_html_e( 'Custom Taxonomies', 'jscfr' ); ?> <button type="button" class="page-title-action" id="jscfr-add-tax"><?php esc_html_e( 'Add New', 'jscfr' ); ?></button></h1>

                <table class="wp-list-table widefat fixed striped" id="jscfr-tax-list">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Slug', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Singular', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Plural', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Post Types', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Hierarchical', 'jscfr' ); ?></th>
                            <th style="width:140px;"><?php esc_html_e( 'Actions', 'jscfr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $taxes ) ) : ?>
                            <tr class="jscfr-no-items"><td colspan="6"><?php esc_html_e( 'No custom taxonomies registered yet.', 'jscfr' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $taxes as $tax ) : ?>
                                <tr data-slug="<?php echo esc_attr( $tax['slug'] ); ?>">
                                    <td><strong><?php echo esc_html( $tax['slug'] ); ?></strong></td>
                                    <td><?php echo esc_html( $tax['singular'] ); ?></td>
                                    <td><?php echo esc_html( $tax['plural'] ); ?></td>
                                    <td><?php echo esc_html( ! empty( $tax['post_types'] ) ? implode( ', ', $tax['post_types'] ) : '—' ); ?></td>
                                    <td><?php echo ! empty( $tax['hierarchical'] ) ? '&#10003;' : '&mdash;'; ?></td>
                                    <td>
                                        <button type="button" class="button button-small jscfr-edit-tax"><?php esc_html_e( 'Edit', 'jscfr' ); ?></button>
                                        <button type="button" class="button button-small button-link-delete jscfr-delete-tax"><?php esc_html_e( 'Delete', 'jscfr' ); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php $this->render_tax_form(); ?>
            </div>
            <?php
        }

        private function render_tax_form() {
            $all_pts = get_post_types( array( 'show_ui' => true ), 'objects' );
            ?>
            <div id="jscfr-tax-form-modal" class="jscfr-modal" style="display:none;">
                <div class="jscfr-modal-content">
                    <h2 id="jscfr-tax-form-title"><?php esc_html_e( 'Add Custom Taxonomy', 'jscfr' ); ?></h2>
                    <form id="jscfr-tax-form">
                        <input type="hidden" id="jscfr-tax-editing" value="" />
                        <table class="form-table">
                            <tr>
                                <th><label for="jscfr-tax-slug"><?php esc_html_e( 'Slug (key)', 'jscfr' ); ?></label></th>
                                <td><input type="text" id="jscfr-tax-slug" class="regular-text" maxlength="32" pattern="[a-z0-9_-]+" required /> <p class="description"><?php esc_html_e( 'Lowercase, no spaces. Max 32 chars.', 'jscfr' ); ?></p></td>
                            </tr>
                            <tr>
                                <th><label for="jscfr-tax-singular"><?php esc_html_e( 'Singular Name', 'jscfr' ); ?></label></th>
                                <td><input type="text" id="jscfr-tax-singular" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th><label for="jscfr-tax-plural"><?php esc_html_e( 'Plural Name', 'jscfr' ); ?></label></th>
                                <td><input type="text" id="jscfr-tax-plural" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Post Types', 'jscfr' ); ?></th>
                                <td>
                                    <?php foreach ( $all_pts as $pt ) : ?>
                                        <label style="display:inline-block;margin-right:14px;"><input type="checkbox" class="jscfr-tax-pt" value="<?php echo esc_attr( $pt->name ); ?>" /> <?php echo esc_html( $pt->labels->singular_name ); ?></label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Visibility', 'jscfr' ); ?></th>
                                <td>
                                    <label><input type="checkbox" id="jscfr-tax-public" checked /> <?php esc_html_e( 'Public', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-tax-publicly-queryable" checked /> <?php esc_html_e( 'Publicly Queryable', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-tax-show-ui" checked /> <?php esc_html_e( 'Show UI', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-tax-show-in-menu" checked /> <?php esc_html_e( 'Show in Menu', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-tax-show-in-nav" checked /> <?php esc_html_e( 'Show in Nav Menus', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-tax-show-in-rest" checked /> <?php esc_html_e( 'Show in REST API', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-tax-show-admin-column" checked /> <?php esc_html_e( 'Show Admin Column', 'jscfr' ); ?></label><br />
                                    <label><input type="checkbox" id="jscfr-tax-show-tagcloud" /> <?php esc_html_e( 'Show Tag Cloud', 'jscfr' ); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Options', 'jscfr' ); ?></th>
                                <td>
                                    <label><input type="checkbox" id="jscfr-tax-hierarchical" checked /> <?php esc_html_e( 'Hierarchical (like Categories)', 'jscfr' ); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="jscfr-tax-rewrite-slug"><?php esc_html_e( 'Rewrite Slug', 'jscfr' ); ?></label></th>
                                <td><input type="text" id="jscfr-tax-rewrite-slug" class="regular-text" placeholder="<?php esc_attr_e( 'Leave empty to use slug', 'jscfr' ); ?>" /></td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Taxonomy', 'jscfr' ); ?></button>
                            <button type="button" class="button jscfr-modal-cancel"><?php esc_html_e( 'Cancel', 'jscfr' ); ?></button>
                        </p>
                    </form>
                </div>
            </div>
            <?php
        }

        /* ---------------------------------------------------------- */
        /*  AJAX: Save / Delete CPT                                    */
        /* ---------------------------------------------------------- */

        public function ajax_save_cpt() {
            check_ajax_referer( 'jscfr_cpt_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $data = isset( $_POST['cpt'] ) ? $_POST['cpt'] : array();
            $slug = sanitize_key( isset( $data['slug'] ) ? $data['slug'] : '' );
            if ( empty( $slug ) || strlen( $slug ) > 20 ) {
                wp_send_json_error( __( 'Invalid slug.', 'jscfr' ) );
            }

            // Prevent overriding built-in post types
            $reserved = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );
            $editing   = sanitize_key( isset( $data['editing'] ) ? $data['editing'] : '' );
            if ( in_array( $slug, $reserved, true ) && $slug !== $editing ) {
                wp_send_json_error( __( 'This slug is reserved by WordPress.', 'jscfr' ) );
            }

            $cpt = array(
                'slug'                => $slug,
                'singular'            => sanitize_text_field( isset( $data['singular'] ) ? $data['singular'] : '' ),
                'plural'              => sanitize_text_field( isset( $data['plural'] ) ? $data['plural'] : '' ),
                'public'              => ! empty( $data['public'] ),
                'publicly_queryable'  => ! empty( $data['publicly_queryable'] ),
                'show_ui'             => ! empty( $data['show_ui'] ),
                'show_in_menu'        => ! empty( $data['show_in_menu'] ),
                'show_in_nav_menus'   => ! empty( $data['show_in_nav_menus'] ),
                'show_in_admin_bar'   => ! empty( $data['show_in_admin_bar'] ),
                'show_in_rest'        => ! empty( $data['show_in_rest'] ),
                'has_archive'         => ! empty( $data['has_archive'] ),
                'hierarchical'        => ! empty( $data['hierarchical'] ),
                'exclude_from_search' => ! empty( $data['exclude_from_search'] ),
                'menu_icon'           => sanitize_text_field( isset( $data['menu_icon'] ) ? $data['menu_icon'] : 'dashicons-admin-post' ),
                'menu_position'       => absint( isset( $data['menu_position'] ) ? $data['menu_position'] : 25 ),
                'rewrite_slug'        => sanitize_title( isset( $data['rewrite_slug'] ) ? $data['rewrite_slug'] : '' ),
                'rewrite_with_front'  => true,
                'supports'            => array(),
                'taxonomies'          => array(),
            );

            if ( ! empty( $data['supports'] ) && is_array( $data['supports'] ) ) {
                $cpt['supports'] = array();
                foreach ( $data['supports'] as $s ) {
                    $cpt['supports'][ sanitize_key( $s ) ] = true;
                }
            }

            if ( ! empty( $data['taxonomies'] ) && is_array( $data['taxonomies'] ) ) {
                $cpt['taxonomies'] = array_map( 'sanitize_key', $data['taxonomies'] );
            }

            // Save
            $all = get_option( self::OPT_CPTS, array() );
            if ( ! is_array( $all ) ) $all = array();

            // Update or add
            $found = false;
            $old_slug = $editing;
            foreach ( $all as $i => $existing ) {
                if ( $existing['slug'] === $old_slug ) {
                    $all[ $i ] = $cpt;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $all[] = $cpt;
            }

            update_option( self::OPT_CPTS, $all );
            flush_rewrite_rules();

            wp_send_json_success( array( 'cpt' => $cpt ) );
        }

        public function ajax_delete_cpt() {
            check_ajax_referer( 'jscfr_cpt_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $slug = sanitize_key( isset( $_POST['slug'] ) ? $_POST['slug'] : '' );
            $all  = get_option( self::OPT_CPTS, array() );
            if ( ! is_array( $all ) ) $all = array();

            $all = array_values( array_filter( $all, function( $c ) use ( $slug ) {
                return $c['slug'] !== $slug;
            } ) );

            update_option( self::OPT_CPTS, $all );
            flush_rewrite_rules();

            wp_send_json_success();
        }

        /* ---------------------------------------------------------- */
        /*  AJAX: Save / Delete Taxonomy                               */
        /* ---------------------------------------------------------- */

        public function ajax_save_taxonomy() {
            check_ajax_referer( 'jscfr_cpt_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $data = isset( $_POST['tax'] ) ? $_POST['tax'] : array();
            $slug = sanitize_key( isset( $data['slug'] ) ? $data['slug'] : '' );
            if ( empty( $slug ) || strlen( $slug ) > 32 ) {
                wp_send_json_error( __( 'Invalid slug.', 'jscfr' ) );
            }

            $reserved = array( 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area' );
            $editing  = sanitize_key( isset( $data['editing'] ) ? $data['editing'] : '' );
            if ( in_array( $slug, $reserved, true ) && $slug !== $editing ) {
                wp_send_json_error( __( 'This slug is reserved by WordPress.', 'jscfr' ) );
            }

            $tax = array(
                'slug'               => $slug,
                'singular'           => sanitize_text_field( isset( $data['singular'] ) ? $data['singular'] : '' ),
                'plural'             => sanitize_text_field( isset( $data['plural'] ) ? $data['plural'] : '' ),
                'public'             => ! empty( $data['public'] ),
                'publicly_queryable' => ! empty( $data['publicly_queryable'] ),
                'show_ui'            => ! empty( $data['show_ui'] ),
                'show_in_menu'       => ! empty( $data['show_in_menu'] ),
                'show_in_nav_menus'  => ! empty( $data['show_in_nav_menus'] ),
                'show_in_rest'       => ! empty( $data['show_in_rest'] ),
                'show_admin_column'  => ! empty( $data['show_admin_column'] ),
                'show_tagcloud'      => ! empty( $data['show_tagcloud'] ),
                'hierarchical'       => ! empty( $data['hierarchical'] ),
                'rewrite_slug'       => sanitize_title( isset( $data['rewrite_slug'] ) ? $data['rewrite_slug'] : '' ),
                'rewrite_with_front' => true,
                'post_types'         => array(),
            );

            if ( ! empty( $data['post_types'] ) && is_array( $data['post_types'] ) ) {
                $tax['post_types'] = array_map( 'sanitize_key', $data['post_types'] );
            }

            $all = get_option( self::OPT_TAXES, array() );
            if ( ! is_array( $all ) ) $all = array();

            $found    = false;
            $old_slug = $editing;
            foreach ( $all as $i => $existing ) {
                if ( $existing['slug'] === $old_slug ) {
                    $all[ $i ] = $tax;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $all[] = $tax;
            }

            update_option( self::OPT_TAXES, $all );
            flush_rewrite_rules();

            wp_send_json_success( array( 'tax' => $tax ) );
        }

        public function ajax_delete_taxonomy() {
            check_ajax_referer( 'jscfr_cpt_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $slug = sanitize_key( isset( $_POST['slug'] ) ? $_POST['slug'] : '' );
            $all  = get_option( self::OPT_TAXES, array() );
            if ( ! is_array( $all ) ) $all = array();

            $all = array_values( array_filter( $all, function( $t ) use ( $slug ) {
                return $t['slug'] !== $slug;
            } ) );

            update_option( self::OPT_TAXES, $all );
            flush_rewrite_rules();

            wp_send_json_success();
        }

        /* ---------------------------------------------------------- */
        /*  AJAX: Get config for edit modal                            */
        /* ---------------------------------------------------------- */

        public function ajax_get_cpt_config() {
            check_ajax_referer( 'jscfr_cpt_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }
            $slug = sanitize_key( isset( $_POST['slug'] ) ? $_POST['slug'] : '' );
            $all  = get_option( self::OPT_CPTS, array() );
            if ( is_array( $all ) ) {
                foreach ( $all as $cpt ) {
                    if ( $cpt['slug'] === $slug ) {
                        wp_send_json_success( $cpt );
                    }
                }
            }
            wp_send_json_error( 'Not found' );
        }

        public function ajax_get_tax_config() {
            check_ajax_referer( 'jscfr_cpt_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }
            $slug = sanitize_key( isset( $_POST['slug'] ) ? $_POST['slug'] : '' );
            $all  = get_option( self::OPT_TAXES, array() );
            if ( is_array( $all ) ) {
                foreach ( $all as $tax ) {
                    if ( $tax['slug'] === $slug ) {
                        wp_send_json_success( $tax );
                    }
                }
            }
            wp_send_json_error( 'Not found' );
        }

        /* ---------------------------------------------------------- */
        /*  Public API: Get registered CPTs / Taxes                    */
        /* ---------------------------------------------------------- */

        public static function get_cpts() {
            $cpts = get_option( self::OPT_CPTS, array() );
            return is_array( $cpts ) ? $cpts : array();
        }

        public static function get_taxonomies() {
            $taxes = get_option( self::OPT_TAXES, array() );
            return is_array( $taxes ) ? $taxes : array();
        }
    }

}

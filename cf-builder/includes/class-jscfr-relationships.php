<?php
/**
 * JSCFR Relationships — Relationships Builder for managing bidirectional
 * connections between posts, terms, and users.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_Relationships' ) ) {

    final class JSCFR_Relationships {

        /** @var string WP option key for relationship definitions. */
        const OPTION_KEY = 'jscfr_relationships';

        /** @var string Option key for custom table version. */
        const DB_VERSION_KEY = 'jscfr_rel_db_version';

        /** @var string Current table schema version. */
        const DB_VERSION = '1.0.0';

        /** @var self|null Singleton instance. */
        private static $instance = null;

        /** @var string Fully-qualified table name (set in constructor). */
        private $table;

        /* ============================================================= */
        /*  Singleton                                                     */
        /* ============================================================= */

        /**
         * Get the singleton instance.
         *
         * @return self
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /* ============================================================= */
        /*  Constructor                                                   */
        /* ============================================================= */

        private function __construct() {
            global $wpdb;
            $this->table = $wpdb->prefix . 'jscfr_relationships';

            // Table creation / upgrade.
            add_action( 'admin_init', array( $this, 'maybe_create_table' ) );

            // Admin menu.
            add_action( 'admin_menu', array( $this, 'add_menu' ) );

            // Admin assets.
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

            // AJAX — definition management.
            add_action( 'wp_ajax_jscfr_save_relationship',   array( $this, 'ajax_save_relationship' ) );
            add_action( 'wp_ajax_jscfr_delete_relationship', array( $this, 'ajax_delete_relationship' ) );

            // AJAX — connection management.
            add_action( 'wp_ajax_jscfr_rel_connect',         array( $this, 'ajax_rel_connect' ) );
            add_action( 'wp_ajax_jscfr_rel_disconnect',      array( $this, 'ajax_rel_disconnect' ) );
            add_action( 'wp_ajax_jscfr_rel_get_connections',  array( $this, 'ajax_rel_get_connections' ) );

            // Metabox integration.
            add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ), 20, 2 );
        }

        /* ============================================================= */
        /*  Table creation / upgrade                                      */
        /* ============================================================= */

        /**
         * Create or update the custom table via dbDelta.
         */
        public function maybe_create_table() {
            $installed = get_option( self::DB_VERSION_KEY, '' );
            if ( self::DB_VERSION === $installed ) {
                return;
            }

            global $wpdb;
            $charset = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$this->table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                relationship_id varchar(100) NOT NULL DEFAULT '',
                from_type varchar(50) NOT NULL DEFAULT 'post',
                from_id bigint(20) unsigned NOT NULL DEFAULT 0,
                to_type varchar(50) NOT NULL DEFAULT 'post',
                to_id bigint(20) unsigned NOT NULL DEFAULT 0,
                order_from int(11) NOT NULL DEFAULT 0,
                order_to int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY rel_from (relationship_id, from_type, from_id),
                KEY rel_to (relationship_id, to_type, to_id)
            ) $charset;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );

            update_option( self::DB_VERSION_KEY, self::DB_VERSION );
        }

        /* ============================================================= */
        /*  Helpers — definitions CRUD                                    */
        /* ============================================================= */

        /**
         * Get all relationship definitions.
         *
         * @return array
         */
        private function get_definitions() {
            $defs = get_option( self::OPTION_KEY, array() );
            return is_array( $defs ) ? $defs : array();
        }

        /**
         * Save all relationship definitions.
         *
         * @param array $defs Full array of definitions.
         */
        private function save_definitions( $defs ) {
            update_option( self::OPTION_KEY, $defs );
        }

        /**
         * Get a single definition by ID.
         *
         * @param  string     $rel_id
         * @return array|null
         */
        private function get_definition( $rel_id ) {
            foreach ( $this->get_definitions() as $def ) {
                if ( isset( $def['id'] ) && $def['id'] === $rel_id ) {
                    return $def;
                }
            }
            return null;
        }

        /**
         * Sanitize a relationship definition coming from user input.
         *
         * @param  array $raw
         * @return array
         */
        private function sanitize_definition( $raw ) {
            $side_defaults = array(
                'object_type' => 'post',
                'post_type'   => '',
                'taxonomy'    => '',
                'meta_box'    => array( 'title' => '' ),
            );

            $sanitize_side = function ( $side ) use ( $side_defaults ) {
                $side = wp_parse_args( (array) $side, $side_defaults );
                $side['object_type'] = in_array( $side['object_type'], array( 'post', 'term', 'user' ), true )
                    ? $side['object_type']
                    : 'post';
                $side['post_type'] = sanitize_key( $side['post_type'] );
                $side['taxonomy']  = sanitize_key( $side['taxonomy'] );
                $side['meta_box']  = array(
                    'title' => sanitize_text_field( isset( $side['meta_box']['title'] ) ? $side['meta_box']['title'] : '' ),
                );
                return $side;
            };

            return array(
                'id'            => ! empty( $raw['id'] ) ? sanitize_key( $raw['id'] ) : 'rel_' . wp_generate_password( 8, false ),
                'label'         => sanitize_text_field( isset( $raw['label'] ) ? $raw['label'] : '' ),
                'from'          => $sanitize_side( isset( $raw['from'] ) ? $raw['from'] : array() ),
                'to'            => $sanitize_side( isset( $raw['to'] ) ? $raw['to'] : array() ),
                'bidirectional' => ! empty( $raw['bidirectional'] ),
                'sortable'      => ! empty( $raw['sortable'] ),
            );
        }

        /* ============================================================= */
        /*  Admin menu                                                    */
        /* ============================================================= */

        /**
         * Add the Relationships submenu under CF Builder.
         */
        public function add_menu() {
            add_submenu_page(
                'jscfr-builder',
                __( 'Relationships', 'jscfr' ),
                __( 'Relationships', 'jscfr' ),
                'manage_options',
                'jscfr-relationships',
                array( $this, 'render_page' )
            );
        }

        /* ============================================================= */
        /*  Page router                                                   */
        /* ============================================================= */

        /**
         * Render the admin page (list or edit).
         */
        public function render_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'jscfr' ) );
            }

            $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

            if ( 'edit' === $action ) {
                $this->render_edit_page();
            } else {
                $this->render_list_page();
            }
        }

        /* ============================================================= */
        /*  List page                                                     */
        /* ============================================================= */

        /**
         * Render the list of all relationship definitions.
         */
        private function render_list_page() {
            $definitions = $this->get_definitions();
            $edit_url    = admin_url( 'admin.php?page=jscfr-relationships&action=edit' );
            $add_url     = esc_url( $edit_url . '&rel_id=new' );

            // Reuse the CPT/Tax list page stylesheet for modern UI.
            wp_enqueue_style( 'jscfr-cpt-css', JSCFR_PLUGIN_URL . 'assets/css/jscfr-cpt.css', array(), JSCFR_VERSION );
            ?>
            <div class="wrap jscfr-cpt-wrap">
                <h1><?php esc_html_e( 'Relationships', 'jscfr' ); ?> <a href="<?php echo $add_url; ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'jscfr' ); ?></a></h1>

                <table class="wp-list-table widefat fixed striped" id="jscfr-rel-list">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Label', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'From', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'To', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Bidirectional', 'jscfr' ); ?></th>
                            <th style="width:160px;"><?php esc_html_e( 'Actions', 'jscfr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $definitions ) ) : ?>
                        <tr class="jscfr-no-items"><td colspan="5"><?php esc_html_e( 'No relationships defined yet.', 'jscfr' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $definitions as $def ) :
                            $rel_id     = $def['id'];
                            $label      = ! empty( $def['label'] ) ? $def['label'] : __( '(no label)', 'jscfr' );
                            $from       = $this->describe_side( $def['from'] );
                            $to         = $this->describe_side( $def['to'] );
                            $bidir      = ! empty( $def['bidirectional'] );
                            $row_edit_url = esc_url( $edit_url . '&rel_id=' . $rel_id );
                            ?>
                            <tr data-rel-id="<?php echo esc_attr( $rel_id ); ?>">
                                <td class="jscfr-col-slug"><a href="<?php echo $row_edit_url; ?>" class="jscfr-slug-link"><?php echo esc_html( $label ); ?></a></td>
                                <td><span class="jscfr-pill"><?php echo esc_html( $from ); ?></span></td>
                                <td><span class="jscfr-pill"><?php echo esc_html( $to ); ?></span></td>
                                <td>
                                    <?php if ( $bidir ) : ?>
                                        <span class="jscfr-badge jscfr-badge-yes"><?php esc_html_e( 'Yes', 'jscfr' ); ?></span>
                                    <?php else : ?>
                                        <span class="jscfr-badge jscfr-badge-no"><?php esc_html_e( 'No', 'jscfr' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="jscfr-col-actions">
                                    <a href="<?php echo $row_edit_url; ?>" class="button jscfr-btn-ghost"><?php esc_html_e( 'Edit', 'jscfr' ); ?></a>
                                    <button type="button" class="button jscfr-btn-ghost jscfr-btn-danger jscfr-rel-delete" data-id="<?php echo esc_attr( $rel_id ); ?>"><?php esc_html_e( 'Delete', 'jscfr' ); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

        /**
         * Describe a side (from/to) for the list table.
         *
         * @param  array  $side
         * @return string
         */
        private function describe_side( $side ) {
            $type = isset( $side['object_type'] ) ? $side['object_type'] : 'post';

            if ( 'post' === $type ) {
                $pt   = ! empty( $side['post_type'] ) ? $side['post_type'] : 'any';
                $obj  = get_post_type_object( $pt );
                $name = $obj ? $obj->labels->singular_name : $pt;
                return sprintf( '%s (%s)', __( 'Post', 'jscfr' ), $name );
            }

            if ( 'term' === $type ) {
                $tax  = ! empty( $side['taxonomy'] ) ? $side['taxonomy'] : 'category';
                $obj  = get_taxonomy( $tax );
                $name = $obj ? $obj->labels->singular_name : $tax;
                return sprintf( '%s (%s)', __( 'Term', 'jscfr' ), $name );
            }

            return __( 'User', 'jscfr' );
        }

        /* ============================================================= */
        /*  Edit page                                                     */
        /* ============================================================= */

        /**
         * Render the relationship edit / create form.
         */
        private function render_edit_page() {
            $rel_id = isset( $_GET['rel_id'] ) ? sanitize_key( $_GET['rel_id'] ) : 'new';
            $def    = ( 'new' !== $rel_id ) ? $this->get_definition( $rel_id ) : null;

            if ( ! $def ) {
                $def = array(
                    'id'            => 'rel_' . wp_generate_password( 8, false ),
                    'label'         => '',
                    'from'          => array(
                        'object_type' => 'post',
                        'post_type'   => 'post',
                        'taxonomy'    => '',
                        'meta_box'    => array( 'title' => '' ),
                    ),
                    'to'            => array(
                        'object_type' => 'post',
                        'post_type'   => 'post',
                        'taxonomy'    => '',
                        'meta_box'    => array( 'title' => '' ),
                    ),
                    'bidirectional' => true,
                    'sortable'      => false,
                );
            }

            $post_types = get_post_types( array( 'public' => true ), 'objects' );
            $taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
            $back_url   = esc_url( admin_url( 'admin.php?page=jscfr-relationships' ) );
            $is_edit    = ( 'new' !== $rel_id );
            $title      = $is_edit
                ? __( 'Edit Relationship', 'jscfr' )
                : __( 'New Relationship', 'jscfr' );

            wp_enqueue_style( 'jscfr-cpt-css', JSCFR_PLUGIN_URL . 'assets/css/jscfr-cpt.css', array(), JSCFR_VERSION );
            ?>
            <div class="wrap jscfr-cpt-wrap jscfr-mb-page">
                <div class="jscfr-mb-page-header">
                    <h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
                    <a href="<?php echo $back_url; ?>" class="page-title-action"><?php esc_html_e( '← Back to Relationships', 'jscfr' ); ?></a>
                </div>
                <hr class="wp-header-end" />

                <form id="jscfr-rel-form" class="jscfr-mb-style" data-rel-id="<?php echo esc_attr( $def['id'] ); ?>">
                    <input type="hidden" name="rel_id" value="<?php echo esc_attr( $def['id'] ); ?>" />

                    <div class="jscfr-mb-tabs">
                        <ul class="jscfr-mb-tab-nav" role="tablist">
                            <li class="active" data-jscfr-tab="general"><?php esc_html_e( 'General', 'jscfr' ); ?></li>
                            <li data-jscfr-tab="from"><?php esc_html_e( 'From', 'jscfr' ); ?></li>
                            <li data-jscfr-tab="to"><?php esc_html_e( 'To', 'jscfr' ); ?></li>
                        </ul>

                        <div class="jscfr-mb-tab-panel active" data-jscfr-panel="general">
                            <div class="jscfr-mb-row">
                                <label for="jscfr-rel-label"><?php esc_html_e( 'Relationship Label', 'jscfr' ); ?><span class="jscfr-mb-req">*</span></label>
                                <div class="jscfr-mb-control">
                                    <input type="text" id="jscfr-rel-label" name="label"
                                           value="<?php echo esc_attr( $def['label'] ); ?>"
                                           placeholder="<?php esc_attr_e( 'e.g. Projects to Investors', 'jscfr' ); ?>" required />
                                    <p class="jscfr-mb-desc"><?php esc_html_e( 'Human-readable name shown throughout the admin.', 'jscfr' ); ?></p>
                                </div>
                            </div>
                            <div class="jscfr-mb-row jscfr-mb-row-toggle">
                                <label><?php esc_html_e( 'Bidirectional', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <label class="jscfr-toggle">
                                        <input type="checkbox" name="bidirectional" value="1" <?php checked( ! empty( $def['bidirectional'] ) ); ?> />
                                        <span class="jscfr-toggle-slider"></span>
                                    </label>
                                    <span class="jscfr-mb-toggle-desc"><?php esc_html_e( 'When a connection is created from A to B, automatically create the reverse connection from B to A.', 'jscfr' ); ?></span>
                                </div>
                            </div>
                            <div class="jscfr-mb-row jscfr-mb-row-toggle">
                                <label><?php esc_html_e( 'Sortable', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <label class="jscfr-toggle">
                                        <input type="checkbox" name="sortable" value="1" <?php checked( ! empty( $def['sortable'] ) ); ?> />
                                        <span class="jscfr-toggle-slider"></span>
                                    </label>
                                    <span class="jscfr-mb-toggle-desc"><?php esc_html_e( 'Allow drag-and-drop reordering of connected items in the metabox.', 'jscfr' ); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="jscfr-mb-tab-panel" data-jscfr-panel="from">
                            <?php $this->render_side_fields( 'from', $def['from'], $post_types, $taxonomies ); ?>
                        </div>

                        <div class="jscfr-mb-tab-panel" data-jscfr-panel="to">
                            <?php $this->render_side_fields( 'to', $def['to'], $post_types, $taxonomies ); ?>
                        </div>
                    </div>

                    <div class="jscfr-mb-footer">
                        <button type="submit" class="button button-primary button-large" id="jscfr-rel-save">
                            <?php echo esc_html( $is_edit ? __( 'Save Changes', 'jscfr' ) : __( 'Create Relationship', 'jscfr' ) ); ?>
                        </button>
                        <a href="<?php echo $back_url; ?>" class="button button-large"><?php esc_html_e( 'Cancel', 'jscfr' ); ?></a>
                        <span class="spinner" id="jscfr-rel-spinner"></span>
                    </div>
                </form>
            </div>
            <?php
        }

        /**
         * Render the From/To side fields.
         *
         * @param string $side        'from' or 'to'.
         * @param array  $values      Current side values.
         * @param array  $post_types  Post type objects.
         * @param array  $taxonomies  Taxonomy objects.
         */
        private function render_side_fields( $side, $values, $post_types, $taxonomies ) {
            $object_type  = isset( $values['object_type'] ) ? $values['object_type'] : 'post';
            $post_type    = isset( $values['post_type'] ) ? $values['post_type'] : '';
            $taxonomy     = isset( $values['taxonomy'] ) ? $values['taxonomy'] : '';
            $mb_title     = isset( $values['meta_box']['title'] ) ? $values['meta_box']['title'] : '';
            ?>
            <div class="jscfr-mb-row">
                <label for="jscfr-rel-<?php echo esc_attr( $side ); ?>-object-type"><?php esc_html_e( 'Object Type', 'jscfr' ); ?></label>
                <div class="jscfr-mb-control">
                    <select id="jscfr-rel-<?php echo esc_attr( $side ); ?>-object-type"
                            name="<?php echo esc_attr( $side ); ?>[object_type]"
                            class="jscfr-rel-object-type" data-side="<?php echo esc_attr( $side ); ?>">
                        <option value="post" <?php selected( $object_type, 'post' ); ?>><?php esc_html_e( 'Post', 'jscfr' ); ?></option>
                        <option value="term" <?php selected( $object_type, 'term' ); ?>><?php esc_html_e( 'Term', 'jscfr' ); ?></option>
                        <option value="user" <?php selected( $object_type, 'user' ); ?>><?php esc_html_e( 'User', 'jscfr' ); ?></option>
                    </select>
                    <p class="jscfr-mb-desc"><?php esc_html_e( 'What kind of objects live on this side of the relationship.', 'jscfr' ); ?></p>
                </div>
            </div>

            <div class="jscfr-mb-row jscfr-rel-row-post-type" data-side="<?php echo esc_attr( $side ); ?>"
                 style="<?php echo 'post' !== $object_type ? 'display:none;' : ''; ?>">
                <label for="jscfr-rel-<?php echo esc_attr( $side ); ?>-post-type"><?php esc_html_e( 'Post Type', 'jscfr' ); ?></label>
                <div class="jscfr-mb-control">
                    <select id="jscfr-rel-<?php echo esc_attr( $side ); ?>-post-type"
                            name="<?php echo esc_attr( $side ); ?>[post_type]">
                        <?php foreach ( $post_types as $pt ) : ?>
                            <option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $post_type, $pt->name ); ?>>
                                <?php echo esc_html( $pt->labels->singular_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="jscfr-mb-row jscfr-rel-row-taxonomy" data-side="<?php echo esc_attr( $side ); ?>"
                 style="<?php echo 'term' !== $object_type ? 'display:none;' : ''; ?>">
                <label for="jscfr-rel-<?php echo esc_attr( $side ); ?>-taxonomy"><?php esc_html_e( 'Taxonomy', 'jscfr' ); ?></label>
                <div class="jscfr-mb-control">
                    <select id="jscfr-rel-<?php echo esc_attr( $side ); ?>-taxonomy"
                            name="<?php echo esc_attr( $side ); ?>[taxonomy]">
                        <?php foreach ( $taxonomies as $tax ) : ?>
                            <option value="<?php echo esc_attr( $tax->name ); ?>" <?php selected( $taxonomy, $tax->name ); ?>>
                                <?php echo esc_html( $tax->labels->singular_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="jscfr-mb-row">
                <label for="jscfr-rel-<?php echo esc_attr( $side ); ?>-mb-title"><?php esc_html_e( 'Meta Box Title', 'jscfr' ); ?></label>
                <div class="jscfr-mb-control">
                    <input type="text" id="jscfr-rel-<?php echo esc_attr( $side ); ?>-mb-title"
                           name="<?php echo esc_attr( $side ); ?>[meta_box][title]"
                           value="<?php echo esc_attr( $mb_title ); ?>"
                           placeholder="<?php esc_attr_e( 'e.g. Related Items', 'jscfr' ); ?>" />
                    <p class="jscfr-mb-desc"><?php esc_html_e( 'Title of the connection metabox shown on the post edit screen.', 'jscfr' ); ?></p>
                </div>
            </div>
            <?php
        }

        /* ============================================================= */
        /*  Admin assets                                                  */
        /* ============================================================= */

        /**
         * Enqueue CSS/JS on the Relationships admin page and post edit screens.
         *
         * @param string $hook
         */
        public function enqueue_assets( $hook ) {
            // --- Relationships admin page ---
            if ( 'cf-builder_page_jscfr-relationships' === $hook ) {
                wp_enqueue_style(
                    'jscfr-builder-css',
                    JSCFR_PLUGIN_URL . 'assets/css/jscfr-builder.css',
                    array(),
                    JSCFR_VERSION
                );

                $this->print_inline_styles();
                $this->print_inline_scripts( 'admin' );
                return;
            }

            // --- Post edit screens: metabox connection UI ---
            if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
                return;
            }

            $screen = get_current_screen();
            if ( ! $screen ) {
                return;
            }

            // Check whether any relationship targets this post type.
            $definitions = $this->get_definitions();
            $has_rel     = false;
            foreach ( $definitions as $def ) {
                if ( $this->side_matches_post_type( $def['from'], $screen->post_type )
                     || $this->side_matches_post_type( $def['to'], $screen->post_type ) ) {
                    $has_rel = true;
                    break;
                }
            }

            if ( ! $has_rel ) {
                return;
            }

            wp_enqueue_script( 'jquery-ui-sortable' );
            $this->print_inline_styles();
            $this->print_inline_scripts( 'metabox' );
        }

        /**
         * Check if a side definition targets a given post type.
         *
         * @param  array  $side
         * @param  string $post_type
         * @return bool
         */
        private function side_matches_post_type( $side, $post_type ) {
            return isset( $side['object_type'] )
                && 'post' === $side['object_type']
                && isset( $side['post_type'] )
                && $side['post_type'] === $post_type;
        }

        /**
         * Print shared inline CSS.
         */
        private function print_inline_styles() {
            static $printed = false;
            if ( $printed ) {
                return;
            }
            $printed = true;
            ?>
            <style>
                /* Relationship edit form */
                .jscfr-rel-form .jscfr-rel-section { background: #fff; border: 1px solid #ccd0d4; padding: 12px 20px; margin-bottom: 16px; }
                .jscfr-rel-form .jscfr-rel-section h2 { margin: 0 0 8px; padding: 0; font-size: 14px; }
                .jscfr-rel-form .form-table th { width: 200px; }
                .jscfr-rel-form .submit .spinner { float: none; vertical-align: middle; }
                .jscfr-back-link { text-decoration: none; margin-right: 6px; }

                /* Relationship list table */
                .jscfr-rel-table .jscfr-col-actions { white-space: nowrap; }
                .jscfr-rel-table .jscfr-col-actions .button { margin-right: 4px; }

                /* Metabox connection UI */
                .jscfr-rel-metabox-inner { padding: 8px 0; }
                .jscfr-rel-connected-list { list-style: none; margin: 0 0 10px; padding: 0; }
                .jscfr-rel-connected-list li { display: flex; align-items: center; padding: 6px 8px; margin-bottom: 4px;
                    background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 3px; }
                .jscfr-rel-connected-list li .jscfr-rel-item-title { flex: 1; }
                .jscfr-rel-connected-list li .jscfr-rel-item-remove { color: #b32d2e; cursor: pointer; text-decoration: none; margin-left: 8px; }
                .jscfr-rel-connected-list li .jscfr-rel-sort-handle { cursor: move; margin-right: 8px; color: #999; }
                .jscfr-rel-search-wrap { display: flex; gap: 6px; }
                .jscfr-rel-search-wrap input[type="text"] { flex: 1; }
                .jscfr-rel-search-results { list-style: none; margin: 6px 0 0; padding: 0; max-height: 200px; overflow-y: auto;
                    border: 1px solid #dcdcde; border-radius: 3px; display: none; }
                .jscfr-rel-search-results li { padding: 6px 10px; cursor: pointer; border-bottom: 1px solid #f0f0f1; }
                .jscfr-rel-search-results li:hover { background: #f0f6fc; }
                .jscfr-rel-search-results li:last-child { border-bottom: none; }
                .jscfr-rel-empty { color: #999; font-style: italic; }
            </style>
            <?php
        }

        /**
         * Print inline JavaScript for a given context.
         *
         * @param string $context 'admin' or 'metabox'.
         */
        private function print_inline_scripts( $context ) {
            $nonce    = wp_create_nonce( JSCFR_BUILDER_NONCE );
            $ajax_url = admin_url( 'admin-ajax.php' );

            if ( 'admin' === $context ) {
                // Scripts for the Relationships admin page.
                ?>
                <script>
                (function($){
                    var nonce   = '<?php echo esc_js( $nonce ); ?>';
                    var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';

                    /* Tab switching for Meta Box–style form */
                    $(document).on('click', '.jscfr-mb-tab-nav li[data-jscfr-tab]', function(){
                        var $tabs  = $(this).closest('.jscfr-mb-tabs');
                        var target = $(this).data('jscfr-tab');
                        $tabs.find('.jscfr-mb-tab-nav li').removeClass('active');
                        $(this).addClass('active');
                        $tabs.find('.jscfr-mb-tab-panel').removeClass('active');
                        $tabs.find('.jscfr-mb-tab-panel[data-jscfr-panel="'+target+'"]').addClass('active');
                    });

                    /* Toggle post type / taxonomy rows */
                    $(document).on('change', '.jscfr-rel-object-type', function(){
                        var side = $(this).data('side');
                        var val  = $(this).val();
                        $('.jscfr-rel-row-post-type[data-side="'+side+'"]').toggle( val === 'post' );
                        $('.jscfr-rel-row-taxonomy[data-side="'+side+'"]').toggle( val === 'term' );
                    });

                    /* Save relationship */
                    $(document).on('submit', '#jscfr-rel-form', function(e){
                        e.preventDefault();
                        var $form    = $(this);
                        var $spinner = $('#jscfr-rel-spinner');
                        $spinner.addClass('is-active');

                        var data = {
                            action : 'jscfr_save_relationship',
                            nonce  : nonce,
                            rel    : JSON.stringify( jscfrSerializeForm($form) )
                        };

                        $.post(ajaxUrl, data, function(res){
                            $spinner.removeClass('is-active');
                            if ( res.success ) {
                                window.location.href = res.data.redirect;
                            } else {
                                alert( res.data || 'Error saving relationship.' );
                            }
                        });
                    });

                    /* Delete relationship */
                    $(document).on('click', '.jscfr-rel-delete', function(e){
                        e.preventDefault();
                        if ( ! confirm('<?php echo esc_js( __( 'Delete this relationship? This cannot be undone.', 'jscfr' ) ); ?>') ) return;
                        var id = $(this).data('id');
                        var $row = $(this).closest('tr');
                        $.post(ajaxUrl, { action:'jscfr_delete_relationship', nonce:nonce, rel_id:id }, function(res){
                            if ( res.success ) $row.fadeOut(300, function(){ $(this).remove(); });
                        });
                    });

                    /* Serialize form into nested object */
                    function jscfrSerializeForm($form){
                        var obj = {};
                        var arr = $form.serializeArray();
                        $.each(arr, function(i, item){
                            var keys  = item.name.replace(/\]/g,'').split('[');
                            var cur   = obj;
                            for ( var k = 0; k < keys.length; k++ ){
                                var key = keys[k];
                                if ( k === keys.length - 1 ){
                                    cur[key] = item.value;
                                } else {
                                    if ( typeof cur[key] === 'undefined' ) cur[key] = {};
                                    cur = cur[key];
                                }
                            }
                        });
                        /* Checkboxes that are unchecked won't appear — handle booleans */
                        if ( typeof obj.bidirectional === 'undefined' ) obj.bidirectional = false;
                        if ( typeof obj.sortable === 'undefined' )      obj.sortable = false;
                        return obj;
                    }
                })(jQuery);
                </script>
                <?php
            }

            if ( 'metabox' === $context ) {
                // Scripts for post edit screen metabox.
                ?>
                <script>
                (function($){
                    var nonce   = '<?php echo esc_js( $nonce ); ?>';
                    var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';

                    /* Load connections on page load */
                    $(document).ready(function(){
                        $('.jscfr-rel-metabox-inner').each(function(){
                            loadConnections( $(this) );
                        });
                    });

                    function loadConnections( $wrap ){
                        var relId    = $wrap.data('rel-id');
                        var fromId   = $wrap.data('from-id');
                        var fromType = $wrap.data('from-type');
                        var $list    = $wrap.find('.jscfr-rel-connected-list');
                        var sortable = $wrap.data('sortable');

                        $.post(ajaxUrl, {
                            action    : 'jscfr_rel_get_connections',
                            nonce     : nonce,
                            rel_id    : relId,
                            from_id   : fromId,
                            from_type : fromType
                        }, function(res){
                            $list.empty();
                            if ( res.success && res.data.length ) {
                                $.each(res.data, function(i, item){
                                    $list.append( connectionItem(item, sortable) );
                                });
                            } else {
                                $list.append('<li class="jscfr-rel-empty"><?php echo esc_js( __( 'No connections yet.', 'jscfr' ) ); ?></li>');
                            }
                            if ( sortable ) {
                                $list.sortable({
                                    handle: '.jscfr-rel-sort-handle',
                                    update: function(){ updateOrder( $wrap ); }
                                });
                            }
                        });
                    }

                    function connectionItem( item, sortable ){
                        var handle = sortable ? '<span class="jscfr-rel-sort-handle dashicons dashicons-menu"></span>' : '';
                        return '<li data-to-id="'+item.id+'" data-to-type="'+item.type+'">'
                            + handle
                            + '<span class="jscfr-rel-item-title">'+item.title+'</span>'
                            + '<a href="#" class="jscfr-rel-item-remove" title="<?php echo esc_attr__( 'Remove', 'jscfr' ); ?>">&times;</a>'
                            + '</li>';
                    }

                    function updateOrder( $wrap ){
                        /* Order persistence is handled via individual re-connect calls if needed. */
                        var relId    = $wrap.data('rel-id');
                        var fromId   = $wrap.data('from-id');
                        var fromType = $wrap.data('from-type');
                        var order    = [];
                        $wrap.find('.jscfr-rel-connected-list li[data-to-id]').each(function(i){
                            order.push({ to_id: $(this).data('to-id'), to_type: $(this).data('to-type'), position: i });
                        });
                        /* Bulk-update is a future enhancement; for now order is preserved client-side. */
                    }

                    /* Search */
                    $(document).on('input', '.jscfr-rel-search-input', function(){
                        var $wrap    = $(this).closest('.jscfr-rel-metabox-inner');
                        var term     = $(this).val();
                        var $results = $wrap.find('.jscfr-rel-search-results');
                        var toType   = $wrap.data('to-type');
                        var toSubtype= $wrap.data('to-subtype');

                        if ( term.length < 2 ) { $results.hide(); return; }

                        var searchData = {
                            nonce  : nonce,
                            search : term
                        };

                        if ( 'post' === toType ) {
                            searchData.action    = 'jscfr_search_posts';
                            searchData.post_type = toSubtype;
                        } else if ( 'term' === toType ) {
                            searchData.action   = 'jscfr_search_terms';
                            searchData.taxonomy = toSubtype;
                        } else {
                            searchData.action = 'jscfr_search_users';
                        }

                        $.post(ajaxUrl, searchData, function(res){
                            $results.empty();
                            if ( res.success && res.data.length ) {
                                $.each(res.data, function(i, item){
                                    var label = item.title || item.name || item.display_name || ('#'+item.id);
                                    $results.append('<li data-id="'+item.id+'">'+label+'</li>');
                                });
                                $results.show();
                            } else {
                                $results.hide();
                            }
                        });
                    });

                    /* Select search result to connect */
                    $(document).on('click', '.jscfr-rel-search-results li', function(){
                        var $wrap    = $(this).closest('.jscfr-rel-metabox-inner');
                        var relId    = $wrap.data('rel-id');
                        var fromId   = $wrap.data('from-id');
                        var fromType = $wrap.data('from-type');
                        var toType   = $wrap.data('to-type');
                        var toId     = $(this).data('id');
                        var $results = $wrap.find('.jscfr-rel-search-results');
                        var $input   = $wrap.find('.jscfr-rel-search-input');

                        $.post(ajaxUrl, {
                            action    : 'jscfr_rel_connect',
                            nonce     : nonce,
                            rel_id    : relId,
                            from_id   : fromId,
                            to_id     : toId,
                            from_type : fromType,
                            to_type   : toType
                        }, function(res){
                            if ( res.success ) {
                                loadConnections( $wrap );
                                $input.val('');
                                $results.hide();
                            }
                        });
                    });

                    /* Disconnect */
                    $(document).on('click', '.jscfr-rel-item-remove', function(e){
                        e.preventDefault();
                        var $wrap  = $(this).closest('.jscfr-rel-metabox-inner');
                        var relId  = $wrap.data('rel-id');
                        var fromId = $wrap.data('from-id');
                        var toId   = $(this).closest('li').data('to-id');

                        $.post(ajaxUrl, {
                            action  : 'jscfr_rel_disconnect',
                            nonce   : nonce,
                            rel_id  : relId,
                            from_id : fromId,
                            to_id   : toId
                        }, function(res){
                            if ( res.success ) loadConnections( $wrap );
                        });
                    });

                    /* Close search results on outside click */
                    $(document).on('click', function(e){
                        if ( ! $(e.target).closest('.jscfr-rel-search-wrap, .jscfr-rel-search-results').length ) {
                            $('.jscfr-rel-search-results').hide();
                        }
                    });

                })(jQuery);
                </script>
                <?php
            }
        }

        /* ============================================================= */
        /*  Metabox integration                                           */
        /* ============================================================= */

        /**
         * Register metaboxes for each relationship on matching post types.
         *
         * @param string  $post_type
         * @param WP_Post $post
         */
        public function register_meta_boxes( $post_type, $post ) {
            $definitions = $this->get_definitions();

            foreach ( $definitions as $def ) {
                // Check the "from" side.
                if ( $this->side_matches_post_type( $def['from'], $post_type ) ) {
                    $mb_title = ! empty( $def['from']['meta_box']['title'] )
                        ? $def['from']['meta_box']['title']
                        : $def['label'];

                    add_meta_box(
                        'jscfr_rel_from_' . $def['id'],
                        esc_html( $mb_title ),
                        array( $this, 'render_meta_box' ),
                        $post_type,
                        'side',
                        'default',
                        array(
                            'def'       => $def,
                            'direction' => 'from',
                        )
                    );
                }

                // Check the "to" side (bidirectional shows the reverse metabox).
                if ( ! empty( $def['bidirectional'] ) && $this->side_matches_post_type( $def['to'], $post_type ) ) {
                    $mb_title = ! empty( $def['to']['meta_box']['title'] )
                        ? $def['to']['meta_box']['title']
                        : $def['label'];

                    add_meta_box(
                        'jscfr_rel_to_' . $def['id'],
                        esc_html( $mb_title ),
                        array( $this, 'render_meta_box' ),
                        $post_type,
                        'side',
                        'default',
                        array(
                            'def'       => $def,
                            'direction' => 'to',
                        )
                    );
                }
            }
        }

        /**
         * Render the connection metabox content.
         *
         * @param WP_Post $post
         * @param array   $metabox
         */
        public function render_meta_box( $post, $metabox ) {
            $def       = $metabox['args']['def'];
            $direction = $metabox['args']['direction'];

            // Determine the "other" side.
            if ( 'from' === $direction ) {
                $from_type = $def['from']['object_type'];
                $to_type   = $def['to']['object_type'];
                $to_sub    = 'post' === $to_type ? $def['to']['post_type'] : ( 'term' === $to_type ? $def['to']['taxonomy'] : '' );
            } else {
                // Reverse: the current post is on the "to" side, looking back at "from".
                $from_type = $def['to']['object_type'];
                $to_type   = $def['from']['object_type'];
                $to_sub    = 'post' === $to_type ? $def['from']['post_type'] : ( 'term' === $to_type ? $def['from']['taxonomy'] : '' );
            }

            $sortable = ! empty( $def['sortable'] ) ? 1 : 0;
            ?>
            <div class="jscfr-rel-metabox-inner"
                 data-rel-id="<?php echo esc_attr( $def['id'] ); ?>"
                 data-from-id="<?php echo esc_attr( $post->ID ); ?>"
                 data-from-type="<?php echo esc_attr( $from_type ); ?>"
                 data-to-type="<?php echo esc_attr( $to_type ); ?>"
                 data-to-subtype="<?php echo esc_attr( $to_sub ); ?>"
                 data-direction="<?php echo esc_attr( $direction ); ?>"
                 data-sortable="<?php echo esc_attr( $sortable ); ?>">

                <ul class="jscfr-rel-connected-list"></ul>

                <div class="jscfr-rel-search-wrap">
                    <input type="text" class="jscfr-rel-search-input"
                           placeholder="<?php esc_attr_e( 'Search to connect...', 'jscfr' ); ?>" />
                </div>
                <ul class="jscfr-rel-search-results"></ul>
            </div>
            <?php
        }

        /* ============================================================= */
        /*  AJAX: Save relationship definition                            */
        /* ============================================================= */

        /**
         * Save or update a relationship definition.
         */
        public function ajax_save_relationship() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $raw = isset( $_POST['rel'] ) ? $_POST['rel'] : '{}';
            $decoded = json_decode( wp_unslash( $raw ), true );
            if ( ! is_array( $decoded ) ) {
                wp_send_json_error( 'Invalid data' );
            }

            $clean = $this->sanitize_definition( $decoded );
            if ( empty( $clean['label'] ) ) {
                wp_send_json_error( __( 'Relationship label is required.', 'jscfr' ) );
            }

            // Update or insert.
            $definitions = $this->get_definitions();
            $found       = false;
            foreach ( $definitions as $i => $def ) {
                if ( $def['id'] === $clean['id'] ) {
                    $definitions[ $i ] = $clean;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $definitions[] = $clean;
            }

            $this->save_definitions( $definitions );

            wp_send_json_success( array(
                'relationship' => $clean,
                'redirect'     => admin_url( 'admin.php?page=jscfr-relationships&action=edit&rel_id=' . $clean['id'] ),
            ) );
        }

        /* ============================================================= */
        /*  AJAX: Delete relationship definition                          */
        /* ============================================================= */

        /**
         * Delete a relationship definition and optionally its connection data.
         */
        public function ajax_delete_relationship() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $rel_id = isset( $_POST['rel_id'] ) ? sanitize_key( $_POST['rel_id'] ) : '';
            if ( ! $rel_id ) {
                wp_send_json_error( 'No ID' );
            }

            // Remove definition.
            $definitions = $this->get_definitions();
            $definitions = array_values( array_filter( $definitions, function ( $d ) use ( $rel_id ) {
                return $d['id'] !== $rel_id;
            } ) );
            $this->save_definitions( $definitions );

            // Remove connection rows.
            global $wpdb;
            $wpdb->delete( $this->table, array( 'relationship_id' => $rel_id ), array( '%s' ) );

            wp_send_json_success();
        }

        /* ============================================================= */
        /*  AJAX: Connect two objects                                     */
        /* ============================================================= */

        /**
         * Create a connection between two objects.
         */
        public function ajax_rel_connect() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $rel_id    = isset( $_POST['rel_id'] )    ? sanitize_key( $_POST['rel_id'] )    : '';
            $from_id   = isset( $_POST['from_id'] )   ? absint( $_POST['from_id'] )          : 0;
            $to_id     = isset( $_POST['to_id'] )     ? absint( $_POST['to_id'] )            : 0;
            $from_type = isset( $_POST['from_type'] ) ? sanitize_key( $_POST['from_type'] )  : 'post';
            $to_type   = isset( $_POST['to_type'] )   ? sanitize_key( $_POST['to_type'] )    : 'post';

            if ( ! $rel_id || ! $from_id || ! $to_id ) {
                wp_send_json_error( 'Missing parameters' );
            }

            $result = self::connect( $rel_id, $from_id, $to_id, $from_type, $to_type );
            if ( $result ) {
                wp_send_json_success();
            } else {
                wp_send_json_error( 'Connection already exists or failed' );
            }
        }

        /* ============================================================= */
        /*  AJAX: Disconnect two objects                                  */
        /* ============================================================= */

        /**
         * Remove a connection between two objects.
         */
        public function ajax_rel_disconnect() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $rel_id  = isset( $_POST['rel_id'] )  ? sanitize_key( $_POST['rel_id'] )  : '';
            $from_id = isset( $_POST['from_id'] ) ? absint( $_POST['from_id'] )        : 0;
            $to_id   = isset( $_POST['to_id'] )   ? absint( $_POST['to_id'] )          : 0;

            if ( ! $rel_id || ! $from_id || ! $to_id ) {
                wp_send_json_error( 'Missing parameters' );
            }

            self::disconnect( $rel_id, $from_id, $to_id );
            wp_send_json_success();
        }

        /* ============================================================= */
        /*  AJAX: Get connections for an object                           */
        /* ============================================================= */

        /**
         * Return connected items for a given object in a relationship.
         */
        public function ajax_rel_get_connections() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $rel_id    = isset( $_POST['rel_id'] )    ? sanitize_key( $_POST['rel_id'] )    : '';
            $from_id   = isset( $_POST['from_id'] )   ? absint( $_POST['from_id'] )          : 0;
            $from_type = isset( $_POST['from_type'] ) ? sanitize_key( $_POST['from_type'] )  : 'post';

            if ( ! $rel_id || ! $from_id ) {
                wp_send_json_error( 'Missing parameters' );
            }

            $connected_ids = self::get_connected( $rel_id, $from_id, $from_type );
            $def           = $this->get_definition( $rel_id );
            $items         = array();

            if ( ! empty( $connected_ids ) && $def ) {
                // Determine what type the connected objects are.
                // We need to figure out which "side" $from_id is on.
                $to_type = $this->resolve_to_type( $def, $from_type, $from_id );

                foreach ( $connected_ids as $cid ) {
                    $items[] = $this->describe_object( $cid, $to_type );
                }
            }

            wp_send_json_success( $items );
        }

        /**
         * Figure out what type the connected objects are for a given from_id in a relationship.
         *
         * @param  array  $def
         * @param  string $from_type
         * @param  int    $from_id
         * @return string
         */
        private function resolve_to_type( $def, $from_type, $from_id ) {
            // If from matches the "from" side of the definition, return the "to" side type.
            if ( $def['from']['object_type'] === $from_type ) {
                return $def['to']['object_type'];
            }
            return $def['from']['object_type'];
        }

        /**
         * Describe an object (post/term/user) for JSON output.
         *
         * @param  int    $id
         * @param  string $type 'post', 'term', 'user'.
         * @return array
         */
        private function describe_object( $id, $type ) {
            switch ( $type ) {
                case 'term':
                    $term = get_term( $id );
                    return array(
                        'id'    => $id,
                        'type'  => 'term',
                        'title' => ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $id,
                    );

                case 'user':
                    $user = get_userdata( $id );
                    return array(
                        'id'    => $id,
                        'type'  => 'user',
                        'title' => $user ? $user->display_name : '#' . $id,
                    );

                default: // post
                    return array(
                        'id'    => $id,
                        'type'  => 'post',
                        'title' => get_the_title( $id ) ?: '#' . $id,
                    );
            }
        }

        /* ============================================================= */
        /*  Public API — static methods                                   */
        /* ============================================================= */

        /**
         * Get connected object IDs for a given object in a relationship.
         *
         * Looks in both directions (from->to and to->from) and returns unique IDs.
         *
         * @param  string $rel_id    Relationship definition ID.
         * @param  int    $from_id   The object ID to find connections for.
         * @param  string $from_type Object type: 'post', 'term', or 'user'.
         * @return int[]             Array of connected object IDs.
         */
        public static function get_connected( $rel_id, $from_id, $from_type = 'post' ) {
            global $wpdb;
            $inst  = self::get_instance();
            $table = $inst->table;

            // Forward: from_id -> to_id
            $forward = $wpdb->get_col( $wpdb->prepare(
                "SELECT to_id FROM {$table} WHERE relationship_id = %s AND from_type = %s AND from_id = %d ORDER BY order_from ASC",
                $rel_id,
                $from_type,
                $from_id
            ) );

            // Reverse: to_id -> from_id (for bidirectional lookups)
            $reverse = $wpdb->get_col( $wpdb->prepare(
                "SELECT from_id FROM {$table} WHERE relationship_id = %s AND to_type = %s AND to_id = %d ORDER BY order_to ASC",
                $rel_id,
                $from_type,
                $from_id
            ) );

            $ids = array_unique( array_merge(
                array_map( 'intval', $forward ),
                array_map( 'intval', $reverse )
            ) );

            return array_values( $ids );
        }

        /**
         * Create a connection between two objects.
         *
         * If the relationship is bidirectional, only one row is stored;
         * `get_connected()` queries both directions.
         *
         * @param  string $rel_id
         * @param  int    $from_id
         * @param  int    $to_id
         * @param  string $from_type
         * @param  string $to_type
         * @return bool   True on success, false if already exists or on failure.
         */
        public static function connect( $rel_id, $from_id, $to_id, $from_type = 'post', $to_type = 'post' ) {
            if ( self::has_connection( $rel_id, $from_id, $to_id ) ) {
                return false;
            }

            global $wpdb;
            $inst  = self::get_instance();
            $table = $inst->table;

            $result = $wpdb->insert(
                $table,
                array(
                    'relationship_id' => $rel_id,
                    'from_type'       => $from_type,
                    'from_id'         => $from_id,
                    'to_type'         => $to_type,
                    'to_id'           => $to_id,
                    'order_from'      => 0,
                    'order_to'        => 0,
                ),
                array( '%s', '%s', '%d', '%s', '%d', '%d', '%d' )
            );

            return false !== $result;
        }

        /**
         * Remove a connection between two objects.
         *
         * Removes rows in both directions (from->to and to->from) to handle
         * bidirectional connections stored in a single row.
         *
         * @param string $rel_id
         * @param int    $from_id
         * @param int    $to_id
         */
        public static function disconnect( $rel_id, $from_id, $to_id ) {
            global $wpdb;
            $inst  = self::get_instance();
            $table = $inst->table;

            // Delete forward direction.
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE relationship_id = %s AND from_id = %d AND to_id = %d",
                $rel_id,
                $from_id,
                $to_id
            ) );

            // Delete reverse direction.
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE relationship_id = %s AND from_id = %d AND to_id = %d",
                $rel_id,
                $to_id,
                $from_id
            ) );
        }

        /**
         * Check whether a connection exists between two objects (in either direction).
         *
         * @param  string $rel_id
         * @param  int    $from_id
         * @param  int    $to_id
         * @return bool
         */
        public static function has_connection( $rel_id, $from_id, $to_id ) {
            global $wpdb;
            $inst  = self::get_instance();
            $table = $inst->table;

            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE relationship_id = %s
                   AND (
                       ( from_id = %d AND to_id = %d )
                       OR
                       ( from_id = %d AND to_id = %d )
                   )",
                $rel_id,
                $from_id,
                $to_id,
                $to_id,
                $from_id
            ) );

            return intval( $count ) > 0;
        }
    }

} // end class_exists check

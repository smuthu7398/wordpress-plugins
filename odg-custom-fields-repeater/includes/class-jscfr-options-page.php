<?php
/**
 * JSCFR Options Pages — Store field data globally (site-wide) instead of per-post.
 * All keys/hooks prefixed with jscfr_ to avoid conflicts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_Options_Page' ) ) {

    final class JSCFR_Options_Page {

        private static $instance = null;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'admin_menu', array( $this, 'register_pages' ), 20 );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
            add_action( 'wp_ajax_jscfr_save_options_page', array( $this, 'ajax_save' ) );
            add_action( 'wp_ajax_jscfr_save_options_pages_config', array( $this, 'ajax_save_config' ) );
        }

        /* ---------------------------------------------------------- */
        /*  Get options pages config                                   */
        /* ---------------------------------------------------------- */
        public static function get_pages() {
            $pages = get_option( JSCFR_OPTIONS_PAGES_KEY, array() );
            return is_array( $pages ) ? $pages : array();
        }

        public static function save_pages( $pages ) {
            update_option( JSCFR_OPTIONS_PAGES_KEY, $pages );
        }

        /* ---------------------------------------------------------- */
        /*  Register admin pages                                       */
        /* ---------------------------------------------------------- */
        public function register_pages() {
            // Options pages manager under CF Builder
            add_submenu_page(
                'jscfr-builder',
                __( 'Options Pages', 'jscfr' ),
                __( 'Options Pages', 'jscfr' ),
                'manage_options',
                'jscfr-options-pages',
                array( $this, 'render_manager' )
            );

            // Register user-created options pages
            $pages = self::get_pages();
            foreach ( $pages as $page ) {
                if ( empty( $page['slug'] ) ) continue;

                $parent = ! empty( $page['parent'] ) ? $page['parent'] : '';

                if ( $parent ) {
                    add_submenu_page(
                        $parent,
                        ! empty( $page['title'] ) ? $page['title'] : __( 'Options', 'jscfr' ),
                        ! empty( $page['menu_title'] ) ? $page['menu_title'] : $page['title'],
                        ! empty( $page['capability'] ) ? $page['capability'] : 'manage_options',
                        'jscfr-opt-' . $page['slug'],
                        array( $this, 'render_options_page' )
                    );
                } else {
                    add_menu_page(
                        ! empty( $page['title'] ) ? $page['title'] : __( 'Options', 'jscfr' ),
                        ! empty( $page['menu_title'] ) ? $page['menu_title'] : $page['title'],
                        ! empty( $page['capability'] ) ? $page['capability'] : 'manage_options',
                        'jscfr-opt-' . $page['slug'],
                        array( $this, 'render_options_page' ),
                        ! empty( $page['icon'] ) ? $page['icon'] : 'dashicons-admin-generic',
                        ! empty( $page['position'] ) ? intval( $page['position'] ) : 82
                    );
                }
            }
        }

        /* ---------------------------------------------------------- */
        /*  Manager page (create/edit options pages)                    */
        /* ---------------------------------------------------------- */
        public function render_manager() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'jscfr' ) );
            }
            $pages = self::get_pages();
            ?>
            <div class="wrap jscfr-builder-wrap">
                <h1><?php esc_html_e( 'Options Pages', 'jscfr' ); ?></h1>
                <p class="description"><?php esc_html_e( 'Create options pages for storing global field data. Assign field groups using location rules with "Options Page" param.', 'jscfr' ); ?></p>
                <hr class="wp-header-end">

                <div id="jscfr-options-pages-manager">
                    <table class="wp-list-table widefat striped jscfr-fg-table" id="jscfr-opt-pages-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Title', 'jscfr' ); ?></th>
                                <th><?php esc_html_e( 'Menu Title', 'jscfr' ); ?></th>
                                <th><?php esc_html_e( 'Slug', 'jscfr' ); ?></th>
                                <th><?php esc_html_e( 'Parent', 'jscfr' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'jscfr' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $pages ) ) : ?>
                                <tr class="jscfr-opt-empty-row"><td colspan="5"><em><?php esc_html_e( 'No options pages yet.', 'jscfr' ); ?></em></td></tr>
                            <?php else : ?>
                                <?php foreach ( $pages as $i => $pg ) : ?>
                                    <tr data-index="<?php echo $i; ?>">
                                        <td><input type="text" class="widefat jscfr-opt-title" value="<?php echo esc_attr( $pg['title'] ); ?>" /></td>
                                        <td><input type="text" class="widefat jscfr-opt-menu-title" value="<?php echo esc_attr( isset( $pg['menu_title'] ) ? $pg['menu_title'] : '' ); ?>" /></td>
                                        <td><code><?php echo esc_html( $pg['slug'] ); ?></code></td>
                                        <td><input type="text" class="widefat jscfr-opt-parent" value="<?php echo esc_attr( isset( $pg['parent'] ) ? $pg['parent'] : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. jscfr-builder', 'jscfr' ); ?>" /></td>
                                        <td><button type="button" class="button jscfr-opt-remove">&times;</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="button" id="jscfr-opt-add-page">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;"></span>
                            <?php esc_html_e( 'Add Options Page', 'jscfr' ); ?>
                        </button>
                        <button type="button" class="button button-primary" id="jscfr-opt-save-pages">
                            <?php esc_html_e( 'Save Pages', 'jscfr' ); ?>
                        </button>
                        <span id="jscfr-opt-status"></span>
                    </p>
                </div>
            </div>
            <script>
            jQuery(function($){
                var $tbl = $('#jscfr-opt-pages-table tbody');

                // Add page
                $('#jscfr-opt-add-page').on('click', function(){
                    $tbl.find('.jscfr-opt-empty-row').remove();
                    var slug = 'opt_' + Math.random().toString(36).substr(2,6);
                    $tbl.append(
                        '<tr>' +
                        '<td><input type="text" class="widefat jscfr-opt-title" value="" placeholder="Page Title" /></td>' +
                        '<td><input type="text" class="widefat jscfr-opt-menu-title" value="" placeholder="Menu Title" /></td>' +
                        '<td><code>' + slug + '</code><input type="hidden" class="jscfr-opt-slug" value="' + slug + '" /></td>' +
                        '<td><input type="text" class="widefat jscfr-opt-parent" value="" placeholder="e.g. jscfr-builder" /></td>' +
                        '<td><button type="button" class="button jscfr-opt-remove">&times;</button></td>' +
                        '</tr>'
                    );
                });

                // Remove page
                $tbl.on('click', '.jscfr-opt-remove', function(){
                    $(this).closest('tr').remove();
                });

                // Save
                $('#jscfr-opt-save-pages').on('click', function(){
                    var pages = [];
                    $tbl.find('tr').each(function(){
                        if ($(this).hasClass('jscfr-opt-empty-row')) return;
                        var slug = $(this).find('.jscfr-opt-slug').val() || $(this).find('code').text().trim();
                        pages.push({
                            title:      $(this).find('.jscfr-opt-title').val(),
                            menu_title: $(this).find('.jscfr-opt-menu-title').val(),
                            slug:       slug,
                            parent:     $(this).find('.jscfr-opt-parent').val(),
                            capability: 'manage_options',
                            icon:       'dashicons-admin-generic',
                            position:   82
                        });
                    });
                    $.post(ajaxurl, {
                        action: 'jscfr_save_options_pages_config',
                        nonce:  '<?php echo wp_create_nonce( JSCFR_BUILDER_NONCE ); ?>',
                        pages:  JSON.stringify(pages)
                    }, function(res){
                        $('#jscfr-opt-status').text(res.success ? 'Saved! Refresh to see menu changes.' : 'Error.');
                        setTimeout(function(){ $('#jscfr-opt-status').text(''); }, 4000);
                    });
                });
            });
            </script>
            <?php
        }

        /* ---------------------------------------------------------- */
        /*  Render an options page (shows field groups)                */
        /* ---------------------------------------------------------- */
        public function render_options_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'jscfr' ) );
            }

            $screen_id = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
            $slug = str_replace( 'jscfr-opt-', '', $screen_id );

            // Find matching options page config
            $page_config = null;
            foreach ( self::get_pages() as $pg ) {
                if ( $pg['slug'] === $slug ) {
                    $page_config = $pg;
                    break;
                }
            }

            $title = $page_config ? $page_config['title'] : __( 'Options', 'jscfr' );

            // Get field groups that target this options page
            $field_groups = $this->get_field_groups_for_options( $slug );
            $saved = get_option( JSCFR_OPTIONS_DATA_KEY, array() );
            if ( ! is_array( $saved ) ) {
                $saved = array();
            }

            ?>
            <div class="wrap jscfr-builder-wrap">
                <h1><?php echo esc_html( $title ); ?></h1>

                <form id="jscfr-options-form" method="post">
                    <?php wp_nonce_field( 'jscfr_save_options', '_jscfr_options_nonce' ); ?>
                    <input type="hidden" name="jscfr_options_slug" value="<?php echo esc_attr( $slug ); ?>" />

                    <?php if ( empty( $field_groups ) ) : ?>
                        <p class="jscfr-empty"><?php esc_html_e( 'No field groups are assigned to this options page. Edit a field group and add a location rule for this options page.', 'jscfr' ); ?></p>
                    <?php else : ?>
                        <?php foreach ( $field_groups as $fg ) :
                            $fg_data = isset( $saved[ $fg['id'] ] ) ? $saved[ $fg['id'] ] : array();
                            $tabs = isset( $fg['tabs'] ) ? $fg['tabs'] : array();
                        ?>
                            <div class="jscfr-meta-wrap" data-fg="<?php echo esc_attr( $fg['id'] ); ?>">
                                <h2><?php echo esc_html( $fg['title'] ); ?></h2>

                                <?php if ( ! empty( $fg['settings']['description'] ) ) : ?>
                                    <p class="jscfr-fg-desc"><?php echo esc_html( $fg['settings']['description'] ); ?></p>
                                <?php endif; ?>

                                <?php
                                $metabox = JSCFR_Metabox::get_instance();
                                // We render via the same metabox patterns but with options data
                                if ( ! empty( $tabs ) ) :
                                    foreach ( $tabs as $tab ) :
                                        if ( empty( $tab['groups'] ) ) continue;
                                        foreach ( $tab['groups'] as $group ) :
                                            $gid  = $group['id'];
                                            $rows = isset( $fg_data[ $tab['id'] ][ $gid ] ) ? $fg_data[ $tab['id'] ][ $gid ] : array();
                                            ?>
                                            <div class="jscfr-group-block" data-fg="<?php echo esc_attr( $fg['id'] ); ?>" data-tab="<?php echo esc_attr( $tab['id'] ); ?>" data-group="<?php echo esc_attr( $gid ); ?>">
                                                <div class="jscfr-group-header"><h3><?php echo esc_html( $group['label'] ); ?></h3>
                                                <span class="jscfr-group-badge"><?php echo count( $rows ); ?> <?php esc_html_e( 'entries', 'jscfr' ); ?></span></div>
                                                <div class="jscfr-clones">
                                                    <?php if ( ! empty( $rows ) ) :
                                                        foreach ( $rows as $idx => $row_data ) :
                                                            $this->render_options_clone_row( $fg['id'], $tab['id'], $gid, $group['fields'], $idx, $row_data );
                                                        endforeach;
                                                    endif; ?>
                                                </div>
                                                <button type="button" class="button button-primary jscfr-add-clone"
                                                    data-fg="<?php echo esc_attr( $fg['id'] ); ?>"
                                                    data-tab="<?php echo esc_attr( $tab['id'] ); ?>"
                                                    data-group="<?php echo esc_attr( $gid ); ?>">
                                                    <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;"></span>
                                                    <?php esc_html_e( 'Add Entry', 'jscfr' ); ?>
                                                </button>
                                                <div class="jscfr-clone-template" id="jscfr-clonetpl-<?php echo esc_attr( $fg['id'] ); ?>-<?php echo esc_attr( $tab['id'] ); ?>-<?php echo esc_attr( $gid ); ?>" style="display:none;">
                                                    <?php $this->render_options_clone_row( $fg['id'], $tab['id'], $gid, $group['fields'], '__IDX__', array() ); ?>
                                                </div>
                                            </div>
                                        <?php endforeach;
                                    endforeach;
                                endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <p class="submit">
                        <button type="button" class="button button-primary button-large" id="jscfr-save-options-btn">
                            <span class="dashicons dashicons-saved" style="vertical-align:middle;margin-right:4px;"></span>
                            <?php esc_html_e( 'Save Options', 'jscfr' ); ?>
                        </button>
                        <span id="jscfr-options-status"></span>
                    </p>
                </form>
            </div>
            <script>
            jQuery(function($){
                $('#jscfr-save-options-btn').on('click', function(){
                    var $form = $('#jscfr-options-form');
                    var formData = $form.serialize();
                    formData += '&action=jscfr_save_options_page';
                    $.post(ajaxurl, formData, function(res){
                        var $st = $('#jscfr-options-status');
                        $st.text(res.success ? 'Saved!' : 'Error saving.').css('color', res.success ? 'green' : 'red');
                        setTimeout(function(){ $st.text(''); }, 3000);
                    });
                });
            });
            </script>
            <?php
        }

        /**
         * Render a clone row for options page (reuses metabox rendering pattern).
         */
        private function render_options_clone_row( $fg_id, $tab_id, $group_id, $fields, $idx, $data ) {
            $ef = esc_attr( $fg_id );
            $et = esc_attr( $tab_id );
            $eg = esc_attr( $group_id );
            $ei = esc_attr( $idx );

            echo '<div class="jscfr-clone-row" data-index="' . $ei . '">';
            echo '<div class="jscfr-clone-header">';
            echo '<span class="jscfr-drag dashicons dashicons-move"></span>';
            echo '<span class="jscfr-clone-num"></span>';
            echo '<button type="button" class="jscfr-clone-toggle"><span class="dashicons dashicons-arrow-down-alt2"></span></button>';
            echo '<button type="button" class="jscfr-clone-remove"><span class="dashicons dashicons-trash"></span></button>';
            echo '</div>';
            echo '<div class="jscfr-clone-fields">';

            foreach ( $fields as $field ) {
                $fid   = $field['id'];
                $value = isset( $data[ $fid ] ) ? $data[ $fid ] : ( isset( $field['default_value'] ) ? $field['default_value'] : '' );
                $name  = 'jscfr_data[' . $ef . '][' . $et . '][' . $eg . '][' . $ei . '][' . esc_attr( $fid ) . ']';
                $domid = 'jscfr_' . $ef . '_' . $et . '_' . $eg . '_' . $ei . '_' . esc_attr( $fid );

                echo '<div class="jscfr-fld jscfr-fld--' . esc_attr( $field['type'] ) . '">';
                if ( 'message' !== $field['type'] ) {
                    echo '<label for="' . $domid . '">' . esc_html( $field['label'] ) . '</label>';
                }
                // Simple rendering for options pages
                switch ( $field['type'] ) {
                    case 'text': case 'email': case 'url': case 'password': case 'date': case 'datetime': case 'time':
                        $t = 'datetime' === $field['type'] ? 'datetime-local' : $field['type'];
                        echo '<input type="' . esc_attr( $t ) . '" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" class="widefat" />';
                        break;
                    case 'number':
                        echo '<input type="number" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" class="widefat" />';
                        break;
                    case 'textarea':
                        echo '<textarea id="' . $domid . '" name="' . $name . '" class="widefat" rows="4">' . esc_textarea( $value ) . '</textarea>';
                        break;
                    case 'wysiwyg':
                        echo '<textarea id="' . $domid . '" name="' . $name . '" class="jscfr-wysiwyg widefat" rows="5">' . esc_textarea( $value ) . '</textarea>';
                        break;
                    case 'color':
                        echo '<input type="text" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-color-picker" />';
                        break;
                    case 'true_false':
                        echo '<input type="hidden" name="' . $name . '" value="0" /><label class="jscfr-toggle"><input type="checkbox" name="' . $name . '" value="1" ' . checked( $value, '1', false ) . ' /><span class="jscfr-toggle-slider"></span></label>';
                        break;
                    case 'checkbox':
                        echo '<input type="hidden" name="' . $name . '" value="0" /><input type="checkbox" name="' . $name . '" value="1" ' . checked( $value, '1', false ) . ' />';
                        break;
                    case 'message':
                        echo '<div class="jscfr-message">' . wp_kses_post( isset( $field['message'] ) ? $field['message'] : '' ) . '</div>';
                        break;
                    default:
                        echo '<input type="text" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( is_array( $value ) ? '' : $value ) . '" class="widefat" />';
                }
                echo '</div>';
            }

            echo '</div></div>';
        }

        /* ---------------------------------------------------------- */
        /*  Get field groups targeting an options page                  */
        /* ---------------------------------------------------------- */
        private function get_field_groups_for_options( $slug ) {
            $matched = array();
            foreach ( JSCFR_Plugin::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) {
                    continue;
                }
                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                foreach ( $rules as $or_group ) {
                    foreach ( $or_group as $rule ) {
                        if ( 'options_page' === ( isset( $rule['param'] ) ? $rule['param'] : '' ) ) {
                            if ( 'is_equal_to' === $rule['operator'] && $rule['value'] === $slug ) {
                                $matched[] = $fg;
                                break 2;
                            }
                        }
                    }
                }
            }
            return $matched;
        }

        /* ---------------------------------------------------------- */
        /*  Enqueue                                                    */
        /* ---------------------------------------------------------- */
        public function enqueue( $hook ) {
            // Check if this is one of our options pages
            if ( 0 !== strpos( $hook, 'toplevel_page_jscfr-opt-' ) && 0 !== strpos( $hook, 'admin_page_jscfr-opt-' ) && false === strpos( $hook, 'jscfr-opt-' ) ) {
                return;
            }

            // Collect field groups for this options page slug
            $slug = isset( $_GET['page'] ) ? sanitize_key( str_replace( 'jscfr-opt-', '', $_GET['page'] ) ) : '';
            $fgs  = $slug ? $this->get_field_groups_for_options( $slug ) : array();

            // Fallback: if we can't resolve a slug, enqueue for ALL option-page-targeted groups so assets are available
            if ( empty( $fgs ) ) {
                foreach ( JSCFR_Plugin::get_config() as $fg ) {
                    if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) continue;
                    $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                    foreach ( $rules as $or_group ) {
                        foreach ( $or_group as $rule ) {
                            if ( 'options_page' === ( $rule['param'] ?? '' ) ) {
                                $fgs[] = $fg;
                                break 2;
                            }
                        }
                    }
                }
            }

            JSCFR_Metabox::enqueue_shared_assets( $fgs );

            $cond_map      = array();
            $field_configs = array();
            foreach ( $fgs as $fg ) {
                if ( empty( $fg['tabs'] ) ) continue;
                foreach ( $fg['tabs'] as $tab ) {
                    if ( empty( $tab['groups'] ) ) continue;
                    foreach ( $tab['groups'] as $group ) {
                        if ( empty( $group['fields'] ) ) continue;
                        foreach ( $group['fields'] as $field ) {
                            $field_configs[ $field['id'] ] = $field;
                            if ( ! empty( $field['conditional_logic'] ) ) {
                                $cond_map[ $field['id'] ] = $field['conditional_logic'];
                            }
                        }
                    }
                }
            }

            wp_localize_script( 'jscfr-metabox-js', 'jscfr_meta', array(
                'ajax_url'        => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( JSCFR_BUILDER_NONCE ),
                'confirm_remove'  => __( 'Remove this entry?', 'jscfr' ),
                'select_file'     => __( 'Select or Upload File', 'jscfr' ),
                'select_image'    => __( 'Select or Upload Image', 'jscfr' ),
                'use_file'        => __( 'Use this file', 'jscfr' ),
                'use_image'       => __( 'Use this image', 'jscfr' ),
                'no_file'         => __( 'No file selected', 'jscfr' ),
                'no_image'        => __( 'No image selected', 'jscfr' ),
                'required_error'  => __( 'This field is required.', 'jscfr' ),
                'add_to_gallery'  => __( 'Add to Gallery', 'jscfr' ),
                'search_posts'    => __( 'Search posts...', 'jscfr' ),
                'search_users'    => __( 'Search users...', 'jscfr' ),
                'no_results'      => __( 'No results found.', 'jscfr' ),
                'cond_map'        => $cond_map,
                'field_configs'   => $field_configs,
                'max_entries_msg' => __( 'Maximum number of entries reached.', 'jscfr' ),
                'min_entries_msg' => __( 'Minimum entries required: ', 'jscfr' ),
            ) );
        }

        /* ---------------------------------------------------------- */
        /*  AJAX: Save options page data                               */
        /* ---------------------------------------------------------- */
        public function ajax_save() {
            check_ajax_referer( 'jscfr_save_options', '_jscfr_options_nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $slug = isset( $_POST['jscfr_options_slug'] ) ? sanitize_key( $_POST['jscfr_options_slug'] ) : '';
            $raw  = isset( $_POST['jscfr_data'] ) ? wp_unslash( $_POST['jscfr_data'] ) : array();

            $existing = get_option( JSCFR_OPTIONS_DATA_KEY, array() );
            if ( ! is_array( $existing ) ) {
                $existing = array();
            }

            // Merge submitted data with field-type-aware sanitization
            if ( is_array( $raw ) ) {
                $fgs_by_id = array();
                foreach ( JSCFR_Plugin::get_config() as $fg ) {
                    if ( ! empty( $fg['id'] ) ) {
                        $fgs_by_id[ $fg['id'] ] = $fg;
                    }
                }
                $metabox = JSCFR_Metabox::get_instance();
                foreach ( $raw as $fg_id => $fg_data ) {
                    $fg_id_clean = sanitize_key( $fg_id );
                    if ( ! isset( $fgs_by_id[ $fg_id_clean ] ) || ! is_array( $fg_data ) ) continue;
                    $existing[ $fg_id_clean ] = $this->sanitize_options_fg_data( $fg_data, $fgs_by_id[ $fg_id_clean ], $metabox );
                }
            }

            update_option( JSCFR_OPTIONS_DATA_KEY, $existing );

            // v5: Also write individual option keys for each field
            $index = JSCFR_Plugin::build_field_index();
            $flat  = JSCFR_Plugin::flatten_blob( $existing );
            foreach ( $flat as $name => $value ) {
                JSCFR_Plugin::set_field_value( $name, $value, 'options', 'options' );
            }

            wp_send_json_success();
        }

        /**
         * Sanitize a field group's submitted data using field-type-aware logic.
         * Walks the configured tabs/groups/fields tree and delegates leaf values
         * to JSCFR_Metabox::sanitize_field_value() so WYSIWYG, URL, email, number,
         * and other typed fields are preserved correctly.
         */
        private function sanitize_options_fg_data( $data, $fg, $metabox ) {
            $clean = array();
            if ( empty( $fg['tabs'] ) || ! is_array( $data ) ) {
                return $clean;
            }
            foreach ( $fg['tabs'] as $tab ) {
                $tab_id = isset( $tab['id'] ) ? $tab['id'] : '';
                if ( ! $tab_id || empty( $tab['groups'] ) || empty( $data[ $tab_id ] ) || ! is_array( $data[ $tab_id ] ) ) continue;
                foreach ( $tab['groups'] as $group ) {
                    $gid = isset( $group['id'] ) ? $group['id'] : '';
                    if ( ! $gid || empty( $group['fields'] ) || empty( $data[ $tab_id ][ $gid ] ) || ! is_array( $data[ $tab_id ][ $gid ] ) ) continue;
                    $rows = $data[ $tab_id ][ $gid ];
                    unset( $rows['__IDX__'] );
                    foreach ( $rows as $idx => $row ) {
                        if ( ! is_array( $row ) ) continue;
                        $idx_key = sanitize_key( $idx );
                        foreach ( $group['fields'] as $field ) {
                            $fid = isset( $field['id'] ) ? $field['id'] : '';
                            if ( ! $fid || ! array_key_exists( $fid, $row ) ) continue;
                            $clean[ $tab_id ][ $gid ][ $idx_key ][ $fid ] = $metabox->sanitize_field_value( $row[ $fid ], $field );
                        }
                    }
                }
            }
            return $clean;
        }

        /* ---------------------------------------------------------- */
        /*  AJAX: Save options pages config                            */
        /* ---------------------------------------------------------- */
        public function ajax_save_config() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $raw = isset( $_POST['pages'] ) ? wp_unslash( $_POST['pages'] ) : '[]';
            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) {
                wp_send_json_error( 'Invalid data' );
            }

            $clean = array();
            foreach ( $data as $pg ) {
                if ( ! is_array( $pg ) || empty( $pg['slug'] ) ) continue;
                $clean[] = array(
                    'title'      => sanitize_text_field( isset( $pg['title'] ) ? $pg['title'] : '' ),
                    'menu_title' => sanitize_text_field( isset( $pg['menu_title'] ) ? $pg['menu_title'] : '' ),
                    'slug'       => sanitize_key( $pg['slug'] ),
                    'parent'     => sanitize_text_field( isset( $pg['parent'] ) ? $pg['parent'] : '' ),
                    'capability' => sanitize_key( isset( $pg['capability'] ) ? $pg['capability'] : 'manage_options' ),
                    'icon'       => sanitize_text_field( isset( $pg['icon'] ) ? $pg['icon'] : 'dashicons-admin-generic' ),
                    'position'   => isset( $pg['position'] ) ? intval( $pg['position'] ) : 82,
                );
            }

            self::save_pages( $clean );
            wp_send_json_success();
        }
    }
}

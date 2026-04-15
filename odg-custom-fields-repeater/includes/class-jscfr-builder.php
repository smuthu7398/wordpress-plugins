<?php
/**
 * JSCFR Builder — Field Group list page + edit page with Location Rules + Settings.
 * v4: All field types, expandable field settings, import/export, duplicate, field names.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_Builder' ) ) {

    final class JSCFR_Builder {

        private static $instance = null;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'admin_menu', array( $this, 'add_menu' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
            add_action( 'wp_ajax_jscfr_save_field_group', array( $this, 'ajax_save_field_group' ) );
            add_action( 'wp_ajax_jscfr_delete_field_group', array( $this, 'ajax_delete_field_group' ) );
            add_action( 'wp_ajax_jscfr_duplicate_field_group', array( $this, 'ajax_duplicate_field_group' ) );
            add_action( 'wp_ajax_jscfr_toggle_field_group', array( $this, 'ajax_toggle_field_group' ) );
            add_action( 'wp_ajax_jscfr_export_field_groups', array( $this, 'ajax_export_field_groups' ) );
            add_action( 'wp_ajax_jscfr_import_field_groups', array( $this, 'ajax_import_field_groups' ) );
            add_action( 'wp_ajax_jscfr_search_posts', array( $this, 'ajax_search_posts' ) );
            add_action( 'wp_ajax_jscfr_search_users', array( $this, 'ajax_search_users' ) );
            add_action( 'wp_ajax_jscfr_search_terms', array( $this, 'ajax_search_terms' ) );
        }

        public function add_menu() {
            add_menu_page(
                __( 'Field Groups', 'jscfr' ),
                __( 'CF Builder', 'jscfr' ),
                'manage_options',
                'jscfr-builder',
                array( $this, 'render_page' ),
                'dashicons-editor-table',
                81
            );
            add_submenu_page(
                'jscfr-builder',
                __( 'Field Groups', 'jscfr' ),
                __( 'Field Groups', 'jscfr' ),
                'manage_options',
                'jscfr-builder'
            );
            add_submenu_page(
                'jscfr-builder',
                __( 'Import / Export', 'jscfr' ),
                __( 'Import / Export', 'jscfr' ),
                'manage_options',
                'jscfr-import-export',
                array( $this, 'render_import_export_page' )
            );
        }

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
        /*  LIST PAGE                                                     */
        /* ============================================================= */
        private function render_list_page() {
            $config   = JSCFR_Plugin::get_config();
            $edit_url = admin_url( 'admin.php?page=jscfr-builder&action=edit' );
            $ie_url   = admin_url( 'admin.php?page=jscfr-import-export' );

            // Counts for filter tabs
            $total_count    = count( $config );
            $active_count   = 0;
            $inactive_count = 0;
            foreach ( $config as $fg ) {
                if ( ! isset( $fg['settings']['active'] ) || $fg['settings']['active'] ) {
                    $active_count++;
                } else {
                    $inactive_count++;
                }
            }

            // Search filter
            $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
            $filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';

            // Count displayed items
            $display_count = 0;
            foreach ( $config as $fg ) {
                $fg_active = isset( $fg['settings']['active'] ) ? $fg['settings']['active'] : true;
                if ( 'active' === $filter && ! $fg_active ) continue;
                $fg_title = ! empty( $fg['title'] ) ? $fg['title'] : $fg['id'];
                if ( $search && false === stripos( $fg_title, $search ) && false === stripos( $fg['id'], $search ) ) continue;
                $display_count++;
            }
            ?>
            <div class="wrap jscfr-builder-wrap jscfr-list-wrap">

                <h1 class="wp-heading-inline"><?php esc_html_e( 'Field Groups', 'jscfr' ); ?></h1>
                <a href="<?php echo esc_url( $edit_url . '&fg_id=new' ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'jscfr' ); ?></a>
                <a href="<?php echo esc_url( $ie_url ); ?>" class="page-title-action"><?php esc_html_e( 'Import', 'jscfr' ); ?></a>
                <hr class="wp-header-end">

                <?php if ( empty( $config ) ) : ?>
                    <div class="jscfr-empty-state">
                        <span class="dashicons dashicons-editor-table jscfr-empty-icon"></span>
                        <p><?php esc_html_e( 'No field groups yet.', 'jscfr' ); ?></p>
                        <a href="<?php echo esc_url( $edit_url . '&fg_id=new' ); ?>" class="page-title-action" style="float:none;display:inline-block;">
                            <?php esc_html_e( 'Create Your First Field Group', 'jscfr' ); ?>
                        </a>
                    </div>
                <?php else : ?>

                    <!-- Filter tabs -->
                    <ul class="subsubsub">
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=jscfr-builder' ) ); ?>" class="<?php echo 'all' === $filter ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'jscfr' ); ?> <span class="count">(<?php echo intval( $total_count ); ?>)</span></a> |</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=jscfr-builder&status=active' ) ); ?>" class="<?php echo 'active' === $filter ? 'current' : ''; ?>"><?php esc_html_e( 'Published', 'jscfr' ); ?> <span class="count">(<?php echo intval( $active_count ); ?>)</span></a></li>
                    </ul>

                    <!-- Search box -->
                    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                        <input type="hidden" name="page" value="jscfr-builder" />
                        <?php if ( 'all' !== $filter ) : ?>
                            <input type="hidden" name="status" value="<?php echo esc_attr( $filter ); ?>" />
                        <?php endif; ?>
                        <p class="search-box">
                            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" />
                            <input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'jscfr' ); ?>" />
                        </p>
                    </form>

                    <!-- Bulk actions top -->
                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <select id="jscfr-bulk-action-top">
                                <option value="-1"><?php esc_html_e( 'Bulk actions', 'jscfr' ); ?></option>
                                <option value="delete"><?php esc_html_e( 'Delete', 'jscfr' ); ?></option>
                                <option value="export"><?php esc_html_e( 'Export', 'jscfr' ); ?></option>
                            </select>
                            <button type="button" class="button action jscfr-bulk-apply" data-select="#jscfr-bulk-action-top"><?php esc_html_e( 'Apply', 'jscfr' ); ?></button>
                        </div>
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php echo intval( $display_count ); ?> <?php echo _n( 'item', 'items', $display_count, 'jscfr' ); ?></span>
                        </div>
                        <br class="clear" />
                    </div>

                    <table class="wp-list-table widefat fixed striped jscfr-fg-table">
                        <thead>
                            <tr>
                                <td class="manage-column column-cb check-column"><input type="checkbox" id="jscfr-cb-all" /></td>
                                <th class="manage-column jscfr-col-status"><?php esc_html_e( 'Status', 'jscfr' ); ?></th>
                                <th class="manage-column column-primary jscfr-col-title sortable"><span><?php esc_html_e( 'Title', 'jscfr' ); ?></span></th>
                                <th class="manage-column jscfr-col-showfor"><?php esc_html_e( 'Show For', 'jscfr' ); ?></th>
                                <th class="manage-column jscfr-col-location"><?php esc_html_e( 'Location', 'jscfr' ); ?></th>
                                <th class="manage-column jscfr-col-shortcode"><?php esc_html_e( 'Shortcode', 'jscfr' ); ?> <span class="dashicons dashicons-info" style="font-size:14px;width:14px;height:14px;color:#999;cursor:help;" title="<?php esc_attr_e( 'Use this shortcode to display the field group as a frontend form.', 'jscfr' ); ?>"></span></th>
                                <th class="manage-column jscfr-col-date sortable"><span><?php esc_html_e( 'Date', 'jscfr' ); ?></span></th>
                            </tr>
                        </thead>
                        <tbody id="jscfr-fg-list">
                        <?php foreach ( $config as $idx => $fg ) :
                            $fg_id    = $fg['id'];
                            $title    = ! empty( $fg['title'] ) ? $fg['title'] : __( '(no title)', 'jscfr' );
                            $active   = isset( $fg['settings']['active'] ) ? $fg['settings']['active'] : true;
                            $show_for = $this->get_show_for( $fg );
                            $location = $this->get_location_label( $fg );

                            // Filter by status
                            if ( 'active' === $filter && ! $active ) continue;

                            // Filter by search
                            if ( $search && false === stripos( $title, $search ) && false === stripos( $fg_id, $search ) ) continue;
                        ?>
                            <tr data-fg-id="<?php echo esc_attr( $fg_id ); ?>" class="<?php echo $active ? '' : 'jscfr-row-inactive'; ?>">
                                <th scope="row" class="check-column">
                                    <input type="checkbox" class="jscfr-export-cb" value="<?php echo esc_attr( $fg_id ); ?>" />
                                </th>
                                <td class="jscfr-col-status">
                                    <label class="jscfr-toggle-switch" title="<?php echo $active ? esc_attr__( 'Active', 'jscfr' ) : esc_attr__( 'Inactive', 'jscfr' ); ?>">
                                        <input type="checkbox" class="jscfr-action-toggle-switch" data-id="<?php echo esc_attr( $fg_id ); ?>" <?php checked( $active ); ?> />
                                        <span class="jscfr-toggle-slider-sm"></span>
                                    </label>
                                </td>
                                <td class="jscfr-col-title column-primary">
                                    <strong><a href="<?php echo esc_url( $edit_url . '&fg_id=' . $fg_id ); ?>" class="row-title"><?php echo esc_html( $title ); ?></a></strong>
                                    <div class="row-actions">
                                        <span class="edit"><a href="<?php echo esc_url( $edit_url . '&fg_id=' . $fg_id ); ?>"><?php esc_html_e( 'Edit', 'jscfr' ); ?></a></span>
                                        <span class="sep"> | </span>
                                        <span class="duplicate"><a href="#" class="jscfr-action-duplicate" data-id="<?php echo esc_attr( $fg_id ); ?>"><?php esc_html_e( 'Duplicate', 'jscfr' ); ?></a></span>
                                        <span class="sep"> | </span>
                                        <span class="trash"><a href="#" class="jscfr-action-delete" data-id="<?php echo esc_attr( $fg_id ); ?>"><?php esc_html_e( 'Trash', 'jscfr' ); ?></a></span>
                                    </div>
                                </td>
                                <td class="jscfr-col-showfor"><?php echo esc_html( $show_for ); ?></td>
                                <td class="jscfr-col-location"><?php echo esc_html( $location ); ?></td>
                                <td class="jscfr-col-shortcode">
                                    <input type="text" class="jscfr-shortcode-input" value="[jscfr_form id='<?php echo esc_attr( $fg_id ); ?>']" readonly onclick="this.select();" />
                                </td>
                                <td class="jscfr-col-date">
                                    <?php echo $active ? esc_html__( 'Published', 'jscfr' ) : esc_html__( 'Draft', 'jscfr' ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="manage-column column-cb check-column"><input type="checkbox" id="jscfr-cb-all-bottom" /></td>
                                <th class="manage-column jscfr-col-status"><?php esc_html_e( 'Status', 'jscfr' ); ?></th>
                                <th class="manage-column column-primary jscfr-col-title"><?php esc_html_e( 'Title', 'jscfr' ); ?></th>
                                <th class="manage-column jscfr-col-showfor"><?php esc_html_e( 'Show For', 'jscfr' ); ?></th>
                                <th class="manage-column jscfr-col-location"><?php esc_html_e( 'Location', 'jscfr' ); ?></th>
                                <th class="manage-column jscfr-col-shortcode"><?php esc_html_e( 'Shortcode', 'jscfr' ); ?></th>
                                <th class="manage-column jscfr-col-date"><?php esc_html_e( 'Date', 'jscfr' ); ?></th>
                            </tr>
                        </tfoot>
                    </table>

                    <!-- Bulk actions bottom -->
                    <div class="tablenav bottom">
                        <div class="alignleft actions bulkactions">
                            <select id="jscfr-bulk-action-bottom">
                                <option value="-1"><?php esc_html_e( 'Bulk actions', 'jscfr' ); ?></option>
                                <option value="delete"><?php esc_html_e( 'Delete', 'jscfr' ); ?></option>
                                <option value="export"><?php esc_html_e( 'Export', 'jscfr' ); ?></option>
                            </select>
                            <button type="button" class="button action jscfr-bulk-apply" data-select="#jscfr-bulk-action-bottom"><?php esc_html_e( 'Apply', 'jscfr' ); ?></button>
                        </div>
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php echo intval( $display_count ); ?> <?php echo _n( 'item', 'items', $display_count, 'jscfr' ); ?></span>
                        </div>
                        <br class="clear" />
                    </div>

                <?php endif; ?>
            </div>
            <?php
        }

        private function get_location_summary( $fg ) {
            $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
            if ( empty( $rules ) ) {
                return '<span class="jscfr-loc-item"><span class="dashicons dashicons-admin-site-alt3 jscfr-loc-icon"></span>' . esc_html__( 'Everywhere', 'jscfr' ) . '</span>';
            }

            // Collect unique, human-readable location targets
            $targets = array();
            $icons   = array();
            foreach ( $rules as $or_group ) {
                foreach ( $or_group as $rule ) {
                    $p = isset( $rule['param'] ) ? $rule['param'] : '';
                    $v = isset( $rule['value'] ) ? $rule['value'] : '';
                    $o = isset( $rule['operator'] ) ? $rule['operator'] : 'is_equal_to';
                    if ( 'is_not_equal_to' === $o ) continue; // Skip exclusions for summary

                    $label = $v;
                    $icon  = 'dashicons-admin-post';

                    switch ( $p ) {
                        case 'post_type':
                            $pt_obj = get_post_type_object( $v );
                            $label  = $pt_obj ? $pt_obj->labels->name : ucfirst( $v );
                            $icon   = 'dashicons-admin-post';
                            break;
                        case 'page_template':
                            $label = 'default' === $v ? __( 'Default Template', 'jscfr' ) : $v;
                            $icon  = 'dashicons-admin-page';
                            break;
                        case 'post_category':
                            $cat = get_category_by_slug( $v );
                            $label = $cat ? $cat->name : $v;
                            $icon  = 'dashicons-category';
                            break;
                        case 'post_taxonomy':
                            $parts = explode( ':', $v, 2 );
                            if ( count( $parts ) === 2 ) {
                                $tax_obj  = get_taxonomy( $parts[0] );
                                $term_obj = get_term_by( 'slug', $parts[1], $parts[0] );
                                $label    = ( $tax_obj ? $tax_obj->labels->singular_name : $parts[0] ) . ': ' . ( $term_obj ? $term_obj->name : $parts[1] );
                            }
                            $icon = 'dashicons-tag';
                            break;
                        case 'user_role':
                            $roles = wp_roles()->roles;
                            $label = isset( $roles[ $v ] ) ? $roles[ $v ]['name'] : ucfirst( $v );
                            $icon  = 'dashicons-admin-users';
                            break;
                        case 'post':
                            $post_obj = get_post( intval( $v ) );
                            $label    = $post_obj ? $post_obj->post_title . ' (ID: ' . $v . ')' : 'Post #' . $v;
                            $icon     = 'dashicons-edit';
                            break;
                        case 'options_page':
                            $opt_pages = JSCFR_Options_Page::get_pages();
                            foreach ( $opt_pages as $pg ) {
                                if ( $pg['slug'] === $v ) {
                                    $label = $pg['title'];
                                    break;
                                }
                            }
                            $icon = 'dashicons-admin-generic';
                            break;
                        default:
                            $icon = 'dashicons-admin-generic';
                    }

                    if ( ! in_array( $label, $targets, true ) ) {
                        $targets[] = $label;
                        $icons[]   = $icon;
                    }
                }
            }

            if ( empty( $targets ) ) {
                return '<span class="jscfr-loc-item"><span class="dashicons dashicons-admin-site-alt3 jscfr-loc-icon"></span>' . esc_html__( 'Everywhere', 'jscfr' ) . '</span>';
            }

            $html = '';
            foreach ( $targets as $i => $t ) {
                if ( $i > 0 ) $html .= ', ';
                $html .= '<span class="jscfr-loc-item"><span class="dashicons ' . esc_attr( $icons[ $i ] ) . ' jscfr-loc-icon"></span>' . esc_html( $t ) . '</span>';
            }
            return $html;
        }

        /**
         * "Show For" column — e.g. "Posts", "Pages", "Users".
         */
        private function get_show_for( $fg ) {
            $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
            if ( empty( $rules ) ) {
                return __( 'All', 'jscfr' );
            }
            $targets = array();
            foreach ( $rules as $or_group ) {
                foreach ( $or_group as $rule ) {
                    $p = isset( $rule['param'] ) ? $rule['param'] : '';
                    switch ( $p ) {
                        case 'post_type':
                            $pt_obj    = get_post_type_object( isset( $rule['value'] ) ? $rule['value'] : '' );
                            $targets[] = $pt_obj ? $pt_obj->labels->name : __( 'Posts', 'jscfr' );
                            break;
                        case 'user_role':
                            $targets[] = __( 'Users', 'jscfr' );
                            break;
                        case 'options_page':
                            $targets[] = __( 'Settings Pages', 'jscfr' );
                            break;
                        case 'post_taxonomy':
                        case 'post_category':
                            $targets[] = __( 'Posts', 'jscfr' );
                            break;
                        default:
                            $targets[] = __( 'Posts', 'jscfr' );
                    }
                }
            }
            $targets = array_unique( $targets );
            return implode( ', ', $targets );
        }

        /**
         * "Location" column — simplified plain text (e.g. "Post", "Page").
         */
        private function get_location_label( $fg ) {
            $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
            if ( empty( $rules ) ) {
                return __( 'Everywhere', 'jscfr' );
            }
            $labels = array();
            foreach ( $rules as $or_group ) {
                foreach ( $or_group as $rule ) {
                    $p = isset( $rule['param'] ) ? $rule['param'] : '';
                    $v = isset( $rule['value'] ) ? $rule['value'] : '';
                    switch ( $p ) {
                        case 'post_type':
                            $pt_obj   = get_post_type_object( $v );
                            $labels[] = $pt_obj ? $pt_obj->labels->singular_name : ucfirst( $v );
                            break;
                        case 'user_role':
                            $roles    = wp_roles()->roles;
                            $labels[] = isset( $roles[ $v ] ) ? $roles[ $v ]['name'] : ucfirst( $v );
                            break;
                        case 'options_page':
                            $labels[] = ucfirst( str_replace( '-', ' ', $v ) );
                            break;
                        default:
                            $labels[] = ucfirst( str_replace( '_', ' ', $p ) );
                    }
                }
            }
            $labels = array_unique( $labels );
            return implode( ', ', $labels );
        }

        /* ============================================================= */
        /*  EDIT PAGE                                                     */
        /* ============================================================= */
        private function render_edit_page() {
            $fg_id  = isset( $_GET['fg_id'] ) ? sanitize_key( $_GET['fg_id'] ) : 'new';
            $is_new = ( 'new' === $fg_id );
            $fg     = null;

            if ( ! $is_new ) {
                $fg = JSCFR_Plugin::get_field_group( $fg_id );
            }
            if ( ! $fg ) {
                $fg = array(
                    'id'             => 'fg_' . wp_generate_password( 8, false ),
                    'title'          => '',
                    'tabs'           => array(),
                    'location_rules' => array(
                        array(
                            array( 'param' => 'post_type', 'operator' => 'is_equal_to', 'value' => 'post' ),
                        ),
                    ),
                    'settings' => JSCFR_Plugin::default_settings(),
                );
            }

            $list_url    = admin_url( 'admin.php?page=jscfr-builder' );
            $new_url     = admin_url( 'admin.php?page=jscfr-builder&action=edit&fg_id=new' );
            ?>
            <div class="wrap jscfr-builder-wrap jscfr-edit-wrap">

                <!-- ===== TOP BAR ===== -->
                <div class="jscfr-topbar">
                    <div class="jscfr-topbar-left">
                        <a href="<?php echo esc_url( $new_url ); ?>" class="jscfr-topbar-icon<?php echo $is_new ? ' jscfr-topbar-icon--active' : ''; ?>" title="<?php esc_attr_e( 'Add New', 'jscfr' ); ?>"><span class="dashicons dashicons-plus-alt2"></span></a>
                        <a href="<?php echo esc_url( $list_url ); ?>" class="jscfr-topbar-icon" title="<?php esc_attr_e( 'All Field Groups', 'jscfr' ); ?>"><span class="dashicons dashicons-editor-ul"></span></a>
                        <button type="button" class="jscfr-topbar-icon jscfr-topbar-toggle-sidebar" title="<?php esc_attr_e( 'Settings', 'jscfr' ); ?>"><span class="dashicons dashicons-admin-generic"></span></button>
                    </div>
                    <div class="jscfr-topbar-center">
                        <input type="text" id="jscfr-fg-title" class="jscfr-fg-title-input" value="<?php echo esc_attr( $fg['title'] ); ?>" placeholder="<?php esc_attr_e( 'Please enter the field group title here...', 'jscfr' ); ?>" />
                        <span class="jscfr-topbar-post-badge"><?php esc_html_e( 'Post', 'jscfr' ); ?></span>
                    </div>
                    <div class="jscfr-topbar-right">
                        <?php if ( ! $is_new ) : ?>
                        <button type="button" class="jscfr-topbar-btn jscfr-topbar-btn--outline" id="jscfr-get-code-btn">
                            <span class="dashicons dashicons-editor-code"></span> <?php esc_html_e( 'Get Code', 'jscfr' ); ?>
                        </button>
                        <?php endif; ?>
                        <button type="button" class="jscfr-topbar-btn jscfr-topbar-btn--primary jscfr-save-fg" id="jscfr-save-fg">
                            <?php echo $is_new ? esc_html__( 'Create Field Group', 'jscfr' ) : esc_html__( 'Save Changes', 'jscfr' ); ?>
                        </button>
                        <span id="jscfr-save-status" class="jscfr-save-status"></span>
                    </div>
                </div>

                <!-- ===== TWO-COLUMN BODY ===== -->
                <div class="jscfr-edit-columns">

                    <button type="button" class="jscfr-sidebar-toggle-btn jscfr-sidebar-collapse-btn" title="<?php esc_attr_e( 'Collapse sidebar', 'jscfr' ); ?>" aria-label="<?php esc_attr_e( 'Collapse sidebar', 'jscfr' ); ?>">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </button>
                    <button type="button" class="jscfr-sidebar-toggle-btn jscfr-sidebar-expand-btn" title="<?php esc_attr_e( 'Expand sidebar', 'jscfr' ); ?>" aria-label="<?php esc_attr_e( 'Expand sidebar', 'jscfr' ); ?>">
                        <span class="dashicons dashicons-admin-generic"></span>
                    </button>

                    <!-- LEFT SIDEBAR -->
                    <div class="jscfr-sidebar" id="jscfr-sidebar">
                        <div class="jscfr-sidebar-header">
                            <span class="jscfr-sidebar-title"><?php esc_html_e( 'Field group settings', 'jscfr' ); ?></span>
                            <span class="jscfr-sidebar-id"><?php esc_html_e( 'ID:', 'jscfr' ); ?> <code><?php echo esc_html( $fg['id'] ); ?></code></span>
                        </div>

                        <!-- Location -->
                        <div class="jscfr-sidebar-section">
                            <div class="jscfr-sidebar-section-header" data-toggle="jscfr-sidebar-location">
                                <span class="jscfr-sidebar-section-title"><?php esc_html_e( 'Location', 'jscfr' ); ?></span>
                                <span class="dashicons dashicons-arrow-up-alt2 jscfr-sidebar-arrow jscfr-sidebar-arrow--down"></span>
                            </div>
                            <div class="jscfr-sidebar-section-body" id="jscfr-sidebar-location" style="display:none;">
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label"><?php esc_html_e( 'RULE', 'jscfr' ); ?> <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Determine where this field group appears.', 'jscfr' ); ?>"></span></label>
                                    <div id="jscfr-location-rules"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Settings -->
                        <div class="jscfr-sidebar-section">
                            <div class="jscfr-sidebar-section-header" data-toggle="jscfr-sidebar-settings">
                                <span class="jscfr-sidebar-section-title"><?php esc_html_e( 'Settings', 'jscfr' ); ?></span>
                                <span class="dashicons dashicons-arrow-up-alt2 jscfr-sidebar-arrow jscfr-sidebar-arrow--down"></span>
                            </div>
                            <div class="jscfr-sidebar-section-body" id="jscfr-sidebar-settings" style="display:none;">

                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label"><?php esc_html_e( 'POSITION', 'jscfr' ); ?></label>
                                    <select id="jscfr-set-position" class="jscfr-sb-select">
                                        <option value="normal"><?php esc_html_e( 'After content', 'jscfr' ); ?></option>
                                        <option value="side"><?php esc_html_e( 'Side', 'jscfr' ); ?></option>
                                        <option value="acf_after_title"><?php esc_html_e( 'After title', 'jscfr' ); ?></option>
                                    </select>
                                </div>

                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label"><?php esc_html_e( 'PRIORITY', 'jscfr' ); ?></label>
                                    <div class="jscfr-sb-pills">
                                        <label class="jscfr-sb-pill"><input type="radio" name="jscfr_priority" value="high" checked /> <?php esc_html_e( 'High', 'jscfr' ); ?></label>
                                        <label class="jscfr-sb-pill"><input type="radio" name="jscfr_priority" value="low" /> <?php esc_html_e( 'Low', 'jscfr' ); ?></label>
                                    </div>
                                </div>

                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label"><?php esc_html_e( 'STYLE', 'jscfr' ); ?></label>
                                    <div class="jscfr-sb-pills">
                                        <label class="jscfr-sb-pill"><input type="radio" name="jscfr_style" value="default" checked /> <?php esc_html_e( 'Standard', 'jscfr' ); ?></label>
                                        <label class="jscfr-sb-pill"><input type="radio" name="jscfr_style" value="seamless" /> <?php esc_html_e( 'Seamless', 'jscfr' ); ?></label>
                                    </div>
                                </div>

                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label"><?php esc_html_e( 'LABEL PLACEMENT', 'jscfr' ); ?></label>
                                    <select id="jscfr-set-label" class="jscfr-sb-select">
                                        <option value="top"><?php esc_html_e( 'Top aligned', 'jscfr' ); ?></option>
                                        <option value="left"><?php esc_html_e( 'Left aligned', 'jscfr' ); ?></option>
                                    </select>
                                </div>

                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label"><?php esc_html_e( 'TAB PLACEMENT', 'jscfr' ); ?></label>
                                    <select id="jscfr-set-tab-placement" class="jscfr-sb-select">
                                        <option value="top"><?php esc_html_e( 'Top (horizontal)', 'jscfr' ); ?></option>
                                        <option value="left"><?php esc_html_e( 'Left (vertical)', 'jscfr' ); ?></option>
                                    </select>
                                </div>

                                <div class="jscfr-sb-toggles">
                                    <label class="jscfr-sb-toggle">
                                        <input type="checkbox" id="jscfr-set-active" value="1" />
                                        <span class="jscfr-sb-toggle-slider"></span>
                                        <span><?php esc_html_e( 'Active', 'jscfr' ); ?></span>
                                        <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Enable or disable this field group.', 'jscfr' ); ?>"></span>
                                    </label>
                                    <label class="jscfr-sb-toggle">
                                        <input type="checkbox" id="jscfr-set-revision" value="1" />
                                        <span class="jscfr-sb-toggle-slider"></span>
                                        <span><?php esc_html_e( 'Revisions', 'jscfr' ); ?></span>
                                        <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Track field values in post revisions.', 'jscfr' ); ?>"></span>
                                    </label>
                                </div>

                                <!-- Hidden fields to preserve backward compat with existing collect() -->
                                <input type="hidden" id="jscfr-set-style" value="default" />
                                <textarea id="jscfr-set-description" style="display:none;"></textarea>
                                <input type="hidden" id="jscfr-set-order" value="0" />
                                <input type="hidden" id="jscfr-set-include" value="" />
                                <input type="hidden" id="jscfr-set-exclude" value="" />
                            </div>
                        </div>

                        <!-- Toggle Rules (collapsed) -->
                        <div class="jscfr-sidebar-section">
                            <div class="jscfr-sidebar-section-header" data-toggle="jscfr-sidebar-toggle-rules">
                                <span class="jscfr-sidebar-section-title"><?php esc_html_e( 'Toggle rules', 'jscfr' ); ?></span>
                                <span class="dashicons dashicons-arrow-up-alt2 jscfr-sidebar-arrow jscfr-sidebar-arrow--down"></span>
                            </div>
                            <div class="jscfr-sidebar-section-body" id="jscfr-sidebar-toggle-rules" style="display:none;">
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label">
                                        <?php esc_html_e( 'TOGGLE TYPE', 'jscfr' ); ?>
                                        <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Show or hide this field group based on toggle rules.', 'jscfr' ); ?>"></span>
                                    </label>
                                    <div class="jscfr-sb-pills">
                                        <label class="jscfr-sb-pill"><input type="radio" name="jscfr_toggle_type" value="show" checked /> <?php esc_html_e( 'Show', 'jscfr' ); ?></label>
                                        <label class="jscfr-sb-pill"><input type="radio" name="jscfr_toggle_type" value="hide" /> <?php esc_html_e( 'Hide', 'jscfr' ); ?></label>
                                    </div>
                                </div>
                                <div class="jscfr-sb-field">
                                    <div id="jscfr-toggle-rules-list" class="jscfr-toggle-rules-list"></div>
                                    <button type="button" class="button jscfr-add-toggle-rule">
                                        <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span> <?php esc_html_e( 'Add Rule', 'jscfr' ); ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Conditional Logic (collapsed) -->
                        <div class="jscfr-sidebar-section">
                            <div class="jscfr-sidebar-section-header" data-toggle="jscfr-sidebar-cond-logic">
                                <span class="jscfr-sidebar-section-title"><?php esc_html_e( 'Conditional logic', 'jscfr' ); ?></span>
                                <span class="dashicons dashicons-arrow-up-alt2 jscfr-sidebar-arrow jscfr-sidebar-arrow--down"></span>
                            </div>
                            <div class="jscfr-sidebar-section-body" id="jscfr-sidebar-cond-logic" style="display:none;">
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-toggle">
                                        <input type="checkbox" id="jscfr-set-fg-cond-enabled" value="1" />
                                        <span class="jscfr-sb-toggle-slider"></span>
                                        <span><?php esc_html_e( 'Enable', 'jscfr' ); ?></span>
                                        <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Show/hide this entire field group based on other field values.', 'jscfr' ); ?>"></span>
                                    </label>
                                </div>
                                <div id="jscfr-fg-cond-rules" class="jscfr-fg-cond-rules" style="display:none;">
                                    <div class="jscfr-sb-field">
                                        <label class="jscfr-sb-label"><?php esc_html_e( 'SHOW THIS FIELD GROUP WHEN', 'jscfr' ); ?></label>
                                        <div id="jscfr-fg-cond-rules-list"></div>
                                        <button type="button" class="button jscfr-add-fg-cond-rule">
                                            <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span> <?php esc_html_e( 'Add Rule', 'jscfr' ); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tab Settings (collapsed) -->
                        <div class="jscfr-sidebar-section">
                            <div class="jscfr-sidebar-section-header" data-toggle="jscfr-sidebar-tab-settings">
                                <span class="jscfr-sidebar-section-title"><?php esc_html_e( 'Tab settings', 'jscfr' ); ?></span>
                                <span class="dashicons dashicons-arrow-up-alt2 jscfr-sidebar-arrow jscfr-sidebar-arrow--down"></span>
                            </div>
                            <div class="jscfr-sidebar-section-body" id="jscfr-sidebar-tab-settings" style="display:none;">
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label">
                                        <?php esc_html_e( 'TAB STYLE', 'jscfr' ); ?>
                                        <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Choose tab style for this field group.', 'jscfr' ); ?>"></span>
                                    </label>
                                    <div class="jscfr-sb-pills">
                                        <label class="jscfr-sb-pill"><input type="radio" name="jscfr_tab_style" value="default" checked /> <?php esc_html_e( 'Default', 'jscfr' ); ?></label>
                                        <label class="jscfr-sb-pill"><input type="radio" name="jscfr_tab_style" value="left" /> <?php esc_html_e( 'Left', 'jscfr' ); ?></label>
                                        <label class="jscfr-sb-pill"><input type="radio" name="jscfr_tab_style" value="box" /> <?php esc_html_e( 'Box', 'jscfr' ); ?></label>
                                    </div>
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-toggle">
                                        <input type="checkbox" id="jscfr-set-tab-remember" value="1" />
                                        <span class="jscfr-sb-toggle-slider"></span>
                                        <span><?php esc_html_e( 'Remember last tab', 'jscfr' ); ?></span>
                                        <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Remember the last active tab when the user returns to the post.', 'jscfr' ); ?>"></span>
                                    </label>
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label" for="jscfr-set-tab-default"><?php esc_html_e( 'DEFAULT TAB', 'jscfr' ); ?></label>
                                    <input type="number" id="jscfr-set-tab-default" class="jscfr-sb-input" value="0" min="0" step="1" placeholder="<?php esc_attr_e( '0 = first tab', 'jscfr' ); ?>" />
                                </div>
                            </div>
                        </div>

                        <!-- Custom Table (collapsed) -->
                        <div class="jscfr-sidebar-section">
                            <div class="jscfr-sidebar-section-header" data-toggle="jscfr-sidebar-custom-table">
                                <span class="jscfr-sidebar-section-title"><?php esc_html_e( 'Custom table', 'jscfr' ); ?></span>
                                <span class="dashicons dashicons-arrow-up-alt2 jscfr-sidebar-arrow jscfr-sidebar-arrow--down"></span>
                            </div>
                            <div class="jscfr-sidebar-section-body" id="jscfr-sidebar-custom-table" style="display:none;">
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-toggle">
                                        <input type="checkbox" id="jscfr-set-custom-table" value="1" />
                                        <span class="jscfr-sb-toggle-slider"></span>
                                        <span><?php esc_html_e( 'Save data in a custom table', 'jscfr' ); ?></span>
                                        <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Store field data in a dedicated database table instead of post meta.', 'jscfr' ); ?>"></span>
                                    </label>
                                </div>
                                <div id="jscfr-custom-table-opts" style="display:none;">
                                    <div class="jscfr-sb-field">
                                        <label class="jscfr-sb-label" for="jscfr-set-table-name"><?php esc_html_e( 'TABLE NAME', 'jscfr' ); ?></label>
                                        <input type="text" id="jscfr-set-table-name" class="jscfr-sb-input" placeholder="<?php esc_attr_e( 'e.g. my_custom_fields', 'jscfr' ); ?>" />
                                    </div>
                                    <div class="jscfr-sb-field">
                                        <label class="jscfr-sb-toggle">
                                            <input type="checkbox" id="jscfr-set-table-create" value="1" checked />
                                            <span class="jscfr-sb-toggle-slider"></span>
                                            <span><?php esc_html_e( 'Create table automatically', 'jscfr' ); ?></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Advanced Settings (collapsed) -->
                        <div class="jscfr-sidebar-section">
                            <div class="jscfr-sidebar-section-header" data-toggle="jscfr-sidebar-advanced">
                                <span class="jscfr-sidebar-section-title"><?php esc_html_e( 'Advanced', 'jscfr' ); ?></span>
                                <span class="dashicons dashicons-arrow-up-alt2 jscfr-sidebar-arrow jscfr-sidebar-arrow--down"></span>
                            </div>
                            <div class="jscfr-sidebar-section-body" id="jscfr-sidebar-advanced" style="display:none;">
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label" for="jscfr-set-description-vis"><?php esc_html_e( 'DESCRIPTION', 'jscfr' ); ?></label>
                                    <textarea id="jscfr-set-description-vis" class="jscfr-sb-textarea" rows="2" placeholder="<?php esc_attr_e( 'Optional description', 'jscfr' ); ?>"></textarea>
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label" for="jscfr-set-order-vis"><?php esc_html_e( 'ORDER', 'jscfr' ); ?></label>
                                    <input type="number" id="jscfr-set-order-vis" class="jscfr-sb-input" value="0" min="0" step="1" />
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label" for="jscfr-set-include-vis"><?php esc_html_e( 'INCLUDE (POST IDS)', 'jscfr' ); ?></label>
                                    <input type="text" id="jscfr-set-include-vis" class="jscfr-sb-input" placeholder="<?php esc_attr_e( 'e.g. 1,5,23', 'jscfr' ); ?>" />
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label" for="jscfr-set-exclude-vis"><?php esc_html_e( 'EXCLUDE (POST IDS)', 'jscfr' ); ?></label>
                                    <input type="text" id="jscfr-set-exclude-vis" class="jscfr-sb-input" placeholder="<?php esc_attr_e( 'e.g. 10,42', 'jscfr' ); ?>" />
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label" for="jscfr-set-custom-class"><?php esc_html_e( 'CUSTOM CSS CLASS', 'jscfr' ); ?></label>
                                    <input type="text" id="jscfr-set-custom-class" class="jscfr-sb-input" placeholder="<?php esc_attr_e( 'e.g. my-custom-class', 'jscfr' ); ?>" />
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label" for="jscfr-set-prefix"><?php esc_html_e( 'CUSTOM PREFIX', 'jscfr' ); ?></label>
                                    <input type="text" id="jscfr-set-prefix" class="jscfr-sb-input" placeholder="<?php esc_attr_e( 'e.g. my_prefix_', 'jscfr' ); ?>" />
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-label" for="jscfr-set-text-domain"><?php esc_html_e( 'TEXT DOMAIN', 'jscfr' ); ?></label>
                                    <input type="text" id="jscfr-set-text-domain" class="jscfr-sb-input" placeholder="<?php esc_attr_e( 'e.g. my-theme', 'jscfr' ); ?>" />
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-toggle">
                                        <input type="checkbox" id="jscfr-set-autosave" value="1" />
                                        <span class="jscfr-sb-toggle-slider"></span>
                                        <span><?php esc_html_e( 'Autosave', 'jscfr' ); ?></span>
                                        <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Save field values on autosave events.', 'jscfr' ); ?>"></span>
                                    </label>
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-toggle">
                                        <input type="checkbox" id="jscfr-set-collapsed" value="1" />
                                        <span class="jscfr-sb-toggle-slider"></span>
                                        <span><?php esc_html_e( 'Collapsed by default', 'jscfr' ); ?></span>
                                        <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Collapse the field group meta box by default.', 'jscfr' ); ?>"></span>
                                    </label>
                                </div>
                                <div class="jscfr-sb-field">
                                    <label class="jscfr-sb-toggle">
                                        <input type="checkbox" id="jscfr-set-hidden" value="1" />
                                        <span class="jscfr-sb-toggle-slider"></span>
                                        <span><?php esc_html_e( 'Hidden by default', 'jscfr' ); ?></span>
                                        <span class="jscfr-sb-info dashicons dashicons-info" title="<?php esc_attr_e( 'Hide this meta box from Screen Options. Users must manually enable it.', 'jscfr' ); ?>"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div><!-- /.jscfr-sidebar -->

                    <!-- MAIN CONTENT -->
                    <div class="jscfr-main">
                        <div class="jscfr-fields-panel">
                            <div class="jscfr-fields-panel-header">
                                <span class="dashicons dashicons-screenoptions jscfr-fields-panel-icon"></span>
                                <span class="jscfr-fields-panel-title"><?php esc_html_e( 'Fields', 'jscfr' ); ?></span>
                            </div>
                            <div class="jscfr-fields-panel-body">
                                <div id="jscfr-tabs-container" class="jscfr-tabs-container"></div>
                                <button type="button" class="jscfr-add-field-main-btn" id="jscfr-add-field-main">
                                    <span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Field', 'jscfr' ); ?>
                                </button>
                            </div>
                        </div>
                    </div><!-- /.jscfr-main -->

                </div><!-- /.jscfr-edit-columns -->
            </div>
            <?php
        }

        /* ============================================================= */
        /*  IMPORT / EXPORT PAGE                                          */
        /* ============================================================= */
        public function render_import_export_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'jscfr' ) );
            }
            $config = JSCFR_Plugin::get_config();
            ?>
            <div class="wrap jscfr-builder-wrap">
                <h1><?php esc_html_e( 'Import / Export Field Groups', 'jscfr' ); ?></h1>
                <hr class="wp-header-end">

                <div class="jscfr-ie-grid">
                    <!-- Export -->
                    <div class="jscfr-ie-box">
                        <h2><?php esc_html_e( 'Export', 'jscfr' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Select field groups to export as JSON.', 'jscfr' ); ?></p>
                        <div class="jscfr-ie-checkboxes">
                            <?php foreach ( $config as $fg ) : ?>
                                <label>
                                    <input type="checkbox" class="jscfr-export-cb" value="<?php echo esc_attr( $fg['id'] ); ?>" checked />
                                    <?php echo esc_html( ! empty( $fg['title'] ) ? $fg['title'] : $fg['id'] ); ?>
                                </label><br>
                            <?php endforeach; ?>
                        </div>
                        <p>
                            <button type="button" class="button button-primary" id="jscfr-export-btn">
                                <span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:4px;"></span>
                                <?php esc_html_e( 'Export Selected', 'jscfr' ); ?>
                            </button>
                        </p>
                        <textarea id="jscfr-export-json" class="large-text code" rows="10" readonly style="display:none;"></textarea>
                    </div>

                    <!-- Import -->
                    <div class="jscfr-ie-box">
                        <h2><?php esc_html_e( 'Import', 'jscfr' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Paste JSON or upload a .json file to import field groups.', 'jscfr' ); ?></p>
                        <textarea id="jscfr-import-json" class="large-text code" rows="10" placeholder='[{"id":"fg_xxx", "title":"...", ...}]'></textarea>
                        <p>
                            <input type="file" id="jscfr-import-file" accept=".json" />
                        </p>
                        <p>
                            <button type="button" class="button button-primary" id="jscfr-import-btn">
                                <span class="dashicons dashicons-upload" style="vertical-align:middle;margin-right:4px;"></span>
                                <?php esc_html_e( 'Import', 'jscfr' ); ?>
                            </button>
                            <span id="jscfr-import-status"></span>
                        </p>
                    </div>
                </div>
            </div>
            <?php
        }

        /* ============================================================= */
        /*  Assets                                                        */
        /* ============================================================= */
        public function enqueue_assets( $hook ) {
            if ( ! in_array( $hook, array( 'toplevel_page_jscfr-builder', 'cf-builder_page_jscfr-import-export' ), true ) ) {
                return;
            }

            wp_enqueue_style( 'jscfr-builder-css', JSCFR_PLUGIN_URL . 'assets/css/jscfr-builder.css', array(), JSCFR_VERSION );

            $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

            // Import/Export page
            if ( 'cf-builder_page_jscfr-import-export' === $hook ) {
                wp_enqueue_script( 'jscfr-builder-list-js', JSCFR_PLUGIN_URL . 'assets/js/jscfr-builder-list.js', array( 'jquery' ), JSCFR_VERSION, true );
                wp_localize_script( 'jscfr-builder-list-js', 'jscfr_list', array(
                    'ajax_url'       => admin_url( 'admin-ajax.php' ),
                    'nonce'          => wp_create_nonce( JSCFR_BUILDER_NONCE ),
                    'confirm_delete' => __( 'Delete this field group? This cannot be undone.', 'jscfr' ),
                ) );
                return;
            }

            if ( 'edit' !== $action ) {
                wp_enqueue_script( 'jscfr-builder-list-js', JSCFR_PLUGIN_URL . 'assets/js/jscfr-builder-list.js', array( 'jquery' ), JSCFR_VERSION, true );
                wp_localize_script( 'jscfr-builder-list-js', 'jscfr_list', array(
                    'ajax_url'       => admin_url( 'admin-ajax.php' ),
                    'nonce'          => wp_create_nonce( JSCFR_BUILDER_NONCE ),
                    'confirm_delete' => __( 'Delete this field group? This cannot be undone.', 'jscfr' ),
                ) );
                return;
            }

            // Edit page
            wp_enqueue_script( 'jquery-ui-sortable' );

            $fg_id = isset( $_GET['fg_id'] ) ? sanitize_key( $_GET['fg_id'] ) : 'new';
            $fg = ( 'new' !== $fg_id ) ? JSCFR_Plugin::get_field_group( $fg_id ) : null;
            if ( ! $fg ) {
                $fg = array(
                    'id'             => 'fg_' . wp_generate_password( 8, false ),
                    'title'          => '',
                    'tabs'           => array(),
                    'location_rules' => array( array( array( 'param' => 'post_type', 'operator' => 'is_equal_to', 'value' => 'post' ) ) ),
                    'settings'       => JSCFR_Plugin::default_settings(),
                );
            }

            wp_enqueue_script( 'jscfr-builder-js', JSCFR_PLUGIN_URL . 'assets/js/jscfr-builder.js', array( 'jquery', 'jquery-ui-sortable' ), JSCFR_VERSION, true );
            wp_localize_script( 'jscfr-builder-js', 'jscfr_builder', array(
                'ajax_url'        => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( JSCFR_BUILDER_NONCE ),
                'field_group'     => $fg,
                'field_types'     => JSCFR_Plugin::get_field_types(),
                'location_params' => $this->get_location_params(),
                'default_field'   => JSCFR_Plugin::default_field(),
                'i18n' => array(
                    'new_tab'          => __( 'New Tab', 'jscfr' ),
                    'new_group'        => __( 'New Group', 'jscfr' ),
                    'new_field'        => __( 'New Field', 'jscfr' ),
                    'confirm_delete'   => __( 'Are you sure?', 'jscfr' ),
                    'saved'            => __( 'Saved!', 'jscfr' ),
                    'save_error'       => __( 'Error saving.', 'jscfr' ),
                    'tab_label'        => __( 'Tab Label', 'jscfr' ),
                    'tab_name'         => __( 'Tab Name', 'jscfr' ),
                    'group_label'      => __( 'Group Label', 'jscfr' ),
                    'group_name'       => __( 'Group Name', 'jscfr' ),
                    'field_label'      => __( 'Field Label', 'jscfr' ),
                    'field_name'       => __( 'Field Name', 'jscfr' ),
                    'field_type'       => __( 'Field Type', 'jscfr' ),
                    'placeholder'      => __( 'Placeholder', 'jscfr' ),
                    'instructions'     => __( 'Instructions', 'jscfr' ),
                    'instructions_hint' => __( 'Displayed below the field label', 'jscfr' ),
                    'default_value'    => __( 'Default Value', 'jscfr' ),
                    'wrapper_width'    => __( 'Wrapper Width %', 'jscfr' ),
                    'wrapper_class'    => __( 'Wrapper Class', 'jscfr' ),
                    'wrapper_id'       => __( 'Wrapper ID', 'jscfr' ),
                    'prepend'          => __( 'Prepend', 'jscfr' ),
                    'append'           => __( 'Append', 'jscfr' ),
                    'character_limit'  => __( 'Character Limit', 'jscfr' ),
                    'min'              => __( 'Minimum Value', 'jscfr' ),
                    'max'              => __( 'Maximum Value', 'jscfr' ),
                    'step'             => __( 'Step Size', 'jscfr' ),
                    'min_rows'         => __( 'Min Entries', 'jscfr' ),
                    'max_rows'         => __( 'Max Entries', 'jscfr' ),
                    'rows'             => __( 'Rows', 'jscfr' ),
                    'new_lines'        => __( 'New Lines', 'jscfr' ),
                    'options_hint'     => __( 'Options (value|Label per line)', 'jscfr' ),
                    'mime_hint'        => __( 'MIME filter (e.g. image/*, application/pdf)', 'jscfr' ),
                    'no_tabs'          => __( 'No tabs yet. Click "Add Tab".', 'jscfr' ),
                    'required'         => __( 'Required', 'jscfr' ),
                    'allow_null'       => __( 'Allow Null', 'jscfr' ),
                    'allow_multiple'   => __( 'Allow Multiple', 'jscfr' ),
                    'return_format'    => __( 'Return Format', 'jscfr' ),
                    'preview_size'     => __( 'Preview Size', 'jscfr' ),
                    'toolbar'          => __( 'Toolbar', 'jscfr' ),
                    'media_upload'     => __( 'Allow Media Upload', 'jscfr' ),
                    'post_type_filter' => __( 'Filter by Post Type', 'jscfr' ),
                    'taxonomy_type'    => __( 'Taxonomy', 'jscfr' ),
                    'field_type_tax'   => __( 'Appearance', 'jscfr' ),
                    'save_terms'       => __( 'Save Terms', 'jscfr' ),
                    'load_terms'       => __( 'Load Terms', 'jscfr' ),
                    'user_role'        => __( 'Filter by Role', 'jscfr' ),
                    'display_format'   => __( 'Display Format', 'jscfr' ),
                    'return_format_dt' => __( 'Return Format', 'jscfr' ),
                    'oembed_width'     => __( 'Width', 'jscfr' ),
                    'oembed_height'    => __( 'Height', 'jscfr' ),
                    'message_text'     => __( 'Message', 'jscfr' ),
                    'link_target'      => __( 'Open in new tab', 'jscfr' ),
                    'add_rule_group'   => __( 'Add rule group', 'jscfr' ),
                    'and'              => __( 'and', 'jscfr' ),
                    'or'               => __( 'or', 'jscfr' ),
                    'conditional_logic' => __( 'Conditional Logic', 'jscfr' ),
                    'cond_show_if'     => __( 'Show this field if', 'jscfr' ),
                    'cond_field'       => __( 'Field', 'jscfr' ),
                    'general_tab'      => __( 'General', 'jscfr' ),
                    'validation_tab'   => __( 'Validation', 'jscfr' ),
                    'presentation_tab' => __( 'Presentation', 'jscfr' ),
                    'conditional_tab'  => __( 'Conditional Logic', 'jscfr' ),
                    'layout'           => __( 'Layout', 'jscfr' ),
                ),
                'post_types' => $this->get_post_type_choices(),
                'taxonomies' => $this->get_taxonomy_choices(),
                'roles'      => $this->get_role_choices(),
                'image_sizes' => $this->get_image_sizes(),
            ) );
        }

        private function get_post_type_choices() {
            $out = array();
            foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
                $out[] = array( 'value' => $pt->name, 'label' => $pt->labels->singular_name );
            }
            return $out;
        }

        private function get_taxonomy_choices() {
            $out = array();
            foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
                $out[] = array( 'value' => $tax->name, 'label' => $tax->labels->singular_name );
            }
            return $out;
        }

        private function get_role_choices() {
            $out = array();
            foreach ( wp_roles()->roles as $slug => $role ) {
                $out[] = array( 'value' => $slug, 'label' => $role['name'] );
            }
            return $out;
        }

        private function get_image_sizes() {
            $sizes = array();
            foreach ( get_intermediate_image_sizes() as $s ) {
                $sizes[] = array( 'value' => $s, 'label' => ucwords( str_replace( array( '-', '_' ), ' ', $s ) ) );
            }
            $sizes[] = array( 'value' => 'full', 'label' => 'Full' );
            return $sizes;
        }

        /* ============================================================= */
        /*  Location param definitions                                    */
        /* ============================================================= */
        private function get_location_params() {
            $params = array();

            $pt_choices = array();
            foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
                $pt_choices[] = array( 'value' => $pt->name, 'label' => $pt->labels->singular_name );
            }
            $params['post_type'] = array( 'label' => __( 'Post Type', 'jscfr' ), 'choices' => $pt_choices );

            $tpl_choices = array( array( 'value' => 'default', 'label' => __( 'Default Template', 'jscfr' ) ) );
            foreach ( wp_get_theme()->get_page_templates() as $file => $name ) {
                $tpl_choices[] = array( 'value' => $file, 'label' => $name );
            }
            $params['page_template'] = array( 'label' => __( 'Page Template', 'jscfr' ), 'choices' => $tpl_choices );

            $cat_choices = array();
            foreach ( get_categories( array( 'hide_empty' => false ) ) as $cat ) {
                $cat_choices[] = array( 'value' => $cat->slug, 'label' => $cat->name );
            }
            $params['post_category'] = array( 'label' => __( 'Post Category', 'jscfr' ), 'choices' => $cat_choices );

            $fmt_choices = array();
            foreach ( get_post_format_strings() as $slug => $label ) {
                $fmt_choices[] = array( 'value' => $slug ? $slug : 'standard', 'label' => $label );
            }
            $params['post_format'] = array( 'label' => __( 'Post Format', 'jscfr' ), 'choices' => $fmt_choices );

            $role_choices = array();
            foreach ( wp_roles()->roles as $slug => $role ) {
                $role_choices[] = array( 'value' => $slug, 'label' => $role['name'] );
            }
            $params['user_role'] = array( 'label' => __( 'User Role', 'jscfr' ), 'choices' => $role_choices );

            $status_choices = array();
            foreach ( get_post_stati( array(), 'objects' ) as $slug => $obj ) {
                $status_choices[] = array( 'value' => $slug, 'label' => $obj->label );
            }
            $params['post_status'] = array( 'label' => __( 'Post Status', 'jscfr' ), 'choices' => $status_choices );

            $params['post'] = array( 'label' => __( 'Post (ID)', 'jscfr' ), 'choices' => 'text_input' );

            $tax_choices = array();
            foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
                $terms = get_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false, 'number' => 100 ) );
                if ( ! is_wp_error( $terms ) ) {
                    foreach ( $terms as $term ) {
                        $tax_choices[] = array( 'value' => $tax->name . ':' . $term->slug, 'label' => $tax->labels->singular_name . ': ' . $term->name );
                    }
                }
            }
            $params['post_taxonomy'] = array( 'label' => __( 'Post Taxonomy', 'jscfr' ), 'choices' => $tax_choices );

            $opt_choices = array();
            foreach ( JSCFR_Options_Page::get_pages() as $pg ) {
                $opt_choices[] = array( 'value' => $pg['slug'], 'label' => $pg['title'] );
            }
            $params['options_page'] = array( 'label' => __( 'Options Page', 'jscfr' ), 'choices' => $opt_choices );

            // Taxonomy term
            $tax_term_choices = array();
            foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
                $tax_term_choices[] = array( 'value' => $tax->name, 'label' => $tax->labels->singular_name );
            }
            $params['taxonomy_term'] = array( 'label' => __( 'Taxonomy Term', 'jscfr' ), 'choices' => $tax_term_choices );

            // User role (already exists as user_role, re-use for user meta context)

            // Comment
            $params['comment'] = array( 'label' => __( 'Comment', 'jscfr' ), 'choices' => array(
                array( 'value' => 'all', 'label' => __( 'All Comments', 'jscfr' ) ),
            ) );

            return $params;
        }

        /* ============================================================= */
        /*  AJAX handlers                                                 */
        /* ============================================================= */
        public function ajax_save_field_group() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }
            $raw = isset( $_POST['field_group'] ) ? $_POST['field_group'] : '{}';
            $decoded = json_decode( wp_unslash( $raw ), true );
            if ( ! is_array( $decoded ) ) {
                wp_send_json_error( 'Invalid data' );
            }
            $clean = $this->sanitize_field_group( $decoded );

            do_action( 'jscfr/before_save_field_group', $clean );

            JSCFR_Plugin::save_field_group( $clean );
            wp_send_json_success( array(
                'field_group' => $clean,
                'redirect'    => admin_url( 'admin.php?page=jscfr-builder&action=edit&fg_id=' . $clean['id'] ),
            ) );
        }

        public function ajax_delete_field_group() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
            $fg_id = isset( $_POST['fg_id'] ) ? sanitize_key( $_POST['fg_id'] ) : '';
            if ( ! $fg_id ) wp_send_json_error( 'No ID' );
            JSCFR_Plugin::delete_field_group( $fg_id );
            wp_send_json_success();
        }

        public function ajax_duplicate_field_group() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
            $fg_id = isset( $_POST['fg_id'] ) ? sanitize_key( $_POST['fg_id'] ) : '';
            $fg = JSCFR_Plugin::get_field_group( $fg_id );
            if ( ! $fg ) wp_send_json_error( 'Not found' );

            $new = $fg;
            $new['id']    = 'fg_' . wp_generate_password( 8, false );
            $new['title'] = $fg['title'] . ' (Copy)';
            $new = $this->regenerate_ids( $new );
            JSCFR_Plugin::save_field_group( $new );
            wp_send_json_success( array( 'redirect' => admin_url( 'admin.php?page=jscfr-builder' ) ) );
        }

        public function ajax_toggle_field_group() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
            $fg_id = isset( $_POST['fg_id'] ) ? sanitize_key( $_POST['fg_id'] ) : '';
            $fg = JSCFR_Plugin::get_field_group( $fg_id );
            if ( ! $fg ) wp_send_json_error( 'Not found' );
            $fg['settings']['active'] = ! $fg['settings']['active'];
            JSCFR_Plugin::save_field_group( $fg );
            wp_send_json_success( array( 'active' => $fg['settings']['active'] ) );
        }

        public function ajax_export_field_groups() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

            $ids = isset( $_POST['ids'] ) ? array_map( 'sanitize_key', (array) $_POST['ids'] ) : array();
            $config = JSCFR_Plugin::get_config();
            $export = array();

            foreach ( $config as $fg ) {
                if ( empty( $ids ) || in_array( $fg['id'], $ids, true ) ) {
                    $export[] = $fg;
                }
            }
            wp_send_json_success( array( 'json' => wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) );
        }

        public function ajax_import_field_groups() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

            $raw = isset( $_POST['json'] ) ? wp_unslash( $_POST['json'] ) : '';
            $data = json_decode( $raw, true );
            if ( ! is_array( $data ) ) {
                wp_send_json_error( 'Invalid JSON' );
            }

            $count = 0;
            foreach ( $data as $fg ) {
                if ( ! is_array( $fg ) || empty( $fg['id'] ) ) continue;
                $clean = $this->sanitize_field_group( $fg );
                // Generate new ID to avoid overwriting
                $clean['id'] = 'fg_' . wp_generate_password( 8, false );
                $clean = $this->regenerate_ids( $clean );
                JSCFR_Plugin::save_field_group( $clean );
                $count++;
            }

            wp_send_json_success( array( 'count' => $count ) );
        }

        /* ============================================================= */
        /*  AJAX: Search posts (for post_object / relationship fields)    */
        /* ============================================================= */
        public function ajax_search_posts() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( __( 'Unauthorized', 'jscfr' ) );
            }

            $search     = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
            $post_types = isset( $_POST['post_type'] ) ? array_map( 'sanitize_key', (array) $_POST['post_type'] ) : array( 'post', 'page' );
            $exclude    = isset( $_POST['exclude'] ) ? array_map( 'absint', (array) $_POST['exclude'] ) : array();

            $post_status = current_user_can( 'read_private_posts' ) ? 'any' : array( 'publish' );

            $args = array(
                'post_type'      => $post_types,
                'posts_per_page' => 20,
                's'              => $search,
                'post_status'    => $post_status,
                'orderby'        => 'title',
                'order'          => 'ASC',
            );
            if ( ! empty( $exclude ) ) {
                $args['post__not_in'] = $exclude;
            }

            $results = array();
            $query = new WP_Query( $args );
            if ( $query->have_posts() ) {
                while ( $query->have_posts() ) {
                    $query->the_post();
                    $results[] = array(
                        'id'    => get_the_ID(),
                        'title' => get_the_title() ?: __( '(no title)', 'jscfr' ),
                        'type'  => get_post_type(),
                    );
                }
                wp_reset_postdata();
            }

            wp_send_json_success( $results );
        }

        /* ============================================================= */
        /*  AJAX: Search users                                            */
        /* ============================================================= */
        public function ajax_search_users() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'list_users' ) ) {
                wp_send_json_error( __( 'Unauthorized', 'jscfr' ) );
            }

            $search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
            $roles  = isset( $_POST['role'] ) ? array_map( 'sanitize_key', (array) $_POST['role'] ) : array();

            $args = array(
                'number'  => 20,
                'search'  => '*' . $search . '*',
                'orderby' => 'display_name',
            );
            if ( ! empty( $roles ) ) {
                $args['role__in'] = $roles;
            }

            $can_see_email = current_user_can( 'edit_users' );
            $results       = array();
            $users         = get_users( $args );
            foreach ( $users as $user ) {
                $row = array(
                    'id'   => $user->ID,
                    'name' => $user->display_name,
                );
                if ( $can_see_email ) {
                    $row['email'] = $user->user_email;
                }
                $results[] = $row;
            }

            wp_send_json_success( $results );
        }

        /* ============================================================= */
        /*  AJAX: Search terms                                            */
        /* ============================================================= */
        public function ajax_search_terms() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error( __( 'Unauthorized', 'jscfr' ) );
            }

            $search   = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
            $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( $_POST['taxonomy'] ) : 'category';

            $terms = get_terms( array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'search'     => $search,
                'number'     => 50,
            ) );

            $results = array();
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $results[] = array(
                        'id'   => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }
            }

            wp_send_json_success( $results );
        }

        /* ============================================================= */
        /*  Sanitize — handles all v4 field properties                    */
        /* ============================================================= */
        private function sanitize_field_group( $fg ) {
            $valid_types = array_keys( JSCFR_Plugin::get_field_types() );

            $clean = array(
                'id'             => $this->sanitize_id( isset( $fg['id'] ) ? $fg['id'] : '', 'fg' ),
                'title'          => sanitize_text_field( isset( $fg['title'] ) ? $fg['title'] : '' ),
                'tabs'           => array(),
                'location_rules' => array(),
                'settings'       => array(),
            );

            if ( isset( $fg['tabs'] ) && is_array( $fg['tabs'] ) ) {
                foreach ( $fg['tabs'] as $tab ) {
                    if ( ! is_array( $tab ) ) continue;
                    $ct = array(
                        'id'        => $this->sanitize_id( isset( $tab['id'] ) ? $tab['id'] : '', 'tab' ),
                        'name'      => sanitize_key( isset( $tab['name'] ) ? $tab['name'] : '' ),
                        'label'     => sanitize_text_field( isset( $tab['label'] ) ? $tab['label'] : '' ),
                        'icon_type' => in_array( isset( $tab['icon_type'] ) ? $tab['icon_type'] : '', array( 'dashicons', 'fontawesome', 'url' ), true ) ? $tab['icon_type'] : '',
                        'icon'      => sanitize_text_field( isset( $tab['icon'] ) ? $tab['icon'] : '' ),
                        'groups'    => array(),
                    );
                    if ( empty( $ct['name'] ) && ! empty( $ct['label'] ) ) {
                        $ct['name'] = sanitize_title( $ct['label'] );
                    }
                    if ( isset( $tab['groups'] ) && is_array( $tab['groups'] ) ) {
                        foreach ( $tab['groups'] as $group ) {
                            if ( ! is_array( $group ) ) continue;
                            $cg = array(
                                'id'       => $this->sanitize_id( isset( $group['id'] ) ? $group['id'] : '', 'grp' ),
                                'name'     => sanitize_key( isset( $group['name'] ) ? $group['name'] : '' ),
                                'label'    => sanitize_text_field( isset( $group['label'] ) ? $group['label'] : '' ),
                                'clonable' => isset( $group['clonable'] ) ? (bool) $group['clonable'] : true,
                                'min'      => isset( $group['min'] ) ? absint( $group['min'] ) : '',
                                'max'      => isset( $group['max'] ) ? absint( $group['max'] ) : '',
                                'layout'   => in_array( isset( $group['layout'] ) ? $group['layout'] : '', array( 'table', 'block', 'row' ), true ) ? $group['layout'] : 'block',
                                'fields'   => array(),
                            );
                            if ( empty( $cg['name'] ) && ! empty( $cg['label'] ) ) {
                                $cg['name'] = sanitize_title( $cg['label'] );
                            }
                            if ( isset( $group['fields'] ) && is_array( $group['fields'] ) ) {
                                foreach ( $group['fields'] as $field ) {
                                    if ( ! is_array( $field ) ) continue;
                                    $cg['fields'][] = $this->sanitize_field( $field, $valid_types );
                                }
                            }
                            $ct['groups'][] = $cg;
                        }
                    }
                    $clean['tabs'][] = $ct;
                }
            }

            if ( isset( $fg['location_rules'] ) && is_array( $fg['location_rules'] ) ) {
                foreach ( $fg['location_rules'] as $or_group ) {
                    if ( ! is_array( $or_group ) ) continue;
                    $co = array();
                    foreach ( $or_group as $rule ) {
                        if ( ! is_array( $rule ) ) continue;
                        $co[] = array(
                            'param'    => sanitize_key( isset( $rule['param'] ) ? $rule['param'] : '' ),
                            'operator' => in_array( isset( $rule['operator'] ) ? $rule['operator'] : '', array( 'is_equal_to', 'is_not_equal_to' ), true ) ? $rule['operator'] : 'is_equal_to',
                            'value'    => sanitize_text_field( isset( $rule['value'] ) ? $rule['value'] : '' ),
                        );
                    }
                    if ( ! empty( $co ) ) $clean['location_rules'][] = $co;
                }
            }

            $s = isset( $fg['settings'] ) && is_array( $fg['settings'] ) ? $fg['settings'] : array();
            $d = JSCFR_Plugin::default_settings();
            $clean['settings'] = array(
                'position'        => in_array( isset( $s['position'] ) ? $s['position'] : '', array( 'normal', 'side', 'acf_after_title' ), true ) ? $s['position'] : $d['position'],
                'style'           => in_array( isset( $s['style'] ) ? $s['style'] : '', array( 'default', 'seamless' ), true ) ? $s['style'] : $d['style'],
                'label_placement' => in_array( isset( $s['label_placement'] ) ? $s['label_placement'] : '', array( 'top', 'left' ), true ) ? $s['label_placement'] : $d['label_placement'],
                'tab_placement'   => in_array( isset( $s['tab_placement'] ) ? $s['tab_placement'] : '', array( 'top', 'left' ), true ) ? $s['tab_placement'] : $d['tab_placement'],
                'active'          => isset( $s['active'] ) ? (bool) $s['active'] : true,
                'description'     => sanitize_textarea_field( isset( $s['description'] ) ? $s['description'] : '' ),
                'order'           => isset( $s['order'] ) ? absint( $s['order'] ) : 0,
                'include'         => sanitize_text_field( isset( $s['include'] ) ? $s['include'] : '' ),
                'exclude'         => sanitize_text_field( isset( $s['exclude'] ) ? $s['exclude'] : '' ),
                'revision'        => isset( $s['revision'] ) ? (bool) $s['revision'] : false,
                // Toggle rules
                'toggle_type'     => in_array( isset( $s['toggle_type'] ) ? $s['toggle_type'] : '', array( 'show', 'hide' ), true ) ? $s['toggle_type'] : 'show',
                'toggle_rules'    => $this->sanitize_simple_rules( isset( $s['toggle_rules'] ) ? $s['toggle_rules'] : array() ),
                // FG conditional logic
                'fg_conditional_logic' => $this->sanitize_simple_rules( isset( $s['fg_conditional_logic'] ) ? $s['fg_conditional_logic'] : array() ),
                // Tab settings
                'tab_style'       => in_array( isset( $s['tab_style'] ) ? $s['tab_style'] : '', array( 'default', 'left', 'box' ), true ) ? $s['tab_style'] : 'default',
                'tab_remember'    => isset( $s['tab_remember'] ) ? (bool) $s['tab_remember'] : false,
                'tab_default'     => isset( $s['tab_default'] ) ? absint( $s['tab_default'] ) : 0,
                // Custom table
                'custom_table'    => isset( $s['custom_table'] ) ? (bool) $s['custom_table'] : false,
                'table_name'      => sanitize_key( isset( $s['table_name'] ) ? $s['table_name'] : '' ),
                'table_create'    => isset( $s['table_create'] ) ? (bool) $s['table_create'] : true,
                // Advanced extras
                'custom_class'    => sanitize_text_field( isset( $s['custom_class'] ) ? $s['custom_class'] : '' ),
                'prefix'          => sanitize_text_field( isset( $s['prefix'] ) ? $s['prefix'] : '' ),
                'text_domain'     => sanitize_text_field( isset( $s['text_domain'] ) ? $s['text_domain'] : '' ),
                'autosave'        => isset( $s['autosave'] ) ? (bool) $s['autosave'] : false,
                'collapsed'       => isset( $s['collapsed'] ) ? (bool) $s['collapsed'] : false,
                'hidden'          => isset( $s['hidden'] ) ? (bool) $s['hidden'] : false,
            );

            return $clean;
        }

        /**
         * Sanitize an array of simple {field, operator, value} rules.
         */
        private function sanitize_simple_rules( $rules ) {
            if ( ! is_array( $rules ) ) return array();
            $clean = array();
            $valid_ops = array( '==', '!=', '>', '<', '>=', '<=', '==empty', '!=empty', '==contains', '!=contains' );
            foreach ( $rules as $rule ) {
                if ( ! is_array( $rule ) ) continue;
                $clean[] = array(
                    'field'    => sanitize_key( isset( $rule['field'] ) ? $rule['field'] : '' ),
                    'operator' => in_array( isset( $rule['operator'] ) ? $rule['operator'] : '', $valid_ops, true ) ? $rule['operator'] : '==',
                    'value'    => sanitize_text_field( isset( $rule['value'] ) ? $rule['value'] : '' ),
                );
            }
            return $clean;
        }

        /**
         * Sanitize a single field with all v4 properties.
         */
        private function sanitize_field( $field, $valid_types ) {
            $ftype = sanitize_key( isset( $field['type'] ) ? $field['type'] : 'text' );
            if ( ! in_array( $ftype, $valid_types, true ) ) {
                $ftype = 'text';
            }

            $wrapper = isset( $field['wrapper'] ) && is_array( $field['wrapper'] ) ? $field['wrapper'] : array();
            $cond    = isset( $field['conditional_logic'] ) && is_array( $field['conditional_logic'] ) ? $field['conditional_logic'] : array();

            // Sanitize conditional logic
            $clean_cond = array();
            foreach ( $cond as $or_group ) {
                if ( ! is_array( $or_group ) ) continue;
                $co = array();
                foreach ( $or_group as $rule ) {
                    if ( ! is_array( $rule ) ) continue;
                    $co[] = array(
                        'field'    => sanitize_key( isset( $rule['field'] ) ? $rule['field'] : '' ),
                        'operator' => in_array( isset( $rule['operator'] ) ? $rule['operator'] : '', array( '==', '!=', '==empty', '!=empty', '==contains', '!=contains' ), true ) ? $rule['operator'] : '==',
                        'value'    => sanitize_text_field( isset( $rule['value'] ) ? $rule['value'] : '' ),
                    );
                }
                if ( ! empty( $co ) ) $clean_cond[] = $co;
            }

            $fname = sanitize_key( isset( $field['name'] ) ? $field['name'] : '' );
            if ( empty( $fname ) && ! empty( $field['label'] ) ) {
                $fname = sanitize_title( $field['label'] );
            }

            $cf = array(
                'id'               => $this->sanitize_id( isset( $field['id'] ) ? $field['id'] : '', 'fld' ),
                'name'             => $fname,
                'label'            => sanitize_text_field( isset( $field['label'] ) ? $field['label'] : '' ),
                'type'             => $ftype,
                'instructions'     => sanitize_textarea_field( isset( $field['instructions'] ) ? $field['instructions'] : '' ),
                'required'         => ! empty( $field['required'] ),
                'default_value'    => sanitize_textarea_field( isset( $field['default_value'] ) ? $field['default_value'] : '' ),
                'placeholder'      => sanitize_text_field( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ),
                'wrapper'          => array(
                    'width' => sanitize_text_field( isset( $wrapper['width'] ) ? $wrapper['width'] : '' ),
                    'class' => sanitize_text_field( isset( $wrapper['class'] ) ? $wrapper['class'] : '' ),
                    'id'    => sanitize_text_field( isset( $wrapper['id'] ) ? $wrapper['id'] : '' ),
                ),
                'conditional_logic' => $clean_cond,
                'prepend'          => sanitize_text_field( isset( $field['prepend'] ) ? $field['prepend'] : '' ),
                'append'           => sanitize_text_field( isset( $field['append'] ) ? $field['append'] : '' ),
                'maxlength'        => sanitize_text_field( isset( $field['maxlength'] ) ? $field['maxlength'] : '' ),
                'min'              => sanitize_text_field( isset( $field['min'] ) ? $field['min'] : '' ),
                'max'              => sanitize_text_field( isset( $field['max'] ) ? $field['max'] : '' ),
                'step'             => sanitize_text_field( isset( $field['step'] ) ? $field['step'] : '' ),
                'rows'             => isset( $field['rows'] ) ? absint( $field['rows'] ) : 4,
                'new_lines'        => in_array( isset( $field['new_lines'] ) ? $field['new_lines'] : '', array( 'wpautop', 'br', '' ), true ) ? ( isset( $field['new_lines'] ) ? $field['new_lines'] : '' ) : 'wpautop',
                'options'          => sanitize_textarea_field( isset( $field['options'] ) ? $field['options'] : '' ),
                'allow_null'       => ! empty( $field['allow_null'] ),
                'multiple'         => ! empty( $field['multiple'] ),
                'return_format'    => sanitize_key( isset( $field['return_format'] ) ? $field['return_format'] : 'id' ),
                'preview_size'     => sanitize_key( isset( $field['preview_size'] ) ? $field['preview_size'] : 'thumbnail' ),
                'mime'             => sanitize_text_field( isset( $field['mime'] ) ? $field['mime'] : '' ),
                'min_count'        => sanitize_text_field( isset( $field['min_count'] ) ? $field['min_count'] : '' ),
                'max_count'        => sanitize_text_field( isset( $field['max_count'] ) ? $field['max_count'] : '' ),
                'toolbar'          => in_array( isset( $field['toolbar'] ) ? $field['toolbar'] : '', array( 'full', 'basic' ), true ) ? $field['toolbar'] : 'full',
                'media_upload'     => isset( $field['media_upload'] ) ? (bool) $field['media_upload'] : true,
                'post_type'        => isset( $field['post_type'] ) && is_array( $field['post_type'] ) ? array_map( 'sanitize_key', $field['post_type'] ) : array(),
                'taxonomy_type'    => sanitize_key( isset( $field['taxonomy_type'] ) ? $field['taxonomy_type'] : '' ),
                'field_type'       => sanitize_key( isset( $field['field_type'] ) ? $field['field_type'] : 'checkbox' ),
                'save_terms'       => ! empty( $field['save_terms'] ),
                'load_terms'       => ! empty( $field['load_terms'] ),
                'role'             => isset( $field['role'] ) && is_array( $field['role'] ) ? array_map( 'sanitize_key', $field['role'] ) : array(),
                'display_format'   => sanitize_text_field( isset( $field['display_format'] ) ? $field['display_format'] : '' ),
                'return_format_dt' => sanitize_text_field( isset( $field['return_format_dt'] ) ? $field['return_format_dt'] : '' ),
                'oembed_width'     => sanitize_text_field( isset( $field['oembed_width'] ) ? $field['oembed_width'] : '' ),
                'oembed_height'    => sanitize_text_field( isset( $field['oembed_height'] ) ? $field['oembed_height'] : '' ),
                'message'          => wp_kses_post( isset( $field['message'] ) ? $field['message'] : '' ),
                'link_target'      => ! empty( $field['link_target'] ),
                // v5 new field type properties
                'heading_tag'      => in_array( isset( $field['heading_tag'] ) ? $field['heading_tag'] : '', array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $field['heading_tag'] : 'h4',
                'on_label'         => sanitize_text_field( isset( $field['on_label'] ) ? $field['on_label'] : 'On' ),
                'off_label'        => sanitize_text_field( isset( $field['off_label'] ) ? $field['off_label'] : 'Off' ),
                'html_content'     => wp_kses_post( isset( $field['html_content'] ) ? $field['html_content'] : '' ),
                'button_label'     => sanitize_text_field( isset( $field['button_label'] ) ? $field['button_label'] : 'Click' ),
                'button_class'     => sanitize_text_field( isset( $field['button_class'] ) ? $field['button_class'] : '' ),
                'image_options'    => sanitize_textarea_field( isset( $field['image_options'] ) ? $field['image_options'] : '' ),
                'image_select_multiple' => ! empty( $field['image_select_multiple'] ),
                'sub_fields'       => sanitize_textarea_field( isset( $field['sub_fields'] ) ? $field['sub_fields'] : '' ),
                'autocomplete_options' => sanitize_textarea_field( isset( $field['autocomplete_options'] ) ? $field['autocomplete_options'] : '' ),
                'admin_columns'    => ! empty( $field['admin_columns'] ),
                'tooltip'          => sanitize_text_field( isset( $field['tooltip'] ) ? $field['tooltip'] : '' ),
                'limit'            => isset( $field['limit'] ) && '' !== $field['limit'] ? absint( $field['limit'] ) : '',
                'limit_type'       => in_array( isset( $field['limit_type'] ) ? $field['limit_type'] : '', array( 'characters', 'words' ), true ) ? $field['limit_type'] : 'characters',
                'columns'          => isset( $field['columns'] ) && '' !== $field['columns'] ? max( 1, min( 12, absint( $field['columns'] ) ) ) : '',
                // v5.1 field properties
                'icon_type'        => in_array( isset( $field['icon_type'] ) ? $field['icon_type'] : '', array( 'dashicons', 'fontawesome' ), true ) ? $field['icon_type'] : 'dashicons',
                'custom_attributes' => isset( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] ) ? array_map( function( $attr ) {
                    return array(
                        'key'   => sanitize_key( isset( $attr['key'] ) ? $attr['key'] : '' ),
                        'value' => sanitize_text_field( isset( $attr['value'] ) ? $attr['value'] : '' ),
                    );
                }, $field['custom_attributes'] ) : array(),
                'js_options'       => sanitize_textarea_field( isset( $field['js_options'] ) ? $field['js_options'] : '' ),
                'save_field'       => isset( $field['save_field'] ) ? (bool) $field['save_field'] : true,
                'html_before'      => wp_kses_post( isset( $field['html_before'] ) ? $field['html_before'] : '' ),
                'html_after'       => wp_kses_post( isset( $field['html_after'] ) ? $field['html_after'] : '' ),
                'sanitize_callback' => sanitize_text_field( isset( $field['sanitize_callback'] ) ? $field['sanitize_callback'] : '' ),
                'display'           => in_array( isset( $field['display'] ) ? $field['display'] : '', array( 'vertical', 'inline' ), true ) ? $field['display'] : 'vertical',
                'label_description' => sanitize_text_field( isset( $field['label_description'] ) ? $field['label_description'] : '' ),
                'input_description' => sanitize_text_field( isset( $field['input_description'] ) ? $field['input_description'] : '' ),
            );

            return apply_filters( 'jscfr/sanitize_field', $cf, $field );
        }

        private function sanitize_id( $id, $prefix = 'jscfr' ) {
            $id = sanitize_key( $id );
            return $id ? $id : $prefix . '_' . wp_generate_password( 8, false );
        }

        private function regenerate_ids( $fg ) {
            if ( isset( $fg['tabs'] ) ) {
                foreach ( $fg['tabs'] as &$tab ) {
                    $tab['id'] = 'tab_' . wp_generate_password( 8, false );
                    if ( isset( $tab['groups'] ) ) {
                        foreach ( $tab['groups'] as &$group ) {
                            $group['id'] = 'grp_' . wp_generate_password( 8, false );
                            if ( isset( $group['fields'] ) ) {
                                foreach ( $group['fields'] as &$field ) {
                                    $field['id'] = 'fld_' . wp_generate_password( 8, false );
                                }
                            }
                        }
                    }
                }
            }
            return $fg;
        }
    }
}

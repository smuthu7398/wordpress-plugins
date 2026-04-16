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

            // Ensure theme supports post thumbnails for this CPT so the Featured Image metabox appears.
            if ( in_array( 'thumbnail', $supports, true ) ) {
                add_theme_support( 'post-thumbnails', array( $slug ) );
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
            $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
            if ( 'new' === $action ) {
                $this->render_cpt_form_page( array(), '' );
                return;
            }
            if ( 'edit' === $action ) {
                $slug   = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
                $cpts   = get_option( self::OPT_CPTS, array() );
                $config = array();
                if ( is_array( $cpts ) ) {
                    foreach ( $cpts as $c ) {
                        if ( is_array( $c ) && isset( $c['slug'] ) && $c['slug'] === $slug ) {
                            $config = $c;
                            break;
                        }
                    }
                }
                $this->render_cpt_form_page( $config, $slug );
                return;
            }

            $cpts = get_option( self::OPT_CPTS, array() );
            if ( ! is_array( $cpts ) ) $cpts = array();
            $add_url = esc_url( add_query_arg( array( 'page' => 'jscfr-post-types', 'action' => 'new' ), admin_url( 'admin.php' ) ) );
            ?>
            <div class="wrap jscfr-cpt-wrap">
                <h1><?php esc_html_e( 'Custom Post Types', 'jscfr' ); ?> <a href="<?php echo $add_url; ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'jscfr' ); ?></a></h1>

                <table class="wp-list-table widefat fixed striped" id="jscfr-cpt-list">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Slug', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Singular', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Plural', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'Public', 'jscfr' ); ?></th>
                            <th><?php esc_html_e( 'REST', 'jscfr' ); ?></th>
                            <th style="width:160px;"><?php esc_html_e( 'Actions', 'jscfr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $cpts ) ) : ?>
                            <tr class="jscfr-no-items"><td colspan="6"><?php esc_html_e( 'No custom post types registered yet.', 'jscfr' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $cpts as $cpt ) :
                                $edit_url = esc_url( add_query_arg( array( 'page' => 'jscfr-post-types', 'action' => 'edit', 'slug' => $cpt['slug'] ), admin_url( 'admin.php' ) ) );
                                ?>
                                <tr data-slug="<?php echo esc_attr( $cpt['slug'] ); ?>">
                                    <td class="jscfr-col-slug"><a href="<?php echo $edit_url; ?>" class="jscfr-slug-link"><?php echo esc_html( $cpt['slug'] ); ?></a></td>
                                    <td><?php echo esc_html( $cpt['singular'] ); ?></td>
                                    <td><?php echo esc_html( $cpt['plural'] ); ?></td>
                                    <td>
                                        <?php if ( ! empty( $cpt['public'] ) ) : ?>
                                            <span class="jscfr-badge jscfr-badge-yes"><?php esc_html_e( 'Yes', 'jscfr' ); ?></span>
                                        <?php else : ?>
                                            <span class="jscfr-badge jscfr-badge-no"><?php esc_html_e( 'No', 'jscfr' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( ! empty( $cpt['show_in_rest'] ) ) : ?>
                                            <span class="jscfr-badge jscfr-badge-yes"><?php esc_html_e( 'Yes', 'jscfr' ); ?></span>
                                        <?php else : ?>
                                            <span class="jscfr-badge jscfr-badge-no"><?php esc_html_e( 'No', 'jscfr' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="jscfr-col-actions">
                                        <a href="<?php echo $edit_url; ?>" class="button jscfr-btn-ghost"><?php esc_html_e( 'Edit', 'jscfr' ); ?></a>
                                        <button type="button" class="button jscfr-btn-ghost jscfr-btn-danger jscfr-delete-cpt"><?php esc_html_e( 'Delete', 'jscfr' ); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

        private function render_cpt_form_page( $cpt = array(), $slug = '' ) {
            $dashicons = array(
                'admin-appearance','admin-collapse','admin-comments','admin-generic','admin-home','admin-links','admin-media','admin-network','admin-page','admin-plugins','admin-post','admin-settings','admin-site-alt','admin-site-alt2','admin-site-alt3','admin-site','admin-tools','admin-users','admin-customizer','admin-multisite',
                'album','align-center','align-left','align-none','align-right','align-full-width','align-pull-left','align-pull-right','align-wide','analytics','archive','arrow-down-alt','arrow-down-alt2','arrow-down','arrow-left-alt','arrow-left-alt2','arrow-left','arrow-right-alt','arrow-right-alt2','arrow-right','arrow-up-alt','arrow-up-alt2','arrow-up','art','awards',
                'backup','bank','bell','block-default','book','book-alt','buddicons-activity','buddicons-bbpress-logo','buddicons-buddypress-logo','buddicons-community','buddicons-forums','buddicons-friends','buddicons-groups','buddicons-pm','buddicons-replies','buddicons-topics','buddicons-tracking','building','businessman','businessperson','businesswoman','button',
                'calendar-alt','calendar','camera-alt','camera','car','cart','category','chart-area','chart-bar','chart-line','chart-pie','clipboard','clock','cloud-saved','cloud-upload','cloud','code-standards','coffee','color-picker','columns','controls-back','controls-forward','controls-pause','controls-play','controls-repeat','controls-skipback','controls-skipforward','controls-volumeoff','controls-volumeon','cover-image',
                'dashboard','database-add','database-export','database-import','database-remove','database-view','database','desktop','dismiss','download',
                'edit-large','edit-page','edit','editor-aligncenter','editor-alignleft','editor-alignright','editor-bold','editor-break','editor-code','editor-contract','editor-customchar','editor-expand','editor-help','editor-indent','editor-insertmore','editor-italic','editor-justify','editor-kitchensink','editor-ltr','editor-ol-rtl','editor-ol','editor-outdent','editor-paragraph','editor-paste-text','editor-paste-word','editor-quote','editor-removeformatting','editor-rtl','editor-spellcheck','editor-strikethrough','editor-table','editor-textcolor','editor-ul','editor-underline','editor-unlink','editor-video','ellipsis','email-alt','email-alt2','email','embed-audio','embed-generic','embed-photo','embed-post','embed-video','excerpt-view','exit','external',
                'facebook-alt','facebook','feedback','filter','flag','food','format-aside','format-audio','format-chat','format-gallery','format-image','format-quote','format-status','format-video','forms','fullscreen-alt','fullscreen-exit-alt',
                'games','google','googleplus','grid-view','groups',
                'hammer','heading','heart','hidden','hourglass',
                'id-alt','id','image-crop','image-filter','image-flip-horizontal','image-flip-vertical','image-rotate-left','image-rotate-right','image-rotate','images-alt','images-alt2','index-card','info','insert-after','insert-before','insert','instagram',
                'laptop','layout','leftright','lightbulb','linkedin','list-view','location-alt','location','lock','marker','media-archive','media-audio','media-code','media-default','media-document','media-interactive','media-spreadsheet','media-text','media-video','megaphone','menu-alt','menu-alt2','menu-alt3','menu','microphone','migrate','minus','money-alt','money','move','nametag','networking','no-alt','no','open-folder',
                'palmtree','paperclip','pdf','performance','pets','phone','playlist-audio','playlist-video','plus-alt','plus-alt2','plus','portfolio','post-status','pressthis','printer','privacy','products',
                'randomize','redo','remove','rest-api','rss','saved','schedule','screenoptions','search','share-alt','share-alt2','share','shield-alt','shield','shortcode','slides','smartphone','smiley','sort','sos','star-empty','star-filled','star-half','sticky','store','superhero-alt','superhero',
                'table-col-after','table-col-before','table-col-delete','table-row-after','table-row-before','table-row-delete','tablet','tag','tagcloud','testimonial','text-page','text','thumbs-down','thumbs-up','tickets-alt','tickets','tide','translation','trash','twitter-alt','twitter','twitch',
                'undo','universal-access-alt','universal-access','unlock','update-alt','update','upload',
                'vault','video-alt','video-alt2','video-alt3','visibility',
                'warning','welcome-add-page','welcome-comments','welcome-learn-more','welcome-view-site','welcome-widgets-menus','welcome-write-blog','whatsapp','wordpress-alt','wordpress','xing','yes-alt','yes','youtube',
            );
            $dashicons = array_map( function( $i ) { return 'dashicons-' . $i; }, $dashicons );

            $is_edit  = ! empty( $slug );
            $list_url = esc_url( add_query_arg( array( 'page' => 'jscfr-post-types' ), admin_url( 'admin.php' ) ) );
            $title    = $is_edit
                ? sprintf( __( 'Edit Post Type: %s', 'jscfr' ), $slug )
                : __( 'Add New Post Type', 'jscfr' );

            $val = function( $key, $default = '' ) use ( $cpt ) {
                return isset( $cpt[ $key ] ) ? $cpt[ $key ] : $default;
            };
            $bool_checked = function( $key, $default_on ) use ( $cpt ) {
                if ( ! array_key_exists( $key, $cpt ) ) {
                    return $default_on;
                }
                return ! empty( $cpt[ $key ] );
            };

            $supports_saved = is_array( $val( 'supports', array() ) ) ? $val( 'supports', array() ) : array();
            $is_supported   = function( $k ) use ( $supports_saved, $is_edit ) {
                if ( ! $is_edit ) {
                    return in_array( $k, array( 'title', 'editor' ), true );
                }
                if ( isset( $supports_saved[ $k ] ) ) return ! empty( $supports_saved[ $k ] );
                return in_array( $k, $supports_saved, true );
            };

            $taxes_saved = is_array( $val( 'taxonomies', array() ) ) ? $val( 'taxonomies', array() ) : array();

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

            $tax_choices  = array( 'category' => __( 'Category', 'jscfr' ), 'post_tag' => __( 'Tag', 'jscfr' ) );
            $custom_taxes = get_option( self::OPT_TAXES, array() );
            if ( is_array( $custom_taxes ) ) {
                foreach ( $custom_taxes as $ct ) {
                    if ( ! empty( $ct['slug'] ) ) {
                        $tax_choices[ $ct['slug'] ] = ! empty( $ct['plural'] ) ? $ct['plural'] : $ct['slug'];
                    }
                }
            }

            $icon_val     = $val( 'menu_icon', 'dashicons-admin-post' );
            $position_val = $val( 'menu_position', 25 );
            ?>
            <div class="wrap jscfr-cpt-wrap jscfr-mb-page">
                <div class="jscfr-mb-page-header">
                    <h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
                    <a href="<?php echo $list_url; ?>" class="page-title-action"><?php esc_html_e( '← Back to Post Types', 'jscfr' ); ?></a>
                </div>
                <hr class="wp-header-end" />

                <form id="jscfr-cpt-form" class="jscfr-mb-style">
                    <input type="hidden" id="jscfr-cpt-editing" value="<?php echo esc_attr( $slug ); ?>" />

                    <div class="jscfr-mb-tabs">
                        <ul class="jscfr-mb-tab-nav" role="tablist">
                            <li class="active" data-jscfr-tab="general"><?php esc_html_e( 'General', 'jscfr' ); ?></li>
                            <li data-jscfr-tab="supports"><?php esc_html_e( 'Supports & Taxonomies', 'jscfr' ); ?></li>
                            <li data-jscfr-tab="visibility"><?php esc_html_e( 'Visibility', 'jscfr' ); ?></li>
                            <li data-jscfr-tab="advanced"><?php esc_html_e( 'Advanced', 'jscfr' ); ?></li>
                        </ul>

                        <div class="jscfr-mb-tab-panel active" data-jscfr-panel="general">
                            <div class="jscfr-mb-row">
                                <label for="jscfr-cpt-plural"><?php esc_html_e( 'Plural name', 'jscfr' ); ?><span class="jscfr-mb-req">*</span></label>
                                <div class="jscfr-mb-control"><input type="text" id="jscfr-cpt-plural" value="<?php echo esc_attr( $val( 'plural' ) ); ?>" required /></div>
                            </div>
                            <div class="jscfr-mb-row">
                                <label for="jscfr-cpt-singular"><?php esc_html_e( 'Singular name', 'jscfr' ); ?><span class="jscfr-mb-req">*</span></label>
                                <div class="jscfr-mb-control"><input type="text" id="jscfr-cpt-singular" value="<?php echo esc_attr( $val( 'singular' ) ); ?>" required /></div>
                            </div>
                            <div class="jscfr-mb-row">
                                <label for="jscfr-cpt-slug"><?php esc_html_e( 'Slug', 'jscfr' ); ?><span class="jscfr-mb-req">*</span></label>
                                <div class="jscfr-mb-control">
                                    <input type="text" id="jscfr-cpt-slug" value="<?php echo esc_attr( $slug ); ?>" maxlength="20" pattern="[a-z0-9_-]+" required <?php echo $is_edit ? 'readonly' : ''; ?> />
                                    <p class="jscfr-mb-desc"><?php esc_html_e( 'Lowercase, no spaces. Max 20 chars.', 'jscfr' ); ?></p>
                                </div>
                            </div>
                            <div class="jscfr-mb-row">
                                <label><?php esc_html_e( 'Menu Icon', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <div class="jscfr-icon-picker">
                                        <input type="hidden" id="jscfr-cpt-icon" value="<?php echo esc_attr( $icon_val ); ?>" />
                                        <div class="jscfr-icon-picker-toolbar">
                                            <div class="jscfr-icon-picker-selected">
                                                <span id="jscfr-cpt-icon-preview" class="dashicons <?php echo esc_attr( $icon_val ); ?>"></span>
                                                <code class="jscfr-icon-picker-value"><?php echo esc_html( str_replace( 'dashicons-', '', $icon_val ) ); ?></code>
                                            </div>
                                            <div class="jscfr-icon-picker-search">
                                                <span class="dashicons dashicons-search"></span>
                                                <input type="text" class="jscfr-icon-picker-search-input" placeholder="<?php esc_attr_e( 'Search icons…', 'jscfr' ); ?>" />
                                            </div>
                                        </div>
                                        <div class="jscfr-icon-picker-grid">
                                            <?php foreach ( $dashicons as $di ) :
                                                $name  = str_replace( 'dashicons-', '', $di );
                                                $label = str_replace( '-', ' ', $name );
                                                ?>
                                                <button type="button"
                                                    class="jscfr-icon-cell <?php echo $icon_val === $di ? 'active' : ''; ?>"
                                                    data-icon="<?php echo esc_attr( $di ); ?>"
                                                    data-search="<?php echo esc_attr( strtolower( $label ) ); ?>"
                                                    title="<?php echo esc_attr( $name ); ?>">
                                                    <span class="dashicons <?php echo esc_attr( $di ); ?>"></span>
                                                    <span class="jscfr-icon-cell-label"><?php echo esc_html( $label ); ?></span>
                                                </button>
                                            <?php endforeach; ?>
                                            <p class="jscfr-icon-picker-empty" hidden><?php esc_html_e( 'No icons match your search.', 'jscfr' ); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="jscfr-mb-row">
                                <label for="jscfr-cpt-position"><?php esc_html_e( 'Menu Position', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <input type="number" id="jscfr-cpt-position" value="<?php echo esc_attr( $position_val ); ?>" min="0" max="100" class="jscfr-mb-number" />
                                    <p class="jscfr-mb-desc"><?php esc_html_e( 'Menu order (0–100). Lower numbers appear higher in the admin menu.', 'jscfr' ); ?></p>
                                </div>
                            </div>
                            <div class="jscfr-mb-row jscfr-mb-row-toggle">
                                <label><?php esc_html_e( 'Public', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <label class="jscfr-toggle"><input type="checkbox" id="jscfr-cpt-public" <?php checked( $bool_checked( 'public', true ) ); ?> /><span class="jscfr-toggle-slider"></span></label>
                                    <span class="jscfr-mb-toggle-desc"><?php esc_html_e( 'Whether the post type is intended to be used publicly, visible on the front-end.', 'jscfr' ); ?></span>
                                </div>
                            </div>
                            <div class="jscfr-mb-row jscfr-mb-row-toggle">
                                <label><?php esc_html_e( 'Hierarchical', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <label class="jscfr-toggle"><input type="checkbox" id="jscfr-cpt-hierarchical" <?php checked( $bool_checked( 'hierarchical', false ) ); ?> /><span class="jscfr-toggle-slider"></span></label>
                                    <span class="jscfr-mb-toggle-desc"><?php esc_html_e( 'Whether the post type is hierarchical (e.g. like pages with parent/child).', 'jscfr' ); ?></span>
                                </div>
                            </div>
                            <div class="jscfr-mb-row jscfr-mb-row-toggle">
                                <label><?php esc_html_e( 'Has Archive', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <label class="jscfr-toggle"><input type="checkbox" id="jscfr-cpt-has-archive" <?php checked( $bool_checked( 'has_archive', true ) ); ?> /><span class="jscfr-toggle-slider"></span></label>
                                    <span class="jscfr-mb-toggle-desc"><?php esc_html_e( 'Enables a post type archive at /slug/.', 'jscfr' ); ?></span>
                                </div>
                            </div>
                            <div class="jscfr-mb-row jscfr-mb-row-toggle">
                                <label><?php esc_html_e( 'Exclude from Search', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <label class="jscfr-toggle"><input type="checkbox" id="jscfr-cpt-exclude-search" <?php checked( $bool_checked( 'exclude_from_search', false ) ); ?> /><span class="jscfr-toggle-slider"></span></label>
                                    <span class="jscfr-mb-toggle-desc"><?php esc_html_e( 'Exclude posts of this type from front-end search results.', 'jscfr' ); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="jscfr-mb-tab-panel" data-jscfr-panel="supports">
                            <div class="jscfr-mb-row">
                                <label><?php esc_html_e( 'Supports', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <div class="jscfr-mb-chips">
                                        <?php foreach ( $support_opts as $skey => $slabel ) : ?>
                                            <label class="jscfr-mb-chip">
                                                <input type="checkbox" class="jscfr-cpt-support" value="<?php echo esc_attr( $skey ); ?>" <?php checked( $is_supported( $skey ) ); ?> />
                                                <span><?php echo esc_html( $slabel ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="jscfr-mb-desc"><?php esc_html_e( 'Features available on the post editor for this post type.', 'jscfr' ); ?></p>
                                </div>
                            </div>
                            <div class="jscfr-mb-row">
                                <label><?php esc_html_e( 'Taxonomies', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control" id="jscfr-cpt-taxonomies-wrap">
                                    <div class="jscfr-mb-chips">
                                        <?php foreach ( $tax_choices as $tslug => $tlabel ) : ?>
                                            <label class="jscfr-mb-chip">
                                                <input type="checkbox" class="jscfr-cpt-tax" value="<?php echo esc_attr( $tslug ); ?>" <?php checked( in_array( $tslug, $taxes_saved, true ) ); ?> />
                                                <span><?php echo esc_html( $tlabel ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="jscfr-mb-desc"><?php esc_html_e( 'Taxonomies this post type is associated with.', 'jscfr' ); ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="jscfr-mb-tab-panel" data-jscfr-panel="visibility">
                            <?php
                            $vis_toggles = array(
                                'jscfr-cpt-publicly-queryable' => array( 'publicly_queryable', __( 'Publicly Queryable', 'jscfr' ), __( 'Whether queries can be performed on the front-end.', 'jscfr' ), true ),
                                'jscfr-cpt-show-ui'            => array( 'show_ui', __( 'Show UI', 'jscfr' ), __( 'Generate a default UI for managing this post type in the admin.', 'jscfr' ), true ),
                                'jscfr-cpt-show-in-menu'       => array( 'show_in_menu', __( 'Show in Menu', 'jscfr' ), __( 'Show the post type in the admin menu.', 'jscfr' ), true ),
                                'jscfr-cpt-show-in-nav'        => array( 'show_in_nav_menus', __( 'Show in Nav Menus', 'jscfr' ), __( 'Make this post type available for selection in navigation menus.', 'jscfr' ), true ),
                                'jscfr-cpt-show-in-admin-bar'  => array( 'show_in_admin_bar', __( 'Show in Admin Bar', 'jscfr' ), __( 'Make this post type available via the WordPress admin bar.', 'jscfr' ), true ),
                                'jscfr-cpt-show-in-rest'       => array( 'show_in_rest', __( 'Show in REST API', 'jscfr' ), __( 'Expose this post type via the REST API (required for Gutenberg).', 'jscfr' ), true ),
                            );
                            foreach ( $vis_toggles as $id => $cfg ) :
                                list( $key, $label, $desc, $default_on ) = $cfg;
                                ?>
                                <div class="jscfr-mb-row jscfr-mb-row-toggle">
                                    <label><?php echo esc_html( $label ); ?></label>
                                    <div class="jscfr-mb-control">
                                        <label class="jscfr-toggle"><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" <?php checked( $bool_checked( $key, $default_on ) ); ?> /><span class="jscfr-toggle-slider"></span></label>
                                        <span class="jscfr-mb-toggle-desc"><?php echo esc_html( $desc ); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="jscfr-mb-tab-panel" data-jscfr-panel="advanced">
                            <div class="jscfr-mb-row">
                                <label for="jscfr-cpt-rewrite-slug"><?php esc_html_e( 'Rewrite Slug', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <input type="text" id="jscfr-cpt-rewrite-slug" value="<?php echo esc_attr( $val( 'rewrite_slug' ) ); ?>" placeholder="<?php esc_attr_e( 'Leave empty to use slug', 'jscfr' ); ?>" />
                                    <p class="jscfr-mb-desc"><?php esc_html_e( 'Custom URL slug used when building permalinks. Defaults to the post type slug.', 'jscfr' ); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="jscfr-mb-footer">
                        <button type="submit" class="button button-primary button-large"><?php echo esc_html( $is_edit ? __( 'Save Changes', 'jscfr' ) : __( 'Create Post Type', 'jscfr' ) ); ?></button>
                        <a href="<?php echo $list_url; ?>" class="button button-large"><?php esc_html_e( 'Cancel', 'jscfr' ); ?></a>
                    </div>
                </form>
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
            $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
            if ( 'new' === $action ) {
                $this->render_tax_form_page( array(), '' );
                return;
            }
            if ( 'edit' === $action ) {
                $slug   = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
                $taxes  = get_option( self::OPT_TAXES, array() );
                $config = array();
                if ( is_array( $taxes ) ) {
                    foreach ( $taxes as $t ) {
                        if ( is_array( $t ) && isset( $t['slug'] ) && $t['slug'] === $slug ) {
                            $config = $t;
                            break;
                        }
                    }
                }
                $this->render_tax_form_page( $config, $slug );
                return;
            }

            $taxes = get_option( self::OPT_TAXES, array() );
            if ( ! is_array( $taxes ) ) $taxes = array();
            $add_url = esc_url( add_query_arg( array( 'page' => 'jscfr-taxonomies', 'action' => 'new' ), admin_url( 'admin.php' ) ) );
            ?>
            <div class="wrap jscfr-cpt-wrap">
                <h1><?php esc_html_e( 'Custom Taxonomies', 'jscfr' ); ?> <a href="<?php echo $add_url; ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'jscfr' ); ?></a></h1>

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
                            <?php foreach ( $taxes as $tax ) :
                                $edit_url = esc_url( add_query_arg( array( 'page' => 'jscfr-taxonomies', 'action' => 'edit', 'slug' => $tax['slug'] ), admin_url( 'admin.php' ) ) );
                                ?>
                                <tr data-slug="<?php echo esc_attr( $tax['slug'] ); ?>">
                                    <td class="jscfr-col-slug"><a href="<?php echo $edit_url; ?>" class="jscfr-slug-link"><?php echo esc_html( $tax['slug'] ); ?></a></td>
                                    <td><?php echo esc_html( $tax['singular'] ); ?></td>
                                    <td><?php echo esc_html( $tax['plural'] ); ?></td>
                                    <td>
                                        <?php if ( ! empty( $tax['post_types'] ) ) : ?>
                                            <?php foreach ( (array) $tax['post_types'] as $pt ) : ?>
                                                <span class="jscfr-pill"><?php echo esc_html( $pt ); ?></span>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <span class="jscfr-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( ! empty( $tax['hierarchical'] ) ) : ?>
                                            <span class="jscfr-badge jscfr-badge-yes"><?php esc_html_e( 'Yes', 'jscfr' ); ?></span>
                                        <?php else : ?>
                                            <span class="jscfr-badge jscfr-badge-no"><?php esc_html_e( 'No', 'jscfr' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="jscfr-col-actions">
                                        <a href="<?php echo $edit_url; ?>" class="button jscfr-btn-ghost"><?php esc_html_e( 'Edit', 'jscfr' ); ?></a>
                                        <button type="button" class="button jscfr-btn-ghost jscfr-btn-danger jscfr-delete-tax"><?php esc_html_e( 'Delete', 'jscfr' ); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

        private function render_tax_form_page( $tax = array(), $slug = '' ) {
            $all_pts    = get_post_types( array( 'show_ui' => true ), 'objects' );
            $is_edit    = ! empty( $slug );
            $list_url   = esc_url( add_query_arg( array( 'page' => 'jscfr-taxonomies' ), admin_url( 'admin.php' ) ) );
            $title      = $is_edit
                ? sprintf( __( 'Edit Taxonomy: %s', 'jscfr' ), $slug )
                : __( 'Add New Taxonomy', 'jscfr' );

            $val = function( $key, $default = '' ) use ( $tax ) {
                return isset( $tax[ $key ] ) ? $tax[ $key ] : $default;
            };
            $bool_checked = function( $key, $default_on = true ) use ( $tax ) {
                if ( ! array_key_exists( $key, $tax ) ) {
                    return $default_on;
                }
                return ! empty( $tax[ $key ] );
            };

            $selected_pts = is_array( $val( 'post_types', array() ) ) ? $val( 'post_types', array() ) : array();
            ?>
            <div class="wrap jscfr-cpt-wrap jscfr-mb-page">
                <div class="jscfr-mb-page-header">
                    <h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
                    <a href="<?php echo $list_url; ?>" class="page-title-action"><?php esc_html_e( '← Back to Taxonomies', 'jscfr' ); ?></a>
                </div>
                <hr class="wp-header-end" />

                <form id="jscfr-tax-form" class="jscfr-mb-style">
                    <input type="hidden" id="jscfr-tax-editing" value="<?php echo esc_attr( $slug ); ?>" />

                    <div class="jscfr-mb-tabs">
                        <ul class="jscfr-mb-tab-nav" role="tablist">
                            <li class="active" data-jscfr-tab="general"><?php esc_html_e( 'General', 'jscfr' ); ?></li>
                            <li data-jscfr-tab="visibility"><?php esc_html_e( 'Visibility', 'jscfr' ); ?></li>
                            <li data-jscfr-tab="advanced"><?php esc_html_e( 'Advanced', 'jscfr' ); ?></li>
                        </ul>

                        <div class="jscfr-mb-tab-panel active" data-jscfr-panel="general">
                            <div class="jscfr-mb-row">
                                <label for="jscfr-tax-plural"><?php esc_html_e( 'Plural name', 'jscfr' ); ?><span class="jscfr-mb-req">*</span></label>
                                <div class="jscfr-mb-control"><input type="text" id="jscfr-tax-plural" value="<?php echo esc_attr( $val( 'plural' ) ); ?>" required /></div>
                            </div>
                            <div class="jscfr-mb-row">
                                <label for="jscfr-tax-singular"><?php esc_html_e( 'Singular name', 'jscfr' ); ?><span class="jscfr-mb-req">*</span></label>
                                <div class="jscfr-mb-control"><input type="text" id="jscfr-tax-singular" value="<?php echo esc_attr( $val( 'singular' ) ); ?>" required /></div>
                            </div>
                            <div class="jscfr-mb-row">
                                <label for="jscfr-tax-slug"><?php esc_html_e( 'Slug', 'jscfr' ); ?><span class="jscfr-mb-req">*</span></label>
                                <div class="jscfr-mb-control">
                                    <input type="text" id="jscfr-tax-slug" value="<?php echo esc_attr( $slug ); ?>" maxlength="32" pattern="[a-z0-9_-]+" required <?php echo $is_edit ? 'readonly' : ''; ?> />
                                    <p class="jscfr-mb-desc"><?php esc_html_e( 'Lowercase, no spaces. Max 32 chars.', 'jscfr' ); ?></p>
                                </div>
                            </div>
                            <div class="jscfr-mb-row jscfr-mb-row-toggle">
                                <label><?php esc_html_e( 'Public', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <label class="jscfr-toggle">
                                        <input type="checkbox" id="jscfr-tax-public" <?php checked( $bool_checked( 'public', true ) ); ?> />
                                        <span class="jscfr-toggle-slider"></span>
                                    </label>
                                    <span class="jscfr-mb-toggle-desc"><?php esc_html_e( 'Whether a taxonomy is intended for use publicly either via the admin interface or by front-end users.', 'jscfr' ); ?></span>
                                </div>
                            </div>
                            <div class="jscfr-mb-row jscfr-mb-row-toggle">
                                <label><?php esc_html_e( 'Hierarchical', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <label class="jscfr-toggle">
                                        <input type="checkbox" id="jscfr-tax-hierarchical" <?php checked( $bool_checked( 'hierarchical', true ) ); ?> />
                                        <span class="jscfr-toggle-slider"></span>
                                    </label>
                                    <span class="jscfr-mb-toggle-desc"><?php esc_html_e( 'Whether the taxonomy is hierarchical (e.g. like category).', 'jscfr' ); ?></span>
                                </div>
                            </div>
                            <div class="jscfr-mb-row">
                                <label><?php esc_html_e( 'Associated post types', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <div class="jscfr-mb-chips">
                                        <?php foreach ( $all_pts as $pt ) : ?>
                                            <label class="jscfr-mb-chip">
                                                <input type="checkbox" class="jscfr-tax-pt" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $selected_pts, true ) ); ?> />
                                                <span><?php echo esc_html( $pt->labels->singular_name ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="jscfr-mb-tab-panel" data-jscfr-panel="visibility">
                            <?php
                            $vis_toggles = array(
                                'jscfr-tax-publicly-queryable' => array( 'publicly_queryable', __( 'Publicly Queryable', 'jscfr' ), __( 'Whether the taxonomy is publicly queryable.', 'jscfr' ), true ),
                                'jscfr-tax-show-ui'            => array( 'show_ui', __( 'Show UI', 'jscfr' ), __( 'Show a default UI for managing this taxonomy in the admin.', 'jscfr' ), true ),
                                'jscfr-tax-show-in-menu'       => array( 'show_in_menu', __( 'Show in Menu', 'jscfr' ), __( 'Show the taxonomy in the admin menu.', 'jscfr' ), true ),
                                'jscfr-tax-show-in-nav'        => array( 'show_in_nav_menus', __( 'Show in Nav Menus', 'jscfr' ), __( 'Make this taxonomy available for selection in navigation menus.', 'jscfr' ), true ),
                                'jscfr-tax-show-in-rest'       => array( 'show_in_rest', __( 'Show in REST API', 'jscfr' ), __( 'Expose this taxonomy in the REST API (required for Gutenberg).', 'jscfr' ), true ),
                                'jscfr-tax-show-admin-column'  => array( 'show_admin_column', __( 'Show Admin Column', 'jscfr' ), __( 'Display a column for the taxonomy on associated post type list tables.', 'jscfr' ), true ),
                                'jscfr-tax-show-tagcloud'      => array( 'show_tagcloud', __( 'Show Tag Cloud', 'jscfr' ), __( 'Include this taxonomy in the Tag Cloud widget controls.', 'jscfr' ), false ),
                            );
                            foreach ( $vis_toggles as $id => $cfg ) :
                                list( $key, $label, $desc, $default_on ) = $cfg;
                                ?>
                                <div class="jscfr-mb-row jscfr-mb-row-toggle">
                                    <label><?php echo esc_html( $label ); ?></label>
                                    <div class="jscfr-mb-control">
                                        <label class="jscfr-toggle">
                                            <input type="checkbox" id="<?php echo esc_attr( $id ); ?>" <?php checked( $bool_checked( $key, $default_on ) ); ?> />
                                            <span class="jscfr-toggle-slider"></span>
                                        </label>
                                        <span class="jscfr-mb-toggle-desc"><?php echo esc_html( $desc ); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="jscfr-mb-tab-panel" data-jscfr-panel="advanced">
                            <div class="jscfr-mb-row">
                                <label for="jscfr-tax-rewrite-slug"><?php esc_html_e( 'Rewrite Slug', 'jscfr' ); ?></label>
                                <div class="jscfr-mb-control">
                                    <input type="text" id="jscfr-tax-rewrite-slug" value="<?php echo esc_attr( $val( 'rewrite_slug' ) ); ?>" placeholder="<?php esc_attr_e( 'Leave empty to use slug', 'jscfr' ); ?>" />
                                    <p class="jscfr-mb-desc"><?php esc_html_e( 'Custom URL slug for taxonomy archives. Defaults to the taxonomy slug.', 'jscfr' ); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="jscfr-mb-footer">
                        <button type="submit" class="button button-primary button-large"><?php echo esc_html( $is_edit ? __( 'Save Changes', 'jscfr' ) : __( 'Create Taxonomy', 'jscfr' ) ); ?></button>
                        <a href="<?php echo $list_url; ?>" class="button button-large"><?php esc_html_e( 'Cancel', 'jscfr' ); ?></a>
                    </div>
                </form>
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

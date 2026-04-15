<?php
/**
 * Plugin Name: Odg Custom Fields Repeater
 * Plugin URI:  https://developer.suspended.suspended
 * Description: ACF-style custom fields plugin. Build Field Groups with Tabs, Groups (clonable repeater), Fields, plus location rules and presentation settings.
 * Version:     5.0.0
 * Author:      Developer
 * Author URI:  https://developer.suspended.suspended
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jscfr
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------------ */
/*  Constants                                                          */
/* ------------------------------------------------------------------ */
define( 'JSCFR_VERSION', '5.5.0' );
define( 'JSCFR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JSCFR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JSCFR_OPTION_KEY', 'jscfr_field_config' );
define( 'JSCFR_OPTIONS_DATA_KEY', 'jscfr_options_data' );
define( 'JSCFR_OPTIONS_PAGES_KEY', 'jscfr_options_pages' );
define( 'JSCFR_DB_VERSION_KEY', 'jscfr_db_version' );
define( 'JSCFR_META_KEY', '_jscfr_data' );           // Legacy v4 blob key (read-only fallback)
define( 'JSCFR_META_PREFIX', '_jscfr_' );             // v5 individual meta key prefix
define( 'JSCFR_FIELD_MAP_KEY', '_jscfr_field_group_map' ); // Maps field names → fg/tab/group/field path
define( 'JSCFR_OPT_PREFIX', 'jscfr_opt_' );           // v5 options storage prefix
define( 'JSCFR_NONCE_ACTION', 'jscfr_save_meta' );
define( 'JSCFR_NONCE_NAME', '_jscfr_nonce' );
define( 'JSCFR_BUILDER_NONCE', 'jscfr_save_builder' );
define( 'JSCFR_CACHE_GROUP', 'jscfr' );               // Object cache group
define( 'JSCFR_DB_V5', '5.0.0' );                     // Current DB version target

/* ------------------------------------------------------------------ */
/*  Includes                                                           */
/* ------------------------------------------------------------------ */
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-builder.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-metabox.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-rest.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-options-page.php';
require_once JSCFR_PLUGIN_DIR . 'includes/jscfr-helpers.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-admin-columns.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-term-meta.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-user-meta.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-comment-meta.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-revision.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-frontend-form.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-cpt.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-theme-code.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-json-sync.php';
require_once JSCFR_PLUGIN_DIR . 'includes/class-jscfr-relationships.php';

/* ------------------------------------------------------------------ */
/*  Main plugin class                                                  */
/* ------------------------------------------------------------------ */
if ( ! class_exists( 'JSCFR_Plugin' ) ) {

    final class JSCFR_Plugin {

        private static $instance = null;

        /** Field name → location index cache */
        private static $field_index = null;

        /** Field groups registered via PHP */
        private static $php_field_groups = array();

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
            add_action( 'jscfr_v5_migrate_batch', array( __CLASS__, 'migrate_v5_batch' ) );
            JSCFR_Builder::get_instance();
            JSCFR_Metabox::get_instance();
            JSCFR_REST::get_instance();
            JSCFR_Options_Page::get_instance();
            JSCFR_Admin_Columns::get_instance();
            JSCFR_Term_Meta::get_instance();
            JSCFR_User_Meta::get_instance();
            JSCFR_Comment_Meta::get_instance();
            JSCFR_Revision::get_instance();
            JSCFR_Frontend_Form::get_instance();
            JSCFR_CPT::get_instance();
            JSCFR_Theme_Code::get_instance();
            JSCFR_JSON_Sync::get_instance();
            JSCFR_Relationships::get_instance();
        }

        /* ---------------------------------------------------------- */
        /*  All supported field types                                  */
        /* ---------------------------------------------------------- */
        public static function get_field_types() {
            return array(
                'text'         => __( 'Text', 'jscfr' ),
                'textarea'     => __( 'Textarea', 'jscfr' ),
                'number'       => __( 'Number', 'jscfr' ),
                'range'        => __( 'Range (Slider)', 'jscfr' ),
                'email'        => __( 'Email', 'jscfr' ),
                'url'          => __( 'URL', 'jscfr' ),
                'password'     => __( 'Password', 'jscfr' ),
                'date'         => __( 'Date Picker', 'jscfr' ),
                'datetime'     => __( 'Date Time Picker', 'jscfr' ),
                'time'         => __( 'Time Picker', 'jscfr' ),
                'color'        => __( 'Color Picker', 'jscfr' ),
                'image'        => __( 'Image', 'jscfr' ),
                'file'         => __( 'File', 'jscfr' ),
                'gallery'      => __( 'Gallery', 'jscfr' ),
                'select'       => __( 'Select', 'jscfr' ),
                'checkbox'     => __( 'Checkbox', 'jscfr' ),
                'radio'        => __( 'Radio Button', 'jscfr' ),
                'button_group' => __( 'Button Group', 'jscfr' ),
                'true_false'   => __( 'True / False', 'jscfr' ),
                'wysiwyg'      => __( 'WYSIWYG Editor', 'jscfr' ),
                'oembed'       => __( 'oEmbed', 'jscfr' ),
                'link'         => __( 'Link', 'jscfr' ),
                'post_object'  => __( 'Post Object', 'jscfr' ),
                'relationship' => __( 'Relationship', 'jscfr' ),
                'taxonomy'     => __( 'Taxonomy', 'jscfr' ),
                'user'          => __( 'User', 'jscfr' ),
                'message'       => __( 'Message (Display Only)', 'jscfr' ),
                // v5 field types
                'hidden'        => __( 'Hidden', 'jscfr' ),
                'heading'       => __( 'Heading', 'jscfr' ),
                'divider'       => __( 'Divider', 'jscfr' ),
                'switch'        => __( 'Switch', 'jscfr' ),
                'custom_html'   => __( 'Custom HTML', 'jscfr' ),
                'button'        => __( 'Button', 'jscfr' ),
                'image_select'  => __( 'Image Select', 'jscfr' ),
                'key_value'     => __( 'Key Value', 'jscfr' ),
                'fieldset_text' => __( 'Fieldset Text', 'jscfr' ),
                'text_list'     => __( 'Text List', 'jscfr' ),
                'sidebar'       => __( 'Sidebar', 'jscfr' ),
                'single_image'  => __( 'Single Image', 'jscfr' ),
                'file_input'    => __( 'File Input', 'jscfr' ),
                'video'         => __( 'Video', 'jscfr' ),
                'background'    => __( 'Background', 'jscfr' ),
                // v5 JS-enhanced field types
                'select_advanced'   => __( 'Select Advanced', 'jscfr' ),
                'slider'            => __( 'Slider', 'jscfr' ),
                'autocomplete'      => __( 'Autocomplete', 'jscfr' ),
                'file_upload'       => __( 'File Upload', 'jscfr' ),
                'image_upload'      => __( 'Image Upload', 'jscfr' ),
                'taxonomy_advanced' => __( 'Taxonomy Advanced', 'jscfr' ),
                // v5.1 field types (Meta Box parity)
                'icon'              => __( 'Icon Picker', 'jscfr' ),
                'file_advanced'     => __( 'File Advanced', 'jscfr' ),
                'image_advanced'    => __( 'Image Advanced', 'jscfr' ),
                'google_map'        => __( 'Google Map', 'jscfr' ),
            );
        }

        /* ---------------------------------------------------------- */
        /*  Config helpers                                             */
        /* ---------------------------------------------------------- */
        public static function get_config() {
            $db     = get_option( JSCFR_OPTION_KEY, array() );
            $db     = is_array( $db ) ? $db : array();
            $merged = $db;

            // Merge PHP-registered field groups
            foreach ( self::$php_field_groups as $fg ) {
                $found = false;
                foreach ( $merged as $existing ) {
                    if ( $existing['id'] === $fg['id'] ) {
                        $found = true;
                        break;
                    }
                }
                if ( ! $found ) {
                    $merged[] = $fg;
                }
            }

            return apply_filters( 'jscfr/load_field_groups', $merged );
        }

        public static function save_config( $config ) {
            update_option( JSCFR_OPTION_KEY, $config );
            self::bust_field_index_cache();
        }

        public static function get_field_group( $fg_id ) {
            foreach ( self::get_config() as $fg ) {
                if ( isset( $fg['id'] ) && $fg['id'] === $fg_id ) {
                    return $fg;
                }
            }
            return null;
        }

        public static function save_field_group( $fg ) {
            $config = get_option( JSCFR_OPTION_KEY, array() );
            if ( ! is_array( $config ) ) {
                $config = array();
            }
            $found = false;
            foreach ( $config as $i => $existing ) {
                if ( $existing['id'] === $fg['id'] ) {
                    $config[ $i ] = $fg;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $config[] = $fg;
            }
            self::save_config( $config );
            do_action( 'jscfr/save_field_group', $fg );
        }

        public static function delete_field_group( $fg_id ) {
            $config = get_option( JSCFR_OPTION_KEY, array() );
            if ( ! is_array( $config ) ) {
                $config = array();
            }
            $config = array_values( array_filter( $config, function( $fg ) use ( $fg_id ) {
                return $fg['id'] !== $fg_id;
            } ) );
            self::save_config( $config );
            do_action( 'jscfr/delete_field_group', $fg_id );
        }

        /* ---------------------------------------------------------- */
        /*  PHP Registration API                                       */
        /* ---------------------------------------------------------- */
        public static function register_field_group( $fg ) {
            $defaults = array(
                'id'             => '',
                'title'          => '',
                'tabs'           => array(),
                'location_rules' => array(),
                'settings'       => self::default_settings(),
            );
            $fg = wp_parse_args( $fg, $defaults );
            if ( empty( $fg['id'] ) ) {
                $fg['id'] = 'fg_' . md5( serialize( $fg ) );
            }
            self::$php_field_groups[ $fg['id'] ] = $fg;
        }

        /* ---------------------------------------------------------- */
        /*  Field name index — maps field name → path (cached)         */
        /* ---------------------------------------------------------- */
        public static function build_field_index() {
            if ( null !== self::$field_index ) {
                return self::$field_index;
            }

            // Try transient cache first
            $cached = get_transient( 'jscfr_field_index' );
            if ( false !== $cached && is_array( $cached ) ) {
                self::$field_index = $cached;
                return self::$field_index;
            }

            self::$field_index = array();
            foreach ( self::get_config() as $fg ) {
                if ( empty( $fg['tabs'] ) ) {
                    continue;
                }
                foreach ( $fg['tabs'] as $tab ) {
                    if ( empty( $tab['groups'] ) ) {
                        continue;
                    }
                    foreach ( $tab['groups'] as $group ) {
                        $group_name = ! empty( $group['name'] ) ? $group['name'] : $group['id'];
                        self::$field_index[ $group_name ] = array(
                            'type'     => 'group',
                            'fg_id'    => $fg['id'],
                            'tab_id'   => $tab['id'],
                            'group_id' => $group['id'],
                        );
                        if ( empty( $group['fields'] ) ) {
                            continue;
                        }
                        foreach ( $group['fields'] as $field ) {
                            $field_name = ! empty( $field['name'] ) ? $field['name'] : $field['id'];
                            self::$field_index[ $field_name ] = array(
                                'type'     => 'field',
                                'fg_id'    => $fg['id'],
                                'tab_id'   => $tab['id'],
                                'group_id' => $group['id'],
                                'field_id' => $field['id'],
                                'field'    => $field,
                            );
                        }
                    }
                }
            }

            // Cache for 1 hour
            set_transient( 'jscfr_field_index', self::$field_index, HOUR_IN_SECONDS );
            return self::$field_index;
        }

        /**
         * Resolve a field name to its path info.
         */
        public static function resolve_field( $name ) {
            $index = self::build_field_index();
            return isset( $index[ $name ] ) ? $index[ $name ] : null;
        }

        /**
         * Bust the field index cache (called on config save).
         */
        public static function bust_field_index_cache() {
            self::$field_index = null;
            delete_transient( 'jscfr_field_index' );
        }

        /* ---------------------------------------------------------- */
        /*  v5 Storage — Individual meta rows per field                */
        /* ---------------------------------------------------------- */

        /**
         * Get a single field value from individual meta.
         * Falls back to v4 blob if v5 row not found.
         *
         * @param string     $field_name  The field name/slug.
         * @param int|string $object_id   Post ID, 'options', or null for current post.
         * @param string     $object_type 'post', 'term', 'user', 'comment', 'options'.
         * @return mixed
         */
        public static function get_field_value( $field_name, $object_id = null, $object_type = 'post' ) {
            if ( 'options' === $object_id || 'options' === $object_type ) {
                $key = JSCFR_OPT_PREFIX . $field_name;
                $val = get_option( $key, null );
                if ( null !== $val ) {
                    return $val;
                }
                // Fallback: try old options blob
                $old = get_option( JSCFR_OPTIONS_DATA_KEY, array() );
                if ( is_array( $old ) ) {
                    return self::find_field_in_blob( $field_name, $old );
                }
                return '';
            }

            if ( ! $object_id && 'post' === $object_type ) {
                $object_id = get_the_ID();
            }
            if ( ! $object_id ) {
                return '';
            }

            $meta_key = JSCFR_META_PREFIX . $field_name;

            switch ( $object_type ) {
                case 'term':
                    $val = get_term_meta( $object_id, $meta_key, true );
                    break;
                case 'user':
                    $val = get_user_meta( $object_id, $meta_key, true );
                    break;
                case 'comment':
                    $val = get_comment_meta( $object_id, $meta_key, true );
                    break;
                default: // post
                    $val = get_post_meta( $object_id, $meta_key, true );
            }

            // If we got a value (including empty string that was explicitly saved), return it
            if ( '' !== $val && false !== $val ) {
                return $val;
            }

            // Check if this meta key exists at all (could be explicitly saved as empty)
            if ( 'post' === $object_type ) {
                $exists = metadata_exists( 'post', $object_id, $meta_key );
                if ( $exists ) {
                    return $val;
                }
            }

            // Fallback: read from v4 blob
            if ( 'post' === $object_type ) {
                $blob = get_post_meta( $object_id, JSCFR_META_KEY, true );
                if ( is_array( $blob ) && ! empty( $blob ) ) {
                    return self::find_field_in_blob( $field_name, $blob );
                }
            }

            return '';
        }

        /**
         * Set a single field value as individual meta.
         */
        public static function set_field_value( $field_name, $value, $object_id, $object_type = 'post' ) {
            if ( 'options' === $object_id || 'options' === $object_type ) {
                $key = JSCFR_OPT_PREFIX . $field_name;
                update_option( $key, $value, false ); // autoload = false
                return;
            }

            $meta_key = JSCFR_META_PREFIX . $field_name;

            switch ( $object_type ) {
                case 'term':
                    update_term_meta( $object_id, $meta_key, $value );
                    break;
                case 'user':
                    update_user_meta( $object_id, $meta_key, $value );
                    break;
                case 'comment':
                    update_comment_meta( $object_id, $meta_key, $value );
                    break;
                default:
                    update_post_meta( $object_id, $meta_key, $value );
            }
        }

        /**
         * Delete a single field value.
         */
        public static function delete_field_value( $field_name, $object_id, $object_type = 'post' ) {
            if ( 'options' === $object_id || 'options' === $object_type ) {
                delete_option( JSCFR_OPT_PREFIX . $field_name );
                return;
            }

            $meta_key = JSCFR_META_PREFIX . $field_name;

            switch ( $object_type ) {
                case 'term':
                    delete_term_meta( $object_id, $meta_key );
                    break;
                case 'user':
                    delete_user_meta( $object_id, $meta_key );
                    break;
                case 'comment':
                    delete_comment_meta( $object_id, $meta_key );
                    break;
                default:
                    delete_post_meta( $object_id, $meta_key );
            }
        }

        /**
         * Get all JSCFR field values for an object as a flat name→value array.
         */
        public static function get_all_field_values( $object_id = null, $object_type = 'post' ) {
            if ( 'options' === $object_id || 'options' === $object_type ) {
                return self::get_all_option_values();
            }

            if ( ! $object_id && 'post' === $object_type ) {
                $object_id = get_the_ID();
            }
            if ( ! $object_id ) {
                return array();
            }

            $prefix = JSCFR_META_PREFIX;
            $len    = strlen( $prefix );
            $out    = array();

            switch ( $object_type ) {
                case 'term':
                    $all = get_term_meta( $object_id );
                    break;
                case 'user':
                    $all = get_user_meta( $object_id );
                    break;
                case 'comment':
                    $all = get_comment_meta( $object_id );
                    break;
                default:
                    $all = get_post_meta( $object_id );
            }

            if ( ! is_array( $all ) ) {
                return array();
            }

            foreach ( $all as $key => $vals ) {
                if ( 0 === strpos( $key, $prefix ) && JSCFR_FIELD_MAP_KEY !== $key ) {
                    $name = substr( $key, $len );
                    $out[ $name ] = maybe_unserialize( $vals[0] );
                }
            }

            // If empty, try v4 blob fallback
            if ( empty( $out ) && 'post' === $object_type ) {
                $blob = get_post_meta( $object_id, JSCFR_META_KEY, true );
                if ( is_array( $blob ) && ! empty( $blob ) ) {
                    return self::flatten_blob( $blob );
                }
            }

            return $out;
        }

        /**
         * Get all option field values.
         */
        private static function get_all_option_values() {
            global $wpdb;
            $prefix = JSCFR_OPT_PREFIX;
            $len    = strlen( $prefix );
            $out    = array();

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like( $prefix ) . '%'
                )
            );

            foreach ( $rows as $row ) {
                $name = substr( $row->option_name, $len );
                $out[ $name ] = maybe_unserialize( $row->option_value );
            }

            // Fallback to old blob
            if ( empty( $out ) ) {
                $blob = get_option( JSCFR_OPTIONS_DATA_KEY, array() );
                if ( is_array( $blob ) && ! empty( $blob ) ) {
                    return self::flatten_blob( $blob );
                }
            }

            return $out;
        }

        /**
         * Search for a field value inside a v4 blob by field name.
         */
        private static function find_field_in_blob( $field_name, $blob ) {
            $info = self::resolve_field( $field_name );
            if ( ! $info ) {
                return '';
            }

            if ( 'group' === $info['type'] ) {
                return isset( $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ] )
                    ? $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ]
                    : array();
            }

            // Return first clone row value
            $rows = isset( $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ] )
                ? $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ]
                : array();

            if ( ! empty( $rows ) && isset( $rows[0][ $info['field_id'] ] ) ) {
                return $rows[0][ $info['field_id'] ];
            }

            return '';
        }

        /**
         * Flatten a v4 blob into a name→value array (for backward compat).
         */
        public static function flatten_blob( $blob ) {
            $out   = array();
            $index = self::build_field_index();

            foreach ( $index as $name => $info ) {
                if ( 'group' === $info['type'] ) {
                    $rows = isset( $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ] )
                        ? $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ]
                        : array();
                    if ( ! empty( $rows ) ) {
                        $out[ $name ] = $rows;
                    }
                } elseif ( isset( $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ] ) ) {
                    $rows = $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ];
                    if ( ! empty( $rows[0][ $info['field_id'] ] ) ) {
                        $out[ $name ] = $rows[0][ $info['field_id'] ];
                    }
                }
            }

            return $out;
        }

        /* ---------------------------------------------------------- */
        /*  Default settings                                           */
        /* ---------------------------------------------------------- */
        public static function default_settings() {
            return array(
                'position'        => 'normal',
                'style'           => 'default',
                'label_placement' => 'top',
                'tab_placement'   => 'top',
                'active'          => true,
                'description'     => '',
                'order'           => 0,
                'include'         => '',
                'exclude'         => '',
                'revision'        => false,
            );
        }

        /**
         * Default field properties.
         */
        public static function default_field() {
            return array(
                'id'               => '',
                'name'             => '',
                'label'            => '',
                'type'             => 'text',
                'instructions'     => '',
                'required'         => false,
                'default_value'    => '',
                'placeholder'      => '',
                'wrapper'          => array( 'width' => '', 'class' => '', 'id' => '' ),
                'conditional_logic' => array(),
                // Text / Number / Range
                'prepend'          => '',
                'append'           => '',
                'maxlength'        => '',
                'min'              => '',
                'max'              => '',
                'step'             => '',
                // Textarea
                'rows'             => 4,
                'new_lines'        => 'wpautop',
                // Select / Radio / Checkbox / Button Group
                'options'          => '',
                'allow_null'       => false,
                'multiple'         => false,
                // Image / File / Gallery
                'return_format'    => 'id',
                'preview_size'     => 'thumbnail',
                'mime'             => '',
                'min_count'        => '',
                'max_count'        => '',
                // WYSIWYG
                'toolbar'          => 'full',
                'media_upload'     => true,
                // Post Object / Relationship
                'post_type'        => array(),
                'filters'          => array( 'search' ),
                // Taxonomy
                'taxonomy_type'    => '',
                'field_type'       => 'checkbox',
                'save_terms'       => false,
                'load_terms'       => false,
                // User
                'role'             => array(),
                // Date / DateTime / Time
                'display_format'   => '',
                'return_format_dt' => '',
                // oEmbed
                'oembed_width'     => '',
                'oembed_height'    => '',
                // Message
                'message'          => '',
                // Link
                'link_target'      => false,
                // v5: Heading
                'heading_tag'      => 'h4',
                // v5: Switch
                'on_label'         => 'On',
                'off_label'        => 'Off',
                // v5: Custom HTML
                'html_content'     => '',
                // v5: Button
                'button_label'     => 'Click',
                'button_class'     => '',
                // v5: Image Select
                'image_options'    => '',
                'image_select_multiple' => false,
                // v5: Fieldset Text / Text List
                'sub_fields'       => '',
                // v5: Background
                'bg_color'         => true,
                'bg_image'         => true,
                'bg_repeat'        => true,
                'bg_position'      => true,
                'bg_size'          => true,
                'bg_attachment'    => true,
                // v5: Autocomplete
                'autocomplete_options' => '',
                // v5: Admin Columns
                'admin_columns'    => false,
                // v5: Tooltip
                'tooltip'          => '',
                // v5: Text Limiter
                'limit'            => '',
                'limit_type'       => 'characters',
                // v5: Columns Layout
                'columns'          => '',
            );
        }

        /* ---------------------------------------------------------- */
        /*  Location rule evaluation                                   */
        /* ---------------------------------------------------------- */

        public static function get_field_groups_for_post( $post ) {
            if ( is_numeric( $post ) ) {
                $post = get_post( $post );
            }
            if ( ! $post ) {
                return array();
            }

            $matched = array();
            foreach ( self::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) {
                    continue;
                }

                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                if ( empty( $rules ) || self::evaluate_location_rules( $rules, $post ) ) {
                    // Include/Exclude check
                    $settings = isset( $fg['settings'] ) ? $fg['settings'] : array();
                    if ( ! empty( $settings['include'] ) ) {
                        $include_ids = array_map( 'absint', array_filter( explode( ',', $settings['include'] ) ) );
                        if ( ! in_array( $post->ID, $include_ids, true ) ) {
                            continue;
                        }
                    }
                    if ( ! empty( $settings['exclude'] ) ) {
                        $exclude_ids = array_map( 'absint', array_filter( explode( ',', $settings['exclude'] ) ) );
                        if ( in_array( $post->ID, $exclude_ids, true ) ) {
                            continue;
                        }
                    }

                    $fg = apply_filters( 'jscfr/load_field_group', $fg );
                    $matched[] = $fg;
                }
            }

            usort( $matched, function( $a, $b ) {
                $oa = isset( $a['settings']['order'] ) ? intval( $a['settings']['order'] ) : 0;
                $ob = isset( $b['settings']['order'] ) ? intval( $b['settings']['order'] ) : 0;
                return $oa - $ob;
            } );

            return $matched;
        }

        public static function get_field_groups_for_post_type( $post_type ) {
            $matched = array();
            foreach ( self::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) {
                    continue;
                }

                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                if ( empty( $rules ) ) {
                    $matched[] = $fg;
                    continue;
                }

                foreach ( $rules as $or_group ) {
                    $group_match = true;
                    foreach ( $or_group as $rule ) {
                        if ( 'post_type' === $rule['param'] ) {
                            if ( 'is_equal_to' === $rule['operator'] && $rule['value'] !== $post_type ) {
                                $group_match = false;
                                break;
                            }
                            if ( 'is_not_equal_to' === $rule['operator'] && $rule['value'] === $post_type ) {
                                $group_match = false;
                                break;
                            }
                        }
                        if ( 'user_role' === $rule['param'] ) {
                            $roles = wp_get_current_user()->roles;
                            if ( 'is_equal_to' === $rule['operator'] && ! in_array( $rule['value'], $roles, true ) ) {
                                $group_match = false;
                                break;
                            }
                            if ( 'is_not_equal_to' === $rule['operator'] && in_array( $rule['value'], $roles, true ) ) {
                                $group_match = false;
                                break;
                            }
                        }
                    }
                    if ( $group_match ) {
                        $matched[] = $fg;
                        break;
                    }
                }
            }

            usort( $matched, function( $a, $b ) {
                $oa = isset( $a['settings']['order'] ) ? intval( $a['settings']['order'] ) : 0;
                $ob = isset( $b['settings']['order'] ) ? intval( $b['settings']['order'] ) : 0;
                return $oa - $ob;
            } );

            return $matched;
        }

        public static function evaluate_location_rules( $rules, $post ) {
            foreach ( $rules as $or_group ) {
                if ( ! is_array( $or_group ) || empty( $or_group ) ) {
                    continue;
                }
                $all_match = true;
                foreach ( $or_group as $rule ) {
                    if ( ! self::evaluate_single_rule( $rule, $post ) ) {
                        $all_match = false;
                        break;
                    }
                }
                if ( $all_match ) {
                    return true;
                }
            }
            return false;
        }

        private static function evaluate_single_rule( $rule, $post ) {
            $param    = isset( $rule['param'] ) ? $rule['param'] : '';
            $operator = isset( $rule['operator'] ) ? $rule['operator'] : 'is_equal_to';
            $value    = isset( $rule['value'] ) ? $rule['value'] : '';
            $actual   = '';

            switch ( $param ) {
                case 'post_type':
                    $actual = $post->post_type;
                    break;

                case 'post':
                    $actual = (string) $post->ID;
                    break;

                case 'page_template':
                    $actual = get_page_template_slug( $post->ID );
                    if ( ! $actual ) {
                        $actual = 'default';
                    }
                    break;

                case 'post_category':
                    $cats = wp_get_post_categories( $post->ID, array( 'fields' => 'slugs' ) );
                    return 'is_equal_to' === $operator
                        ? in_array( $value, $cats, true )
                        : ! in_array( $value, $cats, true );

                case 'post_format':
                    $actual = get_post_format( $post->ID );
                    if ( ! $actual ) {
                        $actual = 'standard';
                    }
                    break;

                case 'user_role':
                    $roles = wp_get_current_user()->roles;
                    return 'is_equal_to' === $operator
                        ? in_array( $value, $roles, true )
                        : ! in_array( $value, $roles, true );

                case 'post_status':
                    $actual = $post->post_status;
                    break;

                case 'post_taxonomy':
                    $parts = explode( ':', $value, 2 );
                    if ( count( $parts ) !== 2 ) {
                        return true;
                    }
                    $has = has_term( $parts[1], $parts[0], $post->ID );
                    return 'is_equal_to' === $operator ? (bool) $has : ! $has;

                case 'options_page':
                case 'taxonomy_term':
                case 'comment':
                    /* These rules are evaluated by their own classes,
                       never by the post-context evaluator. */
                    return false;

                default:
                    return apply_filters( 'jscfr/evaluate_rule', true, $rule, $post );
            }

            return 'is_equal_to' === $operator
                ? $actual === $value
                : $actual !== $value;
        }

        /* ---------------------------------------------------------- */
        /*  Migration v2/v3 → v4                                       */
        /* ---------------------------------------------------------- */
        public function maybe_migrate() {
            $db_version = get_option( JSCFR_DB_VERSION_KEY, '0' );

            // --- v2/v3 → v4 migration (config structure) ---
            if ( version_compare( $db_version, '4.0.0', '<' ) ) {
                $old_config = get_option( JSCFR_OPTION_KEY, array() );
                if ( empty( $old_config ) || ! is_array( $old_config ) ) {
                    update_option( JSCFR_DB_VERSION_KEY, '4.0.0' );
                    $db_version = '4.0.0';
                } elseif ( isset( $old_config[0]['location_rules'] ) || isset( $old_config[0]['title'] ) ) {
                    // v3 → v4: add name fields
                    foreach ( $old_config as &$fg ) {
                        if ( ! empty( $fg['tabs'] ) ) {
                            foreach ( $fg['tabs'] as &$tab ) {
                                if ( empty( $tab['name'] ) ) {
                                    $tab['name'] = sanitize_title( ! empty( $tab['label'] ) ? $tab['label'] : $tab['id'] );
                                }
                                if ( ! empty( $tab['groups'] ) ) {
                                    foreach ( $tab['groups'] as &$group ) {
                                        if ( empty( $group['name'] ) ) {
                                            $group['name'] = sanitize_title( ! empty( $group['label'] ) ? $group['label'] : $group['id'] );
                                        }
                                        if ( ! empty( $group['fields'] ) ) {
                                            foreach ( $group['fields'] as &$field ) {
                                                if ( empty( $field['name'] ) ) {
                                                    $field['name'] = sanitize_title( ! empty( $field['label'] ) ? $field['label'] : $field['id'] );
                                                }
                                                if ( ! isset( $field['instructions'] ) )     $field['instructions'] = '';
                                                if ( ! isset( $field['default_value'] ) )     $field['default_value'] = '';
                                                if ( ! isset( $field['wrapper'] ) )           $field['wrapper'] = array( 'width' => '', 'class' => '', 'id' => '' );
                                                if ( ! isset( $field['conditional_logic'] ) ) $field['conditional_logic'] = array();
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    unset( $fg, $tab, $group, $field );
                    self::save_config( $old_config );
                    update_option( JSCFR_DB_VERSION_KEY, '4.0.0' );
                    $db_version = '4.0.0';
                } else {
                    // v2 → v4 migration (old tab-based format)
                    $groups_by_pt = array();
                    foreach ( $old_config as $tab ) {
                        $pts = isset( $tab['post_types'] ) ? $tab['post_types'] : array();
                        sort( $pts );
                        $key = implode( ',', $pts );
                        if ( ! isset( $groups_by_pt[ $key ] ) ) {
                            $groups_by_pt[ $key ] = array( 'post_types' => $pts, 'tabs' => array() );
                        }
                        $clean_tab = $tab;
                        unset( $clean_tab['post_types'] );
                        $groups_by_pt[ $key ]['tabs'][] = $clean_tab;
                    }

                    $new_config = array();
                    $order = 0;
                    foreach ( $groups_by_pt as $group_data ) {
                        $rules = array();
                        foreach ( $group_data['post_types'] as $pt ) {
                            $rules[] = array(
                                array( 'param' => 'post_type', 'operator' => 'is_equal_to', 'value' => $pt ),
                            );
                        }
                        $title = 'Custom Fields';
                        if ( ! empty( $group_data['tabs'][0]['label'] ) ) {
                            $title = $group_data['tabs'][0]['label'];
                        }
                        $new_config[] = array(
                            'id'             => 'fg_' . wp_generate_password( 8, false ),
                            'title'          => $title,
                            'tabs'           => $group_data['tabs'],
                            'location_rules' => $rules,
                            'settings'       => array(
                                'position'        => 'normal',
                                'style'           => 'default',
                                'label_placement' => 'top',
                                'active'          => true,
                                'description'     => '',
                                'order'           => $order++,
                            ),
                        );
                    }

                    self::save_config( $new_config );
                    update_option( JSCFR_DB_VERSION_KEY, '4.0.0' );
                    $db_version = '4.0.0';
                }
            }

            // --- v4 → v5 migration (blob → individual meta rows) ---
            if ( version_compare( $db_version, JSCFR_DB_V5, '<' ) ) {
                // Schedule background migration if not already running
                if ( ! get_option( 'jscfr_v5_migration_running' ) ) {
                    update_option( 'jscfr_v5_migration_running', 1, false );
                    update_option( 'jscfr_v5_migration_offset', 0, false );
                    if ( ! wp_next_scheduled( 'jscfr_v5_migrate_batch' ) ) {
                        wp_schedule_single_event( time(), 'jscfr_v5_migrate_batch' );
                    }
                }
            }
        }

        /**
         * WP-Cron handler: migrate v4 blob data to v5 individual meta rows.
         * Processes 50 posts per batch to avoid timeouts.
         */
        public static function migrate_v5_batch() {
            $offset     = (int) get_option( 'jscfr_v5_migration_offset', 0 );
            $batch_size = 50;

            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $post_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC LIMIT %d OFFSET %d",
                JSCFR_META_KEY,
                $batch_size,
                $offset
            ) );

            if ( empty( $post_ids ) ) {
                // Also migrate options data
                self::migrate_v5_options();

                // Migration complete
                update_option( JSCFR_DB_VERSION_KEY, JSCFR_DB_V5 );
                delete_option( 'jscfr_v5_migration_running' );
                delete_option( 'jscfr_v5_migration_offset' );
                return;
            }

            $index = self::build_field_index();

            foreach ( $post_ids as $post_id ) {
                $post_id = (int) $post_id;
                $blob = get_post_meta( $post_id, JSCFR_META_KEY, true );
                if ( ! is_array( $blob ) || empty( $blob ) ) {
                    continue;
                }

                // Write individual meta rows from blob
                foreach ( $index as $name => $info ) {
                    if ( 'group' === $info['type'] ) {
                        $rows = isset( $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ] )
                            ? $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ]
                            : array();
                        if ( ! empty( $rows ) ) {
                            update_post_meta( $post_id, JSCFR_META_PREFIX . $name, $rows );
                        }
                    } elseif ( 'field' === $info['type'] ) {
                        $rows = isset( $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ] )
                            ? $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ]
                            : array();
                        if ( ! empty( $rows[0] ) && isset( $rows[0][ $info['field_id'] ] ) ) {
                            $val = $rows[0][ $info['field_id'] ];
                            if ( '' !== $val && null !== $val ) {
                                update_post_meta( $post_id, JSCFR_META_PREFIX . $name, $val );
                            }
                        }
                    }
                }

                // Store field group map on this post
                $map = array();
                foreach ( $index as $name => $info ) {
                    $entry = array(
                        'type'     => $info['type'],
                        'fg_id'    => $info['fg_id'],
                        'tab_id'   => $info['tab_id'],
                        'group_id' => $info['group_id'],
                    );
                    if ( isset( $info['field_id'] ) ) {
                        $entry['field_id'] = $info['field_id'];
                    }
                    $map[ $name ] = $entry;
                }
                update_post_meta( $post_id, JSCFR_FIELD_MAP_KEY, $map );

                // Backup old blob (kept for safety)
                update_post_meta( $post_id, '_jscfr_data_v4_backup', $blob );
            }

            // Schedule next batch
            update_option( 'jscfr_v5_migration_offset', $offset + $batch_size, false );
            wp_schedule_single_event( time() + 5, 'jscfr_v5_migrate_batch' );
        }

        /**
         * Migrate v4 options blob to individual option keys.
         */
        private static function migrate_v5_options() {
            $blob = get_option( JSCFR_OPTIONS_DATA_KEY, array() );
            if ( ! is_array( $blob ) || empty( $blob ) ) {
                return;
            }

            $flat = self::flatten_blob( $blob );
            foreach ( $flat as $name => $value ) {
                update_option( JSCFR_OPT_PREFIX . $name, $value, false );
            }

            // Backup old blob
            update_option( 'jscfr_options_data_v4_backup', $blob, false );
        }
    }

    JSCFR_Plugin::get_instance();
}

/* ------------------------------------------------------------------ */
/*  Global registration function (like acf_add_local_field_group)      */
/* ------------------------------------------------------------------ */
if ( ! function_exists( 'jscfr_register_field_group' ) ) {
    function jscfr_register_field_group( $fg ) {
        JSCFR_Plugin::register_field_group( $fg );
    }
}

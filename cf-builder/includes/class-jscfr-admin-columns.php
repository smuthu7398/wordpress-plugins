<?php
/**
 * JSCFR Admin Columns — Show custom field values in post list table.
 * Fields with `admin_columns` setting enabled appear as sortable, searchable columns.
 * All hooks/classes prefixed with jscfr_ to avoid conflicts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_Admin_Columns' ) ) {

    final class JSCFR_Admin_Columns {

        private static $instance = null;

        /** Cached columns config: post_type => array of column defs */
        private static $columns_cache = null;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'admin_init', array( $this, 'register_column_hooks' ) );
        }

        /* ---------------------------------------------------------- */
        /*  Register hooks for each post type that has admin columns   */
        /* ---------------------------------------------------------- */
        public function register_column_hooks() {
            $columns = self::get_columns_config();
            if ( empty( $columns ) ) {
                return;
            }

            foreach ( $columns as $post_type => $col_defs ) {
                // Add columns
                add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_columns' ) );
                // Render column content
                add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
                // Sortable columns
                add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'sortable_columns' ) );
            }

            // Handle sorting via pre_get_posts
            add_action( 'pre_get_posts', array( $this, 'handle_sort' ) );
        }

        /* ---------------------------------------------------------- */
        /*  Build columns config from field groups                     */
        /* ---------------------------------------------------------- */
        public static function get_columns_config() {
            if ( null !== self::$columns_cache ) {
                return self::$columns_cache;
            }

            self::$columns_cache = array();
            $config = JSCFR_Plugin::get_config();

            foreach ( $config as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) {
                    continue;
                }
                if ( empty( $fg['tabs'] ) || empty( $fg['location_rules'] ) ) {
                    continue;
                }

                // Determine post types from location rules
                $post_types = self::extract_post_types( $fg['location_rules'] );

                foreach ( $fg['tabs'] as $tab ) {
                    if ( empty( $tab['groups'] ) ) continue;
                    foreach ( $tab['groups'] as $group ) {
                        if ( empty( $group['fields'] ) ) continue;
                        foreach ( $group['fields'] as $field ) {
                            if ( empty( $field['admin_columns'] ) ) continue;

                            $field_name = ! empty( $field['name'] ) ? $field['name'] : $field['id'];
                            $col_key    = 'jscfr_' . $field_name;
                            $col_config = is_array( $field['admin_columns'] ) ? $field['admin_columns'] : array();

                            $col_def = array(
                                'key'        => $col_key,
                                'meta_key'   => JSCFR_META_PREFIX . $field_name,
                                'title'      => isset( $col_config['title'] ) ? $col_config['title'] : $field['label'],
                                'sortable'   => isset( $col_config['sort'] ) ? (bool) $col_config['sort'] : true,
                                'searchable' => isset( $col_config['searchable'] ) ? (bool) $col_config['searchable'] : false,
                                'width'      => isset( $col_config['width'] ) ? $col_config['width'] : '',
                                'field_type' => $field['type'],
                                'field'      => $field,
                            );

                            foreach ( $post_types as $pt ) {
                                if ( ! isset( self::$columns_cache[ $pt ] ) ) {
                                    self::$columns_cache[ $pt ] = array();
                                }
                                self::$columns_cache[ $pt ][ $col_key ] = $col_def;
                            }
                        }
                    }
                }
            }

            return self::$columns_cache;
        }

        /**
         * Extract post types from location rules (OR groups of AND rules).
         */
        private static function extract_post_types( $rules ) {
            $types = array();
            foreach ( $rules as $or_group ) {
                foreach ( $or_group as $rule ) {
                    if ( 'post_type' === ( isset( $rule['param'] ) ? $rule['param'] : '' )
                        && 'is_equal_to' === ( isset( $rule['operator'] ) ? $rule['operator'] : '' )
                        && ! empty( $rule['value'] )
                    ) {
                        $types[] = $rule['value'];
                    }
                }
            }
            return array_unique( $types );
        }

        /* ---------------------------------------------------------- */
        /*  Add columns to post list table                             */
        /* ---------------------------------------------------------- */
        public function add_columns( $columns ) {
            $screen = get_current_screen();
            if ( ! $screen ) return $columns;

            $post_type  = $screen->post_type;
            $all_cols   = self::get_columns_config();
            $col_defs   = isset( $all_cols[ $post_type ] ) ? $all_cols[ $post_type ] : array();

            foreach ( $col_defs as $key => $def ) {
                $columns[ $key ] = esc_html( $def['title'] );
            }

            return $columns;
        }

        /* ---------------------------------------------------------- */
        /*  Render column value                                        */
        /* ---------------------------------------------------------- */
        public function render_column( $column, $post_id ) {
            $screen = get_current_screen();
            if ( ! $screen ) return;

            $post_type = $screen->post_type;
            $all_cols  = self::get_columns_config();
            $col_defs  = isset( $all_cols[ $post_type ] ) ? $all_cols[ $post_type ] : array();

            if ( ! isset( $col_defs[ $column ] ) ) return;

            $def   = $col_defs[ $column ];
            $value = get_post_meta( $post_id, $def['meta_key'], true );

            if ( '' === $value || null === $value ) {
                echo '<span class="jscfr-col-empty">&mdash;</span>';
                return;
            }

            // Format based on field type
            switch ( $def['field_type'] ) {
                case 'image':
                case 'single_image':
                case 'image_upload':
                    $img = wp_get_attachment_image_src( absint( $value ), 'thumbnail' );
                    if ( $img ) {
                        echo '<img src="' . esc_url( $img[0] ) . '" alt="" style="max-width:40px;max-height:40px;border-radius:4px;" />';
                    } else {
                        echo '<span class="jscfr-col-empty">&mdash;</span>';
                    }
                    break;

                case 'true_false':
                case 'switch':
                    echo $value ? '<span class="dashicons dashicons-yes-alt" style="color:#22c55e;"></span>' : '<span class="dashicons dashicons-minus" style="color:#94a3b8;"></span>';
                    break;

                case 'color':
                    echo '<span style="display:inline-block;width:20px;height:20px;border-radius:4px;background:' . esc_attr( $value ) . ';border:1px solid #e2e8f0;vertical-align:middle;"></span> ' . esc_html( $value );
                    break;

                case 'file':
                case 'file_upload':
                case 'video':
                    $fname = basename( get_attached_file( absint( $value ) ) ?: '' );
                    echo $fname ? esc_html( $fname ) : '<span class="jscfr-col-empty">&mdash;</span>';
                    break;

                case 'post_object':
                    $p = get_post( absint( $value ) );
                    echo $p ? esc_html( $p->post_title ) : '<span class="jscfr-col-empty">&mdash;</span>';
                    break;

                case 'taxonomy':
                case 'taxonomy_advanced':
                    if ( is_array( $value ) ) {
                        $term_names = array();
                        foreach ( $value as $tid ) {
                            $t = get_term( absint( $tid ) );
                            if ( $t && ! is_wp_error( $t ) ) $term_names[] = $t->name;
                        }
                        echo esc_html( implode( ', ', $term_names ) );
                    } else {
                        echo esc_html( $value );
                    }
                    break;

                default:
                    if ( is_array( $value ) ) {
                        echo esc_html( wp_json_encode( $value ) );
                    } else {
                        $display = wp_trim_words( $value, 10, '...' );
                        echo esc_html( $display );
                    }
            }
        }

        /* ---------------------------------------------------------- */
        /*  Mark columns as sortable                                   */
        /* ---------------------------------------------------------- */
        public function sortable_columns( $columns ) {
            $screen = get_current_screen();
            if ( ! $screen ) return $columns;

            $post_type = $screen->post_type;
            $all_cols  = self::get_columns_config();
            $col_defs  = isset( $all_cols[ $post_type ] ) ? $all_cols[ $post_type ] : array();

            foreach ( $col_defs as $key => $def ) {
                if ( ! empty( $def['sortable'] ) ) {
                    $columns[ $key ] = $key;
                }
            }

            return $columns;
        }

        /* ---------------------------------------------------------- */
        /*  Handle sort by meta_value in pre_get_posts                 */
        /* ---------------------------------------------------------- */
        public function handle_sort( $query ) {
            if ( ! is_admin() || ! $query->is_main_query() ) {
                return;
            }

            $orderby = $query->get( 'orderby' );
            if ( ! $orderby || 0 !== strpos( $orderby, 'jscfr_' ) ) {
                return;
            }

            $post_type = $query->get( 'post_type' );
            $all_cols  = self::get_columns_config();
            $col_defs  = isset( $all_cols[ $post_type ] ) ? $all_cols[ $post_type ] : array();

            if ( ! isset( $col_defs[ $orderby ] ) ) {
                return;
            }

            $def = $col_defs[ $orderby ];
            $query->set( 'meta_key', $def['meta_key'] );

            // Use numeric sort for number-like fields
            $numeric_types = array( 'number', 'range', 'slider' );
            if ( in_array( $def['field_type'], $numeric_types, true ) ) {
                $query->set( 'orderby', 'meta_value_num' );
            } else {
                $query->set( 'orderby', 'meta_value' );
            }
        }
    }
}

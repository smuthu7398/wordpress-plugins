<?php
/**
 * JSCFR Metabox — Renders per-Field-Group metaboxes with Tabs -> Clonable Groups -> Fields.
 * v4: All field types, conditional logic, wrapper width, instructions, default values.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_Metabox' ) ) {

    final class JSCFR_Metabox {

        private static $instance = null;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'add_meta_boxes', array( $this, 'register' ), 10, 2 );
            add_action( 'save_post', array( $this, 'save' ), 10, 2 );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        }

        /* ---------------------------------------------------------- */
        /*  Register                                                   */
        /* ---------------------------------------------------------- */
        public function register( $post_type, $post ) {
            if ( $post && is_object( $post ) ) {
                $field_groups = JSCFR_Plugin::get_field_groups_for_post( $post );
            } else {
                $field_groups = JSCFR_Plugin::get_field_groups_for_post_type( $post_type );
            }

            $post_id = ( $post && is_object( $post ) && isset( $post->ID ) ) ? (int) $post->ID : 0;

            foreach ( $field_groups as $fg ) {
                if ( self::is_fg_hidden( $fg ) ) continue;
                if ( $post_id && ! self::post_passes_include_exclude( $fg, $post_id ) ) continue;

                $position = isset( $fg['settings']['position'] ) ? $fg['settings']['position'] : 'normal';
                if ( 'acf_after_title' === $position ) {
                    $position = 'normal';
                }

                add_meta_box(
                    'jscfr_' . $fg['id'],
                    ! empty( $fg['title'] ) ? esc_html( $fg['title'] ) : __( 'Custom Fields', 'jscfr' ),
                    array( $this, 'render' ),
                    $post_type,
                    $position,
                    'high',
                    array( 'fg' => $fg )
                );
            }
        }

        /* ---------------------------------------------------------- */
        /*  Shared FG settings helpers                                 */
        /* ---------------------------------------------------------- */

        /**
         * Build the class attribute for the .jscfr-meta-wrap wrapper from
         * field-group settings. Centralized so every renderer (post, term,
         * user, comment, options, frontend) applies the same visual options.
         */
        public static function build_wrap_classes( $fg ) {
            $s = isset( $fg['settings'] ) ? $fg['settings'] : array();
            $classes = array();
            if ( isset( $s['style'] ) && 'seamless' === $s['style'] ) {
                $classes[] = 'jscfr-seamless';
            }
            if ( isset( $s['label_placement'] ) && 'left' === $s['label_placement'] ) {
                $classes[] = 'jscfr-labels-left';
            }
            if ( isset( $s['tab_placement'] ) && 'left' === $s['tab_placement'] ) {
                $classes[] = 'jscfr-tabs-left';
            }
            if ( ! empty( $s['tab_style'] ) ) {
                $tab_style = sanitize_html_class( $s['tab_style'] );
                if ( $tab_style ) $classes[] = 'jscfr-tab-style-' . $tab_style;
            }
            if ( ! empty( $s['collapsed'] ) ) {
                $classes[] = 'jscfr-collapsed-default';
            }
            if ( ! empty( $s['autosave'] ) ) {
                $classes[] = 'jscfr-autosave-on';
            }
            if ( ! empty( $s['custom_class'] ) ) {
                $parts = preg_split( '/\s+/', trim( $s['custom_class'] ) );
                foreach ( (array) $parts as $part ) {
                    $safe = sanitize_html_class( $part );
                    if ( $safe ) $classes[] = $safe;
                }
            }
            return implode( ' ', $classes );
        }

        /**
         * Build inline data attributes for the wrapper that JS reads
         * (tab remember/default key, etc.).
         */
        public static function build_wrap_data_attrs( $fg ) {
            $s = isset( $fg['settings'] ) ? $fg['settings'] : array();
            $attrs = '';
            if ( ! empty( $s['tab_remember'] ) ) {
                $attrs .= ' data-jscfr-tab-remember="1"';
            }
            if ( isset( $s['tab_default'] ) && (int) $s['tab_default'] > 0 ) {
                $attrs .= ' data-jscfr-tab-default="' . esc_attr( (int) $s['tab_default'] ) . '"';
            }
            if ( ! empty( $s['autosave'] ) ) {
                $attrs .= ' data-jscfr-autosave="1"';
            }
            return $attrs;
        }

        /**
         * Whether the field group should be suppressed from rendering
         * entirely. Different from settings.active: hidden only affects
         * rendering, not the underlying save path.
         */
        public static function is_fg_hidden( $fg ) {
            $s = isset( $fg['settings'] ) ? $fg['settings'] : array();
            return ! empty( $s['hidden'] );
        }

        /**
         * Check a post ID against the FG's include/exclude filters.
         * Returns true if the post should see the field group, false if
         * filtered out. Post-context only.
         */
        public static function post_passes_include_exclude( $fg, $post_id ) {
            $s = isset( $fg['settings'] ) ? $fg['settings'] : array();
            $include = isset( $s['include'] ) ? trim( (string) $s['include'] ) : '';
            $exclude = isset( $s['exclude'] ) ? trim( (string) $s['exclude'] ) : '';
            $post_id = (int) $post_id;
            if ( '' !== $include ) {
                $ids = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $include ) ) );
                if ( $ids && ! in_array( $post_id, $ids, true ) ) return false;
            }
            if ( '' !== $exclude ) {
                $ids = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $exclude ) ) );
                if ( $ids && in_array( $post_id, $ids, true ) ) return false;
            }
            return true;
        }

        /* ---------------------------------------------------------- */
        /*  Shared asset enqueue (post/term/user/comment/options)      */
        /* ---------------------------------------------------------- */
        public static function collect_has_types( $fgs ) {
            $has_types = array();
            foreach ( $fgs as $fg ) {
                if ( empty( $fg['tabs'] ) ) continue;
                foreach ( $fg['tabs'] as $tab ) {
                    if ( empty( $tab['groups'] ) ) continue;
                    foreach ( $tab['groups'] as $group ) {
                        if ( empty( $group['fields'] ) ) continue;
                        foreach ( $group['fields'] as $field ) {
                            $has_types[ $field['type'] ] = true;
                        }
                    }
                }
            }
            return $has_types;
        }

        public static function enqueue_shared_assets( $fgs ) {
            wp_enqueue_media();
            wp_enqueue_script( 'jquery-ui-sortable' );
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );

            $has_types = self::collect_has_types( $fgs );

            if ( ! empty( $has_types['slider'] ) ) {
                wp_enqueue_script( 'jquery-ui-slider' );
                wp_enqueue_style( 'jscfr-jquery-ui', 'https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css', array(), '1.13.2' );
            }
            if ( ! empty( $has_types['autocomplete'] ) ) {
                wp_enqueue_script( 'jquery-ui-autocomplete' );
                wp_enqueue_style( 'jscfr-jquery-ui', 'https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css', array(), '1.13.2' );
            }
            if ( ! empty( $has_types['select_advanced'] ) || ! empty( $has_types['select'] ) ) {
                wp_enqueue_style( 'jscfr-select2', JSCFR_PLUGIN_URL . 'assets/vendor/select2.min.css', array(), '4.0.13' );
                wp_enqueue_script( 'jscfr-select2', JSCFR_PLUGIN_URL . 'assets/vendor/select2.min.js', array( 'jquery' ), '4.0.13', true );
            }
            if ( ! empty( $has_types['osm'] ) || ! empty( $has_types['google_map'] ) ) {
                wp_enqueue_style( 'jscfr-leaflet', JSCFR_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
                wp_enqueue_script( 'jscfr-leaflet', JSCFR_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
                wp_add_inline_script( 'jscfr-leaflet', 'window.jscfrL = (window.L && window.L.map) ? window.L : (window.jscfrL || null);' );
            }
            if ( ! empty( $has_types['wysiwyg'] ) ) {
                wp_enqueue_editor();
            }

            $js_deps = array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' );
            if ( ! empty( $has_types['select_advanced'] ) || ! empty( $has_types['select'] ) ) {
                $js_deps[] = 'jscfr-select2';
            }
            if ( ! empty( $has_types['osm'] ) || ! empty( $has_types['google_map'] ) ) {
                $js_deps[] = 'jscfr-leaflet';
            }

            wp_enqueue_style( 'jscfr-metabox-css', JSCFR_PLUGIN_URL . 'assets/css/jscfr-metabox.css', array( 'wp-color-picker' ), JSCFR_VERSION );
            wp_enqueue_script( 'jscfr-metabox-js', JSCFR_PLUGIN_URL . 'assets/js/jscfr-metabox.js', $js_deps, JSCFR_VERSION, true );

            return $has_types;
        }

        /* ---------------------------------------------------------- */
        /*  Enqueue                                                    */
        /* ---------------------------------------------------------- */
        public function enqueue( $hook ) {
            if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
                return;
            }

            $screen = get_current_screen();
            if ( ! $screen ) return;

            global $post;
            if ( $post ) {
                $fgs = JSCFR_Plugin::get_field_groups_for_post( $post );
            } else {
                $fgs = JSCFR_Plugin::get_field_groups_for_post_type( $screen->post_type );
            }
            if ( empty( $fgs ) ) return;

            self::enqueue_shared_assets( $fgs );

            // Build conditional logic map and field configs for JS
            $cond_map = array();
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
        /*  Render                                                     */
        /* ---------------------------------------------------------- */
        public function render( $post, $metabox ) {
            $fg = $metabox['args']['fg'];

            wp_nonce_field( JSCFR_NONCE_ACTION, JSCFR_NONCE_NAME );

            $saved = get_post_meta( $post->ID, JSCFR_META_KEY, true );
            if ( ! is_array( $saved ) ) {
                $saved = array();
            }

            // Lazy migration: old format
            if ( ! empty( $saved ) && ! isset( $saved[ $fg['id'] ] ) ) {
                $first_key = array_keys( $saved )[0];
                if ( 0 === strpos( $first_key, 'tab_' ) ) {
                    $saved = array( $fg['id'] => $saved );
                    update_post_meta( $post->ID, JSCFR_META_KEY, $saved );
                }
            }

            $fg_data = isset( $saved[ $fg['id'] ] ) ? $saved[ $fg['id'] ] : array();
            $tabs    = isset( $fg['tabs'] ) ? $fg['tabs'] : array();

            $wrap_classes = self::build_wrap_classes( $fg );
            $wrap_attrs   = self::build_wrap_data_attrs( $fg );

            echo '<div class="jscfr-meta-wrap ' . esc_attr( $wrap_classes ) . '" data-fg="' . esc_attr( $fg['id'] ) . '"' . $wrap_attrs . '>';

            if ( ! empty( $fg['settings']['description'] ) ) {
                echo '<p class="jscfr-fg-desc">' . esc_html( $fg['settings']['description'] ) . '</p>';
            }

            if ( empty( $tabs ) ) {
                echo '<p class="jscfr-empty">' . esc_html__( 'No fields configured.', 'jscfr' ) . '</p>';
                echo '</div>';
                return;
            }

            $has_tabs = count( $tabs ) > 1;
            $tab_placement = isset( $fg['settings']['tab_placement'] ) ? $fg['settings']['tab_placement'] : 'top';

            // Open tabs wrapper for vertical layout
            if ( $has_tabs ) {
                echo '<div class="jscfr-tabs-wrap">';
                echo '<nav class="jscfr-tab-nav">';
                $first = true;
                foreach ( $tabs as $tab ) {
                    $active = $first ? ' jscfr-tab-active' : '';
                    $icon   = '';
                    if ( ! empty( $tab['icon'] ) ) {
                        $icon_type = isset( $tab['icon_type'] ) ? $tab['icon_type'] : 'dashicons';
                        if ( 'dashicons' === $icon_type ) {
                            $icon = '<span class="dashicons dashicons-' . esc_attr( $tab['icon'] ) . ' jscfr-tab-icon"></span>';
                        } elseif ( 'fontawesome' === $icon_type ) {
                            $icon = '<i class="' . esc_attr( $tab['icon'] ) . ' jscfr-tab-icon"></i>';
                        } elseif ( 'url' === $icon_type ) {
                            $icon = '<img src="' . esc_url( $tab['icon'] ) . '" class="jscfr-tab-icon" alt="" />';
                        }
                    } elseif ( 'left' === $tab_placement ) {
                        $icon = '<span class="dashicons dashicons-admin-post jscfr-tab-icon"></span>';
                    }
                    echo '<button type="button" class="jscfr-tab-btn' . $active . '" data-tab="' . esc_attr( $tab['id'] ) . '">' . $icon . esc_html( $tab['label'] ) . '</button>';
                    $first = false;
                }
                echo '</nav>';
                echo '<div class="jscfr-tabs-panels">';
            }

            // Tab content
            $first = true;
            foreach ( $tabs as $tab ) {
                $display = $first ? '' : ' style="display:none;"';
                echo '<div class="jscfr-tab-content" id="jscfr-tab-' . esc_attr( $fg['id'] ) . '-' . esc_attr( $tab['id'] ) . '"' . $display . '>';

                if ( empty( $tab['groups'] ) ) {
                    echo '<p class="jscfr-empty">' . esc_html__( 'No groups in this tab.', 'jscfr' ) . '</p>';
                } else {
                    foreach ( $tab['groups'] as $group ) {
                        $this->render_group( $fg['id'], $tab['id'], $group, $fg_data );
                    }
                }

                echo '</div>';
                $first = false;
            }

            // Close tabs wrapper
            if ( $has_tabs ) {
                echo '</div>'; // .jscfr-tabs-panels
                echo '</div>'; // .jscfr-tabs-wrap
            }

            echo '</div>';
        }

        /* ---------------------------------------------------------- */
        /*  Render group                                               */
        /* ---------------------------------------------------------- */
        public function render_group( $fg_id, $tab_id, $group, $fg_data ) {
            $gid      = $group['id'];
            $rows     = isset( $fg_data[ $tab_id ][ $gid ] ) ? $fg_data[ $tab_id ][ $gid ] : array();
            $layout   = isset( $group['layout'] ) ? $group['layout'] : 'block';
            $clonable = isset( $group['clonable'] ) ? (bool) $group['clonable'] : true;
            $min      = isset( $group['min'] ) && $group['min'] !== '' ? intval( $group['min'] ) : 0;
            $max      = isset( $group['max'] ) && $group['max'] !== '' ? intval( $group['max'] ) : 0;

            $ef = esc_attr( $fg_id );
            $et = esc_attr( $tab_id );
            $eg = esc_attr( $gid );

            echo '<div class="jscfr-group-block jscfr-layout-' . esc_attr( $layout ) . ( $clonable ? '' : ' jscfr-group-static' ) . '" data-fg="' . $ef . '" data-tab="' . $et . '" data-group="' . $eg . '" data-min="' . esc_attr( $min ) . '" data-max="' . esc_attr( $max ) . '" data-clonable="' . ( $clonable ? '1' : '0' ) . '">';
            echo '<div class="jscfr-group-header"><h3>' . esc_html( $group['label'] ) . '</h3>';
            if ( $clonable ) {
                echo '<span class="jscfr-group-badge">' . count( $rows ) . ' ' . esc_html__( 'entries', 'jscfr' ) . '</span>';
            }
            echo '</div>';

            if ( $clonable ) {
                /* --- Clonable (repeater) group --- */
                $clone_container_id = 'jscfr-clones-' . $ef . '-' . $et . '-' . $eg;
                echo '<div class="jscfr-clones" id="' . $clone_container_id . '">';

                if ( ! empty( $rows ) ) {
                    foreach ( $rows as $idx => $row_data ) {
                        $this->render_clone_row( $fg_id, $tab_id, $gid, $group['fields'], $idx, $row_data );
                    }
                }

                echo '</div>';

                echo '<button type="button" class="button button-primary jscfr-add-clone" '
                    . 'data-fg="' . $ef . '" '
                    . 'data-tab="' . $et . '" '
                    . 'data-group="' . $eg . '">'
                    . '<span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;"></span>'
                    . esc_html__( 'Add Entry', 'jscfr' ) . '</button>';

                // Hidden template — use <script> so inputs are inert and never submitted with the form
                $tpl_id = 'jscfr-clonetpl-' . $ef . '-' . $et . '-' . $eg;
                echo '<script type="text/html" class="jscfr-clone-template" id="' . $tpl_id . '">';
                $this->render_clone_row( $fg_id, $tab_id, $gid, $group['fields'], '__IDX__', array() );
                echo '</script>';
            } else {
                /* --- Non-clonable (static) group: render fields directly at index 0 --- */
                $row_data = isset( $rows[0] ) ? $rows[0] : array();
                echo '<div class="jscfr-static-fields">';
                foreach ( $group['fields'] as $field ) {
                    $this->render_field( $ef, $et, $eg, '0', $field, $row_data );
                }
                echo '</div>';
            }

            echo '</div>';
        }

        /* ---------------------------------------------------------- */
        /*  Render clone row                                           */
        /* ---------------------------------------------------------- */
        public function render_clone_row( $fg_id, $tab_id, $group_id, $fields, $idx, $data ) {
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
                $this->render_field( $ef, $et, $eg, $ei, $field, $data );
            }

            echo '</div>';
            echo '</div>';
        }

        /* ---------------------------------------------------------- */
        /*  Render single field                                        */
        /* ---------------------------------------------------------- */
        public function render_field( $ef, $et, $eg, $ei, $field, $data ) {
            $fid   = $field['id'];
            $ftype = $field['type'];
            $value = isset( $data[ $fid ] ) ? $data[ $fid ] : '';
            $req   = ! empty( $field['required'] );

            // Apply default value if empty and has default
            if ( '' === $value && ! empty( $field['default_value'] ) ) {
                $value = $field['default_value'];
            }

            $name  = 'jscfr_data[' . $ef . '][' . $et . '][' . $eg . '][' . $ei . '][' . esc_attr( $fid ) . ']';
            $domid = 'jscfr_' . $ef . '_' . $et . '_' . $eg . '_' . $ei . '_' . esc_attr( $fid );

            // Wrapper
            $wrapper       = isset( $field['wrapper'] ) ? $field['wrapper'] : array();
            $wrapper_width = ! empty( $wrapper['width'] ) ? $wrapper['width'] : '';
            $wrapper_class = ! empty( $wrapper['class'] ) ? $wrapper['class'] : '';
            $wrapper_id    = ! empty( $wrapper['id'] ) ? $wrapper['id'] : '';

            $req_class = $req ? ' jscfr-fld--required' : '';
            $style     = '';
            $cond_attr = '';
            if ( ! empty( $field['conditional_logic'] ) ) {
                $cond_attr = ' data-jscfr-cond="' . esc_attr( $fid ) . '"';
            }

            // Columns layout (12-column grid)
            $col_span = ! empty( $field['columns'] ) ? intval( $field['columns'] ) : 0;
            if ( $col_span > 0 && $col_span <= 12 ) {
                // Columns is the primary layout control
                $style .= 'grid-column:span ' . $col_span . ';';
            } elseif ( $wrapper_width ) {
                // Fallback: convert wrapper width % to grid columns
                $col_span = max( 1, min( 12, round( intval( $wrapper_width ) * 12 / 100 ) ) );
                $style .= 'grid-column:span ' . $col_span . ';';
            }

            // Text limiter attrs
            $limit_attr = '';
            if ( ! empty( $field['limit'] ) && in_array( $ftype, array( 'text', 'textarea', 'email', 'url', 'password' ), true ) ) {
                $limit_attr = ' data-jscfr-limit="' . esc_attr( intval( $field['limit'] ) ) . '" data-jscfr-limit-type="' . esc_attr( isset( $field['limit_type'] ) ? $field['limit_type'] : 'characters' ) . '"';
            }

            echo '<div class="jscfr-fld jscfr-fld--' . esc_attr( $ftype ) . $req_class . ( $wrapper_class ? ' ' . esc_attr( $wrapper_class ) : '' ) . '"'
                . ( $wrapper_id ? ' id="' . esc_attr( $wrapper_id ) . '"' : '' )
                . ( $style ? ' style="' . $style . '"' : '' )
                . $cond_attr
                . $limit_attr
                . ' data-field-id="' . esc_attr( $fid ) . '"'
                . ' data-field-type="' . esc_attr( $ftype ) . '"'
                . '>';

            // Label (skip for display-only types)
            $no_label_types = array( 'message', 'heading', 'divider', 'custom_html', 'hidden' );
            if ( ! in_array( $ftype, $no_label_types, true ) ) {
                echo '<label for="' . $domid . '">' . esc_html( $field['label'] );
                if ( $req ) {
                    echo ' <span class="jscfr-required-star">*</span>';
                }
                if ( ! empty( $field['tooltip'] ) ) {
                    echo ' <span class="jscfr-tooltip" data-tip="' . esc_attr( $field['tooltip'] ) . '"><span class="dashicons dashicons-editor-help"></span></span>';
                }
                echo '</label>';
            }

            // Label Description (below label)
            if ( ! empty( $field['label_description'] ) ) {
                echo '<p class="jscfr-label-desc">' . esc_html( $field['label_description'] ) . '</p>';
            }

            // Instructions
            if ( ! empty( $field['instructions'] ) ) {
                echo '<p class="jscfr-instructions">' . esc_html( $field['instructions'] ) . '</p>';
            }

            $ph = isset( $field['placeholder'] ) ? $field['placeholder'] : '';

            // HTML Before
            if ( ! empty( $field['html_before'] ) ) {
                echo wp_kses_post( $field['html_before'] );
            }

            // Prepend / Append wrapper
            $has_prepend = ! empty( $field['prepend'] ) && in_array( $ftype, array( 'text', 'number', 'email', 'url', 'password', 'range' ), true );
            $has_append  = ! empty( $field['append'] ) && in_array( $ftype, array( 'text', 'number', 'email', 'url', 'password', 'range' ), true );

            if ( $has_prepend || $has_append ) {
                echo '<div class="jscfr-input-group">';
                if ( $has_prepend ) {
                    echo '<span class="jscfr-prepend">' . esc_html( $field['prepend'] ) . '</span>';
                }
            }

            switch ( $ftype ) {

                /* ---- Basic input types ---- */
                case 'text':
                case 'email':
                case 'url':
                case 'password':
                    $maxlen = ! empty( $field['maxlength'] ) ? ' maxlength="' . esc_attr( $field['maxlength'] ) . '"' : '';
                    echo '<input type="' . esc_attr( $ftype ) . '" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $ph ) . '" class="widefat"' . $maxlen . ' />';
                    break;

                case 'number':
                    $min_a  = $field['min'] !== '' ? ' min="' . esc_attr( $field['min'] ) . '"' : '';
                    $max_a  = $field['max'] !== '' ? ' max="' . esc_attr( $field['max'] ) . '"' : '';
                    $step_a = ! empty( $field['step'] ) ? ' step="' . esc_attr( $field['step'] ) . '"' : '';
                    echo '<input type="number" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $ph ) . '" class="widefat"' . $min_a . $max_a . $step_a . ' />';
                    break;

                case 'range':
                    $min_v  = $field['min'] !== '' ? $field['min'] : '0';
                    $max_v  = $field['max'] !== '' ? $field['max'] : '100';
                    $step_v = ! empty( $field['step'] ) ? $field['step'] : '1';
                    $val    = $value !== '' ? $value : $min_v;
                    echo '<div class="jscfr-range-wrap">';
                    echo '<input type="range" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $val ) . '" min="' . esc_attr( $min_v ) . '" max="' . esc_attr( $max_v ) . '" step="' . esc_attr( $step_v ) . '" class="jscfr-range-input" />';
                    echo '<span class="jscfr-range-value">' . esc_html( $val ) . '</span>';
                    echo '</div>';
                    break;

                case 'date':
                    echo '<input type="date" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" class="widefat" />';
                    break;

                case 'datetime':
                    echo '<input type="datetime-local" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" class="widefat" />';
                    break;

                case 'time':
                    echo '<input type="time" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" class="widefat" />';
                    break;

                case 'color':
                    echo '<input type="text" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-color-picker" />';
                    break;

                /* ---- Textarea ---- */
                case 'textarea':
                    $rows   = ! empty( $field['rows'] ) ? intval( $field['rows'] ) : 4;
                    $maxlen = ! empty( $field['maxlength'] ) ? ' maxlength="' . esc_attr( $field['maxlength'] ) . '"' : '';
                    echo '<textarea id="' . $domid . '" name="' . $name . '" placeholder="' . esc_attr( $ph ) . '" class="widefat" rows="' . $rows . '"' . $maxlen . '>' . esc_textarea( $value ) . '</textarea>';
                    break;

                /* ---- WYSIWYG ---- */
                case 'wysiwyg':
                    $editor_id = 'jscfr_wysiwyg_' . $ef . '_' . $et . '_' . $eg . '_' . $ei . '_' . esc_attr( $fid );
                    $toolbar   = isset( $field['toolbar'] ) ? $field['toolbar'] : 'full';
                    $media     = isset( $field['media_upload'] ) ? (bool) $field['media_upload'] : true;
                    echo '<textarea id="' . esc_attr( $editor_id ) . '" name="' . $name . '" class="jscfr-wysiwyg widefat" rows="5" data-toolbar="' . esc_attr( $toolbar ) . '" data-media="' . ( $media ? '1' : '0' ) . '">' . esc_textarea( $value ) . '</textarea>';
                    break;

                /* ---- Select ---- */
                case 'select':
                    $options   = $this->parse_select_options( isset( $field['options'] ) ? $field['options'] : '' );
                    $multiple  = ! empty( $field['multiple'] );
                    $sel_vals  = $multiple ? (array) $value : array( $value );

                    if ( $multiple ) {
                        echo '<div class="jscfr-multi-wrap">';
                        echo '<select id="' . $domid . '" name="' . $name . '[]" class="jscfr-multi-hidden" multiple style="display:none;">';
                        foreach ( $options as $ov => $ol ) {
                            $selected = in_array( (string) $ov, $sel_vals, true ) ? ' selected' : '';
                            echo '<option value="' . esc_attr( $ov ) . '"' . $selected . '>' . esc_html( $ol ) . '</option>';
                        }
                        echo '</select>';
                        echo '<select class="widefat jscfr-multi-picker" data-target="' . $domid . '">';
                        echo '<option value="">' . esc_html__( '— Select —', 'jscfr' ) . '</option>';
                        foreach ( $options as $ov => $ol ) {
                            $disabled = in_array( (string) $ov, $sel_vals, true ) ? ' disabled' : '';
                            echo '<option value="' . esc_attr( $ov ) . '"' . $disabled . '>' . esc_html( $ol ) . '</option>';
                        }
                        echo '</select>';
                        echo '<div class="jscfr-multi-tags" data-target="' . $domid . '">';
                        foreach ( $options as $ov => $ol ) {
                            if ( in_array( (string) $ov, $sel_vals, true ) ) {
                                echo '<span class="jscfr-multi-tag" data-value="' . esc_attr( $ov ) . '">' . esc_html( $ol ) . '<button type="button" class="jscfr-multi-tag-remove">&times;</button></span>';
                            }
                        }
                        echo '</div>';
                        echo '</div>';
                    } else {
                        echo '<select id="' . $domid . '" name="' . $name . '" class="widefat">';
                        if ( ! empty( $field['allow_null'] ) ) {
                            echo '<option value="">' . esc_html__( '— Select —', 'jscfr' ) . '</option>';
                        }
                        foreach ( $options as $ov => $ol ) {
                            $selected = ( (string) $value === (string) $ov ) ? ' selected' : '';
                            echo '<option value="' . esc_attr( $ov ) . '"' . $selected . '>' . esc_html( $ol ) . '</option>';
                        }
                        echo '</select>';
                    }
                    break;

                /* ---- Radio ---- */
                case 'radio':
                    $options = $this->parse_select_options( isset( $field['options'] ) ? $field['options'] : '' );
                    $inline_class = ( ! empty( $field['display'] ) && 'inline' === $field['display'] ) ? ' jscfr-inline' : '';
                    echo '<div class="jscfr-radio-group' . $inline_class . '">';
                    foreach ( $options as $ov => $ol ) {
                        $checked = ( (string) $value === (string) $ov ) ? ' checked' : '';
                        $rid = $domid . '_' . sanitize_key( $ov );
                        echo '<label class="jscfr-radio-label"><input type="radio" id="' . $rid . '" name="' . $name . '" value="' . esc_attr( $ov ) . '"' . $checked . ' /> ' . esc_html( $ol ) . '</label>';
                    }
                    if ( ! empty( $field['allow_null'] ) ) {
                        echo '<label class="jscfr-radio-label"><input type="radio" name="' . $name . '" value=""' . ( '' === $value ? ' checked' : '' ) . ' /> <em>' . esc_html__( 'None', 'jscfr' ) . '</em></label>';
                    }
                    echo '</div>';
                    break;

                /* ---- Button Group ---- */
                case 'button_group':
                    $options = $this->parse_select_options( isset( $field['options'] ) ? $field['options'] : '' );
                    echo '<div class="jscfr-button-group">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-bg-value" />';
                    foreach ( $options as $ov => $ol ) {
                        $active = ( (string) $value === (string) $ov ) ? ' jscfr-bg-active' : '';
                        echo '<button type="button" class="button jscfr-bg-btn' . $active . '" data-value="' . esc_attr( $ov ) . '">' . esc_html( $ol ) . '</button>';
                    }
                    echo '</div>';
                    break;

                /* ---- Checkbox (multi-choice) ---- */
                case 'checkbox':
                    $options   = $this->parse_select_options( isset( $field['options'] ) ? $field['options'] : '' );
                    $cb_values = is_array( $value ) ? $value : ( $value ? array( $value ) : array() );

                    if ( ! empty( $options ) ) {
                        // Multi-checkbox from options
                        $inline_class = ( ! empty( $field['display'] ) && 'inline' === $field['display'] ) ? ' jscfr-inline' : '';
                        echo '<div class="jscfr-checkbox-group' . $inline_class . '">';
                        foreach ( $options as $ov => $ol ) {
                            $checked = in_array( (string) $ov, $cb_values, true ) ? ' checked' : '';
                            echo '<label class="jscfr-cb-label"><input type="checkbox" name="' . $name . '[]" value="' . esc_attr( $ov ) . '"' . $checked . ' /> ' . esc_html( $ol ) . '</label>';
                        }
                        echo '</div>';
                    } else {
                        // Single toggle checkbox
                        echo '<label class="jscfr-cb-label">';
                        echo '<input type="hidden" name="' . $name . '" value="0" />';
                        echo '<input type="checkbox" id="' . $domid . '" name="' . $name . '" value="1" ' . checked( $value, '1', false ) . ' /> ';
                        echo esc_html( $field['label'] );
                        echo '</label>';
                    }
                    break;

                /* ---- True/False (toggle) ---- */
                case 'true_false':
                    echo '<div class="jscfr-toggle-wrap">';
                    echo '<input type="hidden" name="' . $name . '" value="0" />';
                    echo '<label class="jscfr-toggle">';
                    echo '<input type="checkbox" id="' . $domid . '" name="' . $name . '" value="1" ' . checked( $value, '1', false ) . ' />';
                    echo '<span class="jscfr-toggle-slider"></span>';
                    echo '</label>';
                    echo '</div>';
                    break;

                /* ---- Image ---- */
                case 'image':
                    $img_url     = '';
                    $img_preview = '';
                    $preview_sz  = isset( $field['preview_size'] ) ? $field['preview_size'] : 'thumbnail';
                    if ( $value ) {
                        $img_src = wp_get_attachment_image_src( intval( $value ), $preview_sz );
                        if ( $img_src ) {
                            $img_url     = $img_src[0];
                            $img_preview = '<img src="' . esc_url( $img_url ) . '" alt="" />';
                        }
                    }
                    $mime = isset( $field['mime'] ) ? $field['mime'] : 'image';

                    echo '<div class="jscfr-image-wrap" data-preview-size="' . esc_attr( $preview_sz ) . '">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-image-id" />';
                    echo '<div class="jscfr-image-preview">' . ( $img_preview ? $img_preview : '<span class="jscfr-image-placeholder"><span class="dashicons dashicons-format-image"></span></span>' ) . '</div>';
                    echo '<div class="jscfr-image-actions">';
                    echo '<button type="button" class="button jscfr-image-select" data-mime="' . esc_attr( $mime ) . '"><span class="dashicons dashicons-upload" style="vertical-align:middle;margin-right:4px;"></span>' . esc_html__( 'Select Image', 'jscfr' ) . '</button> ';
                    echo '<button type="button" class="button jscfr-image-clear" style="' . ( $value ? '' : 'display:none;' ) . '"><span class="dashicons dashicons-no" style="vertical-align:middle;"></span> ' . esc_html__( 'Remove', 'jscfr' ) . '</button>';
                    echo '</div>';
                    echo '</div>';
                    break;

                /* ---- File ---- */
                case 'file':
                    $att_url   = '';
                    $file_name = '';
                    if ( $value ) {
                        $att_url   = wp_get_attachment_url( intval( $value ) );
                        $file_name = basename( get_attached_file( intval( $value ) ) ?: '' );
                    }
                    $mime = isset( $field['mime'] ) ? $field['mime'] : '';

                    echo '<div class="jscfr-file-wrap">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-file-id" />';
                    echo '<span class="jscfr-file-name">' . ( $file_name ? esc_html( $file_name ) : '<em>' . esc_html__( 'No file selected', 'jscfr' ) . '</em>' ) . '</span> ';
                    echo '<button type="button" class="button jscfr-upload" data-mime="' . esc_attr( $mime ) . '"><span class="dashicons dashicons-upload" style="vertical-align:middle;margin-right:4px;"></span>' . esc_html__( 'Upload / Select', 'jscfr' ) . '</button> ';
                    echo '<button type="button" class="button jscfr-file-clear" style="' . ( $value ? '' : 'display:none;' ) . '"><span class="dashicons dashicons-no" style="vertical-align:middle;"></span></button>';
                    if ( $att_url ) {
                        echo ' <a href="' . esc_url( $att_url ) . '" target="_blank" class="button jscfr-file-preview"><span class="dashicons dashicons-visibility" style="vertical-align:middle;"></span></a>';
                    }
                    echo '</div>';
                    break;

                /* ---- Gallery ---- */
                case 'gallery':
                    $gallery_ids = is_array( $value ) ? $value : ( $value ? explode( ',', $value ) : array() );
                    $gallery_ids = array_filter( array_map( 'absint', $gallery_ids ) );
                    $min_c = isset( $field['min_count'] ) && $field['min_count'] !== '' ? intval( $field['min_count'] ) : 0;
                    $max_c = isset( $field['max_count'] ) && $field['max_count'] !== '' ? intval( $field['max_count'] ) : 0;

                    echo '<div class="jscfr-gallery-wrap" data-min="' . $min_c . '" data-max="' . $max_c . '">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( implode( ',', $gallery_ids ) ) . '" class="jscfr-gallery-ids" />';
                    echo '<div class="jscfr-gallery-thumbs">';
                    foreach ( $gallery_ids as $att_id ) {
                        $thumb = wp_get_attachment_image_src( $att_id, 'thumbnail' );
                        if ( $thumb ) {
                            echo '<div class="jscfr-gallery-item" data-id="' . $att_id . '"><img src="' . esc_url( $thumb[0] ) . '" /><button type="button" class="jscfr-gallery-remove"><span class="dashicons dashicons-no-alt"></span></button></div>';
                        }
                    }
                    echo '</div>';
                    echo '<button type="button" class="button jscfr-gallery-add"><span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;"></span>' . esc_html__( 'Add Images', 'jscfr' ) . '</button>';
                    echo '</div>';
                    break;

                /* ---- Link ---- */
                case 'link':
                    $link_data = is_array( $value ) ? $value : array( 'url' => '', 'title' => '', 'target' => '' );
                    $lu = isset( $link_data['url'] ) ? $link_data['url'] : '';
                    $lt = isset( $link_data['title'] ) ? $link_data['title'] : '';
                    $ltg = isset( $link_data['target'] ) ? $link_data['target'] : '';

                    echo '<div class="jscfr-link-wrap">';
                    echo '<div class="jscfr-link-field"><label>' . esc_html__( 'URL', 'jscfr' ) . '</label><input type="url" name="' . $name . '[url]" value="' . esc_attr( $lu ) . '" class="widefat" placeholder="https://" /></div>';
                    echo '<div class="jscfr-link-field"><label>' . esc_html__( 'Title', 'jscfr' ) . '</label><input type="text" name="' . $name . '[title]" value="' . esc_attr( $lt ) . '" class="widefat" /></div>';
                    echo '<div class="jscfr-link-field"><label><input type="checkbox" name="' . $name . '[target]" value="_blank" ' . checked( $ltg, '_blank', false ) . ' /> ' . esc_html__( 'Open in new tab', 'jscfr' ) . '</label></div>';
                    echo '</div>';
                    break;

                /* ---- oEmbed ---- */
                case 'oembed':
                    echo '<input type="url" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( 'Paste embed URL (YouTube, Vimeo, etc.)', 'jscfr' ) . '" class="widefat jscfr-oembed-input" />';
                    if ( $value ) {
                        $embed = wp_oembed_get( $value, array(
                            'width'  => ! empty( $field['oembed_width'] ) ? intval( $field['oembed_width'] ) : 640,
                            'height' => ! empty( $field['oembed_height'] ) ? intval( $field['oembed_height'] ) : 360,
                        ) );
                        if ( $embed ) {
                            echo '<div class="jscfr-oembed-preview">' . $embed . '</div>';
                        }
                    }
                    break;

                /* ---- Post Object ---- */
                case 'post_object':
                    $multiple   = ! empty( $field['multiple'] );
                    $post_types = ! empty( $field['post_type'] ) ? $field['post_type'] : array( 'post', 'page' );
                    $selected   = $multiple ? ( is_array( $value ) ? $value : array() ) : array( $value );
                    $selected   = array_filter( array_map( 'absint', $selected ) );

                    echo '<div class="jscfr-post-object-wrap" data-multiple="' . ( $multiple ? '1' : '0' ) . '" data-post-types="' . esc_attr( wp_json_encode( $post_types ) ) . '">';
                    echo '<input type="hidden" name="' . $name . ( $multiple ? '' : '' ) . '" value="' . esc_attr( $multiple ? implode( ',', $selected ) : ( ! empty( $selected ) ? $selected[0] : '' ) ) . '" class="jscfr-po-value" />';
                    echo '<div class="jscfr-po-selected">';
                    foreach ( $selected as $pid ) {
                        $p = get_post( $pid );
                        if ( $p ) {
                            echo '<span class="jscfr-po-tag" data-id="' . $pid . '">' . esc_html( $p->post_title ?: __( '(no title)', 'jscfr' ) ) . ' <button type="button" class="jscfr-po-remove">&times;</button></span>';
                        }
                    }
                    echo '</div>';
                    echo '<input type="text" class="widefat jscfr-po-search" placeholder="' . esc_attr__( 'Search posts...', 'jscfr' ) . '" />';
                    echo '<div class="jscfr-po-results"></div>';
                    echo '</div>';
                    break;

                /* ---- Relationship ---- */
                case 'relationship':
                    $post_types = ! empty( $field['post_type'] ) ? $field['post_type'] : array( 'post', 'page' );
                    $selected   = is_array( $value ) ? array_filter( array_map( 'absint', $value ) ) : array();
                    $min_c = isset( $field['min_count'] ) && $field['min_count'] !== '' ? intval( $field['min_count'] ) : 0;
                    $max_c = isset( $field['max_count'] ) && $field['max_count'] !== '' ? intval( $field['max_count'] ) : 0;

                    echo '<div class="jscfr-relationship-wrap" data-post-types="' . esc_attr( wp_json_encode( $post_types ) ) . '" data-min="' . $min_c . '" data-max="' . $max_c . '">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( implode( ',', $selected ) ) . '" class="jscfr-rel-value" />';

                    // Chips row (selected items)
                    echo '<div class="jscfr-rel-chips">';
                    foreach ( $selected as $pid ) {
                        $p = get_post( $pid );
                        if ( $p ) {
                            echo '<span class="jscfr-rel-chip" data-id="' . $pid . '">' . esc_html( $p->post_title ?: __( '(no title)', 'jscfr' ) ) . '<button type="button" class="jscfr-rel-chip-remove" aria-label="' . esc_attr__( 'Remove', 'jscfr' ) . '">&times;</button></span>';
                        }
                    }
                    echo '</div>';

                    // Search + dropdown picker
                    echo '<div class="jscfr-rel-picker">';
                    echo '<input type="text" class="widefat jscfr-rel-search" placeholder="' . esc_attr__( 'Search posts...', 'jscfr' ) . '" autocomplete="off" />';
                    echo '<div class="jscfr-rel-dropdown" style="display:none;"></div>';
                    echo '</div>';

                    echo '</div>';
                    break;

                /* ---- Taxonomy ---- */
                case 'taxonomy':
                    $tax_type   = ! empty( $field['taxonomy_type'] ) ? $field['taxonomy_type'] : 'category';
                    $appearance = isset( $field['field_type'] ) ? $field['field_type'] : 'checkbox';
                    $terms      = get_terms( array( 'taxonomy' => $tax_type, 'hide_empty' => false ) );
                    $sel_vals   = is_array( $value ) ? $value : ( $value ? array( $value ) : array() );
                    $sel_vals   = array_map( 'strval', $sel_vals );

                    // load_terms: pre-populate from post's actual taxonomy terms if no saved value
                    if ( empty( $sel_vals ) && ! empty( $field['load_terms'] ) && $post_id ) {
                        $post_terms = wp_get_object_terms( $post_id, $tax_type, array( 'fields' => 'ids' ) );
                        if ( ! is_wp_error( $post_terms ) ) {
                            $sel_vals = array_map( 'strval', $post_terms );
                        }
                    }

                    echo '<div class="jscfr-taxonomy-wrap" data-taxonomy="' . esc_attr( $tax_type ) . '">';

                    if ( ! is_wp_error( $terms ) ) {
                        switch ( $appearance ) {
                            case 'checkbox':
                                echo '<div class="jscfr-tax-list">';
                                foreach ( $terms as $term ) {
                                    $checked = in_array( (string) $term->term_id, $sel_vals, true ) ? ' checked' : '';
                                    echo '<label class="jscfr-cb-label"><input type="checkbox" name="' . $name . '[]" value="' . esc_attr( $term->term_id ) . '"' . $checked . ' /> ' . esc_html( $term->name ) . '</label>';
                                }
                                echo '</div>';
                                break;

                            case 'radio':
                                echo '<div class="jscfr-tax-list">';
                                foreach ( $terms as $term ) {
                                    $checked = in_array( (string) $term->term_id, $sel_vals, true ) ? ' checked' : '';
                                    echo '<label class="jscfr-radio-label"><input type="radio" name="' . $name . '" value="' . esc_attr( $term->term_id ) . '"' . $checked . ' /> ' . esc_html( $term->name ) . '</label>';
                                }
                                echo '</div>';
                                break;

                            case 'select':
                                echo '<select name="' . $name . '" class="widefat">';
                                if ( ! empty( $field['allow_null'] ) ) {
                                    echo '<option value="">' . esc_html__( '— Select —', 'jscfr' ) . '</option>';
                                }
                                foreach ( $terms as $term ) {
                                    $selected = in_array( (string) $term->term_id, $sel_vals, true ) ? ' selected' : '';
                                    echo '<option value="' . esc_attr( $term->term_id ) . '"' . $selected . '>' . esc_html( $term->name ) . '</option>';
                                }
                                echo '</select>';
                                break;

                            case 'multi_select':
                                echo '<div class="jscfr-multi-wrap">';
                                echo '<select id="' . $domid . '" name="' . $name . '[]" class="jscfr-multi-hidden" multiple style="display:none;">';
                                foreach ( $terms as $term ) {
                                    $selected = in_array( (string) $term->term_id, $sel_vals, true ) ? ' selected' : '';
                                    echo '<option value="' . esc_attr( $term->term_id ) . '"' . $selected . '>' . esc_html( $term->name ) . '</option>';
                                }
                                echo '</select>';
                                echo '<select class="widefat jscfr-multi-picker" data-target="' . $domid . '">';
                                echo '<option value="">' . esc_html__( '— Select —', 'jscfr' ) . '</option>';
                                foreach ( $terms as $term ) {
                                    $disabled = in_array( (string) $term->term_id, $sel_vals, true ) ? ' disabled' : '';
                                    echo '<option value="' . esc_attr( $term->term_id ) . '"' . $disabled . '>' . esc_html( $term->name ) . '</option>';
                                }
                                echo '</select>';
                                echo '<div class="jscfr-multi-tags" data-target="' . $domid . '">';
                                foreach ( $terms as $term ) {
                                    if ( in_array( (string) $term->term_id, $sel_vals, true ) ) {
                                        echo '<span class="jscfr-multi-tag" data-value="' . esc_attr( $term->term_id ) . '">' . esc_html( $term->name ) . '<button type="button" class="jscfr-multi-tag-remove">&times;</button></span>';
                                    }
                                }
                                echo '</div>';
                                echo '</div>';
                                break;
                        }
                    }

                    echo '</div>';
                    break;

                /* ---- User ---- */
                case 'user':
                    $roles    = ! empty( $field['role'] ) ? $field['role'] : array();
                    $multiple = ! empty( $field['multiple'] );
                    $selected = $multiple ? ( is_array( $value ) ? array_filter( array_map( 'absint', $value ) ) : array() ) : array( absint( $value ) );
                    $selected = array_filter( $selected );

                    echo '<div class="jscfr-user-wrap" data-multiple="' . ( $multiple ? '1' : '0' ) . '" data-roles="' . esc_attr( wp_json_encode( $roles ) ) . '">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $multiple ? implode( ',', $selected ) : ( ! empty( $selected ) ? $selected[0] : '' ) ) . '" class="jscfr-user-value" />';
                    echo '<div class="jscfr-user-selected">';
                    foreach ( $selected as $uid ) {
                        $u = get_user_by( 'ID', $uid );
                        if ( $u ) {
                            echo '<span class="jscfr-po-tag" data-id="' . $uid . '">' . esc_html( $u->display_name ) . ' <button type="button" class="jscfr-user-remove">&times;</button></span>';
                        }
                    }
                    echo '</div>';
                    echo '<input type="text" class="widefat jscfr-user-search" placeholder="' . esc_attr__( 'Search users...', 'jscfr' ) . '" />';
                    echo '<div class="jscfr-user-results"></div>';
                    echo '</div>';
                    break;

                /* ---- Message (display only) ---- */
                case 'message':
                    $msg = isset( $field['message'] ) ? $field['message'] : '';
                    echo '<div class="jscfr-message">' . wp_kses_post( $msg ) . '</div>';
                    break;

                /* ---- Hidden ---- */
                case 'hidden':
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $value ) . '" />';
                    break;

                /* ---- Heading (display only) ---- */
                case 'heading':
                    $tag = isset( $field['heading_tag'] ) ? $field['heading_tag'] : 'h4';
                    $allowed = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
                    if ( ! in_array( $tag, $allowed, true ) ) $tag = 'h4';
                    echo '<' . $tag . ' class="jscfr-heading">' . esc_html( $field['label'] ) . '</' . $tag . '>';
                    break;

                /* ---- Divider (display only) ---- */
                case 'divider':
                    echo '<hr class="jscfr-divider" />';
                    break;

                /* ---- Switch (on/off toggle with labels) ---- */
                case 'switch':
                    $on  = isset( $field['on_label'] ) ? $field['on_label'] : 'On';
                    $off = isset( $field['off_label'] ) ? $field['off_label'] : 'Off';
                    echo '<div class="jscfr-switch-wrap">';
                    echo '<input type="hidden" name="' . $name . '" value="0" />';
                    echo '<label class="jscfr-switch">';
                    echo '<input type="checkbox" id="' . $domid . '" name="' . $name . '" value="1" ' . checked( $value, '1', false ) . ' />';
                    echo '<span class="jscfr-switch-slider"></span>';
                    echo '<span class="jscfr-switch-labels" data-on="' . esc_attr( $on ) . '" data-off="' . esc_attr( $off ) . '"></span>';
                    echo '</label>';
                    echo '</div>';
                    break;

                /* ---- Custom HTML (display only) ---- */
                case 'custom_html':
                    $html_content = isset( $field['html_content'] ) ? $field['html_content'] : '';
                    echo '<div class="jscfr-custom-html">' . wp_kses_post( $html_content ) . '</div>';
                    break;

                /* ---- Button (display only, JS action) ---- */
                case 'button':
                    $btn_label = isset( $field['button_label'] ) ? $field['button_label'] : 'Click';
                    $btn_class = isset( $field['button_class'] ) ? $field['button_class'] : '';
                    echo '<button type="button" class="button jscfr-action-btn ' . esc_attr( $btn_class ) . '" data-field="' . esc_attr( $fid ) . '">' . esc_html( $btn_label ) . '</button>';
                    break;

                /* ---- Image Select ---- */
                case 'image_select':
                    $img_opts = $this->parse_image_select_options( isset( $field['image_options'] ) ? $field['image_options'] : '' );
                    $is_multi = ! empty( $field['image_select_multiple'] );
                    $sel_vals = $is_multi ? ( is_array( $value ) ? $value : ( $value ? explode( ',', $value ) : array() ) ) : array( (string) $value );

                    echo '<div class="jscfr-image-select' . ( $is_multi ? ' jscfr-is-multi' : '' ) . '">';
                    if ( $is_multi ) {
                        foreach ( $img_opts as $opt ) {
                            $active = in_array( $opt['value'], $sel_vals, true ) ? ' jscfr-is-active' : '';
                            echo '<label class="jscfr-is-item' . $active . '">';
                            echo '<input type="checkbox" name="' . $name . '[]" value="' . esc_attr( $opt['value'] ) . '"' . ( $active ? ' checked' : '' ) . ' class="jscfr-is-input" />';
                            echo '<div class="jscfr-is-thumb">';
                            if ( ! empty( $opt['image'] ) ) {
                                echo '<img src="' . esc_url( $opt['image'] ) . '" alt="' . esc_attr( $opt['label'] ) . '" onerror="this.style.display=\'none\'" />';
                            }
                            echo '</div>';
                            if ( $opt['label'] ) echo '<span class="jscfr-is-label">' . esc_html( $opt['label'] ) . '</span>';
                            echo '</label>';
                        }
                    } else {
                        echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-is-value" />';
                        foreach ( $img_opts as $opt ) {
                            $active = ( (string) $value === $opt['value'] ) ? ' jscfr-is-active' : '';
                            echo '<div class="jscfr-is-item' . $active . '" data-value="' . esc_attr( $opt['value'] ) . '">';
                            echo '<div class="jscfr-is-thumb">';
                            if ( ! empty( $opt['image'] ) ) {
                                echo '<img src="' . esc_url( $opt['image'] ) . '" alt="' . esc_attr( $opt['label'] ) . '" onerror="this.style.display=\'none\'" />';
                            }
                            echo '</div>';
                            if ( $opt['label'] ) echo '<span class="jscfr-is-label">' . esc_html( $opt['label'] ) . '</span>';
                            echo '</div>';
                        }
                    }
                    echo '</div>';
                    break;

                /* ---- Key Value (repeatable key+value pairs) ---- */
                case 'key_value':
                    $pairs = is_array( $value ) ? $value : array();
                    echo '<div class="jscfr-kv-wrap" data-name="' . esc_attr( $name ) . '">';
                    echo '<div class="jscfr-kv-list">';
                    if ( ! empty( $pairs ) ) {
                        foreach ( $pairs as $ki => $pair ) {
                            $k = isset( $pair['key'] ) ? $pair['key'] : '';
                            $v = isset( $pair['value'] ) ? $pair['value'] : '';
                            echo '<div class="jscfr-kv-row">';
                            echo '<input type="text" name="' . $name . '[' . $ki . '][key]" value="' . esc_attr( $k ) . '" placeholder="' . esc_attr__( 'Key', 'jscfr' ) . '" class="jscfr-kv-key" />';
                            echo '<input type="text" name="' . $name . '[' . $ki . '][value]" value="' . esc_attr( $v ) . '" placeholder="' . esc_attr__( 'Value', 'jscfr' ) . '" class="jscfr-kv-val" />';
                            echo '<button type="button" class="button jscfr-kv-remove"><span class="dashicons dashicons-no-alt"></span></button>';
                            echo '</div>';
                        }
                    }
                    echo '</div>';
                    echo '<button type="button" class="button jscfr-kv-add"><span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;"></span>' . esc_html__( 'Add Pair', 'jscfr' ) . '</button>';
                    echo '</div>';
                    break;

                /* ---- Fieldset Text (labeled text inputs from options) ---- */
                case 'fieldset_text':
                    $subs = $this->parse_sub_field_options( isset( $field['sub_fields'] ) ? $field['sub_fields'] : '' );
                    $vals = is_array( $value ) ? $value : array();
                    echo '<div class="jscfr-fieldset-text">';
                    foreach ( $subs as $sk => $slabel ) {
                        $sv = isset( $vals[ $sk ] ) ? $vals[ $sk ] : '';
                        echo '<div class="jscfr-fs-field">';
                        echo '<label>' . esc_html( $slabel ) . '</label>';
                        echo '<input type="text" name="' . $name . '[' . esc_attr( $sk ) . ']" value="' . esc_attr( $sv ) . '" class="widefat" />';
                        echo '</div>';
                    }
                    echo '</div>';
                    break;

                /* ---- Text List (horizontal labeled inputs) ---- */
                case 'text_list':
                    $subs = $this->parse_sub_field_options( isset( $field['sub_fields'] ) ? $field['sub_fields'] : '' );
                    $vals = is_array( $value ) ? $value : array();
                    echo '<div class="jscfr-text-list">';
                    foreach ( $subs as $sk => $slabel ) {
                        $sv = isset( $vals[ $sk ] ) ? $vals[ $sk ] : '';
                        echo '<div class="jscfr-tl-field">';
                        echo '<label>' . esc_html( $slabel ) . '</label>';
                        echo '<input type="text" name="' . $name . '[' . esc_attr( $sk ) . ']" value="' . esc_attr( $sv ) . '" />';
                        echo '</div>';
                    }
                    echo '</div>';
                    break;

                /* ---- Sidebar (select registered sidebars) ---- */
                case 'sidebar':
                    global $wp_registered_sidebars;
                    $sidebars = is_array( $wp_registered_sidebars ) ? $wp_registered_sidebars : array();
                    echo '<select id="' . $domid . '" name="' . $name . '" class="widefat">';
                    echo '<option value="">' . esc_html__( '— Select Sidebar —', 'jscfr' ) . '</option>';
                    foreach ( $sidebars as $sb ) {
                        $selected = ( $value === $sb['id'] ) ? ' selected' : '';
                        echo '<option value="' . esc_attr( $sb['id'] ) . '"' . $selected . '>' . esc_html( $sb['name'] ) . '</option>';
                    }
                    echo '</select>';
                    break;

                /* ---- Single Image (one image, same as image but simplified) ---- */
                case 'single_image':
                    $img_url     = '';
                    $img_preview = '';
                    $preview_sz  = isset( $field['preview_size'] ) ? $field['preview_size'] : 'thumbnail';
                    if ( $value ) {
                        $img_src = wp_get_attachment_image_src( intval( $value ), $preview_sz );
                        if ( $img_src ) {
                            $img_url     = $img_src[0];
                            $img_preview = '<img src="' . esc_url( $img_url ) . '" alt="" />';
                        }
                    }
                    echo '<div class="jscfr-image-wrap jscfr-single-image" data-preview-size="' . esc_attr( $preview_sz ) . '">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-image-id" />';
                    echo '<div class="jscfr-image-preview">' . ( $img_preview ? $img_preview : '<span class="jscfr-image-placeholder"><span class="dashicons dashicons-format-image"></span></span>' ) . '</div>';
                    echo '<div class="jscfr-image-actions">';
                    echo '<button type="button" class="button jscfr-image-select" data-mime="image"><span class="dashicons dashicons-upload" style="vertical-align:middle;margin-right:4px;"></span>' . esc_html__( 'Select Image', 'jscfr' ) . '</button> ';
                    echo '<button type="button" class="button jscfr-image-clear" style="' . ( $value ? '' : 'display:none;' ) . '"><span class="dashicons dashicons-no" style="vertical-align:middle;"></span> ' . esc_html__( 'Remove', 'jscfr' ) . '</button>';
                    echo '</div>';
                    echo '</div>';
                    break;

                /* ---- File Input (URL text input + media picker) ---- */
                case 'file_input':
                    echo '<div class="jscfr-file-input-wrap">';
                    echo '<input type="url" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" class="widefat jscfr-fi-url" placeholder="' . esc_attr__( 'https:// or select file', 'jscfr' ) . '" />';
                    echo '<button type="button" class="button jscfr-fi-browse"><span class="dashicons dashicons-admin-media" style="vertical-align:middle;margin-right:4px;"></span>' . esc_html__( 'Browse', 'jscfr' ) . '</button>';
                    echo '</div>';
                    break;

                /* ---- Video ---- */
                case 'video':
                    $vid_url  = '';
                    $vid_name = '';
                    if ( $value ) {
                        $vid_url  = wp_get_attachment_url( intval( $value ) );
                        $vid_name = basename( get_attached_file( intval( $value ) ) ?: '' );
                    }
                    echo '<div class="jscfr-video-wrap' . ( $value ? ' has-video' : '' ) . '">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-file-id" />';
                    echo '<div class="jscfr-video-preview">';
                    if ( $vid_url ) {
                        echo '<video src="' . esc_url( $vid_url ) . '" controls preload="metadata"></video>';
                    } else {
                        echo '<div class="jscfr-video-empty"><span class="dashicons dashicons-video-alt3"></span><span class="jscfr-video-empty-text">' . esc_html__( 'No video selected', 'jscfr' ) . '</span></div>';
                    }
                    echo '</div>';
                    echo '<div class="jscfr-video-meta">';
                    echo '<span class="jscfr-file-name jscfr-video-name"' . ( $vid_name ? '' : ' data-empty="' . esc_attr__( 'No video selected', 'jscfr' ) . '"' ) . '>' . esc_html( $vid_name ) . '</span>';
                    echo '<div class="jscfr-video-actions">';
                    echo '<button type="button" class="button jscfr-upload" data-mime="video"><span class="dashicons dashicons-video-alt3"></span>' . esc_html__( $value ? 'Replace Video' : 'Select Video', 'jscfr' ) . '</button>';
                    echo '<button type="button" class="button jscfr-file-clear button-link-delete"' . ( $value ? '' : ' style="display:none;"' ) . '><span class="dashicons dashicons-trash"></span>' . esc_html__( 'Remove', 'jscfr' ) . '</button>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    break;

                /* ---- Background (composite: color + image + repeat + position + size + attachment) ---- */
                case 'background':
                    $bg = is_array( $value ) ? $value : array();
                    $bg = wp_parse_args( $bg, array(
                        'color'      => '',
                        'image'      => '',
                        'repeat'     => 'repeat',
                        'position'   => 'center center',
                        'size'       => 'auto',
                        'attachment' => 'scroll',
                    ) );

                    echo '<div class="jscfr-bg-wrap">';

                    if ( ! empty( $field['bg_color'] ) ) {
                        echo '<div class="jscfr-bg-field"><label>' . esc_html__( 'Color', 'jscfr' ) . '</label>';
                        echo '<input type="text" name="' . $name . '[color]" value="' . esc_attr( $bg['color'] ) . '" class="jscfr-color-picker" /></div>';
                    }

                    if ( ! empty( $field['bg_image'] ) ) {
                        $bg_img_preview = '';
                        if ( $bg['image'] ) {
                            $bg_img_preview = '<img src="' . esc_url( $bg['image'] ) . '" alt="" style="max-width:60px;max-height:60px;" />';
                        }
                        echo '<div class="jscfr-bg-field"><label>' . esc_html__( 'Image', 'jscfr' ) . '</label>';
                        echo '<div class="jscfr-file-input-wrap">';
                        echo '<input type="url" name="' . $name . '[image]" value="' . esc_attr( $bg['image'] ) . '" class="jscfr-fi-url" placeholder="' . esc_attr__( 'Image URL', 'jscfr' ) . '" />';
                        echo '<button type="button" class="button jscfr-fi-browse" data-mime="image"><span class="dashicons dashicons-format-image" style="vertical-align:middle;"></span></button>';
                        echo '</div>';
                        if ( $bg_img_preview ) echo '<div class="jscfr-bg-img-preview">' . $bg_img_preview . '</div>';
                        echo '</div>';
                    }

                    if ( ! empty( $field['bg_repeat'] ) ) {
                        echo '<div class="jscfr-bg-field"><label>' . esc_html__( 'Repeat', 'jscfr' ) . '</label>';
                        echo '<select name="' . $name . '[repeat]">';
                        foreach ( array( 'repeat', 'repeat-x', 'repeat-y', 'no-repeat' ) as $rp ) {
                            echo '<option value="' . $rp . '"' . selected( $bg['repeat'], $rp, false ) . '>' . $rp . '</option>';
                        }
                        echo '</select></div>';
                    }

                    if ( ! empty( $field['bg_position'] ) ) {
                        echo '<div class="jscfr-bg-field"><label>' . esc_html__( 'Position', 'jscfr' ) . '</label>';
                        echo '<select name="' . $name . '[position]">';
                        foreach ( array( 'left top', 'left center', 'left bottom', 'center top', 'center center', 'center bottom', 'right top', 'right center', 'right bottom' ) as $ps ) {
                            echo '<option value="' . $ps . '"' . selected( $bg['position'], $ps, false ) . '>' . $ps . '</option>';
                        }
                        echo '</select></div>';
                    }

                    if ( ! empty( $field['bg_size'] ) ) {
                        echo '<div class="jscfr-bg-field"><label>' . esc_html__( 'Size', 'jscfr' ) . '</label>';
                        echo '<select name="' . $name . '[size]">';
                        foreach ( array( 'auto', 'cover', 'contain' ) as $sz ) {
                            echo '<option value="' . $sz . '"' . selected( $bg['size'], $sz, false ) . '>' . $sz . '</option>';
                        }
                        echo '</select></div>';
                    }

                    if ( ! empty( $field['bg_attachment'] ) ) {
                        echo '<div class="jscfr-bg-field"><label>' . esc_html__( 'Attachment', 'jscfr' ) . '</label>';
                        echo '<select name="' . $name . '[attachment]">';
                        foreach ( array( 'scroll', 'fixed', 'local' ) as $at ) {
                            echo '<option value="' . $at . '"' . selected( $bg['attachment'], $at, false ) . '>' . $at . '</option>';
                        }
                        echo '</select></div>';
                    }

                    echo '</div>';
                    break;

                /* ---- Select Advanced (Select2) ---- */
                case 'select_advanced':
                    $options   = $this->parse_select_options( isset( $field['options'] ) ? $field['options'] : '' );
                    $multiple  = ! empty( $field['multiple'] );
                    $sel_vals  = $multiple ? (array) $value : array( $value );

                    if ( $multiple ) {
                        echo '<div class="jscfr-multi-wrap">';
                        echo '<select id="' . $domid . '" name="' . $name . '[]" class="jscfr-multi-hidden" multiple style="display:none;">';
                        foreach ( $options as $ov => $ol ) {
                            $selected = in_array( (string) $ov, $sel_vals, true ) ? ' selected' : '';
                            echo '<option value="' . esc_attr( $ov ) . '"' . $selected . '>' . esc_html( $ol ) . '</option>';
                        }
                        echo '</select>';
                        echo '<select class="widefat jscfr-multi-picker" data-target="' . $domid . '">';
                        echo '<option value="">' . esc_html( $ph ?: __( '— Select —', 'jscfr' ) ) . '</option>';
                        foreach ( $options as $ov => $ol ) {
                            $disabled = in_array( (string) $ov, $sel_vals, true ) ? ' disabled' : '';
                            echo '<option value="' . esc_attr( $ov ) . '"' . $disabled . '>' . esc_html( $ol ) . '</option>';
                        }
                        echo '</select>';
                        echo '<div class="jscfr-multi-tags" data-target="' . $domid . '">';
                        foreach ( $options as $ov => $ol ) {
                            if ( in_array( (string) $ov, $sel_vals, true ) ) {
                                echo '<span class="jscfr-multi-tag" data-value="' . esc_attr( $ov ) . '">' . esc_html( $ol ) . '<button type="button" class="jscfr-multi-tag-remove">&times;</button></span>';
                            }
                        }
                        echo '</div>';
                        echo '</div>';
                    } else {
                        echo '<select id="' . $domid . '" name="' . $name . '" class="widefat jscfr-select2" data-placeholder="' . esc_attr( $ph ?: __( '— Select —', 'jscfr' ) ) . '">';
                        if ( ! empty( $field['allow_null'] ) ) {
                            echo '<option value=""></option>';
                        }
                        foreach ( $options as $ov => $ol ) {
                            $selected = ( (string) $value === (string) $ov ) ? ' selected' : '';
                            echo '<option value="' . esc_attr( $ov ) . '"' . $selected . '>' . esc_html( $ol ) . '</option>';
                        }
                        echo '</select>';
                    }
                    break;

                /* ---- Slider (jQuery UI) ---- */
                case 'slider':
                    $min_v  = $field['min'] !== '' ? $field['min'] : '0';
                    $max_v  = $field['max'] !== '' ? $field['max'] : '100';
                    $step_v = ! empty( $field['step'] ) ? $field['step'] : '1';
                    $val    = $value !== '' ? $value : $min_v;

                    echo '<div class="jscfr-slider-wrap">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $val ) . '" class="jscfr-slider-value" />';
                    echo '<div class="jscfr-slider-track" data-min="' . esc_attr( $min_v ) . '" data-max="' . esc_attr( $max_v ) . '" data-step="' . esc_attr( $step_v ) . '" data-value="' . esc_attr( $val ) . '"></div>';
                    echo '<span class="jscfr-slider-display">' . esc_html( $val ) . '</span>';
                    echo '</div>';
                    break;

                /* ---- Autocomplete (jQuery UI) ---- */
                case 'autocomplete':
                    $ac_opts = isset( $field['autocomplete_options'] ) ? $field['autocomplete_options'] : ( isset( $field['options'] ) ? $field['options'] : '' );
                    $options_arr = array_values( $this->parse_select_options( $ac_opts ) );

                    echo '<div class="jscfr-autocomplete-wrap">';
                    echo '<input type="text" id="' . $domid . '" name="' . $name . '" value="' . esc_attr( $value ) . '" class="widefat jscfr-autocomplete-input" placeholder="' . esc_attr( $ph ) . '" data-source="' . esc_attr( wp_json_encode( $options_arr ) ) . '" autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" data-1p-ignore="true" />';
                    echo '</div>';
                    break;

                /* ---- File Upload (drag-drop area) ---- */
                case 'file_upload':
                    $att_url   = '';
                    $file_name = '';
                    if ( $value ) {
                        $att_url   = wp_get_attachment_url( intval( $value ) );
                        $file_name = basename( get_attached_file( intval( $value ) ) ?: '' );
                    }
                    $mime = isset( $field['mime'] ) ? $field['mime'] : '';

                    echo '<div class="jscfr-file-upload-wrap">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-file-id" />';
                    echo '<div class="jscfr-dropzone" data-mime="' . esc_attr( $mime ) . '">';
                    echo '<span class="dashicons dashicons-cloud-upload jscfr-dz-icon"></span>';
                    echo '<span class="jscfr-dz-text">' . esc_html__( 'Drop file here or click to upload', 'jscfr' ) . '</span>';
                    echo '</div>';
                    echo '<div class="jscfr-file-info"' . ( $file_name ? '' : ' style="display:none;"' ) . '>';
                    echo '<span class="jscfr-file-name">' . esc_html( $file_name ) . '</span>';
                    echo '<button type="button" class="button jscfr-file-clear"><span class="dashicons dashicons-no" style="vertical-align:middle;"></span></button>';
                    echo '</div>';
                    echo '</div>';
                    break;

                /* ---- Image Upload (drag-drop area, image-only) ---- */
                case 'image_upload':
                    $img_url     = '';
                    $img_preview = '';
                    $preview_sz  = isset( $field['preview_size'] ) ? $field['preview_size'] : 'thumbnail';
                    if ( $value ) {
                        $img_src = wp_get_attachment_image_src( intval( $value ), $preview_sz );
                        if ( $img_src ) {
                            $img_url     = $img_src[0];
                            $img_preview = '<img src="' . esc_url( $img_url ) . '" alt="" />';
                        }
                    }

                    echo '<div class="jscfr-image-upload-wrap">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-image-id" />';
                    echo '<div class="jscfr-dropzone" data-mime="image">';
                    if ( $img_preview ) {
                        echo '<div class="jscfr-image-preview">' . $img_preview . '</div>';
                    } else {
                        echo '<span class="dashicons dashicons-format-image jscfr-dz-icon"></span>';
                        echo '<span class="jscfr-dz-text">' . esc_html__( 'Drop image here or click to upload', 'jscfr' ) . '</span>';
                    }
                    echo '</div>';
                    echo '<div class="jscfr-image-actions"' . ( $value ? '' : ' style="display:none;"' ) . '>';
                    echo '<button type="button" class="button jscfr-image-clear"><span class="dashicons dashicons-no" style="vertical-align:middle;"></span> ' . esc_html__( 'Remove', 'jscfr' ) . '</button>';
                    echo '</div>';
                    echo '</div>';
                    break;

                /* ---- Taxonomy Advanced (stores term IDs as CSV, not as taxonomy terms) ---- */
                case 'taxonomy_advanced':
                    $tax_type   = ! empty( $field['taxonomy_type'] ) ? $field['taxonomy_type'] : 'category';
                    $appearance = isset( $field['field_type'] ) ? $field['field_type'] : 'checkbox';
                    $terms      = get_terms( array( 'taxonomy' => $tax_type, 'hide_empty' => false ) );
                    $sel_vals   = is_string( $value ) ? array_filter( explode( ',', $value ) ) : ( is_array( $value ) ? $value : array() );
                    $sel_vals   = array_map( 'strval', $sel_vals );

                    echo '<div class="jscfr-taxonomy-adv-wrap" data-taxonomy="' . esc_attr( $tax_type ) . '">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( implode( ',', $sel_vals ) ) . '" class="jscfr-tax-adv-value" />';

                    if ( ! is_wp_error( $terms ) ) {
                        switch ( $appearance ) {
                            case 'checkbox':
                                echo '<div class="jscfr-tax-list">';
                                foreach ( $terms as $term ) {
                                    $checked = in_array( (string) $term->term_id, $sel_vals, true ) ? ' checked' : '';
                                    echo '<label class="jscfr-cb-label"><input type="checkbox" class="jscfr-tax-adv-cb" value="' . esc_attr( $term->term_id ) . '"' . $checked . ' /> ' . esc_html( $term->name ) . '</label>';
                                }
                                echo '</div>';
                                break;

                            case 'select':
                                echo '<select class="widefat jscfr-tax-adv-sel">';
                                echo '<option value="">' . esc_html__( '— Select —', 'jscfr' ) . '</option>';
                                foreach ( $terms as $term ) {
                                    $selected = in_array( (string) $term->term_id, $sel_vals, true ) ? ' selected' : '';
                                    echo '<option value="' . esc_attr( $term->term_id ) . '"' . $selected . '>' . esc_html( $term->name ) . '</option>';
                                }
                                echo '</select>';
                                break;

                            case 'multi_select':
                                $adv_domid = $domid . '_adv';
                                echo '<div class="jscfr-multi-wrap">';
                                echo '<select id="' . $adv_domid . '" class="jscfr-tax-adv-sel jscfr-multi-hidden" multiple style="display:none;">';
                                foreach ( $terms as $term ) {
                                    $selected = in_array( (string) $term->term_id, $sel_vals, true ) ? ' selected' : '';
                                    echo '<option value="' . esc_attr( $term->term_id ) . '"' . $selected . '>' . esc_html( $term->name ) . '</option>';
                                }
                                echo '</select>';
                                echo '<select class="widefat jscfr-multi-picker" data-target="' . $adv_domid . '">';
                                echo '<option value="">' . esc_html__( '— Select —', 'jscfr' ) . '</option>';
                                foreach ( $terms as $term ) {
                                    $disabled = in_array( (string) $term->term_id, $sel_vals, true ) ? ' disabled' : '';
                                    echo '<option value="' . esc_attr( $term->term_id ) . '"' . $disabled . '>' . esc_html( $term->name ) . '</option>';
                                }
                                echo '</select>';
                                echo '<div class="jscfr-multi-tags" data-target="' . $adv_domid . '">';
                                foreach ( $terms as $term ) {
                                    if ( in_array( (string) $term->term_id, $sel_vals, true ) ) {
                                        echo '<span class="jscfr-multi-tag" data-value="' . esc_attr( $term->term_id ) . '">' . esc_html( $term->name ) . '<button type="button" class="jscfr-multi-tag-remove">&times;</button></span>';
                                    }
                                }
                                echo '</div>';
                                echo '</div>';
                                break;

                            default: // radio
                                echo '<div class="jscfr-tax-list">';
                                foreach ( $terms as $term ) {
                                    $checked = in_array( (string) $term->term_id, $sel_vals, true ) ? ' checked' : '';
                                    echo '<label class="jscfr-radio-label"><input type="radio" class="jscfr-tax-adv-radio" value="' . esc_attr( $term->term_id ) . '"' . $checked . ' /> ' . esc_html( $term->name ) . '</label>';
                                }
                                echo '</div>';
                        }
                    }

                    echo '</div>';
                    break;

                /* ---- Icon Picker ---- */
                case 'icon':
                    $icon_type = isset( $field['icon_type'] ) ? $field['icon_type'] : 'dashicons';
                    $is_svg    = ( is_string( $value ) && strpos( $value, 'svg:' ) === 0 );
                    $svg_code  = $is_svg ? substr( $value, 4 ) : '';
                    echo '<div class="jscfr-icon-picker-wrap" data-icon-type="' . esc_attr( $icon_type ) . '">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( $value ) . '" class="jscfr-icon-value" />';
                    echo '<div class="jscfr-icon-preview">';
                    if ( $is_svg && $svg_code ) {
                        echo wp_kses( $svg_code, self::svg_allowed_tags() );
                    } elseif ( $value ) {
                        echo '<span class="' . esc_attr( $value ) . '"></span>';
                    } else {
                        echo '<span class="jscfr-icon-placeholder">' . esc_html__( 'No icon', 'jscfr' ) . '</span>';
                    }
                    echo '</div>';
                    echo '<button type="button" class="button jscfr-icon-select">' . esc_html__( 'Select Icon', 'jscfr' ) . '</button> ';
                    echo '<button type="button" class="button jscfr-icon-clear" style="' . ( $value ? '' : 'display:none;' ) . '">' . esc_html__( 'Remove', 'jscfr' ) . '</button>';
                    echo '<div class="jscfr-icon-picker-panel" style="display:none;">';
                    echo '<div class="jscfr-icon-tabs">';
                    echo '<button type="button" class="jscfr-icon-tab jscfr-icon-tab-active" data-tab="dashicons"><span class="dashicons dashicons-screenoptions"></span> ' . esc_html__( 'Dashicons', 'jscfr' ) . '</button>';
                    echo '<button type="button" class="jscfr-icon-tab" data-tab="svg"><span class="dashicons dashicons-media-code"></span> ' . esc_html__( 'Custom SVG', 'jscfr' ) . '</button>';
                    echo '</div>';
                    echo '<div class="jscfr-icon-tab-body" data-body="dashicons">';
                    echo '<input type="text" class="widefat jscfr-icon-search" placeholder="' . esc_attr__( 'Search icons...', 'jscfr' ) . '" />';
                    echo '<div class="jscfr-icon-grid"></div>';
                    echo '</div>';
                    echo '<div class="jscfr-icon-tab-body jscfr-icon-svg-body" data-body="svg" style="display:none;">';
                    echo '<div class="jscfr-icon-svg-live" aria-hidden="true">' . ( $is_svg && $svg_code ? wp_kses( $svg_code, self::svg_allowed_tags() ) : '' ) . '</div>';
                    echo '<input type="file" accept=".svg,image/svg+xml" class="jscfr-icon-svg-file" style="display:none;" />';
                    echo '<div class="jscfr-icon-svg-actions">';
                    echo '<button type="button" class="button button-primary jscfr-icon-svg-upload"><span class="dashicons dashicons-upload"></span> ' . esc_html__( 'Choose SVG File', 'jscfr' ) . '</button>';
                    echo '<button type="button" class="button jscfr-icon-svg-remove" style="' . ( $is_svg ? '' : 'display:none;' ) . '"><span class="dashicons dashicons-no-alt"></span> ' . esc_html__( 'Remove', 'jscfr' ) . '</button>';
                    echo '<span class="jscfr-icon-svg-msg" aria-live="polite"></span>';
                    echo '</div>';
                    echo '<p class="jscfr-icon-svg-note"><span class="dashicons dashicons-shield"></span> ' . esc_html__( 'Pick an SVG file from your computer — it is read locally and stored inline (no upload to Media Library). Scripts, event handlers, and external links are stripped.', 'jscfr' ) . '</p>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    break;

                /* ---- File Advanced (multi-file with list view) ---- */
                case 'file_advanced':
                    $file_ids = is_array( $value ) ? $value : ( $value ? explode( ',', $value ) : array() );
                    $file_ids = array_filter( array_map( 'absint', $file_ids ) );
                    $max_files = isset( $field['max_count'] ) && $field['max_count'] !== '' ? intval( $field['max_count'] ) : 0;
                    $mime = isset( $field['mime'] ) ? $field['mime'] : '';

                    echo '<div class="jscfr-file-advanced-wrap" data-max="' . $max_files . '" data-mime="' . esc_attr( $mime ) . '">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( implode( ',', $file_ids ) ) . '" class="jscfr-fa-ids" />';
                    echo '<div class="jscfr-fa-list">';
                    foreach ( $file_ids as $faid ) {
                        $fa_url  = wp_get_attachment_url( $faid );
                        $fa_name = basename( get_attached_file( $faid ) ?: '' );
                        $fa_size = size_format( filesize( get_attached_file( $faid ) ?: '' ) ?: 0 );
                        echo '<div class="jscfr-fa-item" data-id="' . $faid . '">';
                        echo '<span class="dashicons dashicons-media-default"></span>';
                        echo '<span class="jscfr-fa-name">' . esc_html( $fa_name ) . '</span>';
                        echo '<span class="jscfr-fa-size">' . esc_html( $fa_size ) . '</span>';
                        echo '<a href="' . esc_url( $fa_url ) . '" target="_blank" class="jscfr-fa-view"><span class="dashicons dashicons-visibility"></span></a>';
                        echo '<button type="button" class="jscfr-fa-remove"><span class="dashicons dashicons-no-alt"></span></button>';
                        echo '</div>';
                    }
                    echo '</div>';
                    echo '<button type="button" class="button jscfr-fa-add"><span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;"></span>' . esc_html__( 'Add Files', 'jscfr' ) . '</button>';
                    echo '</div>';
                    break;

                /* ---- Image Advanced (multi-image with meta details) ---- */
                case 'image_advanced':
                    $img_ids = is_array( $value ) ? $value : ( $value ? explode( ',', $value ) : array() );
                    $img_ids = array_filter( array_map( 'absint', $img_ids ) );
                    $max_imgs = isset( $field['max_count'] ) && $field['max_count'] !== '' ? intval( $field['max_count'] ) : 0;
                    $preview_sz = isset( $field['preview_size'] ) ? $field['preview_size'] : 'thumbnail';

                    echo '<div class="jscfr-image-advanced-wrap" data-max="' . $max_imgs . '">';
                    echo '<input type="hidden" name="' . $name . '" value="' . esc_attr( implode( ',', $img_ids ) ) . '" class="jscfr-ia-ids" />';
                    echo '<div class="jscfr-ia-grid">';
                    foreach ( $img_ids as $iaid ) {
                        $ia_thumb = wp_get_attachment_image_src( $iaid, $preview_sz );
                        if ( $ia_thumb ) {
                            echo '<div class="jscfr-ia-item" data-id="' . $iaid . '">';
                            echo '<img src="' . esc_url( $ia_thumb[0] ) . '" alt="" />';
                            echo '<button type="button" class="jscfr-ia-remove"><span class="dashicons dashicons-no-alt"></span></button>';
                            echo '</div>';
                        }
                    }
                    echo '</div>';
                    echo '<button type="button" class="button jscfr-ia-add"><span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px;"></span>' . esc_html__( 'Add Images', 'jscfr' ) . '</button>';
                    echo '</div>';
                    break;

                /* ---- Google Map (Leaflet + Google-style tiles) ---- */
                case 'google_map':
                    $map_data = is_array( $value ) ? $value : array();
                    $map_data = wp_parse_args( $map_data, array( 'lat' => '', 'lng' => '', 'zoom' => '14', 'address' => '' ) );
                    echo '<div class="jscfr-map-wrap jscfr-gmap-wrap" data-tile-style="google">';
                    echo '<div class="jscfr-map-search-row">';
                    echo '<input type="text" class="widefat jscfr-map-address jscfr-gmap-address" name="' . $name . '[address]" value="' . esc_attr( $map_data['address'] ) . '" placeholder="' . esc_attr__( 'Search address...', 'jscfr' ) . '" />';
                    echo '<button type="button" class="button jscfr-map-search-btn"><span class="dashicons dashicons-search"></span></button>';
                    echo '</div>';
                    echo '<div class="jscfr-map-canvas jscfr-gmap-canvas" id="' . $domid . '_map"></div>';
                    echo '<div class="jscfr-map-coords jscfr-gmap-coords">';
                    echo '<label>' . esc_html__( 'Lat', 'jscfr' ) . ' <input type="text" name="' . $name . '[lat]" value="' . esc_attr( $map_data['lat'] ) . '" class="jscfr-map-lat jscfr-gmap-lat" /></label>';
                    echo '<label>' . esc_html__( 'Lng', 'jscfr' ) . ' <input type="text" name="' . $name . '[lng]" value="' . esc_attr( $map_data['lng'] ) . '" class="jscfr-map-lng jscfr-gmap-lng" /></label>';
                    echo '<label>' . esc_html__( 'Zoom', 'jscfr' ) . ' <input type="number" name="' . $name . '[zoom]" value="' . esc_attr( $map_data['zoom'] ) . '" class="jscfr-map-zoom jscfr-gmap-zoom" min="0" max="19" /></label>';
                    echo '</div>';
                    echo '</div>';
                    break;

                /* ---- OpenStreetMap (Leaflet + OSM tiles) ---- */
                case 'osm':
                    $osm_data = is_array( $value ) ? $value : array();
                    $osm_data = wp_parse_args( $osm_data, array( 'lat' => '51.505', 'lng' => '-0.09', 'zoom' => '13', 'address' => '' ) );
                    echo '<div class="jscfr-map-wrap jscfr-osm-wrap" data-tile-style="osm">';
                    echo '<div class="jscfr-map-search-row">';
                    echo '<input type="text" class="widefat jscfr-map-address jscfr-osm-address" name="' . $name . '[address]" value="' . esc_attr( $osm_data['address'] ) . '" placeholder="' . esc_attr__( 'Search address...', 'jscfr' ) . '" />';
                    echo '<button type="button" class="button jscfr-map-search-btn"><span class="dashicons dashicons-search"></span></button>';
                    echo '</div>';
                    echo '<div class="jscfr-map-canvas jscfr-osm-canvas" id="' . $domid . '_map"></div>';
                    echo '<div class="jscfr-map-coords jscfr-osm-coords">';
                    echo '<label>' . esc_html__( 'Lat', 'jscfr' ) . ' <input type="text" name="' . $name . '[lat]" value="' . esc_attr( $osm_data['lat'] ) . '" class="jscfr-map-lat jscfr-osm-lat" /></label>';
                    echo '<label>' . esc_html__( 'Lng', 'jscfr' ) . ' <input type="text" name="' . $name . '[lng]" value="' . esc_attr( $osm_data['lng'] ) . '" class="jscfr-map-lng jscfr-osm-lng" /></label>';
                    echo '<label>' . esc_html__( 'Zoom', 'jscfr' ) . ' <input type="number" name="' . $name . '[zoom]" value="' . esc_attr( $osm_data['zoom'] ) . '" class="jscfr-map-zoom jscfr-osm-zoom" min="0" max="19" /></label>';
                    echo '</div>';
                    echo '</div>';
                    break;
            }

            // Close prepend/append wrapper
            if ( $has_prepend || $has_append ) {
                if ( $has_append ) {
                    echo '<span class="jscfr-append">' . esc_html( $field['append'] ) . '</span>';
                }
                echo '</div>';
            }

            // Input Description (below input)
            if ( ! empty( $field['input_description'] ) ) {
                echo '<p class="jscfr-input-desc">' . esc_html( $field['input_description'] ) . '</p>';
            }

            // HTML After
            if ( ! empty( $field['html_after'] ) ) {
                echo wp_kses_post( $field['html_after'] );
            }

            echo '</div>'; // .jscfr-fld
        }

        private function parse_select_options( $raw ) {
            $options = array();
            foreach ( preg_split( '/\r?\n/', trim( $raw ) ) as $line ) {
                $line = trim( $line );
                if ( '' === $line ) continue;
                if ( strpos( $line, '|' ) !== false ) {
                    list( $v, $l ) = explode( '|', $line, 2 );
                    $options[ trim( $v ) ] = trim( $l );
                } else {
                    $options[ $line ] = $line;
                }
            }
            return $options;
        }

        /**
         * Parse image_select options. Format per line: value|image_url|label
         */
        private function parse_image_select_options( $raw ) {
            $options = array();
            foreach ( preg_split( '/\r?\n/', trim( $raw ) ) as $line ) {
                $line = trim( $line );
                if ( '' === $line ) continue;
                $parts = explode( '|', $line, 3 );
                $options[] = array(
                    'value' => trim( $parts[0] ),
                    'image' => isset( $parts[1] ) ? trim( $parts[1] ) : '',
                    'label' => isset( $parts[2] ) ? trim( $parts[2] ) : '',
                );
            }
            return $options;
        }

        /**
         * Parse sub_fields options for fieldset_text / text_list. Format per line: key|Label
         */
        private function parse_sub_field_options( $raw ) {
            $options = array();
            foreach ( preg_split( '/\r?\n/', trim( $raw ) ) as $line ) {
                $line = trim( $line );
                if ( '' === $line ) continue;
                if ( strpos( $line, '|' ) !== false ) {
                    list( $k, $l ) = explode( '|', $line, 2 );
                    $options[ trim( $k ) ] = trim( $l );
                } else {
                    $options[ sanitize_key( $line ) ] = $line;
                }
            }
            return $options;
        }

        /* ---------------------------------------------------------- */
        /*  Save                                                       */
        /* ---------------------------------------------------------- */
        public function save( $post_id, $post ) {
            if ( ! isset( $_POST[ JSCFR_NONCE_NAME ] ) || ! wp_verify_nonce( $_POST[ JSCFR_NONCE_NAME ], JSCFR_NONCE_ACTION ) ) {
                return;
            }
            $pt_obj = get_post_type_object( $post->post_type );
            if ( ! current_user_can( $pt_obj->cap->edit_post, $post_id ) ) {
                return;
            }
            if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
                return;
            }

            $field_groups = JSCFR_Plugin::get_field_groups_for_post( $post );
            if ( empty( $field_groups ) ) return;

            do_action( 'jscfr/before_save_post', $post_id, $post );

            $raw = isset( $_POST['jscfr_data'] ) ? $_POST['jscfr_data'] : array();

            $existing = get_post_meta( $post_id, JSCFR_META_KEY, true );
            if ( ! is_array( $existing ) ) {
                $existing = array();
            }

            foreach ( $field_groups as $fg ) {
                $fgid = $fg['id'];
                if ( ! isset( $raw[ $fgid ] ) || ! is_array( $raw[ $fgid ] ) ) continue;

                $existing[ $fgid ] = array();

                foreach ( $fg['tabs'] as $tab ) {
                    $tid = $tab['id'];
                    if ( ! isset( $raw[ $fgid ][ $tid ] ) || ! is_array( $raw[ $fgid ][ $tid ] ) ) continue;

                    $existing[ $fgid ][ $tid ] = array();

                    foreach ( $tab['groups'] as $group ) {
                        $gid = $group['id'];
                        if ( ! isset( $raw[ $fgid ][ $tid ][ $gid ] ) || ! is_array( $raw[ $fgid ][ $tid ][ $gid ] ) ) continue;

                        $clean_rows = array();

                        foreach ( $raw[ $fgid ][ $tid ][ $gid ] as $row ) {
                            if ( ! is_array( $row ) ) continue;

                            $clean_row = array();
                            $has_val   = false;

                            foreach ( $group['fields'] as $field ) {
                                $f_id = $field['id'];
                                $val  = isset( $row[ $f_id ] ) ? $row[ $f_id ] : '';

                                $val = $this->sanitize_field_value( $val, $field );
                                $val = apply_filters( 'jscfr/sanitize_value', $val, $field, $post_id );
                                $val = apply_filters( 'jscfr/sanitize_value/type=' . $field['type'], $val, $field, $post_id );

                                $clean_row[ $f_id ] = $val;
                                if ( $this->has_value( $val ) ) {
                                    $has_val = true;
                                }
                            }

                            if ( $has_val ) {
                                $clean_rows[] = $clean_row;
                            }
                        }

                        // Save taxonomy terms if save_terms is enabled
                        foreach ( $group['fields'] as $field ) {
                            if ( 'taxonomy' === $field['type'] && ! empty( $field['save_terms'] ) && ! empty( $field['taxonomy_type'] ) ) {
                                $term_ids = array();
                                foreach ( $clean_rows as $crow ) {
                                    $tv = isset( $crow[ $field['id'] ] ) ? $crow[ $field['id'] ] : array();
                                    if ( is_array( $tv ) ) {
                                        $term_ids = array_merge( $term_ids, array_map( 'intval', $tv ) );
                                    } elseif ( $tv ) {
                                        $term_ids[] = intval( $tv );
                                    }
                                }
                                wp_set_post_terms( $post_id, array_unique( $term_ids ), $field['taxonomy_type'] );
                            }
                        }

                        $existing[ $fgid ][ $tid ][ $gid ] = array_values( $clean_rows );
                    }
                }
            }

            if ( ! empty( $existing ) ) {
                update_post_meta( $post_id, JSCFR_META_KEY, $existing );
            } else {
                delete_post_meta( $post_id, JSCFR_META_KEY );
            }

            // v5: Write individual meta rows per field (dual-write for transition)
            $field_map = array();
            foreach ( $field_groups as $fg ) {
                $fgid = $fg['id'];
                if ( empty( $fg['tabs'] ) ) continue;

                foreach ( $fg['tabs'] as $tab ) {
                    $tid = $tab['id'];
                    if ( empty( $tab['groups'] ) ) continue;

                    foreach ( $tab['groups'] as $group ) {
                        $gid  = $group['id'];
                        $rows = isset( $existing[ $fgid ][ $tid ][ $gid ] ) ? $existing[ $fgid ][ $tid ][ $gid ] : array();

                        // Store group rows under group name
                        $group_name = ! empty( $group['name'] ) ? $group['name'] : $group['id'];
                        JSCFR_Plugin::set_field_value( $group_name, $rows, $post_id );

                        $field_map[ $group_name ] = array(
                            'type'     => 'group',
                            'fg_id'    => $fgid,
                            'tab_id'   => $tid,
                            'group_id' => $gid,
                        );

                        // Store first row's fields individually (enables WP_Query)
                        if ( ! empty( $group['fields'] ) ) {
                            foreach ( $group['fields'] as $field ) {
                                $field_name = ! empty( $field['name'] ) ? $field['name'] : $field['id'];
                                $val = ( ! empty( $rows[0] ) && isset( $rows[0][ $field['id'] ] ) ) ? $rows[0][ $field['id'] ] : '';
                                JSCFR_Plugin::set_field_value( $field_name, $val, $post_id );

                                $field_map[ $field_name ] = array(
                                    'type'     => 'field',
                                    'fg_id'    => $fgid,
                                    'tab_id'   => $tid,
                                    'group_id' => $gid,
                                    'field_id' => $field['id'],
                                );
                            }
                        }
                    }
                }
            }

            // Store field group map on this post
            if ( ! empty( $field_map ) ) {
                update_post_meta( $post_id, JSCFR_FIELD_MAP_KEY, $field_map );
            }

            do_action( 'jscfr/after_save_post', $post_id, $post );
        }

        /**
         * Sanitize a single field value by type.
         */
        public function sanitize_field_value( $val, $field ) {
            switch ( $field['type'] ) {
                case 'text':
                case 'date':
                case 'datetime':
                case 'time':
                case 'color':
                case 'password':
                case 'button_group':
                    return sanitize_text_field( $val );

                case 'textarea':
                    return sanitize_textarea_field( $val );

                case 'wysiwyg':
                    return wp_kses_post( $val );

                case 'url':
                case 'oembed':
                    return esc_url_raw( $val );

                case 'email':
                    return sanitize_email( $val );

                case 'number':
                case 'range':
                    return is_numeric( $val ) ? floatval( $val ) : '';

                case 'image':
                case 'file':
                    return absint( $val );

                case 'gallery':
                    // Comma-separated IDs
                    if ( is_string( $val ) ) {
                        return implode( ',', array_filter( array_map( 'absint', explode( ',', $val ) ) ) );
                    }
                    return '';

                case 'select':
                case 'radio':
                    if ( is_array( $val ) ) {
                        return array_map( 'sanitize_text_field', $val );
                    }
                    return sanitize_text_field( $val );

                case 'checkbox':
                    if ( is_array( $val ) ) {
                        return array_map( 'sanitize_text_field', $val );
                    }
                    return ( '1' === $val ) ? '1' : '0';

                case 'true_false':
                    return ( '1' === (string) $val ) ? '1' : '0';

                case 'link':
                    if ( is_array( $val ) ) {
                        return array(
                            'url'    => esc_url_raw( isset( $val['url'] ) ? $val['url'] : '' ),
                            'title'  => sanitize_text_field( isset( $val['title'] ) ? $val['title'] : '' ),
                            'target' => ( isset( $val['target'] ) && '_blank' === $val['target'] ) ? '_blank' : '',
                        );
                    }
                    return array( 'url' => '', 'title' => '', 'target' => '' );

                case 'post_object':
                    if ( ! empty( $field['multiple'] ) ) {
                        if ( is_string( $val ) ) {
                            return array_filter( array_map( 'absint', explode( ',', $val ) ) );
                        }
                        return is_array( $val ) ? array_filter( array_map( 'absint', $val ) ) : array();
                    }
                    return absint( $val );

                case 'relationship':
                    if ( is_string( $val ) ) {
                        return array_filter( array_map( 'absint', explode( ',', $val ) ) );
                    }
                    return is_array( $val ) ? array_filter( array_map( 'absint', $val ) ) : array();

                case 'taxonomy':
                    if ( is_array( $val ) ) {
                        return array_map( 'sanitize_text_field', $val );
                    }
                    return sanitize_text_field( $val );

                case 'user':
                    if ( ! empty( $field['multiple'] ) ) {
                        if ( is_string( $val ) ) {
                            return array_filter( array_map( 'absint', explode( ',', $val ) ) );
                        }
                        return is_array( $val ) ? array_filter( array_map( 'absint', $val ) ) : array();
                    }
                    return absint( $val );

                case 'message':
                case 'heading':
                case 'divider':
                case 'custom_html':
                case 'button':
                    return '';

                case 'hidden':
                    return sanitize_text_field( $val );

                case 'switch':
                    return ( '1' === (string) $val ) ? '1' : '0';

                case 'image_select':
                    if ( is_array( $val ) ) {
                        return array_map( 'sanitize_text_field', $val );
                    }
                    return sanitize_text_field( $val );

                case 'key_value':
                    if ( ! is_array( $val ) ) return array();
                    $clean = array();
                    foreach ( $val as $pair ) {
                        if ( ! is_array( $pair ) ) continue;
                        $k = isset( $pair['key'] ) ? sanitize_text_field( $pair['key'] ) : '';
                        $v = isset( $pair['value'] ) ? sanitize_text_field( $pair['value'] ) : '';
                        if ( '' !== $k || '' !== $v ) {
                            $clean[] = array( 'key' => $k, 'value' => $v );
                        }
                    }
                    return $clean;

                case 'fieldset_text':
                case 'text_list':
                    if ( ! is_array( $val ) ) return array();
                    return array_map( 'sanitize_text_field', $val );

                case 'sidebar':
                    return sanitize_key( $val );

                case 'single_image':
                case 'video':
                    return absint( $val );

                case 'file_input':
                    return esc_url_raw( $val );

                case 'background':
                    if ( ! is_array( $val ) ) return array();
                    return array(
                        'color'      => sanitize_hex_color( isset( $val['color'] ) ? $val['color'] : '' ),
                        'image'      => esc_url_raw( isset( $val['image'] ) ? $val['image'] : '' ),
                        'repeat'     => sanitize_text_field( isset( $val['repeat'] ) ? $val['repeat'] : 'repeat' ),
                        'position'   => sanitize_text_field( isset( $val['position'] ) ? $val['position'] : 'center center' ),
                        'size'       => sanitize_text_field( isset( $val['size'] ) ? $val['size'] : 'auto' ),
                        'attachment' => sanitize_text_field( isset( $val['attachment'] ) ? $val['attachment'] : 'scroll' ),
                    );

                case 'select_advanced':
                case 'autocomplete':
                    if ( is_array( $val ) ) {
                        return array_map( 'sanitize_text_field', $val );
                    }
                    return sanitize_text_field( $val );

                case 'slider':
                    return is_numeric( $val ) ? floatval( $val ) : '';

                case 'file_upload':
                case 'image_upload':
                    return absint( $val );

                case 'taxonomy_advanced':
                    // CSV string of term IDs
                    if ( is_string( $val ) ) {
                        return implode( ',', array_filter( array_map( 'absint', explode( ',', $val ) ) ) );
                    }
                    return '';

                case 'google_map':
                case 'osm':
                    if ( ! is_array( $val ) ) return array();
                    return array(
                        'address' => sanitize_text_field( isset( $val['address'] ) ? $val['address'] : '' ),
                        'lat'     => sanitize_text_field( isset( $val['lat'] ) ? $val['lat'] : '' ),
                        'lng'     => sanitize_text_field( isset( $val['lng'] ) ? $val['lng'] : '' ),
                        'zoom'    => absint( isset( $val['zoom'] ) ? $val['zoom'] : 14 ),
                    );

                case 'icon':
                    if ( is_string( $val ) && strpos( $val, 'svg:' ) === 0 ) {
                        $svg = substr( $val, 4 );
                        $svg = wp_kses( $svg, self::svg_allowed_tags() );
                        $svg = trim( $svg );
                        return $svg ? 'svg:' . $svg : '';
                    }
                    return sanitize_text_field( $val );

                case 'file_advanced':
                case 'image_advanced':
                    if ( is_string( $val ) ) {
                        return implode( ',', array_filter( array_map( 'absint', explode( ',', $val ) ) ) );
                    }
                    return '';

                default:
                    return sanitize_text_field( is_array( $val ) ? '' : $val );
            }
        }

        /**
         * Allowed SVG tags & attributes for custom SVG icons.
         */
        public static function svg_allowed_tags() {
            $common = array(
                'fill' => true, 'stroke' => true, 'stroke-width' => true,
                'stroke-linecap' => true, 'stroke-linejoin' => true,
                'stroke-dasharray' => true, 'stroke-opacity' => true,
                'fill-opacity' => true, 'fill-rule' => true, 'clip-rule' => true,
                'opacity' => true, 'transform' => true, 'class' => true,
                'id' => true, 'style' => true,
            );
            return array(
                'svg'            => array_merge( $common, array(
                    'xmlns' => true, 'xmlns:xlink' => true, 'viewbox' => true,
                    'width' => true, 'height' => true,
                    'preserveaspectratio' => true, 'aria-hidden' => true, 'role' => true,
                ) ),
                'g'              => $common,
                'path'           => array_merge( $common, array( 'd' => true ) ),
                'circle'         => array_merge( $common, array( 'cx' => true, 'cy' => true, 'r' => true ) ),
                'rect'           => array_merge( $common, array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true ) ),
                'line'           => array_merge( $common, array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ) ),
                'polyline'       => array_merge( $common, array( 'points' => true ) ),
                'polygon'        => array_merge( $common, array( 'points' => true ) ),
                'ellipse'        => array_merge( $common, array( 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true ) ),
                'text'           => array_merge( $common, array( 'x' => true, 'y' => true, 'dx' => true, 'dy' => true, 'text-anchor' => true, 'font-size' => true, 'font-family' => true, 'font-weight' => true ) ),
                'tspan'          => array_merge( $common, array( 'x' => true, 'y' => true, 'dx' => true, 'dy' => true ) ),
                'defs'           => array(),
                'lineargradient' => array( 'id' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'gradienttransform' => true, 'gradientunits' => true ),
                'radialgradient' => array( 'id' => true, 'cx' => true, 'cy' => true, 'r' => true, 'fx' => true, 'fy' => true, 'gradienttransform' => true, 'gradientunits' => true ),
                'stop'           => array( 'offset' => true, 'stop-color' => true, 'stop-opacity' => true ),
                'symbol'         => array( 'id' => true, 'viewbox' => true ),
                'use'            => array( 'href' => true, 'xlink:href' => true, 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'fill' => true, 'transform' => true ),
                'mask'           => array( 'id' => true, 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'maskunits' => true ),
                'clippath'       => array( 'id' => true, 'clippathunits' => true ),
                'title'          => array(),
                'desc'           => array(),
            );
        }

        /**
         * Check if a value is considered "non-empty" for row-skipping logic.
         */
        private function has_value( $val ) {
            if ( is_array( $val ) ) {
                // Link field
                if ( isset( $val['url'] ) ) {
                    return ! empty( $val['url'] ) || ! empty( $val['title'] );
                }
                // Map fields (google_map / osm)
                if ( isset( $val['lat'] ) || isset( $val['lng'] ) ) {
                    return ! empty( $val['lat'] ) || ! empty( $val['lng'] ) || ! empty( $val['address'] );
                }
                return ! empty( $val );
            }
            return ! empty( $val );
        }
    }
}

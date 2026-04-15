<?php
/**
 * JSCFR Term Meta — Renders field groups on taxonomy term edit screens.
 * Location rule param: taxonomy_term, value: taxonomy slug.
 * Stores in wp_termmeta with _jscfr_ prefix.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_Term_Meta' ) ) {

    final class JSCFR_Term_Meta {

        private static $instance = null;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'admin_init', array( $this, 'register_hooks' ) );
        }

        public function register_hooks() {
            $taxonomies = $this->get_targeted_taxonomies();
            foreach ( $taxonomies as $tax ) {
                add_action( "{$tax}_edit_form_fields", array( $this, 'render_fields' ), 10, 2 );
                add_action( "edited_{$tax}", array( $this, 'save_fields' ), 10, 2 );
                add_action( "{$tax}_add_form_fields", array( $this, 'render_add_fields' ), 10 );
                add_action( "created_{$tax}", array( $this, 'save_fields' ), 10, 2 );
            }

            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        }

        private function get_targeted_taxonomies() {
            $taxonomies = array();
            foreach ( JSCFR_Plugin::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) continue;
                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                foreach ( $rules as $or_group ) {
                    foreach ( $or_group as $rule ) {
                        if ( 'taxonomy_term' === ( isset( $rule['param'] ) ? $rule['param'] : '' ) && ! empty( $rule['value'] ) ) {
                            $taxonomies[] = $rule['value'];
                        }
                    }
                }
            }
            return array_unique( $taxonomies );
        }

        private function get_field_groups_for_taxonomy( $taxonomy ) {
            $matched = array();
            foreach ( JSCFR_Plugin::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) continue;
                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                foreach ( $rules as $or_group ) {
                    foreach ( $or_group as $rule ) {
                        if ( 'taxonomy_term' === ( isset( $rule['param'] ) ? $rule['param'] : '' )
                            && 'is_equal_to' === ( isset( $rule['operator'] ) ? $rule['operator'] : '' )
                            && $rule['value'] === $taxonomy
                        ) {
                            $matched[] = $fg;
                            break 2;
                        }
                    }
                }
            }
            return $matched;
        }

        /**
         * Build nested $fg_data tree from stored term meta for a single field group.
         * Shape: [ tab_id => [ group_id => [ idx => [ field_id => value ] ] ] ]
         */
        private function build_fg_data( $fg, $term_id ) {
            $tree = array();
            if ( ! $term_id || empty( $fg['tabs'] ) ) return $tree;

            foreach ( $fg['tabs'] as $tab ) {
                if ( empty( $tab['groups'] ) ) continue;
                foreach ( $tab['groups'] as $group ) {
                    if ( empty( $group['fields'] ) ) continue;
                    $clonable = isset( $group['clonable'] ) ? (bool) $group['clonable'] : true;

                    foreach ( $group['fields'] as $field ) {
                        $field_name = ! empty( $field['name'] ) ? $field['name'] : $field['id'];
                        $val        = JSCFR_Plugin::get_field_value( $field_name, $term_id, 'term' );

                        if ( $clonable && is_array( $val ) ) {
                            // Clonable: stored as indexed array of row-values
                            foreach ( $val as $idx => $row_val ) {
                                $tree[ $tab['id'] ][ $group['id'] ][ $idx ][ $field['id'] ] = $row_val;
                            }
                        } else {
                            $tree[ $tab['id'] ][ $group['id'] ][0][ $field['id'] ] = $val;
                        }
                    }
                }
            }
            return $tree;
        }

        /* ---------------------------------------------------------- */
        /*  Enqueue                                                    */
        /* ---------------------------------------------------------- */
        public function enqueue( $hook ) {
            if ( ! in_array( $hook, array( 'term.php', 'edit-tags.php' ), true ) ) {
                return;
            }

            $screen = get_current_screen();
            if ( ! $screen || empty( $screen->taxonomy ) ) return;

            $fgs = $this->get_field_groups_for_taxonomy( $screen->taxonomy );
            if ( empty( $fgs ) ) return;

            JSCFR_Metabox::enqueue_shared_assets( $fgs );

            // Build conditional logic map and field configs for JS
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
        /*  Render on edit term screen                                 */
        /* ---------------------------------------------------------- */
        public function render_fields( $term, $taxonomy ) {
            $fgs = $this->get_field_groups_for_taxonomy( $taxonomy );
            if ( empty( $fgs ) ) return;

            wp_nonce_field( 'jscfr_save_term_meta', '_jscfr_term_nonce' );
            $term_id = is_object( $term ) ? $term->term_id : 0;
            echo '<tr class="form-field jscfr-term-fields"><td colspan="2">';
            $this->render_field_groups_inner( $fgs, $term_id );
            echo '</td></tr>';
        }

        public function render_add_fields( $taxonomy ) {
            $fgs = $this->get_field_groups_for_taxonomy( $taxonomy );
            if ( empty( $fgs ) ) return;

            wp_nonce_field( 'jscfr_save_term_meta', '_jscfr_term_nonce' );
            echo '<div class="form-field jscfr-term-fields">';
            $this->render_field_groups_inner( $fgs, 0 );
            echo '</div>';
        }

        private function render_field_groups_inner( $fgs, $term_id ) {
            $metabox = JSCFR_Metabox::get_instance();

            foreach ( $fgs as $fg ) {
                if ( empty( $fg['tabs'] ) ) continue;

                $style_class = '';
                if ( isset( $fg['settings']['style'] ) && 'seamless' === $fg['settings']['style'] ) {
                    $style_class .= ' jscfr-seamless';
                }
                if ( isset( $fg['settings']['label_placement'] ) && 'left' === $fg['settings']['label_placement'] ) {
                    $style_class .= ' jscfr-labels-left';
                }
                $tab_placement = isset( $fg['settings']['tab_placement'] ) ? $fg['settings']['tab_placement'] : 'top';
                if ( 'left' === $tab_placement ) {
                    $style_class .= ' jscfr-tabs-left';
                }

                echo '<h3 class="jscfr-term-fg-title">' . esc_html( $fg['title'] ) . '</h3>';
                echo '<div class="jscfr-meta-wrap' . esc_attr( $style_class ) . '" data-fg="' . esc_attr( $fg['id'] ) . '">';

                if ( ! empty( $fg['settings']['description'] ) ) {
                    echo '<p class="jscfr-fg-desc">' . esc_html( $fg['settings']['description'] ) . '</p>';
                }

                $fg_data  = $this->build_fg_data( $fg, $term_id );
                $tabs     = $fg['tabs'];
                $has_tabs = count( $tabs ) > 1;

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

                $first = true;
                foreach ( $tabs as $tab ) {
                    if ( empty( $tab['groups'] ) ) { $first = false; continue; }
                    $display = ( $has_tabs && ! $first ) ? ' style="display:none;"' : '';
                    echo '<div class="jscfr-tab-content" id="jscfr-tab-' . esc_attr( $fg['id'] ) . '-' . esc_attr( $tab['id'] ) . '"' . $display . '>';
                    foreach ( $tab['groups'] as $group ) {
                        $metabox->render_group( $fg['id'], $tab['id'], $group, $fg_data );
                    }
                    echo '</div>';
                    $first = false;
                }

                if ( $has_tabs ) {
                    echo '</div></div>';
                }

                echo '</div>';
            }
        }

        /* ---------------------------------------------------------- */
        /*  Save term meta                                             */
        /* ---------------------------------------------------------- */
        public function save_fields( $term_id, $tt_id = 0 ) {
            if ( ! isset( $_POST['_jscfr_term_nonce'] ) || ! wp_verify_nonce( $_POST['_jscfr_term_nonce'], 'jscfr_save_term_meta' ) ) {
                return;
            }
            if ( ! current_user_can( 'manage_categories' ) ) return;

            $raw = isset( $_POST['jscfr_data'] ) ? wp_unslash( $_POST['jscfr_data'] ) : array();
            if ( ! is_array( $raw ) ) return;

            $term = get_term( $term_id );
            if ( ! $term || is_wp_error( $term ) ) return;

            $fgs     = $this->get_field_groups_for_taxonomy( $term->taxonomy );
            $metabox = JSCFR_Metabox::get_instance();

            foreach ( $fgs as $fg ) {
                if ( empty( $fg['tabs'] ) ) continue;
                $fg_id = $fg['id'];
                foreach ( $fg['tabs'] as $tab ) {
                    if ( empty( $tab['groups'] ) ) continue;
                    $tab_id = $tab['id'];
                    foreach ( $tab['groups'] as $group ) {
                        if ( empty( $group['fields'] ) ) continue;
                        $gid      = $group['id'];
                        $clonable = isset( $group['clonable'] ) ? (bool) $group['clonable'] : true;
                        $rows     = isset( $raw[ $fg_id ][ $tab_id ][ $gid ] ) ? $raw[ $fg_id ][ $tab_id ][ $gid ] : array();
                        if ( ! is_array( $rows ) ) $rows = array();

                        // Skip the __IDX__ template row (never submitted from real inputs, but guard anyway)
                        unset( $rows['__IDX__'] );

                        foreach ( $group['fields'] as $field ) {
                            $field_name = ! empty( $field['name'] ) ? $field['name'] : $field['id'];

                            if ( $clonable ) {
                                // Collect all row values for this field into an indexed array
                                $collected = array();
                                foreach ( $rows as $idx => $row ) {
                                    if ( ! is_array( $row ) ) continue;
                                    $raw_val = isset( $row[ $field['id'] ] ) ? $row[ $field['id'] ] : '';
                                    $collected[] = $metabox->sanitize_field_value( $raw_val, $field );
                                }
                                JSCFR_Plugin::set_field_value( $field_name, $collected, $term_id, 'term' );
                            } else {
                                $row0    = isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : array();
                                $raw_val = isset( $row0[ $field['id'] ] ) ? $row0[ $field['id'] ] : '';
                                $clean   = $metabox->sanitize_field_value( $raw_val, $field );
                                JSCFR_Plugin::set_field_value( $field_name, $clean, $term_id, 'term' );
                            }
                        }
                    }
                }
            }
        }
    }
}

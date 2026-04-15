<?php
/**
 * JSCFR User Meta — Renders field groups on user profile screens.
 * Location rule param: user_role, value: role slug or 'all'.
 * Stores in wp_usermeta with _jscfr_ prefix.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_User_Meta' ) ) {

    final class JSCFR_User_Meta {

        private static $instance = null;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'show_user_profile', array( $this, 'render_fields' ) );
            add_action( 'edit_user_profile', array( $this, 'render_fields' ) );
            add_action( 'personal_options_update', array( $this, 'save_fields' ) );
            add_action( 'edit_user_profile_update', array( $this, 'save_fields' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        }

        private function get_field_groups_for_user( $user ) {
            $matched = array();
            foreach ( JSCFR_Plugin::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) continue;
                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                foreach ( $rules as $or_group ) {
                    $or_match = true;
                    foreach ( $or_group as $rule ) {
                        if ( 'user_role' !== ( isset( $rule['param'] ) ? $rule['param'] : '' ) ) {
                            $or_match = false;
                            break;
                        }
                        $val = isset( $rule['value'] ) ? $rule['value'] : '';
                        $op  = isset( $rule['operator'] ) ? $rule['operator'] : 'is_equal_to';
                        if ( 'all' === $val ) {
                            // Matches all users
                        } elseif ( 'is_equal_to' === $op ) {
                            if ( ! in_array( $val, (array) $user->roles, true ) ) {
                                $or_match = false;
                            }
                        } elseif ( 'is_not_equal_to' === $op ) {
                            if ( in_array( $val, (array) $user->roles, true ) ) {
                                $or_match = false;
                            }
                        }
                    }
                    if ( $or_match && ! empty( $or_group ) ) {
                        $matched[] = $fg;
                        break;
                    }
                }
            }
            return $matched;
        }

        private function build_fg_data( $fg, $user_id ) {
            $tree = array();
            if ( ! $user_id || empty( $fg['tabs'] ) ) return $tree;

            foreach ( $fg['tabs'] as $tab ) {
                if ( empty( $tab['groups'] ) ) continue;
                foreach ( $tab['groups'] as $group ) {
                    if ( empty( $group['fields'] ) ) continue;
                    $clonable = isset( $group['clonable'] ) ? (bool) $group['clonable'] : true;

                    foreach ( $group['fields'] as $field ) {
                        $field_name = ! empty( $field['name'] ) ? $field['name'] : $field['id'];
                        $val        = JSCFR_Plugin::get_field_value( $field_name, $user_id, 'user' );

                        if ( $clonable && is_array( $val ) ) {
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
            if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
                return;
            }

            // Determine which user is being edited to match field groups
            $user = null;
            if ( 'user-edit.php' === $hook && isset( $_GET['user_id'] ) ) {
                $user = get_user_by( 'ID', intval( $_GET['user_id'] ) );
            }
            if ( ! $user ) {
                $user = wp_get_current_user();
            }
            if ( ! $user || ! $user->ID ) return;

            $fgs = $this->get_field_groups_for_user( $user );
            if ( empty( $fgs ) ) return;

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
        /*  Render                                                     */
        /* ---------------------------------------------------------- */
        public function render_fields( $user ) {
            $fgs = $this->get_field_groups_for_user( $user );
            if ( empty( $fgs ) ) return;

            wp_nonce_field( 'jscfr_save_user_meta', '_jscfr_user_nonce' );
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

                echo '<h2 class="jscfr-user-fg-title">' . esc_html( $fg['title'] ) . '</h2>';
                echo '<div class="jscfr-meta-wrap' . esc_attr( $style_class ) . '" data-fg="' . esc_attr( $fg['id'] ) . '">';

                if ( ! empty( $fg['settings']['description'] ) ) {
                    echo '<p class="jscfr-fg-desc">' . esc_html( $fg['settings']['description'] ) . '</p>';
                }

                $fg_data  = $this->build_fg_data( $fg, $user->ID );
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
        /*  Save                                                       */
        /* ---------------------------------------------------------- */
        public function save_fields( $user_id ) {
            if ( ! isset( $_POST['_jscfr_user_nonce'] ) || ! wp_verify_nonce( $_POST['_jscfr_user_nonce'], 'jscfr_save_user_meta' ) ) {
                return;
            }
            if ( ! current_user_can( 'edit_user', $user_id ) ) return;

            $raw = isset( $_POST['jscfr_data'] ) ? wp_unslash( $_POST['jscfr_data'] ) : array();
            if ( ! is_array( $raw ) ) return;

            $user = get_user_by( 'ID', $user_id );
            if ( ! $user ) return;

            $fgs     = $this->get_field_groups_for_user( $user );
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
                        unset( $rows['__IDX__'] );

                        foreach ( $group['fields'] as $field ) {
                            $field_name = ! empty( $field['name'] ) ? $field['name'] : $field['id'];

                            if ( $clonable ) {
                                $collected = array();
                                foreach ( $rows as $idx => $row ) {
                                    if ( ! is_array( $row ) ) continue;
                                    $raw_val = isset( $row[ $field['id'] ] ) ? $row[ $field['id'] ] : '';
                                    $collected[] = $metabox->sanitize_field_value( $raw_val, $field );
                                }
                                JSCFR_Plugin::set_field_value( $field_name, $collected, $user_id, 'user' );
                            } else {
                                $row0    = isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : array();
                                $raw_val = isset( $row0[ $field['id'] ] ) ? $row0[ $field['id'] ] : '';
                                $clean   = $metabox->sanitize_field_value( $raw_val, $field );
                                JSCFR_Plugin::set_field_value( $field_name, $clean, $user_id, 'user' );
                            }
                        }
                    }
                }
            }
        }
    }
}

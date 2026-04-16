<?php
/**
 * JSCFR Comment Meta — Renders field groups on comment edit screens.
 * Location rule param: comment.
 * Stores in wp_commentmeta with _jscfr_ prefix.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_Comment_Meta' ) ) {

    final class JSCFR_Comment_Meta {

        private static $instance = null;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'add_meta_boxes_comment', array( $this, 'register_metabox' ) );
            add_action( 'edit_comment', array( $this, 'save_fields' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        }

        private function get_field_groups_for_comments() {
            $matched = array();
            foreach ( JSCFR_Plugin::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) continue;
                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                foreach ( $rules as $or_group ) {
                    foreach ( $or_group as $rule ) {
                        if ( 'comment' === ( isset( $rule['param'] ) ? $rule['param'] : '' ) ) {
                            $matched[] = $fg;
                            break 2;
                        }
                    }
                }
            }
            return $matched;
        }

        private function build_fg_data( $fg, $comment_id ) {
            $tree = array();
            if ( ! $comment_id || empty( $fg['tabs'] ) ) return $tree;

            foreach ( $fg['tabs'] as $tab ) {
                if ( empty( $tab['groups'] ) ) continue;
                foreach ( $tab['groups'] as $group ) {
                    if ( empty( $group['fields'] ) ) continue;
                    $clonable = isset( $group['clonable'] ) ? (bool) $group['clonable'] : true;

                    foreach ( $group['fields'] as $field ) {
                        $field_name = ! empty( $field['name'] ) ? $field['name'] : $field['id'];
                        $val        = JSCFR_Plugin::get_field_value( $field_name, $comment_id, 'comment' );

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
            if ( 'comment.php' !== $hook ) return;

            $fgs = $this->get_field_groups_for_comments();
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
        /*  Register metabox on comment edit                           */
        /* ---------------------------------------------------------- */
        public function register_metabox( $comment ) {
            $fgs = $this->get_field_groups_for_comments();
            if ( empty( $fgs ) ) return;

            foreach ( $fgs as $fg ) {
                if ( JSCFR_Metabox::is_fg_hidden( $fg ) ) continue;
                add_meta_box(
                    'jscfr_comment_' . $fg['id'],
                    ! empty( $fg['title'] ) ? esc_html( $fg['title'] ) : __( 'Custom Fields', 'jscfr' ),
                    array( $this, 'render_metabox' ),
                    'comment',
                    'normal',
                    'high',
                    array( 'fg' => $fg, 'comment' => $comment )
                );
            }
        }

        /* ---------------------------------------------------------- */
        /*  Render                                                     */
        /* ---------------------------------------------------------- */
        public function render_metabox( $comment, $box ) {
            $fg         = $box['args']['fg'];
            $comment_id = $comment->comment_ID;

            wp_nonce_field( 'jscfr_save_comment_meta', '_jscfr_comment_nonce' );

            if ( empty( $fg['tabs'] ) ) return;

            $wrap_classes = JSCFR_Metabox::build_wrap_classes( $fg );
            $wrap_attrs   = JSCFR_Metabox::build_wrap_data_attrs( $fg );

            echo '<div class="jscfr-meta-wrap ' . esc_attr( $wrap_classes ) . '" data-fg="' . esc_attr( $fg['id'] ) . '"' . $wrap_attrs . '>';

            if ( ! empty( $fg['settings']['description'] ) ) {
                echo '<p class="jscfr-fg-desc">' . esc_html( $fg['settings']['description'] ) . '</p>';
            }

            $metabox  = JSCFR_Metabox::get_instance();
            $fg_data  = $this->build_fg_data( $fg, $comment_id );
            $tabs     = $fg['tabs'];
            $has_tabs = count( $tabs ) > 1;
            $tab_placement = isset( $fg['settings']['tab_placement'] ) ? $fg['settings']['tab_placement'] : 'top';

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
                        }
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

        /* ---------------------------------------------------------- */
        /*  Save                                                       */
        /* ---------------------------------------------------------- */
        public function save_fields( $comment_id ) {
            if ( ! isset( $_POST['_jscfr_comment_nonce'] ) || ! wp_verify_nonce( $_POST['_jscfr_comment_nonce'], 'jscfr_save_comment_meta' ) ) {
                return;
            }
            if ( ! current_user_can( 'edit_comment', $comment_id ) ) return;

            $raw = isset( $_POST['jscfr_data'] ) ? wp_unslash( $_POST['jscfr_data'] ) : array();
            if ( ! is_array( $raw ) ) return;

            $fgs     = $this->get_field_groups_for_comments();
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
                                JSCFR_Plugin::set_field_value( $field_name, $collected, $comment_id, 'comment' );
                            } else {
                                $row0    = isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : array();
                                $raw_val = isset( $row0[ $field['id'] ] ) ? $row0[ $field['id'] ] : '';
                                $clean   = $metabox->sanitize_field_value( $raw_val, $field );
                                JSCFR_Plugin::set_field_value( $field_name, $clean, $comment_id, 'comment' );
                            }
                        }
                    }
                }
            }
        }
    }
}

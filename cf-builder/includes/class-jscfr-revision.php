<?php
/**
 * JSCFR Revision Support
 *
 * Tracks JSCFR custom field values in WordPress post revisions
 * so users can compare and restore previous field states.
 *
 * Activated per field group via the "revision" setting.
 *
 * @package JSCFR
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_Revision' ) ) {

    final class JSCFR_Revision {

        private static $instance = null;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            // Register JSCFR fields as revision-tracked fields
            add_filter( '_wp_post_revision_fields', array( $this, 'revision_fields' ), 10, 2 );

            // Save JSCFR meta to revision when a revision is created
            add_action( '_wp_put_post_revision', array( $this, 'save_revision' ), 10, 1 );

            // Restore JSCFR meta when a revision is restored
            add_action( 'wp_restore_post_revision', array( $this, 'restore_revision' ), 10, 2 );

            // Display JSCFR field diffs in the revision comparison screen
            add_filter( '_wp_post_revision_field_jscfr_fields', array( $this, 'revision_field_display' ), 10, 4 );
        }

        /**
         * Get all field groups that have revision tracking enabled.
         *
         * @return array Field groups with revision=true
         */
        private function get_revision_field_groups() {
            $config = JSCFR_Plugin::get_config();
            $groups = array();

            foreach ( $config as $fg ) {
                $settings = isset( $fg['settings'] ) ? $fg['settings'] : array();
                if ( ! empty( $settings['revision'] ) ) {
                    $groups[] = $fg;
                }
            }

            return $groups;
        }

        /**
         * Get all meta keys tracked by revision-enabled field groups.
         *
         * @return array Associative array of meta_key => label
         */
        private function get_tracked_meta_keys() {
            $keys = array();
            $fgs  = $this->get_revision_field_groups();

            foreach ( $fgs as $fg ) {
                $fg_title = isset( $fg['title'] ) ? $fg['title'] : $fg['id'];
                if ( empty( $fg['tabs'] ) ) {
                    continue;
                }
                foreach ( $fg['tabs'] as $tab ) {
                    if ( empty( $tab['groups'] ) ) {
                        continue;
                    }
                    foreach ( $tab['groups'] as $group ) {
                        $group_name = ! empty( $group['name'] ) ? $group['name'] : $group['id'];
                        $keys[ JSCFR_META_PREFIX . $group_name ] = $fg_title . ': ' . $group_name;

                        if ( empty( $group['fields'] ) ) {
                            continue;
                        }
                        foreach ( $group['fields'] as $field ) {
                            // Skip display-only types
                            if ( in_array( $field['type'], array( 'heading', 'divider', 'custom_html', 'button' ), true ) ) {
                                continue;
                            }
                            $field_name  = ! empty( $field['name'] ) ? $field['name'] : $field['id'];
                            $field_label = ! empty( $field['label'] ) ? $field['label'] : $field_name;
                            $keys[ JSCFR_META_PREFIX . $field_name ] = $fg_title . ': ' . $field_label;
                        }
                    }
                }
            }

            return $keys;
        }

        /**
         * Register a single composite field in the revision fields list.
         * WordPress uses this to show the diff UI.
         *
         * @param array    $fields  Revision fields.
         * @param WP_Post  $post    The post (or revision) being compared.
         * @return array
         */
        public function revision_fields( $fields, $post = null ) {
            $tracked = $this->get_tracked_meta_keys();
            if ( ! empty( $tracked ) ) {
                // Register one composite field for the diff display
                $fields['jscfr_fields'] = __( 'JSCFR Custom Fields', 'jscfr' );
            }
            return $fields;
        }

        /**
         * When WordPress creates a revision, copy all tracked JSCFR meta
         * from the parent post to the revision.
         *
         * @param int $revision_id The revision post ID.
         */
        public function save_revision( $revision_id ) {
            $parent_id = wp_is_post_revision( $revision_id );
            if ( ! $parent_id ) {
                return;
            }

            $tracked = $this->get_tracked_meta_keys();
            if ( empty( $tracked ) ) {
                return;
            }

            foreach ( $tracked as $meta_key => $label ) {
                $value = get_post_meta( $parent_id, $meta_key, true );
                if ( '' !== $value && false !== $value ) {
                    update_metadata( 'post', $revision_id, $meta_key, $value );
                }
            }

            // Also copy the v4 blob for completeness
            $blob = get_post_meta( $parent_id, JSCFR_META_KEY, true );
            if ( ! empty( $blob ) ) {
                update_metadata( 'post', $revision_id, JSCFR_META_KEY, $blob );
            }

            // Copy the field group map
            $map = get_post_meta( $parent_id, JSCFR_FIELD_MAP_KEY, true );
            if ( ! empty( $map ) ) {
                update_metadata( 'post', $revision_id, JSCFR_FIELD_MAP_KEY, $map );
            }
        }

        /**
         * When a revision is restored, copy JSCFR meta from the revision
         * back to the parent post.
         *
         * @param int $post_id     The parent post ID.
         * @param int $revision_id The revision post ID being restored.
         */
        public function restore_revision( $post_id, $revision_id ) {
            $tracked = $this->get_tracked_meta_keys();
            if ( empty( $tracked ) ) {
                return;
            }

            foreach ( $tracked as $meta_key => $label ) {
                $value = get_metadata( 'post', $revision_id, $meta_key, true );
                if ( '' !== $value && false !== $value ) {
                    update_post_meta( $post_id, $meta_key, $value );
                } else {
                    delete_post_meta( $post_id, $meta_key );
                }
            }

            // Restore v4 blob
            $blob = get_metadata( 'post', $revision_id, JSCFR_META_KEY, true );
            if ( ! empty( $blob ) ) {
                update_post_meta( $post_id, JSCFR_META_KEY, $blob );
            } else {
                delete_post_meta( $post_id, JSCFR_META_KEY );
            }

            // Restore field group map
            $map = get_metadata( 'post', $revision_id, JSCFR_FIELD_MAP_KEY, true );
            if ( ! empty( $map ) ) {
                update_post_meta( $post_id, JSCFR_FIELD_MAP_KEY, $map );
            }
        }

        /**
         * Build a human-readable text representation of all JSCFR field values
         * for the revision diff display.
         *
         * @param string  $value       Current value text.
         * @param string  $field_key   The field slug (jscfr_fields).
         * @param WP_Post $compare_to  The revision or post being compared.
         * @param string  $direction   'to' or 'from'.
         * @return string
         */
        public function revision_field_display( $value, $field_key, $compare_to, $direction ) {
            $tracked = $this->get_tracked_meta_keys();
            if ( empty( $tracked ) ) {
                return $value;
            }

            $post_id = is_object( $compare_to ) ? $compare_to->ID : $compare_to;
            $lines   = array();

            foreach ( $tracked as $meta_key => $label ) {
                $meta_value = get_metadata( 'post', $post_id, $meta_key, true );
                if ( is_array( $meta_value ) ) {
                    $meta_value = wp_json_encode( $meta_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
                }
                $meta_value = (string) $meta_value;
                if ( '' !== $meta_value ) {
                    $lines[] = $label . ': ' . $meta_value;
                }
            }

            return implode( "\n", $lines );
        }
    }

}

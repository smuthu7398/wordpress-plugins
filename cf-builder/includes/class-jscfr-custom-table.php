<?php
/**
 * JSCFR Custom Table — Write-through storage for field groups with custom_table enabled.
 *
 * When a field group has settings.custom_table = true (or custom_table.enabled = true),
 * saves mirror the submitted data into {prefix}jscfr_ct_<slug>. The main source of
 * truth remains postmeta/termmeta/usermeta/commentmeta (for reads), this table is
 * a write-through mirror for fast SQL queries.
 *
 * Schema (auto-created on first save when table_create is true):
 *   id          BIGINT PK AI
 *   object_id   BIGINT  — post/term/user/comment ID (or 0 for options)
 *   context     VARCHAR(20) — 'post'|'term'|'user'|'comment'|'options'
 *   fg_id       VARCHAR(64)
 *   data_json   LONGTEXT — full FG payload as JSON
 *   updated_at  DATETIME
 *   UNIQUE KEY (object_id, context, fg_id)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_Custom_Table' ) ) {

    final class JSCFR_Custom_Table {

        private static $instance = null;
        /** @var array<string,bool> table_name => exists */
        private static $ensured = array();

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            // Post save
            add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
            // Term save
            add_action( 'edited_term', array( $this, 'on_save_term' ), 20, 3 );
            add_action( 'created_term', array( $this, 'on_save_term' ), 20, 3 );
            // User save
            add_action( 'profile_update', array( $this, 'on_save_user' ), 20, 1 );
            add_action( 'user_register', array( $this, 'on_save_user' ), 20, 1 );
            // Comment save
            add_action( 'edit_comment', array( $this, 'on_save_comment' ), 20, 1 );
            // Options page (custom action fired by options-page save handler)
            add_action( 'jscfr_options_saved', array( $this, 'on_save_options' ), 20, 2 );
        }

        /* ---------------------------------------------------------- */
        /*  Helpers                                                   */
        /* ---------------------------------------------------------- */

        /**
         * Read custom_table config from an FG. Supports both nested and flat settings keys.
         * Returns array('enabled' => bool, 'table_name' => string, 'auto_create' => bool).
         */
        public static function get_ct_config( $fg ) {
            $ct = isset( $fg['custom_table'] ) && is_array( $fg['custom_table'] ) ? $fg['custom_table'] : array();
            $s  = isset( $fg['settings'] ) && is_array( $fg['settings'] ) ? $fg['settings'] : array();

            $enabled     = ! empty( $ct['enabled'] ) || ! empty( $s['custom_table'] );
            $table_name  = isset( $ct['table_name'] ) ? $ct['table_name'] : ( isset( $s['table_name'] ) ? $s['table_name'] : '' );
            $auto_create = ! empty( $ct['auto_create'] ) || ! empty( $s['table_create'] );

            return array(
                'enabled'     => (bool) $enabled,
                'table_name'  => $table_name,
                'auto_create' => (bool) $auto_create,
            );
        }

        /**
         * Sanitize table slug and produce full wpdb-prefixed table name.
         * Returns '' if invalid.
         */
        public static function resolve_table( $fg ) {
            global $wpdb;
            $cfg = self::get_ct_config( $fg );
            if ( ! $cfg['enabled'] ) return '';

            $name = $cfg['table_name'];
            if ( ! $name ) {
                // Fall back to fg_id if no custom name given
                $name = ! empty( $fg['id'] ) ? $fg['id'] : '';
            }
            $slug = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $name ) );
            $slug = trim( $slug, '_' );
            if ( '' === $slug ) return '';
            // Cap length so final table name stays under MySQL 64-char limit
            $slug = substr( $slug, 0, 40 );
            return $wpdb->prefix . 'jscfr_ct_' . $slug;
        }

        public static function ensure_table( $table ) {
            global $wpdb;
            if ( ! $table ) return false;
            if ( ! empty( self::$ensured[ $table ] ) ) return true;

            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                context VARCHAR(20) NOT NULL DEFAULT 'post',
                fg_id VARCHAR(64) NOT NULL DEFAULT '',
                data_json LONGTEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY object_context_fg (object_id, context, fg_id)
            ) {$charset_collate};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );

            self::$ensured[ $table ] = true;
            return true;
        }

        /**
         * UPSERT a row for the given context/object/fg pair.
         */
        private function upsert( $fg, $context, $object_id ) {
            global $wpdb;
            $cfg   = self::get_ct_config( $fg );
            if ( ! $cfg['enabled'] ) return;
            $table = self::resolve_table( $fg );
            if ( ! $table ) return;
            if ( $cfg['auto_create'] ) {
                self::ensure_table( $table );
            } else {
                // Only upsert if table already exists
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
                if ( $exists !== $table ) return;
            }

            // Build data payload by walking FG tree and reading stored values
            $payload = array();
            if ( ! empty( $fg['tabs'] ) ) {
                foreach ( $fg['tabs'] as $tab ) {
                    if ( empty( $tab['groups'] ) ) continue;
                    foreach ( $tab['groups'] as $group ) {
                        if ( empty( $group['fields'] ) ) continue;
                        foreach ( $group['fields'] as $field ) {
                            $field_name = ! empty( $field['name'] ) ? $field['name'] : $field['id'];
                            $payload[ $field_name ] = JSCFR_Plugin::get_field_value( $field_name, $object_id, $context );
                        }
                    }
                }
            }

            $json = wp_json_encode( $payload );
            $now  = current_time( 'mysql' );

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table} (object_id, context, fg_id, data_json, updated_at)
                 VALUES (%d, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = VALUES(updated_at)",
                (int) $object_id,
                $context,
                isset( $fg['id'] ) ? $fg['id'] : '',
                $json,
                $now
            ) );
        }

        /* ---------------------------------------------------------- */
        /*  Context getters — find FGs applicable to an object        */
        /* ---------------------------------------------------------- */

        private function fgs_for_post( $post ) {
            $matched = array();
            foreach ( JSCFR_Plugin::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) continue;
                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                foreach ( $rules as $or_group ) {
                    foreach ( $or_group as $rule ) {
                        $p = isset( $rule['param'] ) ? $rule['param'] : '';
                        $v = isset( $rule['value'] ) ? $rule['value'] : '';
                        if ( 'post_type' === $p && $v === $post->post_type ) {
                            $matched[] = $fg;
                            continue 3;
                        }
                        if ( 'post' === $p && (int) $v === (int) $post->ID ) {
                            $matched[] = $fg;
                            continue 3;
                        }
                    }
                }
            }
            return $matched;
        }

        private function fgs_for_taxonomy( $taxonomy ) {
            $matched = array();
            foreach ( JSCFR_Plugin::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) continue;
                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                foreach ( $rules as $or_group ) {
                    foreach ( $or_group as $rule ) {
                        if ( 'taxonomy_term' === ( isset( $rule['param'] ) ? $rule['param'] : '' )
                            && isset( $rule['value'] ) && $rule['value'] === $taxonomy ) {
                            $matched[] = $fg;
                            continue 3;
                        }
                    }
                }
            }
            return $matched;
        }

        private function fgs_for_user_roles( $roles ) {
            $matched = array();
            foreach ( JSCFR_Plugin::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) continue;
                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                foreach ( $rules as $or_group ) {
                    foreach ( $or_group as $rule ) {
                        if ( 'user_role' !== ( isset( $rule['param'] ) ? $rule['param'] : '' ) ) continue;
                        $v = isset( $rule['value'] ) ? $rule['value'] : '';
                        if ( 'all' === $v || in_array( $v, (array) $roles, true ) ) {
                            $matched[] = $fg;
                            continue 3;
                        }
                    }
                }
            }
            return $matched;
        }

        private function fgs_for_comment() {
            $matched = array();
            foreach ( JSCFR_Plugin::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) continue;
                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                foreach ( $rules as $or_group ) {
                    foreach ( $or_group as $rule ) {
                        if ( 'comment' === ( isset( $rule['param'] ) ? $rule['param'] : '' ) ) {
                            $matched[] = $fg;
                            continue 3;
                        }
                    }
                }
            }
            return $matched;
        }

        private function fgs_for_options_slug( $slug ) {
            $matched = array();
            foreach ( JSCFR_Plugin::get_config() as $fg ) {
                if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) continue;
                $rules = isset( $fg['location_rules'] ) ? $fg['location_rules'] : array();
                foreach ( $rules as $or_group ) {
                    foreach ( $or_group as $rule ) {
                        if ( 'options_page' === ( isset( $rule['param'] ) ? $rule['param'] : '' )
                            && isset( $rule['value'] ) && $rule['value'] === $slug ) {
                            $matched[] = $fg;
                            continue 3;
                        }
                    }
                }
            }
            return $matched;
        }

        /* ---------------------------------------------------------- */
        /*  Save hooks                                                */
        /* ---------------------------------------------------------- */

        public function on_save_post( $post_id, $post ) {
            if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
            if ( ! $post instanceof WP_Post ) $post = get_post( $post_id );
            if ( ! $post ) return;
            foreach ( $this->fgs_for_post( $post ) as $fg ) {
                $this->upsert( $fg, 'post', $post_id );
            }
        }

        public function on_save_term( $term_id, $tt_id, $taxonomy ) {
            foreach ( $this->fgs_for_taxonomy( $taxonomy ) as $fg ) {
                $this->upsert( $fg, 'term', $term_id );
            }
        }

        public function on_save_user( $user_id ) {
            $user = get_user_by( 'ID', $user_id );
            if ( ! $user ) return;
            foreach ( $this->fgs_for_user_roles( $user->roles ) as $fg ) {
                $this->upsert( $fg, 'user', $user_id );
            }
        }

        public function on_save_comment( $comment_id ) {
            foreach ( $this->fgs_for_comment() as $fg ) {
                $this->upsert( $fg, 'comment', $comment_id );
            }
        }

        public function on_save_options( $slug, $data ) {
            foreach ( $this->fgs_for_options_slug( $slug ) as $fg ) {
                $this->upsert( $fg, 'options', 0 );
            }
        }
    }
}

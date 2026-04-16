<?php
/**
 * JSCFR REST API — Exposes custom field data via WP REST API.
 * All routes under jscfr/v1 namespace to avoid conflicts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_REST' ) ) {

    final class JSCFR_REST {

        private static $instance = null;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'rest_api_init', array( $this, 'register_routes' ) );
            add_action( 'rest_api_init', array( $this, 'register_post_meta_fields' ) );
        }

        /* ---------------------------------------------------------- */
        /*  Register REST routes                                       */
        /* ---------------------------------------------------------- */
        public function register_routes() {
            // Get all field data for a post
            register_rest_route( 'jscfr/v1', '/fields/(?P<post_id>\d+)', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_post_fields' ),
                'permission_callback' => array( $this, 'can_read_post' ),
                'args'                => array(
                    'post_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
                ),
            ) );

            // Get a specific field value
            register_rest_route( 'jscfr/v1', '/field/(?P<post_id>\d+)/(?P<field_name>[a-zA-Z0-9_-]+)', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_single_field' ),
                'permission_callback' => array( $this, 'can_read_post' ),
                'args'                => array(
                    'post_id'    => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
                    'field_name' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
                ),
            ) );

            // Get group rows
            register_rest_route( 'jscfr/v1', '/rows/(?P<post_id>\d+)/(?P<group_name>[a-zA-Z0-9_-]+)', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_group_rows' ),
                'permission_callback' => array( $this, 'can_read_post' ),
                'args'                => array(
                    'post_id'    => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
                    'group_name' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
                ),
            ) );

            // List all field groups (admin)
            register_rest_route( 'jscfr/v1', '/field-groups', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_field_groups' ),
                'permission_callback' => array( $this, 'can_manage_options' ),
            ) );

            // Get options page data
            register_rest_route( 'jscfr/v1', '/options', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_options_fields' ),
                'permission_callback' => array( $this, 'can_read_options' ),
            ) );
        }

        /* ---------------------------------------------------------- */
        /*  Register jscfr_data in post REST responses                 */
        /* ---------------------------------------------------------- */
        public function register_post_meta_fields() {
            $post_types = get_post_types( array( 'public' => true, 'show_in_rest' => true ) );
            foreach ( $post_types as $pt ) {
                register_rest_field( $pt, 'jscfr_fields', array(
                    'get_callback'    => array( $this, 'rest_get_post_fields' ),
                    'update_callback' => null,
                    'schema'          => array(
                        'description' => __( 'JSCFR custom field data', 'jscfr' ),
                        'type'        => 'object',
                        'context'     => array( 'view', 'edit' ),
                    ),
                ) );
            }
        }

        /**
         * Callback for rest_field: attach jscfr data to post responses.
         */
        public function rest_get_post_fields( $object ) {
            $post_id = isset( $object['id'] ) ? $object['id'] : 0;
            if ( ! $post_id ) return array();

            return JSCFR_Plugin::get_all_field_values( $post_id );
        }

        /* ---------------------------------------------------------- */
        /*  Route callbacks                                            */
        /* ---------------------------------------------------------- */
        public function get_post_fields( $request ) {
            $post_id = $request->get_param( 'post_id' );
            return rest_ensure_response( JSCFR_Plugin::get_all_field_values( $post_id ) );
        }

        public function get_single_field( $request ) {
            $post_id    = $request->get_param( 'post_id' );
            $field_name = $request->get_param( 'field_name' );

            $val = JSCFR_Plugin::get_field_value( $field_name, $post_id );
            return rest_ensure_response( array( 'field' => $field_name, 'value' => $val ) );
        }

        public function get_group_rows( $request ) {
            $post_id    = $request->get_param( 'post_id' );
            $group_name = $request->get_param( 'group_name' );

            $info = JSCFR_Plugin::resolve_field( $group_name );
            if ( ! $info || 'group' !== $info['type'] ) {
                return new WP_Error( 'jscfr_not_found', __( 'Group not found', 'jscfr' ), array( 'status' => 404 ) );
            }

            // v5: read from individual meta (has v4 blob fallback)
            $rows = JSCFR_Plugin::get_field_value( $group_name, $post_id );
            if ( ! is_array( $rows ) ) {
                $rows = array();
            }
            return rest_ensure_response( $rows );
        }

        public function get_field_groups( $request ) {
            $config = JSCFR_Plugin::get_config();
            return rest_ensure_response( $config );
        }

        public function get_options_fields( $request ) {
            return rest_ensure_response( JSCFR_Plugin::get_all_field_values( null, 'options' ) );
        }

        /* ---------------------------------------------------------- */
        /*  Permissions                                                */
        /* ---------------------------------------------------------- */
        public function can_read_post( $request ) {
            $post_id = $request->get_param( 'post_id' );
            $post    = get_post( $post_id );
            if ( ! $post ) {
                return new WP_Error( 'jscfr_not_found', __( 'Post not found', 'jscfr' ), array( 'status' => 404 ) );
            }
            if ( 'publish' === $post->post_status ) {
                return true;
            }
            return current_user_can( 'read_post', $post_id );
        }

        public function can_manage_options() {
            return current_user_can( 'manage_options' );
        }

        public function can_read_options() {
            return current_user_can( 'manage_options' );
        }
    }
}

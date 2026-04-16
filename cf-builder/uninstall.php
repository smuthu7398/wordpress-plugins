<?php
/**
 * JSCFR Uninstall — runs when the plugin is deleted from wp-admin > Plugins.
 *
 * Removes only jscfr_-prefixed data:
 *   - options (jscfr_* and jscfr_opt_*)
 *   - meta (_jscfr_*) across postmeta, termmeta, usermeta, commentmeta
 *   - custom table {prefix}jscfr_relationships
 *   - scheduled cron events (jscfr_*)
 *   - transients (jscfr_*)
 *
 * Does NOT touch posts authored under CPTs registered via this plugin — that
 * would destroy user content. Export first if you need to remove those.
 *
 * Respects an opt-out: if option jscfr_preserve_data_on_uninstall is truthy,
 * nothing is removed.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! function_exists( 'jscfr_uninstall_cleanup_site' ) ) {

    function jscfr_uninstall_cleanup_site() {
        global $wpdb;

        if ( get_option( 'jscfr_preserve_data_on_uninstall' ) ) {
            return;
        }

        // 1. Options — exact keys
        $exact_options = array(
            'jscfr_field_config',
            'jscfr_options_data',
            'jscfr_options_data_v4_backup',
            'jscfr_options_pages',
            'jscfr_db_version',
            'jscfr_v5_migration_running',
            'jscfr_v5_migration_offset',
            'jscfr_custom_post_types',
            'jscfr_custom_taxonomies',
            'jscfr_relationships',
            'jscfr_rel_db_version',
            'jscfr_preserve_data_on_uninstall',
        );
        foreach ( $exact_options as $opt ) {
            delete_option( $opt );
        }

        // 2. Options — prefixed (jscfr_opt_* and any jscfr_* holdovers)
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'jscfr\\_opt\\_%' OR option_name LIKE '\\_transient\\_jscfr\\_%' OR option_name LIKE '\\_transient\\_timeout\\_jscfr\\_%' OR option_name LIKE '\\_site\\_transient\\_jscfr\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_jscfr\\_%'"
        );

        // 3. Meta — _jscfr_* across all meta tables
        $wpdb->query( "DELETE FROM {$wpdb->postmeta}    WHERE meta_key LIKE '\\_jscfr\\_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->termmeta}    WHERE meta_key LIKE '\\_jscfr\\_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->usermeta}    WHERE meta_key LIKE '\\_jscfr\\_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE '\\_jscfr\\_%'" );

        // 4. Custom tables — relationships + any write-through tables
        $rel_table = $wpdb->prefix . 'jscfr_relationships';
        $wpdb->query( "DROP TABLE IF EXISTS {$rel_table}" );

        $ct_prefix = $wpdb->esc_like( $wpdb->prefix . 'jscfr_ct_' ) . '%';
        $ct_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ct_prefix ) );
        if ( is_array( $ct_tables ) ) {
            foreach ( $ct_tables as $ct ) {
                $wpdb->query( "DROP TABLE IF EXISTS `{$ct}`" );
            }
        }

        // 5. Cron events (clear all jscfr_* hooks, in case names change)
        $crons = _get_cron_array();
        if ( is_array( $crons ) ) {
            foreach ( $crons as $timestamp => $hooks ) {
                if ( ! is_array( $hooks ) ) continue;
                foreach ( $hooks as $hook => $events ) {
                    if ( 0 === strpos( $hook, 'jscfr_' ) ) {
                        wp_clear_scheduled_hook( $hook );
                    }
                }
            }
        }
    }
}

// Multisite: iterate every site; single-site: just clean current.
if ( is_multisite() ) {
    $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        jscfr_uninstall_cleanup_site();
        restore_current_blog();
    }
} else {
    jscfr_uninstall_cleanup_site();
}

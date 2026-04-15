<?php
/**
 * JSCFR JSON Sync
 *
 * Syncs field groups between the WordPress database and JSON files
 * stored in the active theme directory. Provides a visual comparison
 * UI and bidirectional sync capabilities.
 *
 * @package JSCFR
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_JSON_Sync' ) ) {

    final class JSCFR_JSON_Sync {

        private static $instance = null;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'admin_menu', array( $this, 'add_submenu' ) );
            add_action( 'wp_ajax_jscfr_json_sync_status', array( $this, 'ajax_sync_status' ) );
            add_action( 'wp_ajax_jscfr_json_export', array( $this, 'ajax_export' ) );
            add_action( 'wp_ajax_jscfr_json_import', array( $this, 'ajax_import' ) );
            add_action( 'wp_ajax_jscfr_json_delete', array( $this, 'ajax_delete' ) );
        }

        /* ============================================================= */
        /*  Admin menu                                                     */
        /* ============================================================= */

        public function add_submenu() {
            add_submenu_page(
                'jscfr-builder',
                __( 'JSON Sync', 'jscfr' ),
                __( 'JSON Sync', 'jscfr' ),
                'manage_options',
                'jscfr-json-sync',
                array( $this, 'render_page' )
            );
        }

        /* ============================================================= */
        /*  Helper: JSON directory path                                    */
        /* ============================================================= */

        /**
         * Returns the path to the JSON sync directory inside the active theme.
         * Creates the directory and a protective index.php if it does not exist.
         *
         * @return string Full path to the jscfr-json directory (with trailing slash).
         */
        public function get_json_dir() {
            $dir = trailingslashit( get_stylesheet_directory() ) . 'jscfr-json';

            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
                $index = trailingslashit( $dir ) . 'index.php';
                if ( ! file_exists( $index ) ) {
                    file_put_contents( $index, "<?php\n// Silence is golden." );
                }
            }

            return trailingslashit( $dir );
        }

        /* ============================================================= */
        /*  AJAX: Sync status                                              */
        /* ============================================================= */

        public function ajax_sync_status() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $config  = JSCFR_Plugin::get_config();
            $json_dir = $this->get_json_dir();
            $results = array();

            // Index DB field groups by ID.
            $db_map = array();
            foreach ( $config as $fg ) {
                if ( ! empty( $fg['id'] ) ) {
                    $db_map[ $fg['id'] ] = $fg;
                }
            }

            // Scan JSON files.
            $json_map = array();
            $files = glob( $json_dir . '*.json' );
            if ( is_array( $files ) ) {
                foreach ( $files as $file ) {
                    $fg_id = basename( $file, '.json' );
                    $json_map[ $fg_id ] = $file;
                }
            }

            // All known IDs (union of DB + JSON).
            $all_ids = array_unique( array_merge( array_keys( $db_map ), array_keys( $json_map ) ) );

            foreach ( $all_ids as $fg_id ) {
                $in_db   = isset( $db_map[ $fg_id ] );
                $in_json = isset( $json_map[ $fg_id ] );

                $title         = '';
                $status        = '';
                $db_modified   = null;
                $json_modified = null;

                if ( $in_db ) {
                    $fg    = $db_map[ $fg_id ];
                    $title = ! empty( $fg['title'] ) ? $fg['title'] : $fg_id;
                }

                if ( $in_json ) {
                    $json_modified = filemtime( $json_map[ $fg_id ] );
                    if ( empty( $title ) ) {
                        $json_data = json_decode( file_get_contents( $json_map[ $fg_id ] ), true );
                        $title = is_array( $json_data ) && ! empty( $json_data['title'] ) ? $json_data['title'] : $fg_id;
                    }
                }

                // Determine DB modified time from export transient or option update.
                if ( $in_db ) {
                    $export_time = get_transient( 'jscfr_json_export_' . $fg_id );
                    if ( false !== $export_time ) {
                        $db_modified = (int) $export_time;
                    } else {
                        // Fallback: use the option's last update time.
                        $db_modified = $this->get_option_modified_time();
                    }
                }

                // Determine status.
                if ( $in_db && ! $in_json ) {
                    $status = 'db_only';
                } elseif ( ! $in_db && $in_json ) {
                    $status = 'json_only';
                } else {
                    // Both exist — compare content.
                    $db_hash   = md5( wp_json_encode( $db_map[ $fg_id ] ) );
                    $json_raw  = file_get_contents( $json_map[ $fg_id ] );
                    $json_data = json_decode( $json_raw, true );
                    $json_hash = md5( wp_json_encode( $json_data ) );

                    if ( $db_hash === $json_hash ) {
                        $status = 'in_sync';
                    } elseif ( $db_modified && $json_modified && $db_modified > $json_modified ) {
                        $status = 'db_newer';
                    } elseif ( $db_modified && $json_modified && $json_modified > $db_modified ) {
                        $status = 'json_newer';
                    } elseif ( $json_modified && ! $db_modified ) {
                        $status = 'json_newer';
                    } else {
                        $status = 'db_newer';
                    }
                }

                $results[] = array(
                    'fg_id'         => $fg_id,
                    'title'         => $title,
                    'status'        => $status,
                    'db_modified'   => $db_modified ? $db_modified : null,
                    'json_modified' => $json_modified ? $json_modified : null,
                );
            }

            wp_send_json_success( $results );
        }

        /* ============================================================= */
        /*  AJAX: Export to JSON                                           */
        /* ============================================================= */

        public function ajax_export() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $fg_ids  = isset( $_POST['fg_ids'] ) ? array_map( 'sanitize_key', (array) $_POST['fg_ids'] ) : array();
            $json_dir = $this->get_json_dir();
            $count   = 0;

            foreach ( $fg_ids as $fg_id ) {
                $fg = JSCFR_Plugin::get_field_group( $fg_id );
                if ( ! $fg ) {
                    continue;
                }

                $file = $json_dir . $fg_id . '.json';
                $json = wp_json_encode( $fg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

                if ( false !== file_put_contents( $file, $json ) ) {
                    set_transient( 'jscfr_json_export_' . $fg_id, time(), 0 );
                    $count++;
                }
            }

            wp_send_json_success( array(
                'exported' => $count,
                'total'    => count( $fg_ids ),
            ) );
        }

        /* ============================================================= */
        /*  AJAX: Import from JSON                                         */
        /* ============================================================= */

        public function ajax_import() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $fg_ids   = isset( $_POST['fg_ids'] ) ? array_map( 'sanitize_key', (array) $_POST['fg_ids'] ) : array();
            $json_dir = $this->get_json_dir();
            $count    = 0;

            foreach ( $fg_ids as $fg_id ) {
                $file = $json_dir . $fg_id . '.json';
                if ( ! file_exists( $file ) ) {
                    continue;
                }

                $raw = file_get_contents( $file );
                $fg  = json_decode( $raw, true );
                if ( ! is_array( $fg ) || empty( $fg['id'] ) ) {
                    continue;
                }

                JSCFR_Plugin::save_field_group( $fg );
                set_transient( 'jscfr_json_export_' . $fg_id, time(), 0 );
                $count++;
            }

            wp_send_json_success( array(
                'imported' => $count,
                'total'    => count( $fg_ids ),
            ) );
        }

        /* ============================================================= */
        /*  AJAX: Delete JSON files                                        */
        /* ============================================================= */

        public function ajax_delete() {
            check_ajax_referer( JSCFR_BUILDER_NONCE, 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }

            $fg_ids   = isset( $_POST['fg_ids'] ) ? array_map( 'sanitize_key', (array) $_POST['fg_ids'] ) : array();
            $json_dir = $this->get_json_dir();
            $count    = 0;

            foreach ( $fg_ids as $fg_id ) {
                $file = $json_dir . $fg_id . '.json';
                if ( file_exists( $file ) && unlink( $file ) ) {
                    delete_transient( 'jscfr_json_export_' . $fg_id );
                    $count++;
                }
            }

            wp_send_json_success( array(
                'deleted' => $count,
                'total'   => count( $fg_ids ),
            ) );
        }

        /* ============================================================= */
        /*  Render page                                                    */
        /* ============================================================= */

        public function render_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'jscfr' ) );
            }
            ?>
            <div class="wrap jscfr-json-sync-wrap">
                <h1><?php esc_html_e( 'JSON Sync', 'jscfr' ); ?></h1>
                <p class="description">
                    <?php esc_html_e( 'Sync field groups between the database and JSON files in your active theme directory. JSON files are stored in', 'jscfr' ); ?>
                    <code><?php echo esc_html( trailingslashit( basename( get_stylesheet_directory() ) ) . 'jscfr-json/' ); ?></code>
                </p>
                <hr class="wp-header-end">

                <!-- Bulk Actions -->
                <div class="jscfr-json-sync-actions" style="margin:15px 0;">
                    <select id="jscfr-json-sync-bulk-action" class="jscfr-json-sync-bulk-select">
                        <option value=""><?php esc_html_e( 'Bulk Actions', 'jscfr' ); ?></option>
                        <option value="export"><?php esc_html_e( 'Export to JSON', 'jscfr' ); ?></option>
                        <option value="import"><?php esc_html_e( 'Import from JSON', 'jscfr' ); ?></option>
                        <option value="delete"><?php esc_html_e( 'Delete JSON', 'jscfr' ); ?></option>
                    </select>
                    <button type="button" class="button jscfr-json-sync-apply-btn" id="jscfr-json-sync-apply">
                        <?php esc_html_e( 'Apply', 'jscfr' ); ?>
                    </button>
                    <span class="spinner jscfr-json-sync-spinner" id="jscfr-json-sync-spinner"></span>
                </div>

                <!-- Status Table -->
                <table class="wp-list-table widefat fixed striped jscfr-json-sync-table">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column" style="width:30px;">
                                <input type="checkbox" id="jscfr-json-sync-select-all" />
                            </td>
                            <th class="manage-column jscfr-json-sync-col-title"><?php esc_html_e( 'Title', 'jscfr' ); ?></th>
                            <th class="manage-column jscfr-json-sync-col-status" style="width:130px;"><?php esc_html_e( 'Status', 'jscfr' ); ?></th>
                            <th class="manage-column jscfr-json-sync-col-db-mod" style="width:170px;"><?php esc_html_e( 'DB Modified', 'jscfr' ); ?></th>
                            <th class="manage-column jscfr-json-sync-col-json-mod" style="width:170px;"><?php esc_html_e( 'JSON Modified', 'jscfr' ); ?></th>
                            <th class="manage-column jscfr-json-sync-col-actions" style="width:200px;"><?php esc_html_e( 'Actions', 'jscfr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="jscfr-json-sync-tbody">
                        <tr>
                            <td colspan="6" style="text-align:center;padding:20px;">
                                <span class="spinner is-active" style="float:none;"></span>
                                <?php esc_html_e( 'Loading sync status...', 'jscfr' ); ?>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" class="jscfr-json-sync-select-all-foot" />
                            </td>
                            <th class="manage-column"><?php esc_html_e( 'Title', 'jscfr' ); ?></th>
                            <th class="manage-column"><?php esc_html_e( 'Status', 'jscfr' ); ?></th>
                            <th class="manage-column"><?php esc_html_e( 'DB Modified', 'jscfr' ); ?></th>
                            <th class="manage-column"><?php esc_html_e( 'JSON Modified', 'jscfr' ); ?></th>
                            <th class="manage-column"><?php esc_html_e( 'Actions', 'jscfr' ); ?></th>
                        </tr>
                    </tfoot>
                </table>

                <!-- Status message area -->
                <div id="jscfr-json-sync-message" class="jscfr-json-sync-message" style="margin-top:10px;"></div>
            </div>

            <style>
                .jscfr-json-sync-wrap .jscfr-json-sync-table { margin-top: 5px; }
                .jscfr-json-sync-badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: 600;
                    line-height: 1.4;
                    color: #fff;
                }
                .jscfr-json-sync-badge-db_only     { background: #0073aa; }
                .jscfr-json-sync-badge-json_only    { background: #826eb4; }
                .jscfr-json-sync-badge-in_sync      { background: #46b450; }
                .jscfr-json-sync-badge-db_newer     { background: #ffb900; color: #23282d; }
                .jscfr-json-sync-badge-json_newer    { background: #00a0d2; }

                .jscfr-json-sync-row-actions a {
                    cursor: pointer;
                    margin-right: 8px;
                }
                .jscfr-json-sync-row-actions a.jscfr-json-sync-action-delete {
                    color: #a00;
                }
                .jscfr-json-sync-row-actions a.jscfr-json-sync-action-delete:hover {
                    color: #dc3232;
                }
                .jscfr-json-sync-message .notice {
                    margin: 5px 0;
                }
            </style>

            <script type="text/javascript">
            (function($){
                var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
                var nonce   = '<?php echo esc_js( wp_create_nonce( JSCFR_BUILDER_NONCE ) ); ?>';

                var statusLabels = {
                    'db_only':    '<?php echo esc_js( __( 'DB Only', 'jscfr' ) ); ?>',
                    'json_only':  '<?php echo esc_js( __( 'JSON Only', 'jscfr' ) ); ?>',
                    'in_sync':    '<?php echo esc_js( __( 'In Sync', 'jscfr' ) ); ?>',
                    'db_newer':   '<?php echo esc_js( __( 'DB Newer', 'jscfr' ) ); ?>',
                    'json_newer': '<?php echo esc_js( __( 'JSON Newer', 'jscfr' ) ); ?>'
                };

                function formatDate(ts) {
                    if (!ts) return '&mdash;';
                    var d = new Date(ts * 1000);
                    return d.toLocaleString();
                }

                function getRowActions(item) {
                    var actions = [];
                    var s = item.status;

                    if (s === 'db_only' || s === 'db_newer' || s === 'in_sync') {
                        actions.push('<a class="jscfr-json-sync-action-export" data-fg="' + item.fg_id + '"><?php echo esc_js( __( 'Export', 'jscfr' ) ); ?></a>');
                    }
                    if (s === 'json_only' || s === 'json_newer' || s === 'in_sync') {
                        actions.push('<a class="jscfr-json-sync-action-import" data-fg="' + item.fg_id + '"><?php echo esc_js( __( 'Import', 'jscfr' ) ); ?></a>');
                    }
                    if (s !== 'db_only') {
                        actions.push('<a class="jscfr-json-sync-action-delete" data-fg="' + item.fg_id + '"><?php echo esc_js( __( 'Delete JSON', 'jscfr' ) ); ?></a>');
                    }
                    return actions.join(' | ');
                }

                function loadStatus() {
                    var $tbody = $('#jscfr-json-sync-tbody');
                    $tbody.html('<tr><td colspan="6" style="text-align:center;padding:20px;"><span class="spinner is-active" style="float:none;"></span> <?php echo esc_js( __( 'Loading...', 'jscfr' ) ); ?></td></tr>');

                    $.post(ajaxUrl, {
                        action: 'jscfr_json_sync_status',
                        nonce: nonce
                    }, function(res) {
                        if (!res.success || !res.data.length) {
                            $tbody.html('<tr><td colspan="6" style="text-align:center;padding:20px;"><?php echo esc_js( __( 'No field groups found.', 'jscfr' ) ); ?></td></tr>');
                            return;
                        }

                        var html = '';
                        $.each(res.data, function(i, item) {
                            html += '<tr data-fg="' + item.fg_id + '">';
                            html += '<th scope="row" class="check-column"><input type="checkbox" class="jscfr-json-sync-cb" value="' + item.fg_id + '" /></th>';
                            html += '<td><strong>' + $('<span>').text(item.title).html() + '</strong><br><code style="font-size:11px;">' + $('<span>').text(item.fg_id).html() + '</code></td>';
                            html += '<td><span class="jscfr-json-sync-badge jscfr-json-sync-badge-' + item.status + '">' + (statusLabels[item.status] || item.status) + '</span></td>';
                            html += '<td>' + formatDate(item.db_modified) + '</td>';
                            html += '<td>' + formatDate(item.json_modified) + '</td>';
                            html += '<td class="jscfr-json-sync-row-actions">' + getRowActions(item) + '</td>';
                            html += '</tr>';
                        });
                        $tbody.html(html);
                    });
                }

                function showMessage(text, type) {
                    var cls = (type === 'error') ? 'notice-error' : 'notice-success';
                    $('#jscfr-json-sync-message').html('<div class="notice ' + cls + ' is-dismissible"><p>' + text + '</p></div>');
                }

                function doAction(action, fgIds) {
                    if (!fgIds.length) return;
                    var $spinner = $('#jscfr-json-sync-spinner');
                    $spinner.addClass('is-active');

                    $.post(ajaxUrl, {
                        action: 'jscfr_json_' + action,
                        nonce: nonce,
                        fg_ids: fgIds
                    }, function(res) {
                        $spinner.removeClass('is-active');
                        if (res.success) {
                            var key = Object.keys(res.data)[0];
                            showMessage(res.data[key] + ' / ' + res.data.total + ' <?php echo esc_js( __( 'completed.', 'jscfr' ) ); ?>', 'success');
                            loadStatus();
                        } else {
                            showMessage(res.data || '<?php echo esc_js( __( 'An error occurred.', 'jscfr' ) ); ?>', 'error');
                        }
                    }).fail(function() {
                        $spinner.removeClass('is-active');
                        showMessage('<?php echo esc_js( __( 'Request failed.', 'jscfr' ) ); ?>', 'error');
                    });
                }

                // Select-all checkboxes.
                $(document).on('change', '#jscfr-json-sync-select-all, .jscfr-json-sync-select-all-foot', function() {
                    var checked = $(this).prop('checked');
                    $('.jscfr-json-sync-cb').prop('checked', checked);
                    $('#jscfr-json-sync-select-all, .jscfr-json-sync-select-all-foot').prop('checked', checked);
                });

                // Bulk apply.
                $('#jscfr-json-sync-apply').on('click', function() {
                    var bulkAction = $('#jscfr-json-sync-bulk-action').val();
                    if (!bulkAction) return;

                    var ids = [];
                    $('.jscfr-json-sync-cb:checked').each(function() {
                        ids.push($(this).val());
                    });
                    if (!ids.length) {
                        alert('<?php echo esc_js( __( 'Please select at least one field group.', 'jscfr' ) ); ?>');
                        return;
                    }
                    doAction(bulkAction, ids);
                });

                // Individual row actions.
                $(document).on('click', '.jscfr-json-sync-action-export', function(e) {
                    e.preventDefault();
                    doAction('export', [$(this).data('fg')]);
                });
                $(document).on('click', '.jscfr-json-sync-action-import', function(e) {
                    e.preventDefault();
                    doAction('import', [$(this).data('fg')]);
                });
                $(document).on('click', '.jscfr-json-sync-action-delete', function(e) {
                    e.preventDefault();
                    if (confirm('<?php echo esc_js( __( 'Delete this JSON file? This cannot be undone.', 'jscfr' ) ); ?>')) {
                        doAction('delete', [$(this).data('fg')]);
                    }
                });

                // Auto-load status on page load.
                loadStatus();

            })(jQuery);
            </script>
            <?php
        }

        /* ============================================================= */
        /*  Internal helpers                                               */
        /* ============================================================= */

        /**
         * Get the last-modified timestamp for the JSCFR option in the DB.
         * Falls back to current time if it cannot be determined.
         *
         * @return int Unix timestamp.
         */
        private function get_option_modified_time() {
            global $wpdb;

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT UNIX_TIMESTAMP(a.option_value) AS ts
                     FROM {$wpdb->options} a
                     WHERE a.option_name = %s
                     LIMIT 1",
                    JSCFR_OPTION_KEY
                )
            );

            // The above won't give a real modification time for options,
            // so fall back to the autoload query or current time.
            if ( $row && ! empty( $row->ts ) && $row->ts > 0 ) {
                return (int) $row->ts;
            }

            return time();
        }
    }

} // end class_exists check

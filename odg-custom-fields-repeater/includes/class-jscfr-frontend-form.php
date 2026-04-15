<?php
/**
 * JSCFR Frontend Form
 *
 * Provides a [jscfr_form] shortcode that renders field groups as a frontend form.
 * Handles AJAX submission to create/update posts with JSCFR custom field values.
 *
 * Usage:
 *   [jscfr_form field_group="fg_xxx" post_type="post"]
 *   [jscfr_form field_group="fg_xxx" post_type="post" post_id="123" post_status="draft"
 *               redirect="/thanks" submit_button="Submit" login_required="1"]
 *
 * @package JSCFR
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JSCFR_Frontend_Form' ) ) {

    final class JSCFR_Frontend_Form {

        private static $instance = null;

        /** Track whether assets have been enqueued for this page load */
        private $assets_enqueued = false;

        /** Nonce action/name */
        const NONCE_ACTION = 'jscfr_frontend_submit';
        const NONCE_NAME   = '_jscfr_frontend_nonce';

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_shortcode( 'jscfr_form', array( $this, 'render_shortcode' ) );

            // AJAX handlers
            add_action( 'wp_ajax_jscfr_frontend_submit', array( $this, 'ajax_submit' ) );
            add_action( 'wp_ajax_nopriv_jscfr_frontend_submit', array( $this, 'ajax_submit' ) );
        }

        /* ---------------------------------------------------------- */
        /*  Shortcode render                                           */
        /* ---------------------------------------------------------- */

        /**
         * Render the [jscfr_form] shortcode.
         *
         * @param array $atts Shortcode attributes.
         * @return string HTML output.
         */
        public function render_shortcode( $atts ) {
            $atts = shortcode_atts( array(
                'field_group'    => '',
                'post_type'      => 'post',
                'post_id'        => 0,
                'post_status'    => 'draft',
                'redirect'       => '',
                'submit_button'  => __( 'Submit', 'jscfr' ),
                'login_required' => '0',
                'updated_message' => __( 'Updated successfully.', 'jscfr' ),
                'created_message' => __( 'Submitted successfully.', 'jscfr' ),
            ), $atts, 'jscfr_form' );

            // Login check
            if ( $atts['login_required'] && ! is_user_logged_in() ) {
                return '<p class="jscfr-frontend-login-required">' . esc_html__( 'You must be logged in to submit this form.', 'jscfr' ) . '</p>';
            }

            // Get field group
            $fg_id = sanitize_text_field( $atts['field_group'] );
            if ( empty( $fg_id ) ) {
                return '<!-- jscfr_form: field_group attribute required -->';
            }

            $fg = JSCFR_Plugin::get_field_group( $fg_id );
            if ( ! $fg ) {
                return '<!-- jscfr_form: field group not found -->';
            }

            if ( isset( $fg['settings']['active'] ) && ! $fg['settings']['active'] ) {
                return '<!-- jscfr_form: field group is inactive -->';
            }

            // Enqueue assets
            $this->enqueue_assets( $fg );

            // Load existing data if editing
            $post_id  = absint( $atts['post_id'] );
            $existing = array();
            if ( $post_id ) {
                $existing = get_post_meta( $post_id, JSCFR_META_KEY, true );
                if ( ! is_array( $existing ) ) {
                    $existing = array();
                }
            }

            // Build form
            ob_start();
            ?>
            <div class="jscfr-frontend-form-wrap" data-fg-id="<?php echo esc_attr( $fg_id ); ?>">
                <form class="jscfr-frontend-form" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="jscfr_frontend_submit" />
                    <input type="hidden" name="<?php echo esc_attr( self::NONCE_NAME ); ?>" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>" />
                    <input type="hidden" name="jscfr_fg_id" value="<?php echo esc_attr( $fg_id ); ?>" />
                    <input type="hidden" name="jscfr_post_type" value="<?php echo esc_attr( $atts['post_type'] ); ?>" />
                    <input type="hidden" name="jscfr_post_id" value="<?php echo esc_attr( $post_id ); ?>" />
                    <input type="hidden" name="jscfr_post_status" value="<?php echo esc_attr( $atts['post_status'] ); ?>" />
                    <input type="hidden" name="jscfr_redirect" value="<?php echo esc_url( $atts['redirect'] ); ?>" />
                    <input type="hidden" name="jscfr_updated_message" value="<?php echo esc_attr( $atts['updated_message'] ); ?>" />
                    <input type="hidden" name="jscfr_created_message" value="<?php echo esc_attr( $atts['created_message'] ); ?>" />

                    <?php // Honeypot spam protection ?>
                    <div style="position:absolute;left:-9999px;" aria-hidden="true">
                        <input type="text" name="jscfr_hp_field" value="" tabindex="-1" autocomplete="off" />
                    </div>

                    <?php $this->render_field_group( $fg, $existing, $post_id ); ?>

                    <div class="jscfr-frontend-submit-wrap">
                        <button type="submit" class="jscfr-frontend-submit-btn">
                            <?php echo esc_html( $atts['submit_button'] ); ?>
                        </button>
                        <span class="jscfr-frontend-spinner" style="display:none;"></span>
                    </div>

                    <div class="jscfr-frontend-message" style="display:none;"></div>
                </form>
            </div>
            <?php
            return ob_get_clean();
        }

        /* ---------------------------------------------------------- */
        /*  Render field group (tabs → groups → fields)                */
        /* ---------------------------------------------------------- */

        /**
         * Render all tabs, groups, and fields for a field group.
         *
         * @param array $fg       The field group config.
         * @param array $existing Existing data blob (for editing).
         * @param int   $post_id  Post ID (0 for new).
         */
        private function render_field_group( $fg, $existing, $post_id ) {
            if ( empty( $fg['tabs'] ) ) {
                return;
            }

            $fgid     = $fg['id'];
            $settings = isset( $fg['settings'] ) ? $fg['settings'] : array();
            $has_tabs = count( $fg['tabs'] ) > 1 || ! empty( $fg['tabs'][0]['label'] );

            if ( $has_tabs ) {
                echo '<div class="jscfr-frontend-tabs">';
                echo '<ul class="jscfr-frontend-tab-nav">';
                foreach ( $fg['tabs'] as $ti => $tab ) {
                    $active = ( 0 === $ti ) ? ' class="jscfr-active"' : '';
                    echo '<li' . $active . '><a href="#jscfr-ftab-' . esc_attr( $tab['id'] ) . '">';
                    echo esc_html( ! empty( $tab['label'] ) ? $tab['label'] : __( 'Tab', 'jscfr' ) );
                    echo '</a></li>';
                }
                echo '</ul>';
            }

            foreach ( $fg['tabs'] as $ti => $tab ) {
                $tid     = $tab['id'];
                $display = ( $has_tabs && $ti > 0 ) ? ' style="display:none;"' : '';

                echo '<div class="jscfr-frontend-tab-panel" id="jscfr-ftab-' . esc_attr( $tid ) . '"' . $display . '>';

                if ( empty( $tab['groups'] ) ) {
                    echo '</div>';
                    continue;
                }

                foreach ( $tab['groups'] as $group ) {
                    $gid  = $group['id'];
                    $rows = isset( $existing[ $fgid ][ $tid ][ $gid ] ) ? $existing[ $fgid ][ $tid ][ $gid ] : array();
                    if ( empty( $rows ) ) {
                        $rows = array( array() ); // At least one empty row
                    }

                    $clonable = ! empty( $group['clonable'] );

                    echo '<div class="jscfr-frontend-group" data-group-id="' . esc_attr( $gid ) . '"' . ( $clonable ? ' data-clonable="1"' : '' ) . '>';
                    if ( ! empty( $group['label'] ) ) {
                        echo '<h4 class="jscfr-frontend-group-title">' . esc_html( $group['label'] ) . '</h4>';
                    }

                    foreach ( $rows as $ri => $row ) {
                        echo '<div class="jscfr-frontend-row' . ( $clonable ? ' jscfr-frontend-clone-row' : '' ) . '">';
                        if ( $clonable && count( $rows ) > 1 ) {
                            echo '<button type="button" class="jscfr-frontend-remove-row">&times;</button>';
                        }

                        if ( ! empty( $group['fields'] ) ) {
                            echo '<div class="jscfr-frontend-fields">';
                            foreach ( $group['fields'] as $field ) {
                                $this->render_field( $fgid, $tid, $gid, $ri, $field, $row );
                            }
                            echo '</div>';
                        }
                        echo '</div>';
                    }

                    if ( $clonable ) {
                        echo '<button type="button" class="jscfr-frontend-add-row">' . esc_html__( '+ Add Row', 'jscfr' ) . '</button>';
                    }

                    echo '</div>';
                }

                echo '</div>';
            }

            if ( $has_tabs ) {
                echo '</div>';
            }
        }

        /**
         * Render a single field for the frontend form.
         *
         * @param string $ef    Field group ID.
         * @param string $et    Tab ID.
         * @param string $eg    Group ID.
         * @param int    $ei    Row index.
         * @param array  $field Field config.
         * @param array  $data  Row data.
         */
        private function render_field( $ef, $et, $eg, $ei, $field, $data ) {
            $fid   = $field['id'];
            $ftype = $field['type'];
            $value = isset( $data[ $fid ] ) ? $data[ $fid ] : '';
            $req   = ! empty( $field['required'] );

            if ( '' === $value && ! empty( $field['default_value'] ) ) {
                $value = $field['default_value'];
            }

            $name  = 'jscfr_data[' . esc_attr( $ef ) . '][' . esc_attr( $et ) . '][' . esc_attr( $eg ) . '][' . $ei . '][' . esc_attr( $fid ) . ']';
            $domid = 'jscfr_fe_' . esc_attr( $ef . '_' . $et . '_' . $eg . '_' . $ei . '_' . $fid );

            // Display-only types
            if ( 'heading' === $ftype ) {
                $tag = ! empty( $field['heading_tag'] ) ? $field['heading_tag'] : 'h4';
                echo '<div class="jscfr-frontend-field jscfr-frontend-field--heading">';
                echo '<' . esc_attr( $tag ) . '>' . esc_html( $field['label'] ) . '</' . esc_attr( $tag ) . '>';
                echo '</div>';
                return;
            }
            if ( 'divider' === $ftype ) {
                echo '<div class="jscfr-frontend-field jscfr-frontend-field--divider"><hr /></div>';
                return;
            }
            if ( 'custom_html' === $ftype ) {
                echo '<div class="jscfr-frontend-field jscfr-frontend-field--custom-html">';
                echo wp_kses_post( ! empty( $field['html_content'] ) ? $field['html_content'] : '' );
                echo '</div>';
                return;
            }
            if ( 'button' === $ftype ) {
                return; // Skip buttons on frontend
            }
            if ( 'hidden' === $ftype ) {
                echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
                return;
            }

            // Columns layout
            $col_style = '';
            $col_span  = ! empty( $field['columns'] ) ? intval( $field['columns'] ) : 0;
            if ( $col_span > 0 && $col_span <= 12 ) {
                $col_style = ' style="grid-column:span ' . $col_span . ';"';
            }

            $req_attr = $req ? ' required' : '';
            $req_star = $req ? ' <span class="jscfr-frontend-required">*</span>' : '';

            echo '<div class="jscfr-frontend-field jscfr-frontend-field--' . esc_attr( $ftype ) . '"' . $col_style . '>';

            // Label
            if ( ! empty( $field['label'] ) ) {
                echo '<label for="' . esc_attr( $domid ) . '">' . esc_html( $field['label'] ) . $req_star . '</label>';
            }

            // Instructions
            if ( ! empty( $field['instructions'] ) ) {
                echo '<p class="jscfr-frontend-instructions">' . esc_html( $field['instructions'] ) . '</p>';
            }

            // Render input by type
            switch ( $ftype ) {
                case 'text':
                case 'email':
                case 'url':
                case 'password':
                    $input_type = $ftype;
                    echo '<input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" value="' . esc_attr( $value ) . '"';
                    if ( ! empty( $field['placeholder'] ) ) echo ' placeholder="' . esc_attr( $field['placeholder'] ) . '"';
                    if ( ! empty( $field['maxlength'] ) ) echo ' maxlength="' . esc_attr( $field['maxlength'] ) . '"';
                    echo $req_attr . ' class="jscfr-frontend-input" />';
                    break;

                case 'number':
                case 'range':
                    echo '<input type="' . ( 'range' === $ftype ? 'range' : 'number' ) . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" value="' . esc_attr( $value ) . '"';
                    if ( '' !== $field['min'] ) echo ' min="' . esc_attr( $field['min'] ) . '"';
                    if ( '' !== $field['max'] ) echo ' max="' . esc_attr( $field['max'] ) . '"';
                    if ( ! empty( $field['step'] ) ) echo ' step="' . esc_attr( $field['step'] ) . '"';
                    echo $req_attr . ' class="jscfr-frontend-input" />';
                    break;

                case 'textarea':
                    $rows = ! empty( $field['rows'] ) ? intval( $field['rows'] ) : 4;
                    echo '<textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" rows="' . esc_attr( $rows ) . '"';
                    if ( ! empty( $field['placeholder'] ) ) echo ' placeholder="' . esc_attr( $field['placeholder'] ) . '"';
                    if ( ! empty( $field['maxlength'] ) ) echo ' maxlength="' . esc_attr( $field['maxlength'] ) . '"';
                    echo $req_attr . ' class="jscfr-frontend-textarea">' . esc_textarea( $value ) . '</textarea>';
                    break;

                case 'wysiwyg':
                    $editor_id = 'jscfr_wysiwyg_' . esc_attr( $ef . $et . $eg . $ei . $fid );
                    wp_editor( $value, $editor_id, array(
                        'textarea_name' => $name,
                        'textarea_rows' => ! empty( $field['rows'] ) ? intval( $field['rows'] ) : 8,
                        'media_buttons' => false,
                        'teeny'         => true,
                        'quicktags'     => true,
                    ) );
                    break;

                case 'select':
                case 'select_advanced':
                    $options  = isset( $field['options'] ) ? $field['options'] : '';
                    $multiple = ! empty( $field['multiple'] );
                    $select_name = $multiple ? $name . '[]' : $name;
                    $css_class   = 'select_advanced' === $ftype ? 'jscfr-frontend-select jscfr-select2' : 'jscfr-frontend-select';
                    echo '<select name="' . esc_attr( $select_name ) . '" id="' . esc_attr( $domid ) . '"' . ( $multiple ? ' multiple' : '' ) . $req_attr . ' class="' . esc_attr( $css_class ) . '">';
                    if ( ! $multiple ) {
                        echo '<option value="">' . esc_html( ! empty( $field['placeholder'] ) ? $field['placeholder'] : __( '— Select —', 'jscfr' ) ) . '</option>';
                    }
                    $lines = is_string( $options ) ? explode( "\n", $options ) : (array) $options;
                    $vals  = is_array( $value ) ? $value : array( $value );
                    foreach ( $lines as $line ) {
                        $line = trim( $line );
                        if ( '' === $line ) continue;
                        if ( false !== strpos( $line, '|' ) ) {
                            list( $opt_val, $opt_label ) = explode( '|', $line, 2 );
                        } else {
                            $opt_val = $opt_label = $line;
                        }
                        $selected = in_array( trim( $opt_val ), $vals, true ) ? ' selected' : '';
                        echo '<option value="' . esc_attr( trim( $opt_val ) ) . '"' . $selected . '>' . esc_html( trim( $opt_label ) ) . '</option>';
                    }
                    echo '</select>';
                    break;

                case 'checkbox':
                    $options = isset( $field['options'] ) ? $field['options'] : '';
                    $lines   = is_string( $options ) ? explode( "\n", $options ) : (array) $options;
                    $vals    = is_array( $value ) ? $value : ( $value ? array( $value ) : array() );
                    echo '<div class="jscfr-frontend-checkboxes">';
                    foreach ( $lines as $li => $line ) {
                        $line = trim( $line );
                        if ( '' === $line ) continue;
                        if ( false !== strpos( $line, '|' ) ) {
                            list( $opt_val, $opt_label ) = explode( '|', $line, 2 );
                        } else {
                            $opt_val = $opt_label = $line;
                        }
                        $checked = in_array( trim( $opt_val ), $vals, true ) ? ' checked' : '';
                        echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( trim( $opt_val ) ) . '"' . $checked . ' /> ' . esc_html( trim( $opt_label ) ) . '</label>';
                    }
                    echo '</div>';
                    break;

                case 'radio':
                case 'button_group':
                    $options = isset( $field['options'] ) ? $field['options'] : '';
                    $lines   = is_string( $options ) ? explode( "\n", $options ) : (array) $options;
                    echo '<div class="jscfr-frontend-radios">';
                    foreach ( $lines as $li => $line ) {
                        $line = trim( $line );
                        if ( '' === $line ) continue;
                        if ( false !== strpos( $line, '|' ) ) {
                            list( $opt_val, $opt_label ) = explode( '|', $line, 2 );
                        } else {
                            $opt_val = $opt_label = $line;
                        }
                        $checked = ( trim( $opt_val ) === (string) $value ) ? ' checked' : '';
                        echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( trim( $opt_val ) ) . '"' . $checked . ' /> ' . esc_html( trim( $opt_label ) ) . '</label>';
                    }
                    echo '</div>';
                    break;

                case 'true_false':
                    $checked = ! empty( $value ) ? ' checked' : '';
                    echo '<label class="jscfr-frontend-toggle"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . $checked . ' /> ' . esc_html__( 'Yes', 'jscfr' ) . '</label>';
                    break;

                case 'switch':
                    $checked   = ! empty( $value ) ? ' checked' : '';
                    $on_label  = ! empty( $field['on_label'] ) ? $field['on_label'] : __( 'On', 'jscfr' );
                    $off_label = ! empty( $field['off_label'] ) ? $field['off_label'] : __( 'Off', 'jscfr' );
                    echo '<label class="jscfr-frontend-switch">';
                    echo '<input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . $checked . ' />';
                    echo '<span class="jscfr-frontend-switch-label" data-on="' . esc_attr( $on_label ) . '" data-off="' . esc_attr( $off_label ) . '"></span>';
                    echo '</label>';
                    break;

                case 'date':
                    echo '<input type="date" name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" value="' . esc_attr( $value ) . '"' . $req_attr . ' class="jscfr-frontend-input" />';
                    break;

                case 'datetime':
                    echo '<input type="datetime-local" name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" value="' . esc_attr( $value ) . '"' . $req_attr . ' class="jscfr-frontend-input" />';
                    break;

                case 'time':
                    echo '<input type="time" name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" value="' . esc_attr( $value ) . '"' . $req_attr . ' class="jscfr-frontend-input" />';
                    break;

                case 'color':
                    echo '<input type="color" name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" value="' . esc_attr( $value ? $value : '#000000' ) . '" class="jscfr-frontend-input jscfr-frontend-color" />';
                    break;

                case 'image':
                case 'single_image':
                case 'file':
                case 'video':
                case 'file_input':
                    // Frontend: simple file URL input
                    echo '<input type="url" name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( 'Enter file URL', 'jscfr' ) . '" class="jscfr-frontend-input" />';
                    break;

                case 'gallery':
                    $gallery_val = is_array( $value ) ? implode( ',', $value ) : $value;
                    echo '<input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" value="' . esc_attr( $gallery_val ) . '" placeholder="' . esc_attr__( 'Comma-separated image URLs or IDs', 'jscfr' ) . '" class="jscfr-frontend-input" />';
                    break;

                case 'oembed':
                    echo '<input type="url" name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( 'Enter embed URL (YouTube, Vimeo, etc.)', 'jscfr' ) . '"' . $req_attr . ' class="jscfr-frontend-input" />';
                    break;

                case 'post_object':
                case 'relationship':
                case 'page_link':
                    // Render as select with posts
                    $post_types = ! empty( $field['post_type_filter'] ) ? array( $field['post_type_filter'] ) : array( 'post', 'page' );
                    $multiple   = 'relationship' === $ftype || ! empty( $field['multiple'] );
                    $select_name = $multiple ? $name . '[]' : $name;
                    echo '<select name="' . esc_attr( $select_name ) . '" id="' . esc_attr( $domid ) . '"' . ( $multiple ? ' multiple size="8"' : '' ) . $req_attr . ' class="jscfr-frontend-select">';
                    if ( ! $multiple ) {
                        echo '<option value="">' . esc_html__( '— Select —', 'jscfr' ) . '</option>';
                    }
                    $posts = get_posts( array(
                        'post_type'      => $post_types,
                        'posts_per_page' => 200,
                        'orderby'        => 'title',
                        'order'          => 'ASC',
                        'post_status'    => 'publish',
                    ) );
                    $selected_vals = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
                    foreach ( $posts as $p ) {
                        $selected = in_array( (string) $p->ID, $selected_vals, true ) ? ' selected' : '';
                        echo '<option value="' . esc_attr( $p->ID ) . '"' . $selected . '>' . esc_html( $p->post_title ) . '</option>';
                    }
                    echo '</select>';
                    break;

                case 'taxonomy':
                case 'taxonomy_advanced':
                    $tax = ! empty( $field['taxonomy_type'] ) ? $field['taxonomy_type'] : 'category';
                    $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
                    $appearance = ! empty( $field['appearance'] ) ? $field['appearance'] : 'checkbox';
                    if ( is_wp_error( $terms ) ) $terms = array();

                    if ( 'select' === $appearance ) {
                        $multiple    = ! empty( $field['multiple'] );
                        $select_name = $multiple ? $name . '[]' : $name;
                        echo '<select name="' . esc_attr( $select_name ) . '" id="' . esc_attr( $domid ) . '"' . ( $multiple ? ' multiple' : '' ) . ' class="jscfr-frontend-select">';
                        echo '<option value="">' . esc_html__( '— Select —', 'jscfr' ) . '</option>';
                        $vals = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
                        foreach ( $terms as $term ) {
                            $sel = in_array( (string) $term->term_id, $vals, true ) ? ' selected' : '';
                            echo '<option value="' . esc_attr( $term->term_id ) . '"' . $sel . '>' . esc_html( $term->name ) . '</option>';
                        }
                        echo '</select>';
                    } else {
                        // Checkbox or radio
                        $is_radio = ( 'radio' === $appearance );
                        $vals     = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );
                        echo '<div class="jscfr-frontend-term-list">';
                        foreach ( $terms as $term ) {
                            $checked = in_array( (string) $term->term_id, $vals, true ) ? ' checked' : '';
                            $input_type = $is_radio ? 'radio' : 'checkbox';
                            $input_name = $is_radio ? $name : $name . '[]';
                            echo '<label><input type="' . $input_type . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $term->term_id ) . '"' . $checked . ' /> ' . esc_html( $term->name ) . '</label>';
                        }
                        echo '</div>';
                    }
                    break;

                case 'user':
                    $users = get_users( array( 'number' => 200, 'orderby' => 'display_name' ) );
                    echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '"' . $req_attr . ' class="jscfr-frontend-select">';
                    echo '<option value="">' . esc_html__( '— Select User —', 'jscfr' ) . '</option>';
                    foreach ( $users as $u ) {
                        $sel = ( (string) $u->ID === (string) $value ) ? ' selected' : '';
                        echo '<option value="' . esc_attr( $u->ID ) . '"' . $sel . '>' . esc_html( $u->display_name ) . '</option>';
                    }
                    echo '</select>';
                    break;

                case 'sidebar':
                    global $wp_registered_sidebars;
                    echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '"' . $req_attr . ' class="jscfr-frontend-select">';
                    echo '<option value="">' . esc_html__( '— Select Sidebar —', 'jscfr' ) . '</option>';
                    if ( ! empty( $wp_registered_sidebars ) ) {
                        foreach ( $wp_registered_sidebars as $sb ) {
                            $sel = ( $sb['id'] === $value ) ? ' selected' : '';
                            echo '<option value="' . esc_attr( $sb['id'] ) . '"' . $sel . '>' . esc_html( $sb['name'] ) . '</option>';
                        }
                    }
                    echo '</select>';
                    break;

                case 'image_select':
                    $img_options = ! empty( $field['image_options'] ) ? $field['image_options'] : '';
                    $lines       = explode( "\n", $img_options );
                    $is_multi    = ! empty( $field['image_select_multiple'] );
                    $vals        = is_array( $value ) ? $value : array( $value );
                    echo '<div class="jscfr-frontend-image-select">';
                    foreach ( $lines as $line ) {
                        $line = trim( $line );
                        if ( '' === $line ) continue;
                        $parts     = explode( '|', $line );
                        $opt_val   = isset( $parts[0] ) ? trim( $parts[0] ) : '';
                        $opt_img   = isset( $parts[1] ) ? trim( $parts[1] ) : '';
                        $opt_label = isset( $parts[2] ) ? trim( $parts[2] ) : $opt_val;
                        $checked   = in_array( $opt_val, $vals, true ) ? ' checked' : '';
                        $type      = $is_multi ? 'checkbox' : 'radio';
                        $iname     = $is_multi ? $name . '[]' : $name;
                        echo '<label class="jscfr-frontend-imgsel-item">';
                        echo '<input type="' . $type . '" name="' . esc_attr( $iname ) . '" value="' . esc_attr( $opt_val ) . '"' . $checked . ' />';
                        if ( $opt_img ) {
                            echo '<img src="' . esc_url( $opt_img ) . '" alt="' . esc_attr( $opt_label ) . '" />';
                        }
                        echo '<span>' . esc_html( $opt_label ) . '</span>';
                        echo '</label>';
                    }
                    echo '</div>';
                    break;

                case 'key_value':
                    $pairs = is_array( $value ) ? $value : array();
                    if ( empty( $pairs ) ) {
                        $pairs = array( array( 'key' => '', 'value' => '' ) );
                    }
                    echo '<div class="jscfr-frontend-key-value">';
                    foreach ( $pairs as $pi => $pair ) {
                        $k = isset( $pair['key'] ) ? $pair['key'] : '';
                        $v = isset( $pair['value'] ) ? $pair['value'] : '';
                        echo '<div class="jscfr-frontend-kv-row">';
                        echo '<input type="text" name="' . esc_attr( $name ) . '[' . $pi . '][key]" value="' . esc_attr( $k ) . '" placeholder="' . esc_attr__( 'Key', 'jscfr' ) . '" class="jscfr-frontend-input" />';
                        echo '<input type="text" name="' . esc_attr( $name ) . '[' . $pi . '][value]" value="' . esc_attr( $v ) . '" placeholder="' . esc_attr__( 'Value', 'jscfr' ) . '" class="jscfr-frontend-input" />';
                        echo '<button type="button" class="jscfr-frontend-kv-remove">&times;</button>';
                        echo '</div>';
                    }
                    echo '<button type="button" class="jscfr-frontend-kv-add">' . esc_html__( '+ Add Pair', 'jscfr' ) . '</button>';
                    echo '</div>';
                    break;

                case 'fieldset_text':
                case 'text_list':
                    $sub_fields = ! empty( $field['sub_fields'] ) ? $field['sub_fields'] : '';
                    $lines      = explode( "\n", $sub_fields );
                    $vals       = is_array( $value ) ? $value : array();
                    echo '<div class="jscfr-frontend-fieldset-text">';
                    foreach ( $lines as $line ) {
                        $line = trim( $line );
                        if ( '' === $line ) continue;
                        if ( false !== strpos( $line, '|' ) ) {
                            list( $sub_key, $sub_label ) = explode( '|', $line, 2 );
                        } else {
                            $sub_key = $sub_label = $line;
                        }
                        $sub_key = trim( $sub_key );
                        $sub_val = isset( $vals[ $sub_key ] ) ? $vals[ $sub_key ] : '';
                        echo '<div class="jscfr-frontend-fst-pair">';
                        echo '<label>' . esc_html( trim( $sub_label ) ) . '</label>';
                        echo '<input type="text" name="' . esc_attr( $name ) . '[' . esc_attr( $sub_key ) . ']" value="' . esc_attr( $sub_val ) . '" class="jscfr-frontend-input" />';
                        echo '</div>';
                    }
                    echo '</div>';
                    break;

                case 'background':
                    $bg = is_array( $value ) ? $value : array();
                    $bg_color      = isset( $bg['color'] ) ? $bg['color'] : '';
                    $bg_image      = isset( $bg['image'] ) ? $bg['image'] : '';
                    $bg_repeat     = isset( $bg['repeat'] ) ? $bg['repeat'] : '';
                    $bg_position   = isset( $bg['position'] ) ? $bg['position'] : '';
                    $bg_size       = isset( $bg['size'] ) ? $bg['size'] : '';
                    $bg_attachment = isset( $bg['attachment'] ) ? $bg['attachment'] : '';

                    echo '<div class="jscfr-frontend-background">';
                    echo '<label>' . esc_html__( 'Color', 'jscfr' ) . '</label>';
                    echo '<input type="color" name="' . esc_attr( $name ) . '[color]" value="' . esc_attr( $bg_color ? $bg_color : '#ffffff' ) . '" class="jscfr-frontend-input" />';

                    echo '<label>' . esc_html__( 'Image URL', 'jscfr' ) . '</label>';
                    echo '<input type="url" name="' . esc_attr( $name ) . '[image]" value="' . esc_attr( $bg_image ) . '" class="jscfr-frontend-input" />';

                    echo '<label>' . esc_html__( 'Repeat', 'jscfr' ) . '</label>';
                    echo '<select name="' . esc_attr( $name ) . '[repeat]" class="jscfr-frontend-select">';
                    foreach ( array( '' => '—', 'no-repeat' => 'No Repeat', 'repeat' => 'Repeat', 'repeat-x' => 'Repeat X', 'repeat-y' => 'Repeat Y' ) as $rk => $rl ) {
                        echo '<option value="' . esc_attr( $rk ) . '"' . selected( $bg_repeat, $rk, false ) . '>' . esc_html( $rl ) . '</option>';
                    }
                    echo '</select>';

                    echo '<label>' . esc_html__( 'Position', 'jscfr' ) . '</label>';
                    echo '<select name="' . esc_attr( $name ) . '[position]" class="jscfr-frontend-select">';
                    foreach ( array( '' => '—', 'left top' => 'Left Top', 'center top' => 'Center Top', 'right top' => 'Right Top', 'left center' => 'Left Center', 'center center' => 'Center', 'right center' => 'Right Center', 'left bottom' => 'Left Bottom', 'center bottom' => 'Center Bottom', 'right bottom' => 'Right Bottom' ) as $pk => $pl ) {
                        echo '<option value="' . esc_attr( $pk ) . '"' . selected( $bg_position, $pk, false ) . '>' . esc_html( $pl ) . '</option>';
                    }
                    echo '</select>';

                    echo '<label>' . esc_html__( 'Size', 'jscfr' ) . '</label>';
                    echo '<select name="' . esc_attr( $name ) . '[size]" class="jscfr-frontend-select">';
                    foreach ( array( '' => '—', 'cover' => 'Cover', 'contain' => 'Contain', 'auto' => 'Auto' ) as $sk => $sl ) {
                        echo '<option value="' . esc_attr( $sk ) . '"' . selected( $bg_size, $sk, false ) . '>' . esc_html( $sl ) . '</option>';
                    }
                    echo '</select>';

                    echo '<label>' . esc_html__( 'Attachment', 'jscfr' ) . '</label>';
                    echo '<select name="' . esc_attr( $name ) . '[attachment]" class="jscfr-frontend-select">';
                    foreach ( array( '' => '—', 'scroll' => 'Scroll', 'fixed' => 'Fixed' ) as $ak => $al ) {
                        echo '<option value="' . esc_attr( $ak ) . '"' . selected( $bg_attachment, $ak, false ) . '>' . esc_html( $al ) . '</option>';
                    }
                    echo '</select>';
                    echo '</div>';
                    break;

                default:
                    // Fallback: text input
                    echo '<input type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $domid ) . '" value="' . esc_attr( is_array( $value ) ? wp_json_encode( $value ) : $value ) . '" class="jscfr-frontend-input" />';
                    break;
            }

            echo '</div>';
        }

        /* ---------------------------------------------------------- */
        /*  Enqueue frontend assets                                    */
        /* ---------------------------------------------------------- */

        /**
         * Enqueue CSS and JS for the frontend form.
         *
         * @param array $fg The field group.
         */
        private function enqueue_assets( $fg ) {
            if ( $this->assets_enqueued ) {
                return;
            }
            $this->assets_enqueued = true;

            // Detect field types in use
            $has_types = array();
            if ( ! empty( $fg['tabs'] ) ) {
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

            // Select2 for select_advanced
            if ( ! empty( $has_types['select_advanced'] ) ) {
                wp_enqueue_style( 'jscfr-select2', JSCFR_PLUGIN_URL . 'assets/vendor/select2.min.css', array(), '4.0.13' );
                wp_enqueue_script( 'jscfr-select2', JSCFR_PLUGIN_URL . 'assets/vendor/select2.min.js', array( 'jquery' ), '4.0.13', true );
            }

            wp_enqueue_style( 'jscfr-frontend-css', JSCFR_PLUGIN_URL . 'assets/css/jscfr-frontend.css', array(), JSCFR_VERSION );

            $js_deps = array( 'jquery' );
            if ( ! empty( $has_types['select_advanced'] ) ) {
                $js_deps[] = 'jscfr-select2';
            }

            wp_enqueue_script( 'jscfr-frontend-js', JSCFR_PLUGIN_URL . 'assets/js/jscfr-frontend.js', $js_deps, JSCFR_VERSION, true );

            wp_localize_script( 'jscfr-frontend-js', 'jscfr_front', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'i18n'     => array(
                    'submitting' => __( 'Submitting...', 'jscfr' ),
                    'error'      => __( 'An error occurred. Please try again.', 'jscfr' ),
                ),
            ) );
        }

        /* ---------------------------------------------------------- */
        /*  AJAX submission handler                                    */
        /* ---------------------------------------------------------- */

        /**
         * Handle frontend form AJAX submission.
         */
        public function ajax_submit() {
            // Verify nonce
            if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
                wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'jscfr' ) ) );
            }

            // Honeypot check
            if ( ! empty( $_POST['jscfr_hp_field'] ) ) {
                wp_send_json_error( array( 'message' => __( 'Spam detected.', 'jscfr' ) ) );
            }

            $fg_id       = sanitize_text_field( isset( $_POST['jscfr_fg_id'] ) ? $_POST['jscfr_fg_id'] : '' );
            $post_type   = sanitize_key( isset( $_POST['jscfr_post_type'] ) ? $_POST['jscfr_post_type'] : 'post' );
            $post_id     = absint( isset( $_POST['jscfr_post_id'] ) ? $_POST['jscfr_post_id'] : 0 );
            $post_status = sanitize_key( isset( $_POST['jscfr_post_status'] ) ? $_POST['jscfr_post_status'] : 'draft' );
            $redirect    = esc_url_raw( isset( $_POST['jscfr_redirect'] ) ? $_POST['jscfr_redirect'] : '' );
            $updated_msg = sanitize_text_field( isset( $_POST['jscfr_updated_message'] ) ? $_POST['jscfr_updated_message'] : __( 'Updated successfully.', 'jscfr' ) );
            $created_msg = sanitize_text_field( isset( $_POST['jscfr_created_message'] ) ? $_POST['jscfr_created_message'] : __( 'Submitted successfully.', 'jscfr' ) );

            // Validate field group
            $fg = JSCFR_Plugin::get_field_group( $fg_id );
            if ( ! $fg ) {
                wp_send_json_error( array( 'message' => __( 'Invalid field group.', 'jscfr' ) ) );
            }

            // Validate post type
            $pt_obj = get_post_type_object( $post_type );
            if ( ! $pt_obj ) {
                wp_send_json_error( array( 'message' => __( 'Invalid post type.', 'jscfr' ) ) );
            }

            // Allowed post statuses
            $allowed_statuses = array( 'draft', 'pending', 'publish', 'private' );
            if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
                $post_status = 'draft';
            }

            // Check capabilities
            $is_update = ( $post_id > 0 );
            if ( $is_update ) {
                $existing_post = get_post( $post_id );
                if ( ! $existing_post || $existing_post->post_type !== $post_type ) {
                    wp_send_json_error( array( 'message' => __( 'Post not found.', 'jscfr' ) ) );
                }
                if ( is_user_logged_in() && ! current_user_can( 'edit_post', $post_id ) ) {
                    wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this post.', 'jscfr' ) ) );
                }
            } else {
                // For creating, allow if user can edit posts of this type or if anonymous submission is allowed
                if ( is_user_logged_in() && ! current_user_can( $pt_obj->cap->create_posts ) ) {
                    wp_send_json_error( array( 'message' => __( 'You do not have permission to create posts.', 'jscfr' ) ) );
                }
            }

            // Create or update post
            $post_data = array(
                'post_type'   => $post_type,
                'post_status' => $post_status,
            );

            if ( is_user_logged_in() ) {
                $post_data['post_author'] = get_current_user_id();
            }

            if ( $is_update ) {
                $post_data['ID'] = $post_id;
                $result = wp_update_post( $post_data, true );
            } else {
                $post_data['post_title'] = __( 'Frontend Submission', 'jscfr' );
                $result = wp_insert_post( $post_data, true );
            }

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            $post_id = $is_update ? $post_id : $result;

            // Process field data — reuse same structure as metabox
            $raw = isset( $_POST['jscfr_data'] ) ? $_POST['jscfr_data'] : array();

            $existing_blob = get_post_meta( $post_id, JSCFR_META_KEY, true );
            if ( ! is_array( $existing_blob ) ) {
                $existing_blob = array();
            }

            $fgid = $fg['id'];
            if ( ! isset( $raw[ $fgid ] ) || ! is_array( $raw[ $fgid ] ) ) {
                $raw[ $fgid ] = array();
            }

            $existing_blob[ $fgid ] = array();

            if ( ! empty( $fg['tabs'] ) ) {
                foreach ( $fg['tabs'] as $tab ) {
                    $tid = $tab['id'];
                    if ( ! isset( $raw[ $fgid ][ $tid ] ) || ! is_array( $raw[ $fgid ][ $tid ] ) ) {
                        continue;
                    }

                    $existing_blob[ $fgid ][ $tid ] = array();

                    if ( empty( $tab['groups'] ) ) continue;

                    foreach ( $tab['groups'] as $group ) {
                        $gid = $group['id'];
                        if ( ! isset( $raw[ $fgid ][ $tid ][ $gid ] ) || ! is_array( $raw[ $fgid ][ $tid ][ $gid ] ) ) {
                            continue;
                        }

                        $clean_rows = array();

                        foreach ( $raw[ $fgid ][ $tid ][ $gid ] as $row ) {
                            if ( ! is_array( $row ) ) continue;
                            $clean_row = array();
                            $has_val   = false;

                            if ( ! empty( $group['fields'] ) ) {
                                foreach ( $group['fields'] as $field ) {
                                    $f_id = $field['id'];
                                    $val  = isset( $row[ $f_id ] ) ? $row[ $f_id ] : '';
                                    $val  = $this->sanitize_frontend_value( $val, $field );
                                    $clean_row[ $f_id ] = $val;
                                    if ( $this->has_value( $val ) ) {
                                        $has_val = true;
                                    }
                                }
                            }

                            if ( $has_val ) {
                                $clean_rows[] = $clean_row;
                            }
                        }

                        $existing_blob[ $fgid ][ $tid ][ $gid ] = array_values( $clean_rows );
                    }
                }
            }

            // Save blob
            if ( ! empty( $existing_blob ) ) {
                update_post_meta( $post_id, JSCFR_META_KEY, $existing_blob );
            }

            // v5: Write individual meta rows
            $field_map = array();
            if ( ! empty( $fg['tabs'] ) ) {
                foreach ( $fg['tabs'] as $tab ) {
                    $tid = $tab['id'];
                    if ( empty( $tab['groups'] ) ) continue;

                    foreach ( $tab['groups'] as $group ) {
                        $gid  = $group['id'];
                        $rows = isset( $existing_blob[ $fgid ][ $tid ][ $gid ] ) ? $existing_blob[ $fgid ][ $tid ][ $gid ] : array();

                        $group_name = ! empty( $group['name'] ) ? $group['name'] : $group['id'];
                        JSCFR_Plugin::set_field_value( $group_name, $rows, $post_id );

                        $field_map[ $group_name ] = array(
                            'type'     => 'group',
                            'fg_id'    => $fgid,
                            'tab_id'   => $tid,
                            'group_id' => $gid,
                        );

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

            if ( ! empty( $field_map ) ) {
                update_post_meta( $post_id, JSCFR_FIELD_MAP_KEY, $field_map );
            }

            do_action( 'jscfr/frontend_after_save', $post_id, $fg );

            $message = $is_update ? $updated_msg : $created_msg;

            wp_send_json_success( array(
                'message'  => $message,
                'post_id'  => $post_id,
                'redirect' => $redirect,
            ) );
        }

        /* ---------------------------------------------------------- */
        /*  Sanitize field value for frontend                          */
        /* ---------------------------------------------------------- */

        /**
         * Sanitize a field value from frontend submission.
         * More restrictive than admin — no unfiltered HTML.
         *
         * @param mixed $val   Raw value.
         * @param array $field Field config.
         * @return mixed Sanitized value.
         */
        private function sanitize_frontend_value( $val, $field ) {
            switch ( $field['type'] ) {
                case 'text':
                case 'date':
                case 'datetime':
                case 'time':
                case 'color':
                case 'password':
                case 'hidden':
                case 'select':
                case 'select_advanced':
                case 'radio':
                case 'button_group':
                case 'sidebar':
                case 'image_select':
                case 'slider':
                case 'autocomplete':
                    return sanitize_text_field( $val );

                case 'textarea':
                    return sanitize_textarea_field( $val );

                case 'wysiwyg':
                case 'custom_html':
                    return wp_kses_post( $val );

                case 'email':
                    return sanitize_email( $val );

                case 'url':
                case 'oembed':
                case 'file_input':
                case 'image':
                case 'single_image':
                case 'file':
                case 'video':
                    return esc_url_raw( $val );

                case 'number':
                case 'range':
                    return is_numeric( $val ) ? $val : '';

                case 'true_false':
                case 'switch':
                    return ! empty( $val ) ? '1' : '0';

                case 'checkbox':
                case 'taxonomy':
                case 'taxonomy_advanced':
                case 'gallery':
                case 'relationship':
                    if ( is_array( $val ) ) {
                        return array_map( 'sanitize_text_field', $val );
                    }
                    return sanitize_text_field( $val );

                case 'post_object':
                case 'page_link':
                case 'user':
                    return absint( $val );

                case 'key_value':
                    if ( ! is_array( $val ) ) return array();
                    $clean = array();
                    foreach ( $val as $pair ) {
                        if ( ! is_array( $pair ) ) continue;
                        $clean[] = array(
                            'key'   => sanitize_text_field( isset( $pair['key'] ) ? $pair['key'] : '' ),
                            'value' => sanitize_text_field( isset( $pair['value'] ) ? $pair['value'] : '' ),
                        );
                    }
                    return $clean;

                case 'fieldset_text':
                case 'text_list':
                    if ( ! is_array( $val ) ) return array();
                    return array_map( 'sanitize_text_field', $val );

                case 'background':
                    if ( ! is_array( $val ) ) return array();
                    return array(
                        'color'      => sanitize_hex_color( isset( $val['color'] ) ? $val['color'] : '' ),
                        'image'      => esc_url_raw( isset( $val['image'] ) ? $val['image'] : '' ),
                        'repeat'     => sanitize_text_field( isset( $val['repeat'] ) ? $val['repeat'] : '' ),
                        'position'   => sanitize_text_field( isset( $val['position'] ) ? $val['position'] : '' ),
                        'size'       => sanitize_text_field( isset( $val['size'] ) ? $val['size'] : '' ),
                        'attachment' => sanitize_text_field( isset( $val['attachment'] ) ? $val['attachment'] : '' ),
                    );

                default:
                    return is_array( $val ) ? array_map( 'sanitize_text_field', $val ) : sanitize_text_field( $val );
            }
        }

        /**
         * Check if a value is non-empty.
         *
         * @param mixed $val The value.
         * @return bool
         */
        private function has_value( $val ) {
            if ( is_array( $val ) ) {
                return ! empty( $val );
            }
            return '' !== $val && false !== $val && null !== $val;
        }
    }

}

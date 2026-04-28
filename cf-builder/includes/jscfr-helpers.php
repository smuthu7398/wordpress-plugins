<?php
/**
 * JSCFR Helper / Template Functions & Shortcode (v4)
 * ACF-style API: jscfr_get_field(), jscfr_have_rows(), jscfr_the_row(), etc.
 * All functions prefixed with jscfr_ to avoid conflicts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ================================================================== */
/*  Row loop state (for have_rows / the_row pattern)                   */
/* ================================================================== */
global $jscfr_row_state;
$jscfr_row_state = array(
    'active'  => false,
    'rows'    => array(),
    'index'   => -1,
    'current' => null,
    'fg_id'   => '',
    'tab_id'  => '',
    'grp_id'  => '',
);

/* ================================================================== */
/*  Object-context auto-detection                                      */
/* ================================================================== */

/**
 * Resolve the implicit object context (id + type) for the current request.
 *
 * Used when callers omit the $post_id/$object_type arguments. Inside a
 * `the_post()` loop we always read from the looped post (so per-item field
 * lookups inside an archive listing work). Otherwise we fall back to the
 * queried object — which gives terms/users their own context on archive,
 * author, and term pages.
 *
 * @return array [ $object_id, $object_type ]
 */
function jscfr_resolve_current_object() {
    if ( function_exists( 'in_the_loop' ) && in_the_loop() ) {
        return array( get_the_ID(), 'post' );
    }
    if ( function_exists( 'get_queried_object' ) ) {
        $obj = get_queried_object();
        if ( $obj instanceof WP_Term ) {
            return array( (int) $obj->term_id, 'term' );
        }
        if ( $obj instanceof WP_User ) {
            return array( (int) $obj->ID, 'user' );
        }
        if ( $obj instanceof WP_Post ) {
            return array( (int) $obj->ID, 'post' );
        }
    }
    return array( get_the_ID(), 'post' );
}

/* ================================================================== */
/*  Core data retrieval                                                */
/* ================================================================== */

/**
 * Get all saved custom field data for a post (or options).
 *
 * v5: Returns flat name→value array from individual meta rows.
 * Falls back to v4 blob if no v5 data found.
 */
function jscfr_get_data( $post_id = null ) {
    if ( 'options' === $post_id || 'option' === $post_id ) {
        return JSCFR_Plugin::get_all_field_values( null, 'options' );
    }
    if ( ! $post_id ) {
        $post_id = get_the_ID();
    }

    // Try v5 individual meta first
    $v5 = JSCFR_Plugin::get_all_field_values( $post_id );
    if ( ! empty( $v5 ) ) {
        return $v5;
    }

    // Fallback: v4 blob
    $data = get_post_meta( $post_id, JSCFR_META_KEY, true );
    return is_array( $data ) ? $data : array();
}

/**
 * Get clone rows for a specific field_group + tab + group (original API).
 */
function jscfr_get_group( $fg_id, $tab_id, $group_id, $post_id = null ) {
    $data = jscfr_get_data( $post_id );
    return isset( $data[ $fg_id ][ $tab_id ][ $group_id ] ) ? $data[ $fg_id ][ $tab_id ][ $group_id ] : array();
}

/**
 * Get a single field value (original verbose API).
 */
function jscfr_get_field_by_path( $fg_id, $tab_id, $group_id, $field_id, $clone_index = 0, $post_id = null ) {
    $rows = jscfr_get_group( $fg_id, $tab_id, $group_id, $post_id );
    return isset( $rows[ $clone_index ][ $field_id ] ) ? $rows[ $clone_index ][ $field_id ] : '';
}

/* ================================================================== */
/*  Simplified ACF-style API                                           */
/* ================================================================== */

/**
 * Get a field value by field name (slug).
 *
 * Usage:
 *   jscfr_get_field( 'my_field_name' )              — first clone row, current post
 *   jscfr_get_field( 'my_field_name', $post_id )    — first clone row, specific post
 *   jscfr_get_field( 'my_field_name', 'options' )   — options page
 *
 * For fields inside a loop (jscfr_have_rows), use jscfr_get_sub_field() instead.
 */
function jscfr_get_field( $selector, $post_id = null, $clone_index = 0 ) {
    // If called with old-style 4+ args, delegate to legacy function
    if ( func_num_args() >= 4 ) {
        $args = func_get_args();
        return jscfr_get_field_by_path( $args[0], $args[1], $args[2], $args[3], isset( $args[4] ) ? $args[4] : 0, isset( $args[5] ) ? $args[5] : null );
    }

    $info = JSCFR_Plugin::resolve_field( $selector );
    if ( ! $info ) {
        return '';
    }

    // Determine object type
    $obj_type = 'post';
    if ( 'options' === $post_id || 'option' === $post_id ) {
        $obj_type = 'options';
    }

    if ( 'group' === $info['type'] ) {
        // v5: read group rows from individual meta
        $val = JSCFR_Plugin::get_field_value( $selector, $post_id, $obj_type );
        if ( ! empty( $val ) && is_array( $val ) ) {
            return $val;
        }
        // Fallback: v4 blob path
        return jscfr_get_group( $info['fg_id'], $info['tab_id'], $info['group_id'], $post_id );
    }

    // v5: read field from individual meta (has v4 blob fallback built in)
    $raw = JSCFR_Plugin::get_field_value( $selector, $post_id, $obj_type );

    // For non-zero clone_index, fall back to blob-based read
    if ( $clone_index > 0 && 'options' !== $obj_type ) {
        $blob_post_id = $post_id ? $post_id : get_the_ID();
        $blob = get_post_meta( $blob_post_id, JSCFR_META_KEY, true );
        if ( is_array( $blob ) && isset( $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ][ $clone_index ][ $info['field_id'] ] ) ) {
            $raw = $blob[ $info['fg_id'] ][ $info['tab_id'] ][ $info['group_id'] ][ $clone_index ][ $info['field_id'] ];
        }
    }

    return apply_filters( 'jscfr/format_value', $raw, $info['field'], $post_id );
}

/**
 * Echo a field value.
 */
function jscfr_the_field( $selector, $post_id = null, $clone_index = 0 ) {
    $val = jscfr_get_field( $selector, $post_id, $clone_index );
    if ( is_array( $val ) ) {
        echo esc_html( wp_json_encode( $val ) );
    } else {
        echo wp_kses_post( $val );
    }
}

/* ================================================================== */
/*  have_rows / the_row loop API                                       */
/* ================================================================== */

/**
 * Check if a group (repeater) has rows and prepare iteration.
 *
 * Usage:
 *   if ( jscfr_have_rows( 'my_group' ) ) {
 *       while ( jscfr_have_rows( 'my_group' ) ) {
 *           jscfr_the_row();
 *           $val = jscfr_get_sub_field( 'my_field' );
 *       }
 *   }
 *
 * Term/user/comment contexts:
 *   jscfr_have_rows( 'my_group', $term_id, 'term' )
 *   jscfr_have_rows( 'my_group', $user_id, 'user' )
 *   jscfr_have_rows( 'my_group', $comment_id, 'comment' )
 */
function jscfr_have_rows( $selector, $post_id = null, $object_type = null ) {
    global $jscfr_row_state;

    $info = JSCFR_Plugin::resolve_field( $selector );
    if ( ! $info || 'group' !== $info['type'] ) {
        $jscfr_row_state['active'] = false;
        return false;
    }

    // Resolve context. Explicit args win; otherwise auto-detect from current request.
    if ( null === $object_type ) {
        if ( 'options' === $post_id || 'option' === $post_id ) {
            $object_type = 'options';
        } elseif ( null === $post_id ) {
            list( $post_id, $object_type ) = jscfr_resolve_current_object();
        } else {
            $object_type = 'post';
        }
    }

    // Initialize on first call OR when context (group/post/type) changes
    $needs_init = ! $jscfr_row_state['active']
        || $jscfr_row_state['grp_id'] !== $info['group_id']
        || ( isset( $jscfr_row_state['post_id'] ) && $jscfr_row_state['post_id'] !== $post_id )
        || ( isset( $jscfr_row_state['object_type'] ) && $jscfr_row_state['object_type'] !== $object_type );

    if ( $needs_init ) {
        // v5: read group rows from individual meta (has v4 blob fallback)
        $rows = JSCFR_Plugin::get_field_value( $selector, $post_id, $object_type );
        if ( ( ! is_array( $rows ) || empty( $rows ) ) && in_array( $object_type, array( 'post', 'options' ), true ) ) {
            // Fallback: v4 blob path (post/options only — no blob storage for term/user/comment)
            $rows = jscfr_get_group( $info['fg_id'], $info['tab_id'], $info['group_id'], $post_id );
        }
        $jscfr_row_state = array(
            'active'      => true,
            'rows'        => is_array( $rows ) ? $rows : array(),
            'index'       => -1,
            'current'     => null,
            'fg_id'       => $info['fg_id'],
            'tab_id'      => $info['tab_id'],
            'grp_id'      => $info['group_id'],
            'post_id'     => $post_id,
            'object_type' => $object_type,
        );
    }

    // Check if more rows
    if ( $jscfr_row_state['index'] + 1 < count( $jscfr_row_state['rows'] ) ) {
        return true;
    }

    // Reset when done
    $jscfr_row_state['active'] = false;
    $jscfr_row_state['index']  = -1;
    return false;
}

/**
 * Advance to the next row.
 *
 * Returns the new current row (truthy) on success, or false when there are no more rows.
 * Supports both patterns:
 *   while ( jscfr_have_rows( 'g' ) ) { jscfr_the_row(); ... }
 *   while ( jscfr_the_row() ) { ... }   // requires prior jscfr_have_rows() call
 */
function jscfr_the_row() {
    global $jscfr_row_state;
    if ( ! $jscfr_row_state['active'] ) {
        return false;
    }
    $jscfr_row_state['index']++;
    $idx = $jscfr_row_state['index'];
    if ( ! isset( $jscfr_row_state['rows'][ $idx ] ) ) {
        $jscfr_row_state['active']  = false;
        $jscfr_row_state['current'] = null;
        return false;
    }
    $jscfr_row_state['current'] = $jscfr_row_state['rows'][ $idx ];
    // Return non-empty array (truthy) or sentinel `true` for an empty row so loop continues.
    return ! empty( $jscfr_row_state['current'] ) ? $jscfr_row_state['current'] : true;
}

/**
 * Get a sub field value inside a have_rows loop.
 */
function jscfr_get_sub_field( $field_name ) {
    global $jscfr_row_state;
    if ( ! $jscfr_row_state['active'] || ! $jscfr_row_state['current'] ) {
        return '';
    }

    // Try by field name first (resolve to field ID)
    $row = $jscfr_row_state['current'];
    if ( isset( $row[ $field_name ] ) ) {
        return $row[ $field_name ];
    }

    // Try resolving name → id
    $info = JSCFR_Plugin::resolve_field( $field_name );
    if ( $info && 'field' === $info['type'] && isset( $row[ $info['field_id'] ] ) ) {
        return $row[ $info['field_id'] ];
    }

    return '';
}

/**
 * Echo a sub field value.
 */
function jscfr_the_sub_field( $field_name ) {
    $val = jscfr_get_sub_field( $field_name );
    if ( is_array( $val ) ) {
        echo esc_html( wp_json_encode( $val ) );
    } else {
        echo wp_kses_post( $val );
    }
}

/**
 * Get the current row index (0-based).
 */
function jscfr_get_row_index() {
    global $jscfr_row_state;
    return $jscfr_row_state['active'] ? $jscfr_row_state['index'] : -1;
}

/**
 * Get the full current row as an associative array.
 */
function jscfr_get_row() {
    global $jscfr_row_state;
    return $jscfr_row_state['active'] ? $jscfr_row_state['current'] : array();
}

/**
 * Reset the row loop state manually.
 */
function jscfr_reset_rows() {
    global $jscfr_row_state;
    $jscfr_row_state = array(
        'active'  => false,
        'rows'    => array(),
        'index'   => -1,
        'current' => null,
        'fg_id'   => '',
        'tab_id'  => '',
        'grp_id'  => '',
    );
}

/* ================================================================== */
/*  Utility helpers                                                    */
/* ================================================================== */

/**
 * Get image field data based on return_format.
 */
function jscfr_get_image( $selector, $post_id = null, $size = 'full' ) {
    $val = jscfr_get_field( $selector, $post_id );
    if ( ! $val ) return '';

    $att_id = absint( $val );
    if ( ! $att_id ) return '';

    // Check field's return_format
    $info = JSCFR_Plugin::resolve_field( $selector );
    $format = ( $info && isset( $info['field']['return_format'] ) ) ? $info['field']['return_format'] : 'id';

    switch ( $format ) {
        case 'url':
            $src = wp_get_attachment_image_src( $att_id, $size );
            return $src ? $src[0] : '';
        case 'array':
            $src = wp_get_attachment_image_src( $att_id, $size );
            return array(
                'ID'     => $att_id,
                'url'    => $src ? $src[0] : '',
                'width'  => $src ? $src[1] : 0,
                'height' => $src ? $src[2] : 0,
                'alt'    => get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
                'title'  => get_the_title( $att_id ),
            );
        default: // 'id'
            return $att_id;
    }
}

/**
 * Get all field groups config (public accessor).
 */
function jscfr_get_field_groups() {
    return JSCFR_Plugin::get_config();
}

/**
 * Check if a field group exists.
 */
function jscfr_field_group_exists( $fg_id ) {
    return null !== JSCFR_Plugin::get_field_group( $fg_id );
}

/* ================================================================== */
/*  Shortcode                                                          */
/* ================================================================== */

/**
 * Shortcode: [jscfr field="field_name" post_id="123"]
 * Simple shortcode to output a single field value.
 */
function jscfr_field_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'field'   => '',
        'post_id' => '',
    ), $atts, 'jscfr_field' );

    if ( ! $atts['field'] ) return '';

    $post_id = $atts['post_id'] ? intval( $atts['post_id'] ) : null;
    $val = jscfr_get_field( $atts['field'], $post_id );

    if ( is_array( $val ) ) {
        return esc_html( wp_json_encode( $val ) );
    }
    return wp_kses_post( $val );
}
add_shortcode( 'jscfr_field', 'jscfr_field_shortcode' );

/**
 * Shortcode: [jscfr_list field_group="fg_xxx" tab="tab_xxx" group="grp_xxx" fields="fld1,fld2"]
 * Or simplified: [jscfr_list group="my_group_name" fields="field1,field2"]
 */
function jscfr_list_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'field_group' => '',
        'tab'         => '',
        'group'       => '',
        'fields'      => '',
        'post_id'     => '',
    ), $atts, 'jscfr_list' );

    $post_id = $atts['post_id'] ? intval( $atts['post_id'] ) : null;
    $rows    = array();

    // Try simplified name-based lookup
    if ( $atts['group'] && ! $atts['field_group'] ) {
        $info = JSCFR_Plugin::resolve_field( $atts['group'] );
        if ( $info && 'group' === $info['type'] ) {
            $rows = jscfr_get_group( $info['fg_id'], $info['tab_id'], $info['group_id'], $post_id );
        }
    } elseif ( $atts['field_group'] && $atts['tab'] && $atts['group'] ) {
        $rows = jscfr_get_group( $atts['field_group'], $atts['tab'], $atts['group'], $post_id );
    }

    if ( empty( $rows ) ) {
        return '';
    }

    $show_fields = $atts['fields'] ? array_map( 'trim', explode( ',', $atts['fields'] ) ) : null;

    $out = '<div class="jscfr-list">';
    foreach ( $rows as $row ) {
        $out .= '<div class="jscfr-list-item">';
        foreach ( $row as $fid => $val ) {
            if ( $show_fields && ! in_array( $fid, $show_fields, true ) ) {
                // Also try matching by field name
                $field_info = JSCFR_Plugin::resolve_field( $fid );
                if ( ! $field_info ) continue;
                $fname = isset( $field_info['field']['name'] ) ? $field_info['field']['name'] : '';
                if ( ! in_array( $fname, $show_fields, true ) ) continue;
            }
            if ( '' === $val || ( ! is_array( $val ) && '0' === $val ) ) continue;

            // Handle array values (link, post_object, relationship)
            if ( is_array( $val ) ) {
                if ( isset( $val['url'] ) ) {
                    // Link field
                    $out .= '<div class="jscfr-list-field jscfr-list-field--link"><a href="' . esc_url( $val['url'] ) . '"' . ( ! empty( $val['target'] ) ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( ! empty( $val['title'] ) ? $val['title'] : $val['url'] ) . '</a></div>';
                } else {
                    $out .= '<div class="jscfr-list-field">' . esc_html( wp_json_encode( $val ) ) . '</div>';
                }
                continue;
            }

            // File/image detection
            if ( is_numeric( $val ) && intval( $val ) > 0 ) {
                $url = wp_get_attachment_url( intval( $val ) );
                if ( $url ) {
                    if ( wp_attachment_is_image( intval( $val ) ) ) {
                        $out .= '<div class="jscfr-list-field jscfr-list-field--image"><img src="' . esc_url( $url ) . '" alt="" /></div>';
                    } else {
                        $fname = basename( get_attached_file( intval( $val ) ) ?: '' );
                        $out .= '<div class="jscfr-list-field jscfr-list-field--file"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $fname ) . '</a></div>';
                    }
                    continue;
                }
            }

            $out .= '<div class="jscfr-list-field">' . wp_kses_post( $val ) . '</div>';
        }
        $out .= '</div>';
    }
    $out .= '</div>';

    return $out;
}
add_shortcode( 'jscfr_list', 'jscfr_list_shortcode' );

/* ================================================================== */
/*  Default format_value filter                                        */
/* ================================================================== */

/**
 * Apply field-type–specific formatting when retrieving values via API.
 *
 * Handles:
 *   - textarea `new_lines` setting (wpautop / br / none)
 *   - image/file `return_format` (id / url / array)
 *   - date/datetime/time `display_format`
 */
function jscfr_default_format_value( $value, $field, $post_id ) {
    if ( empty( $field ) || ! is_array( $field ) ) {
        return $value;
    }
    $type = isset( $field['type'] ) ? $field['type'] : '';

    switch ( $type ) {

        /* -- Textarea: new_lines -- */
        case 'textarea':
            $nl = isset( $field['new_lines'] ) ? $field['new_lines'] : '';
            if ( 'wpautop' === $nl ) {
                $value = wpautop( $value );
            } elseif ( 'br' === $nl ) {
                $value = nl2br( $value );
            }
            break;

        /* -- Image: return_format -- */
        case 'image':
            $att_id = absint( $value );
            if ( ! $att_id ) break;
            $format = isset( $field['return_format'] ) ? $field['return_format'] : 'id';
            $size   = isset( $field['preview_size'] ) ? $field['preview_size'] : 'full';
            if ( 'url' === $format ) {
                $src   = wp_get_attachment_image_src( $att_id, $size );
                $value = $src ? $src[0] : '';
            } elseif ( 'array' === $format ) {
                $src   = wp_get_attachment_image_src( $att_id, $size );
                $value = array(
                    'ID'     => $att_id,
                    'url'    => $src ? $src[0] : '',
                    'width'  => $src ? $src[1] : 0,
                    'height' => $src ? $src[2] : 0,
                    'alt'    => get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
                    'title'  => get_the_title( $att_id ),
                );
            }
            break;

        /* -- File: return_format -- */
        case 'file':
            $att_id = absint( $value );
            if ( ! $att_id ) break;
            $format = isset( $field['return_format'] ) ? $field['return_format'] : 'id';
            if ( 'url' === $format ) {
                $value = wp_get_attachment_url( $att_id );
            } elseif ( 'array' === $format ) {
                $value = array(
                    'ID'       => $att_id,
                    'url'      => wp_get_attachment_url( $att_id ),
                    'filename' => basename( get_attached_file( $att_id ) ?: '' ),
                    'title'    => get_the_title( $att_id ),
                    'filesize' => filesize( get_attached_file( $att_id ) ?: '' ),
                    'type'     => get_post_mime_type( $att_id ),
                );
            }
            break;

        /* -- Date/Datetime/Time: display_format -- */
        case 'date':
        case 'datetime':
        case 'time':
            $display_fmt = isset( $field['display_format'] ) ? $field['display_format'] : '';
            if ( $display_fmt && $value ) {
                $ts = strtotime( $value );
                if ( false !== $ts ) {
                    $value = date_i18n( $display_fmt, $ts );
                }
            }
            break;
    }

    return $value;
}
add_filter( 'jscfr/format_value', 'jscfr_default_format_value', 10, 3 );

/* ================================================================== */
/*  v5 Write / Delete helpers                                          */
/* ================================================================== */

/**
 * Update (set) a field value.
 *
 * Usage:
 *   jscfr_update_field( 'my_field', 'new value' )             — current post
 *   jscfr_update_field( 'my_field', 'new value', $post_id )   — specific post
 *   jscfr_update_field( 'my_field', 'new value', 'options' )  — options
 */
function jscfr_update_field( $selector, $value, $post_id = null ) {
    $obj_type = 'post';
    if ( 'options' === $post_id || 'option' === $post_id ) {
        $obj_type = 'options';
    }
    if ( ! $post_id && 'post' === $obj_type ) {
        $post_id = get_the_ID();
    }
    JSCFR_Plugin::set_field_value( $selector, $value, $post_id, $obj_type );
}

/**
 * Delete a field value.
 *
 * Usage:
 *   jscfr_delete_field( 'my_field' )             — current post
 *   jscfr_delete_field( 'my_field', $post_id )   — specific post
 *   jscfr_delete_field( 'my_field', 'options' )  — options
 */
function jscfr_delete_field( $selector, $post_id = null ) {
    $obj_type = 'post';
    if ( 'options' === $post_id || 'option' === $post_id ) {
        $obj_type = 'options';
    }
    if ( ! $post_id && 'post' === $obj_type ) {
        $post_id = get_the_ID();
    }
    JSCFR_Plugin::delete_field_value( $selector, $post_id, $obj_type );
}

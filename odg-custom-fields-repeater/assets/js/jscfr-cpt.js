/**
 * JSCFR Custom Post Types & Taxonomies admin JS
 * @package JSCFR
 * @since   5.0.0
 */
(function($) {
    'use strict';

    var CPT_DATA = {};
    var TAX_DATA = {};

    /* ================================================================ */
    /*  Helpers                                                          */
    /* ================================================================ */
    function closeModal() {
        $('.jscfr-modal').fadeOut(150);
    }

    $(document).on('click', '.jscfr-modal-cancel', closeModal);
    $(document).on('click', '.jscfr-modal', function(e) {
        if ($(e.target).hasClass('jscfr-modal')) closeModal();
    });

    /* Load CPT data from table rows */
    function loadCptDataFromRows() {
        CPT_DATA = {};
        $('#jscfr-cpt-list tbody tr[data-slug]').each(function() {
            var slug = $(this).data('slug');
            CPT_DATA[slug] = $(this).data('config') || {};
        });
    }

    function loadTaxDataFromRows() {
        TAX_DATA = {};
        $('#jscfr-tax-list tbody tr[data-slug]').each(function() {
            var slug = $(this).data('slug');
            TAX_DATA[slug] = $(this).data('config') || {};
        });
    }

    /* ================================================================ */
    /*  CPT: Add / Edit / Delete                                         */
    /* ================================================================ */
    $(document).on('click', '#jscfr-add-cpt', function() {
        $('#jscfr-cpt-form-title').text('Add Custom Post Type');
        $('#jscfr-cpt-form')[0].reset();
        $('#jscfr-cpt-editing').val('');
        $('#jscfr-cpt-slug').prop('readonly', false);
        // Default supports
        $('.jscfr-cpt-support').prop('checked', false);
        $('.jscfr-cpt-support[value="title"]').prop('checked', true);
        $('.jscfr-cpt-support[value="editor"]').prop('checked', true);
        // Default visibility
        $('#jscfr-cpt-public, #jscfr-cpt-publicly-queryable, #jscfr-cpt-show-ui, #jscfr-cpt-show-in-menu, #jscfr-cpt-show-in-nav, #jscfr-cpt-show-in-admin-bar, #jscfr-cpt-show-in-rest, #jscfr-cpt-has-archive').prop('checked', true);
        $('#jscfr-cpt-hierarchical, #jscfr-cpt-exclude-search').prop('checked', false);
        $('#jscfr-cpt-icon').val('dashicons-admin-post');
        updateIconPreview();
        $('#jscfr-cpt-form-modal').fadeIn(150);
    });

    $(document).on('click', '.jscfr-edit-cpt', function() {
        var $row = $(this).closest('tr');
        var slug = $row.data('slug');

        $.post(jscfr_cpt.ajax_url, {
            action: 'jscfr_get_cpt_data',
            nonce:  jscfr_cpt.nonce,
            slug:   slug
        }, function() {
            // We'll populate from stored option data via a sync approach
        });

        // For simplicity, we fetch all CPTs and populate
        // This is done inline since we have the data in the page
        $('#jscfr-cpt-form-title').text('Edit Custom Post Type');
        $('#jscfr-cpt-editing').val(slug);
        $('#jscfr-cpt-slug').val(slug).prop('readonly', true);
        $('#jscfr-cpt-form-modal').fadeIn(150);

        // Populate from AJAX
        $.post(jscfr_cpt.ajax_url, {
            action: 'jscfr_get_cpt_config',
            nonce:  jscfr_cpt.nonce,
            slug:   slug
        }, function(res) {
            if (!res.success) return;
            var c = res.data;
            $('#jscfr-cpt-singular').val(c.singular || '');
            $('#jscfr-cpt-plural').val(c.plural || '');
            $('#jscfr-cpt-icon').val(c.menu_icon || 'dashicons-admin-post');
            updateIconPreview();
            $('#jscfr-cpt-position').val(c.menu_position || 25);
            $('#jscfr-cpt-rewrite-slug').val(c.rewrite_slug || '');

            $('#jscfr-cpt-public').prop('checked', !!c.public);
            $('#jscfr-cpt-publicly-queryable').prop('checked', c.publicly_queryable !== false);
            $('#jscfr-cpt-show-ui').prop('checked', c.show_ui !== false);
            $('#jscfr-cpt-show-in-menu').prop('checked', c.show_in_menu !== false);
            $('#jscfr-cpt-show-in-nav').prop('checked', c.show_in_nav_menus !== false);
            $('#jscfr-cpt-show-in-admin-bar').prop('checked', c.show_in_admin_bar !== false);
            $('#jscfr-cpt-show-in-rest').prop('checked', c.show_in_rest !== false);
            $('#jscfr-cpt-has-archive').prop('checked', !!c.has_archive);
            $('#jscfr-cpt-hierarchical').prop('checked', !!c.hierarchical);
            $('#jscfr-cpt-exclude-search').prop('checked', !!c.exclude_from_search);

            // Supports
            $('.jscfr-cpt-support').prop('checked', false);
            if (c.supports && typeof c.supports === 'object') {
                $.each(c.supports, function(key) {
                    $('.jscfr-cpt-support[value="' + key + '"]').prop('checked', true);
                });
            }

            // Taxonomies
            $('.jscfr-cpt-tax').prop('checked', false);
            if (c.taxonomies && Array.isArray(c.taxonomies)) {
                c.taxonomies.forEach(function(t) {
                    $('.jscfr-cpt-tax[value="' + t + '"]').prop('checked', true);
                });
            }
        });
    });

    $(document).on('submit', '#jscfr-cpt-form', function(e) {
        e.preventDefault();

        var supports = [];
        $('.jscfr-cpt-support:checked').each(function() { supports.push($(this).val()); });

        var taxonomies = [];
        $('.jscfr-cpt-tax:checked').each(function() { taxonomies.push($(this).val()); });

        var data = {
            action: 'jscfr_save_cpt',
            nonce:  jscfr_cpt.nonce,
            cpt: {
                editing:             $('#jscfr-cpt-editing').val(),
                slug:                $('#jscfr-cpt-slug').val(),
                singular:            $('#jscfr-cpt-singular').val(),
                plural:              $('#jscfr-cpt-plural').val(),
                public:              $('#jscfr-cpt-public').is(':checked') ? 1 : 0,
                publicly_queryable:  $('#jscfr-cpt-publicly-queryable').is(':checked') ? 1 : 0,
                show_ui:             $('#jscfr-cpt-show-ui').is(':checked') ? 1 : 0,
                show_in_menu:        $('#jscfr-cpt-show-in-menu').is(':checked') ? 1 : 0,
                show_in_nav_menus:   $('#jscfr-cpt-show-in-nav').is(':checked') ? 1 : 0,
                show_in_admin_bar:   $('#jscfr-cpt-show-in-admin-bar').is(':checked') ? 1 : 0,
                show_in_rest:        $('#jscfr-cpt-show-in-rest').is(':checked') ? 1 : 0,
                has_archive:         $('#jscfr-cpt-has-archive').is(':checked') ? 1 : 0,
                hierarchical:        $('#jscfr-cpt-hierarchical').is(':checked') ? 1 : 0,
                exclude_from_search: $('#jscfr-cpt-exclude-search').is(':checked') ? 1 : 0,
                menu_icon:           $('#jscfr-cpt-icon').val(),
                menu_position:       $('#jscfr-cpt-position').val(),
                rewrite_slug:        $('#jscfr-cpt-rewrite-slug').val(),
                supports:            supports,
                taxonomies:          taxonomies
            }
        };

        $.post(jscfr_cpt.ajax_url, data, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.data || 'Error saving post type.');
            }
        });
    });

    $(document).on('click', '.jscfr-delete-cpt', function() {
        if (!confirm(jscfr_cpt.confirm_delete)) return;
        var slug = $(this).closest('tr').data('slug');
        $.post(jscfr_cpt.ajax_url, {
            action: 'jscfr_delete_cpt',
            nonce:  jscfr_cpt.nonce,
            slug:   slug
        }, function(res) {
            if (res.success) location.reload();
        });
    });

    /* ================================================================ */
    /*  Taxonomy: Add / Edit / Delete                                    */
    /* ================================================================ */
    $(document).on('click', '#jscfr-add-tax', function() {
        $('#jscfr-tax-form-title').text('Add Custom Taxonomy');
        $('#jscfr-tax-form')[0].reset();
        $('#jscfr-tax-editing').val('');
        $('#jscfr-tax-slug').prop('readonly', false);
        $('#jscfr-tax-public, #jscfr-tax-publicly-queryable, #jscfr-tax-show-ui, #jscfr-tax-show-in-menu, #jscfr-tax-show-in-nav, #jscfr-tax-show-in-rest, #jscfr-tax-show-admin-column, #jscfr-tax-hierarchical').prop('checked', true);
        $('#jscfr-tax-show-tagcloud').prop('checked', false);
        $('.jscfr-tax-pt').prop('checked', false);
        $('#jscfr-tax-form-modal').fadeIn(150);
    });

    $(document).on('click', '.jscfr-edit-tax', function() {
        var $row = $(this).closest('tr');
        var slug = $row.data('slug');

        $('#jscfr-tax-form-title').text('Edit Custom Taxonomy');
        $('#jscfr-tax-editing').val(slug);
        $('#jscfr-tax-slug').val(slug).prop('readonly', true);
        $('#jscfr-tax-form-modal').fadeIn(150);

        $.post(jscfr_cpt.ajax_url, {
            action: 'jscfr_get_tax_config',
            nonce:  jscfr_cpt.nonce,
            slug:   slug
        }, function(res) {
            if (!res.success) return;
            var c = res.data;
            $('#jscfr-tax-singular').val(c.singular || '');
            $('#jscfr-tax-plural').val(c.plural || '');
            $('#jscfr-tax-rewrite-slug').val(c.rewrite_slug || '');

            $('#jscfr-tax-public').prop('checked', !!c.public);
            $('#jscfr-tax-publicly-queryable').prop('checked', c.publicly_queryable !== false);
            $('#jscfr-tax-show-ui').prop('checked', c.show_ui !== false);
            $('#jscfr-tax-show-in-menu').prop('checked', c.show_in_menu !== false);
            $('#jscfr-tax-show-in-nav').prop('checked', c.show_in_nav_menus !== false);
            $('#jscfr-tax-show-in-rest').prop('checked', c.show_in_rest !== false);
            $('#jscfr-tax-show-admin-column').prop('checked', c.show_admin_column !== false);
            $('#jscfr-tax-show-tagcloud').prop('checked', !!c.show_tagcloud);
            $('#jscfr-tax-hierarchical').prop('checked', c.hierarchical !== false);

            $('.jscfr-tax-pt').prop('checked', false);
            if (c.post_types && Array.isArray(c.post_types)) {
                c.post_types.forEach(function(pt) {
                    $('.jscfr-tax-pt[value="' + pt + '"]').prop('checked', true);
                });
            }
        });
    });

    $(document).on('submit', '#jscfr-tax-form', function(e) {
        e.preventDefault();

        var post_types = [];
        $('.jscfr-tax-pt:checked').each(function() { post_types.push($(this).val()); });

        var data = {
            action: 'jscfr_save_taxonomy',
            nonce:  jscfr_cpt.nonce,
            tax: {
                editing:             $('#jscfr-tax-editing').val(),
                slug:                $('#jscfr-tax-slug').val(),
                singular:            $('#jscfr-tax-singular').val(),
                plural:              $('#jscfr-tax-plural').val(),
                public:              $('#jscfr-tax-public').is(':checked') ? 1 : 0,
                publicly_queryable:  $('#jscfr-tax-publicly-queryable').is(':checked') ? 1 : 0,
                show_ui:             $('#jscfr-tax-show-ui').is(':checked') ? 1 : 0,
                show_in_menu:        $('#jscfr-tax-show-in-menu').is(':checked') ? 1 : 0,
                show_in_nav_menus:   $('#jscfr-tax-show-in-nav').is(':checked') ? 1 : 0,
                show_in_rest:        $('#jscfr-tax-show-in-rest').is(':checked') ? 1 : 0,
                show_admin_column:   $('#jscfr-tax-show-admin-column').is(':checked') ? 1 : 0,
                show_tagcloud:       $('#jscfr-tax-show-tagcloud').is(':checked') ? 1 : 0,
                hierarchical:        $('#jscfr-tax-hierarchical').is(':checked') ? 1 : 0,
                rewrite_slug:        $('#jscfr-tax-rewrite-slug').val(),
                post_types:          post_types
            }
        };

        $.post(jscfr_cpt.ajax_url, data, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.data || 'Error saving taxonomy.');
            }
        });
    });

    $(document).on('click', '.jscfr-delete-tax', function() {
        if (!confirm(jscfr_cpt.confirm_delete)) return;
        var slug = $(this).closest('tr').data('slug');
        $.post(jscfr_cpt.ajax_url, {
            action: 'jscfr_delete_taxonomy',
            nonce:  jscfr_cpt.nonce,
            slug:   slug
        }, function(res) {
            if (res.success) location.reload();
        });
    });

    /* ================================================================ */
    /*  Icon preview                                                     */
    /* ================================================================ */
    function updateIconPreview() {
        var val = $('#jscfr-cpt-icon').val();
        $('#jscfr-cpt-icon-preview').attr('class', 'dashicons ' + val);
    }
    $(document).on('change', '#jscfr-cpt-icon', updateIconPreview);

})(jQuery);

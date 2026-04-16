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
    /*  CPT form submit (full-page form, server-rendered values)         */
    /* ================================================================ */
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
                window.location.href = 'admin.php?page=jscfr-post-types';
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
    /* Tab switching for Meta Box–style form */
    $(document).on('click', '.jscfr-mb-tab-nav li[data-jscfr-tab]', function() {
        var $tabs = $(this).closest('.jscfr-mb-tabs');
        var target = $(this).data('jscfr-tab');
        $tabs.find('.jscfr-mb-tab-nav li').removeClass('active');
        $(this).addClass('active');
        $tabs.find('.jscfr-mb-tab-panel').removeClass('active');
        $tabs.find('.jscfr-mb-tab-panel[data-jscfr-panel="' + target + '"]').addClass('active');
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
                window.location.href = 'admin.php?page=jscfr-taxonomies';
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
    /*  Icon picker (visual grid + search)                               */
    /* ================================================================ */
    $(document).on('click', '.jscfr-icon-cell', function(e) {
        e.preventDefault();
        var $cell = $(this);
        var icon  = $cell.data('icon');
        $cell.closest('.jscfr-icon-picker').find('.jscfr-icon-cell').removeClass('active');
        $cell.addClass('active');
        $('#jscfr-cpt-icon').val(icon);
        $('#jscfr-cpt-icon-preview').attr('class', 'dashicons ' + icon);
        $('.jscfr-icon-picker-value').text(icon.replace('dashicons-', ''));
    });

    $(document).on('input', '.jscfr-icon-picker-search-input', function() {
        var q      = $(this).val().trim().toLowerCase();
        var $grid  = $(this).closest('.jscfr-icon-picker').find('.jscfr-icon-picker-grid');
        var $cells = $grid.find('.jscfr-icon-cell');
        var visible = 0;
        $cells.each(function() {
            var match = !q || $(this).data('search').indexOf(q) !== -1;
            $(this).toggle(match);
            if (match) visible++;
        });
        $grid.find('.jscfr-icon-picker-empty').prop('hidden', visible > 0);
    });

})(jQuery);

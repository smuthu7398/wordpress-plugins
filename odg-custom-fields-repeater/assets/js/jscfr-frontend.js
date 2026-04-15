/**
 * JSCFR Frontend Form JS
 * Handles AJAX submission, tab navigation, clone rows, key-value pairs, Select2.
 *
 * @package JSCFR
 * @since   5.0.0
 */
(function($) {
    'use strict';

    /* ================================================================ */
    /*  Tab navigation                                                   */
    /* ================================================================ */
    $(document).on('click', '.jscfr-frontend-tab-nav a', function(e) {
        e.preventDefault();
        var $link  = $(this),
            target = $link.attr('href'),
            $wrap  = $link.closest('.jscfr-frontend-tabs');

        $wrap.find('.jscfr-frontend-tab-nav li').removeClass('jscfr-active');
        $link.parent('li').addClass('jscfr-active');

        $wrap.find('.jscfr-frontend-tab-panel').hide();
        $wrap.find(target).show();
    });

    /* ================================================================ */
    /*  Clone rows (repeater)                                            */
    /* ================================================================ */
    $(document).on('click', '.jscfr-frontend-add-row', function() {
        var $group  = $(this).closest('.jscfr-frontend-group');
        var $rows   = $group.find('.jscfr-frontend-row');
        var $last   = $rows.last();
        var $clone  = $last.clone();
        var newIdx  = $rows.length;

        // Clear values
        $clone.find('input:not([type=hidden]):not([type=radio]):not([type=checkbox])').val('');
        $clone.find('textarea').val('');
        $clone.find('select').prop('selectedIndex', 0);
        $clone.find('input[type=radio], input[type=checkbox]').prop('checked', false);

        // Update name indices
        $clone.find('[name]').each(function() {
            var oldName = $(this).attr('name');
            // Replace the row index [N] — it's the 4th bracket group in jscfr_data[fg][tab][group][N][field]
            var parts = oldName.match(/^(jscfr_data\[[^\]]+\]\[[^\]]+\]\[[^\]]+\])\[\d+\](.*)$/);
            if (parts) {
                $(this).attr('name', parts[1] + '[' + newIdx + ']' + parts[2]);
            }
        });

        // Update IDs
        $clone.find('[id]').each(function() {
            var oldId = $(this).attr('id');
            $(this).attr('id', oldId.replace(/_\d+_([^_]+)$/, '_' + newIdx + '_$1'));
        });

        // Add remove button if first clone didn't have one
        if ($clone.find('.jscfr-frontend-remove-row').length === 0) {
            $clone.prepend('<button type="button" class="jscfr-frontend-remove-row">&times;</button>');
        }

        // Also add remove button to first row if it doesn't have one
        if ($rows.length === 1 && $rows.first().find('.jscfr-frontend-remove-row').length === 0) {
            $rows.first().prepend('<button type="button" class="jscfr-frontend-remove-row">&times;</button>');
        }

        $last.after($clone);

        // Re-init Select2 on cloned selects
        $clone.find('.jscfr-select2').each(function() {
            if ($.fn.select2) {
                $(this).select2({ width: '100%' });
            }
        });
    });

    $(document).on('click', '.jscfr-frontend-remove-row', function() {
        var $group = $(this).closest('.jscfr-frontend-group');
        var $rows  = $group.find('.jscfr-frontend-row');
        if ($rows.length <= 1) return;
        $(this).closest('.jscfr-frontend-row').remove();

        // If only one row left, remove its remove button
        var $remaining = $group.find('.jscfr-frontend-row');
        if ($remaining.length === 1) {
            $remaining.find('.jscfr-frontend-remove-row').remove();
        }
    });

    /* ================================================================ */
    /*  Key-Value pairs                                                  */
    /* ================================================================ */
    $(document).on('click', '.jscfr-frontend-kv-add', function() {
        var $wrap = $(this).closest('.jscfr-frontend-key-value');
        var $rows = $wrap.find('.jscfr-frontend-kv-row');
        var $last = $rows.last();
        var $clone = $last.clone();
        var newIdx = $rows.length;

        $clone.find('input').val('');
        $clone.find('[name]').each(function() {
            var n = $(this).attr('name');
            n = n.replace(/\[\d+\]/, '[' + newIdx + ']');
            $(this).attr('name', n);
        });

        $last.after($clone);
    });

    $(document).on('click', '.jscfr-frontend-kv-remove', function() {
        var $wrap = $(this).closest('.jscfr-frontend-key-value');
        if ($wrap.find('.jscfr-frontend-kv-row').length <= 1) return;
        $(this).closest('.jscfr-frontend-kv-row').remove();
    });

    /* ================================================================ */
    /*  AJAX form submission                                             */
    /* ================================================================ */
    $(document).on('submit', '.jscfr-frontend-form', function(e) {
        e.preventDefault();

        var $form    = $(this);
        var $btn     = $form.find('.jscfr-frontend-submit-btn');
        var $spinner = $form.find('.jscfr-frontend-spinner');
        var $msg     = $form.find('.jscfr-frontend-message');

        // Disable button
        $btn.prop('disabled', true).text(jscfr_front.i18n.submitting);
        $spinner.show();
        $msg.hide().removeClass('jscfr-frontend-success jscfr-frontend-error');

        $.ajax({
            url:  jscfr_front.ajax_url,
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(response) {
                $spinner.hide();
                $btn.prop('disabled', false).text($btn.data('original-text') || $btn.text());

                if (response.success) {
                    $msg.addClass('jscfr-frontend-success').text(response.data.message).show();

                    // Update post_id hidden field if it was a new post
                    if (response.data.post_id) {
                        $form.find('input[name="jscfr_post_id"]').val(response.data.post_id);
                    }

                    // Redirect if specified
                    if (response.data.redirect) {
                        window.location.href = response.data.redirect;
                    }
                } else {
                    $msg.addClass('jscfr-frontend-error').text(response.data.message || jscfr_front.i18n.error).show();
                }
            },
            error: function() {
                $spinner.hide();
                $btn.prop('disabled', false);
                $msg.addClass('jscfr-frontend-error').text(jscfr_front.i18n.error).show();
            }
        });
    });

    // Store original button text
    $(document).on('focus', '.jscfr-frontend-form', function() {
        var $btn = $(this).find('.jscfr-frontend-submit-btn');
        if (!$btn.data('original-text')) {
            $btn.data('original-text', $btn.text());
        }
    });

    /* ================================================================ */
    /*  Init on ready                                                    */
    /* ================================================================ */
    $(function() {
        // Initialize Select2
        if ($.fn.select2) {
            $('.jscfr-frontend-form .jscfr-select2').select2({ width: '100%' });
        }
    });

})(jQuery);

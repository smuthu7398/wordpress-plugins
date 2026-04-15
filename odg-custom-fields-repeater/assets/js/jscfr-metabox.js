/**
 * JSCFR Metabox — Post editor JS (v4)
 * Handles: tab switching, clone add/remove/sort, all field type interactions,
 * conditional logic runtime, image/gallery/post object/relationship/taxonomy/user.
 */
(function ($) {
    'use strict';

    var L = jscfr_meta;
    var searchTimers = {};

    /* ================================================================ */
    /*  Renumber clone rows                                             */
    /* ================================================================ */
    function renumber($block) {
        $block.find('.jscfr-clones .jscfr-clone-row').each(function (i) {
            var $row = $(this);
            $row.attr('data-index', i);
            $row.find('.jscfr-clone-num').text('#' + (i + 1));

            $row.find('[name]').each(function () {
                var n = $(this).attr('name');
                if (!n) return;
                n = n.replace(
                    /^(jscfr_data\[[^\]]+\]\[[^\]]+\]\[[^\]]+\])\[[^\]]+\]/,
                    '$1[' + i + ']'
                );
                $(this).attr('name', n);
            });

            $row.find('[id^="jscfr_"]').each(function () {
                var old = $(this).attr('id');
                if (!old) return;
                var parts = old.split('_');
                if (parts.length >= 6) {
                    parts[4] = i;
                    $(this).attr('id', parts.join('_'));
                }
            });
        });

        var count = $block.find('.jscfr-clones .jscfr-clone-row').length;
        $block.find('.jscfr-group-badge').text(count + ' entries');
    }

    function initSortable() {
        $('.jscfr-clones').sortable({
            handle: '.jscfr-drag',
            items: '> .jscfr-clone-row',
            placeholder: 'jscfr-clone-placeholder',
            tolerance: 'pointer',
            cursor: 'grabbing',
            opacity: 0.85,
            update: function () {
                renumber($(this).closest('.jscfr-group-block'));
            }
        });

        // Gallery sortable
        $('.jscfr-gallery-thumbs').sortable({
            items: '> .jscfr-gallery-item',
            tolerance: 'pointer',
            cursor: 'grabbing',
            update: function () {
                updateGalleryIds($(this).closest('.jscfr-gallery-wrap'));
            }
        });

        // Relationship chips sortable
        $('.jscfr-rel-chips').sortable({
            items: '> .jscfr-rel-chip',
            tolerance: 'pointer',
            cursor: 'grabbing',
            update: function () {
                updateRelValue($(this).closest('.jscfr-relationship-wrap'));
            }
        });
    }

    function initColorPickers($scope) {
        $scope.find('.jscfr-color-picker').not('.wp-color-picker').filter(function () {
            return !$(this).closest('.jscfr-clone-template').length;
        }).each(function () {
            $(this).wpColorPicker();
        });
    }

    /* ================================================================ */
    /*  Conditional Logic Runtime                                       */
    /* ================================================================ */
    function evaluateConditionalLogic() {
        if (!L.cond_map || $.isEmptyObject(L.cond_map)) return;

        $.each(L.cond_map, function (fieldId, orGroups) {
            $('[data-jscfr-cond="' + fieldId + '"]').each(function () {
                var $fld = $(this);
                var $row = $fld.closest('.jscfr-clone-row');
                var visible = false;

                $.each(orGroups, function (_, andGroup) {
                    var allMatch = true;
                    $.each(andGroup, function (_, rule) {
                        var val = getFieldValueInRow($row, rule.field);
                        if (!matchesRule(val, rule.operator, rule.value)) {
                            allMatch = false;
                            return false;
                        }
                    });
                    if (allMatch) { visible = true; return false; }
                });

                $fld[visible ? 'show' : 'hide']();
            });
        });
    }

    function getFieldValueInRow($row, fieldId) {
        var $el = $row.find('[data-field-id="' + fieldId + '"]');
        if (!$el.length) return '';
        var $input = $el.find('input, select, textarea').first();
        if ($input.is(':checkbox')) return $input.is(':checked') ? '1' : '0';
        if ($input.is(':radio')) return $el.find('input:checked').val() || '';
        return $input.val() || '';
    }

    function matchesRule(val, op, ruleVal) {
        switch (op) {
            case '==': return val == ruleVal;
            case '!=': return val != ruleVal;
            case '==empty': return !val || val === '' || val === '0';
            case '!=empty': return val && val !== '' && val !== '0';
            case '==contains': return val.indexOf(ruleVal) !== -1;
            case '!=contains': return val.indexOf(ruleVal) === -1;
            default: return true;
        }
    }

    /* ================================================================ */
    /*  Gallery helpers                                                 */
    /* ================================================================ */
    function updateGalleryIds($wrap) {
        var ids = [];
        $wrap.find('.jscfr-gallery-item').each(function () {
            ids.push($(this).data('id'));
        });
        $wrap.find('.jscfr-gallery-ids').val(ids.join(','));
    }

    /* ================================================================ */
    /*  Relationship helpers                                            */
    /* ================================================================ */
    function updateRelValue($wrap) {
        var ids = [];
        $wrap.find('.jscfr-rel-chips .jscfr-rel-chip').each(function () {
            ids.push($(this).data('id'));
        });
        $wrap.find('.jscfr-rel-value').val(ids.join(','));
    }

    /* ================================================================ */
    /*  Post Object helpers                                             */
    /* ================================================================ */
    function updatePoValue($wrap) {
        var ids = [];
        $wrap.find('.jscfr-po-selected .jscfr-po-tag').each(function () {
            ids.push($(this).data('id'));
        });
        var multiple = $wrap.data('multiple') === 1 || $wrap.data('multiple') === '1';
        if (multiple) {
            $wrap.find('.jscfr-po-value').val(ids.join(','));
        } else {
            $wrap.find('.jscfr-po-value').val(ids.length ? ids[0] : '');
        }
    }

    /* ================================================================ */
    /*  DOM ready                                                       */
    /* ================================================================ */
    $(function () {

        initSortable();
        initColorPickers($('.jscfr-meta-wrap'));

        $('.jscfr-group-block').each(function () {
            renumber($(this));
        });

        // Initial conditional logic evaluation
        evaluateConditionalLogic();


        /* Tab switching */
        function jscfrActivateTab($wrap, tabId) {
            if (!tabId) return false;
            var $btn = $wrap.find('.jscfr-tab-nav > .jscfr-tab-btn[data-tab="' + tabId + '"]');
            if (!$btn.length) return false;
            $btn.siblings().removeClass('jscfr-tab-active');
            $btn.addClass('jscfr-tab-active');
            $wrap.find('> .jscfr-tabs-wrap > .jscfr-tabs-panels > .jscfr-tab-content, .jscfr-tab-content').filter(function () {
                return $(this).closest('.jscfr-meta-wrap').is($wrap);
            }).hide();
            $wrap.find('#jscfr-tab-' + $wrap.data('fg') + '-' + tabId).show();
            return true;
        }

        function jscfrTabStorageKey($wrap) {
            var fg = $wrap.data('fg');
            return fg ? 'jscfr_tab_' + fg : '';
        }

        $(document).on('click.jscfr', '.jscfr-tab-btn', function () {
            var tabId = $(this).data('tab');
            var $wrap = $(this).closest('.jscfr-meta-wrap');
            jscfrActivateTab($wrap, tabId);
            if ($wrap.data('jscfr-tab-remember')) {
                try {
                    var key = jscfrTabStorageKey($wrap);
                    if (key && window.localStorage) localStorage.setItem(key, tabId);
                } catch (e) {}
            }
        });

        /* Restore remembered tab / apply default tab index on init */
        $('.jscfr-meta-wrap').each(function () {
            var $wrap = $(this);
            var restored = false;
            if ($wrap.data('jscfr-tab-remember')) {
                try {
                    var key = jscfrTabStorageKey($wrap);
                    var saved = key && window.localStorage ? localStorage.getItem(key) : null;
                    if (saved) restored = jscfrActivateTab($wrap, saved);
                } catch (e) {}
            }
            if (!restored) {
                var defIdx = parseInt($wrap.data('jscfr-tab-default'), 10);
                if (defIdx > 0) {
                    var $btns = $wrap.find('> .jscfr-tabs-wrap > .jscfr-tab-nav > .jscfr-tab-btn');
                    if (!$btns.length) $btns = $wrap.find('.jscfr-tab-nav > .jscfr-tab-btn').filter(function () {
                        return $(this).closest('.jscfr-meta-wrap').is($wrap);
                    });
                    var $target = $btns.eq(defIdx - 1);
                    if ($target.length) jscfrActivateTab($wrap, $target.data('tab'));
                }
            }
        });

        /* ============================================================ */
        /*  Autosave drafts to localStorage (autosave setting)          */
        /* ============================================================ */
        function jscfrAutosaveKey($wrap) {
            var fg = $wrap.data('fg');
            if (!fg) return '';
            var ctx = '';
            var params = new URLSearchParams(window.location.search);
            ctx = params.get('post') || params.get('tag_ID') || params.get('user_id') || params.get('c') || params.get('page') || 'global';
            return 'jscfr_draft_' + fg + '_' + ctx;
        }

        function jscfrCollectDraft($wrap) {
            var data = {};
            $wrap.find(':input[name^="jscfr_data"]').not('[disabled]').each(function () {
                var $el = $(this);
                var name = $el.attr('name');
                if (!name || name.indexOf('__IDX__') !== -1) return;
                if ($el.is(':checkbox') || $el.is(':radio')) {
                    if (!$el.is(':checked')) return;
                }
                data[name] = $el.is(':checkbox,:radio') ? $el.val() : $el.val();
            });
            return data;
        }

        function jscfrSaveDraft($wrap) {
            try {
                var key = jscfrAutosaveKey($wrap);
                if (!key || !window.localStorage) return;
                var payload = { t: Date.now(), d: jscfrCollectDraft($wrap) };
                localStorage.setItem(key, JSON.stringify(payload));
                var $status = $wrap.find('> .jscfr-autosave-status');
                if (!$status.length) {
                    $status = $('<span class="jscfr-autosave-status"></span>').prependTo($wrap);
                }
                var d = new Date(payload.t);
                var hh = String(d.getHours()).padStart(2, '0');
                var mm = String(d.getMinutes()).padStart(2, '0');
                var ss = String(d.getSeconds()).padStart(2, '0');
                $status.text('Draft saved ' + hh + ':' + mm + ':' + ss);
            } catch (e) {}
        }

        function jscfrClearDraft($wrap) {
            try {
                var key = jscfrAutosaveKey($wrap);
                if (key && window.localStorage) localStorage.removeItem(key);
            } catch (e) {}
        }

        var autosaveTimer = null;
        $(document).on('input.jscfr change.jscfr', '.jscfr-meta-wrap.jscfr-autosave-on :input', function () {
            var $wrap = $(this).closest('.jscfr-meta-wrap.jscfr-autosave-on');
            if (!$wrap.length) return;
            if (autosaveTimer) clearTimeout(autosaveTimer);
            autosaveTimer = setTimeout(function () {
                jscfrSaveDraft($wrap);
            }, 1500);
        });

        $('.jscfr-meta-wrap.jscfr-autosave-on').each(function () {
            var $wrap = $(this);
            try {
                var key = jscfrAutosaveKey($wrap);
                if (!key || !window.localStorage) return;
                var raw = localStorage.getItem(key);
                if (!raw) return;
                var saved = JSON.parse(raw);
                if (!saved || !saved.d || !saved.t) return;
                // Show restore banner (user opt-in; never silently overwrite)
                var $banner = $(
                    '<div class="jscfr-autosave-banner notice notice-info" style="margin:8px 0;padding:8px 12px;">' +
                    '<span class="dashicons dashicons-backup" style="vertical-align:middle;"></span> ' +
                    'An unsaved draft from ' + new Date(saved.t).toLocaleString() + ' is available. ' +
                    '<button type="button" class="button button-small jscfr-autosave-restore" style="margin:0 4px;">Restore</button>' +
                    '<button type="button" class="button-link jscfr-autosave-discard">Discard</button>' +
                    '</div>'
                );
                $banner.data('draft', saved.d);
                $wrap.prepend($banner);
            } catch (e) {}
        });

        $(document).on('click.jscfr', '.jscfr-autosave-restore', function () {
            var $banner = $(this).closest('.jscfr-autosave-banner');
            var $wrap   = $banner.closest('.jscfr-meta-wrap');
            var d       = $banner.data('draft');
            if (!d) return;
            $.each(d, function (name, val) {
                var $el = $wrap.find(':input[name="' + name + '"]');
                if ($el.is(':checkbox,:radio')) {
                    $el.filter('[value="' + val + '"]').prop('checked', true);
                } else {
                    $el.val(val).trigger('change');
                }
            });
            $banner.remove();
        });

        $(document).on('click.jscfr', '.jscfr-autosave-discard', function () {
            var $banner = $(this).closest('.jscfr-autosave-banner');
            var $wrap   = $banner.closest('.jscfr-meta-wrap');
            jscfrClearDraft($wrap);
            $banner.remove();
        });

        // Clear drafts on successful form submit
        $(document).on('submit.jscfr', 'form', function () {
            $(this).find('.jscfr-meta-wrap.jscfr-autosave-on').each(function () {
                jscfrClearDraft($(this));
            });
        });

        /* Add Clone */
        $(document).on('click.jscfr', '.jscfr-add-clone', function (e) {
            e.preventDefault();
            var $block = $(this).closest('.jscfr-group-block');
            var maxRows = parseInt($block.data('max'), 10) || 0;
            var currentRows = $block.find('.jscfr-clones .jscfr-clone-row').length;

            if (maxRows > 0 && currentRows >= maxRows) {
                alert(L.max_entries_msg || 'Maximum entries reached.');
                return;
            }

            var fgId  = $(this).data('fg');
            var tabId = $(this).data('tab');
            var grpId = $(this).data('group');
            var $clones = $block.find('.jscfr-clones');
            var newIdx  = currentRows;

            var $tpl = $('#jscfr-clonetpl-' + fgId + '-' + tabId + '-' + grpId);
            var html = $tpl.html().replace(/__IDX__/g, newIdx);
            var $newRow = $(html);

            // Clear values
            $newRow.find('input[type="text"], input[type="url"], input[type="email"], input[type="number"], input[type="date"], input[type="datetime-local"], input[type="time"], input[type="password"], textarea').val('');
            $newRow.find('input[type="hidden"].jscfr-file-id, input[type="hidden"].jscfr-image-id').val('');
            $newRow.find('input[type="hidden"].jscfr-gallery-ids').val('');
            $newRow.find('input[type="hidden"].jscfr-po-value, input[type="hidden"].jscfr-rel-value, input[type="hidden"].jscfr-user-value').val('');
            $newRow.find('input[type="checkbox"]').prop('checked', false);
            $newRow.find('select').not('[multiple]').prop('selectedIndex', 0);
            $newRow.find('select[multiple]').find('option').prop('selected', false);
            $newRow.find('.jscfr-multi-picker option').prop('disabled', false);
            $newRow.find('.jscfr-multi-tags').empty();
            $newRow.find('.jscfr-file-name').html('<em>' + L.no_file + '</em>');
            $newRow.find('.jscfr-file-clear, .jscfr-image-clear').hide();
            $newRow.find('.jscfr-file-preview').remove();
            $newRow.find('.jscfr-image-preview').html('<span class="jscfr-image-placeholder"><span class="dashicons dashicons-format-image"></span></span>');
            $newRow.find('.jscfr-gallery-thumbs').empty();
            $newRow.find('.jscfr-po-selected').empty();
            $newRow.find('.jscfr-rel-chips').empty();
            $newRow.find('.jscfr-rel-dropdown').hide().empty();
            $newRow.find('.jscfr-rel-search').val('');
            $newRow.find('.jscfr-user-selected').empty();
            $newRow.find('.jscfr-oembed-preview').empty();

            // Clear button group active state
            $newRow.find('.jscfr-bg-btn').removeClass('jscfr-bg-active');
            $newRow.find('.jscfr-bg-value').val('');

            // Range reset
            $newRow.find('.jscfr-range-input').each(function() {
                var min = $(this).attr('min') || 0;
                $(this).val(min);
                $(this).siblings('.jscfr-range-value').text(min);
            });

            $clones.append($newRow);
            renumber($block);
            initColorPickers($newRow);
            initSortable();
            evaluateConditionalLogic();
            $(document).trigger('jscfr:clone_added', [$newRow]);
            $('html, body').animate({ scrollTop: $newRow.offset().top - 60 }, 300);
        });

        /* Remove Clone */
        $(document).on('click.jscfr', '.jscfr-clone-remove', function (e) {
            e.preventDefault();
            if (!confirm(L.confirm_remove)) return;
            var $block = $(this).closest('.jscfr-group-block');
            $(this).closest('.jscfr-clone-row').fadeOut(200, function () {
                $(this).remove();
                renumber($block);
            });
        });

        /* Toggle clone */
        $(document).on('click.jscfr', '.jscfr-clone-toggle', function (e) {
            e.preventDefault();
            $(this).closest('.jscfr-clone-row').toggleClass('jscfr-collapsed');
        });

        /* ---- Range slider ---- */
        $(document).on('input.jscfr', '.jscfr-range-input', function () {
            $(this).siblings('.jscfr-range-value').text($(this).val());
        });

        /* ---- Button Group ---- */
        $(document).on('click.jscfr', '.jscfr-bg-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $wrap = $btn.closest('.jscfr-button-group');
            $wrap.find('.jscfr-bg-btn').removeClass('jscfr-bg-active');
            $btn.addClass('jscfr-bg-active');
            $wrap.find('.jscfr-bg-value').val($btn.data('value'));
            $btn.blur(); // Release WP button focus so active state shows immediately
            evaluateConditionalLogic();
        });

        /* ---- True/False toggle ---- */
        $(document).on('change.jscfr', '.jscfr-toggle input[type="checkbox"]', function () {
            evaluateConditionalLogic();
        });

        /* ---- Image Select ---- */
        $(document).on('click.jscfr', '.jscfr-image-select', function (e) {
            e.preventDefault();
            var $btn   = $(this);
            var $wrap  = $btn.closest('.jscfr-image-wrap');
            var $input = $wrap.find('.jscfr-image-id');
            var mime   = $btn.data('mime') || 'image';
            var uploader = wp.media({
                title: L.select_image || L.select_file,
                button: { text: L.use_image || L.use_file },
                multiple: false,
                library: { type: mime }
            });
            uploader.on('select', function () {
                var att = uploader.state().get('selection').first().toJSON();
                $input.val(att.id);
                var previewUrl = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                $wrap.find('.jscfr-image-preview').html('<img src="' + previewUrl + '" alt="" />');
                $wrap.find('.jscfr-image-clear').show();
            });
            uploader.open();
        });

        /* ---- Image Clear ---- */
        $(document).on('click.jscfr', '.jscfr-image-clear', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.jscfr-image-wrap');
            $wrap.find('.jscfr-image-id').val('');
            $wrap.find('.jscfr-image-preview').html('<span class="jscfr-image-placeholder"><span class="dashicons dashicons-format-image"></span></span>');
            $(this).hide();
        });

        /* ---- File Upload ---- */
        $(document).on('click.jscfr', '.jscfr-upload', function (e) {
            e.preventDefault();
            var $btn   = $(this);
            var $wrap  = $btn.closest('.jscfr-file-wrap, .jscfr-video-wrap');
            var isVideo = $wrap.hasClass('jscfr-video-wrap');
            var $input = $wrap.find('.jscfr-file-id');
            var $name  = $wrap.find('.jscfr-file-name');
            var $clear = $wrap.find('.jscfr-file-clear');
            var mime   = $btn.data('mime') || '';
            var args   = { title: L.select_file, button: { text: L.use_file }, multiple: false };
            if (mime) args.library = { type: mime };
            var uploader = wp.media(args);
            uploader.on('select', function () {
                var att = uploader.state().get('selection').first().toJSON();
                $input.val(att.id);
                $name.text(att.filename);
                $clear.show();
                if (isVideo) {
                    $wrap.addClass('has-video');
                    var $preview = $wrap.find('.jscfr-video-preview');
                    $preview.empty().append($('<video>', { src: att.url, controls: true, preload: 'metadata' }));
                    $btn.contents().filter(function () { return this.nodeType === 3; }).last().replaceWith(' Replace Video');
                } else {
                    $wrap.find('.jscfr-file-preview').remove();
                    $wrap.append('<a href="' + att.url + '" target="_blank" class="button jscfr-file-preview"><span class="dashicons dashicons-visibility" style="vertical-align:middle;"></span></a>');
                }
            });
            uploader.open();
        });

        /* ---- File Clear ---- */
        $(document).on('click.jscfr', '.jscfr-file-clear', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.jscfr-file-wrap, .jscfr-video-wrap');
            var isVideo = $wrap.hasClass('jscfr-video-wrap');
            $wrap.find('.jscfr-file-id').val('');
            if (isVideo) {
                $wrap.removeClass('has-video');
                $wrap.find('.jscfr-file-name').text('');
                $wrap.find('.jscfr-video-preview').html('<div class="jscfr-video-empty"><span class="dashicons dashicons-video-alt3"></span><span class="jscfr-video-empty-text">' + (L.no_video || 'No video selected') + '</span></div>');
                var $btn = $wrap.find('.jscfr-upload');
                $btn.contents().filter(function () { return this.nodeType === 3; }).last().replaceWith(' Select Video');
            } else {
                $wrap.find('.jscfr-file-name').html('<em>' + L.no_file + '</em>');
                $wrap.find('.jscfr-file-preview').remove();
            }
            $(this).hide();
        });

        /* ---- Gallery Add ---- */
        $(document).on('click.jscfr', '.jscfr-gallery-add', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.jscfr-gallery-wrap');
            var uploader = wp.media({
                title: L.add_to_gallery || 'Add Images',
                button: { text: L.use_image || 'Add' },
                multiple: true,
                library: { type: 'image' }
            });
            uploader.on('select', function () {
                var selection = uploader.state().get('selection');
                selection.each(function (att) {
                    var json = att.toJSON();
                    var thumb = json.sizes && json.sizes.thumbnail ? json.sizes.thumbnail.url : json.url;
                    $wrap.find('.jscfr-gallery-thumbs').append(
                        '<div class="jscfr-gallery-item" data-id="' + json.id + '"><img src="' + thumb + '" /><button type="button" class="jscfr-gallery-remove"><span class="dashicons dashicons-no-alt"></span></button></div>'
                    );
                });
                updateGalleryIds($wrap);
            });
            uploader.open();
        });

        /* ---- Gallery Remove ---- */
        $(document).on('click.jscfr', '.jscfr-gallery-remove', function (e) {
            e.preventDefault();
            var $wrap = $(this).closest('.jscfr-gallery-wrap');
            $(this).closest('.jscfr-gallery-item').remove();
            updateGalleryIds($wrap);
        });

        /* ---- Post Object Search ---- */
        $(document).on('input.jscfr', '.jscfr-po-search', function () {
            var $wrap = $(this).closest('.jscfr-post-object-wrap');
            var search = $(this).val();
            var postTypes = $wrap.data('post-types') || ['post','page'];
            clearTimeout(searchTimers['po']);
            searchTimers['po'] = setTimeout(function () {
                if (search.length < 2) { $wrap.find('.jscfr-po-results').empty(); return; }
                $.post(L.ajax_url, {
                    action: 'jscfr_search_posts',
                    nonce: L.nonce,
                    search: search,
                    post_type: postTypes
                }, function (res) {
                    var $results = $wrap.find('.jscfr-po-results').empty();
                    if (res.success && res.data.length) {
                        $.each(res.data, function (_, p) {
                            $results.append('<div class="jscfr-po-result" data-id="' + p.id + '">' + p.title + ' <small>(' + p.type + ' #' + p.id + ')</small></div>');
                        });
                    } else {
                        $results.append('<div class="jscfr-po-no-result">' + L.no_results + '</div>');
                    }
                });
            }, 300);
        });

        $(document).on('click.jscfr', '.jscfr-po-result', function () {
            var $wrap = $(this).closest('.jscfr-post-object-wrap');
            var id = $(this).data('id');
            var title = $(this).text();
            var multiple = $wrap.data('multiple') === 1 || $wrap.data('multiple') === '1';

            if (!multiple) $wrap.find('.jscfr-po-selected').empty();

            $wrap.find('.jscfr-po-selected').append('<span class="jscfr-po-tag" data-id="' + id + '">' + title + ' <button type="button" class="jscfr-po-remove">&times;</button></span>');
            updatePoValue($wrap);
            $wrap.find('.jscfr-po-results').empty();
            $wrap.find('.jscfr-po-search').val('');
        });

        $(document).on('click.jscfr', '.jscfr-po-remove', function () {
            var $wrap = $(this).closest('.jscfr-post-object-wrap');
            $(this).closest('.jscfr-po-tag').remove();
            updatePoValue($wrap);
        });

        /* ---- Relationship Search (chip picker) ---- */
        function loadRelDropdown($wrap, search) {
            var postTypes = $wrap.data('post-types') || ['post','page'];
            var exclude = [];
            $wrap.find('.jscfr-rel-chips .jscfr-rel-chip').each(function () {
                exclude.push($(this).data('id'));
            });
            var $dropdown = $wrap.find('.jscfr-rel-dropdown').show();
            $dropdown.html('<div class="jscfr-rel-empty">Loading...</div>');
            $.post(L.ajax_url, {
                action: 'jscfr_search_posts',
                nonce: L.nonce,
                search: search || '',
                post_type: postTypes,
                exclude: exclude
            }).done(function (res) {
                $dropdown.empty();
                if (res && res.success && res.data && res.data.length) {
                    $.each(res.data, function (_, p) {
                        $dropdown.append('<div class="jscfr-rel-option" data-id="' + p.id + '" data-title="' + $('<span>').text(p.title).html() + '">' + $('<span>').text(p.title).html() + ' <small>(' + $('<span>').text(p.type).html() + ')</small></div>');
                    });
                } else {
                    $dropdown.append('<div class="jscfr-rel-empty">No matching items</div>');
                }
            }).fail(function () {
                $dropdown.empty().append('<div class="jscfr-rel-empty">Unable to load items</div>');
            });
        }

        $(document).on('focus.jscfr', '.jscfr-rel-search', function () {
            var $wrap = $(this).closest('.jscfr-relationship-wrap');
            loadRelDropdown($wrap, $(this).val());
        });

        $(document).on('input.jscfr', '.jscfr-rel-search', function () {
            var $wrap = $(this).closest('.jscfr-relationship-wrap');
            var search = $(this).val();
            clearTimeout(searchTimers['rel']);
            searchTimers['rel'] = setTimeout(function () {
                loadRelDropdown($wrap, search);
            }, 250);
        });

        $(document).on('click.jscfr', '.jscfr-rel-option', function () {
            var $wrap = $(this).closest('.jscfr-relationship-wrap');
            var id = $(this).data('id');
            var title = $(this).attr('data-title') || $(this).clone().children().remove().end().text().trim();
            var maxC = parseInt($wrap.data('max'), 10) || 0;
            var current = $wrap.find('.jscfr-rel-chips .jscfr-rel-chip').length;

            if (maxC > 0 && current >= maxC) return;

            var chip = '<span class="jscfr-rel-chip" data-id="' + id + '">' + $('<span>').text(title).html() + '<button type="button" class="jscfr-rel-chip-remove" aria-label="Remove">&times;</button></span>';
            $wrap.find('.jscfr-rel-chips').append(chip);
            $wrap.find('.jscfr-rel-search').val('').trigger('focus');
            updateRelValue($wrap);
            loadRelDropdown($wrap, '');
        });

        $(document).on('click.jscfr', '.jscfr-rel-chip-remove', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $wrap = $(this).closest('.jscfr-relationship-wrap');
            $(this).closest('.jscfr-rel-chip').remove();
            updateRelValue($wrap);
        });

        // Close dropdown on outside click
        $(document).on('click.jscfr', function (e) {
            if (!$(e.target).closest('.jscfr-relationship-wrap').length) {
                $('.jscfr-rel-dropdown').hide();
            }
        });

        // Keyboard: Esc closes dropdown
        $(document).on('keydown.jscfr', '.jscfr-rel-search', function (e) {
            if (e.key === 'Escape') {
                $(this).closest('.jscfr-relationship-wrap').find('.jscfr-rel-dropdown').hide();
            }
        });

        /* ---- User Search ---- */
        $(document).on('input.jscfr', '.jscfr-user-search', function () {
            var $wrap = $(this).closest('.jscfr-user-wrap');
            var search = $(this).val();
            var roles = $wrap.data('roles') || [];
            clearTimeout(searchTimers['user']);
            searchTimers['user'] = setTimeout(function () {
                if (search.length < 2) { $wrap.find('.jscfr-user-results').empty(); return; }
                $.post(L.ajax_url, {
                    action: 'jscfr_search_users',
                    nonce: L.nonce,
                    search: search,
                    role: roles
                }, function (res) {
                    var $results = $wrap.find('.jscfr-user-results').empty();
                    if (res.success && res.data.length) {
                        $.each(res.data, function (_, u) {
                            $results.append('<div class="jscfr-user-result" data-id="' + u.id + '">' + u.name + ' (' + u.email + ')</div>');
                        });
                    }
                });
            }, 300);
        });

        $(document).on('click.jscfr', '.jscfr-user-result', function () {
            var $wrap = $(this).closest('.jscfr-user-wrap');
            var id = $(this).data('id');
            var name = $(this).text();
            var multiple = $wrap.data('multiple') === 1 || $wrap.data('multiple') === '1';

            if (!multiple) $wrap.find('.jscfr-user-selected').empty();

            $wrap.find('.jscfr-user-selected').append('<span class="jscfr-po-tag" data-id="' + id + '">' + name + ' <button type="button" class="jscfr-user-remove">&times;</button></span>');

            var ids = [];
            $wrap.find('.jscfr-po-tag').each(function () { ids.push($(this).data('id')); });
            $wrap.find('.jscfr-user-value').val(multiple ? ids.join(',') : (ids[0] || ''));
            $wrap.find('.jscfr-user-results').empty();
            $wrap.find('.jscfr-user-search').val('');
        });

        $(document).on('click.jscfr', '.jscfr-user-remove', function () {
            var $wrap = $(this).closest('.jscfr-user-wrap');
            $(this).closest('.jscfr-po-tag').remove();
            var ids = [];
            $wrap.find('.jscfr-po-tag').each(function () { ids.push($(this).data('id')); });
            var multiple = $wrap.data('multiple') === 1 || $wrap.data('multiple') === '1';
            $wrap.find('.jscfr-user-value').val(multiple ? ids.join(',') : (ids[0] || ''));
        });

        /* ---- Re-evaluate conditional logic on input changes ---- */
        $(document).on('change.jscfr input.jscfr', '.jscfr-clone-fields input, .jscfr-clone-fields select, .jscfr-clone-fields textarea', function () {
            evaluateConditionalLogic();
        });

        /* ================================================================ */
        /*  v5: Text Limiter — show counter below input                     */
        /* ================================================================ */
        function initTextLimiters() {
            $('[data-jscfr-limit]').filter(function () {
                return !$(this).closest('.jscfr-clone-template').length;
            }).each(function () {
                var $fld = $(this);
                if ($fld.find('.jscfr-limit-counter').length) return; // already init
                var limit = parseInt($fld.data('jscfr-limit'), 10);
                var type = $fld.data('jscfr-limit-type') || 'characters';
                var $input = $fld.find('input[type="text"], input[type="email"], input[type="url"], input[type="password"], textarea').first();
                if (!$input.length || !limit) return;

                var $counter = $('<div class="jscfr-limit-counter"></div>');
                $input.after($counter);

                function update() {
                    var val = $input.val() || '';
                    var count = type === 'words' ? (val.trim() ? val.trim().split(/\s+/).length : 0) : val.length;
                    $counter.text(count + ' / ' + limit + ' ' + type);
                    $counter.toggleClass('jscfr-limit-exceeded', count > limit);
                }
                $input.on('input.jscfr', update);
                update();
            });
        }
        initTextLimiters();
        $(document).on('jscfr:clone_added', function () { initTextLimiters(); });

        /* ================================================================ */
        /*  v5: Image Select (single mode) — click to select               */
        /* ================================================================ */
        $(document).on('click.jscfr', '.jscfr-image-select:not(.jscfr-is-multi) .jscfr-is-item', function () {
            var $item = $(this), $wrap = $item.closest('.jscfr-image-select');
            $wrap.find('.jscfr-is-item').removeClass('jscfr-is-active');
            $item.addClass('jscfr-is-active');
            $wrap.find('.jscfr-is-value').val($item.data('value')).trigger('change');
        });

        /* Image Select (multi mode) — toggle active class on checkbox */
        $(document).on('change.jscfr', '.jscfr-is-multi .jscfr-is-input', function () {
            $(this).closest('.jscfr-is-item').toggleClass('jscfr-is-active', this.checked);
        });

        /* ================================================================ */
        /*  v5: Key Value — add / remove pairs                              */
        /* ================================================================ */
        $(document).on('click.jscfr', '.jscfr-kv-add', function () {
            var $wrap = $(this).closest('.jscfr-kv-wrap');
            var $list = $wrap.find('.jscfr-kv-list');
            var $rows = $list.find('.jscfr-kv-row');
            var idx = $rows.length;
            var baseName = $wrap.attr('data-name') || '';
            if (!baseName && $rows.length) {
                baseName = $rows.first().find('.jscfr-kv-key').attr('name').replace(/\[\d+\]\[key\]$/, '');
            }
            if (!baseName) return;
            var row = '<div class="jscfr-kv-row">' +
                '<input type="text" name="' + baseName + '[' + idx + '][key]" value="" placeholder="Key" class="jscfr-kv-key" />' +
                '<input type="text" name="' + baseName + '[' + idx + '][value]" value="" placeholder="Value" class="jscfr-kv-val" />' +
                '<button type="button" class="button jscfr-kv-remove"><span class="dashicons dashicons-no-alt"></span></button>' +
                '</div>';
            $list.append(row);
        });

        $(document).on('click.jscfr', '.jscfr-kv-remove', function () {
            $(this).closest('.jscfr-kv-row').remove();
        });

        /* ================================================================ */
        /*  v5: File Input — browse media picker                            */
        /* ================================================================ */
        $(document).on('click.jscfr', '.jscfr-fi-browse', function () {
            var $btn = $(this), $wrap = $btn.closest('.jscfr-file-input-wrap, .jscfr-bg-field');
            var $url = $wrap.find('.jscfr-fi-url');
            var mimeFilter = $btn.data('mime') || '';

            var frame = wp.media({
                title: jscfr_meta.select_file || 'Select File',
                button: { text: jscfr_meta.use_file || 'Use this file' },
                multiple: false,
                library: mimeFilter ? { type: mimeFilter } : {}
            });

            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                $url.val(att.url).trigger('change');
            });

            frame.open();
        });

        /* ================================================================ */
        /*  Multi-select picker (tags below dropdown)                       */
        /* ================================================================ */
        function jscfrMultiFindHidden($from) {
            var target = $from.data('target') || $from.attr('data-target');
            if (target) {
                var $h = $(document.getElementById(target));
                if ($h.length) return $h;
            }
            return $from.closest('.jscfr-fld').find('.jscfr-multi-hidden').first();
        }
        function jscfrMultiFindTags($picker) {
            var target = $picker.data('target') || $picker.attr('data-target');
            if (target) {
                var $t = $('.jscfr-multi-tags[data-target="' + target + '"]');
                if ($t.length) return $t;
            }
            return $picker.closest('.jscfr-fld').find('.jscfr-multi-tags').first();
        }

        function jscfrMultiFindPicker($hidden) {
            var id = $hidden.attr('id');
            if (id) {
                var $p = $('.jscfr-multi-picker[data-target="' + id + '"]');
                if ($p.length) return $p;
            }
            return $hidden.closest('.jscfr-fld').find('.jscfr-multi-picker').first();
        }

        $(document).on('change', '.jscfr-multi-picker', function () {
            var $picker = $(this);
            var val = $picker.val();
            if (!val) return;
            var $hidden = jscfrMultiFindHidden($picker);
            var $tags = jscfrMultiFindTags($picker);
            var label = $picker.find('option:selected').text();

            var chipExists = $tags.find('.jscfr-multi-tag').filter(function () {
                return $(this).attr('data-value') === val;
            }).length > 0;

            if (chipExists) {
                $picker.val('');
                return;
            }

            $hidden.find('option').filter(function () { return this.value === val; }).prop('selected', true);
            $hidden.trigger('change');
            $picker.find('option').filter(function () { return this.value === val; }).prop('disabled', true);
            $tags.append('<span class="jscfr-multi-tag" data-value="' + $('<span>').text(val).html() + '">' + $('<span>').text(label).html() + '<button type="button" class="jscfr-multi-tag-remove">&times;</button></span>');
            $picker.val('');
        });

        $(document).on('click', '.jscfr-multi-tag-remove', function () {
            var $tag = $(this).closest('.jscfr-multi-tag');
            var val = $tag.attr('data-value');
            var $tagsWrap = $tag.closest('.jscfr-multi-tags');
            var target = $tagsWrap.attr('data-target');
            var $hidden;
            if (target) {
                $hidden = $(document.getElementById(target));
            }
            if (!$hidden || !$hidden.length) {
                $hidden = $tag.closest('.jscfr-fld').find('.jscfr-multi-hidden').first();
            }
            $hidden.find('option').filter(function () { return this.value === val; }).prop('selected', false);
            $hidden.trigger('change');
            var $picker = jscfrMultiFindPicker($hidden);
            $picker.find('option').filter(function () { return this.value === val; }).prop('disabled', false);
            $tag.removeAttr('data-value').fadeOut(150, function () { $(this).remove(); });
        });

        /* ================================================================ */
        /*  v5: WYSIWYG (TinyMCE) initialization                            */
        /* ================================================================ */
        function initWysiwyg() {
            if (typeof wp === 'undefined' || typeof wp.editor === 'undefined') return;

            $('.jscfr-wysiwyg').filter(function () {
                return !$(this).closest('.jscfr-clone-template').length;
            }).each(function () {
                var $ta = $(this);
                var id = $ta.attr('id');
                if (!id) return;

                // Skip if already initialized
                if (typeof tinymce !== 'undefined' && tinymce.get(id)) return;

                var toolbar = $ta.data('toolbar') || 'full';
                var media = $ta.data('media') !== 0 && $ta.data('media') !== '0';

                var tinymceSettings = {
                    wpautop: true,
                    plugins: 'charmap colorpicker hr lists paste tabfocus textcolor fullscreen wordpress wpautoresize wpdialogs wpeditimage wpemoji wpgallery wplink wptextpattern',
                    toolbar1: toolbar === 'basic'
                        ? 'bold,italic,underline,bullist,numlist,link,unlink'
                        : 'formatselect,bold,italic,underline,strikethrough,bullist,numlist,blockquote,hr,alignleft,aligncenter,alignright,link,unlink' + (media ? ',wp_adv' : ''),
                    toolbar2: toolbar === 'basic'
                        ? ''
                        : 'forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,fullscreen',
                    body_class: 'jscfr-wysiwyg-body',
                    height: 250,
                    menubar: false,
                    branding: false,
                    relative_urls: false,
                    remove_script_host: false,
                    convert_urls: false,
                    setup: function (editor) {
                        editor.on('change keyup', function () {
                            editor.save();
                        });
                    }
                };

                var quicktagsSettings = { id: id, buttons: 'strong,em,link,block,del,ins,img,ul,ol,li,code,close' };

                wp.editor.initialize(id, {
                    tinymce: tinymceSettings,
                    quicktags: quicktagsSettings,
                    mediaButtons: media
                });
            });
        }

        // Delay slightly so WordPress has finished setting up its own editors
        setTimeout(initWysiwyg, 200);

        $(document).on('jscfr:clone_added', function (e, $newRow) {
            if (!$newRow) return;
            // Give new clone unique IDs then init
            $newRow.find('.jscfr-wysiwyg').each(function () {
                var $ta = $(this);
                var id = $ta.attr('id');
                if (!id) return;
                // Ensure textarea is clean (no leftover editor wrappers from template)
                var $parent = $ta.parent();
                if ($parent.hasClass('wp-editor-wrap')) {
                    $parent.replaceWith($ta);
                }
                $ta.val('').show();
            });
            setTimeout(function () {
                initWysiwyg();
            }, 100);
        });

        // Sync all TinyMCE editors back to textareas before form submit
        $(document).on('submit', 'form#post', function () {
            if (typeof tinymce !== 'undefined') {
                tinymce.triggerSave();
            }
        });

        /* ================================================================ */
        /*  v5: Select2 initialization                                      */
        /* ================================================================ */
        function initSelect2() {
            if (typeof $.fn.select2 === 'undefined') return;
            $('.jscfr-select2').not('.select2-hidden-accessible').filter(function () {
                return !$(this).closest('.jscfr-clone-template').length;
            }).each(function () {
                $(this).select2({
                    allowClear: true,
                    placeholder: $(this).data('placeholder') || '',
                    width: '100%'
                });
            });
        }
        initSelect2();
        // Re-init after clone add
        $(document).on('jscfr:clone_added', function () { initSelect2(); });

        /* ================================================================ */
        /*  v5: jQuery UI Slider initialization                             */
        /* ================================================================ */
        function initSliders() {
            if (typeof $.fn.slider === 'undefined') return;
            $('.jscfr-slider-track').not('.ui-slider').filter(function () {
                return !$(this).closest('.jscfr-clone-template').length;
            }).each(function () {
                var $track = $(this), $wrap = $track.closest('.jscfr-slider-wrap');
                var $hidden = $wrap.find('.jscfr-slider-value');
                var $display = $wrap.find('.jscfr-slider-display');
                $track.slider({
                    min: parseFloat($track.data('min')) || 0,
                    max: parseFloat($track.data('max')) || 100,
                    step: parseFloat($track.data('step')) || 1,
                    value: parseFloat($track.data('value')) || 0,
                    slide: function (e, ui) {
                        $hidden.val(ui.value);
                        $display.text(ui.value);
                    }
                });
            });
        }
        initSliders();
        $(document).on('jscfr:clone_added', function () { initSliders(); });

        /* ================================================================ */
        /*  v5: jQuery UI Autocomplete initialization                       */
        /* ================================================================ */
        function initAutocomplete() {
            if (typeof $.fn.autocomplete === 'undefined') return;
            $('.jscfr-autocomplete-input').not('.ui-autocomplete-input').filter(function () {
                return !$(this).closest('.jscfr-clone-template').length;
            }).each(function () {
                var source = [];
                try { source = JSON.parse($(this).attr('data-source')); } catch (e) {}
                $(this).autocomplete({ source: source, minLength: 1 });
            });
        }
        initAutocomplete();
        $(document).on('jscfr:clone_added', function () { initAutocomplete(); });

        /* ================================================================ */
        /*  v5: Dropzone — click to open media picker                       */
        /* ================================================================ */
        $(document).on('click.jscfr', '.jscfr-dropzone', function () {
            var $dz = $(this), $wrap = $dz.closest('.jscfr-file-upload-wrap, .jscfr-image-upload-wrap');
            var isImage = $wrap.hasClass('jscfr-image-upload-wrap');
            var mimeFilter = $dz.data('mime') || '';

            var frame = wp.media({
                title: isImage ? (jscfr_meta.select_image || 'Select Image') : (jscfr_meta.select_file || 'Select File'),
                button: { text: isImage ? (jscfr_meta.use_image || 'Use this image') : (jscfr_meta.use_file || 'Use this file') },
                multiple: false,
                library: mimeFilter ? { type: mimeFilter } : {}
            });

            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                if (isImage) {
                    $wrap.find('.jscfr-image-id').val(att.id);
                    var imgUrl = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                    $dz.html('<div class="jscfr-image-preview"><img src="' + imgUrl + '" alt="" /></div>');
                    $wrap.find('.jscfr-image-actions').show();
                } else {
                    $wrap.find('.jscfr-file-id').val(att.id);
                    $wrap.find('.jscfr-file-name').text(att.filename || att.title);
                    $wrap.find('.jscfr-file-info').show();
                }
            });

            frame.open();
        });

        /* Dropzone image clear */
        $(document).on('click.jscfr', '.jscfr-image-upload-wrap .jscfr-image-clear', function () {
            var $wrap = $(this).closest('.jscfr-image-upload-wrap');
            $wrap.find('.jscfr-image-id').val('');
            $wrap.find('.jscfr-dropzone').html(
                '<span class="dashicons dashicons-format-image jscfr-dz-icon"></span>' +
                '<span class="jscfr-dz-text">Drop image here or click to upload</span>'
            );
            $wrap.find('.jscfr-image-actions').hide();
        });

        /* Dropzone file clear */
        $(document).on('click.jscfr', '.jscfr-file-upload-wrap .jscfr-file-clear', function () {
            var $wrap = $(this).closest('.jscfr-file-upload-wrap');
            $wrap.find('.jscfr-file-id').val('');
            $wrap.find('.jscfr-file-info').hide();
        });

        /* ================================================================ */
        /*  v5: Taxonomy Advanced — sync checkboxes/selects to CSV hidden   */
        /* ================================================================ */
        $(document).on('change.jscfr', '.jscfr-tax-adv-cb', function () {
            var $wrap = $(this).closest('.jscfr-taxonomy-adv-wrap');
            var ids = [];
            $wrap.find('.jscfr-tax-adv-cb:checked').each(function () { ids.push($(this).val()); });
            $wrap.find('.jscfr-tax-adv-value').val(ids.join(','));
        });

        $(document).on('change.jscfr', '.jscfr-tax-adv-radio', function () {
            var $wrap = $(this).closest('.jscfr-taxonomy-adv-wrap');
            $wrap.find('.jscfr-tax-adv-value').val($(this).val());
        });

        $(document).on('change.jscfr', '.jscfr-tax-adv-sel', function () {
            var $wrap = $(this).closest('.jscfr-taxonomy-adv-wrap');
            var val = $(this).val();
            if (Array.isArray(val)) {
                $wrap.find('.jscfr-tax-adv-value').val(val.join(','));
            } else {
                $wrap.find('.jscfr-tax-adv-value').val(val || '');
            }
        });

        /* ================================================================ */
        /*  v5.1: Icon Picker field                                        */
        /* ================================================================ */
        var jscfrDashicons = [
            'menu','admin-site','dashboard','admin-post','admin-media','admin-links','admin-page','admin-comments',
            'admin-appearance','admin-plugins','admin-users','admin-tools','admin-settings','admin-network','admin-home',
            'admin-generic','admin-collapse','format-aside','format-image','format-gallery','format-video','format-audio',
            'format-chat','format-status','format-quote','format-links','media-archive','media-audio','media-code','media-default',
            'media-document','media-interactive','media-spreadsheet','media-text','media-video',
            'editor-bold','editor-italic','editor-ul','editor-ol','editor-quote','editor-alignleft','editor-aligncenter',
            'editor-alignright','editor-insertmore','editor-spellcheck','editor-expand','editor-contract','editor-table',
            'editor-paste-word','editor-paste-text','editor-removeformatting','editor-video','editor-customchar',
            'editor-outdent','editor-indent','editor-help','editor-strikethrough','editor-unlink','editor-rtl','editor-code',
            'align-left','align-right','align-center','align-none','lock','unlock','calendar','calendar-alt',
            'visibility','hidden','post-status','edit','trash','sticky','external',
            'arrow-up','arrow-down','arrow-right','arrow-left','arrow-up-alt','arrow-down-alt',
            'arrow-right-alt','arrow-left-alt','arrow-up-alt2','arrow-down-alt2','sort','leftright',
            'list-view','grid-view','move','screenshot','hammer','art','migrate','performance',
            'universal-access','tickets','nametag','clipboard','heart','megaphone','schedule',
            'wordpress','wordpress-alt','update','screenoptions','info','cart','feedback',
            'cloud','translation','tag','category','archive','tagcloud','text','yes','no','no-alt',
            'plus','plus-alt','plus-alt2','minus','dismiss','marker','star-filled','star-half','star-empty',
            'flag','warning','location','location-alt','vault','shield','shield-alt','sos',
            'search','slides','analytics','chart-pie','chart-bar','chart-line','chart-area',
            'groups','businessman','businesswoman','id','id-alt','products',
            'awards','forms','testimonial','portfolio','book','book-alt','download','upload',
            'backup','clock','lightbulb','microphone','desktop','laptop','tablet','smartphone',
            'phone','index-card','building','store','album','palmtree','tickets-alt',
            'money','money-alt','thumbs-up','thumbs-down','layout','paperclip','color-picker',
            'email','email-alt','email-alt2','networking','share','share-alt','share-alt2',
            'ellipsis','database','bell','saved','open-folder','pets','privacy',
            'superhero','superhero-alt','food','games','hourglass'
        ];

        $(document).on('click.jscfr', '.jscfr-icon-select', function () {
            var $wrap = $(this).closest('.jscfr-icon-picker-wrap');
            var $panel = $wrap.find('.jscfr-icon-picker-panel');
            var $grid = $panel.find('.jscfr-icon-grid');

            if ($panel.is(':visible')) {
                $panel.hide();
                return;
            }

            $grid.empty();
            var currentVal = $wrap.find('.jscfr-icon-value').val();
            $.each(jscfrDashicons, function (_, ic) {
                var cls = 'dashicons dashicons-' + ic;
                var active = (currentVal === cls) ? ' jscfr-icon-active' : '';
                $grid.append('<span class="dashicons dashicons-' + ic + active + '" data-icon="dashicons dashicons-' + ic + '" title="' + ic + '"></span>');
            });
            $panel.show();
        });

        $(document).on('click.jscfr', '.jscfr-icon-grid .dashicons', function () {
            var $wrap = $(this).closest('.jscfr-icon-picker-wrap');
            var icon = $(this).data('icon');
            $wrap.find('.jscfr-icon-value').val(icon);
            $wrap.find('.jscfr-icon-preview').html('<span class="' + icon + '"></span>');
            $wrap.find('.jscfr-icon-clear').show();
            $wrap.find('.jscfr-icon-picker-panel').hide();
        });

        $(document).on('click.jscfr', '.jscfr-icon-clear', function () {
            var $wrap = $(this).closest('.jscfr-icon-picker-wrap');
            $wrap.find('.jscfr-icon-value').val('');
            $wrap.find('.jscfr-icon-preview').html('<span class="jscfr-icon-placeholder">No icon</span>');
            $wrap.find('.jscfr-icon-svg-input').val('');
            $wrap.find('.jscfr-icon-svg-live').empty();
            $(this).hide();
        });

        $(document).on('input.jscfr', '.jscfr-icon-search', function () {
            var q = $(this).val().toLowerCase();
            $(this).closest('.jscfr-icon-tab-body').find('.jscfr-icon-grid .dashicons').each(function () {
                var name = $(this).data('icon') || '';
                $(this).toggle(name.indexOf(q) !== -1);
            });
        });

        /* Icon Picker — tab switching */
        $(document).on('click.jscfr', '.jscfr-icon-tab', function () {
            var $btn = $(this);
            var tab = $btn.data('tab');
            var $panel = $btn.closest('.jscfr-icon-picker-panel');
            $panel.find('.jscfr-icon-tab').removeClass('jscfr-icon-tab-active');
            $btn.addClass('jscfr-icon-tab-active');
            $panel.find('.jscfr-icon-tab-body').hide();
            $panel.find('.jscfr-icon-tab-body[data-body="' + tab + '"]').show();
        });

        /* Icon Picker — SVG upload from Media Library */
        function jscfrSanitizeSvgClient(raw) {
            var cleaned = String(raw || '').trim();
            if (!cleaned) return '';
            cleaned = cleaned.replace(/<\?xml[\s\S]*?\?>/gi, '');
            cleaned = cleaned.replace(/<!DOCTYPE[\s\S]*?>/gi, '');
            cleaned = cleaned.replace(/<!--[\s\S]*?-->/g, '');
            cleaned = cleaned.replace(/<script[\s\S]*?<\/script>/gi, '');
            cleaned = cleaned.replace(/\s+on[a-z]+\s*=\s*"[^"]*"/gi, '');
            cleaned = cleaned.replace(/\s+on[a-z]+\s*=\s*'[^']*'/gi, '');
            cleaned = cleaned.replace(/(href|xlink:href)\s*=\s*"\s*javascript:[^"]*"/gi, '$1=""');
            cleaned = cleaned.replace(/(href|xlink:href)\s*=\s*'\s*javascript:[^']*'/gi, '$1=""');
            return cleaned.trim();
        }

        function jscfrIsValidSvg(html) {
            if (!html) return false;
            var doc = new DOMParser().parseFromString(html, 'image/svg+xml');
            var err = doc.getElementsByTagName('parsererror').length;
            var root = doc.documentElement;
            return !err && root && root.nodeName.toLowerCase() === 'svg';
        }

        $(document).on('click.jscfr', '.jscfr-icon-svg-upload', function () {
            var $wrap = $(this).closest('.jscfr-icon-picker-wrap');
            $wrap.find('.jscfr-icon-svg-msg').text('').removeClass('is-ok is-err');
            $wrap.find('.jscfr-icon-svg-file').trigger('click');
        });

        $(document).on('change.jscfr', '.jscfr-icon-svg-file', function () {
            var input = this;
            var $wrap = $(input).closest('.jscfr-icon-picker-wrap');
            var $msg = $wrap.find('.jscfr-icon-svg-msg');
            var file = input.files && input.files[0];
            if (!file) return;
            if (file.type && file.type !== 'image/svg+xml' && !/\.svg$/i.test(file.name)) {
                $msg.text('Please select an SVG file').addClass('is-err');
                input.value = '';
                return;
            }
            if (file.size > 512 * 1024) {
                $msg.text('SVG too large (max 512 KB)').addClass('is-err');
                input.value = '';
                return;
            }
            $msg.text('Reading…');
            var reader = new FileReader();
            reader.onload = function (e) {
                var raw = String(e.target.result || '');
                var cleaned = jscfrSanitizeSvgClient(raw);
                if (!cleaned || !jscfrIsValidSvg(cleaned)) {
                    $msg.text('Invalid SVG file').removeClass('is-ok').addClass('is-err');
                    input.value = '';
                    return;
                }
                $wrap.find('.jscfr-icon-value').val('svg:' + cleaned);
                $wrap.find('.jscfr-icon-preview').html(cleaned);
                $wrap.find('.jscfr-icon-svg-live').html(cleaned);
                $wrap.find('.jscfr-icon-clear').show();
                $wrap.find('.jscfr-icon-svg-remove').show();
                $wrap.find('.jscfr-icon-picker-panel').hide();
                $msg.text('').removeClass('is-err is-ok');
                input.value = '';
            };
            reader.onerror = function () {
                $msg.text('Could not read file').addClass('is-err');
                input.value = '';
            };
            reader.readAsText(file);
        });

        $(document).on('click.jscfr', '.jscfr-icon-svg-remove', function () {
            var $wrap = $(this).closest('.jscfr-icon-picker-wrap');
            $wrap.find('.jscfr-icon-value').val('');
            $wrap.find('.jscfr-icon-preview').html('<span class="jscfr-icon-placeholder">No icon</span>');
            $wrap.find('.jscfr-icon-svg-live').empty();
            $wrap.find('.jscfr-icon-clear').hide();
            $(this).hide();
            $wrap.find('.jscfr-icon-svg-msg').text('').removeClass('is-ok is-err');
        });

        /* ================================================================ */
        /*  v5.1: File Advanced                                            */
        /* ================================================================ */
        $(document).on('click.jscfr', '.jscfr-fa-add', function () {
            var $wrap = $(this).closest('.jscfr-file-advanced-wrap');
            var maxFiles = parseInt($wrap.data('max'), 10) || 0;
            var mime = $wrap.data('mime') || '';
            var currentIds = $wrap.find('.jscfr-fa-ids').val();
            var currentCount = currentIds ? currentIds.split(',').length : 0;

            var frame = wp.media({
                title: L.select_file,
                button: { text: L.use_file },
                library: mime ? { type: mime } : {},
                multiple: true
            });

            frame.on('select', function () {
                var selected = frame.state().get('selection').toJSON();
                var ids = currentIds ? currentIds.split(',').filter(Boolean) : [];
                var $list = $wrap.find('.jscfr-fa-list');

                $.each(selected, function (_, att) {
                    if (maxFiles && ids.length >= maxFiles) return false;
                    if (ids.indexOf(String(att.id)) !== -1) return;
                    ids.push(String(att.id));
                    var size = att.filesizeHumanReadable || '';
                    $list.append(
                        '<div class="jscfr-fa-item" data-id="' + att.id + '">' +
                        '<span class="dashicons dashicons-media-default"></span>' +
                        '<span class="jscfr-fa-name">' + (att.filename || '') + '</span>' +
                        '<span class="jscfr-fa-size">' + size + '</span>' +
                        '<a href="' + att.url + '" target="_blank" class="jscfr-fa-view"><span class="dashicons dashicons-visibility"></span></a>' +
                        '<button type="button" class="jscfr-fa-remove"><span class="dashicons dashicons-no-alt"></span></button>' +
                        '</div>'
                    );
                });

                $wrap.find('.jscfr-fa-ids').val(ids.join(','));
            });

            frame.open();
        });

        $(document).on('click.jscfr', '.jscfr-fa-remove', function () {
            var $wrap = $(this).closest('.jscfr-file-advanced-wrap');
            var $item = $(this).closest('.jscfr-fa-item');
            var removeId = String($item.data('id'));
            var ids = $wrap.find('.jscfr-fa-ids').val().split(',').filter(function (id) { return id !== removeId; });
            $wrap.find('.jscfr-fa-ids').val(ids.join(','));
            $item.remove();
        });

        /* ================================================================ */
        /*  v5.1: Image Advanced                                           */
        /* ================================================================ */
        $(document).on('click.jscfr', '.jscfr-ia-add', function () {
            var $wrap = $(this).closest('.jscfr-image-advanced-wrap');
            var maxImgs = parseInt($wrap.data('max'), 10) || 0;
            var currentIds = $wrap.find('.jscfr-ia-ids').val();

            var frame = wp.media({
                title: L.select_image,
                button: { text: L.use_image },
                library: { type: 'image' },
                multiple: true
            });

            frame.on('select', function () {
                var selected = frame.state().get('selection').toJSON();
                var ids = currentIds ? currentIds.split(',').filter(Boolean) : [];
                var $grid = $wrap.find('.jscfr-ia-grid');

                $.each(selected, function (_, att) {
                    if (maxImgs && ids.length >= maxImgs) return false;
                    if (ids.indexOf(String(att.id)) !== -1) return;
                    ids.push(String(att.id));
                    var thumb = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                    $grid.append(
                        '<div class="jscfr-ia-item" data-id="' + att.id + '">' +
                        '<img src="' + thumb + '" alt="" />' +
                        '<button type="button" class="jscfr-ia-remove"><span class="dashicons dashicons-no-alt"></span></button>' +
                        '</div>'
                    );
                });

                $wrap.find('.jscfr-ia-ids').val(ids.join(','));
            });

            frame.open();
        });

        $(document).on('click.jscfr', '.jscfr-ia-remove', function () {
            var $wrap = $(this).closest('.jscfr-image-advanced-wrap');
            var $item = $(this).closest('.jscfr-ia-item');
            var removeId = String($item.data('id'));
            var ids = $wrap.find('.jscfr-ia-ids').val().split(',').filter(function (id) { return id !== removeId; });
            $wrap.find('.jscfr-ia-ids').val(ids.join(','));
            $item.remove();
        });

        /* ================================================================ */
        /*  Map fields — Leaflet (Google Maps look + OSM)                  */
        /* ================================================================ */
        var jscfrMapTiles = {
            google: {
                url: 'https://mt{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}',
                attribution: '',
                subdomains: '0123',
                maxZoom: 20
            },
            osm: {
                url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                attribution: '',
                subdomains: 'abc',
                maxZoom: 19
            }
        };

        function jscfrInitMap($wrap) {
            var LL = (window.jscfrL && window.jscfrL.map) ? window.jscfrL : ((window.L && window.L.map) ? window.L : null);
            if (!LL) { console.warn('[jscfr] Leaflet not available (window.L overwritten and jscfrL missing)'); return; }
            if ($wrap.data('jscfr-map')) return;
            var $canvas = $wrap.find('.jscfr-map-canvas');
            if (!$canvas.length || $canvas.is(':hidden')) return;
            if ($canvas[0].offsetWidth === 0 || $canvas[0].offsetHeight === 0) {
                setTimeout(function () { jscfrInitMap($wrap); }, 100);
                return;
            }

            var style = $wrap.data('tile-style') || 'osm';
            var tile = jscfrMapTiles[style] || jscfrMapTiles.osm;
            var $lat = $wrap.find('.jscfr-map-lat');
            var $lng = $wrap.find('.jscfr-map-lng');
            var $zoom = $wrap.find('.jscfr-map-zoom');
            var lat = parseFloat($lat.val());
            var lng = parseFloat($lng.val());
            var zoom = parseInt($zoom.val(), 10);
            var hasCoords = !isNaN(lat) && !isNaN(lng);
            if (!hasCoords) { lat = 20; lng = 0; zoom = isNaN(zoom) ? 2 : zoom; }
            if (isNaN(zoom)) zoom = 14;

            var map = LL.map($canvas.get(0), { scrollWheelZoom: false, attributionControl: false }).setView([lat, lng], zoom);
            LL.tileLayer(tile.url, {
                attribution: tile.attribution,
                subdomains: tile.subdomains,
                maxZoom: tile.maxZoom
            }).addTo(map);

            var marker = hasCoords ? LL.marker([lat, lng], { draggable: true }).addTo(map) : null;

            function setMarker(latlng, updateInputs) {
                if (!marker) {
                    marker = LL.marker(latlng, { draggable: true }).addTo(map);
                    marker.on('dragend', function () {
                        var p = marker.getLatLng();
                        $lat.val(p.lat.toFixed(7));
                        $lng.val(p.lng.toFixed(7));
                    });
                } else {
                    marker.setLatLng(latlng);
                }
                if (updateInputs !== false) {
                    $lat.val(latlng.lat.toFixed(7));
                    $lng.val(latlng.lng.toFixed(7));
                }
            }

            if (marker) {
                marker.on('dragend', function () {
                    var p = marker.getLatLng();
                    $lat.val(p.lat.toFixed(7));
                    $lng.val(p.lng.toFixed(7));
                });
            }

            map.on('click', function (e) { setMarker(e.latlng, true); });
            map.on('zoomend', function () { $zoom.val(map.getZoom()); });

            $lat.add($lng).on('change.jscfrmap', function () {
                var la = parseFloat($lat.val()), ln = parseFloat($lng.val());
                if (!isNaN(la) && !isNaN(ln)) { setMarker({ lat: la, lng: ln }, false); map.setView([la, ln]); }
            });
            $zoom.on('change.jscfrmap', function () {
                var z = parseInt($zoom.val(), 10);
                if (!isNaN(z)) map.setZoom(z);
            });

            $wrap.find('.jscfr-map-search-btn').on('click.jscfrmap', function () {
                jscfrGeocode($wrap, map, setMarker);
            });
            $wrap.find('.jscfr-map-address').on('keydown.jscfrmap', function (e) {
                if (e.which === 13) { e.preventDefault(); jscfrGeocode($wrap, map, setMarker); }
            });

            $wrap.data('jscfr-map', map);
            setTimeout(function () { map.invalidateSize(); }, 100);
        }

        function jscfrGeocode($wrap, map, setMarker) {
            var q = ($wrap.find('.jscfr-map-address').val() || '').trim();
            if (!q) return;
            var $btn = $wrap.find('.jscfr-map-search-btn');
            $btn.prop('disabled', true);
            $.ajax({
                url: 'https://nominatim.openstreetmap.org/search',
                data: { q: q, format: 'json', limit: 1 },
                dataType: 'json'
            }).done(function (res) {
                if (res && res.length) {
                    var lat = parseFloat(res[0].lat), lng = parseFloat(res[0].lon);
                    setMarker({ lat: lat, lng: lng }, true);
                    map.setView([lat, lng], 15);
                    $wrap.find('.jscfr-map-zoom').val(15);
                }
            }).always(function () { $btn.prop('disabled', false); });
        }

        $('.jscfr-map-wrap').each(function () { jscfrInitMap($(this)); });

        $(document).on('click.jscfr', '.jscfr-tab-btn', function () {
            setTimeout(function () {
                $('.jscfr-map-wrap:visible').each(function () { jscfrInitMap($(this)); });
            }, 50);
        });

        $(document).on('jscfr:clone_added', function (e, $newRow) {
            setTimeout(function () {
                $newRow.find('.jscfr-map-wrap').each(function () { jscfrInitMap($(this)); });
            }, 50);
        });

        /* ================================================================ */
        /*  Validation — banner above post/term title, no native popover   */
        /* ================================================================ */
        $(document).on('invalid.jscfr', '.jscfr-meta-wrap :input', function (e) {
            e.preventDefault();
        });

        function jscfrGetAnchor($form) {
            var $a = $form.find('#titlediv').first();
            if ($a.length) return $a;
            $a = $form.find('#title').first();
            if ($a.length) return $a.closest('.form-field, tr, div').first();
            $a = $form.find('#edittag, .form-table').first();
            if ($a.length) return $a;
            return $form.find('.jscfr-meta-wrap').first();
        }

        function jscfrBuildErrorItem(err) {
            return '<li><a href="#' + err.anchor + '" data-jscfr-goto="' + err.fldId + '">' +
                $('<span>').text(err.label).html() + '</a> — ' +
                $('<span>').text(err.msg).html() + '</li>';
        }

        function jscfrShowBanner($form, errors) {
            $form.find('.jscfr-validation-banner').remove();
            if (!errors.length) return;
            var items = errors.map(jscfrBuildErrorItem).join('');
            var $b = $(
                '<div class="jscfr-validation-banner notice notice-error" role="alert" tabindex="-1">' +
                '<p class="jscfr-vb-title"><span class="dashicons dashicons-warning"></span> ' +
                (errors.length === 1
                    ? 'Please fix 1 field before saving:'
                    : 'Please fix ' + errors.length + ' fields before saving:') +
                '</p>' +
                '<ul class="jscfr-vb-list">' + items + '</ul>' +
                '</div>'
            );
            var $anchor = jscfrGetAnchor($form);
            $b.insertBefore($anchor);
            $('html, body').animate({ scrollTop: $b.offset().top - 60 }, 200);
            $b.focus();
        }

        function jscfrMarkField($fld, msg) {
            $fld.addClass('jscfr-fld--invalid').attr('data-jscfr-error', msg);
        }

        function jscfrClearField($fld) {
            $fld.removeClass('jscfr-fld--invalid').removeAttr('data-jscfr-error');
        }

        function jscfrFieldValue($fld) {
            var $in = $fld.find('input, select, textarea');
            if (!$in.length) return '';
            if ($in.filter(':checkbox').length) {
                return $in.filter(':checkbox:checked').length ? '1' : '';
            }
            if ($in.filter(':radio').length) {
                return $in.filter(':radio:checked').val() || '';
            }
            var $primary = $in.filter('[name]').filter(':not([type=hidden])').first();
            if (!$primary.length) $primary = $in.filter('[name]').first();
            if (!$primary.length) $primary = $in.first();
            var v = $primary.val();
            if ($.isArray(v)) return v.length ? v.join(',') : '';
            return (v === null || v === undefined) ? '' : String(v).trim();
        }

        function jscfrValidateForm($form) {
            var errors = [];
            $form.find('.jscfr-fld').each(function () {
                var $fld = $(this);
                jscfrClearField($fld);
                if (!$fld.is(':visible')) return;
                var fldId = $fld.data('field-id');
                var label = $fld.find('> label').first().clone().children().remove().end().text().trim()
                    || fldId || 'Field';
                var required = $fld.hasClass('jscfr-fld--required');
                var val = jscfrFieldValue($fld);

                if (required && val === '') {
                    var msg = L.required_error || 'This field is required.';
                    if (!$fld.attr('id')) $fld.attr('id', 'jscfr-fld-' + Math.random().toString(36).slice(2, 8));
                    jscfrMarkField($fld, msg);
                    errors.push({ anchor: $fld.attr('id'), fldId: fldId, label: label, msg: msg });
                    return;
                }

                var bad = null;
                $fld.find('input, select, textarea').each(function () {
                    if (this.type === 'hidden') return;
                    if (!this.willValidate) return;
                    if (!this.checkValidity()) { bad = this; return false; }
                });
                if (bad) {
                    var nmsg = bad.validationMessage || 'Invalid value.';
                    if (!$fld.attr('id')) $fld.attr('id', 'jscfr-fld-' + Math.random().toString(36).slice(2, 8));
                    jscfrMarkField($fld, nmsg);
                    errors.push({ anchor: $fld.attr('id'), fldId: fldId, label: label, msg: nmsg });
                }
            });
            return errors;
        }

        $(document).on('submit.jscfr', 'form', function (e) {
            var $form = $(this);
            if (!$form.find('.jscfr-meta-wrap').length) return;
            var errors = jscfrValidateForm($form);
            if (errors.length) {
                e.preventDefault();
                e.stopImmediatePropagation();
                jscfrShowBanner($form, errors);
                // Re-enable WP submit buttons + spinner that post.js disabled before this handler
                $('#publish, #save-post, #post-preview, input[type="submit"], button[type="submit"]')
                    .removeClass('disabled button-disabled button-primary-disabled')
                    .removeAttr('aria-disabled')
                    .prop('disabled', false);
                $('#ajax-loading, #publishing-action .spinner, #save-action .spinner, #major-publishing-actions .spinner')
                    .removeClass('is-active')
                    .css('visibility', 'hidden');
            } else {
                $form.find('.jscfr-validation-banner').remove();
            }
        });

        $(document).on('click.jscfr', '.jscfr-validation-banner a[data-jscfr-goto]', function (e) {
            e.preventDefault();
            var id = $(this).attr('href').slice(1);
            var $t = $('#' + id);
            if (!$t.length) return;
            $('html, body').animate({ scrollTop: $t.offset().top - 80 }, 250);
            $t.find('input, select, textarea').filter(':visible').first().focus();
        });

        $(document).on('input.jscfr change.jscfr', '.jscfr-fld--invalid :input', function () {
            var $fld = $(this).closest('.jscfr-fld');
            var val = jscfrFieldValue($fld);
            var stillInvalid = false;
            if ($fld.hasClass('jscfr-fld--required') && val === '') stillInvalid = true;
            if (!stillInvalid) {
                $fld.find('input, select, textarea').each(function () {
                    if (this.type !== 'hidden' && this.willValidate && !this.checkValidity()) {
                        stillInvalid = true;
                        return false;
                    }
                });
            }
            if (!stillInvalid) jscfrClearField($fld);
        });

    });

})(jQuery);

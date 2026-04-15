/**
 * JSCFR Builder — List page JS (delete, duplicate, toggle, bulk actions, import/export)
 */
(function ($) {
    'use strict';

    var L = jscfr_list;

    $(function () {

        /* Select all checkboxes (top + bottom) */
        $(document).on('change.jscfr', '#jscfr-cb-all, #jscfr-cb-all-bottom', function () {
            var checked = $(this).prop('checked');
            $('.jscfr-export-cb').prop('checked', checked);
            $('#jscfr-cb-all, #jscfr-cb-all-bottom').prop('checked', checked);
        });
        $(document).on('change.jscfr', '.jscfr-export-cb', function () {
            var total = $('.jscfr-export-cb').length;
            var checked = $('.jscfr-export-cb:checked').length;
            $('#jscfr-cb-all, #jscfr-cb-all-bottom').prop('checked', total === checked);
        });

        /* Trash (soft delete) */
        $(document).on('click.jscfr', '.jscfr-action-trash', function (e) {
            e.preventDefault();
            if (!confirm(L.confirm_delete)) return;
            var $tr = $(this).closest('tr');
            var id = $tr.data('fg-id') || $(this).data('id');
            $.post(L.ajax_url, { action: 'jscfr_trash_field_group', nonce: L.nonce, fg_id: id })
                .done(function (res) {
                    if (res.success) {
                        $tr.fadeOut(300, function () { window.location.reload(); });
                    } else {
                        alert('Trash failed: ' + (res.data || 'Unknown error'));
                    }
                });
        });

        /* Restore from trash */
        $(document).on('click.jscfr', '.jscfr-action-restore', function (e) {
            e.preventDefault();
            var $tr = $(this).closest('tr');
            var id = $tr.data('fg-id') || $(this).data('id');
            $.post(L.ajax_url, { action: 'jscfr_restore_field_group', nonce: L.nonce, fg_id: id })
                .done(function (res) {
                    if (res.success) {
                        $tr.fadeOut(300, function () { window.location.reload(); });
                    } else {
                        alert('Restore failed: ' + (res.data || 'Unknown error'));
                    }
                });
        });

        /* Delete permanently */
        $(document).on('click.jscfr', '.jscfr-action-delete-permanently', function (e) {
            e.preventDefault();
            if (!confirm(L.confirm_delete_permanently)) return;
            var $tr = $(this).closest('tr');
            var id = $tr.data('fg-id') || $(this).data('id');
            $.post(L.ajax_url, { action: 'jscfr_delete_field_group', nonce: L.nonce, fg_id: id })
                .done(function (res) {
                    if (res.success) {
                        $tr.fadeOut(300, function () { window.location.reload(); });
                    } else {
                        alert('Delete failed: ' + (res.data || 'Unknown error'));
                    }
                });
        });

        /* Duplicate */
        $(document).on('click.jscfr', '.jscfr-action-duplicate', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            $.post(L.ajax_url, { action: 'jscfr_duplicate_field_group', nonce: L.nonce, fg_id: id }, function (res) {
                if (res.success && res.data.redirect) {
                    window.location.href = res.data.redirect;
                }
            });
        });

        /* Toggle active — via toggle switch */
        $(document).on('change.jscfr', '.jscfr-action-toggle-switch', function () {
            var $cb = $(this);
            var $tr = $cb.closest('tr');
            var id = $cb.data('id');
            $.post(L.ajax_url, { action: 'jscfr_toggle_field_group', nonce: L.nonce, fg_id: id }, function (res) {
                if (res.success) {
                    if (res.data.active) {
                        $tr.removeClass('jscfr-row-inactive');
                        $tr.find('.jscfr-col-date').text('Published');
                    } else {
                        $tr.addClass('jscfr-row-inactive');
                        $tr.find('.jscfr-col-date').text('Draft');
                    }
                }
            });
        });

        /* Legacy toggle link (fallback) */
        $(document).on('click.jscfr', '.jscfr-action-toggle', function (e) {
            e.preventDefault();
            var $a = $(this);
            var $tr = $a.closest('tr');
            var id = $a.data('id');
            $.post(L.ajax_url, { action: 'jscfr_toggle_field_group', nonce: L.nonce, fg_id: id }, function (res) {
                if (res.success) {
                    if (res.data.active) {
                        $tr.removeClass('jscfr-row-inactive');
                        $a.text('Deactivate');
                    } else {
                        $tr.addClass('jscfr-row-inactive');
                        $a.text('Activate');
                    }
                }
            });
        });

        /* Bulk actions */
        $(document).on('click.jscfr', '.jscfr-bulk-apply', function () {
            var selId = $(this).data('select');
            var action = $(selId).val();
            if (action === '-1') return;

            var ids = [];
            $('.jscfr-export-cb:checked').each(function () {
                ids.push($(this).val());
            });
            if (!ids.length) { alert('Select at least one field group.'); return; }

            var bulkEndpoint = null;
            var confirmMsg = null;
            if (action === 'trash') {
                bulkEndpoint = 'jscfr_trash_field_group';
                confirmMsg = L.confirm_delete;
            } else if (action === 'restore') {
                bulkEndpoint = 'jscfr_restore_field_group';
            } else if (action === 'delete_permanently' || action === 'delete') {
                bulkEndpoint = 'jscfr_delete_field_group';
                confirmMsg = L.confirm_delete_permanently || L.confirm_delete;
            }

            if (bulkEndpoint) {
                if (confirmMsg && !confirm(confirmMsg)) return;
                var done = 0;
                $.each(ids, function (_, id) {
                    $.post(L.ajax_url, { action: bulkEndpoint, nonce: L.nonce, fg_id: id }, function () {
                        done++;
                        if (done >= ids.length) {
                            setTimeout(function () { window.location.reload(); }, 300);
                        }
                    });
                });
            } else if (action === 'export') {
                $.post(L.ajax_url, {
                    action: 'jscfr_export_field_groups',
                    nonce: L.nonce,
                    ids: ids
                }, function (res) {
                    if (res.success) {
                        var blob = new Blob([res.data.json], { type: 'application/json' });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = 'jscfr-field-groups.json';
                        a.click();
                        URL.revokeObjectURL(url);
                    }
                });
            }
        });

        /* Export (standalone button on import/export page) */
        $(document).on('click.jscfr', '#jscfr-export-btn', function () {
            var ids = [];
            $('.jscfr-export-cb:checked').each(function () {
                ids.push($(this).val());
            });
            if (!ids.length) { alert('Select at least one field group.'); return; }

            $.post(L.ajax_url, {
                action: 'jscfr_export_field_groups',
                nonce: L.nonce,
                ids: ids
            }, function (res) {
                if (res.success) {
                    var $ta = $('#jscfr-export-json');
                    $ta.val(res.data.json).show();
                    $ta[0].select();
                    var blob = new Blob([res.data.json], { type: 'application/json' });
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'jscfr-field-groups.json';
                    a.click();
                    URL.revokeObjectURL(url);
                }
            });
        });

        /* Import/Export page: Select-all + counter */
        function jscfrIeUpdateCount() {
            var total = $('.jscfr-export-cb').length;
            var checked = $('.jscfr-export-cb:checked').length;
            $('#jscfr-ie-count').text(checked);
            $('#jscfr-ie-select-all').prop('checked', total > 0 && total === checked);
        }
        $(document).on('change.jscfr', '#jscfr-ie-select-all', function () {
            $('.jscfr-export-cb').prop('checked', $(this).prop('checked'));
            jscfrIeUpdateCount();
        });
        $(document).on('change.jscfr', '.jscfr-export-cb', jscfrIeUpdateCount);

        /* Import: file upload (hidden input inside dropzone label) */
        function jscfrIeReadFile(file) {
            if (!file) return;
            $('#jscfr-ie-filename').text(file.name);
            var reader = new FileReader();
            reader.onload = function (e) {
                $('#jscfr-import-json').val(e.target.result);
            };
            reader.readAsText(file);
        }
        $(document).on('change.jscfr', '#jscfr-import-file', function () {
            jscfrIeReadFile(this.files[0]);
        });

        /* Drag & drop on the dropzone */
        var $dz = $('#jscfr-ie-dropzone');
        if ($dz.length) {
            $dz.on('dragover dragenter', function (e) {
                e.preventDefault(); e.stopPropagation();
                $dz.addClass('is-dragging');
            });
            $dz.on('dragleave dragend drop', function (e) {
                e.preventDefault(); e.stopPropagation();
                $dz.removeClass('is-dragging');
            });
            $dz.on('drop', function (e) {
                var files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
                if (files && files.length) jscfrIeReadFile(files[0]);
            });
        }

        /* Import */
        $(document).on('click.jscfr', '#jscfr-import-btn', function () {
            var json = $('#jscfr-import-json').val().trim();
            if (!json) { alert('Paste JSON or upload a file first.'); return; }

            $.post(L.ajax_url, {
                action: 'jscfr_import_field_groups',
                nonce: L.nonce,
                json: json
            }, function (res) {
                var $st = $('#jscfr-import-status');
                if (res.success) {
                    $st.text('Imported ' + res.data.count + ' field group(s). Opening list...').css('color', 'green');
                    setTimeout(function () {
                        window.location.href = 'admin.php?page=jscfr-builder';
                    }, 1200);
                } else {
                    $st.text('Error: ' + (res.data || 'Invalid JSON')).css('color', 'red');
                }
            });
        });
    });

})(jQuery);

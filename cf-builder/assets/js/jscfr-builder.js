/**
 * JSCFR Builder — Edit page JS (v4)
 * Expandable field settings panels, field name auto-gen, conditional logic builder,
 * all new field types, duplicate field/group/tab.
 */
(function ($) {
    'use strict';

    var B  = jscfr_builder,
        FG = B.field_group,
        LP = B.location_params,
        $tabs = null;

    /* ================================================================ */
    /*  Field Type Categories & Icons (Meta Box style)                  */
    /* ================================================================ */
    var fieldCategories = {
        'Basic': {
            'text':        { icon: 'editor-textcolor', label: 'Text' },
            'textarea':    { icon: 'editor-paragraph', label: 'Textarea' },
            'number':      { icon: 'editor-ol', label: 'Number' },
            'select':      { icon: 'arrow-down-alt2', label: 'Select' },
            'checkbox':    { icon: 'yes-alt', label: 'Checkbox' },
            'radio':       { icon: 'marker', label: 'Radio' },
            'true_false':  { icon: 'controls-play', label: 'True / False' },
            'button_group':{ icon: 'screenoptions', label: 'Button Group' },
            'range':       { icon: 'leftright', label: 'Range' },
            'hidden':      { icon: 'hidden', label: 'Hidden' }
        },
        'Advanced': {
            'autocomplete':    { icon: 'search', label: 'Autocomplete' },
            'background':      { icon: 'art', label: 'Background' },
            'custom_html':     { icon: 'editor-code', label: 'Custom HTML' },
            'color':           { icon: 'admin-customizer', label: 'Color Picker' },
            'fieldset_text':   { icon: 'grid-view', label: 'Fieldset Text' },
            'google_map':      { icon: 'location', label: 'Google Maps' },
            'icon':            { icon: 'star-filled', label: 'Icon' },
            'image_select':    { icon: 'format-gallery', label: 'Image Select' },
            'key_value':       { icon: 'screenoptions', label: 'Key Value' },
            'oembed':          { icon: 'admin-media', label: 'oEmbed' },
            'password':        { icon: 'lock', label: 'Password' },
            'select_advanced': { icon: 'arrow-down-alt', label: 'Select Advanced' },
            'slider':          { icon: 'image-flip-horizontal', label: 'jQuery UI Slider' },
            'switch':          { icon: 'admin-settings', label: 'Switch' },
            'text_list':       { icon: 'grid-view', label: 'Text List' },
            'wysiwyg':         { icon: 'edit', label: 'WYSIWYG Editor' }
        },
        'HTML5': {
            'date':     { icon: 'calendar', label: 'Date Picker' },
            'datetime': { icon: 'calendar-alt', label: 'Datetime Picker' },
            'time':     { icon: 'clock', label: 'Time Picker' },
            'email':    { icon: 'email', label: 'Email' },
            'url':      { icon: 'admin-links', label: 'URL' }
        },
        'WordPress': {
            'post_object':  { icon: 'admin-post', label: 'Post' },
            'taxonomy':     { icon: 'category', label: 'Taxonomy' },
            'taxonomy_advanced': { icon: 'tag', label: 'Taxonomy Advanced' },
            'user':         { icon: 'admin-users', label: 'User' },
            'relationship': { icon: 'networking', label: 'Relationship' },
            'sidebar':      { icon: 'columns', label: 'Sidebar' }
        },
        'Upload': {
            'file':           { icon: 'media-default', label: 'File' },
            'file_advanced':  { icon: 'media-spreadsheet', label: 'File Advanced' },
            'file_input':     { icon: 'media-text', label: 'File Input' },
            'file_upload':    { icon: 'upload', label: 'File Upload' },
            'image':          { icon: 'format-image', label: 'Image' },
            'image_advanced': { icon: 'images-alt2', label: 'Image Advanced' },
            'image_upload':   { icon: 'format-gallery', label: 'Image Upload' },
            'single_image':   { icon: 'format-image', label: 'Single Image' },
            'gallery':        { icon: 'format-gallery', label: 'Gallery' },
            'video':          { icon: 'video-alt3', label: 'Video' }
        },
        'Layout': {
            'heading':  { icon: 'heading', label: 'Heading' },
            'divider':  { icon: 'minus', label: 'Divider' },
            'message':  { icon: 'info', label: 'Message' },
            'button':   { icon: 'button', label: 'Button' },
            'link':     { icon: 'admin-links', label: 'Link' },
            '_tab':     { icon: 'index-card', label: 'Tab' },
            '_group':   { icon: 'screenoptions', label: 'Group' }
        }
    };

    function buildAddFieldModal() {
        var html = '<div class="jscfr-modal-overlay jscfr-add-field-modal" style="display:none;">';
        html += '<div class="jscfr-modal" style="width:860px;max-width:90vw;">';
        html += '<div class="jscfr-modal-header"><h3>Add a new field</h3>';
        html += '<button type="button" class="jscfr-modal-close jscfr-add-field-close">&times;</button></div>';
        html += '<div class="jscfr-modal-body" style="padding:0;">';
        html += '<div class="jscfr-aft-search-wrap"><input type="text" class="jscfr-aft-search" placeholder="Search field types..." /></div>';
        html += '<div class="jscfr-aft-scroll">';
        $.each(fieldCategories, function (catName, types) {
            html += '<div class="jscfr-aft-category" data-cat="' + catName + '">';
            html += '<div class="jscfr-aft-cat-label">' + catName.toUpperCase() + '</div>';
            html += '<div class="jscfr-aft-grid">';
            $.each(types, function (typeKey, info) {
                html += '<div class="jscfr-aft-item" data-type="' + typeKey + '" title="' + esc(info.label) + '">';
                html += '<span class="dashicons dashicons-' + info.icon + '"></span>';
                html += '<span class="jscfr-aft-label">' + esc(info.label) + '</span>';
                html += '</div>';
            });
            html += '</div></div>';
        });
        html += '</div></div></div></div>';
        return $(html);
    }

    /* ================================================================ */
    /*  Helpers                                                         */
    /* ================================================================ */
    function uid(prefix) {
        return prefix + '_' + Math.random().toString(36).substr(2, 8);
    }

    function esc(str) {
        if (typeof str !== 'string') return '';
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return str.replace(/[&<>"']/g, function (c) { return map[c]; });
    }

    function slugify(str) {
        return str.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').substring(0, 40);
    }

    /* Groups of field types that share settings */
    var textLike   = ['text','email','url','password'];
    var numberLike = ['number','range'];
    var hasOptions = ['select','radio','checkbox','button_group'];
    var fileLike   = ['file','image','single_image','video'];
    var dateLike   = ['date','datetime','time'];
    var displayOnly = ['heading','divider','custom_html','button','message'];
    var compositeTypes = ['fieldset_text','text_list'];
    var mapTypes   = ['google_map'];
    var multiFileTypes = ['file_advanced','image_advanced'];

    /* Dashicons list for icon picker */
    var dashiconsList = [
        'menu','admin-site','dashboard','admin-post','admin-media','admin-links','admin-page','admin-comments',
        'admin-appearance','admin-plugins','admin-users','admin-tools','admin-settings','admin-network','admin-home',
        'admin-generic','admin-collapse','welcome-write-blog','welcome-add-page','welcome-view-site','welcome-widgets-menus',
        'welcome-comments','welcome-learn-more','format-aside','format-image','format-gallery','format-video','format-audio',
        'format-chat','format-status','format-quote','format-links','media-archive','media-audio','media-code','media-default',
        'media-document','media-interactive','media-spreadsheet','media-text','media-video','image-crop','image-rotate-left',
        'image-rotate-right','image-flip-vertical','image-flip-horizontal','image-filter','image-rotate',
        'editor-bold','editor-italic','editor-ul','editor-ol','editor-quote','editor-alignleft','editor-aligncenter',
        'editor-alignright','editor-insertmore','editor-spellcheck','editor-expand','editor-contract','editor-table',
        'editor-paste-word','editor-paste-text','editor-removeformatting','editor-video','editor-customchar',
        'editor-outdent','editor-indent','editor-help','editor-strikethrough','editor-unlink','editor-rtl','editor-ltr',
        'editor-break','editor-code','editor-paragraph','editor-textcolor','align-left','align-right','align-center','align-none',
        'lock','unlock','calendar','calendar-alt','visibility','hidden','post-status','edit','trash','sticky',
        'external','arrow-up','arrow-down','arrow-right','arrow-left','arrow-up-alt','arrow-down-alt','arrow-right-alt',
        'arrow-left-alt','arrow-up-alt2','arrow-down-alt2','arrow-right-alt2','arrow-left-alt2','sort','leftright',
        'randomize','list-view','exerpt-view','grid-view','move','screenshot','hammer','art','migrate','performance',
        'universal-access','universal-access-alt','tickets','nametag','clipboard','heart','megaphone','schedule',
        'tide','rest-api','code-standards','buddicons-activity','buddicons-bbpress-logo','buddicons-buddypress-logo',
        'buddicons-community','buddicons-forums','buddicons-friends','buddicons-groups','buddicons-pm',
        'wordpress','wordpress-alt','pressthis','update','screenoptions','info','cart','feedback',
        'cloud','translation','tag','category','archive','tagcloud','text','yes','no','no-alt',
        'plus','plus-alt','plus-alt2','minus','dismiss','marker','star-filled','star-half','star-empty',
        'flag','warning','location','location-alt','vault','shield','shield-alt','sos',
        'search','slides','analytics','chart-pie','chart-bar','chart-line','chart-area',
        'groups','businessman','businesswoman','businessperson','id','id-alt','products',
        'awards','forms','testimonial','portfolio','book','book-alt','download','upload',
        'backup','clock','lightbulb','microphone','desktop','laptop','tablet','smartphone',
        'phone','index-card','carrot','building','store','album','palmtree','tickets-alt',
        'money','money-alt','thumbs-up','thumbs-down','layout','paperclip','color-picker',
        'email','email-alt','email-alt2','networking','amazon','google','linkedin','facebook',
        'facebook-alt','twitter','twitter-alt','instagram','rss','youtube','pinterest',
        'share','share-alt','share-alt2','ellipsis','plus-light','database','bell',
        'table-col-after','table-col-before','table-col-delete','table-row-after','table-row-before','table-row-delete',
        'saved','open-folder','pets','privacy','superhero','superhero-alt','food','games',
        'hourglass','airplane','car','calculator','ames','beer','coffee','drumstick','html','insert'
    ];

    function inArr(val, arr) { return arr.indexOf(val) !== -1; }

    /* ================================================================ */
    /*  TABS / GROUPS / FIELDS — Build UI                               */
    /* ================================================================ */
    function renderTabs() {
        $tabs.empty();
        if (!FG.tabs || !FG.tabs.length) {
            $tabs.attr('data-empty', B.i18n.no_tabs);
        } else {
            $tabs.removeAttr('data-empty');
            $.each(FG.tabs, function (_, tab) {
                $tabs.append(buildTabCard(tab));
            });
        }
        initSortables();
    }

    function buildTabCard(tab) {
        var $c = $('<div class="jscfr-tab-card" data-id="' + esc(tab.id) + '"></div>');
        var $h = $('<div class="jscfr-tab-head"></div>');
        $h.append('<span class="dashicons dashicons-move jscfr-drag"></span>');
        $h.append('<input type="text" class="jscfr-tab-label" value="' + esc(tab.label) + '" placeholder="' + esc(B.i18n.tab_label) + '" />');
        $h.append('<input type="text" class="jscfr-tab-name" value="' + esc(tab.name || '') + '" placeholder="' + esc(B.i18n.tab_name) + '" />');
        /* Tab Icon Picker */
        var iconPreview = tab.icon ? '<span class="dashicons dashicons-' + esc(tab.icon) + '"></span>' : '<span class="dashicons dashicons-admin-post"></span>';
        $h.append('<button type="button" class="jscfr-btn-icon jscfr-tab-icon-btn" title="Select Icon">' + iconPreview + '</button>');
        $h.append('<input type="hidden" class="jscfr-tab-icon-value" value="' + esc(tab.icon || '') + '" />');
        $h.append('<input type="hidden" class="jscfr-tab-icon-type" value="' + esc(tab.icon_type || 'dashicons') + '" />');
        $h.append('<span class="jscfr-id-badge jscfr-copy-id" data-id="' + esc(tab.id) + '" title="Click to copy">ID: ' + esc(tab.id) + '</span>');
        $h.append('<button type="button" class="jscfr-btn-icon jscfr-toggle-tab" title="Toggle"><span class="dashicons dashicons-arrow-down-alt2"></span></button>');
        $h.append('<button type="button" class="jscfr-btn-icon jscfr-dup-tab" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></button>');
        $h.append('<button type="button" class="jscfr-btn-icon jscfr-del-tab" title="Delete"><span class="dashicons dashicons-trash"></span></button>');
        $c.append($h);

        var $b = $('<div class="jscfr-tab-body" style="display:none;"></div>');
        var $gc = $('<div class="jscfr-groups-container"></div>');
        $.each(tab.groups || [], function (_, grp) { $gc.append(buildGroupCard(grp)); });
        $b.append($gc);
        $b.append('<button type="button" class="button jscfr-add-group"><span class="dashicons dashicons-plus-alt2"></span> ' + esc(B.i18n.new_group) + '</button>');
        $c.append($b);
        return $c;
    }

    function buildGroupCard(grp) {
        var $c = $('<div class="jscfr-group-card" data-id="' + esc(grp.id) + '"></div>');
        var $h = $('<div class="jscfr-group-head"></div>');
        $h.append('<span class="dashicons dashicons-move jscfr-drag"></span>');
        $h.append('<input type="text" class="jscfr-group-label" value="' + esc(grp.label) + '" placeholder="' + esc(B.i18n.group_label) + '" />');
        $h.append('<input type="text" class="jscfr-group-name" value="' + esc(grp.name || '') + '" placeholder="' + esc(B.i18n.group_name) + '" />');
        $h.append('<span class="jscfr-id-badge jscfr-id-badge--grp jscfr-copy-id" data-id="' + esc(grp.id) + '" title="Click to copy">ID: ' + esc(grp.id) + '</span>');

        // Settings popover (gear) — contains clonable / min / max / layout
        var clonableChecked = grp.clonable !== false ? ' checked' : '';
        var minMaxHidden    = grp.clonable === false ? ' style="display:none;"' : '';
        var layOpts = '';
        $.each(['block','table','row'], function(_, v){
            layOpts += '<option value="' + v + '"' + ((grp.layout||'block')===v?' selected':'') + '>' + v + '</option>';
        });

        var popHtml =
            '<div class="jscfr-group-options">' +
                '<button type="button" class="jscfr-btn-sm jscfr-group-options-btn" title="' + esc(B.i18n.layout || 'Options') + '" aria-haspopup="true" aria-expanded="false">' +
                    '<span class="dashicons dashicons-admin-generic"></span>' +
                '</button>' +
                '<div class="jscfr-group-options-pop" role="menu" hidden>' +
                    '<div class="jscfr-gop-row jscfr-gop-row--toggle">' +
                        '<label class="jscfr-gop-label"><span class="dashicons dashicons-controls-repeat"></span> ' + esc(B.i18n.clonable || 'Clonable') + '</label>' +
                        '<label class="jscfr-gop-switch"><input type="checkbox" class="jscfr-group-clonable"' + clonableChecked + ' /><span class="jscfr-gop-slider"></span></label>' +
                    '</div>' +
                    '<div class="jscfr-gop-row jscfr-gop-row--pair"' + minMaxHidden + '>' +
                        '<label class="jscfr-gop-col">' +
                            '<span class="jscfr-gop-caption">' + esc(B.i18n.min_rows || 'Min') + '</span>' +
                            '<div class="jscfr-gop-input-wrap">' +
                                '<input type="number" class="jscfr-group-min" value="' + esc(grp.min || '') + '" min="0" placeholder="0" />' +
                                '<button type="button" class="jscfr-gop-clear" title="Reset" aria-label="Reset"><span class="dashicons dashicons-no-alt"></span></button>' +
                            '</div>' +
                        '</label>' +
                        '<label class="jscfr-gop-col">' +
                            '<span class="jscfr-gop-caption">' + esc(B.i18n.max_rows || 'Max') + '</span>' +
                            '<div class="jscfr-gop-input-wrap">' +
                                '<input type="number" class="jscfr-group-max" value="' + esc(grp.max || '') + '" min="0" placeholder="∞" />' +
                                '<button type="button" class="jscfr-gop-clear" title="Set to unlimited" aria-label="Set to unlimited"><span class="dashicons dashicons-no-alt"></span></button>' +
                            '</div>' +
                        '</label>' +
                    '</div>' +
                    '<p class="jscfr-gop-hint">' + esc(B.i18n.max_hint || 'Leave Max empty for unlimited (∞).') + '</p>' +
                    '<div class="jscfr-gop-row">' +
                        '<label class="jscfr-gop-col">' +
                            '<span class="jscfr-gop-caption">' + esc(B.i18n.layout || 'Layout') + '</span>' +
                            '<select class="jscfr-group-layout">' + layOpts + '</select>' +
                        '</label>' +
                    '</div>' +
                '</div>' +
            '</div>';
        $h.append(popHtml);

        $h.append('<button type="button" class="jscfr-btn-sm jscfr-toggle-group"><span class="dashicons dashicons-arrow-down-alt2"></span></button>');
        $h.append('<button type="button" class="jscfr-btn-sm jscfr-dup-group"><span class="dashicons dashicons-admin-page"></span></button>');
        $h.append('<button type="button" class="jscfr-btn-sm jscfr-del-group"><span class="dashicons dashicons-trash"></span></button>');
        $c.append($h);

        var $b = $('<div class="jscfr-group-body"></div>');
        var $fc = $('<div class="jscfr-fields-container"></div>');
        $.each(grp.fields || [], function (_, fld) { $fc.append(buildFieldRow(fld)); });
        $b.append($fc);
        $b.append('<button type="button" class="button button-secondary jscfr-add-field"><span class="dashicons dashicons-plus-alt2"></span> ' + esc(B.i18n.new_field) + '</button>');
        $c.append($b);
        return $c;
    }

    /* ================================================================ */
    /*  Build expandable field row                                      */
    /* ================================================================ */
    function buildFieldRow(fld) {
        var typeOpts = '';
        $.each(B.field_types, function (val, label) {
            typeOpts += '<option value="' + esc(val) + '"' + (fld.type === val ? ' selected' : '') + '>' + esc(label) + '</option>';
        });

        var $r = $('<div class="jscfr-field-row" data-id="' + esc(fld.id) + '" data-type="' + esc(fld.type) + '"></div>');

        /* Header (always visible) */
        var $head = $('<div class="jscfr-field-head"></div>');
        $head.append('<span class="dashicons dashicons-move jscfr-drag"></span>');
        $head.append('<span class="jscfr-id-badge jscfr-id-badge--fld jscfr-copy-id" data-id="' + esc(fld.id) + '" title="Click to copy">' + esc(fld.id) + '</span>');
        $head.append('<span class="jscfr-field-label-preview">' + esc(fld.label || B.i18n.new_field) + '</span>');
        $head.append('<span class="jscfr-field-type-badge">' + esc(B.field_types[fld.type] || fld.type) + '</span>');
        $head.append('<span class="jscfr-field-name-preview">' + esc(fld.name || '') + '</span>');
        $head.append('<button type="button" class="jscfr-btn-icon jscfr-toggle-field-settings"><span class="dashicons dashicons-arrow-down-alt2"></span></button>');
        $head.append('<button type="button" class="jscfr-btn-icon jscfr-dup-field"><span class="dashicons dashicons-admin-page"></span></button>');
        $head.append('<button type="button" class="jscfr-del-field" title="Delete"><span class="dashicons dashicons-trash"></span></button>');
        $r.append($head);

        /* Settings panel (collapsible) */
        var $panel = $('<div class="jscfr-field-settings" style="display:none;"></div>');

        /* Sub-tabs */
        $panel.append(
            '<div class="jscfr-field-stabs">' +
            '<a href="#" class="jscfr-fstab-btn jscfr-fstab-active" data-fstab="general">' + esc(B.i18n.general_tab) + '</a>' +
            '<a href="#" class="jscfr-fstab-btn" data-fstab="validation">' + esc(B.i18n.validation_tab) + '</a>' +
            '<a href="#" class="jscfr-fstab-btn" data-fstab="presentation">' + esc(B.i18n.presentation_tab) + '</a>' +
            '<a href="#" class="jscfr-fstab-btn" data-fstab="conditional">' + esc(B.i18n.conditional_tab) + '</a>' +
            '<a href="#" class="jscfr-fstab-btn" data-fstab="advanced">Advanced</a>' +
            '</div>'
        );

        /* General tab */
        var $gen = $('<div class="jscfr-fstab-panel" data-fstab="general"></div>');
        $gen.append(settingsRow(B.i18n.field_label, '<input type="text" class="jscfr-field-label widefat" value="' + esc(fld.label) + '" />'));
        $gen.append(settingsRow(B.i18n.field_name, '<input type="text" class="jscfr-field-name widefat" value="' + esc(fld.name || '') + '" placeholder="auto-generated" />'));
        $gen.append(settingsRow(B.i18n.field_type, '<select class="jscfr-field-type widefat">' + typeOpts + '</select>'));
        $gen.append(settingsRow(B.i18n.instructions, '<textarea class="jscfr-field-instructions widefat" rows="2">' + esc(fld.instructions || '') + '</textarea><p class="description">' + esc(B.i18n.instructions_hint) + '</p>'));
        $gen.append(settingsRow(B.i18n.default_value, '<input type="text" class="jscfr-field-default widefat" value="' + esc(fld.default_value || '') + '" />'));
        $gen.append(settingsRow(B.i18n.placeholder, '<input type="text" class="jscfr-field-ph widefat" value="' + esc(fld.placeholder || '') + '" />', 'jscfr-srow-ph'));

        /* Type-specific: text/number prepend/append */
        $gen.append(settingsRow(B.i18n.prepend, '<input type="text" class="jscfr-field-prepend" value="' + esc(fld.prepend || '') + '" />', 'jscfr-srow-prepend'));
        $gen.append(settingsRow(B.i18n.append, '<input type="text" class="jscfr-field-append" value="' + esc(fld.append || '') + '" />', 'jscfr-srow-append'));

        /* Number/Range min/max/step */
        $gen.append(settingsRow(B.i18n.min, '<input type="text" class="jscfr-field-min" value="' + esc(fld.min || '') + '" />', 'jscfr-srow-min'));
        $gen.append(settingsRow(B.i18n.max, '<input type="text" class="jscfr-field-max" value="' + esc(fld.max || '') + '" />', 'jscfr-srow-max'));
        $gen.append(settingsRow(B.i18n.step, '<input type="text" class="jscfr-field-step" value="' + esc(fld.step || '') + '" />', 'jscfr-srow-step'));

        /* Textarea rows */
        $gen.append(settingsRow(B.i18n.rows, '<input type="number" class="jscfr-field-rows" value="' + esc(fld.rows || '4') + '" min="1" style="width:80px;" />', 'jscfr-srow-rows'));

        /* Options for select/radio/checkbox/button_group */
        $gen.append(settingsRow(B.i18n.options_hint, '<textarea class="jscfr-field-opts widefat" rows="4">' + esc(fld.options || '') + '</textarea>', 'jscfr-srow-opts'));

        /* Display: inline or vertical (checkbox/radio) */
        $gen.append(settingsRow('Display', '<select class="jscfr-field-display"><option value="vertical"' + ((fld.display||'vertical')==='vertical'?' selected':'') + '>Vertical</option><option value="inline"' + (fld.display==='inline'?' selected':'') + '>Inline</option></select>', 'jscfr-srow-display'));

        /* Allow null, multiple */
        $gen.append(settingsRow(B.i18n.allow_null, '<label><input type="checkbox" class="jscfr-field-allow-null"' + (fld.allow_null ? ' checked' : '') + ' /> ' + esc(B.i18n.allow_null) + '</label>', 'jscfr-srow-allownull'));
        $gen.append(settingsRow(B.i18n.allow_multiple, '<label><input type="checkbox" class="jscfr-field-multiple"' + (fld.multiple ? ' checked' : '') + ' /> ' + esc(B.i18n.allow_multiple) + '</label>', 'jscfr-srow-multiple'));

        /* Return format for image/file */
        var rfOpts = '<select class="jscfr-field-return-format"><option value="id"' + (fld.return_format==='id'?' selected':'') + '>ID</option><option value="url"' + (fld.return_format==='url'?' selected':'') + '>URL</option><option value="array"' + (fld.return_format==='array'?' selected':'') + '>Array</option></select>';
        $gen.append(settingsRow(B.i18n.return_format, rfOpts, 'jscfr-srow-returnformat'));

        /* Preview size for image */
        var szOpts = '<select class="jscfr-field-preview-size">';
        $.each(B.image_sizes || [], function(_, s) {
            szOpts += '<option value="' + esc(s.value) + '"' + ((fld.preview_size||'thumbnail')===s.value?' selected':'') + '>' + esc(s.label) + '</option>';
        });
        szOpts += '</select>';
        $gen.append(settingsRow(B.i18n.preview_size, szOpts, 'jscfr-srow-previewsize'));

        /* MIME */
        $gen.append(settingsRow(B.i18n.mime_hint, '<input type="text" class="jscfr-field-mime widefat" value="' + esc(fld.mime || '') + '" />', 'jscfr-srow-mime'));

        /* Gallery min/max */
        $gen.append(settingsRow(B.i18n.min, '<input type="number" class="jscfr-field-min-count" value="' + esc(fld.min_count || '') + '" min="0" style="width:80px;" />', 'jscfr-srow-mincount'));
        $gen.append(settingsRow(B.i18n.max, '<input type="number" class="jscfr-field-max-count" value="' + esc(fld.max_count || '') + '" min="0" style="width:80px;" />', 'jscfr-srow-maxcount'));

        /* WYSIWYG toolbar + media */
        $gen.append(settingsRow(B.i18n.toolbar, '<select class="jscfr-field-toolbar"><option value="full"' + ((fld.toolbar||'full')==='full'?' selected':'') + '>Full</option><option value="basic"' + (fld.toolbar==='basic'?' selected':'') + '>Basic</option></select>', 'jscfr-srow-toolbar'));
        $gen.append(settingsRow(B.i18n.media_upload, '<label><input type="checkbox" class="jscfr-field-media-upload"' + (fld.media_upload!==false?' checked':'') + ' /> ' + esc(B.i18n.media_upload) + '</label>', 'jscfr-srow-mediaupload'));

        /* Post Object / Relationship: post type filter */
        $gen.append(settingsRow(
            B.i18n.post_type_filter,
            buildMultiSelect('jscfr-field-post-type', B.post_types || [], fld.post_type || [], B.i18n.post_type_filter),
            'jscfr-srow-posttype'
        ));

        /* Taxonomy field: taxonomy type + appearance */
        var taxOpts = '';
        $.each(B.taxonomies || [], function(_, tx) {
            taxOpts += '<option value="' + esc(tx.value) + '"' + ((fld.taxonomy_type||'')=== tx.value?' selected':'') + '>' + esc(tx.label) + '</option>';
        });
        $gen.append(settingsRow(B.i18n.taxonomy_type, '<select class="jscfr-field-taxonomy-type widefat">' + taxOpts + '</select>', 'jscfr-srow-taxtype'));

        var ftOpts = '<select class="jscfr-field-field-type">';
        $.each(['checkbox','radio','select','multi_select'], function(_, v) {
            ftOpts += '<option value="' + v + '"' + ((fld.field_type||'checkbox')===v?' selected':'') + '>' + v.replace('_',' ') + '</option>';
        });
        ftOpts += '</select>';
        $gen.append(settingsRow(B.i18n.field_type_tax, ftOpts, 'jscfr-srow-fieldtype'));
        $gen.append(settingsRow(B.i18n.save_terms, '<label><input type="checkbox" class="jscfr-field-save-terms"' + (fld.save_terms?' checked':'') + ' /> ' + esc(B.i18n.save_terms) + '</label>', 'jscfr-srow-saveterms'));
        $gen.append(settingsRow(B.i18n.load_terms, '<label><input type="checkbox" class="jscfr-field-load-terms"' + (fld.load_terms?' checked':'') + ' /> ' + esc(B.i18n.load_terms) + '</label>', 'jscfr-srow-loadterms'));

        /* User: role filter */
        $gen.append(settingsRow(
            B.i18n.user_role,
            buildMultiSelect('jscfr-field-role', B.roles || [], fld.role || [], B.i18n.user_role),
            'jscfr-srow-role'
        ));

        /* oEmbed */
        $gen.append(settingsRow(B.i18n.oembed_width, '<input type="number" class="jscfr-field-oembed-w" value="' + esc(fld.oembed_width||'') + '" style="width:100px;" />', 'jscfr-srow-oembedw'));
        $gen.append(settingsRow(B.i18n.oembed_height, '<input type="number" class="jscfr-field-oembed-h" value="' + esc(fld.oembed_height||'') + '" style="width:100px;" />', 'jscfr-srow-oembedh'));

        /* Message */
        $gen.append(settingsRow(B.i18n.message_text, '<textarea class="jscfr-field-message widefat" rows="4">' + esc(fld.message||'') + '</textarea>', 'jscfr-srow-message'));

        /* Date display/return format */
        $gen.append(settingsRow(B.i18n.display_format, '<input type="text" class="jscfr-field-display-format" value="' + esc(fld.display_format||'') + '" placeholder="Y-m-d" />', 'jscfr-srow-displayfmt'));
        $gen.append(settingsRow(B.i18n.return_format_dt, '<input type="text" class="jscfr-field-return-format-dt" value="' + esc(fld.return_format_dt||'') + '" placeholder="Y-m-d" />', 'jscfr-srow-returnfmtdt'));

        /* v5: Heading tag */
        $gen.append(settingsRow('Heading Tag', '<select class="jscfr-field-heading-tag"><option value="h1"' + (fld.heading_tag==='h1'?' selected':'') + '>H1</option><option value="h2"' + (fld.heading_tag==='h2'?' selected':'') + '>H2</option><option value="h3"' + (fld.heading_tag==='h3'?' selected':'') + '>H3</option><option value="h4"' + ((fld.heading_tag||'h4')==='h4'?' selected':'') + '>H4</option><option value="h5"' + (fld.heading_tag==='h5'?' selected':'') + '>H5</option><option value="h6"' + (fld.heading_tag==='h6'?' selected':'') + '>H6</option></select>', 'jscfr-srow-headingtag'));

        /* v5: Switch labels */
        $gen.append(settingsRow('On Label', '<input type="text" class="jscfr-field-on-label" value="' + esc(fld.on_label||'On') + '" style="width:120px;" />', 'jscfr-srow-onlabel'));
        $gen.append(settingsRow('Off Label', '<input type="text" class="jscfr-field-off-label" value="' + esc(fld.off_label||'Off') + '" style="width:120px;" />', 'jscfr-srow-offlabel'));

        /* v5: Custom HTML content */
        $gen.append(settingsRow('HTML Content', '<textarea class="jscfr-field-html-content widefat" rows="4">' + esc(fld.html_content||'') + '</textarea>', 'jscfr-srow-htmlcontent'));

        /* v5: Button */
        $gen.append(settingsRow('Button Label', '<input type="text" class="jscfr-field-button-label" value="' + esc(fld.button_label||'Click') + '" />', 'jscfr-srow-buttonlabel'));
        $gen.append(settingsRow('Button CSS Class', '<input type="text" class="jscfr-field-button-class widefat" value="' + esc(fld.button_class||'') + '" placeholder="e.g. button-primary" />', 'jscfr-srow-buttonclass'));

        /* v5: Image Select options (value|image_url|label per line) */
        $gen.append(settingsRow('Image Options', '<textarea class="jscfr-field-image-options widefat" rows="4" placeholder="value|https://image-url|Label">' + esc(fld.image_options||'') + '</textarea>', 'jscfr-srow-imageopts'));

        /* v5: Sub fields (fieldset_text / text_list) */
        $gen.append(settingsRow('Sub Fields', '<textarea class="jscfr-field-sub-fields widefat" rows="4" placeholder="key|Label (one per line)">' + esc(fld.sub_fields||'') + '</textarea>', 'jscfr-srow-subfields'));

        /* v5.1: Icon field: icon type */
        $gen.append(settingsRow('Icon Type', '<select class="jscfr-field-icon-type"><option value="dashicons"' + ((fld.icon_type||'dashicons')==='dashicons'?' selected':'') + '>Dashicons</option><option value="fontawesome"' + (fld.icon_type==='fontawesome'?' selected':'') + '>Font Awesome</option></select>', 'jscfr-srow-icontype'));

        $panel.append($gen);

        /* Validation tab */
        var $val = $('<div class="jscfr-fstab-panel" data-fstab="validation" style="display:none;"></div>');
        $val.append(settingsRow(B.i18n.required, '<label><input type="checkbox" class="jscfr-field-req"' + (fld.required ? ' checked' : '') + ' /> ' + esc(B.i18n.required) + '</label>'));
        $val.append(settingsRow(B.i18n.character_limit, '<input type="number" class="jscfr-field-maxlength" value="' + esc(fld.maxlength || '') + '" min="0" style="width:100px;" />', 'jscfr-srow-maxlength'));
        $val.append(settingsRow('Text Limit', '<input type="number" class="jscfr-field-limit" value="' + esc(fld.limit||'') + '" min="0" style="width:80px;" /> <select class="jscfr-field-limit-type"><option value="characters"' + ((fld.limit_type||'characters')==='characters'?' selected':'') + '>Characters</option><option value="words"' + (fld.limit_type==='words'?' selected':'') + '>Words</option></select>'));
        $panel.append($val);

        /* Presentation tab */
        var $pres = $('<div class="jscfr-fstab-panel" data-fstab="presentation" style="display:none;"></div>');
        $pres.append(settingsRow('Tooltip', '<input type="text" class="jscfr-field-tooltip widefat" value="' + esc(fld.tooltip||'') + '" placeholder="Help text on hover" />'));
        $pres.append(settingsRow('Label Description', '<input type="text" class="jscfr-field-label-desc widefat" value="' + esc(fld.label_description||'') + '" /><p class="description">Display below the field label.</p>'));
        $pres.append(settingsRow('Input Description', '<input type="text" class="jscfr-field-input-desc widefat" value="' + esc(fld.input_description||'') + '" /><p class="description">Display below the field input.</p>'));

        /* Columns: range slider + number */
        var colVal = String(fld.columns || 12);
        $pres.append(settingsRow('Columns', '<div class="jscfr-columns-slider-wrap"><input type="range" class="jscfr-field-columns-range" min="1" max="12" value="' + esc(colVal) + '" /><input type="number" class="jscfr-field-columns" min="1" max="12" value="' + esc(colVal) + '" style="width:60px;" /></div><p class="description">The number of columns for this field in a 12-column grid.</p>'));

        $pres.append(settingsRow(B.i18n.wrapper_width, '<input type="text" class="jscfr-field-wrapper-width" value="' + esc((fld.wrapper && fld.wrapper.width) || '') + '" placeholder="e.g. 50" style="width:100px;" /> %'));
        $pres.append(settingsRow(B.i18n.wrapper_class, '<input type="text" class="jscfr-field-wrapper-class widefat" value="' + esc((fld.wrapper && fld.wrapper.class) || '') + '" />'));
        $pres.append(settingsRow(B.i18n.wrapper_id, '<input type="text" class="jscfr-field-wrapper-id widefat" value="' + esc((fld.wrapper && fld.wrapper.id) || '') + '" />'));
        $pres.append(settingsRow('Admin Column', '<label><input type="checkbox" class="jscfr-field-admin-columns"' + (fld.admin_columns ? ' checked' : '') + ' /> Show as sortable column in post list</label>'));
        $panel.append($pres);

        /* Conditional Logic tab */
        var $cond = $('<div class="jscfr-fstab-panel" data-fstab="conditional" style="display:none;"></div>');
        var condEnabled = fld.conditional_logic && fld.conditional_logic.length > 0;
        $cond.append('<label class="jscfr-cond-toggle"><input type="checkbox" class="jscfr-field-cond-enabled"' + (condEnabled ? ' checked' : '') + ' /> ' + esc(B.i18n.conditional_logic) + '</label>');
        $cond.append('<div class="jscfr-cond-rules"' + (condEnabled ? '' : ' style="display:none;"') + '></div>');
        $panel.append($cond);

        /* Advanced tab (Custom Attributes, JS Options, Save Field, HTML Before/After, Sanitize Callback) */
        var $adv = $('<div class="jscfr-fstab-panel" data-fstab="advanced" style="display:none;"></div>');

        /* Custom Attributes - key/value repeater */
        var caHtml = '<div class="jscfr-ca-wrap">';
        caHtml += '<div class="jscfr-ca-list">';
        var caAttrs = fld.custom_attributes || [];
        $.each(caAttrs, function(_, attr) {
            caHtml += '<div class="jscfr-ca-row">';
            caHtml += '<input type="text" class="jscfr-ca-key" value="' + esc(attr.key || '') + '" placeholder="Attribute" />';
            caHtml += '<input type="text" class="jscfr-ca-val" value="' + esc(attr.value || '') + '" placeholder="Value" />';
            caHtml += '<button type="button" class="button jscfr-ca-remove"><span class="dashicons dashicons-no-alt"></span></button>';
            caHtml += '</div>';
        });
        caHtml += '</div>';
        caHtml += '<button type="button" class="button button-small jscfr-ca-add"><span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span> Add Attribute</button>';
        caHtml += '<p class="description">Add custom HTML attributes (e.g. disabled, readonly, data-*, pattern, etc.)</p>';
        caHtml += '</div>';
        $adv.append(settingsRow('Custom Attributes', caHtml));

        /* JS Options */
        $adv.append(settingsRow('JS Options', '<textarea class="jscfr-field-js-options widefat" rows="4" placeholder=\'{"key": "value"}\'>' + esc(fld.js_options||'') + '</textarea><p class="description">JSON options for jQuery plugins (datepicker, select2, slider, etc.)</p>'));

        /* HTML Before/After */
        $adv.append(settingsRow('HTML Before', '<textarea class="jscfr-field-html-before widefat" rows="2">' + esc(fld.html_before||'') + '</textarea>'));
        $adv.append(settingsRow('HTML After', '<textarea class="jscfr-field-html-after widefat" rows="2">' + esc(fld.html_after||'') + '</textarea>'));

        /* Save Field */
        $adv.append(settingsRow('Save Field', '<label><input type="checkbox" class="jscfr-field-save-field"' + (fld.save_field !== false ? ' checked' : '') + ' /> Save this field value to the database</label>'));

        /* Custom Sanitize Callback */
        $adv.append(settingsRow('Sanitize Callback', '<input type="text" class="jscfr-field-sanitize-cb widefat" value="' + esc(fld.sanitize_callback||'') + '" placeholder="e.g. sanitize_text_field" /><p class="description">Custom PHP function name for sanitization</p>'));

        $panel.append($adv);

        $r.append($panel);

        // Toggle type-specific visibility after building
        setTimeout(function() { toggleTypeSettings($r, fld.type); }, 0);

        // Build conditional logic rules
        if (condEnabled) {
            setTimeout(function() { buildCondRules($r, fld.conditional_logic); }, 0);
        }

        return $r;
    }

    function settingsRow(label, input, extraClass) {
        return '<div class="jscfr-settings-row' + (extraClass ? ' ' + extraClass : '') + '"><div class="jscfr-srow-label">' + esc(label) + '</div><div class="jscfr-srow-input">' + input + '</div></div>';
    }

    /* Multi-select widget: hidden <select multiple> + picker dropdown + tag chips */
    function buildMultiSelect(hiddenClass, items, selectedValues, placeholder) {
        selectedValues = selectedValues || [];
        var hidden = '<select class="' + hiddenClass + ' jscfr-bmulti-hidden" multiple style="display:none;">';
        var picker = '<select class="jscfr-bmulti-picker widefat"><option value="">' + esc(placeholder || '— Select —') + '</option>';
        var tags   = '';
        $.each(items || [], function(_, it) {
            var isSel = selectedValues && selectedValues.indexOf(it.value) !== -1;
            hidden += '<option value="' + esc(it.value) + '"' + (isSel ? ' selected' : '') + '>' + esc(it.label) + '</option>';
            picker += '<option value="' + esc(it.value) + '"' + (isSel ? ' disabled' : '') + '>' + esc(it.label) + '</option>';
            if (isSel) {
                tags += '<span class="jscfr-bmulti-tag" data-value="' + esc(it.value) + '">' + esc(it.label) + '<button type="button" class="jscfr-bmulti-tag-remove" aria-label="Remove">&times;</button></span>';
            }
        });
        hidden += '</select>';
        picker += '</select>';
        return '<div class="jscfr-bmulti-wrap">' + hidden + picker + '<div class="jscfr-bmulti-tags">' + tags + '</div></div>';
    }

    /* ================================================================ */
    /*  Toggle type-specific settings visibility                        */
    /* ================================================================ */
    function toggleTypeSettings($row, type) {
        var show = {
            'jscfr-srow-ph':           inArr(type, textLike.concat(numberLike, ['textarea','file_input'])),
            'jscfr-srow-prepend':      inArr(type, textLike.concat(numberLike)),
            'jscfr-srow-append':       inArr(type, textLike.concat(numberLike)),
            'jscfr-srow-min':          inArr(type, numberLike.concat(['slider'])),
            'jscfr-srow-max':          inArr(type, numberLike.concat(['slider'])),
            'jscfr-srow-step':         inArr(type, numberLike.concat(['slider'])),
            'jscfr-srow-rows':         type === 'textarea',
            'jscfr-srow-opts':         inArr(type, hasOptions.concat(['image_select','select_advanced','autocomplete'])),
            'jscfr-srow-allownull':    inArr(type, ['select','select_advanced','radio','post_object','taxonomy','taxonomy_advanced','user','sidebar']),
            'jscfr-srow-multiple':     inArr(type, ['select','select_advanced','post_object','user','image_select']),
            'jscfr-srow-returnformat': inArr(type, ['file','image','single_image']),
            'jscfr-srow-previewsize':  inArr(type, ['image','single_image']),
            'jscfr-srow-mime':         inArr(type, ['file','image','gallery','video']),
            'jscfr-srow-mincount':     inArr(type, ['gallery','relationship']),
            'jscfr-srow-maxcount':     inArr(type, ['gallery','relationship']),
            'jscfr-srow-toolbar':      type === 'wysiwyg',
            'jscfr-srow-mediaupload':  type === 'wysiwyg',
            'jscfr-srow-posttype':     inArr(type, ['post_object','relationship']),
            'jscfr-srow-taxtype':      inArr(type, ['taxonomy','taxonomy_advanced']),
            'jscfr-srow-fieldtype':    inArr(type, ['taxonomy','taxonomy_advanced']),
            'jscfr-srow-saveterms':    type === 'taxonomy',
            'jscfr-srow-loadterms':    type === 'taxonomy',
            'jscfr-srow-role':         type === 'user',
            'jscfr-srow-oembedw':      type === 'oembed',
            'jscfr-srow-oembedh':      type === 'oembed',
            'jscfr-srow-message':      inArr(type, ['message','custom_html']),
            'jscfr-srow-displayfmt':   inArr(type, dateLike),
            'jscfr-srow-returnfmtdt':  inArr(type, dateLike),
            'jscfr-srow-maxlength':    inArr(type, textLike.concat(['textarea'])),
            // v5 new settings
            'jscfr-srow-headingtag':   type === 'heading',
            'jscfr-srow-onlabel':      type === 'switch',
            'jscfr-srow-offlabel':     type === 'switch',
            'jscfr-srow-htmlcontent':  type === 'custom_html',
            'jscfr-srow-buttonlabel':  type === 'button',
            'jscfr-srow-buttonclass':  type === 'button',
            'jscfr-srow-imageopts':    type === 'image_select',
            'jscfr-srow-subfields':    inArr(type, compositeTypes),
            // v5.1 new settings
            'jscfr-srow-icontype':     type === 'icon',
            'jscfr-srow-display':      inArr(type, ['checkbox','radio']),
        };
        $.each(show, function(cls, visible) {
            $row.find('.' + cls)[visible ? 'show' : 'hide']();
        });
    }

    /* ================================================================ */
    /*  Conditional Logic builder                                       */
    /* ================================================================ */
    function getAllFieldIds() {
        var fields = [];
        $tabs.find('.jscfr-field-row').each(function() {
            var $f = $(this);
            fields.push({ id: $f.data('id'), label: $f.find('.jscfr-field-label').val() || $f.data('id') });
        });
        return fields;
    }

    function buildCondRules($row, rules) {
        var $container = $row.find('.jscfr-cond-rules').empty();
        if (!rules || !rules.length) return;
        var fields = getAllFieldIds();

        $.each(rules, function(oi, orGroup) {
            if (oi > 0) $container.append('<div class="jscfr-cond-or">or</div>');
            var $og = $('<div class="jscfr-cond-or-group"></div>');
            $.each(orGroup, function(ai, rule) {
                var $rr = $('<div class="jscfr-cond-rule"></div>');
                if (ai > 0) $rr.append('<span class="jscfr-cond-and">and</span>');

                var fieldSel = '<select class="jscfr-cond-field">';
                $.each(fields, function(_, f) {
                    fieldSel += '<option value="' + esc(f.id) + '"' + (f.id === rule.field ? ' selected' : '') + '>' + esc(f.label) + '</option>';
                });
                fieldSel += '</select>';
                $rr.append(fieldSel);

                $rr.append('<select class="jscfr-cond-op"><option value="=="' + (rule.operator==='=='?' selected':'') + '>=</option><option value="!="' + (rule.operator==='!='?' selected':'') + '>!=</option><option value="==empty"' + (rule.operator==='==empty'?' selected':'') + '>empty</option><option value="!=empty"' + (rule.operator==='!=empty'?' selected':'') + '>not empty</option></select>');
                $rr.append('<input type="text" class="jscfr-cond-val" value="' + esc(rule.value || '') + '" />');
                $rr.append('<button type="button" class="jscfr-cond-remove">&times;</button>');
                $og.append($rr);
            });
            $og.append('<button type="button" class="button button-small jscfr-cond-add-and">+ and</button>');
            $container.append($og);
        });
        $container.append('<button type="button" class="button button-small jscfr-cond-add-or">+ or</button>');
    }

    /* ================================================================ */
    /*  LOCATION RULES                                                  */
    /* ================================================================ */
    function renderLocationRules() {
        var $lr = $('#jscfr-location-rules').empty();
        var rules = FG.location_rules || [];
        if (!rules.length) {
            rules = [[ { param: 'post_type', operator: 'is_equal_to', value: 'post' } ]];
        }

        $.each(rules, function (oi, orGroup) {
            if (oi > 0) $lr.append('<div class="jscfr-or-divider">' + esc(B.i18n.or) + '</div>');
            var $og = $('<div class="jscfr-or-group" data-or="' + oi + '"></div>');
            $.each(orGroup, function (ai, rule) {
                $og.append(buildRuleRow(rule, ai > 0));
            });
            $og.append('<button type="button" class="button button-small jscfr-add-and"><span class="dashicons dashicons-plus" style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span> ' + esc(B.i18n.and) + '</button>');
            $lr.append($og);
        });

        $lr.append('<div class="jscfr-or-add-wrap"><button type="button" class="button jscfr-add-or"><span class="dashicons dashicons-plus-alt2" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:2px;"></span> ' + esc(B.i18n.add_rule_group) + '</button></div>');
    }

    function buildRuleRow(rule, showAndLabel) {
        var $row = $('<div class="jscfr-rule-row"></div>');
        if (showAndLabel) $row.append('<span class="jscfr-and-label">' + esc(B.i18n.and) + '</span>');

        var paramOpts = '';
        $.each(LP, function (key, obj) {
            paramOpts += '<option value="' + esc(key) + '"' + (rule.param === key ? ' selected' : '') + '>' + esc(obj.label) + '</option>';
        });
        $row.append('<select class="jscfr-rule-param">' + paramOpts + '</select>');
        $row.append('<select class="jscfr-rule-operator"><option value="is_equal_to"' + (rule.operator === 'is_equal_to' ? ' selected' : '') + '>is equal to</option><option value="is_not_equal_to"' + (rule.operator === 'is_not_equal_to' ? ' selected' : '') + '>is not equal to</option></select>');
        $row.append(buildValueControl(rule.param, rule.value));
        $row.append('<button type="button" class="jscfr-rule-remove" title="Remove"><span class="dashicons dashicons-no-alt"></span></button>');
        return $row;
    }

    function buildValueControl(param, value) {
        var info = LP[param];
        if (!info) return '<input type="text" class="jscfr-rule-value" value="" />';
        if (info.choices === 'text_input') return '<input type="text" class="jscfr-rule-value" value="' + esc(value || '') + '" placeholder="Post ID" />';
        var html = '<select class="jscfr-rule-value">';
        $.each(info.choices, function (_, ch) {
            html += '<option value="' + esc(ch.value) + '"' + (ch.value === value ? ' selected' : '') + '>' + esc(ch.label) + '</option>';
        });
        html += '</select>';
        return html;
    }

    /* ================================================================ */
    /*  SETTINGS                                                        */
    /* ================================================================ */
    function populateSettings() {
        var s = FG.settings || {};
        $('#jscfr-set-position').val(s.position || 'normal');
        // Style — pill radios
        var styleVal = s.style || 'default';
        $('input[name="jscfr_style"][value="' + styleVal + '"]').prop('checked', true);
        $('#jscfr-set-style').val(styleVal);
        // Priority — pill radios
        var prioVal = s.priority || 'high';
        $('input[name="jscfr_priority"][value="' + prioVal + '"]').prop('checked', true);
        $('#jscfr-set-label').val(s.label_placement || 'top');
        $('#jscfr-set-tab-placement').val(s.tab_placement || 'top');
        $('#jscfr-set-active').prop('checked', s.active !== false);
        $('#jscfr-set-description').val(s.description || '');
        $('#jscfr-set-description-vis').val(s.description || '');
        $('#jscfr-set-order').val(s.order || 0);
        $('#jscfr-set-order-vis').val(s.order || 0);
        $('#jscfr-set-include').val(s.include || '');
        $('#jscfr-set-include-vis').val(s.include || '');
        $('#jscfr-set-exclude').val(s.exclude || '');
        $('#jscfr-set-exclude-vis').val(s.exclude || '');
        $('#jscfr-set-revision').prop('checked', !!s.revision);

        // Toggle rules
        var toggleType = s.toggle_type || 'show';
        $('input[name="jscfr_toggle_type"][value="' + toggleType + '"]').prop('checked', true);
        if (s.toggle_rules && s.toggle_rules.length) {
            $.each(s.toggle_rules, function (i, rule) {
                addToggleRuleRow(rule);
            });
        }

        // Conditional logic (field group level)
        if (s.fg_conditional_logic && s.fg_conditional_logic.length) {
            $('#jscfr-set-fg-cond-enabled').prop('checked', true);
            $('#jscfr-fg-cond-rules').show();
            $.each(s.fg_conditional_logic, function (i, rule) {
                addFgCondRuleRow(rule);
            });
        }

        // Tab settings
        var tabStyle = s.tab_style || 'default';
        $('input[name="jscfr_tab_style"][value="' + tabStyle + '"]').prop('checked', true);
        $('#jscfr-set-tab-remember').prop('checked', !!s.tab_remember);
        $('#jscfr-set-tab-default').val(s.tab_default || 0);

        // Custom table
        $('#jscfr-set-custom-table').prop('checked', !!s.custom_table);
        if (s.custom_table) {
            $('#jscfr-custom-table-opts').show();
        }
        $('#jscfr-set-table-name').val(s.table_name || '');
        $('#jscfr-set-table-create').prop('checked', s.table_create !== false);

        // Advanced extras
        $('#jscfr-set-custom-class').val(s.custom_class || '');
        $('#jscfr-set-prefix').val(s.prefix || '');
        $('#jscfr-set-text-domain').val(s.text_domain || '');
        $('#jscfr-set-autosave').prop('checked', !!s.autosave);
        $('#jscfr-set-collapsed').prop('checked', !!s.collapsed);
        $('#jscfr-set-hidden').prop('checked', !!s.hidden);
    }

    /* ================================================================ */
    /*  POST BADGE                                                      */
    /* ================================================================ */
    function updatePostBadge() {
        var $badge = $('.jscfr-topbar-post-badge');
        if (!$badge.length) return;
        var firstRule = $('#jscfr-location-rules .jscfr-rule-row:first');
        if (firstRule.length) {
            var param = firstRule.find('.jscfr-rule-param').val();
            var value = firstRule.find('.jscfr-rule-value option:selected').text() || firstRule.find('.jscfr-rule-value').val();
            if (param === 'post_type' && value) {
                $badge.text(value);
            } else if (param) {
                $badge.text(param.replace(/_/g, ' '));
            }
        }
    }

    /* ================================================================ */
    /*  COLLECT all data from DOM                                       */
    /* ================================================================ */
    function collect() {
        var data = {
            id:    FG.id,
            title: $('#jscfr-fg-title').val(),
            tabs:  [],
            location_rules: [],
            settings: {}
        };

        $tabs.find('.jscfr-tab-card').each(function () {
            var $t = $(this);
            var tab = {
                id: $t.data('id'),
                label: $t.find('> .jscfr-tab-head .jscfr-tab-label').val(),
                name: $t.find('> .jscfr-tab-head .jscfr-tab-name').val() || slugify($t.find('> .jscfr-tab-head .jscfr-tab-label').val()),
                icon: $t.find('> .jscfr-tab-head .jscfr-tab-icon-value').val() || '',
                icon_type: $t.find('> .jscfr-tab-head .jscfr-tab-icon-type').val() || 'dashicons',
                groups: []
            };
            $t.find('.jscfr-group-card').each(function () {
                var $g = $(this);
                var grp = {
                    id:       $g.data('id'),
                    label:    $g.find('> .jscfr-group-head .jscfr-group-label').val(),
                    name:     $g.find('> .jscfr-group-head .jscfr-group-name').val() || slugify($g.find('> .jscfr-group-head .jscfr-group-label').val()),
                    clonable: $g.find('> .jscfr-group-head .jscfr-group-clonable').is(':checked'),
                    min:      $g.find('> .jscfr-group-head .jscfr-group-min').val() || '',
                    max:      $g.find('> .jscfr-group-head .jscfr-group-max').val() || '',
                    layout:   $g.find('> .jscfr-group-head .jscfr-group-layout').val() || 'block',
                    fields:   []
                };
                $g.find('.jscfr-field-row').each(function () {
                    var $f = $(this);
                    var fld = {
                        id:             $f.data('id'),
                        label:          $f.find('.jscfr-field-label').val(),
                        name:           $f.find('.jscfr-field-name').val() || slugify($f.find('.jscfr-field-label').val()),
                        type:           $f.find('.jscfr-field-type').val(),
                        instructions:   $f.find('.jscfr-field-instructions').val(),
                        required:       $f.find('.jscfr-field-req').is(':checked'),
                        default_value:  $f.find('.jscfr-field-default').val(),
                        placeholder:    $f.find('.jscfr-field-ph').val(),
                        wrapper: {
                            width: $f.find('.jscfr-field-wrapper-width').val(),
                            class: $f.find('.jscfr-field-wrapper-class').val(),
                            id:    $f.find('.jscfr-field-wrapper-id').val()
                        },
                        prepend:        $f.find('.jscfr-field-prepend').val(),
                        append:         $f.find('.jscfr-field-append').val(),
                        maxlength:      $f.find('.jscfr-field-maxlength').val(),
                        min:            $f.find('.jscfr-field-min').val(),
                        max:            $f.find('.jscfr-field-max').val(),
                        step:           $f.find('.jscfr-field-step').val(),
                        rows:           parseInt($f.find('.jscfr-field-rows').val(), 10) || 4,
                        options:        $f.find('.jscfr-field-opts').val(),
                        allow_null:     $f.find('.jscfr-field-allow-null').is(':checked'),
                        multiple:       $f.find('.jscfr-field-multiple').is(':checked'),
                        return_format:  $f.find('.jscfr-field-return-format').val(),
                        preview_size:   $f.find('.jscfr-field-preview-size').val(),
                        mime:           $f.find('.jscfr-field-mime').val(),
                        min_count:      $f.find('.jscfr-field-min-count').val(),
                        max_count:      $f.find('.jscfr-field-max-count').val(),
                        toolbar:        $f.find('.jscfr-field-toolbar').val(),
                        media_upload:   $f.find('.jscfr-field-media-upload').is(':checked'),
                        post_type:      $f.find('.jscfr-field-post-type').val() || [],
                        taxonomy_type:  $f.find('.jscfr-field-taxonomy-type').val(),
                        field_type:     $f.find('.jscfr-field-field-type').val(),
                        save_terms:     $f.find('.jscfr-field-save-terms').is(':checked'),
                        load_terms:     $f.find('.jscfr-field-load-terms').is(':checked'),
                        role:           $f.find('.jscfr-field-role').val() || [],
                        display_format:  $f.find('.jscfr-field-display-format').val(),
                        return_format_dt: $f.find('.jscfr-field-return-format-dt').val(),
                        oembed_width:   $f.find('.jscfr-field-oembed-w').val(),
                        oembed_height:  $f.find('.jscfr-field-oembed-h').val(),
                        message:        $f.find('.jscfr-field-message').val(),
                        link_target:    false,
                        conditional_logic: collectCondLogic($f),
                        // v5 settings
                        heading_tag: $f.find('.jscfr-field-heading-tag').val() || 'h4',
                        on_label: $f.find('.jscfr-field-on-label').val() || 'On',
                        off_label: $f.find('.jscfr-field-off-label').val() || 'Off',
                        html_content: $f.find('.jscfr-field-html-content').val() || '',
                        button_label: $f.find('.jscfr-field-button-label').val() || 'Click',
                        button_class: $f.find('.jscfr-field-button-class').val() || '',
                        image_options: $f.find('.jscfr-field-image-options').val() || '',
                        image_select_multiple: $f.find('.jscfr-field-multiple').is(':checked'),
                        sub_fields: $f.find('.jscfr-field-sub-fields').val() || '',
                        display: $f.find('.jscfr-field-display').val() || 'vertical',
                        admin_columns: $f.find('.jscfr-field-admin-columns').is(':checked'),
                        tooltip: $f.find('.jscfr-field-tooltip').val() || '',
                        label_description: $f.find('.jscfr-field-label-desc').val() || '',
                        input_description: $f.find('.jscfr-field-input-desc').val() || '',
                        columns: $f.find('.jscfr-field-columns').val() || '',
                        limit: $f.find('.jscfr-field-limit').val() || '',
                        limit_type: $f.find('.jscfr-field-limit-type').val() || 'characters',
                        // v5.1 Advanced settings
                        icon_type: $f.find('.jscfr-field-icon-type').val() || 'dashicons',
                        custom_attributes: collectCustomAttrs($f),
                        js_options: $f.find('.jscfr-field-js-options').val() || '',
                        save_field: $f.find('.jscfr-field-save-field').is(':checked'),
                        html_before: $f.find('.jscfr-field-html-before').val() || '',
                        html_after: $f.find('.jscfr-field-html-after').val() || '',
                        sanitize_callback: $f.find('.jscfr-field-sanitize-cb').val() || ''
                    };
                    grp.fields.push(fld);
                });
                tab.groups.push(grp);
            });
            data.tabs.push(tab);
        });

        // Location rules
        $('#jscfr-location-rules .jscfr-or-group').each(function () {
            var orGroup = [];
            $(this).find('.jscfr-rule-row').each(function () {
                orGroup.push({
                    param:    $(this).find('.jscfr-rule-param').val(),
                    operator: $(this).find('.jscfr-rule-operator').val(),
                    value:    $(this).find('.jscfr-rule-value').val() || ''
                });
            });
            if (orGroup.length) data.location_rules.push(orGroup);
        });

        // Settings
        data.settings = {
            position:        $('#jscfr-set-position').val(),
            style:           $('input[name="jscfr_style"]:checked').val() || 'default',
            priority:        $('input[name="jscfr_priority"]:checked').val() || 'high',
            label_placement: $('#jscfr-set-label').val(),
            tab_placement:   $('#jscfr-set-tab-placement').val(),
            active:          $('#jscfr-set-active').is(':checked'),
            description:     $('#jscfr-set-description-vis').val() || $('#jscfr-set-description').val(),
            order:           parseInt($('#jscfr-set-order-vis').val(), 10) || parseInt($('#jscfr-set-order').val(), 10) || 0,
            include:         $('#jscfr-set-include-vis').val() || $('#jscfr-set-include').val(),
            exclude:         $('#jscfr-set-exclude-vis').val() || $('#jscfr-set-exclude').val(),
            revision:        $('#jscfr-set-revision').is(':checked'),
            // Toggle rules
            toggle_type:     $('input[name="jscfr_toggle_type"]:checked').val() || 'show',
            toggle_rules:    collectToggleRules(),
            // Conditional logic (field group level)
            fg_conditional_logic: collectFgCondLogic(),
            // Tab settings
            tab_style:       $('input[name="jscfr_tab_style"]:checked').val() || 'default',
            tab_remember:    $('#jscfr-set-tab-remember').is(':checked'),
            tab_default:     parseInt($('#jscfr-set-tab-default').val(), 10) || 0,
            // Custom table
            custom_table:    $('#jscfr-set-custom-table').is(':checked'),
            table_name:      $('#jscfr-set-table-name').val() || '',
            table_create:    $('#jscfr-set-table-create').is(':checked'),
            // Advanced extras
            custom_class:    $('#jscfr-set-custom-class').val() || '',
            prefix:          $('#jscfr-set-prefix').val() || '',
            text_domain:     $('#jscfr-set-text-domain').val() || '',
            autosave:        $('#jscfr-set-autosave').is(':checked'),
            collapsed:       $('#jscfr-set-collapsed').is(':checked'),
            hidden:          $('#jscfr-set-hidden').is(':checked')
        };

        return data;
    }

    function collectCondLogic($fieldRow) {
        if (!$fieldRow.find('.jscfr-field-cond-enabled').is(':checked')) return [];
        var rules = [];
        $fieldRow.find('.jscfr-cond-or-group').each(function() {
            var orGroup = [];
            $(this).find('.jscfr-cond-rule').each(function() {
                orGroup.push({
                    field:    $(this).find('.jscfr-cond-field').val(),
                    operator: $(this).find('.jscfr-cond-op').val(),
                    value:    $(this).find('.jscfr-cond-val').val()
                });
            });
            if (orGroup.length) rules.push(orGroup);
        });
        return rules;
    }

    /* ================================================================ */
    /*  Toggle Rules helpers                                            */
    /* ================================================================ */
    function addToggleRuleRow(rule) {
        rule = rule || { field: '', operator: '==', value: '' };
        var html = '<div class="jscfr-toggle-rule-row" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;">';
        html += '<select class="jscfr-toggle-rule-field jscfr-sb-select" style="flex:1;">';
        html += '<option value="">— Select —</option>';
        // Populate from all fields in the current field group
        $tabs.find('.jscfr-field-row').each(function () {
            var fName = $(this).find('.jscfr-field-name').val();
            var fLabel = $(this).find('.jscfr-field-label').val() || fName;
            if (fName) {
                html += '<option value="' + esc(fName) + '"' + (rule.field === fName ? ' selected' : '') + '>' + esc(fLabel) + '</option>';
            }
        });
        html += '</select>';
        html += '<select class="jscfr-toggle-rule-op jscfr-sb-select" style="width:60px;">';
        html += '<option value="==" ' + (rule.operator === '==' ? 'selected' : '') + '>=</option>';
        html += '<option value="!=" ' + (rule.operator === '!=' ? 'selected' : '') + '>!=</option>';
        html += '<option value=">" ' + (rule.operator === '>' ? 'selected' : '') + '>></option>';
        html += '<option value="<" ' + (rule.operator === '<' ? 'selected' : '') + '><</option>';
        html += '</select>';
        html += '<input type="text" class="jscfr-toggle-rule-val jscfr-sb-input" style="flex:1;" value="' + esc(rule.value) + '" placeholder="Value" />';
        html += '<button type="button" class="button jscfr-remove-toggle-rule" title="Remove" style="padding:0 6px;color:#b32d2e;">&times;</button>';
        html += '</div>';
        $('#jscfr-toggle-rules-list').append(html);
    }

    function collectToggleRules() {
        var rules = [];
        $('#jscfr-toggle-rules-list .jscfr-toggle-rule-row').each(function () {
            var field = $(this).find('.jscfr-toggle-rule-field').val();
            if (field) {
                rules.push({
                    field:    field,
                    operator: $(this).find('.jscfr-toggle-rule-op').val(),
                    value:    $(this).find('.jscfr-toggle-rule-val').val()
                });
            }
        });
        return rules;
    }

    /* ================================================================ */
    /*  Field Group Conditional Logic helpers                            */
    /* ================================================================ */
    function addFgCondRuleRow(rule) {
        rule = rule || { field: '', operator: '==', value: '' };
        var html = '<div class="jscfr-fg-cond-rule-row" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;">';
        html += '<select class="jscfr-fg-cond-rule-field jscfr-sb-select" style="flex:1;">';
        html += '<option value="">— Select —</option>';
        $tabs.find('.jscfr-field-row').each(function () {
            var fName = $(this).find('.jscfr-field-name').val();
            var fLabel = $(this).find('.jscfr-field-label').val() || fName;
            if (fName) {
                html += '<option value="' + esc(fName) + '"' + (rule.field === fName ? ' selected' : '') + '>' + esc(fLabel) + '</option>';
            }
        });
        html += '</select>';
        html += '<select class="jscfr-fg-cond-rule-op jscfr-sb-select" style="width:60px;">';
        html += '<option value="==" ' + (rule.operator === '==' ? 'selected' : '') + '>=</option>';
        html += '<option value="!=" ' + (rule.operator === '!=' ? 'selected' : '') + '>!=</option>';
        html += '<option value="==empty" ' + (rule.operator === '==empty' ? 'selected' : '') + '>Empty</option>';
        html += '<option value="!=empty" ' + (rule.operator === '!=empty' ? 'selected' : '') + '>Not empty</option>';
        html += '</select>';
        html += '<input type="text" class="jscfr-fg-cond-rule-val jscfr-sb-input" style="flex:1;" value="' + esc(rule.value) + '" placeholder="Value" />';
        html += '<button type="button" class="button jscfr-remove-fg-cond-rule" title="Remove" style="padding:0 6px;color:#b32d2e;">&times;</button>';
        html += '</div>';
        $('#jscfr-fg-cond-rules-list').append(html);
    }

    function collectFgCondLogic() {
        if (!$('#jscfr-set-fg-cond-enabled').is(':checked')) return [];
        var rules = [];
        $('#jscfr-fg-cond-rules-list .jscfr-fg-cond-rule-row').each(function () {
            var field = $(this).find('.jscfr-fg-cond-rule-field').val();
            if (field) {
                rules.push({
                    field:    field,
                    operator: $(this).find('.jscfr-fg-cond-rule-op').val(),
                    value:    $(this).find('.jscfr-fg-cond-rule-val').val()
                });
            }
        });
        return rules;
    }

    /* ================================================================ */
    /*  TOAST NOTIFICATION                                              */
    /* ================================================================ */
    function showToast(message, type) {
        type = type || 'success'; // success | error | info
        var icons = {
            success: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="10" fill="#22c55e"/><path d="M6 10.5l2.5 2.5L14 7.5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            error:   '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="10" fill="#ef4444"/><path d="M7 7l6 6M13 7l-6 6" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
            info:    '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="10" fill="#3b82f6"/><path d="M10 9v4M10 7h.01" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>'
        };

        // Ensure container exists
        if (!$('#jscfr-toast-container').length) {
            $('body').append('<div id="jscfr-toast-container" class="jscfr-toast-container"></div>');
        }

        var $toast = $('<div class="jscfr-toast jscfr-toast--' + type + '">' +
            '<span class="jscfr-toast-icon">' + icons[type] + '</span>' +
            '<span class="jscfr-toast-msg">' + $('<span>').text(message).html() + '</span>' +
            '<button type="button" class="jscfr-toast-close">&times;</button>' +
            '</div>');

        $('#jscfr-toast-container').append($toast);

        // Trigger animation
        setTimeout(function () { $toast.addClass('jscfr-toast--visible'); }, 10);

        // Auto dismiss
        var timer = setTimeout(function () { dismissToast($toast); }, 4000);

        // Close button
        $toast.find('.jscfr-toast-close').on('click', function () {
            clearTimeout(timer);
            dismissToast($toast);
        });
    }

    function dismissToast($toast) {
        $toast.removeClass('jscfr-toast--visible');
        setTimeout(function () { $toast.remove(); }, 300);
    }

    /* ================================================================ */
    /*  SAVE                                                            */
    /* ================================================================ */
    function save() {
        var data = collect();
        var $btn = $('.jscfr-save-fg');
        var origText = $btn.first().html();
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update jscfr-spin" style="vertical-align:middle;margin-right:4px;"></span> Saving\u2026');

        $.post(B.ajax_url, {
            action: 'jscfr_save_field_group',
            nonce:  B.nonce,
            field_group: JSON.stringify(data)
        }, function (res) {
            $btn.prop('disabled', false).html(origText);
            if (res.success) {
                FG = res.data.field_group;
                showToast(B.i18n.saved, 'success');
                if (res.data.redirect && window.location.href.indexOf('fg_id=new') !== -1) {
                    window.history.replaceState(null, '', res.data.redirect);
                }
            } else {
                showToast(B.i18n.save_error, 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false).html(origText);
            showToast(B.i18n.save_error, 'error');
        });
    }

    /* ================================================================ */
    /*  Sortables                                                       */
    /* ================================================================ */
    function initSortables() {
        $tabs.sortable({ handle: '> .jscfr-tab-head .jscfr-drag', items: '> .jscfr-tab-card', placeholder: 'jscfr-sortable-ph', tolerance: 'pointer' });
        $tabs.find('.jscfr-groups-container').sortable({ handle: '> .jscfr-group-card > .jscfr-group-head .jscfr-drag', items: '> .jscfr-group-card', placeholder: 'jscfr-sortable-ph', tolerance: 'pointer', connectWith: '.jscfr-groups-container' });
        $tabs.find('.jscfr-fields-container').sortable({ handle: '> .jscfr-field-row .jscfr-drag', items: '> .jscfr-field-row', placeholder: 'jscfr-sortable-ph', tolerance: 'pointer', connectWith: '.jscfr-fields-container' });
    }

    /* ================================================================ */
    /*  DOM ready                                                       */
    /* ================================================================ */
    $(function () {
        $tabs = $('#jscfr-tabs-container');

        renderTabs();
        renderLocationRules();
        populateSettings();
        updatePostBadge();

        /* --- Sync visible advanced fields to hidden fields --- */
        $(document).on('input change', '#jscfr-set-description-vis', function () { $('#jscfr-set-description').val($(this).val()); });
        $(document).on('input change', '#jscfr-set-order-vis', function () { $('#jscfr-set-order').val($(this).val()); });
        $(document).on('input change', '#jscfr-set-include-vis', function () { $('#jscfr-set-include').val($(this).val()); });
        $(document).on('input change', '#jscfr-set-exclude-vis', function () { $('#jscfr-set-exclude').val($(this).val()); });
        /* --- Sync style pill to hidden field --- */
        $(document).on('change', 'input[name="jscfr_style"]', function () { $('#jscfr-set-style').val($(this).val()); });

        /* --- Builder multi-select widget: picker → tag --- */
        $(document).on('change', '.jscfr-bmulti-picker', function () {
            var val = $(this).val();
            if (!val) return;
            var $wrap = $(this).closest('.jscfr-bmulti-wrap');
            var $hidden = $wrap.find('.jscfr-bmulti-hidden');
            var $tags = $wrap.find('.jscfr-bmulti-tags');
            var $opt = $(this).find('option:selected');
            var label = $opt.text();

            if ($hidden.find('option[value="' + val + '"]').prop('selected')) {
                $(this).val('');
                return;
            }
            $hidden.find('option[value="' + val + '"]').prop('selected', true);
            $tags.append('<span class="jscfr-bmulti-tag" data-value="' + $('<span>').text(val).html() + '">' + $('<span>').text(label).html() + '<button type="button" class="jscfr-bmulti-tag-remove" aria-label="Remove">&times;</button></span>');
            $opt.prop('disabled', true);
            $(this).val('');
        });

        $(document).on('click', '.jscfr-bmulti-tag-remove', function (e) {
            e.preventDefault();
            var $tag = $(this).closest('.jscfr-bmulti-tag');
            var $wrap = $tag.closest('.jscfr-bmulti-wrap');
            var val = $tag.attr('data-value');
            $wrap.find('.jscfr-bmulti-hidden option[value="' + val + '"]').prop('selected', false);
            $wrap.find('.jscfr-bmulti-picker option[value="' + val + '"]').prop('disabled', false);
            $tag.remove();
        });

        /* --- Toggle rules: add/remove --- */
        $(document).on('click.jscfr', '.jscfr-add-toggle-rule', function () { addToggleRuleRow(); });
        $(document).on('click.jscfr', '.jscfr-remove-toggle-rule', function () { $(this).closest('.jscfr-toggle-rule-row').remove(); });

        /* --- FG Conditional logic: enable toggle, add/remove --- */
        $(document).on('change.jscfr', '#jscfr-set-fg-cond-enabled', function () {
            $('#jscfr-fg-cond-rules').toggle($(this).is(':checked'));
        });
        $(document).on('click.jscfr', '.jscfr-add-fg-cond-rule', function () { addFgCondRuleRow(); });
        $(document).on('click.jscfr', '.jscfr-remove-fg-cond-rule', function () { $(this).closest('.jscfr-fg-cond-rule-row').remove(); });

        /* --- Custom table: toggle options panel --- */
        $(document).on('change.jscfr', '#jscfr-set-custom-table', function () {
            $('#jscfr-custom-table-opts').toggle($(this).is(':checked'));
        });

        /* --- Sidebar section toggle --- */
        $(document).on('click.jscfr', '.jscfr-sidebar-section-header', function () {
            var target = $(this).data('toggle');
            $('#' + target).slideToggle(200);
            $(this).find('.jscfr-sidebar-arrow').toggleClass('jscfr-sidebar-arrow--down');
            $(this).closest('.jscfr-sidebar-section').toggleClass('jscfr-sidebar-section--open');
        });

        /* --- Toggle sidebar visibility --- */
        $(document).on('click.jscfr', '.jscfr-topbar-toggle-sidebar', function () {
            $('.jscfr-edit-columns').toggleClass('jscfr-sidebar-hidden');
            $(this).toggleClass('jscfr-topbar-icon--active');
        });

        /* --- Collapse / expand sidebar to icon-only --- */
        $(document).on('click.jscfr', '.jscfr-sidebar-collapse-btn, .jscfr-sidebar-expand-btn', function () {
            $('.jscfr-edit-columns').toggleClass('jscfr-sidebar-collapsed');
        });

        /* --- Legacy section toggle (kept for any remaining use) --- */
        $(document).on('click.jscfr', '.jscfr-section-header', function () {
            var target = $(this).data('toggle');
            $('#' + target).slideToggle(200);
            $(this).find('.jscfr-section-arrow').toggleClass('jscfr-arrow-down');
        });

        /* --- Field settings sub-tabs --- */
        $(document).on('click.jscfr', '.jscfr-fstab-btn', function (e) {
            e.preventDefault();
            var $panel = $(this).closest('.jscfr-field-settings');
            $(this).siblings().removeClass('jscfr-fstab-active');
            $(this).addClass('jscfr-fstab-active');
            $panel.find('.jscfr-fstab-panel').hide();
            $panel.find('.jscfr-fstab-panel[data-fstab="' + $(this).data('fstab') + '"]').show();
        });

        /* --- Toggle field settings panel --- */
        $(document).on('click.jscfr', '.jscfr-toggle-field-settings', function () {
            var $row = $(this).closest('.jscfr-field-row');
            $row.toggleClass('is-open');
            $row.find('.jscfr-field-settings').slideToggle(200);
        });

        /* --- Add Tab --- */
        $(document).on('click.jscfr', '#jscfr-add-tab', function () {
            var tab = { id: uid('tab'), name: '', label: B.i18n.new_tab, groups: [] };
            $tabs.removeAttr('data-empty');
            $tabs.append(buildTabCard(tab));
            initSortables();
            $tabs.find('.jscfr-tab-card:last .jscfr-tab-label').focus().select();
        });

        /* --- Del Tab --- */
        $(document).on('click.jscfr', '.jscfr-del-tab', function () {
            if (!confirm(B.i18n.confirm_delete)) return;
            $(this).closest('.jscfr-tab-card').fadeOut(200, function () {
                $(this).remove();
                if (!$tabs.children().length) $tabs.attr('data-empty', B.i18n.no_tabs);
            });
        });

        /* --- Duplicate Tab --- */
        $(document).on('click.jscfr', '.jscfr-dup-tab', function () {
            var $orig = $(this).closest('.jscfr-tab-card');
            var tabData = collectTabData($orig);
            tabData.id = uid('tab');
            tabData.label += ' (Copy)';
            tabData.name = '';
            tabData.groups.forEach(function(g) {
                g.id = uid('grp'); g.name = '';
                g.fields.forEach(function(f) { f.id = uid('fld'); f.name = ''; });
            });
            $orig.after(buildTabCard(tabData));
            initSortables();
        });

        /* --- Toggle Tab --- */
        $(document).on('click.jscfr', '.jscfr-toggle-tab', function () {
            $(this).closest('.jscfr-tab-card').find('.jscfr-tab-body').slideToggle(200);
        });

        /* --- Add Group --- */
        $(document).on('click.jscfr', '.jscfr-add-group', function () {
            var grp = { id: uid('grp'), name: '', label: B.i18n.new_group, min: '', max: '', layout: 'block', fields: [] };
            var $gc = $(this).siblings('.jscfr-groups-container');
            $gc.append(buildGroupCard(grp));
            initSortables();
            $gc.find('.jscfr-group-card:last .jscfr-group-label').focus().select();
        });

        /* --- Del Group --- */
        $(document).on('click.jscfr', '.jscfr-del-group', function () {
            if (!confirm(B.i18n.confirm_delete)) return;
            $(this).closest('.jscfr-group-card').fadeOut(200, function () { $(this).remove(); });
        });

        /* --- Duplicate Group --- */
        $(document).on('click.jscfr', '.jscfr-dup-group', function () {
            var $orig = $(this).closest('.jscfr-group-card');
            var grpData = collectGroupData($orig);
            grpData.id = uid('grp'); grpData.name = ''; grpData.label += ' (Copy)';
            grpData.fields.forEach(function(f) { f.id = uid('fld'); f.name = ''; });
            $orig.after(buildGroupCard(grpData));
            initSortables();
        });

        /* --- Toggle Group --- */
        $(document).on('click.jscfr', '.jscfr-toggle-group', function () {
            $(this).closest('.jscfr-group-card').find('.jscfr-group-body').slideToggle(200);
        });

        /* --- Clonable toggle --- */
        $(document).on('change.jscfr', '.jscfr-group-clonable', function () {
            var $head = $(this).closest('.jscfr-group-head');
            var show = $(this).is(':checked');
            $head.find('.jscfr-gop-row--pair').toggle(show);
        });

        /* --- Group options popover --- */
        function closeAllGroupOptionPops() {
            $('.jscfr-group-options-pop').prop('hidden', true);
            $('.jscfr-group-options-btn').attr('aria-expanded', 'false');
            $('.jscfr-group-card.jscfr-has-pop, .jscfr-tab-card.jscfr-has-pop').removeClass('jscfr-has-pop');
        }
        $(document).on('click.jscfr', '.jscfr-group-options-btn', function (e) {
            e.stopPropagation();
            var $btn = $(this);
            var $pop = $btn.siblings('.jscfr-group-options-pop');
            var willOpen = $pop.prop('hidden');
            closeAllGroupOptionPops();
            if ( willOpen ) {
                $pop.prop('hidden', false);
                $btn.attr('aria-expanded', 'true');
                $btn.closest('.jscfr-group-card').addClass('jscfr-has-pop')
                    .closest('.jscfr-tab-card').addClass('jscfr-has-pop');
            }
        });
        $(document).on('click.jscfr', '.jscfr-group-options-pop', function (e) { e.stopPropagation(); });
        $(document).on('click.jscfr', '.jscfr-gop-clear', function (e) {
            e.preventDefault();
            $(this).siblings('input[type="number"]').val('').trigger('change').focus();
        });
        $(document).on('click.jscfr', function () { closeAllGroupOptionPops(); });

        /* --- Add Field Modal --- */
        var $addFieldModal = buildAddFieldModal();
        $('body').append($addFieldModal);
        var _addFieldTarget = null; // which fields-container to add to

        // Open modal from "+ Add Field" button inside a group
        $(document).on('click.jscfr', '.jscfr-add-field', function () {
            _addFieldTarget = $(this).siblings('.jscfr-fields-container');
            $addFieldModal.fadeIn(150);
            $addFieldModal.find('.jscfr-aft-search').val('').trigger('input').focus();
        });

        // Open modal from main "+ Add Field" button (fields panel level)
        $(document).on('click.jscfr', '#jscfr-add-field-main', function () {
            // Target the last tab's last group, or create tab+group first
            var $lastFC = $tabs.find('.jscfr-fields-container:last');
            if (!$lastFC.length) {
                // Auto-create a tab and group
                var tab = { id: uid('tab'), name: '', label: B.i18n.new_tab || 'Tab', groups: [{ id: uid('grp'), name: '', label: B.i18n.new_group || 'Group', min: '', max: '', layout: 'block', clonable: true, fields: [] }] };
                $tabs.removeAttr('data-empty');
                $tabs.append(buildTabCard(tab));
                initSortables();
                $lastFC = $tabs.find('.jscfr-fields-container:last');
            }
            _addFieldTarget = $lastFC;
            $addFieldModal.fadeIn(150);
            $addFieldModal.find('.jscfr-aft-search').val('').trigger('input').focus();
        });

        // Close modal
        $(document).on('click.jscfr', '.jscfr-add-field-close, .jscfr-add-field-modal', function (e) {
            if (e.target === this) $addFieldModal.fadeOut(150);
        });

        // Search filter inside modal
        $(document).on('input.jscfr', '.jscfr-aft-search', function () {
            var q = $(this).val().toLowerCase();
            $addFieldModal.find('.jscfr-aft-item').each(function () {
                var match = $(this).data('type').toLowerCase().indexOf(q) !== -1 ||
                            $(this).attr('title').toLowerCase().indexOf(q) !== -1;
                $(this).toggle(match);
            });
            // Hide empty categories
            $addFieldModal.find('.jscfr-aft-category').each(function () {
                var hasVisible = $(this).find('.jscfr-aft-item:visible').length > 0;
                $(this).toggle(hasVisible);
            });
        });

        // Click a field type in modal
        $(document).on('click.jscfr', '.jscfr-aft-item', function () {
            var typeKey = $(this).data('type');
            $addFieldModal.fadeOut(150);

            // Special: Tab
            if (typeKey === '_tab') {
                var tab = { id: uid('tab'), name: '', label: B.i18n.new_tab || 'Tab', groups: [] };
                $tabs.removeAttr('data-empty');
                $tabs.append(buildTabCard(tab));
                initSortables();
                $tabs.find('.jscfr-tab-card:last .jscfr-tab-label').focus().select();
                return;
            }

            // Special: Group — auto-create a Tab containing the new Group
            if (typeKey === '_group') {
                var grp = { id: uid('grp'), name: '', label: B.i18n.new_group || 'Group', min: '', max: '', layout: 'block', clonable: true, fields: [] };
                var tab = { id: uid('tab'), name: '', label: B.i18n.new_tab || 'Tab', groups: [grp] };
                $tabs.removeAttr('data-empty');
                var $newTab = buildTabCard(tab);
                $tabs.append($newTab);
                initSortables();
                // Ensure tab body is visible
                $newTab.find('.jscfr-tab-body').show();
                $newTab.find('.jscfr-group-label').first().focus().select();
                // Scroll to new tab
                $('html, body').animate({ scrollTop: $newTab.offset().top - 60 }, 300);
                return;
            }

            // Regular field type
            if (!_addFieldTarget || !_addFieldTarget.length) return;
            var typeLabel = (B.field_types && B.field_types[typeKey]) ? B.field_types[typeKey] : typeKey;
            var fld = $.extend(true, {}, B.default_field, { id: uid('fld'), label: typeLabel, name: slugify(typeLabel), type: typeKey });
            _addFieldTarget.append(buildFieldRow(fld));
            initSortables();
            var $last = _addFieldTarget.find('.jscfr-field-row:last');
            $last.find('.jscfr-field-settings').show();
            $last.find('.jscfr-field-label').focus().select();
        });

        /* --- Del Field --- */
        $(document).on('click.jscfr', '.jscfr-del-field', function () {
            if (!confirm(B.i18n.confirm_delete)) return;
            $(this).closest('.jscfr-field-row').fadeOut(200, function () { $(this).remove(); });
        });

        /* --- Duplicate Field --- */
        $(document).on('click.jscfr', '.jscfr-dup-field', function () {
            var $orig = $(this).closest('.jscfr-field-row');
            var fldData = collectFieldData($orig);
            fldData.id = uid('fld'); fldData.label += ' (Copy)'; fldData.name = slugify(fldData.label);
            $orig.after(buildFieldRow(fldData));
            initSortables();
        });

        /* --- Field type change --- */
        $(document).on('change.jscfr', '.jscfr-field-type', function () {
            var type = $(this).val();
            var $row = $(this).closest('.jscfr-field-row');
            $row.attr('data-type', type);
            $row.find('.jscfr-field-type-badge').text(B.field_types[type] || type);
            toggleTypeSettings($row, type);
        });

        /* --- Auto-generate field name from label --- */
        $(document).on('input.jscfr', '.jscfr-field-label', function () {
            var $row = $(this).closest('.jscfr-field-row');
            var $nameInput = $row.find('.jscfr-field-name');
            // Only auto-gen if name hasn't been manually edited
            if (!$nameInput.data('manual')) {
                $nameInput.val(slugify($(this).val()));
            }
            $row.find('.jscfr-field-label-preview').text($(this).val() || B.i18n.new_field);
        });

        $(document).on('input.jscfr', '.jscfr-field-name', function () {
            $(this).data('manual', true);
            $(this).closest('.jscfr-field-row').find('.jscfr-field-name-preview').text($(this).val());
        });

        /* --- Columns slider sync --- */
        $(document).on('input.jscfr', '.jscfr-field-columns-range', function () {
            $(this).siblings('.jscfr-field-columns').val($(this).val());
        });
        $(document).on('input.jscfr', '.jscfr-field-columns', function () {
            $(this).siblings('.jscfr-field-columns-range').val($(this).val());
        });

        /* --- Auto-gen tab/group names --- */
        $(document).on('input.jscfr', '.jscfr-tab-label', function () {
            var $name = $(this).siblings('.jscfr-tab-name');
            if (!$name.data('manual')) $name.val(slugify($(this).val()));
        });
        $(document).on('input.jscfr', '.jscfr-tab-name', function () { $(this).data('manual', true); });
        $(document).on('input.jscfr', '.jscfr-group-label', function () {
            var $name = $(this).siblings('.jscfr-group-name');
            if (!$name.data('manual')) $name.val(slugify($(this).val()));
        });
        $(document).on('input.jscfr', '.jscfr-group-name', function () { $(this).data('manual', true); });

        /* --- Location: param change --- */
        $(document).on('change.jscfr', '.jscfr-rule-param', function () {
            var param = $(this).val();
            $(this).closest('.jscfr-rule-row').find('.jscfr-rule-value').replaceWith(buildValueControl(param, ''));
            updatePostBadge();
        });
        $(document).on('change.jscfr', '.jscfr-rule-value', function () { updatePostBadge(); });

        /* --- Location: Add AND/OR --- */
        $(document).on('click.jscfr', '.jscfr-add-and', function () {
            $(this).before(buildRuleRow({ param: 'post_type', operator: 'is_equal_to', value: '' }, true));
        });

        $(document).on('click.jscfr', '.jscfr-rule-remove', function () {
            var $og = $(this).closest('.jscfr-or-group');
            var $row = $(this).closest('.jscfr-rule-row');
            if ($og.find('.jscfr-rule-row').length <= 1) {
                var $prev = $og.prev('.jscfr-or-divider');
                var $next = $og.next('.jscfr-or-divider');
                if ($prev.length) $prev.remove(); else if ($next.length) $next.remove();
                $og.remove();
            } else {
                if ($row.is(':first-child') || !$row.prev().length) {
                    $row.next('.jscfr-rule-row').find('.jscfr-and-label').remove();
                }
                $row.remove();
            }
        });

        $(document).on('click.jscfr', '.jscfr-add-or', function () {
            var $wrap = $('#jscfr-location-rules');
            var $addWrap = $wrap.find('.jscfr-or-add-wrap');
            var $og = $('<div class="jscfr-or-group"></div>');
            $og.append(buildRuleRow({ param: 'post_type', operator: 'is_equal_to', value: '' }, false));
            $og.append('<button type="button" class="button button-small jscfr-add-and"><span class="dashicons dashicons-plus" style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span> ' + esc(B.i18n.and) + '</button>');
            $addWrap.before('<div class="jscfr-or-divider">' + esc(B.i18n.or) + '</div>');
            $addWrap.before($og);
        });

        /* --- Conditional logic events --- */
        $(document).on('change.jscfr', '.jscfr-field-cond-enabled', function () {
            var $rules = $(this).closest('.jscfr-fstab-panel').find('.jscfr-cond-rules');
            if ($(this).is(':checked')) {
                $rules.show();
                if (!$rules.children().length) {
                    buildCondRules($(this).closest('.jscfr-field-row'), [[{field:'',operator:'==',value:''}]]);
                }
            } else {
                $rules.hide();
            }
        });

        $(document).on('click.jscfr', '.jscfr-cond-add-and', function () {
            var $og = $(this).closest('.jscfr-cond-or-group');
            var fields = getAllFieldIds();
            var $rr = $('<div class="jscfr-cond-rule"><span class="jscfr-cond-and">and</span></div>');
            var sel = '<select class="jscfr-cond-field">';
            $.each(fields, function(_,f){ sel += '<option value="'+esc(f.id)+'">'+esc(f.label)+'</option>'; });
            sel += '</select>';
            $rr.append(sel);
            $rr.append('<select class="jscfr-cond-op"><option value="==">=</option><option value="!=">!=</option><option value="==empty">empty</option><option value="!=empty">not empty</option></select>');
            $rr.append('<input type="text" class="jscfr-cond-val" value="" />');
            $rr.append('<button type="button" class="jscfr-cond-remove">&times;</button>');
            $(this).before($rr);
        });

        $(document).on('click.jscfr', '.jscfr-cond-add-or', function () {
            var $container = $(this).closest('.jscfr-cond-rules');
            var fields = getAllFieldIds();
            $container.find('.jscfr-cond-add-or').before('<div class="jscfr-cond-or">or</div>');
            var $og = $('<div class="jscfr-cond-or-group"></div>');
            var $rr = $('<div class="jscfr-cond-rule"></div>');
            var sel = '<select class="jscfr-cond-field">';
            $.each(fields, function(_,f){ sel += '<option value="'+esc(f.id)+'">'+esc(f.label)+'</option>'; });
            sel += '</select>';
            $rr.append(sel);
            $rr.append('<select class="jscfr-cond-op"><option value="==">=</option><option value="!=">!=</option><option value="==empty">empty</option><option value="!=empty">not empty</option></select>');
            $rr.append('<input type="text" class="jscfr-cond-val" value="" />');
            $rr.append('<button type="button" class="jscfr-cond-remove">&times;</button>');
            $og.append($rr);
            $og.append('<button type="button" class="button button-small jscfr-cond-add-and">+ and</button>');
            $container.find('.jscfr-cond-add-or').before($og);
        });

        $(document).on('click.jscfr', '.jscfr-cond-remove', function () {
            var $og = $(this).closest('.jscfr-cond-or-group');
            var $rule = $(this).closest('.jscfr-cond-rule');
            if ($og.find('.jscfr-cond-rule').length <= 1) {
                var $prev = $og.prev('.jscfr-cond-or');
                var $next = $og.next('.jscfr-cond-or');
                if ($prev.length) $prev.remove(); else if ($next.length) $next.remove();
                $og.remove();
            } else {
                $rule.remove();
            }
        });

        /* --- Copy ID --- */
        $(document).on('click.jscfr', '.jscfr-copy-id', function (e) {
            e.stopPropagation();
            var id = $(this).data('id');
            var $el = $(this);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(id).then(function () {
                    var orig = $el.text();
                    $el.text('Copied!');
                    setTimeout(function () { $el.text(orig); }, 1200);
                });
            }
        });

        /* --- Save --- */
        $(document).on('click.jscfr', '.jscfr-save-fg', function () { save(); });
        $(document).on('keydown.jscfr', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                if ($('.jscfr-edit-wrap').length) { e.preventDefault(); save(); }
            }
        });
    });

    /* ================================================================ */
    /*  Helpers: collect data from a tab/group/field card               */
    /* ================================================================ */
    function collectTabData($t) {
        var tab = {
            id: $t.data('id'),
            label: $t.find('> .jscfr-tab-head .jscfr-tab-label').val(),
            name: $t.find('> .jscfr-tab-head .jscfr-tab-name').val(),
            icon: $t.find('> .jscfr-tab-head .jscfr-tab-icon-value').val() || '',
            icon_type: $t.find('> .jscfr-tab-head .jscfr-tab-icon-type').val() || 'dashicons',
            groups: []
        };
        $t.find('.jscfr-group-card').each(function () {
            tab.groups.push(collectGroupData($(this)));
        });
        return tab;
    }

    function collectGroupData($g) {
        var grp = {
            id: $g.data('id'),
            label: $g.find('> .jscfr-group-head .jscfr-group-label').val(),
            name: $g.find('> .jscfr-group-head .jscfr-group-name').val(),
            clonable: $g.find('> .jscfr-group-head .jscfr-group-clonable').is(':checked'),
            min: $g.find('> .jscfr-group-head .jscfr-group-min').val(),
            max: $g.find('> .jscfr-group-head .jscfr-group-max').val(),
            layout: $g.find('> .jscfr-group-head .jscfr-group-layout').val(),
            fields: []
        };
        $g.find('.jscfr-field-row').each(function () {
            grp.fields.push(collectFieldData($(this)));
        });
        return grp;
    }

    function collectFieldData($f) {
        return {
            id: $f.data('id'),
            label: $f.find('.jscfr-field-label').val(),
            name: $f.find('.jscfr-field-name').val() || slugify($f.find('.jscfr-field-label').val()),
            type: $f.find('.jscfr-field-type').val(),
            instructions: $f.find('.jscfr-field-instructions').val() || '',
            required: $f.find('.jscfr-field-req').is(':checked'),
            default_value: $f.find('.jscfr-field-default').val() || '',
            placeholder: $f.find('.jscfr-field-ph').val() || '',
            wrapper: {
                width: $f.find('.jscfr-field-wrapper-width').val() || '',
                class: $f.find('.jscfr-field-wrapper-class').val() || '',
                id: $f.find('.jscfr-field-wrapper-id').val() || ''
            },
            conditional_logic: collectCondLogic($f),
            prepend: $f.find('.jscfr-field-prepend').val() || '',
            append: $f.find('.jscfr-field-append').val() || '',
            maxlength: $f.find('.jscfr-field-maxlength').val() || '',
            min: $f.find('.jscfr-field-min').val() || '',
            max: $f.find('.jscfr-field-max').val() || '',
            step: $f.find('.jscfr-field-step').val() || '',
            rows: parseInt($f.find('.jscfr-field-rows').val(), 10) || 4,
            options: $f.find('.jscfr-field-opts').val() || '',
            allow_null: $f.find('.jscfr-field-allow-null').is(':checked'),
            multiple: $f.find('.jscfr-field-multiple').is(':checked'),
            return_format: $f.find('.jscfr-field-return-format').val() || 'id',
            preview_size: $f.find('.jscfr-field-preview-size').val() || 'thumbnail',
            mime: $f.find('.jscfr-field-mime').val() || '',
            min_count: $f.find('.jscfr-field-min-count').val() || '',
            max_count: $f.find('.jscfr-field-max-count').val() || '',
            toolbar: $f.find('.jscfr-field-toolbar').val() || 'full',
            media_upload: $f.find('.jscfr-field-media-upload').is(':checked'),
            post_type: $f.find('.jscfr-field-post-type').val() || [],
            taxonomy_type: $f.find('.jscfr-field-taxonomy-type').val() || '',
            field_type: $f.find('.jscfr-field-field-type').val() || 'checkbox',
            save_terms: $f.find('.jscfr-field-save-terms').is(':checked'),
            load_terms: $f.find('.jscfr-field-load-terms').is(':checked'),
            role: $f.find('.jscfr-field-role').val() || [],
            display_format: $f.find('.jscfr-field-display-format').val() || '',
            return_format_dt: $f.find('.jscfr-field-return-format-dt').val() || '',
            oembed_width: $f.find('.jscfr-field-oembed-w').val() || '',
            oembed_height: $f.find('.jscfr-field-oembed-h').val() || '',
            message: $f.find('.jscfr-field-message').val() || '',
            link_target: false,
            // v5 new field type settings
            heading_tag: $f.find('.jscfr-field-heading-tag').val() || 'h4',
            on_label: $f.find('.jscfr-field-on-label').val() || 'On',
            off_label: $f.find('.jscfr-field-off-label').val() || 'Off',
            html_content: $f.find('.jscfr-field-html-content').val() || '',
            button_label: $f.find('.jscfr-field-button-label').val() || 'Click',
            button_class: $f.find('.jscfr-field-button-class').val() || '',
            image_options: $f.find('.jscfr-field-image-options').val() || '',
            image_select_multiple: $f.find('.jscfr-field-multiple').is(':checked'),
            sub_fields: $f.find('.jscfr-field-sub-fields').val() || '',
            new_lines: $f.find('.jscfr-field-new-lines').val() || 'wpautop',
            display: $f.find('.jscfr-field-display').val() || 'vertical',
            admin_columns: $f.find('.jscfr-field-admin-columns').is(':checked'),
            tooltip: $f.find('.jscfr-field-tooltip').val() || '',
            label_description: $f.find('.jscfr-field-label-desc').val() || '',
            input_description: $f.find('.jscfr-field-input-desc').val() || '',
            columns: $f.find('.jscfr-field-columns').val() || '',
            limit: $f.find('.jscfr-field-limit').val() || '',
            limit_type: $f.find('.jscfr-field-limit-type').val() || 'characters',
            // v5.1
            icon_type: $f.find('.jscfr-field-icon-type').val() || 'dashicons',
            custom_attributes: collectCustomAttrs($f),
            js_options: $f.find('.jscfr-field-js-options').val() || '',
            save_field: $f.find('.jscfr-field-save-field').is(':checked'),
            html_before: $f.find('.jscfr-field-html-before').val() || '',
            html_after: $f.find('.jscfr-field-html-after').val() || '',
            sanitize_callback: $f.find('.jscfr-field-sanitize-cb').val() || ''
        };
    }

    /* ================================================================ */
    /*  Collect custom attributes from field row                        */
    /* ================================================================ */
    function collectCustomAttrs($f) {
        var attrs = [];
        $f.find('.jscfr-ca-row').each(function() {
            var k = $(this).find('.jscfr-ca-key').val();
            var v = $(this).find('.jscfr-ca-val').val();
            if (k) attrs.push({ key: k, value: v });
        });
        return attrs;
    }

    /* ================================================================ */
    /*  Tab Icon Picker events                                          */
    /* ================================================================ */
    $(document).on('click.jscfr', '.jscfr-tab-icon-btn', function(e) {
        e.stopPropagation();
        var $btn = $(this);
        var $head = $btn.closest('.jscfr-tab-head');

        // Remove any existing picker
        $('.jscfr-icon-picker-dropdown').remove();

        var $picker = $('<div class="jscfr-icon-picker-dropdown"></div>');
        $picker.append('<input type="text" class="jscfr-icon-dd-search widefat" placeholder="Search icons..." />');
        var $grid = $('<div class="jscfr-icon-dd-grid"></div>');

        $.each(dashiconsList, function(_, ic) {
            $grid.append('<span class="jscfr-icon-dd-item" data-icon="' + ic + '" title="' + ic + '"><span class="dashicons dashicons-' + ic + '"></span></span>');
        });
        $picker.append($grid);
        $picker.append('<button type="button" class="button button-small jscfr-icon-dd-clear">Clear Icon</button>');
        $btn.after($picker);

        $picker.find('.jscfr-icon-dd-search').on('input', function() {
            var q = $(this).val().toLowerCase();
            $picker.find('.jscfr-icon-dd-item').each(function() {
                $(this).toggle($(this).data('icon').indexOf(q) !== -1);
            });
        });

        $picker.on('click', '.jscfr-icon-dd-item', function() {
            var ic = $(this).data('icon');
            $head.find('.jscfr-tab-icon-value').val(ic);
            $btn.html('<span class="dashicons dashicons-' + ic + '"></span>');
            $picker.remove();
        });

        $picker.on('click', '.jscfr-icon-dd-clear', function() {
            $head.find('.jscfr-tab-icon-value').val('');
            $btn.html('<span class="dashicons dashicons-admin-post"></span>');
            $picker.remove();
        });

        // Close on click outside
        setTimeout(function() {
            $(document).one('click', function() { $picker.remove(); });
        }, 10);
        $picker.on('click', function(e) { e.stopPropagation(); });
    });

    /* ================================================================ */
    /*  Custom Attributes add/remove events                             */
    /* ================================================================ */
    $(document).on('click.jscfr', '.jscfr-ca-add', function() {
        var row = '<div class="jscfr-ca-row">' +
            '<input type="text" class="jscfr-ca-key" value="" placeholder="Attribute" />' +
            '<input type="text" class="jscfr-ca-val" value="" placeholder="Value" />' +
            '<button type="button" class="button jscfr-ca-remove"><span class="dashicons dashicons-no-alt"></span></button>' +
            '</div>';
        $(this).closest('.jscfr-ca-wrap').find('.jscfr-ca-list').append(row);
    });

    $(document).on('click.jscfr', '.jscfr-ca-remove', function() {
        $(this).closest('.jscfr-ca-row').remove();
    });

    /* ================================================================ */
    /*  Theme Code Generator                                            */
    /* ================================================================ */
    $(document).on('click.jscfr', '#jscfr-get-code-btn', function() {
        var $modal = $('#jscfr-theme-code-modal');
        if ($modal.length) {
            $modal.show();
        } else {
            $modal = $(
                '<div id="jscfr-theme-code-modal" class="jscfr-modal-overlay">' +
                '<div class="jscfr-modal">' +
                '<div class="jscfr-modal-header">' +
                '<h3>Theme Code</h3>' +
                '<button type="button" class="jscfr-modal-close">&times;</button>' +
                '</div>' +
                '<div class="jscfr-modal-body">' +
                '<pre><code id="jscfr-theme-code-output">Loading...</code></pre>' +
                '</div>' +
                '<div class="jscfr-modal-footer">' +
                '<button type="button" class="button button-primary" id="jscfr-copy-code"><span class="dashicons dashicons-clipboard" style="vertical-align:middle;margin-right:4px;"></span>Copy Code</button>' +
                '</div>' +
                '</div></div>'
            );
            $('body').append($modal);
        }

        // Fetch generated code
        $.post(B.ajax_url, {
            action: 'jscfr_generate_theme_code',
            nonce: B.nonce,
            fg_id: FG.id
        }, function(res) {
            if (res.success) {
                $('#jscfr-theme-code-output').text(res.data.code);
            } else {
                $('#jscfr-theme-code-output').text('Error generating code.');
            }
        });
    });

    $(document).on('click.jscfr', '.jscfr-modal-close, .jscfr-modal-overlay', function(e) {
        if (e.target === this) $(this).closest('.jscfr-modal-overlay').hide();
    });

    $(document).on('click.jscfr', '#jscfr-copy-code', function() {
        var code = $('#jscfr-theme-code-output').text();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(code).then(function() {
                showToast('Code copied to clipboard!', 'success');
            });
        }
    });

    /* ================================================================ */
    /*  collectTabData — updated with icon                              */
    /* ================================================================ */
    /* (override the existing one is not needed since we update collect()) */

    /* ================================================================ */
    /*  Keyboard shortcut: Ctrl+S / Cmd+S to save                       */
    /* ================================================================ */
    $(document).on('keydown', function (e) {
        if ( (e.ctrlKey || e.metaKey) && e.key === 's' ) {
            e.preventDefault();
            if ( typeof save === 'function' ) {
                save();
            } else {
                $('#jscfr-save-btn').trigger('click');
            }
        }
    });

})(jQuery);

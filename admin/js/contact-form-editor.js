/* LRob Email Toolkit — Contact Form embed block (page editor)
 *
 * This file used to register a Gutenberg block per field type plus a
 * PluginDocumentSettingPanel for per-form settings. Both are gone — fields
 * are edited on the Contact Forms admin page (FormsPage) by a custom
 * inline editor, and per-form settings live on the same page's cards with
 * auto-save.
 *
 * What's left here: the page-side `lrob-etk/contact-form` block — the
 * picker that lets a user embed a chosen form into any page/post. Pure
 * vanilla wp.element / wp.blocks; no JSX, no build step.
 */
(function (wp) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element) return;

    var data = window.lrobEtkCfEditor || {};
    var blocks = wp.blocks;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
    var sprintf = (wp.i18n && wp.i18n.sprintf) ? wp.i18n.sprintf : function (s) { return s; };
    var components = wp.components || {};
    var blockEditor = wp.blockEditor || wp.editor || {};
    var InspectorControls = blockEditor.InspectorControls;
    var apiFetch = wp.apiFetch;

    var TextControl = components.TextControl;
    var SelectControl = components.SelectControl;
    var PanelBody = components.PanelBody;
    var Button = components.Button;
    var Placeholder = components.Placeholder;
    var useBlockProps = blockEditor.useBlockProps;

    function formTitleFallback(id) {
        /* translators: %d: contact form ID, used when the form has no title */
        return sprintf(__('Form #%d', 'lrob-email-toolkit'), id);
    }

    function labelDefault(name) {
        /* translators: %s: current default value, shown as "Default (X)" in dropdown labels */
        return sprintf(__('Default (%s)', 'lrob-email-toolkit'), name);
    }

    function EmbedEdit(props) {
        var attrs = props.attributes;
        var state = useState({ forms: null, loading: false, error: null });
        var snap = state[0];
        var setSnap = state[1];

        if (!snap.forms && !snap.loading && apiFetch) {
            setSnap({ forms: null, loading: true, error: null });
            apiFetch({ path: '/wp/v2/lrob-etk-contact-forms?per_page=50&status=publish&_fields=id,title,link' })
                .then(function (res) { setSnap({ forms: res, loading: false, error: null }); })
                .catch(function (e) { setSnap({ forms: [], loading: false, error: e && e.message ? e.message : 'fetch_failed' }); });
        }

        var options = [{ label: __('— Select a form —', 'lrob-email-toolkit'), value: '0' }];
        if (snap.forms) {
            snap.forms.forEach(function (f) {
                var title = (f.title && (f.title.rendered || f.title.raw)) || formTitleFallback(f.id);
                options.push({ label: title, value: String(f.id) });
            });
        }

        var globalsForBlock = data.globalDefaults || {};
        var presetLabels = {
            'default':  __('Default',  'lrob-email-toolkit'),
            'minimal':  __('Minimal',  'lrob-email-toolkit'),
            'soft':     __('Soft',     'lrob-email-toolkit'),
            'contrast': __('Contrast', 'lrob-email-toolkit')
        };
        var inheritedPresetLabel = presetLabels[globalsForBlock.style_preset] || presetLabels['default'];
        var presetOptions = [
            { label: labelDefault(inheritedPresetLabel), value: '' },
            { label: presetLabels['default'],  value: 'default' },
            { label: presetLabels['minimal'],  value: 'minimal' },
            { label: presetLabels['soft'],     value: 'soft' },
            { label: presetLabels['contrast'], value: 'contrast' }
        ];

        var overrides = attrs.overrides || {};
        function setOverride(key, value) {
            var next = Object.assign({}, overrides);
            if (value === '' || value == null) delete next[key]; else next[key] = value;
            props.setAttributes({ overrides: next });
        }

        var inspector = el(InspectorControls, null,
            el(PanelBody, { title: __('Form', 'lrob-email-toolkit'), initialOpen: true },
                el(SelectControl, {
                    label: __('Contact form', 'lrob-email-toolkit'),
                    value: String(attrs.formId || 0),
                    options: options,
                    onChange: function (v) { props.setAttributes({ formId: parseInt(v, 10) || 0 }); }
                }),
                attrs.formId ? el(Button, {
                    variant: 'secondary',
                    href: data.editFormBase + attrs.formId,
                    target: '_blank',
                    rel: 'noopener'
                }, __('Edit this form →', 'lrob-email-toolkit')) : null
            ),
            el(PanelBody, { title: __('Style', 'lrob-email-toolkit'), initialOpen: false },
                el(SelectControl, {
                    label: __('Preset', 'lrob-email-toolkit'),
                    value: attrs.preset || '',
                    options: presetOptions,
                    onChange: function (v) { props.setAttributes({ preset: v }); }
                }),
                el(TextControl, {
                    label: __('Accent color (CSS color)', 'lrob-email-toolkit'),
                    value: overrides.accent || '',
                    onChange: function (v) { setOverride('accent', v); }
                }),
                el(TextControl, {
                    label: __('Corner roundness (e.g. 8px)', 'lrob-email-toolkit'),
                    value: overrides.radius || '',
                    onChange: function (v) { setOverride('radius', v); }
                }),
                el(TextControl, {
                    label: __('Font size (e.g. 1rem)', 'lrob-email-toolkit'),
                    value: overrides.font_size || '',
                    onChange: function (v) { setOverride('font_size', v); }
                })
            )
        );

        // Block-editor needs the props from useBlockProps on the outer
        // wrapper element. Without them Gutenberg can't tag the block in
        // the DOM and click-to-select stops working — first click works
        // but anything afterwards is silently ignored.
        var blockProps = useBlockProps ? useBlockProps() : {};

        // Resolve the picked form (if any). When the saved formId no
        // longer points to a published form (deleted / trashed), treat
        // the block as orphan: render the picker so the user can swap to
        // another form without having to delete and re-add the block.
        var picked = null;
        var isOrphan = false;
        if (attrs.formId) {
            if (snap.forms) {
                picked = snap.forms.find(function (f) { return f.id === attrs.formId; }) || null;
                if (!picked) isOrphan = true;
            }
        }
        var showPicker = !attrs.formId || isOrphan;

        if (showPicker) {
            var instructions;
            if (snap.loading) {
                instructions = __('Loading available forms…', 'lrob-email-toolkit');
            } else if (isOrphan) {
                instructions = sprintf(
                    /* translators: %d: missing form ID */
                    __('Form #%d is no longer published. Pick another contact form to embed on this page.', 'lrob-email-toolkit'),
                    attrs.formId
                );
            } else if (snap.forms && snap.forms.length === 0) {
                instructions = __('No published contact forms yet. Create one first under Email Toolkit → Contact Forms.', 'lrob-email-toolkit');
            } else {
                instructions = __('Pick a contact form to embed on this page.', 'lrob-email-toolkit');
            }
            return el('div', blockProps,
                inspector,
                el(Placeholder, {
                    label: __('Contact Form', 'lrob-email-toolkit'),
                    icon: 'feedback',
                    instructions: instructions
                },
                    el(SelectControl, {
                        value: String(attrs.formId || 0),
                        options: options,
                        onChange: function (v) { props.setAttributes({ formId: parseInt(v, 10) || 0 }); }
                    })
                )
            );
        }

        var title = picked ? ((picked.title && (picked.title.rendered || picked.title.raw)) || formTitleFallback(picked.id)) : formTitleFallback(attrs.formId);

        return el('div', blockProps,
            inspector,
            el('div', { className: 'lrob-etk-cf-embed-preview lrob-etk-cf-preset--' + (attrs.preset || 'default') },
                el('div', { className: 'lrob-etk-cf-embed-preview-head' },
                    el('span', { className: 'dashicons dashicons-feedback' }),
                    el('strong', null, __('Contact Form: ', 'lrob-email-toolkit') + title)
                ),
                el('p', { className: 'description' }, __('Form fields will appear here on the published page. Edit the form on the Contact Forms admin page to change them.', 'lrob-email-toolkit'))
            )
        );
    }

    blocks.registerBlockType('lrob-etk/contact-form', {
        apiVersion: 2,
        title: __('Contact Form', 'lrob-email-toolkit'),
        icon: 'feedback',
        category: 'widgets',
        keywords: ['contact', 'form'],
        attributes: {
            formId:    { type: 'integer', default: 0 },
            preset:    { type: 'string',  default: '' },
            overrides: { type: 'object',  default: {} }
        },
        supports: { html: false, align: ['wide', 'full'] },
        edit: EmbedEdit,
        save: function () { return null; }
    });
})(window.wp);

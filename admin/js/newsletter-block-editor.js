/* LRob Email Toolkit — lrob-etk/newsletter-subscribe Gutenberg block. Server-rendered on front end. */
(function (wp) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element) return;

    var data = window.lrobEtkNlBlock || {};
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

    var SelectControl = components.SelectControl;
    var PanelBody = components.PanelBody;
    var Button = components.Button;
    var Placeholder = components.Placeholder;
    var useBlockProps = blockEditor.useBlockProps;

    function formTitleFallback(id) {
        /* translators: %d: form ID, used as a fallback label when the form has no title */
        return sprintf(__('Form #%d', 'lrob-email-toolkit'), id);
    }

    function EmbedEdit(props) {
        var attrs = props.attributes;
        var state = useState({ forms: null, loading: false, error: null });
        var snap = state[0];
        var setSnap = state[1];

        if (!snap.forms && !snap.loading && apiFetch) {
            setSnap({ forms: null, loading: true, error: null });
            apiFetch({ path: '/wp/v2/lrob-etk-nl-forms?per_page=50&status=publish&_fields=id,title,link' })
                .then(function (res) { setSnap({ forms: res, loading: false, error: null }); })
                .catch(function (e) { setSnap({ forms: [], loading: false, error: e && e.message ? e.message : 'fetch_failed' }); });
        }

        var options = [{ label: __('— Select a form —', 'lrob-email-toolkit'), value: '0' }];
        if (Array.isArray(snap.forms)) {
            snap.forms.forEach(function (f) {
                var title = (f.title && f.title.rendered) || formTitleFallback(f.id);
                options.push({ label: title, value: String(f.id) });
            });
        }

        var blockProps = useBlockProps ? useBlockProps() : {};

        var picker = el(SelectControl, {
            label:    __('Subscribe form', 'lrob-email-toolkit'),
            value:    String(attrs.formId || 0),
            options:  options,
            onChange: function (v) { props.setAttributes({ formId: parseInt(v, 10) || 0 }); }
        });

        var editLink = null;
        if (attrs.formId > 0 && data.editFormBase) {
            editLink = el(
                'p',
                { className: 'lrob-etk-nl-block-editlink' },
                el('a', { href: data.editFormBase + attrs.formId, target: '_blank', rel: 'noreferrer' }, __('Edit this form →', 'lrob-email-toolkit'))
            );
        }

        var placeholderBody = attrs.formId > 0
            ? el(
                'div',
                { className: 'lrob-etk-nl-block-summary' },
                el('p', null, __('This subscribe form will render here on the published page.', 'lrob-email-toolkit')),
                editLink
            )
            : el('p', null, __('Pick a subscribe form to embed.', 'lrob-email-toolkit'));

        return el(
            Fragment,
            null,
            InspectorControls
                ? el(
                    InspectorControls,
                    null,
                    el(PanelBody, { title: __('Subscribe form', 'lrob-email-toolkit'), initialOpen: true }, picker)
                )
                : null,
            el(
                'div',
                blockProps,
                el(
                    Placeholder,
                    {
                        icon:        'email-alt',
                        label:       __('Newsletter subscribe', 'lrob-email-toolkit'),
                        instructions: snap.loading ? __('Loading forms…', 'lrob-email-toolkit') : ''
                    },
                    picker,
                    placeholderBody
                )
            )
        );
    }

    blocks.registerBlockType('lrob-etk/newsletter-subscribe', {
        title:       __('Newsletter subscribe', 'lrob-email-toolkit'),
        description: __('Embed one of your subscribe forms on this page.', 'lrob-email-toolkit'),
        category:    'widgets',
        icon:        'email-alt',
        attributes:  { formId: { type: 'integer', default: 0 } },
        supports:    { html: false, align: ['wide', 'full'] },
        edit:        EmbedEdit,
        save:        function () { return null; }
    });
})(window.wp);

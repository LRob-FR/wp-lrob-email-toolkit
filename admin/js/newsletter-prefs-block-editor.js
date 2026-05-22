/* LRob Email Toolkit — Newsletter preferences block (page editor)
 *
 * The page-side `lrob-etk/newsletter-preferences` block. Embeds the
 * preferences UI on any page/post — typically a publicly linked
 * `/newsletter-preferences/` page. No attributes (the block always
 * shows the prefs for whoever is viewing).
 *
 * Vanilla wp.element / wp.blocks; server-rendered on the front end.
 * In the editor we render a static placeholder describing what the
 * block does, since the actual UI depends on the visitor's identity.
 */
(function (wp) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element) return;

    var blocks = wp.blocks;
    var el = wp.element.createElement;
    var __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };
    var components = wp.components || {};
    var blockEditor = wp.blockEditor || wp.editor || {};
    var Placeholder = components.Placeholder;
    var useBlockProps = blockEditor.useBlockProps;

    function PrefsEdit() {
        var blockProps = useBlockProps ? useBlockProps() : {};
        return el(
            'div',
            blockProps,
            el(
                Placeholder,
                {
                    icon:         'email',
                    label:        __('Newsletter preferences', 'lrob-email-toolkit'),
                    instructions: __('Logged-in visitors see their email preferences here. Anonymous visitors see a short message pointing at the link in their emails.', 'lrob-email-toolkit')
                }
            )
        );
    }

    blocks.registerBlockType('lrob-etk/newsletter-preferences', {
        title:       __('Newsletter preferences', 'lrob-email-toolkit'),
        description: __('Embed the email-preferences form on a public page. Logged-in users manage their settings; anonymous visitors get a short message.', 'lrob-email-toolkit'),
        category:    'widgets',
        icon:        'email',
        attributes:  {},
        supports:    { html: false, align: ['wide'] },
        edit:        PrefsEdit,
        save:        function () { return null; }
    });
})(window.wp);

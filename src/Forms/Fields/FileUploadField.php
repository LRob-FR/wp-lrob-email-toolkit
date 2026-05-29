<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;
use LRob\EmailToolkit\Forms\FormContext;
use LRob\EmailToolkit\Forms\Upload\UploadPolicy;

final class FileUploadField implements FieldTypeInterface
{
    public const TYPE = 'file_upload';

    public function slug(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('File upload', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());

        $base['multiple']       = !empty($field['multiple']);
        $base['max_count']      = max(1, min(50, (int) ($field['max_count'] ?? 1)));
        $base['max_size_mb']    = max(1, (int) ($field['max_size_mb'] ?? 15));
        $base['total_size_mb']  = max(1, (int) ($field['total_size_mb'] ?? 50));

        $preset = isset($field['accept_preset']) ? (string) $field['accept_preset'] : UploadPolicy::PRESET_DOCUMENTS;
        $base['accept_preset']  = UploadPolicy::is_known_preset($preset) ? $preset : UploadPolicy::PRESET_DOCUMENTS;
        $base['accept_custom']  = isset($field['accept_custom']) ? (string) $field['accept_custom'] : '';
        $base['strip_exif']     = !empty($field['strip_exif']);
        $base['allow_dangerous'] = !empty($field['allow_dangerous']);

        $delivery = isset($field['delivery']) ? (string) $field['delivery'] : UploadPolicy::DELIVERY_WEBSERVER;
        $allowed_delivery = [
            UploadPolicy::DELIVERY_WEBSERVER,
            UploadPolicy::DELIVERY_ATTACHMENT,
            UploadPolicy::DELIVERY_BOTH,
        ];
        $base['delivery'] = in_array($delivery, $allowed_delivery, true) ? $delivery : UploadPolicy::DELIVERY_WEBSERVER;

        if (!$base['multiple']) {
            $base['max_count'] = 1;
        }

        return $base;
    }

    public function render(array $attrs): string
    {
        if (!FormContext::is_active()) {
            return '';
        }
        $slug = FieldRenderHelpers::normalize_slug($attrs);
        if ($slug === '') {
            return '';
        }

        $label     = isset($attrs['label']) ? (string) $attrs['label'] : '';
        $helper    = isset($attrs['helper']) ? (string) $attrs['helper'] : '';
        $required  = !empty($attrs['required']);
        $multiple  = !empty($attrs['multiple']);
        $max_count = max(1, (int) ($attrs['max_count'] ?? 1));
        $max_size_mb = max(1, (int) ($attrs['max_size_mb'] ?? 15));
        $total_size_mb = max(1, (int) ($attrs['total_size_mb'] ?? 50));
        $preset = isset($attrs['accept_preset']) ? (string) $attrs['accept_preset'] : UploadPolicy::PRESET_DOCUMENTS;
        $custom = isset($attrs['accept_custom']) ? (string) $attrs['accept_custom'] : '';
        $allow_dangerous = !empty($attrs['allow_dangerous']);

        $resolved_exts = UploadPolicy::resolve_extensions($preset, $custom, $allow_dangerous);
        $accept_attr   = UploadPolicy::accept_attribute($resolved_exts);

        $id   = FormContext::field_id($slug);
        $name = FormContext::field_name($slug) . ($multiple ? '[]' : '');

        // Editor-only: side-channel admin knobs so JS initial-sync can scrape them.
        $admin_attrs = '';
        if (FormContext::is_editor()) {
            $admin_attrs = ' data-admin-preset="' . esc_attr((string) ($attrs['accept_preset'] ?? '')) . '"'
                . ' data-admin-custom="' . esc_attr((string) ($attrs['accept_custom'] ?? '')) . '"'
                . ' data-admin-strip-exif="' . (!empty($attrs['strip_exif']) ? '1' : '0') . '"'
                . ' data-admin-allow-dangerous="' . (!empty($attrs['allow_dangerous']) ? '1' : '0') . '"'
                . ' data-admin-delivery="' . esc_attr((string) ($attrs['delivery'] ?? UploadPolicy::DELIVERY_WEBSERVER)) . '"';
        }

        $control = '<div class="lrob-etk-form-file" data-file-upload'
            . ($multiple ? ' data-multiple="1"' : '')
            . ' data-max-count="' . esc_attr((string) ($multiple ? $max_count : 1)) . '"'
            . ' data-max-size-mb="' . esc_attr((string) $max_size_mb) . '"'
            . ' data-total-size-mb="' . esc_attr((string) $total_size_mb) . '"'
            . ' data-accept-exts="' . esc_attr(implode(',', $resolved_exts)) . '"'
            . $admin_attrs
            . '>'
            . '<label class="lrob-etk-form-file-trigger" for="' . esc_attr($id) . '">'
            .   '<span class="dashicons dashicons-upload" aria-hidden="true"></span>'
            .   '<span class="lrob-etk-form-file-trigger-text">'
            .     ($multiple
                    ? sprintf(
                        /* translators: %d: max number of files allowed */
                        esc_html(_n('Choose a file (max %d)', 'Choose files (max %d)', $max_count, 'lrob-email-toolkit')),
                        $max_count
                    )
                    : esc_html__('Choose a file', 'lrob-email-toolkit'))
            .   '</span>'
            . '</label>'
            . '<input type="file" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"'
            .     ($multiple ? ' multiple' : '')
            .     ($accept_attr !== '' ? ' accept="' . esc_attr($accept_attr) . '"' : '')
            .     ($required ? ' required aria-required="true"' : '')
            .     ' hidden>'
            . '<ul class="lrob-etk-form-file-list" data-file-list></ul>'
            . '<p class="lrob-etk-form-file-hint">'
            .   sprintf(
                /* translators: 1: max size per file MB, 2: comma-separated extension list */
                esc_html__('Max %1$d MB per file — %2$s', 'lrob-email-toolkit'),
                $max_size_mb,
                $accept_attr !== '' ? esc_html($accept_attr) : esc_html__('no file type restriction', 'lrob-email-toolkit')
            )
            . '</p>'
            . (FormContext::is_editor()
                ? '<p class="lrob-etk-form-file-warning" data-save-off-warn>'
                    . '<span class="dashicons dashicons-warning" aria-hidden="true"></span> '
                    . esc_html__('“Save submissions” is off — files from this field will be attached to the notification email instead of being stored on the server.', 'lrob-email-toolkit')
                . '</p>'
                : '')
            . '</div>';

        return FieldRenderHelpers::wrap_field('file', $slug, $label, $helper, $required, $control, $id);
    }
}

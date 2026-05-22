<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;

final class PhoneField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'phone';
    }

    public function label(): string
    {
        return __('Phone', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        if (isset($field['pattern']) && $field['pattern'] !== '') {
            $base['pattern'] = (string) $field['pattern'];
        }
        return $base;
    }

    public function render(array $attrs): string
    {
        return FieldRenderHelpers::render_input('tel', $attrs, [
            'inputmode' => 'tel',
            'pattern'   => isset($attrs['pattern']) && is_string($attrs['pattern']) && $attrs['pattern'] !== '' ? $attrs['pattern'] : null,
        ]);
    }
}

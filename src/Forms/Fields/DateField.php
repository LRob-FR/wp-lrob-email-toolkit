<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;

final class DateField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'date';
    }

    public function label(): string
    {
        return __('Date', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        foreach (['min', 'max'] as $k) {
            if (isset($field[$k]) && $field[$k] !== '') {
                $base[$k] = sanitize_text_field((string) $field[$k]);
            }
        }
        return $base;
    }

    public function render(array $attrs): string
    {
        return FieldRenderHelpers::render_input('date', $attrs, [
            'min' => $attrs['min'] ?? null,
            'max' => $attrs['max'] ?? null,
        ]);
    }
}

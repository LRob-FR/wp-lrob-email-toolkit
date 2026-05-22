<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;

final class NumberField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'number';
    }

    public function label(): string
    {
        return __('Number', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        foreach (['min', 'max', 'step'] as $k) {
            if (isset($field[$k]) && $field[$k] !== '') {
                $base[$k] = sanitize_text_field((string) $field[$k]);
            }
        }
        return $base;
    }

    public function render(array $attrs): string
    {
        return FieldRenderHelpers::render_input('number', $attrs, [
            'min'  => $attrs['min']  ?? null,
            'max'  => $attrs['max']  ?? null,
            'step' => $attrs['step'] ?? null,
        ]);
    }
}

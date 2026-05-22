<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;

final class EmailField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return __('Email', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        if (isset($field['maxLength'])) {
            $base['maxLength'] = max(0, (int) $field['maxLength']);
        }
        return $base;
    }

    public function render(array $attrs): string
    {
        return FieldRenderHelpers::render_input('email', $attrs);
    }
}

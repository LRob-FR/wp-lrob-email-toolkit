<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Forms\Fields;

use LRob\EmailToolkit\Forms\FieldRenderHelpers;
use LRob\EmailToolkit\Forms\FieldTypeInterface;

final class RadioField implements FieldTypeInterface
{
    public function slug(): string
    {
        return 'radio';
    }

    public function label(): string
    {
        return __('Radio buttons', 'lrob-email-toolkit');
    }

    public function normalize(array $field): ?array
    {
        $base = FieldRenderHelpers::normalize_base_keys($field, $this->slug());
        $base['options'] = FieldRenderHelpers::normalize_options($field['options'] ?? []);
        return $base;
    }

    public function render(array $attrs): string
    {
        return FieldRenderHelpers::render_option_group('radio', $attrs);
    }
}

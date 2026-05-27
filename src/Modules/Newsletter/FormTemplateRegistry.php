<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Forms\FormStructure;

/**
 * Starter templates for newsletter subscribe forms. Same pattern as
 * ContactForm's TemplateRegistry — picker modal lists these, the
 * AjaxController clones the chosen structure into a new draft.
 *
 * list-picker / category-picker starter templates land alongside the
 * field types themselves in step 4 (categories + lists CRUD).
 */
final class FormTemplateRegistry
{
    /** @return array<int, array{slug:string, name:string, description:string}> */
    public static function list_for_picker(): array
    {
        $out = [];
        foreach (self::all() as $slug => $tpl) {
            $out[] = [
                'slug'        => $slug,
                'name'        => $tpl['name'],
                'description' => $tpl['description'],
            ];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $slug): ?array
    {
        $all = self::all();
        return $all[$slug] ?? null;
    }

    /**
     * @return array{version:int, rows:array<int, array>}
     */
    public static function structure_for(string $slug): array
    {
        $tpl = self::get($slug);
        if ($tpl === null || !isset($tpl['structure'])) {
            return FormStructure::empty_structure();
        }
        return FormStructure::normalize($tpl['structure'], FormCPT::POST_TYPE);
    }

    /** @return array<string, array{name:string, description:string, structure:array}> */
    private static function all(): array
    {
        return [
            'email_only' => [
                'name'        => __('Email only', 'lrob-email-toolkit'),
                'description' => __('Just an email field — the simplest subscribe form.', 'lrob-email-toolkit'),
                'structure'   => self::tpl_email_only(),
            ],
            'email_name' => [
                'name'        => __('Email + name', 'lrob-email-toolkit'),
                'description' => __('Collect the subscriber\'s name too for friendlier emails.', 'lrob-email-toolkit'),
                'structure'   => self::tpl_email_name(),
            ],
        ];
    }

    private static function tpl_email_only(): array
    {
        return [
            'version' => FormStructure::VERSION,
            'rows'    => array_merge([
                self::row([self::col([
                    self::field('email', 'email', __('Your email', 'lrob-email-toolkit'), true, ['maps_to' => 'email']),
                ])]),
            ], self::tail_rows(__('Subscribe', 'lrob-email-toolkit'))),
        ];
    }

    private static function tpl_email_name(): array
    {
        return [
            'version' => FormStructure::VERSION,
            'rows'    => array_merge([
                self::row([
                    self::col([self::field('text',  'name',  __('Your name',  'lrob-email-toolkit'), false, ['maps_to' => 'name'])]),
                    self::col([self::field('email', 'email', __('Your email', 'lrob-email-toolkit'), true,  ['maps_to' => 'email'])]),
                ]),
            ], self::tail_rows(__('Subscribe', 'lrob-email-toolkit'))),
        ];
    }

    /** Captcha + submit row appended to every starter (newsletter context). */
    private static function tail_rows(string $submit_text): array
    {
        return [
            self::row([
                self::col([['id' => self::gen_id('f'), 'type' => 'captcha']]),
                self::col([['id' => self::gen_id('f'), 'type' => 'submit', 'text' => $submit_text, 'align' => 'right']]),
            ]),
        ];
    }

    /** @param array<int, array> $columns */
    private static function row(array $columns): array
    {
        return ['id' => self::gen_id('row'), 'columns' => $columns];
    }

    /** @param array<int, array> $fields */
    private static function col(array $fields): array
    {
        return ['id' => self::gen_id('col'), 'fields' => $fields];
    }

    /** @param array<string, mixed> $extra */
    private static function field(string $type, string $slug, string $label, bool $required, array $extra = []): array
    {
        $base = [
            'id'       => self::gen_id('f'),
            'type'     => $type,
            'slug'     => $slug,
            'label'    => $label,
            'required' => $required,
        ];
        return array_merge($base, $extra);
    }

    private static function gen_id(string $prefix): string
    {
        return $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}

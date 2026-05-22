<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\ContactForm;

use LRob\EmailToolkit\Forms\FormStructure;

/**
 * Built-in form templates the user can pick from when creating a new form.
 * Each entry returns a FormStructure-shaped array — same shape that's
 * stored in post_content for any existing form, so "user templates"
 * (existing forms) and built-in templates flow through the same clone
 * pathway in AjaxController.
 *
 * Templates ship structure only — no settings (recipient, anti-spam, style
 * preset). The new form picks those up from Settings::defaults().
 */
final class TemplateRegistry
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
     * Apply a template's structure to a newly-created form. Returns the
     * structure as FormStructure expects it.
     *
     * @return array{version:int, submit:array{text:string, align:string}, rows:array<int, array>}
     */
    public static function structure_for(string $slug): array
    {
        $tpl = self::get($slug);
        if ($tpl === null || !isset($tpl['structure'])) {
            return FormStructure::empty_structure();
        }
        // Templates ship structures bound to Contact Form's CPT — that's
        // the only consumer of this registry. Normalize against it so the
        // shared FieldTypeRegistry dispatch resolves correctly.
        return FormStructure::normalize($tpl['structure'], CPT::POST_TYPE);
    }

    /** @return array<string, array{name:string, description:string, structure:array}> */
    private static function all(): array
    {
        return [
            'simple_contact' => [
                'name'        => __('Simple contact', 'lrob-email-toolkit'),
                'description' => __('Name, email, and message — the classic starting point.', 'lrob-email-toolkit'),
                'structure'   => self::tpl_simple_contact(),
            ],
            'quote_request' => [
                'name'        => __('Quote request', 'lrob-email-toolkit'),
                'description' => __('Name, company, phone, project description — for sales leads.', 'lrob-email-toolkit'),
                'structure'   => self::tpl_quote_request(),
            ],
            'support_ticket' => [
                'name'        => __('Support ticket', 'lrob-email-toolkit'),
                'description' => __('Email, urgency level, and a description — for help requests.', 'lrob-email-toolkit'),
                'structure'   => self::tpl_support_ticket(),
            ],
            'newsletter_signup' => [
                'name'        => __('Newsletter signup', 'lrob-email-toolkit'),
                'description' => __('Just an email and a consent checkbox.', 'lrob-email-toolkit'),
                'structure'   => self::tpl_newsletter_signup(),
            ],
            'event_rsvp' => [
                'name'        => __('Event RSVP', 'lrob-email-toolkit'),
                'description' => __('Name, email, number of guests, and a notes field.', 'lrob-email-toolkit'),
                'structure'   => self::tpl_event_rsvp(),
            ],
        ];
    }

    private static function tpl_simple_contact(): array
    {
        return [
            'version' => FormStructure::VERSION,
            'rows'    => array_merge([
                self::row([self::col([
                    self::field('text',  'name',  __('Your name', 'lrob-email-toolkit'),  true),
                ])]),
                self::row([
                    self::col([self::field('email', 'email', __('Your email', 'lrob-email-toolkit'), true)]),
                    self::col([self::field('phone', 'phone', __('Phone', 'lrob-email-toolkit'), false)]),
                ]),
                self::row([self::col([
                    self::field('textarea', 'message', __('Your message', 'lrob-email-toolkit'), true, ['rows' => 5]),
                ])]),
            ], self::tail_rows(__('Send message', 'lrob-email-toolkit'))),
        ];
    }

    private static function tpl_quote_request(): array
    {
        return [
            'version' => FormStructure::VERSION,
            'rows'    => array_merge([
                self::row([
                    self::col([self::field('text', 'firstname', __('First name', 'lrob-email-toolkit'), true)]),
                    self::col([self::field('text', 'lastname',  __('Last name',  'lrob-email-toolkit'), true)]),
                ]),
                self::row([
                    self::col([self::field('email', 'email',   __('Email',   'lrob-email-toolkit'), true)]),
                    self::col([self::field('phone', 'phone',   __('Phone',   'lrob-email-toolkit'), false)]),
                ]),
                self::row([self::col([self::field('text', 'company', __('Company', 'lrob-email-toolkit'), false)])]),
                self::row([self::col([
                    self::field('textarea', 'project', __('Project details', 'lrob-email-toolkit'), true, ['rows' => 6]),
                ])]),
            ], self::tail_rows(__('Request quote', 'lrob-email-toolkit'))),
        ];
    }

    private static function tpl_support_ticket(): array
    {
        return [
            'version' => FormStructure::VERSION,
            'rows'    => array_merge([
                self::row([
                    self::col([self::field('email', 'email', __('Your email', 'lrob-email-toolkit'), true)]),
                    self::col([self::field('select', 'urgency', __('Urgency', 'lrob-email-toolkit'), true, [
                        'options' => [
                            ['value' => 'low',     'label' => __('Low — when you can',    'lrob-email-toolkit')],
                            ['value' => 'normal',  'label' => __('Normal',                'lrob-email-toolkit')],
                            ['value' => 'high',    'label' => __('High — blocking',       'lrob-email-toolkit')],
                            ['value' => 'urgent',  'label' => __('Urgent — production down', 'lrob-email-toolkit')],
                        ],
                    ])]),
                ]),
                self::row([self::col([self::field('text', 'subject', __('Short summary', 'lrob-email-toolkit'), true)])]),
                self::row([self::col([
                    self::field('textarea', 'description', __('What\'s happening?', 'lrob-email-toolkit'), true, [
                        'rows'   => 6,
                        'helper' => __('Include steps to reproduce if relevant.', 'lrob-email-toolkit'),
                    ]),
                ])]),
            ], self::tail_rows(__('Open ticket', 'lrob-email-toolkit'))),
        ];
    }

    private static function tpl_newsletter_signup(): array
    {
        return [
            'version' => FormStructure::VERSION,
            'rows'    => array_merge([
                self::row([self::col([self::field('email', 'email', __('Your email', 'lrob-email-toolkit'), true)])]),
                self::row([self::col([
                    self::field('checkbox', 'consent', __('I agree to receive your newsletter', 'lrob-email-toolkit'), true, [
                        'multiple' => false,
                    ]),
                ])]),
            ], self::tail_rows(__('Subscribe', 'lrob-email-toolkit'))),
        ];
    }

    private static function tpl_event_rsvp(): array
    {
        return [
            'version' => FormStructure::VERSION,
            'rows'    => array_merge([
                self::row([
                    self::col([self::field('text',  'name',  __('Your name',  'lrob-email-toolkit'), true)]),
                    self::col([self::field('email', 'email', __('Your email', 'lrob-email-toolkit'), true)]),
                ]),
                self::row([
                    self::col([self::field('radio', 'attendance', __('Will you attend?', 'lrob-email-toolkit'), true, [
                        'options' => [
                            ['value' => 'yes',   'label' => __('Yes',         'lrob-email-toolkit')],
                            ['value' => 'no',    'label' => __('No',          'lrob-email-toolkit')],
                            ['value' => 'maybe', 'label' => __('Maybe',       'lrob-email-toolkit')],
                        ],
                    ])]),
                    self::col([self::field('number', 'guests', __('Number of guests', 'lrob-email-toolkit'), false, [
                        'min' => '0',
                        'max' => '10',
                    ])]),
                ]),
                self::row([self::col([
                    self::field('textarea', 'notes', __('Dietary requirements / notes', 'lrob-email-toolkit'), false, ['rows' => 3]),
                ])]),
            ], self::tail_rows(__('Send RSVP', 'lrob-email-toolkit'))),
        ];
    }

    /** @param array<int, array> $columns */
    private static function row(array $columns): array
    {
        return ['id' => 'row_' . substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8), 'columns' => $columns];
    }

    /** @param array<int, array> $fields */
    private static function col(array $fields): array
    {
        return ['id' => 'col_' . substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8), 'fields' => $fields];
    }

    /** @param array<string, mixed> $extra */
    private static function field(string $type, string $slug, string $label, bool $required, array $extra = []): array
    {
        $base = [
            'id'       => 'f_' . substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8),
            'type'     => $type,
            'slug'     => $slug,
            'label'    => $label,
            'required' => $required,
        ];
        return array_merge($base, $extra);
    }

    /**
     * Trailing row shared by templates: captcha + submit side-by-side. One
     * row, two columns — the captcha sits inline on the left, the submit
     * button on the right. Cleaner than stacking them vertically.
     */
    private static function tail_rows(string $submit_text): array
    {
        return [
            self::row([
                self::col([['id' => 'f_' . substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8), 'type' => 'captcha']]),
                self::col([['id' => 'f_' . substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8), 'type' => 'submit', 'text' => $submit_text, 'align' => 'right']]),
            ]),
        ];
    }
}

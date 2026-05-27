<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

/**
 * Single source of truth for the subscriber profile fields:
 *   - what columns exist on the subscribers table,
 *   - what gender values are allowed (slugs persist; labels translate),
 *   - what targets the form-builder's "Maps to" picker offers.
 *
 * Every form-builder field can declare `data-attr-maps-to=<column>` so
 * subscribe submissions populate the right column. Pure-static so the
 * registry is callable from any boot phase.
 */
final class SubscriberFields
{
    /** @var array<int, string> Columns the form-mapping picker may target. */
    public const PROFILE_COLUMNS = [
        'email',
        'name',
        'first_name',
        'last_name',
        'phone',
        'address_line',
        'address_line2',
        'address_postcode',
        'address_city',
        'address_region',
        'address_country',
        'gender',
        'language',
    ];

    /** @var array<int, string> Gender slugs persisted in the DB column. */
    public const GENDER_VALUES = [
        'female',
        'male',
        'other',
        'prefer_not_to_say',
    ];

    /**
     * Human label for a column — used by the form-mapping picker and the
     * detail-modal field list. Translation lives here so consumers don't
     * have to mirror the vocabulary.
     */
    public static function label(string $column): string
    {
        return match ($column) {
            'email'             => __('Email', 'lrob-email-toolkit'),
            'name'              => __('Full name', 'lrob-email-toolkit'),
            'first_name'        => __('First name', 'lrob-email-toolkit'),
            'last_name'         => __('Last name', 'lrob-email-toolkit'),
            'phone'             => __('Phone', 'lrob-email-toolkit'),
            'address_line'      => __('Street address', 'lrob-email-toolkit'),
            'address_line2'     => __('Address line 2', 'lrob-email-toolkit'),
            'address_postcode'  => __('Postcode', 'lrob-email-toolkit'),
            'address_city'      => __('City', 'lrob-email-toolkit'),
            'address_region'    => __('State / region', 'lrob-email-toolkit'),
            'address_country'   => __('Country (ISO-2)', 'lrob-email-toolkit'),
            'gender'            => __('Gender', 'lrob-email-toolkit'),
            'language'          => __('Language', 'lrob-email-toolkit'),
            default             => $column,
        };
    }

    public static function gender_label(string $value): string
    {
        return match ($value) {
            'female'            => __('Female', 'lrob-email-toolkit'),
            'male'              => __('Male', 'lrob-email-toolkit'),
            'other'             => __('Other', 'lrob-email-toolkit'),
            'prefer_not_to_say' => __('Prefer not to say', 'lrob-email-toolkit'),
            default             => '',
        };
    }

    /**
     * Per-column sanitisation. Returns the cleaned value (or empty
     * string when input doesn't survive). Caller checks for empty before
     * persisting if it wants to reject blank writes.
     */
    public static function sanitize(string $column, string $value): string
    {
        $value = (string) wp_unslash($value);
        return match ($column) {
            'email'             => sanitize_email($value),
            'phone'             => preg_replace('/[^0-9+ \-().]/', '', $value) ?? '',
            'address_country'   => strtoupper(substr(sanitize_text_field($value), 0, 2)),
            'gender'            => in_array($value, self::GENDER_VALUES, true) ? $value : '',
            'language'          => sanitize_text_field($value),
            default             => sanitize_text_field($value),
        };
    }
}

<?php
declare(strict_types=1);

namespace LRob\EmailToolkit\Admin;

// Docs: docs/admin-ui.md (semantic colour states).

/**
 * Single source of truth mapping a domain status value → a semantic colour
 * state (on/off/fail/pending/info). Both the server-rendered pills and the
 * client-rebuilt ones (newsletter-cards.js) resolve through here — the map is
 * localized to JS via modifier_map() so the two render paths never drift.
 */
final class StatusPill
{
    public const STATE_ON      = 'on';
    public const STATE_OFF     = 'off';
    public const STATE_FAIL    = 'fail';
    public const STATE_PENDING = 'pending';
    public const STATE_INFO    = 'info';

    /** Domain status value → colour state. Faithful to the pre-refactor palette. */
    public static function modifier(string $status): string
    {
        return match ($status) {
            'sent', 'confirmed', 'opted_in', 'active' => self::STATE_ON,
            'failed', 'refused', 'bounced'            => self::STATE_FAIL,
            'pending', 'sending', 'paused'            => self::STATE_PENDING,
            'scheduled'                               => self::STATE_INFO,
            // draft, aborted, unsubscribed, opted_out, trashed, unknown
            default                                   => self::STATE_OFF,
        };
    }

    /** The full colour-state class for a status value. */
    public static function state_class(string $status): string
    {
        return 'lrob-etk-state--' . self::modifier($status);
    }

    /** value → state map for JS localization (the values clients rebuild). */
    public static function modifier_map(): array
    {
        $values = [
            'sent', 'confirmed', 'opted_in', 'active',
            'failed', 'refused', 'bounced',
            'pending', 'sending', 'paused',
            'scheduled',
            'draft', 'aborted', 'unsubscribed', 'opted_out', 'trashed',
        ];
        $map = [];
        foreach ($values as $v) {
            $map[$v] = self::modifier($v);
        }
        return $map;
    }
}

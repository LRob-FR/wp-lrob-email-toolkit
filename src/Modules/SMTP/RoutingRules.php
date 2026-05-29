<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

// Docs: docs/smtp.md
final class RoutingRules
{
    private const OPTION = 'lrob_etk_smtp_routing';

    public function __construct(private IdentityRepository $identities)
    {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? array_filter(
            array_map(static fn ($v) => is_string($v) ? $v : '', $stored),
            static fn ($v) => $v !== ''
        ) : [];
    }

    /** Falls back: source rule → SOURCE_DEFAULT rule → is_default=1 identity. Returns null when none usable. */
    public function resolve(string $source): ?Identity
    {
        $rules = $this->all();

        foreach ([$source, SourceResolver::SOURCE_DEFAULT] as $candidate) {
            if (!isset($rules[$candidate])) {
                continue;
            }
            $identity = $this->identities->find_by_slug($rules[$candidate]);
            if ($identity instanceof Identity && $identity->is_active) {
                return $identity;
            }
        }

        $default = $this->identities->find_default();
        return $default instanceof Identity && $default->is_active ? $default : null;
    }

    /** @param array<string, ?string> $rules */
    public function save(array $rules): void
    {
        $clean = [];
        foreach ($rules as $source => $slug) {
            if (!is_string($source) || $source === '') {
                continue;
            }
            if (is_string($slug) && $slug !== '') {
                $clean[$source] = $slug;
            }
        }
        update_option(self::OPTION, $clean);
    }

    public function set_rule(string $source, ?string $identity_slug): void
    {
        $rules = $this->all();
        if ($identity_slug === null || $identity_slug === '') {
            unset($rules[$source]);
        } else {
            $rules[$source] = $identity_slug;
        }
        update_option(self::OPTION, $rules);
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }
}

<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

// Docs: docs/smtp.md
final class DnsLookup
{
    private const RESOLVE_CACHE_TTL = HOUR_IN_SECONDS;

    private const RATE_WINDOW_SECONDS = 3600;

    private const RATE_MAX_CALLS = 60;

    public function resolves(string $host): bool
    {
        $host = $this->sanitize_host($host);
        if ($host === '') {
            return false;
        }

        $cache_key = 'lrob_etk_dns_a_' . md5($host);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached === '1';
        }

        if (!$this->rate_check()) {
            return false;
        }

        $a    = @dns_get_record($host, DNS_A);
        $aaaa = @dns_get_record($host, DNS_AAAA);
        $resolves = (is_array($a) && $a !== []) || (is_array($aaaa) && $aaaa !== []);

        set_transient($cache_key, $resolves ? '1' : '0', self::RESOLVE_CACHE_TTL);
        return $resolves;
    }

    /** @return array<int, array{host: string, priority: int}> MX targets + priority, in preference order */
    public function mx_records(string $domain): array
    {
        $domain = $this->sanitize_host($domain);
        if ($domain === '') {
            return [];
        }

        $cache_key = 'lrob_etk_dns_mxr_' . md5($domain);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        if (!$this->rate_check()) {
            return [];
        }

        $records = @dns_get_record($domain, DNS_MX);
        if (!is_array($records)) {
            $records = [];
        }

        usort($records, static function ($a, $b): int {
            return ((int) ($a['pri'] ?? 0)) <=> ((int) ($b['pri'] ?? 0));
        });

        $hosts = [];
        $seen = [];
        foreach ($records as $r) {
            $target = isset($r['target']) ? rtrim((string) $r['target'], '.') : '';
            if ($target === '' || in_array($target, $seen, true)) {
                continue;
            }
            $seen[] = $target;
            $hosts[] = ['host' => $target, 'priority' => (int) ($r['pri'] ?? 0)];
        }

        set_transient($cache_key, $hosts, self::RESOLVE_CACHE_TTL);
        return $hosts;
    }

    private function rate_check(): bool
    {
        $user_id = get_current_user_id();
        $key = 'lrob_etk_dns_rate_' . $user_id;
        $count = (int) get_transient($key);
        if ($count >= self::RATE_MAX_CALLS) {
            return false;
        }
        set_transient($key, $count + 1, self::RATE_WINDOW_SECONDS);
        return true;
    }

    private function sanitize_host(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        // Strip leading dots and anything not a valid domain character.
        if (!preg_match('/^[a-z0-9._-]+$/', $host)) {
            return '';
        }
        if (strlen($host) > 253) {
            return '';
        }
        return $host;
    }
}

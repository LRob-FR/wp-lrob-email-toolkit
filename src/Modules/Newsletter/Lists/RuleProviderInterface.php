<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter\Lists;

/**
 * Contract for rule-based list providers.
 *
 * A provider answers two questions:
 *   - what config fields does the admin need to fill in? (config_fields)
 *   - given a config payload, which WP user IDs match? (resolve_user_ids)
 *
 * Built-in providers ship in this namespace; third-party plugins can
 * register their own via the `lrob_etk_nl_list_rule_providers` filter
 * (consumed by RuleRegistry). The send-time Materializer unions the
 * resolved user IDs with the list's manual subscriber/user members,
 * so a single list can carry both manual entries AND a rule.
 *
 * Providers are expected to be cheap on `resolve_user_ids` — the
 * Materializer runs them inside a send loop and assumes the result fits
 * in memory. If a provider needs millions of rows, batch it externally
 * via a custom cron writing into the manual membership table.
 */
interface RuleProviderInterface
{
    /** Stable identifier persisted in rule_json (e.g. `wp_user_role`). */
    public function slug(): string;

    /** Human label for the admin picker. */
    public function label(): string;

    /** One-line description shown next to the picker. */
    public function description(): string;

    /**
     * Config schema. Returns an array of field descriptors:
     *
     *   [
     *     [
     *       'name'    => 'roles',
     *       'label'   => __('Roles', ...),
     *       'type'    => 'multiselect',  // 'text', 'select', 'multiselect', 'checkbox'
     *       'options' => ['administrator' => 'Administrator', ...],  // for select/multiselect
     *       'default' => [],
     *     ],
     *     ...
     *   ]
     *
     * The admin UI in ListsPage renders these fields generically; on save,
     * the field values are JSON-serialised into rule_json.config.
     *
     * @return array<int, array<string, mixed>>
     */
    public function config_fields(): array;

    /**
     * Validate + normalise a config payload posted from the admin UI.
     * Returns the cleaned config (any unknown keys stripped, defaults
     * applied). Throw or return `[]` to refuse the payload.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function sanitize_config(array $config): array;

    /**
     * Evaluate the rule and return matching WP user IDs.
     *
     * @param array<string, mixed> $config  cleaned payload from sanitize_config
     * @return array<int, int>
     */
    public function resolve_user_ids(array $config): array;
}

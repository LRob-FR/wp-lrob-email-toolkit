<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Admin;

use LRob\EmailToolkit\Admin\Combobox;
use LRob\EmailToolkit\Admin\PageHeader;
use LRob\EmailToolkit\Modules\Captcha\CaptchaService;
use LRob\EmailToolkit\Modules\Captcha\Identity;
use LRob\EmailToolkit\Modules\Captcha\Providers\ProviderInterface;
use LRob\EmailToolkit\Modules\Captcha\Routing;
use LRob\EmailToolkit\Modules\Captcha\StatsRepository;
use LRob\EmailToolkit\Modules\ModuleInterface;

/**
 * Captcha settings page. Three stacked sections:
 *
 *   1. Built-in challenges  — read-only "always available" list.
 *   2. Hosted providers     — card grid; one card per configured identity,
 *                             plus a "+ Add identity" launcher when at least
 *                             one provider class is registered.
 *   3. Routing               — global default dropdown + per-context
 *                             overrides. Routes that point at unconfigured
 *                             hosted providers are surfaced as disabled
 *                             "configure first" options.
 *
 * The card UI follows the SMTP pattern: existing cards auto-save on blur,
 * new cards have an explicit Create button. Routing dropdowns auto-save
 * on change.
 */
final class SettingsPage
{
    public function __construct(
        private ModuleInterface $module,
        private CaptchaService $service,
    ) {
    }

    /** @var array<string, array{passed:int, failed:int, total:int}> */
    private array $stats_breakdown = [];

    public function render(): void
    {
        $homemade = $this->service->homemade_challenges();
        $providers = $this->service->hosted_providers();
        $identities = $this->service->identity_repository()->all();
        $map = Routing::context_map();
        $default_route = isset($map[Routing::KEY_DEFAULT]) ? $map[Routing::KEY_DEFAULT] : Routing::ROUTE_NONE;
        $this->stats_breakdown = (new StatsRepository())->breakdown_by_route(30);

        ?>
        <div class="wrap lrob-etk lrob-etk-captcha-page">
            <?php PageHeader::render([
                'title'   => __('Captcha', 'lrob-email-toolkit'),
                'primary' => $providers !== [] ? [
                    'label' => __('New identity', 'lrob-email-toolkit'),
                    'icon'  => 'dashicons-plus-alt2',
                    'id'    => 'lrob-etk-captcha-add',
                ] : null,
            ]); ?>

            <div id="lrob-etk-flash" class="lrob-etk-flash" aria-live="polite"></div>

            <p class="description" style="max-width: 760px;">
                <?php esc_html_e('Anti-bot challenges shared across modules. Pick a default below, then optionally override it per use case (contact forms, comments, …). Built-in challenges work offline; hosted providers (hCaptcha, soon Turnstile / reCAPTCHA) need credentials added as identities.', 'lrob-email-toolkit'); ?>
            </p>

            <?php $this->render_builtins_section($homemade); ?>
            <?php $this->render_providers_section($providers, $identities); ?>
            <?php $this->render_routing_section($default_route, $map); ?>
            <?php $this->render_diagnostics_section($identities, $map); ?>

            <?php $this->render_master_card_template($providers); ?>
            <?php foreach ($providers as $provider) {
                $this->render_fields_template($provider);
            } ?>
        </div>

        <script>
        <?php $this->print_inline_js(); ?>
        </script>
        <?php
    }

    /**
     * @param array<string, \LRob\EmailToolkit\Modules\Captcha\Challenges\ChallengeInterface> $homemade
     */
    private function render_builtins_section(array $homemade): void
    {
        if ($homemade === []) {
            return;
        }
        $default_route = Routing::default_route();
        ?>
        <section class="lrob-etk-captcha-section">
            <h2 class="lrob-etk-section-title"><?php esc_html_e('Built-in challenges', 'lrob-email-toolkit'); ?></h2>
            <ul class="lrob-etk-captcha-builtins">
                <?php foreach ($homemade as $slug => $challenge) :
                    $route = Routing::homemade($slug);
                    $stats = $this->stats_breakdown[$route] ?? null;
                    $is_default = $default_route === $route;
                    ?>
                    <li>
                        <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                        <div>
                            <strong><?php echo esc_html($challenge->label()); ?></strong>
                            <p><?php echo esc_html($challenge->description()); ?></p>
                            <?php if ($stats !== null && $stats['total'] > 0) : ?>
                                <p class="lrob-etk-captcha-route-stats">
                                    <?php $this->render_route_counter($stats); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="lrob-etk-card-footer-default">
                            <?php self::render_default_marker($route, $is_default); ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    private static function render_default_marker(string $route, bool $is_default): void
    {
        if ($is_default) :
            ?>
            <span class="lrob-etk-default-badge">
                <span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
                <?php esc_html_e('Default', 'lrob-email-toolkit'); ?>
            </span>
            <?php
        else :
            ?>
            <button type="button"
                    class="lrob-etk-set-default"
                    data-set-default-route="<?php echo esc_attr($route); ?>">
                <span class="dashicons dashicons-star-empty" aria-hidden="true"></span>
                <?php esc_html_e('Set as default', 'lrob-email-toolkit'); ?>
            </button>
            <?php
        endif;
    }

    /**
     * @param array<string, ProviderInterface> $providers
     * @param array<int, Identity>             $identities
     */
    private function render_providers_section(array $providers, array $identities): void
    {
        ?>
        <section class="lrob-etk-captcha-section">
            <h2 class="lrob-etk-section-title"><?php esc_html_e('Hosted providers', 'lrob-email-toolkit'); ?></h2>

            <?php if ($providers === []) : ?>
                <p class="description"><?php esc_html_e('No hosted providers are registered yet.', 'lrob-email-toolkit'); ?></p>
            <?php else : ?>
                <?php if ($identities === []) : ?>
                    <p class="description lrob-etk-captcha-providers-empty">
                        <?php esc_html_e('No captchas configured yet. Click "Add captcha" to set one up.', 'lrob-email-toolkit'); ?>
                    </p>
                <?php endif; ?>

                <div class="lrob-etk-card-grid lrob-etk-captcha-identities" id="lrob-etk-captcha-identities">
                    <?php foreach ($identities as $identity) :
                        if (!isset($providers[$identity->provider_slug])) {
                            continue; // stale row pointing at an uninstalled provider
                        }
                        $this->render_identity_card($identity, $providers[$identity->provider_slug], $providers);
                    endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * @param array<string, ProviderInterface> $providers
     */
    private function render_identity_card(Identity $identity, ProviderInterface $provider, array $providers): void
    {
        $this->render_card_shell($identity, $provider, $providers, false);
    }

    /** @param array{passed:int, failed:int, total:int} $stats */
    private function render_route_counter(array $stats): void
    {
        ?>
        <span class="lrob-etk-captcha-stat" title="<?php esc_attr_e('Captcha activity over the last 30 days', 'lrob-email-toolkit'); ?>">
            <span class="lrob-etk-captcha-stat-blocked">
                <strong><?php echo esc_html(number_format_i18n($stats['failed'])); ?></strong>
                <?php esc_html_e('blocked', 'lrob-email-toolkit'); ?>
            </span>
            <span class="lrob-etk-captcha-stat-sep">·</span>
            <span class="lrob-etk-captcha-stat-passed">
                <strong><?php echo esc_html(number_format_i18n($stats['passed'])); ?></strong>
                <?php esc_html_e('passed', 'lrob-email-toolkit'); ?>
            </span>
            <span class="lrob-etk-captcha-stat-window"><?php esc_html_e('(30d)', 'lrob-email-toolkit'); ?></span>
        </span>
        <?php
    }

    /**
     * Master card template — single shape, provider chosen via the inline
     * dropdown. JS clones this on "Add captcha", then injects the right
     * credential fields by cloning the matching `render_fields_template`.
     *
     * @param array<string, ProviderInterface> $providers
     */
    private function render_master_card_template(array $providers): void
    {
        if ($providers === []) {
            return;
        }
        $first = array_key_first($providers);
        ?>
        <template id="lrob-etk-captcha-card-template">
            <?php $this->render_card_shell(null, $providers[$first], $providers, true); ?>
        </template>
        <?php
    }

    /**
     * Per-provider credential-fields snippet, used by JS to populate the
     * card body when a new card is created or the provider dropdown
     * changes on an unsaved card.
     */
    private function render_fields_template(ProviderInterface $provider): void
    {
        ?>
        <template class="lrob-etk-captcha-fields-template" data-provider="<?php echo esc_attr($provider->slug()); ?>">
            <?php $this->render_credential_fields($provider, true); ?>
        </template>
        <?php
    }

    /**
     * @param array<string, ProviderInterface> $providers
     */
    private function render_card_shell(?Identity $identity, ProviderInterface $provider, array $providers, bool $is_new): void
    {
        $id = $identity?->id ?? 0;
        $label = $identity?->label ?? '';
        $is_active = $identity ? $identity->is_active : true;
        $derived_slug = $identity?->derived_slug() ?? '';
        $can_swap_provider = $is_new && count($providers) > 1;

        // Site key is public-by-design — encoded in the rendered widget HTML
        // for visitors anyway — so it's safe to surface as a data attribute
        // for the JS preview. Secret keys never leave the server.
        $site_key = '';
        if ($identity !== null) {
            try {
                $creds = $identity->decrypted_credentials();
                $site_key = isset($creds['site_key']) ? (string) $creds['site_key'] : '';
            } catch (\RuntimeException) {
                // AUTH_KEY rotated or ciphertext tampered — surface no key;
                // the preview will fall back to the unsaved placeholder.
                $site_key = '';
            }
        }
        ?>
        <article class="lrob-etk-card lrob-etk-captcha-card<?php echo $is_new ? ' is-new' : ''; ?>"
                 data-identity-id="<?php echo (int) $id; ?>"
                 data-provider="<?php echo esc_attr($provider->slug()); ?>"
                 data-state="<?php echo $is_new ? 'new' : 'existing'; ?>"
                 data-site-key="<?php echo esc_attr($site_key); ?>">
            <form class="lrob-etk-card-form" novalidate>
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                <input type="hidden" name="provider_slug" value="<?php echo esc_attr($provider->slug()); ?>" data-provider-slug>

                <header class="lrob-etk-card-form-head">
                    <span class="lrob-etk-captcha-card-provider" data-provider-chip>
                        <span class="lrob-etk-captcha-card-logo" data-provider-logo><?php echo $provider->logo_html(); ?></span>
                        <span data-provider-label><?php echo esc_html($provider->label()); ?></span>
                    </span>
                    <input
                        type="text"
                        name="label"
                        class="lrob-etk-title-input lrob-etk-field-label"
                        value="<?php echo esc_attr($label); ?>"
                        placeholder="<?php esc_attr_e('e.g. Main site', 'lrob-email-toolkit'); ?>"
                        autocomplete="off">
                    <span class="lrob-etk-card-status" aria-live="polite"></span>
                    <label class="lrob-etk-inline-switch" title="<?php esc_attr_e('Active', 'lrob-email-toolkit'); ?>">
                        <input type="checkbox" name="is_active" value="1" <?php checked($is_active); ?>>
                        <span class="lrob-etk-switch-track"></span>
                        <span class="lrob-etk-inline-switch-label" data-on="<?php esc_attr_e('Active', 'lrob-email-toolkit'); ?>" data-off="<?php esc_attr_e('Inactive', 'lrob-email-toolkit'); ?>">
                            <?php echo $is_active
                                ? esc_html__('Active', 'lrob-email-toolkit')
                                : esc_html__('Inactive', 'lrob-email-toolkit'); ?>
                        </span>
                    </label>
                </header>

                <div class="lrob-etk-captcha-card-meta">
                    <span class="lrob-etk-captcha-card-slug" data-card-slug <?php echo $derived_slug === '' ? 'hidden' : ''; ?>>
                        <span class="dashicons dashicons-tag" aria-hidden="true"></span>
                        <code><?php echo esc_html($derived_slug); ?></code>
                    </span>
                    <?php
                    if ($identity !== null) {
                        $route = Routing::identity((int) $identity->id);
                        $stats = $this->stats_breakdown[$route] ?? null;
                        if ($stats !== null && $stats['total'] > 0) {
                            $this->render_route_counter($stats);
                        }
                    }
                    ?>
                </div>

                <?php if ($can_swap_provider) : ?>
                    <div class="lrob-etk-field lrob-etk-captcha-provider-pick-field" data-provider-pick-field>
                        <label for="lrob-etk-captcha-provider-pick-<?php echo (int) $id; ?>">
                            <?php esc_html_e('Provider', 'lrob-email-toolkit'); ?>
                        </label>
                        <select id="lrob-etk-captcha-provider-pick-<?php echo (int) $id; ?>" data-provider-pick>
                            <?php foreach ($providers as $slug => $p) : ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $provider->slug()); ?>>
                                    <?php echo esc_html($p->label()); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="lrob-etk-captcha-card-body" data-fields-container>
                    <?php $this->render_credential_fields($provider, $is_new, $identity); ?>
                </div>

                <div class="lrob-etk-captcha-card-preview" data-preview-container <?php echo $is_new ? 'hidden' : ''; ?>>
                    <div class="lrob-etk-captcha-card-preview-head">
                        <?php esc_html_e('Test', 'lrob-email-toolkit'); ?>
                        <span class="description"><?php esc_html_e('Solve below to verify your credentials.', 'lrob-email-toolkit'); ?></span>
                    </div>
                    <div class="lrob-etk-captcha-card-preview-widget" data-preview-widget></div>
                    <div class="lrob-etk-captcha-card-test-result" data-test-result hidden></div>
                </div>

                <footer class="lrob-etk-card-footer">
                    <div class="lrob-etk-card-footer-default">
                        <?php
                        if (!$is_new && $identity !== null && $identity->is_active) {
                            $route = Routing::identity((int) $identity->id);
                            self::render_default_marker($route, Routing::default_route() === $route);
                        }
                        ?>
                    </div>
                    <div class="lrob-etk-card-footer-actions">
                        <button type="button" class="button button-primary lrob-etk-card-create" data-action="create" <?php echo $is_new ? '' : 'hidden'; ?>>
                            <?php esc_html_e('Create', 'lrob-email-toolkit'); ?>
                        </button>
                        <button type="button" class="button lrob-etk-card-discard" data-action="discard" <?php echo $is_new ? '' : 'hidden'; ?>>
                            <?php esc_html_e('Discard', 'lrob-email-toolkit'); ?>
                        </button>
                        <button type="button" class="lrob-etk-card-delete-link" data-action="delete" data-id="<?php echo (int) $id; ?>" <?php echo $is_new ? 'hidden' : ''; ?>>
                            <?php esc_html_e('Delete', 'lrob-email-toolkit'); ?>
                        </button>
                    </div>
                </footer>
            </form>
        </article>
        <?php
    }

    private function render_credential_fields(ProviderInterface $provider, bool $is_new, ?Identity $identity = null): void
    {
        // Decrypt once per card render so the loop can pre-populate text
        // fields with the actual stored value (site keys are public — they
        // ride along in the rendered widget anyway) and show a dots
        // placeholder on password fields that already have a stored value.
        $stored_credentials = [];
        if ($identity !== null) {
            try {
                $stored_credentials = $identity->decrypted_credentials();
            } catch (\RuntimeException) {
                $stored_credentials = [];
            }
        }

        foreach ($provider->credential_fields() as $field) {
            $key = (string) $field['key'];
            $type = (string) ($field['type'] ?? 'text');
            $required = !empty($field['required']);
            $description = isset($field['description']) ? (string) $field['description'] : '';
            $is_secret = $type === 'password';

            $stored_value = isset($stored_credentials[$key]) ? (string) $stored_credentials[$key] : '';
            $has_stored = $stored_value !== '';

            // Public credential (e.g. hCaptcha site key) — pre-populate the
            // visible value so the admin can see what's saved and copy/edit
            // it. Secret credentials stay value="" + dots placeholder; an
            // empty submit is treated as "keep existing" server-side.
            $input_value = $is_secret ? '' : $stored_value;
            $placeholder = $is_secret && $has_stored && !$is_new
                ? str_repeat("\u{2022}", 10) // ten bullets — matches password manager UI
                : '';
            ?>
            <div class="lrob-etk-field">
                <label>
                    <?php echo esc_html((string) $field['label']); ?>
                    <?php if ($required) : ?><span class="lrob-etk-required" aria-hidden="true">*</span><?php endif; ?>
                </label>
                <input
                    type="<?php echo esc_attr($type); ?>"
                    name="credentials[<?php echo esc_attr($key); ?>]"
                    data-credential-key="<?php echo esc_attr($key); ?>"
                    value="<?php echo esc_attr($input_value); ?>"
                    autocomplete="<?php echo $is_secret ? 'new-password' : 'off'; ?>"
                    placeholder="<?php echo esc_attr($placeholder); ?>">
                <?php if ($description !== '') : ?>
                    <p class="description"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
                <p class="lrob-etk-cf-error" data-field-error hidden></p>
            </div>
            <?php
        }
    }

    /**
     * Diagnostic strip — collapsed by default — that surfaces what's
     * actually stored for each identity (credentials_encrypted length,
     * whether decryption succeeds, which keys appear) and what the
     * resolved route is for each context. Helps the admin tell apart
     * "credentials never persisted" / "AUTH_KEY rotated" /
     * "routing-key points at wrong identity" without phpMyAdmin.
     *
     * @param array<int, Identity>   $identities
     * @param array<string, string>  $map
     */
    private function render_diagnostics_section(array $identities, array $map): void
    {
        ?>
        <section class="lrob-etk-captcha-section">
            <details class="lrob-etk-captcha-diag">
                <summary>
                    <span class="dashicons dashicons-info" aria-hidden="true"></span>
                    <?php esc_html_e('Diagnostics', 'lrob-email-toolkit'); ?>
                </summary>
                <div class="lrob-etk-captcha-diag-body">
                    <h3><?php esc_html_e('Stored identities', 'lrob-email-toolkit'); ?></h3>
                    <?php if ($identities === []) : ?>
                        <p class="description"><?php esc_html_e('No identities saved.', 'lrob-email-toolkit'); ?></p>
                    <?php else : ?>
                        <table class="lrob-etk-captcha-diag-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('ID', 'lrob-email-toolkit'); ?></th>
                                    <th><?php esc_html_e('Provider', 'lrob-email-toolkit'); ?></th>
                                    <th><?php esc_html_e('Label', 'lrob-email-toolkit'); ?></th>
                                    <th><?php esc_html_e('Active', 'lrob-email-toolkit'); ?></th>
                                    <th><?php esc_html_e('Blob (chars)', 'lrob-email-toolkit'); ?></th>
                                    <th><?php esc_html_e('Decrypt', 'lrob-email-toolkit'); ?></th>
                                    <th><?php esc_html_e('Keys present', 'lrob-email-toolkit'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($identities as $identity) :
                                    $blob_len = strlen($identity->credentials_encrypted);
                                    $decrypt_status = '—';
                                    $keys_present = '—';
                                    if ($blob_len > 0) {
                                        try {
                                            $creds = $identity->decrypted_credentials();
                                            $decrypt_status = '✓';
                                            $keys_present = $creds === []
                                                ? __('(decoded but empty)', 'lrob-email-toolkit')
                                                : implode(', ', array_keys($creds));
                                        } catch (\Throwable $e) {
                                            $decrypt_status = '✗ ' . $e->getMessage();
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $identity->id; ?></td>
                                        <td><code><?php echo esc_html($identity->provider_slug); ?></code></td>
                                        <td><?php echo esc_html($identity->label); ?></td>
                                        <td><?php echo $identity->is_active ? '✓' : '✗'; ?></td>
                                        <td><?php echo (int) $blob_len; ?></td>
                                        <td><?php echo esc_html($decrypt_status); ?></td>
                                        <td><?php echo esc_html($keys_present); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h3 style="margin-top: 16px;"><?php esc_html_e('Routing resolution', 'lrob-email-toolkit'); ?></h3>
                    <table class="lrob-etk-captcha-diag-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Context', 'lrob-email-toolkit'); ?></th>
                                <th><?php esc_html_e('Stored route', 'lrob-email-toolkit'); ?></th>
                                <th><?php esc_html_e('Resolves to', 'lrob-email-toolkit'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong><?php esc_html_e('Default', 'lrob-email-toolkit'); ?></strong></td>
                                <td><code><?php echo esc_html(isset($map[Routing::KEY_DEFAULT]) ? $map[Routing::KEY_DEFAULT] : '(unset)'); ?></code></td>
                                <td><?php echo esc_html($this->describe_resolved_route(Routing::default_route())); ?></td>
                            </tr>
                            <?php foreach (Routing::known_contexts() as $context) :
                                $stored = isset($map[$context]) ? $map[$context] : '(unset)';
                                ?>
                                <tr>
                                    <td><?php echo esc_html(Routing::context_label($context)); ?></td>
                                    <td><code><?php echo esc_html($stored); ?></code></td>
                                    <td><?php echo esc_html($this->describe_resolved_route(Routing::effective_route($context))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </section>
        <?php
    }

    /**
     * Human-readable summary of what a routing key resolves to, so the
     * diagnostics table reads naturally ("Identity #5 (hCaptcha · Main,
     * 132 chars stored, decrypts OK)" rather than just "identity:5").
     */
    private function describe_resolved_route(string $route): string
    {
        if ($route === Routing::ROUTE_NONE || $route === '') {
            return __('No challenge', 'lrob-email-toolkit');
        }
        $parsed = Routing::parse($route);
        if ($parsed['kind'] === Routing::KIND_HOMEMADE) {
            $homemade = $this->service->homemade_challenges();
            if (isset($homemade[$parsed['value']])) {
                return $homemade[$parsed['value']]->label() . ' (built-in)';
            }
            /* translators: %s: homemade challenge slug */
            return sprintf(__('Unknown built-in challenge "%s"', 'lrob-email-toolkit'), $parsed['value']);
        }
        if ($parsed['kind'] === Routing::KIND_IDENTITY) {
            $id = (int) $parsed['value'];
            $identity = $this->service->identity_repository()->find($id);
            if ($identity === null) {
                /* translators: %d: identity row id that no longer exists */
                return sprintf(__('Identity #%d (not found)', 'lrob-email-toolkit'), $id);
            }
            $providers = $this->service->hosted_providers();
            $provider_label = isset($providers[$identity->provider_slug]) ? $providers[$identity->provider_slug]->label() : $identity->provider_slug;
            try {
                $creds = $identity->decrypted_credentials();
                $cred_summary = $creds === [] ? __('decoded empty', 'lrob-email-toolkit') : implode(',', array_keys($creds));
            } catch (\Throwable) {
                $cred_summary = __('decrypt failed', 'lrob-email-toolkit');
            }
            return sprintf(
                /* translators: 1: identity id, 2: provider label, 3: identity label, 4: creds summary */
                __('Identity #%1$d · %2$s · %3$s (%4$s)', 'lrob-email-toolkit'),
                $id,
                $provider_label,
                $identity->label,
                $cred_summary
            );
        }
        return $route;
    }

    /** @param array<string, string> $map */
    private function render_routing_section(string $default_route, array $map): void
    {
        $default_label = $this->resolve_route_label($default_route);
        ?>
        <section class="lrob-etk-captcha-section lrob-etk-captcha-routing">
            <h2 class="lrob-etk-section-title"><?php esc_html_e('Captcha assignments', 'lrob-email-toolkit'); ?></h2>
            <p class="description" style="max-width: 720px;">
                <?php esc_html_e('Pick the default challenge for the whole site, then pick a per-context override below — leave a context on "None" to skip the captcha there.', 'lrob-email-toolkit'); ?>
            </p>

            <div class="lrob-etk-captcha-routing-grid">
                <?php
                $default_options = $this->route_options_for_combobox(false, false, '');
                ?>
                <div class="lrob-etk-captcha-routing-row lrob-etk-captcha-routing-default"
                     data-routing-key="<?php echo esc_attr(Routing::KEY_DEFAULT); ?>">
                    <span class="lrob-etk-captcha-routing-context-label">
                        <?php esc_html_e('Default challenge for the whole site', 'lrob-email-toolkit'); ?>
                    </span>
                    <?php Combobox::render_fixed_select('lrob_etk_captcha_route__' . Routing::KEY_DEFAULT, $default_route, $default_options, '', ''); ?>
                </div>

                <?php foreach (Routing::known_contexts() as $context) :
                    $current = $map[$context] ?? Routing::ROUTE_NONE;
                    $options = $this->route_options_for_combobox(true, true, $default_label);
                    ?>
                    <div class="lrob-etk-captcha-routing-row"
                         data-routing-key="<?php echo esc_attr($context); ?>">
                        <span class="lrob-etk-captcha-routing-context-label">
                            <?php echo esc_html(Routing::context_label($context)); ?>
                        </span>
                        <?php Combobox::render_fixed_select('lrob_etk_captcha_route__' . $context, $current, $options, Routing::ROUTE_INHERIT, ''); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * Combobox option list for the routing pickers.
     *
     * @return array<int, array{value:string, label:string}>
     */
    private function route_options_for_combobox(bool $include_inherit, bool $include_none, string $default_label): array
    {
        $options = [];
        if ($include_inherit) {
            $inherit_label = $default_label !== ''
                ? sprintf(
                    /* translators: %s: human-readable label of the currently-default challenge (e.g. "Math challenge", "hCaptcha: Production") */
                    __('Inherit default (%s)', 'lrob-email-toolkit'),
                    $default_label
                )
                : __('Inherit default', 'lrob-email-toolkit');
            $options[] = ['value' => Routing::ROUTE_INHERIT, 'label' => $inherit_label];
        }
        if ($include_none) {
            $options[] = ['value' => Routing::ROUTE_NONE, 'label' => __('None — no captcha here', 'lrob-email-toolkit')];
        }
        $builtin_prefix = __('Built-in', 'lrob-email-toolkit');
        foreach ($this->service->homemade_challenges() as $slug => $challenge) {
            $options[] = [
                'value' => Routing::homemade($slug),
                'label' => sprintf('%s: %s', $builtin_prefix, (string) $challenge->label()),
            ];
        }
        $providers = $this->service->hosted_providers();
        $by_provider = $this->identities_grouped();
        foreach ($providers as $slug => $provider) {
            $rows = $by_provider[$slug] ?? [];
            foreach ($rows as $identity) {
                if (!$identity->is_active) {
                    continue;
                }
                $name = $identity->label !== '' ? $identity->label : (string) $provider->label();
                $options[] = [
                    'value' => Routing::identity((int) $identity->id),
                    'label' => sprintf('%s: %s', $provider->label(), $name),
                ];
            }
        }
        return $options;
    }

    /**
     * Resolve a route key to a human-readable label, used by the
     * "Inherit default (X)" hint so admins see what they're inheriting.
     * Returns an empty string for 'none' / 'inherit' / unresolvable routes.
     */
    private function resolve_route_label(string $route): string
    {
        if ($route === Routing::ROUTE_NONE || $route === Routing::ROUTE_INHERIT) {
            return '';
        }
        $parsed = Routing::parse($route);
        if ($parsed['kind'] === Routing::KIND_HOMEMADE) {
            $homemade = $this->service->homemade_challenges();
            $slug = $parsed['value'];
            return isset($homemade[$slug]) ? (string) $homemade[$slug]->label() : '';
        }
        if ($parsed['kind'] === Routing::KIND_IDENTITY) {
            $id = (int) $parsed['value'];
            $providers = $this->service->hosted_providers();
            foreach ($this->service->identity_repository()->all() as $identity) {
                if ((int) $identity->id !== $id) {
                    continue;
                }
                $provider = $providers[$identity->provider_slug] ?? null;
                $type = $provider !== null ? (string) $provider->label() : $identity->provider_slug;
                $name = $identity->label !== '' ? $identity->label : $type;
                return sprintf('%s: %s', $type, $name);
            }
        }
        return '';
    }

    /** @return array<string, array<int, Identity>> */
    private function identities_grouped(): array
    {
        $grouped = [];
        foreach ($this->service->identity_repository()->all() as $identity) {
            $grouped[$identity->provider_slug][] = $identity;
        }
        return $grouped;
    }

    private function print_inline_js(): void
    {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce(AjaxController::NONCE_ACTION);

        // Each hosted provider whose class defines a SCRIPT_URL constant
        // exposes its vendor JS so the admin preview can load the widget
        // dynamically. Falls out by reflection so new providers plug in
        // without touching this method.
        $provider_scripts = [];
        foreach ($this->service->hosted_providers() as $slug => $provider) {
            $cls = $provider::class;
            if (defined($cls . '::SCRIPT_URL')) {
                $provider_scripts[$slug] = (string) constant($cls . '::SCRIPT_URL');
            }
        }
        ?>
        window.lrobEtkCaptcha = {
            ajaxUrl: <?php echo wp_json_encode($ajax_url); ?>,
            nonce: <?php echo wp_json_encode($nonce); ?>,
            actions: {
                saveIdentity:   <?php echo wp_json_encode(AjaxController::ACTION_SAVE_IDENTITY); ?>,
                deleteIdentity: <?php echo wp_json_encode(AjaxController::ACTION_DELETE_IDENTITY); ?>,
                saveRouting:    <?php echo wp_json_encode(AjaxController::ACTION_SAVE_ROUTING); ?>,
                testIdentity:   <?php echo wp_json_encode(AjaxController::ACTION_TEST_IDENTITY); ?>,
                setDefault:     <?php echo wp_json_encode(AjaxController::ACTION_SET_DEFAULT); ?>
            },
            // Per-action nonces for destructive endpoints (defense in
            // depth on top of the module nonce + capability gate).
            actionNonces: {
                deleteIdentity: <?php echo wp_json_encode(wp_create_nonce(AjaxController::ACTION_DELETE_IDENTITY)); ?>,
                saveRouting:    <?php echo wp_json_encode(wp_create_nonce(AjaxController::ACTION_SAVE_ROUTING)); ?>,
                setDefault:     <?php echo wp_json_encode(wp_create_nonce(AjaxController::ACTION_SET_DEFAULT)); ?>
            },
            providerScripts: <?php echo wp_json_encode($provider_scripts); ?>,
            i18n: {
                saving:          <?php echo wp_json_encode(__('Saving…', 'lrob-email-toolkit')); ?>,
                saved:           <?php echo wp_json_encode(__('Saved', 'lrob-email-toolkit')); ?>,
                failed:          <?php echo wp_json_encode(__('Save failed', 'lrob-email-toolkit')); ?>,
                confirmDelete:   <?php echo wp_json_encode(__('Delete this captcha?', 'lrob-email-toolkit')); ?>,
                labelRequired:   <?php echo wp_json_encode(__('Label is required.', 'lrob-email-toolkit')); ?>,
                previewUnsaved:  <?php echo wp_json_encode(__('Save your credentials to load the captcha widget here.', 'lrob-email-toolkit')); ?>,
                testing:         <?php echo wp_json_encode(__('Verifying…', 'lrob-email-toolkit')); ?>,
                testWorks:       <?php echo wp_json_encode(__('✓ Captcha works', 'lrob-email-toolkit')); ?>,
                testFailed:      <?php echo wp_json_encode(__('✗ Verification failed', 'lrob-email-toolkit')); ?>,
                /* translators: %s: human-readable label of the currently-default challenge (e.g. "Math challenge", "hCaptcha: Production") */
                inheritLabelTemplate: <?php echo wp_json_encode(__('Inherit default (%s)', 'lrob-email-toolkit')); ?>,
                inheritLabelEmpty:    <?php echo wp_json_encode(__('Inherit default (none — site-wide default is off)', 'lrob-email-toolkit')); ?>
            }
        };
        <?php
    }
}

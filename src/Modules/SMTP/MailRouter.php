<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use LRob\EmailToolkit\Support\Events;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Reconfigures the WordPress-bundled PHPMailer to route through a configured
 * SMTP identity. Hooks the standard wp_mail lifecycle filters/actions and
 * dispatches the plugin's email.* events.
 *
 * Identity resolution flow per wp_mail() call:
 *   1. SourceResolver picks the source (default / woocommerce / pushed)
 *   2. RoutingRules maps source → identity (falls back to default identity)
 *   3. ConstantOverrides applies wp-config overrides on the default identity
 *
 * Resolution is cached for the duration of one wp_mail() and cleared by the
 * success/failure callback so subsequent calls re-resolve.
 */
final class MailRouter
{
    /** Identity in use for the wp_mail() call currently in flight. */
    private ?Identity $current_identity = null;

    private bool $current_resolved = false;

    /** When set, overrides routing for the very next wp_mail() resolution. */
    private ?string $forced_identity_slug = null;

    public function __construct(
        private IdentityRepository $identities,
        private RoutingRules $routing,
        private SourceResolver $source_resolver,
        private ConstantOverrides $constant_overrides,
    ) {
    }

    public function register(): void
    {
        add_action('phpmailer_init', [$this, 'configure_mailer'], 9);
        add_filter('wp_mail_from', [$this, 'override_from_email'], 9);
        add_filter('wp_mail_from_name', [$this, 'override_from_name'], 9);
        add_action('wp_mail_succeeded', [$this, 'on_succeeded']);
        add_action('wp_mail_failed', [$this, 'on_failed']);

        // Wrap WooCommerce's mail callback so wp_mail() inside it sees source 'woocommerce'.
        add_filter('woocommerce_mail_callback', [$this, 'wrap_woocommerce_callback']);
    }

    public function configure_mailer(PHPMailer $mailer): void
    {
        $identity = $this->resolve_identity();
        if (!$identity instanceof Identity) {
            return;
        }
        if (!$identity->is_active || $identity->smtp_host === '') {
            return;
        }

        try {
            $mailer->isSMTP();
            $mailer->Host = $identity->smtp_host;
            $mailer->Port = $identity->smtp_port;
            $mailer->SMTPAuth = $identity->smtp_auth;
            if ($identity->smtp_auth) {
                $mailer->Username = $identity->smtp_username;
                $mailer->Password = $identity->decrypted_password();
            }
            $mailer->SMTPSecure = $identity->smtp_encryption;
            $mailer->setFrom($identity->from_email, $identity->from_name, false);

            if ($identity->reply_to_email !== null && $identity->reply_to_email !== '') {
                $mailer->addReplyTo($identity->reply_to_email);
            }

            Events::dispatch('email.sending', [
                'identity_id'   => $identity->id,
                'identity_slug' => $identity->slug,
                'source'        => $this->source_resolver->resolve(),
            ]);
        } catch (\Throwable $e) {
            // Don't break wp_mail — let WordPress fall back to mail() transport.
            error_log('[lrob-etk] SMTP configure failed: ' . $e->getMessage());
        }
    }

    public function override_from_email(string $email): string
    {
        $identity = $this->resolve_identity();
        if ($identity instanceof Identity && $identity->force_from && $identity->from_email !== '') {
            return $identity->from_email;
        }
        return $email;
    }

    public function override_from_name(string $name): string
    {
        $identity = $this->resolve_identity();
        if ($identity instanceof Identity && $identity->force_from && $identity->from_name !== '') {
            return $identity->from_name;
        }
        return $name;
    }

    /**
     * @param array<string, mixed> $mail_data WordPress passes a hash with keys
     *        to, subject, message, headers, attachments.
     */
    public function on_succeeded(array $mail_data): void
    {
        $identity = $this->current_identity;
        Events::dispatch('email.sent', [
            'identity_id'   => $identity?->id,
            'identity_slug' => $identity?->slug,
            'to'            => $mail_data['to'] ?? [],
            'subject'       => $mail_data['subject'] ?? '',
            'source'        => $this->source_resolver->resolve(),
        ]);
        $this->reset_current();
    }

    public function on_failed(\WP_Error $error): void
    {
        $identity = $this->current_identity;
        $data = $error->get_error_data();

        Events::dispatch('email.failed', [
            'identity_id'   => $identity?->id,
            'identity_slug' => $identity?->slug,
            'error_code'    => $error->get_error_code(),
            'error_message' => $error->get_error_message(),
            'to'            => is_array($data) && isset($data['to']) ? $data['to'] : [],
            'subject'       => is_array($data) && isset($data['subject']) ? $data['subject'] : '',
            'source'        => $this->source_resolver->resolve(),
        ]);
        $this->reset_current();
    }

    public function wrap_woocommerce_callback(mixed $callback): mixed
    {
        if (!is_callable($callback)) {
            return $callback;
        }
        return static function (...$args) use ($callback) {
            SourceResolver::push(SourceResolver::SOURCE_WOOCOMMERCE);
            try {
                return $callback(...$args);
            } finally {
                SourceResolver::pop();
            }
        };
    }

    /**
     * Force the next wp_mail() to use a specific identity, bypassing routing.
     * Used by the admin "Send test email" button to test an identity that may
     * not be the default for any source. Pass null to clear.
     */
    public function force_identity(?string $slug): void
    {
        $this->forced_identity_slug = $slug;
        // Reset any cached resolution so the forced choice takes effect now.
        $this->reset_current();
    }

    /**
     * Lazily resolve the identity for the current wp_mail() call. Cached for
     * the lifetime of one mail dispatch (reset by on_succeeded / on_failed).
     */
    private function resolve_identity(): ?Identity
    {
        if ($this->current_resolved) {
            return $this->current_identity;
        }
        $this->current_resolved = true;

        if ($this->forced_identity_slug !== null) {
            $forced = $this->identities->find_by_slug($this->forced_identity_slug);
            if ($forced instanceof Identity) {
                return $this->current_identity = $this->constant_overrides->apply($forced);
            }
        }

        $source = $this->source_resolver->resolve();
        $identity = $this->routing->resolve($source);
        if ($identity instanceof Identity) {
            $identity = $this->constant_overrides->apply($identity);
        }
        return $this->current_identity = $identity;
    }

    private function reset_current(): void
    {
        $this->current_identity = null;
        $this->current_resolved = false;
    }
}

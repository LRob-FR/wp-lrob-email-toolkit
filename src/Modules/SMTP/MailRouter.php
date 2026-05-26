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
    /** Identity for the in-flight wp_mail() call. */
    private ?Identity $current_identity = null;

    private bool $current_resolved = false;

    /** When set, overrides routing for the very next wp_mail() resolution. */
    private ?string $forced_identity_slug = null;

    /**
     * Set by the `wp_mail` filter before the per-call filters run — tells
     * us whether the caller passed an explicit `From:` header. Drives
     * OVERRIDE_WHEN_DEFAULT behaviour. Reset on each wp_mail invocation.
     */
    private bool $caller_set_from = false;

    public function __construct(
        private IdentityRepository $identities,
        private RoutingRules $routing,
        private SourceResolver $source_resolver,
        private ConstantOverrides $constant_overrides,
    ) {
    }

    public function register(): void
    {
        // wp_mail filter fires first and gives us $args['headers'] —
        // sniff for a caller-set `From:` header so OVERRIDE_WHEN_DEFAULT
        // can decide whether to step in. Filter is invoked even when the
        // caller passes no headers; we just store false then.
        add_filter('wp_mail', [$this, 'capture_caller_from'], 1);
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
        if (!$identity->is_active) {
            return;
        }

        // Mail() transport: skip SMTP wiring entirely; PHPMailer stays on its
        // default mail() transport. The wp_mail_from / wp_mail_from_name
        // filters still apply, so the user gets From/Reply-to overrides + logging.
        if ($identity->uses_mail_transport()) {
            Events::dispatch('email.sending', [
                'identity_id'   => $identity->id,
                'identity_slug' => $identity->slug,
                'source'        => $this->source_resolver->resolve(),
                'transport'     => 'mail',
            ]);
            return;
        }

        if ($identity->smtp_host === '') {
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
            // Same override-mode gate as the wp_mail_from filter pair below.
            // Without this, configure_mailer's setFrom would stomp anything
            // the caller set, regardless of the override_mode the admin chose.
            if ($this->should_override_sender($identity)) {
                $mailer->setFrom($identity->effective_from_email(), $identity->effective_from_name(), false);
            }

            if ($identity->reply_to_email !== null && $identity->reply_to_email !== '') {
                $mailer->addReplyTo($identity->reply_to_email);
            }

            Events::dispatch('email.sending', [
                'identity_id'   => $identity->id,
                'identity_slug' => $identity->slug,
                'source'        => $this->source_resolver->resolve(),
                'transport'     => 'smtp',
            ]);
        } catch (\Throwable $e) {
            // Don't break wp_mail — let WordPress fall back to mail() transport.
            error_log('[lrob-etk] SMTP configure failed: ' . $e->getMessage());
        }
    }

    public function override_from_email(string $email): string
    {
        $identity = $this->resolve_identity();
        if ($identity instanceof Identity && $this->should_override_sender($identity)) {
            $resolved = $identity->effective_from_email();
            if ($resolved !== '') {
                return $resolved;
            }
        }
        return $email;
    }

    public function override_from_name(string $name): string
    {
        $identity = $this->resolve_identity();
        if ($identity instanceof Identity && $this->should_override_sender($identity)) {
            $resolved = $identity->effective_from_name();
            if ($resolved !== '') {
                return $resolved;
            }
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
        $this->caller_set_from = false;
    }

    /**
     * Walk the wp_mail $args['headers'] looking for a `From:` line. Lets
     * OVERRIDE_WHEN_DEFAULT distinguish "caller wanted a specific sender"
     * from "caller didn't care, WordPress filled in wordpress@hostname".
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function capture_caller_from(array $args): array
    {
        $headers = $args['headers'] ?? '';
        if (is_string($headers)) {
            $headers = $headers === '' ? [] : explode("\n", str_replace("\r\n", "\n", $headers));
        }
        $this->caller_set_from = false;
        if (is_array($headers)) {
            foreach ($headers as $line) {
                if (is_string($line) && stripos(ltrim($line), 'from:') === 0) {
                    $this->caller_set_from = true;
                    break;
                }
            }
        }
        return $args;
    }

    /**
     * Per-identity override gate. Three modes:
     *  - never        — keep the caller's value (or WP default).
     *  - when_default — keep the caller's value only if they explicitly
     *                   set one; otherwise step in.
     *  - always       — step in unconditionally.
     */
    private function should_override_sender(Identity $identity): bool
    {
        $mode = $identity->override_mode;
        if ($mode === Identity::OVERRIDE_NEVER) {
            return false;
        }
        if ($mode === Identity::OVERRIDE_ALWAYS) {
            return true;
        }
        // OVERRIDE_WHEN_DEFAULT — only step in when the caller didn't.
        return !$this->caller_set_from;
    }
}

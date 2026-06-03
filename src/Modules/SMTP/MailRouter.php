<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\SMTP;

use LRob\EmailToolkit\Support\Events;
use PHPMailer\PHPMailer\PHPMailer;

// Docs: docs/smtp.md
final class MailRouter
{
    private ?Identity $current_identity = null;

    private bool $current_resolved = false;

    private ?string $forced_identity_slug = null;

    private ?string $forced_from_name = null;

    /** True when the caller passed an explicit From: header; drives OVERRIDE_WHEN_DEFAULT. */
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

        if ($identity->uses_mail_transport()) {
            Events::dispatch('email.sending', [
                'identity_id'      => $identity->id,
                'identity_slug'    => $identity->slug,
                'source'           => $this->source_resolver->resolve(),
                'transport'        => 'mail',
                'save_attachments' => $identity->save_attachments,
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
            // setFrom gate: must match the wp_mail_from filter pair — see docs/smtp.md.
            if ($this->should_override_sender($identity)) {
                $from_name = $this->forced_from_name ?? $identity->effective_from_name();
                $mailer->setFrom($identity->effective_from_email(), $from_name, false);
            }

            if ($identity->reply_to_email !== null && $identity->reply_to_email !== '') {
                $mailer->addReplyTo($identity->reply_to_email);
            }

            Events::dispatch('email.sending', [
                'identity_id'      => $identity->id,
                'identity_slug'    => $identity->slug,
                'source'           => $this->source_resolver->resolve(),
                'transport'        => 'smtp',
                'save_attachments' => $identity->save_attachments,
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
        if ($this->forced_from_name !== null) {
            return $this->forced_from_name;
        }
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

    /** Bypass routing for the next wp_mail(); used by TestSender. Pass null to clear. */
    public function force_identity(?string $slug): void
    {
        $this->forced_identity_slug = $slug;
        // Reset any cached resolution so the forced choice takes effect now.
        $this->reset_current();
    }

    /** Force a display From-name for the upcoming wp_mail(s), independent of the identity. Empty/null clears. */
    public function force_from_name(?string $name): void
    {
        $this->forced_from_name = ($name === '' ? null : $name);
    }

    /**
     * Convenience for callers that send under an explicit identity + sender
     * override (the Newsletter pipeline, real send AND test): resolve the
     * numeric identity id to its slug and force it, plus an optional From-name.
     * id 0 / empty name → leave that aspect on the default routing. Pair with
     * clear_forced_send() in a finally.
     */
    public function force_send(int $identity_id, string $from_name_override = ''): void
    {
        if ($identity_id > 0) {
            $identity = $this->identities->find($identity_id);
            if ($identity instanceof Identity) {
                $this->force_identity($identity->slug);
            }
        }
        if ($from_name_override !== '') {
            $this->force_from_name($from_name_override);
        }
    }

    public function clear_forced_send(): void
    {
        $this->force_identity(null);
        $this->force_from_name(null);
    }

    /** Cached for the lifetime of one wp_mail() call; reset by on_succeeded / on_failed. */
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

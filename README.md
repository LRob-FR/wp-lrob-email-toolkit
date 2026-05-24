# LRob — Email Toolkit

> All-in-one modular email plugin for WordPress. SMTP routing, email logging, customizable contact forms, multi-context captcha, a full newsletter platform with tracking + segmentation, and webhook integrations — all from one plugin, no SaaS, no per-subscriber fees.

## Status

**Public alpha — v0.3.x.** Five modules are in the codebase today: SMTP, Email Logging, Contact Form, Captcha, and Newsletter. The Newsletter module is alpha and being hardened — field testing is welcome, please report any issue through GitHub issues.

Pre-1.0, so the schema can still change between minor versions — see [Versioning](#versioning).

For the full feature history, see [`completed.md`](./completed.md).
For the backlog, see [`todo.md`](./todo.md).

## What it is

A single plugin that replaces the typical stack of *SMTP plugin + email logger + contact-form plugin + captcha plugin + newsletter plugin (+ Mailchimp/Brevo subscription)*, with consistent design, shared SMTP routing, and a shared event vocabulary. Each module is independently activatable — only the parts you enable add code to the runtime.

No external libraries at runtime: no Composer, no React, no build pipeline. Plain PHP 8.1+, vanilla JS, server-rendered admin UI. The ~620 KB release zip is the entire plugin.

## Modules

| Module | Status | What it does |
|---|---|---|
| **SMTP** | ✅ shipped | Route `wp_mail()` through one or more configured SMTP servers. Multiple "from" identities, per-source routing rules, `wp-config.php` constant overrides, AES-256-GCM-encrypted passwords at rest, native PHP `mail()` transport as fallback. |
| **Email Logging** | ✅ shipped | Log every outgoing email (headers, body, attachments, status, errors). Browse / search / filter / resend in the admin. Configurable retention. Activity charts on the dashboard. |
| **Contact Form** | ✅ shipped | Customizable forms with a from-scratch WYSIWYG editor (drag & drop, columns, inline settings, autosave, undo/redo). Stacked anti-spam: honeypot, time-trap, rate-limit, captcha. Starter templates. Per-form recipients, Reply-To picking, subject templates, success-message templates. Submissions inbox with captcha-outcome tracking + filters + detail view. |
| **Captcha** | ✅ shipped (service module) | Shared captcha service consumed by Contact Form + Newsletter sign-up forms + WordPress comments + lost-password + registration. Per-context assignments with the **Make as default** badge — pick the default site-wide then optionally override per use case. Built-in math + picture-recognition challenges. **hCaptcha** provider shipped; Cloudflare Turnstile and Google reCAPTCHA designed to plug in (multi-identity, encrypted credentials). |
| **Newsletter** | ⚗️ alpha (v0.3.x) | Newsletters to WordPress users and email-only subscribers. Manual + rule-based lists with per-category opt-outs. Double-opt-in subscribe forms with RFC 8058 one-click unsubscribe. Throttled AJAX+Cron send pipeline with SMTP circuit-breaker. Open + click tracking via HMAC-signed REST endpoints (no per-recipient asset rows — small per-newsletter side-tables). Per-subscriber lifetime engagement stats + cold-subscriber detection + bulk-unsubscribe. Recipients drawer with status filtering + Logging cross-links. Trash/restore. WP-Cron health diagnostic. Field testing welcome. |
| **Integrations** | ⏳ planned | Outbound webhooks to n8n, Slack, Discord, Matrix and generic endpoints. Each module already emits events from v0.0.1 — devs can hook them today via WordPress actions, no module needed. |

## Requirements

- PHP **8.1+**
- WordPress **6.0+**
- WooCommerce **8.0+** *(only required if you'll use WooCommerce-based segmentation in the Newsletter module — HPOS supported, integration planned)*

## Languages

- 🇬🇧 English (source)
- 🇫🇷 French (`fr_FR`) — 100% coverage

## Install

Two ways:

**From a release:**

1. Download `lrob-email-toolkit-<version>.zip` from the [Releases](https://github.com/LRob-FR/wp-lrob-email-toolkit/releases) page.
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Activate.

**From source:**

1. `git clone` this repo.
2. `./release.sh` — produces `../releases/lrob-email-toolkit-<version>.zip`.
3. Upload the zip as above.

In both cases, **all modules are disabled** after activation. You opt in to each one explicitly from the Email Toolkit dashboard. Once installed, the plugin auto-updates from GitHub releases (1-hour cache, force-refresh on `update-core.php`).

## Security notes

- SMTP / IMAP / captcha-provider credentials are stored in the database encrypted with AES-256-GCM, using a key derived from your WordPress `AUTH_KEY` via HKDF-SHA256. Without a valid `AUTH_KEY` in `wp-config.php`, the plugin refuses to encrypt secrets.
- Newsletter tracking tokens are HMAC-SHA256 signatures of the URL parameters — tampering invalidates the token and the endpoint refuses to record / redirect.
- Recipient IPs in tracking events are anonymised before storage (IPv4 → /24, IPv6 → /48). User-agents are not stored by default; opt-in per newsletter.
- All credential fields can be overridden by `wp-config.php` constants for ops teams who want secrets out of the database entirely.

## Hooks for developers

Stable since v0.0.1, regardless of which modules are enabled.

```php
// Subscribe to one specific event
add_action('lrob_etk_email_sent', function (array $payload): void {
    // $payload = ['log_id' => ..., 'to' => [...], 'subject' => ..., 'identity_id' => ...]
});

// Or subscribe to every plugin event in one place
add_action('lrob_etk_event', function (string $name, array $payload): void {
    error_log("[lrob-etk] {$name}: " . wp_json_encode($payload));
}, 10, 2);
```

Live event names: `email.{sending,sent,failed,imap_saved,imap_save_failed}`, `contact_form.{submitted,spam_blocked,delivered}`, `newsletter.{started,paused,resumed,aborted,completed,test_sent}`, `newsletter.recipient.{sent,failed}`, `newsletter.subscriber.{added,confirmed,refused,unsubscribed,trashed,promoted,resubscribed,reminder_sent}`, `newsletter.tracking.{opened,clicked,unsubscribed}`. Full vocabulary in [`CLAUDE.md`](./CLAUDE.md).

## Versioning

Two cadences:
- **Patch (`+0.0.1`)** — small adjustments, on demand.
- **Minor (`+0.1.0`)** — a full module shipped.

Migrations between versions are idempotent. Downgrades are not supported. `1.0.0` happens when the plugin is stable enough to declare it so — no specific feature gate.

## Roadmap

Priority order, no version commitment — see [`todo.md`](./todo.md) for the full backlog with reasoning. **Top three priorities right now**:

1. **UI uniformization + theme system** (Light / Dark / LRob / Auto).
2. **Statistics overhaul** — dedicated Newsletter view + global Email Toolkit dashboard tiles.
3. **International phone field** with country-code picker + flag.

**Next major features on deck**: marketing automation workflows (drip campaigns), drag-and-drop email builder, subscriber custom fields + tags, bounce handling, suppression list, WooCommerce integration, GDPR toolkit.

## License

GPL-2.0-or-later. See [`LICENSE`](./LICENSE).

## Author

Built by [LRob](https://www.lrob.fr).

- Plugin home: <https://www.lrob.fr/wordpress/plugins/lrob-email-toolkit/>
- Issues: <https://github.com/LRob-FR/wp-lrob-email-toolkit/issues>

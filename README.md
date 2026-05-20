# LRob — Email Toolkit

> All-in-one modular email plugin for WordPress. SMTP routing, email logging, customizable contact forms with multi-context captcha, plus an upcoming newsletter module and webhook integrations.

## Status

**Public beta — v0.1.1.** Three modules are production-usable today (SMTP, Email Logging, Contact Form + Captcha). Newsletter is next. Pre-1.0, so the schema can still change between minor versions — see [Versioning](#versioning).

## What it is

A single plugin that replaces the typical stack of *SMTP plugin + email logger + contact form plugin + captcha plugin + newsletter plugin*, with consistent design, shared SMTP routing, and a shared event vocabulary. Each module is independently activatable — only the parts you enable add code to the runtime.

No external libraries at runtime: no Composer, no React, no build pipeline. Plain PHP 8.1+, vanilla JS, server-rendered admin UI. The ~300 KB release zip is the entire plugin.

## Modules

| Module | Status | What it does |
|---|---|---|
| **SMTP** | ✅ shipped | Route `wp_mail()` through one or more configured SMTP servers. Multiple "from" identities, per-source routing rules (WooCommerce / contact form / default / etc.), wp-config.php constant overrides, AES-256-GCM-encrypted passwords at rest, native PHP `mail()` transport as fallback. |
| **Email Logging** | ✅ shipped | Log every outgoing email (headers, body, attachments, status, errors). Browse / search / filter / resend in the admin. Configurable retention. Activity charts on the dashboard. IMAP "Save to Sent" archive is the next chunk on this module. |
| **Contact Form** | ✅ shipped | Customizable forms with a from-scratch WYSIWYG editor (drag & drop, columns, inline settings, autosave, undo/redo). Stacked anti-spam: honeypot, time-trap, rate-limit, captcha. Starter templates. Per-form recipients, Reply-To picking, subject templates, success-message templates. Submission logging with captcha-outcome tracking. |
| **Captcha** | ✅ shipped (service module) | Shared captcha service consumed by Contact Form (and later: comments, newsletter signup, lost password, registration). Per-context routing — pick the default site-wide then optionally override per use case. Built-in math + picture-recognition challenges. **hCaptcha** provider shipped; Cloudflare Turnstile and Google reCAPTCHA designed to plug in (multi-identity, encrypted credentials). |
| **Newsletter** | ⏳ planned | Campaigns to your WordPress users with segmentation by role / meta / WooCommerce purchase data (HPOS-aware). Throttled sending, open/click tracking, unsubscribe handling. Migration importer from the [Newsletter](https://wordpress.org/plugins/newsletter/) plugin. |
| **Integrations** | ⏳ later | Outbound webhooks to n8n, Slack, Discord, Matrix and generic endpoints. Each module already emits events from v0.0.1 — devs can hook them today via WordPress actions, no module needed. |

## Requirements

- PHP **8.1+**
- WordPress **6.0+**
- WooCommerce **8.0+** *(only required if you'll use WooCommerce-based segmentation in the Newsletter module once it ships — HPOS supported)*

## Languages

- 🇬🇧 English (source)
- 🇫🇷 French (`fr_FR`) — 100% coverage

## Install

Pre-1.0 — no auto-update from WordPress.org yet. Two ways to install:

**From a release:**

1. Download `lrob-email-toolkit-<version>.zip` from the [Releases](https://github.com/LRob-FR/wp-lrob-email-toolkit/releases) page.
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Activate.

**From source:**

1. `git clone` this repo.
2. `./release.sh` — produces `../releases/lrob-email-toolkit-<version>.zip`.
3. Upload the zip as above.

In both cases, **all modules are disabled** after activation. You opt in to each one explicitly from the Email Toolkit dashboard.

A GitHub-release-based auto-update mechanism (mirroring [wp-lrob-calendar](https://github.com/LRob-FR/wp-lrob-calendar)) is planned for the 1.0 release.

## Security notes

- SMTP / IMAP / captcha-provider credentials are stored in the database encrypted with AES-256-GCM, using a key derived from your WordPress `AUTH_KEY` via HKDF-SHA256. If you don't have `AUTH_KEY` configured in `wp-config.php` (which you should), the plugin refuses to encrypt secrets.
- All credential fields can be overridden by `wp-config.php` constants — e.g. `define('LROB_ETK_SMTP_PASS', '...');` — for ops teams who want secrets out of the database entirely.
- Captcha hosted-provider credentials follow the same encryption model. Each identity is a separately-encrypted record so rotating one set of keys doesn't affect others.

## Hooks for developers

Stable since v0.0.1, regardless of which modules are enabled. The Integrations module (later) is built on top of these — no need to wait.

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

Live event names today: `email.sending`, `email.sent`, `email.failed`, `email.imap_saved`, `email.imap_save_failed`, `contact_form.submitted`, `contact_form.spam_blocked`, `contact_form.delivered`. Newsletter-related events land with that module. Full vocabulary in [`CLAUDE.md`](./CLAUDE.md).

## Versioning

Pre-1.0 the schema can still change between minor versions (`0.X.0 → 0.X+1.0`), and patch bumps (`0.0.X → 0.0.X+1`) are normal dev iterations.

- Minor bumps correspond to a module reaching a feature-complete milestone (e.g. `0.1.0` = Captcha module shipped with multi-context routing + first hosted provider).
- The first `1.0.0` will ship with the public repo + GitHub-release auto-update wired up.

Migrations between versions are idempotent. Downgrades are not supported.

## Roadmap

- **v0.1.x** (current) — Polishing the Captcha + Contact Form story, FR translation, UX iteration.
- **v0.2.0** — Email Logging: IMAP "Save to Sent" archive, plus email export.
- **v0.3.0** — Newsletter module (campaigns, segmentation, tracking, unsubscribe).
- **v0.4.0** — Newsletter import from the Newsletter plugin.
- **v0.5.0** — Cross-feature captcha (comments, newsletter signup, lost password, registration) + additional homemade anti-bot challenges.
- **v0.6.0** — Integrations module (webhooks: Slack / Discord / Matrix / n8n / generic).
- **v1.0.0** — Repo goes fully public + GitHub-release auto-update + WordPress.org listing.

## License

GPL-2.0-or-later. See [`LICENSE`](./LICENSE).

## Author

Built by [LRob](https://www.lrob.fr).

- Plugin home: <https://www.lrob.fr/wordpress/plugins/lrob-email-toolkit/>
- Issues: <https://github.com/LRob-FR/wp-lrob-email-toolkit/issues>

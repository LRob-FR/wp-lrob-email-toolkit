# LRob — Email Toolkit

> All-in-one modular email plugin for WordPress. SMTP routing, email logging with IMAP archive, contact forms, newsletters, and webhook integrations.

## Status

**Early development (v0.0.1).** Scaffolding only. Feature modules are being built incrementally — see [Roadmap](#roadmap).

## What it is

A single plugin that replaces the typical stack of *SMTP plugin + email logger + contact form plugin + newsletter plugin*, with consistent design, shared SMTP routing, and shared event vocabulary. Each module is independently activatable — only the parts you enable add code to the runtime.

## Modules

| Module | Status | What it does |
|---|---|---|
| **SMTP** | 🛠 in progress | Route `wp_mail()` through one or more configured SMTP servers, with per-source identity routing (default / newsletter / contact form / WooCommerce). Multiple "from" identities supported. |
| **Email Logging** | 🛠 in progress | Log every outgoing email (headers, body, attachments, status, errors). Browse / search / filter / resend in the admin. Retention policy. Optional IMAP "Save to Sent" archive — a feature most logging plugins skip. |
| **Contact Form** | ⏳ planned | Customizable forms with stacked anti-spam (honeypot, time-trap, rate-limit, JS token) and optional captcha (hCaptcha, Cloudflare Turnstile, Google reCAPTCHA). Captcha is configured once at the plugin level, not per-form. |
| **Newsletter** | ⏳ planned | Campaigns to your WordPress users with segmentation by role / meta / WooCommerce purchase data (HPOS-aware). Throttled sending, open/click tracking, unsubscribe handling. Migration importer from the [Newsletter](https://wordpress.org/plugins/newsletter/) plugin. |
| **Integrations** | ⏳ later | Outbound webhooks to n8n, Slack, Discord, Matrix and generic endpoints. Each module already emits events from v0.0.1 — devs can hook them today via WordPress actions, no module needed. |

## Requirements

- PHP **8.1+**
- WordPress **6.0+**
- WooCommerce **8.0+** *(only required if you use WooCommerce-based segmentation in the Newsletter module — HPOS supported)*

## Install

1. Build the release zip: `./release.sh` (produces `../releases/lrob-email-toolkit-<version>.zip`).
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Activate.
3. Settings → Email Toolkit → Modules → enable the modules you need.

By default, **all modules are disabled** after activation. You opt in to each one explicitly.

## Security notes

- SMTP / IMAP credentials are stored in the database encrypted with AES-256-GCM, using a key derived from your WordPress `AUTH_KEY` via HKDF-SHA256. If you don't have `AUTH_KEY` configured in `wp-config.php` (which you should), the plugin refuses to encrypt secrets.
- All credential fields can be overridden by `wp-config.php` constants — e.g. `define('LROB_ETK_SMTP_PASS', '...');` — for ops teams who want secrets out of the database entirely.

## Hooks for developers

Stable from v0.0.1, regardless of which modules are enabled. The Integrations module (later) is built on top of these — no need to wait.

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

Full event vocabulary in [`CLAUDE.md`](./CLAUDE.md).

## Roadmap

- **v0.0.1** — SMTP routing + Email logging core.
- **v0.0.2** — Contact form module.
- **v0.0.3** — IMAP "Save to Sent" archive.
- **v0.0.4** — Newsletter module (campaigns, segmentation, tracking, unsubscribe).
- **v0.0.5** — Newsletter import from the Newsletter plugin.
- **v0.1.0** — Integrations module (webhooks).

## License

GPL-2.0-or-later. See [`LICENSE`](./LICENSE).

## Author

Built by [LRob](https://www.lrob.fr).

- Plugin home: <https://www.lrob.fr/wordpress/plugins/lrob-email-toolkit/>
- Issues: <https://github.com/LRob-FR/wp-lrob-email-toolkit/issues>

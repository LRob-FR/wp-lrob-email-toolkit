# LRob — Email Toolkit

> All-in-one modular email plugin for WordPress. SMTP routing, email logging, customizable contact forms, multi-context captcha, a full newsletter platform with tracking + segmentation, and webhook integrations — all from one plugin, no SaaS, no per-subscriber fees.

## Status

**Public beta — v0.5.0.** Five modules ship today: SMTP, Email Logging, Contact Form, Captcha, and Newsletter. The schema and the admin surfaces are stable, and the toolkit drives real email on production sites every day.

The v0.5 cycle gave the whole admin a single branded dark theme (matching the lrob.fr site), hardened the public-facing forms for accessibility (proper labels, announced errors, keyboard support), and did a big internal cleanup that makes future releases faster — with nothing changing in what you see. It builds on v0.4's full captcha lineup (hCaptcha / Turnstile / reCAPTCHA incl. invisible + score-based v3), in-place detail views, and resend-safe saved attachments.

Pre-1.0, so the schema can still change between minor versions — see [Versioning](#versioning). Bug reports + UX feedback welcome on [GitHub issues](https://github.com/LRob-FR/wp-lrob-email-toolkit/issues).

For the full feature history, see [`docs/done.md`](./docs/done.md). For the backlog, see [`docs/todo.md`](./docs/todo.md).

## What it is

A single plugin that replaces the typical stack of *SMTP plugin + email logger + contact-form plugin + captcha plugin + newsletter plugin (+ Mailchimp/Brevo subscription)*, with consistent design, shared SMTP routing, and a shared event vocabulary. Each module is independently activatable — only the parts you enable add code to the runtime.

No external libraries at runtime: no Composer, no React, no build pipeline. Plain PHP 8.2+, vanilla JS, server-rendered admin UI. The ~780 KB release zip is the entire plugin.

## Highlights

A few things that make it stand out:

- **See every email your site sends.** Full logging captures every outgoing message — recipients, subject, body, attachments, status and any error — all searchable and filterable. No more "did that order confirmation actually go out?"; open the log and know.
- **Re-send any email, attachments and all.** Switch on *Save attachments locally* and any logged message can be re-sent later with its files intact, straight from the log — and those files live on **your** server, not stuck in a mailbox (with an optional copy saved to your IMAP *Sent* folder too).
- **SMTP that practically sets itself up.** You don't need to know your mail-server settings: the host picker suggests your domain's real servers (common presets **plus a live MX lookup**), shows a green/red check that each one actually resolves, and a one-click connection test confirms login works — *before* you rely on it.
- **One mailbox per job — add as many as you like.** Run multiple SMTP "from" identities and route each source to the right one: WooCommerce through one, your contact form through another, newsletters through a third.
- **Stack as many captchas as you need.** Multiple providers and multiple credentials side by side — the privacy-friendly built-in *math* and *picture* challenges (zero third-party calls, no cookies), plus hCaptcha, Cloudflare Turnstile and Google reCAPTCHA — each assignable to its own context (forms, comments, login, registration…) with a clear site-wide default.
- **Own your newsletter — no SaaS, no per-subscriber fees.** Send to your subscribers and WordPress users from your own server, with open/click tracking, list segmentation and one-click unsubscribe — without Mailchimp/Brevo monthly bills or handing your contact list to a third party.
- **Up and running in minutes, thanks to presets.** Ready-made form templates, prebuilt subscribe-form profiles (*Email-only*, *Contact basics*, *Full profile*), and smart defaults everywhere mean most things just work the moment you switch them on — you tweak only what you want.
- **Forms anyone can fill in.** A drag-and-drop builder for contact and signup forms, with front-end markup hardened for keyboard and screen-reader users (clear labels, announced errors).
- **Your secrets stay yours.** SMTP and captcha credentials are encrypted at rest (AES-256-GCM); tracking IPs are anonymised; ops teams can keep secrets entirely in `wp-config.php`.
- **Light and self-contained.** No Composer, no React, no build step, no external CDN — a ~780 KB zip is the whole plugin, and it auto-updates from GitHub.

## Modules

| Module | Status | What it does |
|---|---|---|
| **SMTP** | ✅ shipped | Route `wp_mail()` through one or more configured SMTP servers. Multiple "from" identities, per-source routing rules, `wp-config.php` constant overrides, AES-256-GCM-encrypted passwords at rest, native PHP `mail()` transport as fallback. Per-identity *save attachments locally* option so logged emails can be re-sent with their files intact. |
| **Email Logging** | ✅ shipped | Log every outgoing email (headers, body, attachments, status, errors). Browse / search / filter / resend from an in-page detail modal — resend re-attaches files saved via the SMTP *save attachments* toggle. Configurable retention. Activity charts on the dashboard. |
| **Contact Form** | ✅ shipped | Customizable forms with a from-scratch WYSIWYG editor (drag & drop, columns, inline settings, autosave, undo/redo). Stacked anti-spam: honeypot, time-trap, rate-limit, captcha. Starter templates. Per-form recipients, Reply-To picking, subject templates, success-message templates. Submissions inbox with captcha-outcome tracking + filters + detail view. |
| **Captcha** | ✅ shipped (service module) | Shared captcha service consumed by Contact Form + Newsletter sign-up forms + WordPress comments + login + lost-password + registration (+ WooCommerce login). Per-context assignments with the **Make as default** badge — pick the default site-wide then optionally override per use case. Built-in math + picture-recognition challenges. **hCaptcha**, **Cloudflare Turnstile**, and **Google reCAPTCHA** (v2 + v3 score-based) all shipped — multi-identity, AES-encrypted credentials, per-identity theme / size / invisible mode. |
| **Newsletter** | 🧪 beta (v0.3.x) | Newsletters composed in Gutenberg, sent to your subscribers + WordPress users. **Lists**: two kinds — *Subscribers lists* (manual membership) and *WP users lists* (rule-based — by role, WooCommerce customer status, active WooCommerce subscriptions, or a custom rule plugged in by a developer). Mark lists as *Public* so subscribers self-join from their prefs page, *Private* for admin-managed segmentation. **Audience picker** with per-list member counts, opt-out visibility, per-row *Send anyway / Exclude* overrides, *Bypass opt-outs* for operational sends (warned at send time). **Subscribe forms** with a drag-and-drop builder (text / email / phone with country picker / gender / dropdown / list picker / captcha / submit), full-form templates (*Email-only*, *Contact basics*, *Full profile*), per-form default lists. **Subscriber self-edit**: name / phone / postal address / gender from the prefs page; email change requires click-to-confirm on the new address. Throttled AJAX + Cron send pipeline with SMTP circuit-breaker, open + click tracking via HMAC-signed REST endpoints, per-subscriber lifetime engagement stats, cold-subscriber detection, bulk unsubscribe, RFC 8058 one-click unsubscribe headers. WP-Cron health diagnostic. |
| **Integrations** | ⏳ planned | Outbound webhooks to n8n, Slack, Discord, Matrix and generic endpoints. Each module already emits events from v0.0.1 — devs can hook them today via WordPress actions, no module needed. |

## Requirements

- PHP **8.2+**
- WordPress **6.8+**
- WooCommerce **8.0+** *(only required if you'll use WooCommerce-based segmentation in the Newsletter module — HPOS supported, integration planned)*
- A reasonably modern browser for the **admin UI** — the design system uses CSS `color-mix()` (Chrome/Edge **111+**, Firefox **113+**, Safari **16.2+**; all released 2023). Older browsers degrade gracefully (tints/glows just don't paint); the public-facing forms have no such requirement.

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

- SMTP and captcha-provider credentials are stored in the database encrypted with AES-256-GCM, using a key derived from your WordPress `AUTH_KEY` via HKDF-SHA256. Without a valid `AUTH_KEY` in `wp-config.php`, the plugin refuses to encrypt secrets.
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

Live event names: `email.{sending,sent,failed,imap_saved,imap_save_failed}`, `contact_form.{submitted,spam_blocked,delivered}`, `newsletter.{started,paused,resumed,aborted,completed,test_sent}`, `newsletter.recipient.{sent,failed}`, `newsletter.subscriber.{added,confirmed,refused,unsubscribed,trashed,promoted,resubscribed,reminder_sent,email_change_requested}`, `newsletter.tracking.{opened,clicked,unsubscribed}`. Full vocabulary in [`CLAUDE.md`](./CLAUDE.md).

## Versioning

Two cadences:
- **Patch (`+0.0.1`)** — small adjustments, on demand.
- **Minor (`+0.1.0`)** — a full module shipped.

Migrations between versions are idempotent. Downgrades are not supported. `1.0.0` happens when the plugin is stable enough to declare it so — no specific feature gate.

## Roadmap

Priority order, no version commitment — see [`docs/todo.md`](./docs/todo.md) for the full backlog with reasoning. **Top priorities right now**:

1. **Statistics overhaul** — a dedicated Newsletter statistics view plus global Email Toolkit dashboard tiles (open/click trends, per-list rollups, per-send drill-down).
2. **Contact-form personalization** — per-form colours, hover effects, and send animations.

**Next major features on deck**: universal email tracking (Opened column on Email Logs), one-shot email composer with templates + attachments, marketing automation workflows (drip campaigns), drag-and-drop email builder, subscriber custom fields + tags, bounce handling, suppression list, GDPR toolkit, customize WP default emails.

## License

GPL-2.0-or-later. See [`LICENSE`](./LICENSE).

## Author

Built by [LRob](https://www.lrob.fr).

- Plugin home: <https://www.lrob.fr/wordpress/plugins/lrob-email-toolkit/>
- Issues: <https://github.com/LRob-FR/wp-lrob-email-toolkit/issues>

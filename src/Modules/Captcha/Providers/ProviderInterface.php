<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Captcha\Providers;

use LRob\EmailToolkit\Modules\Captcha\Challenges\ChallengeInterface;

/**
 * Hosted captcha provider — hCaptcha, Cloudflare Turnstile, Google reCAPTCHA.
 * Extends ChallengeInterface so the same render/verify shape applies; the
 * difference from a homemade challenge is that providers need credentials
 * (configured per-identity in the lrob_etk_captcha_identities table) and
 * load a vendor JS widget.
 *
 * At render/verify time the active identity's plaintext credentials are
 * injected into $context['credentials'] (array<string, string>). The provider
 * passes them to the vendor — site_key into the rendered HTML, secret_key
 * into the verify HTTP call.
 */
interface ProviderInterface extends ChallengeInterface
{
    /**
     * Describe the credential fields this provider needs. Drives the admin
     * settings UI: one form input per entry.
     *
     * Each entry:
     *  - key:         POST/data key + decrypted_credentials() lookup
     *  - label:       translated input label
     *  - type:        'text' | 'password' (HTML input type)
     *  - required:    whether the admin must fill it before saving
     *  - description: optional translated help text
     *
     * @return array<int, array{key:string, label:string, type:string, required:bool, description?:string}>
     */
    public function credential_fields(): array;

    /**
     * Validate raw credential input from the admin form. On success returns
     * `['credentials' => [k=>v], 'errors' => []]`. On failure returns
     * `['credentials' => [], 'errors' => [field_key => translated_message]]`.
     *
     * @param array<string, string> $values
     * @return array{credentials: array<string, string>, errors: array<string, string>}
     */
    public function validate_credentials(array $values): array;

    /**
     * Inline HTML logo for the admin UI — inline SVG preferred so it
     * inherits text color and ships without an extra asset round-trip. May
     * be empty when the provider doesn't have a visual mark.
     *
     * Use vendor-official artwork where it falls within brand-use guidelines
     * for descriptive identification; keep dimensions roughly square (~20px
     * intrinsic) so the card chip lays out consistently.
     */
    public function logo_html(): string;
}

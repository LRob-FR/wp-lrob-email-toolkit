<?php

declare(strict_types=1);

namespace LRob\EmailToolkit\Modules\Newsletter;

use LRob\EmailToolkit\Modules\ContactForm\Frontend as ContactFormFrontend;

/**
 * Frontend asset wiring. Subscribe forms emit `.lrob-etk-form` DOM,
 * same shape as Contact Form (form-builder DOM is shared since 0.2.2),
 * so we lean on Contact Form's Frontend class to register the script +
 * style under shared handles (`lrob-etk-form-submit`,
 * `lrob-etk-form-frontend`). WP's enqueue dedupe means it's safe to
 * call from either module.
 *
 * EmbedRenderer::render() calls Frontend::enqueue_assets() when a
 * newsletter form actually renders on the page — no JS/CSS loaded
 * on pages without a form.
 */
final class Frontend
{
    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_assets(): void
    {
        // Delegate to Contact Form's registrar — same handles, same
        // file paths, same localize global. Idempotent thanks to
        // wp_register_* deduping.
        (new ContactFormFrontend())->register_assets();
    }

    public static function enqueue_assets(): void
    {
        ContactFormFrontend::enqueue_assets();
    }
}

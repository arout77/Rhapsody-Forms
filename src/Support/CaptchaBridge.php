<?php

namespace Arout\Forms\Support;

use Rhapsody\Core\Helpers\Recaptcha;

/**
 * The module's only contact with the framework's reCAPTCHA helper, so the
 * rest of the code never touches it directly.
 */
final class CaptchaBridge
{
    /** True only when BOTH keys are configured. verify() fails whenever the secret key is missing. */
    public static function isEnabled(): bool
    {
        if (! class_exists(Recaptcha::class)) {
            return false;
        }

        if (method_exists(Recaptcha::class, 'isEnabled')) {
            return Recaptcha::isEnabled();
        }

        // Older cores: same rule, written out.
        return ! empty($_ENV['RECAPTCHA_SITE_KEY'] ?? '') && ! empty($_ENV['RECAPTCHA_SECRET_KEY'] ?? '');
    }

    /**
     * The widget HTML. Prefers the page's own `captcha_form` template
     * variable (the one themes print with {{ captcha_form|raw }}) and falls
     * back to asking the helper directly, e.g. when the form sits on a page
     * the framework didn't inject it into.
     *
     * @param array<string, mixed> $twigContext
     */
    public static function widget(array $twigContext): string
    {
        $fromPage = $twigContext['captcha_form'] ?? null;
        if (is_string($fromPage) && $fromPage !== '') {
            return $fromPage;
        }

        return class_exists(Recaptcha::class) ? Recaptcha::render() : '';
    }

    public static function verify(string $token, ?string $ip): bool
    {
        return class_exists(Recaptcha::class) && Recaptcha::verify($token, $ip);
    }
}

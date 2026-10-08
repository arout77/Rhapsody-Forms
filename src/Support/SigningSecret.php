<?php

namespace Arout\Forms\Support;

use Rhapsody\Core\Modules\Facades\SettingsFacade;

/**
 * The module's private signing secret: signs the time-trap tokens and keys
 * the IP hashes. Generated once and kept in the module's settings file.
 */
final class SigningSecret
{
    public const KEY = 'signing_secret';

    /** The stored secret, or null when there isn't a usable one yet. */
    public static function get(SettingsFacade $settings): ?string
    {
        $secret = $settings->get(self::KEY);

        return (is_string($secret) && strlen($secret) >= 32) ? $secret : null;
    }

    /**
     * Returns the secret, creating it first if needed. Never throws: on a
     * read-only filesystem the module reports "no secret" (forms then refuse
     * to submit) instead of taking the whole site down on every request.
     */
    public static function ensure(SettingsFacade $settings): ?string
    {
        $existing = self::get($settings);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $settings->set(self::KEY, bin2hex(random_bytes(32)));
        } catch (\Throwable $e) {
            error_log('Forms: could not create the signing secret: ' . $e->getMessage());

            return null;
        }

        // Read back what actually landed: if two first requests raced, the last write wins for both.
        return self::get($settings);
    }
}

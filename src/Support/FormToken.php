<?php

namespace Arout\Forms\Support;

/**
 * The "time trap": a signed render timestamp embedded in every form.
 *
 * It answers two questions without any server-side state:
 *   - Was this form actually rendered by us? (the HMAC proves it)
 *   - How long ago? (bots submit instantly; people don't)
 *
 * The signature covers the form slug, so a token from one form can't be
 * replayed against another.
 */
final class FormToken
{
    public const OK      = 'ok';
    public const INVALID = 'invalid';
    public const TOO_FAST = 'too_fast';
    public const EXPIRED = 'expired';

    public static function issue(string $formSlug, int $now, string $secret): string
    {
        return $now . '.' . self::signature($formSlug, $now, $secret);
    }

    /** @return string One of the class constants */
    public static function check(
        string $token,
        string $formSlug,
        int $now,
        string $secret,
        int $minAgeSeconds,
        int $maxAgeSeconds,
    ): string {
        if (! preg_match('/^(\d{9,12})\.([a-f0-9]{32})$/', $token, $m)) {
            return self::INVALID;
        }

        $issuedAt = (int) $m[1];

        if (! hash_equals(self::signature($formSlug, $issuedAt, $secret), $m[2])) {
            return self::INVALID;
        }

        $age = $now - $issuedAt;

        if ($age < 0) {
            return self::INVALID; // from the future: clock skew or forgery
        }
        if ($age < $minAgeSeconds) {
            return self::TOO_FAST;
        }
        if ($age > $maxAgeSeconds) {
            return self::EXPIRED;
        }

        return self::OK;
    }

    private static function signature(string $formSlug, int $issuedAt, string $secret): string
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('FormToken needs a secret of at least 32 characters');
        }

        return substr(hash_hmac('sha256', $formSlug . '|' . $issuedAt, $secret), 0, 32);
    }
}

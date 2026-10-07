<?php

namespace Arout\Forms\Support;

/**
 * Guards redirect targets. A form can ask to send the visitor "back to the
 * page they came from" or to a thank-you page; if that value ever comes from
 * the request it must not become an open redirect to someone else's site.
 */
final class LocalPath
{
    public static function isLocal(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || strlen($path) > 2000) {
            return false;
        }

        // "//evil.example" is protocol-relative; browsers also treat "/\evil.example" the same way.
        if (str_starts_with($path, '//') || str_contains($path, '\\')) {
            return false;
        }

        return ! preg_match('/[\x00-\x1F\x7F]/', $path);
    }

    public static function sanitize(?string $path, string $fallback = '/'): string
    {
        return ($path !== null && self::isLocal($path)) ? $path : $fallback;
    }
}

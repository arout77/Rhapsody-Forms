<?php

namespace Arout\Forms\Support;

/**
 * Works out who is submitting, safely.
 *
 * By default only REMOTE_ADDR is believed. Headers like X-Forwarded-For are
 * written by the client unless a proxy you control overwrites them, so
 * trusting them blindly lets anyone dodge rate limits by sending a random
 * value each time. Name the header in $trustedHeader only when the site
 * really sits behind a proxy or CDN that sets it.
 */
final class ClientIp
{
    /** @param array<string, mixed> $server $_SERVER-style array */
    public static function resolve(array $server, string $trustedHeader = ''): string
    {
        $trustedHeader = trim($trustedHeader);

        if ($trustedHeader !== '') {
            $key   = 'HTTP_' . strtoupper(str_replace('-', '_', $trustedHeader));
            $value = $server[$key] ?? null;

            if (is_string($value) && $value !== '') {
                // X-Forwarded-For can be a chain ("client, proxy1, proxy2"): the first entry is the client.
                $first = trim(explode(',', $value)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        $remote = $server['REMOTE_ADDR'] ?? null;

        return (is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) !== false) ? $remote : '0.0.0.0';
    }

    /** Stored instead of the raw address: lets us rate-limit without keeping personal data. */
    public static function hash(string $ip, string $secret): string
    {
        return hash_hmac('sha256', $ip, $secret);
    }
}

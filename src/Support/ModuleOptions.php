<?php

namespace Arout\Forms\Support;

use Rhapsody\Core\Modules\Facades\SettingsFacade;

/**
 * Reads the module-wide settings with defaults, and tolerates hand-edited
 * JSON ("true" as a string, a number as text, rubbish in an email field).
 */
final class ModuleOptions
{
    /**
     * @return array{notify_email: string, min_submit_seconds: int, trusted_proxy_header: string,
     *               retention_days: int, include_css: bool, delete_data_on_uninstall: bool}
     */
    public static function read(SettingsFacade $settings): array
    {
        $email = trim((string) self::scalar($settings->get('notify_email')));

        return [
            'notify_email'             => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'min_submit_seconds'       => max(0, min(60, self::int($settings->get('min_submit_seconds'), 2))),
            // Only header-name characters survive, so it can only ever select a $_SERVER key.
            'trusted_proxy_header'     => (string) preg_replace('/[^A-Za-z0-9-]/', '', (string) self::scalar($settings->get('trusted_proxy_header'))),
            'retention_days'           => max(0, self::int($settings->get('retention_days'), 0)),
            'include_css'              => self::bool($settings->get('include_css'), true),
            'delete_data_on_uninstall' => self::bool($settings->get('delete_data_on_uninstall'), false),
        ];
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value, int $default): int
    {
        return (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)))) ? (int) $value : $default;
    }

    private static function bool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $parsed = filter_var(trim($value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed ?? $default;
        }

        return $default;
    }
}

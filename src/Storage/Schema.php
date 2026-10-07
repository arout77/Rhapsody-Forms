<?php

namespace Arout\Forms\Storage;

/**
 * The module's one table. DatabaseFacade::migrate() takes exactly one
 * statement per call, so install() creates it and uninstall() leaves it
 * alone (submissions are the site owner's data; there is an explicit purge).
 *
 * Timestamps are stored in UTC.
 */
final class Schema
{
    public const TABLE = 'submissions';

    public static function table(string $prefix): string
    {
        return $prefix . self::TABLE;
    }

    public static function createSubmissions(string $prefix): string
    {
        $table = self::table($prefix);

        return <<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    form_slug VARCHAR(64) NOT NULL,
    data MEDIUMTEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'new',
    notify_status VARCHAR(16) NOT NULL DEFAULT 'pending',
    ip_hash CHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_form_created (form_slug, created_at),
    KEY idx_throttle (form_slug, ip_hash, created_at),
    KEY idx_status (status),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    }

    public static function dropSubmissions(string $prefix): string
    {
        return 'DROP TABLE IF EXISTS ' . self::table($prefix);
    }
}

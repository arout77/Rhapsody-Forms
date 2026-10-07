<?php

namespace Arout\Forms\Form;

use Arout\Forms\Support\LocalPath;
use InvalidArgumentException;

/**
 * A validated form: slug, display name, fields and per-form settings.
 *
 * Definitions fail loudly at registration time (typos in a rule name, a
 * duplicate field, a bad redirect) instead of misbehaving when a visitor
 * submits.
 */
final class FormDefinition
{
    private const DEFAULT_SETTINGS = [
        'notify_email'     => null,
        'submit_label'     => 'Send',
        'success_message'  => 'Thanks! Your message has been sent.',
        'redirect'         => null,
        'throttle_per_hour' => 5,
        'captcha'          => 'auto',
    ];

    /**
     * @param Field[]              $fields
     * @param array<string, mixed> $settings
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly array $fields,
        public readonly array $settings,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $slug = $data['slug'] ?? null;
        if (! is_string($slug) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) {
            throw new InvalidArgumentException(
                'Form "slug" must be lowercase letters, digits and dashes, starting with a letter or digit (max 64)'
            );
        }

        $unknown = array_diff(array_keys($data), ['slug', 'name', 'fields', 'settings']);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Form \"{$slug}\": unknown key(s): " . implode(', ', $unknown));
        }

        $rawFields = $data['fields'] ?? null;
        if (! is_array($rawFields) || $rawFields === []) {
            throw new InvalidArgumentException("Form \"{$slug}\" needs at least one field");
        }

        $fields = [];
        $seen   = [];
        foreach ($rawFields as $rawField) {
            if (! is_array($rawField)) {
                throw new InvalidArgumentException("Form \"{$slug}\": each field must be an array");
            }
            $field = Field::fromArray($rawField);
            if (isset($seen[$field->name])) {
                throw new InvalidArgumentException("Form \"{$slug}\": duplicate field name \"{$field->name}\"");
            }
            $seen[$field->name] = true;
            $fields[]           = $field;
        }

        $name = isset($data['name']) && is_scalar($data['name']) ? trim((string) $data['name']) : '';

        return new self(
            $slug,
            $name !== '' ? $name : ucfirst(str_replace('-', ' ', $slug)),
            $fields,
            self::normalizeSettings($slug, $data['settings'] ?? []),
        );
    }

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /** The first email-type field: where a notification's Reply-To comes from. */
    public function emailField(): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->type === 'email') {
                return $field;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function normalizeSettings(string $slug, mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException("Form \"{$slug}\": \"settings\" must be an array");
        }

        $unknown = array_diff(array_keys($raw), array_keys(self::DEFAULT_SETTINGS));
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Form \"{$slug}\": unknown setting(s): " . implode(', ', $unknown) .
                '. Allowed: ' . implode(', ', array_keys(self::DEFAULT_SETTINGS))
            );
        }

        $s = array_merge(self::DEFAULT_SETTINGS, $raw);

        $email = is_scalar($s['notify_email']) ? trim((string) $s['notify_email']) : '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Form \"{$slug}\": settings.notify_email is not a valid email address");
        }
        $s['notify_email'] = $email !== '' ? $email : null;

        $s['submit_label']    = trim((string) $s['submit_label']) ?: self::DEFAULT_SETTINGS['submit_label'];
        $s['success_message'] = trim((string) $s['success_message']) ?: self::DEFAULT_SETTINGS['success_message'];

        if ($s['redirect'] !== null && $s['redirect'] !== '') {
            if (! is_string($s['redirect']) || ! LocalPath::isLocal($s['redirect'])) {
                throw new InvalidArgumentException("Form \"{$slug}\": settings.redirect must be a local path like \"/thanks\"");
            }
        } else {
            $s['redirect'] = null;
        }

        if (! is_int($s['throttle_per_hour']) || $s['throttle_per_hour'] < 0) {
            throw new InvalidArgumentException("Form \"{$slug}\": settings.throttle_per_hour must be a whole number, 0 or more (0 = no limit)");
        }

        if (! in_array($s['captcha'], ['auto', 'off'], true)) {
            throw new InvalidArgumentException("Form \"{$slug}\": settings.captcha must be \"auto\" or \"off\"");
        }

        return $s;
    }
}

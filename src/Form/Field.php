<?php

namespace Arout\Forms\Form;

use InvalidArgumentException;

/**
 * One input in a form. Immutable; built from a plain array so forms can be
 * defined in PHP, JSON, or (in Pro) the database.
 */
final class Field
{
    public const TYPES = ['text', 'email', 'tel', 'url', 'number', 'textarea', 'select', 'radio', 'checkbox', 'hidden'];

    /** Names the module itself uses in every form post. */
    public const RESERVED_NAMES = ['_token', '_ts', '_form', '_return', 'hp_website', 'g-recaptcha-response'];

    public const RULES = ['required', 'accepted', 'email', 'url', 'numeric', 'alpha', 'alphaNum', 'min', 'max', 'in', 'notIn', 'dateFormat'];

    /** Nothing longer than this is ever accepted, whatever a rule says. */
    public const HARD_MAX_LENGTH = 20000;

    /**
     * @param array<int, array{0: string, 1: ?string}>        $rules   parsed rules: [name, parameter]
     * @param array<int, array{value: string, label: string}> $options select/radio choices
     */
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly array $rules,
        public readonly array $options,
        public readonly string $placeholder,
        public readonly string $help,
        public readonly string $default,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
            throw new InvalidArgumentException(
                'Form field "name" must be lowercase letters, digits and underscores, starting with a letter (max 64)'
            );
        }
        if (in_array($name, self::RESERVED_NAMES, true)) {
            throw new InvalidArgumentException("Form field name \"{$name}\" is reserved by the Forms module");
        }

        $type = (string) ($data['type'] ?? 'text');
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                "Field \"{$name}\": unknown type \"{$type}\". Allowed: " . implode(', ', self::TYPES)
            );
        }

        $options = [];
        if ($type === 'select' || $type === 'radio') {
            $options = self::normalizeOptions($data['options'] ?? null, $name);
        }

        $unknown = array_diff(array_keys($data), ['name', 'type', 'label', 'rules', 'options', 'placeholder', 'help', 'default']);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Field \"{$name}\": unknown setting(s): " . implode(', ', $unknown));
        }

        return new self(
            name: $name,
            type: $type,
            label: self::text($data['label'] ?? null) ?: ucfirst(str_replace('_', ' ', $name)),
            rules: self::parseRules((string) ($data['rules'] ?? ''), $name),
            options: $options,
            placeholder: self::text($data['placeholder'] ?? null),
            help: self::text($data['help'] ?? null),
            default: self::text($data['default'] ?? null),
        );
    }

    public function isRequired(): bool
    {
        return $this->hasRule('required');
    }

    public function hasRule(string $rule): bool
    {
        foreach ($this->rules as [$name]) {
            if ($name === $rule) {
                return true;
            }
        }

        return false;
    }

    public function ruleParam(string $rule): ?string
    {
        foreach ($this->rules as [$name, $param]) {
            if ($name === $rule) {
                return $param;
            }
        }

        return null;
    }

    /** @return string[] */
    public function optionValues(): array
    {
        return array_column($this->options, 'value');
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return array<int, array{0: string, 1: ?string}> */
    private static function parseRules(string $ruleString, string $field): array
    {
        $rules = [];

        foreach (explode('|', $ruleString) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            $param = null;
            $name  = $rule;
            if (str_contains($rule, ':')) {
                [$name, $param] = explode(':', $rule, 2);
            }

            if (! in_array($name, self::RULES, true)) {
                throw new InvalidArgumentException(
                    "Field \"{$field}\": unknown rule \"{$name}\". Allowed: " . implode(', ', self::RULES)
                );
            }

            $needsParam = in_array($name, ['min', 'max', 'in', 'notIn', 'dateFormat'], true);
            if ($needsParam && ($param === null || $param === '')) {
                throw new InvalidArgumentException("Field \"{$field}\": rule \"{$name}\" needs a value, e.g. \"{$name}:5\"");
            }
            if (($name === 'min' || $name === 'max') && ! (is_numeric($param) && (float) $param >= 0)) {
                throw new InvalidArgumentException("Field \"{$field}\": rule \"{$name}\" needs a non-negative number");
            }

            $rules[] = [$name, $param];
        }

        return $rules;
    }

    /** @return array<int, array{value: string, label: string}> */
    private static function normalizeOptions(mixed $raw, string $field): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new InvalidArgumentException("Field \"{$field}\": select and radio fields need a non-empty \"options\" list");
        }

        $options = [];
        $isList  = array_is_list($raw);

        foreach ($raw as $key => $item) {
            if (! $isList) {
                $value = (string) $key;
                $label = is_scalar($item) ? (string) $item : $value;
            } elseif (is_array($item)) {
                if (! isset($item['value']) || ! is_scalar($item['value'])) {
                    throw new InvalidArgumentException("Field \"{$field}\": every option array needs a \"value\"");
                }
                $value = (string) $item['value'];
                $label = isset($item['label']) && is_scalar($item['label']) ? (string) $item['label'] : $value;
            } elseif (is_scalar($item)) {
                $value = $label = (string) $item;
            } else {
                throw new InvalidArgumentException("Field \"{$field}\": options must be strings or {value,label} arrays");
            }

            foreach ($options as $existing) {
                if ($existing['value'] === $value) {
                    throw new InvalidArgumentException("Field \"{$field}\": duplicate option value \"{$value}\"");
                }
            }
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}

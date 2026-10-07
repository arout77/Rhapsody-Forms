<?php

namespace Arout\Forms\Validation;

use Arout\Forms\Form\Field;
use Arout\Forms\Form\FormDefinition;

/**
 * Validates a posted form against its definition.
 *
 * The rule names match the framework's own Validator (required, email, min,
 * max, in, ...) so definitions read the same as the rest of your code. It is
 * deliberately separate from it: core's Validator needs an EntityManager a
 * module can't reach, treats a value of "0" as empty, and words its messages
 * with raw field names ("first_name") that a visitor shouldn't see.
 *
 * Only fields in the definition are read: anything else in the post is
 * ignored, so a visitor can't store arbitrary extra data.
 */
final class FieldValidator
{
    private const TRUTHY = ['1', 'on', 'true', 'yes'];

    /** @param array<string, mixed> $input */
    public function validate(FormDefinition $form, array $input): ValidationResult
    {
        $errors = [];
        $values = [];

        foreach ($form->fields as $field) {
            $raw = $input[$field->name] ?? null;

            if ($field->type === 'checkbox') {
                $checked = is_bool($raw)
                    ? $raw
                    : (is_scalar($raw) && in_array(strtolower(trim((string) $raw)), self::TRUTHY, true));

                $values[$field->name] = $checked;

                if (! $checked && ($field->isRequired() || $field->hasRule('accepted'))) {
                    $errors[$field->name] = ["{$field->label} must be checked."];
                }
                continue;
            }

            if ($raw !== null && ! is_scalar($raw)) {
                $values[$field->name] = '';
                $errors[$field->name] = ["{$field->label} is not valid."];
                continue;
            }

            $value                = $this->clean((string) ($raw ?? ''), $field);
            $values[$field->name] = $value;

            if ($value === '') {
                if ($field->isRequired()) {
                    $errors[$field->name] = ["{$field->label} is required."];
                }
                continue;
            }

            $message = $this->firstError($field, $value);
            if ($message !== null) {
                $errors[$field->name] = [$message];
            }
        }

        return new ValidationResult($errors, $values);
    }

    /** Trim, normalise line endings, drop control characters other than newline and tab. */
    private function clean(string $value, Field $field): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        if ($field->type !== 'textarea') {
            $value = str_replace(["\n", "\t"], ' ', $value);
        }

        return trim($value);
    }

    private function firstError(Field $field, string $value): ?string
    {
        $label     = $field->label;
        $isNumeric = $field->type === 'number' || $field->hasRule('numeric');
        $length    = mb_strlen($value, 'UTF-8');

        // The length ceiling always applies. A "max:" rule may lower or raise
        // the default, never past the hard limit.
        $max = $field->hasRule('max') && ! $isNumeric
            ? (int) $field->ruleParam('max')
            : ($field->type === 'textarea' ? 5000 : 255);
        $max = min($max, Field::HARD_MAX_LENGTH);

        if (! $isNumeric && $length > $max) {
            return "{$label} must not be longer than {$max} characters.";
        }

        // Rules implied by the field type
        switch ($field->type) {
            case 'email':
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    return "{$label} must be a valid email address.";
                }
                break;
            case 'url':
                if (! $this->isHttpUrl($value)) {
                    return "{$label} must be a valid web address starting with http:// or https://.";
                }
                break;
            case 'tel':
                if (! preg_match('/^[0-9+()\-.\s]{5,30}$/', $value)) {
                    return "{$label} must be a valid phone number.";
                }
                break;
            case 'number':
                if (! is_numeric($value) || ! is_finite((float) $value)) {
                    return "{$label} must be a number.";
                }
                break;
            case 'select':
            case 'radio':
                if (! in_array($value, $field->optionValues(), true)) {
                    return "Please choose a valid option for {$label}.";
                }
                break;
        }

        // Rules written in the definition
        foreach ($field->rules as [$rule, $param]) {
            $error = $this->checkRule($rule, $param, $field, $value, $length, $isNumeric);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private function checkRule(string $rule, ?string $param, Field $field, string $value, int $length, bool $isNumeric): ?string
    {
        $label = $field->label;

        switch ($rule) {
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? "{$label} must be a valid email address." : null;

            case 'url':
                return $this->isHttpUrl($value) ? null : "{$label} must be a valid web address starting with http:// or https://.";

            case 'numeric':
                return (is_numeric($value) && is_finite((float) $value)) ? null : "{$label} must be a number.";

            case 'alpha':
                return preg_match('/^\pL+$/u', $value) ? null : "{$label} may only contain letters.";

            case 'alphaNum':
                return preg_match('/^[\pL\pN]+$/u', $value) ? null : "{$label} may only contain letters and numbers.";

            case 'min':
                if ($isNumeric) {
                    return (is_numeric($value) && (float) $value >= (float) $param) ? null : "{$label} must be at least {$param}.";
                }
                return $length >= (int) $param ? null : "{$label} must be at least {$param} characters.";

            case 'max':
                if ($isNumeric) {
                    return (is_numeric($value) && (float) $value <= (float) $param) ? null : "{$label} must not be more than {$param}.";
                }
                return null; // length is enforced above

            case 'in':
                return in_array($value, explode(',', (string) $param), true) ? null : "Please choose a valid option for {$label}.";

            case 'notIn':
                return in_array($value, explode(',', (string) $param), true) ? "{$label} is not allowed." : null;

            case 'dateFormat':
                $date = \DateTime::createFromFormat((string) $param, $value);
                return ($date !== false && $date->format((string) $param) === $value) ? null : "{$label} must be a valid date ({$param}).";

            case 'accepted':
                return in_array(strtolower($value), self::TRUTHY, true) ? null : "{$label} must be accepted.";
        }

        return null; // 'required' is handled before this point
    }

    /** http(s) only: a stored "javascript:" link shown in an admin screen would be an XSS vector. */
    private function isHttpUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }
}

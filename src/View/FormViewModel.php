<?php

namespace Arout\Forms\View;

use Arout\Forms\Form\Field;
use Arout\Forms\Form\FormDefinition;

/**
 * Turns a form definition (plus any message carried over from a failed
 * attempt) into the plain arrays views/form.twig prints. All the decisions
 * live here so the template stays a dumb loop.
 */
final class FormViewModel
{
    private const TRUTHY = ['1', 'on', 'true', 'yes'];

    /**
     * @param array{ok: bool, message: string, errors: array<string, string[]>, values: array<string, mixed>}|null $flash
     * @return array<string, mixed>
     */
    public static function build(
        FormDefinition $form,
        string $action,
        string $token,
        string $returnPath,
        ?array $flash,
        string $captchaHtml,
        ?string $css,
    ): array {
        $values = is_array($flash['values'] ?? null) ? $flash['values'] : [];
        $errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];

        $fields = [];
        foreach ($form->fields as $field) {
            $fields[] = self::field($form->slug, $field, $values, $errors);
        }

        $alert = null;
        if ($flash !== null && $flash['message'] !== '') {
            $alert = ['type' => $flash['ok'] ? 'success' : 'error', 'message' => $flash['message']];
        }

        return [
            'form'         => [
                'id'           => 'rforms-' . $form->slug,
                'slug'         => $form->slug,
                'name'         => $form->name,
                'submit_label' => $form->settings['submit_label'],
            ],
            'action'       => $action,
            'token'        => $token,
            'return_path'  => $returnPath,
            'alert'        => $alert,
            'fields'       => $fields,
            'captcha_html' => $captchaHtml,
            'css'          => $css,
        ];
    }

    /**
     * @param array<string, mixed>    $values
     * @param array<string, string[]> $errors
     * @return array<string, mixed>
     */
    private static function field(string $slug, Field $field, array $values, array $errors): array
    {
        $id    = 'rforms-' . $slug . '-' . $field->name;
        $error = isset($errors[$field->name][0]) && is_string($errors[$field->name][0]) ? $errors[$field->name][0] : null;

        $given = array_key_exists($field->name, $values);
        $value = $given ? $values[$field->name] : $field->default;

        if ($field->type === 'checkbox') {
            $checked = is_bool($value) ? $value : (is_scalar($value) && in_array(strtolower(trim((string) $value)), self::TRUTHY, true));
            $value   = $checked;
        } else {
            $value = is_scalar($value) ? (string) $value : '';
        }

        $options = [];
        foreach ($field->options as $option) {
            $options[] = [
                'value'    => $option['value'],
                'label'    => $option['label'],
                'selected' => $value === $option['value'],
            ];
        }

        $attrs = [];
        if ($field->type === 'number') {
            foreach (['min', 'max'] as $bound) {
                $param = $field->ruleParam($bound);
                if ($param !== null) {
                    $attrs[$bound] = $param;
                }
            }
        } elseif (in_array($field->type, ['text', 'email', 'tel', 'url', 'textarea'], true)) {
            $attrs['maxlength'] = (string) self::maxLength($field);
            if ($field->hasRule('min') && $field->type !== 'textarea') {
                $attrs['minlength'] = (string) (int) $field->ruleParam('min');
            }
        }

        return [
            'id'          => $id,
            'name'        => $field->name,
            'type'        => $field->type,
            'label'       => $field->label,
            'help'        => $field->help,
            'placeholder' => $field->placeholder,
            'required'    => $field->isRequired(),
            'value'       => $value,
            'options'     => $options,
            'error'       => $error,
            'attrs'       => $attrs,
        ];
    }

    /** Mirrors FieldValidator's ceiling, so the browser stops people where the server would. */
    private static function maxLength(Field $field): int
    {
        $isNumeric = $field->hasRule('numeric');
        $max       = $field->hasRule('max') && ! $isNumeric
            ? (int) $field->ruleParam('max')
            : ($field->type === 'textarea' ? 5000 : 255);

        return min($max, Field::HARD_MAX_LENGTH);
    }
}

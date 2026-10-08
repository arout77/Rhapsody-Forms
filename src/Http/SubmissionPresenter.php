<?php

namespace Arout\Forms\Http;

use Arout\Forms\Form\Field;
use Arout\Forms\Form\FormDefinition;

/**
 * Turns a stored submission into text a person can read in the inbox. A
 * form's definition (when it is still registered) supplies the labels and the
 * option names; without it the stored keys are shown as they are.
 */
final class SubmissionPresenter
{
    /**
     * @param array<string, mixed> $submission
     * @return array<int, array{label: string, value: string}>
     */
    public static function rows(array $submission, ?FormDefinition $form): array
    {
        $data = is_array($submission['data'] ?? null) ? $submission['data'] : [];
        $rows = [];
        $seen = [];

        if ($form !== null) {
            foreach ($form->fields as $field) {
                $seen[$field->name] = true;
                $rows[]             = ['label' => $field->label, 'value' => self::display($data[$field->name] ?? '', $field)];
            }
        }

        foreach ($data as $key => $value) {
            if (! isset($seen[$key])) {
                $rows[] = ['label' => ucfirst(str_replace('_', ' ', (string) $key)), 'value' => self::display($value, null)];
            }
        }

        return $rows;
    }

    /**
     * One line for the list view: the first few non-empty answers.
     *
     * @param array<string, mixed> $submission
     */
    public static function summary(array $submission, ?FormDefinition $form, int $max = 110): string
    {
        $parts = [];
        foreach (self::rows($submission, $form) as $row) {
            if ($row['value'] !== '' && $row['value'] !== 'No') {
                $parts[] = preg_replace('/\s+/', ' ', $row['value']);
            }
            if (count($parts) === 3) {
                break;
            }
        }

        $text = implode(' | ', $parts);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    public static function display(mixed $value, ?Field $field): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $value = is_scalar($value) ? (string) $value : '';

        if ($field !== null && ($field->type === 'select' || $field->type === 'radio')) {
            foreach ($field->options as $option) {
                if ($option['value'] === $value) {
                    return $option['label'];
                }
            }
        }

        return $value;
    }
}

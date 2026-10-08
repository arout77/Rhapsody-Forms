<?php

namespace Arout\Forms\Export;

use Arout\Forms\Form\FormDefinition;
use Arout\Forms\Http\SubmissionPresenter;

/**
 * Builds the CSV for the inbox's export.
 *
 * Two things matter here beyond "write some commas":
 *
 *  - A cell that starts with = + - or @ is read by Excel and friends as a
 *    FORMULA, so a visitor could plant one in your spreadsheet. Those cells
 *    get a leading single quote (numbers are left alone).
 *  - A UTF-8 byte-order mark is written first, or Excel mangles accents.
 */
final class CsvExporter
{
    /** A ceiling, because the finished file is held in memory to hand to the response. */
    public const MAX_ROWS = 25000;

    /**
     * @param iterable<array<string, mixed>> $submissions oldest first
     * @return array{csv: string, rows: int, truncated: bool}
     */
    public static function build(iterable $submissions, ?FormDefinition $form, int $maxRows = self::MAX_ROWS): array
    {
        $handle = fopen('php://temp/maxmemory:2097152', 'w+');

        fwrite($handle, "\xEF\xBB\xBF");
        self::put($handle, self::headers($form));

        $rows      = 0;
        $truncated = false;

        foreach ($submissions as $submission) {
            if ($rows >= $maxRows) {
                $truncated = true;
                break;
            }
            self::put($handle, self::row($submission, $form));
            $rows++;
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return ['csv' => $csv, 'rows' => $rows, 'truncated' => $truncated];
    }

    public static function safeCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)) {
            return "'" . $value;
        }

        return $value;
    }

    /** @return string[] */
    private static function headers(?FormDefinition $form): array
    {
        $headers = ['ID', 'Form', 'Received (UTC)', 'Status'];

        if ($form !== null) {
            foreach ($form->fields as $field) {
                $headers[] = $field->label;
            }
            $headers[] = 'Email status';

            return $headers;
        }

        return [...$headers, 'Email status', 'Answers (JSON)'];
    }

    /**
     * @param array<string, mixed> $submission
     * @return string[]
     */
    private static function row(array $submission, ?FormDefinition $form): array
    {
        $data = is_array($submission['data'] ?? null) ? $submission['data'] : [];
        $row  = [
            (string) $submission['id'],
            (string) $submission['form_slug'],
            (string) $submission['created_at'],
            (string) $submission['status'],
        ];

        if ($form !== null) {
            foreach ($form->fields as $field) {
                $row[] = SubmissionPresenter::display($data[$field->name] ?? '', $field);
            }
            $row[] = (string) $submission['notify_status'];
        } else {
            $row[] = (string) $submission['notify_status'];
            $row[] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return array_map(static fn (string $cell): string => self::safeCell($cell), $row);
    }

    /** @param resource $handle @param string[] $fields */
    private static function put($handle, array $fields): void
    {
        // The explicit empty $escape (and "\r\n" ending) keeps this correct on PHP 8.4, which deprecates relying on the default.
        fputcsv($handle, $fields, ',', '"', '', "\r\n");
    }
}

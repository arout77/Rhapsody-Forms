<?php

namespace Arout\Forms\Mail;

use Arout\Forms\Form\FormDefinition;
use Rhapsody\Core\Modules\Facades\MailFacade;

/**
 * Emails the site owner about a new submission.
 *
 * Mail is best-effort: the submission is already saved before this runs, so
 * every failure is caught, logged, and reported back as a status string the
 * inbox can show. Reply-To is the visitor's address (the first email-type
 * field), so "Reply" in the owner's mail client answers the visitor. From is
 * always the site's own address (MailFacade enforces that).
 */
final class NotificationMailer
{
    public const SENT    = 'sent';
    public const FAILED  = 'failed';
    public const SKIPPED = 'skipped';

    public function __construct(private readonly MailFacade $mail)
    {
    }

    /** @param array<string, string|bool> $values */
    public function send(FormDefinition $form, int $submissionId, array $values, string $to): string
    {
        try {
            if (! $this->mail->isConfigured()) {
                return self::SKIPPED;
            }

            $replyTo = null;
            $email   = $form->emailField();
            if ($email !== null) {
                $candidate = $values[$email->name] ?? '';
                if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                    $replyTo = $candidate;
                }
            }

            $this->mail->send(
                to: $to,
                subject: 'New submission: ' . $form->name,
                htmlBody: $this->html($form, $submissionId, $values),
                plainTextBody: $this->text($form, $submissionId, $values),
                replyTo: $replyTo,
            );

            return self::SENT;
        } catch (\Throwable $e) {
            error_log('Forms: notification for submission #' . $submissionId . ' failed: ' . $e->getMessage());

            return self::FAILED;
        }
    }

    /** @param array<string, string|bool> $values */
    private function html(FormDefinition $form, int $id, array $values): string
    {
        $rows = '';
        foreach ($form->fields as $field) {
            if ($field->type === 'hidden') {
                continue;
            }
            $rows .= '<tr><th align="left" valign="top" style="padding:6px 12px 6px 0;white-space:nowrap">' .
                $this->e($field->label) . '</th><td style="padding:6px 0">' .
                nl2br($this->e($this->display($values[$field->name] ?? ''))) . '</td></tr>';
        }

        return '<p>New submission on <strong>' . $this->e($form->name) . '</strong>:</p>' .
            '<table cellpadding="0" cellspacing="0" style="font-family:sans-serif;font-size:14px">' . $rows . '</table>' .
            '<p style="color:#666;font-size:12px">Submission #' . $id . '</p>';
    }

    /** @param array<string, string|bool> $values */
    private function text(FormDefinition $form, int $id, array $values): string
    {
        $lines = ['New submission on ' . $form->name, ''];
        foreach ($form->fields as $field) {
            if ($field->type === 'hidden') {
                continue;
            }
            $lines[] = $field->label . ': ' . $this->display($values[$field->name] ?? '');
        }
        $lines[] = '';
        $lines[] = 'Submission #' . $id;

        return implode("\n", $lines);
    }

    private function display(string|bool $value): string
    {
        return is_bool($value) ? ($value ? 'Yes' : 'No') : $value;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

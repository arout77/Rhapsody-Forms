<?php

namespace Arout\Forms\Support;

use Arout\Forms\Submission\SubmissionResult;

/**
 * Carries the outcome of a submission across the redirect back to the page,
 * so the form can show its message, the per-field errors, and what the
 * visitor had typed. Read once and then forgotten.
 *
 * It uses its own session key rather than the framework's flash_success /
 * flash_error ones, so a theme that already prints those doesn't show the
 * message twice.
 */
final class FlashState
{
    private const KEY = '_rforms';

    public static function put(string $formSlug, SubmissionResult $result): void
    {
        if (! isset($_SESSION) || ! is_array($_SESSION)) {
            return; // no session: the message is simply lost, the submission isn't
        }

        $_SESSION[self::KEY][$formSlug] = [
            'ok'      => $result->isOk(),
            'message' => $result->message,
            'errors'  => $result->errors,
            // Only worth keeping when the visitor has to fix something and resend.
            'values'  => $result->isOk() ? [] : $result->values,
        ];
    }

    /** @return array{ok: bool, message: string, errors: array<string, string[]>, values: array<string, mixed>}|null */
    public static function pull(string $formSlug): ?array
    {
        if (! isset($_SESSION[self::KEY][$formSlug]) || ! is_array($_SESSION[self::KEY][$formSlug])) {
            return null;
        }

        $state = $_SESSION[self::KEY][$formSlug];
        unset($_SESSION[self::KEY][$formSlug]);

        return $state;
    }
}

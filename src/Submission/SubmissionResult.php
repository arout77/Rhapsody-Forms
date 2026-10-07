<?php

namespace Arout\Forms\Submission;

/**
 * What happened to a submission, in a form the HTTP layer can act on.
 */
final class SubmissionResult
{
    public const OK         = 'ok';
    public const INVALID    = 'invalid';
    public const EXPIRED    = 'expired';
    public const TOO_FAST   = 'too_fast';
    public const THROTTLED  = 'throttled';
    public const CAPTCHA    = 'captcha';
    public const REJECTED   = 'rejected';
    public const ERROR      = 'error';
    public const NOT_FOUND  = 'not_found';

    /**
     * @param array<string, string[]>    $errors per-field messages (INVALID only)
     * @param array<string, string|bool> $values what the visitor typed, to refill the form
     * @param bool                       $silent true when a bot was fooled into thinking it succeeded
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $errors = [],
        public readonly array $values = [],
        public readonly ?int $submissionId = null,
        public readonly bool $silent = false,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function httpStatus(): int
    {
        return match ($this->status) {
            self::OK        => 200,
            self::NOT_FOUND => 404,
            self::THROTTLED => 429,
            self::ERROR     => 500,
            default         => 422,
        };
    }
}

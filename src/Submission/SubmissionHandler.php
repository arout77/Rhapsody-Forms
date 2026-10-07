<?php

namespace Arout\Forms\Submission;

use Arout\Forms\Events\FormSubmitted;
use Arout\Forms\Events\FormSubmitting;
use Arout\Forms\Form\FormDefinition;
use Arout\Forms\Mail\NotificationMailer;
use Arout\Forms\Storage\SubmissionRepository;
use Arout\Forms\Support\ClientIp;
use Arout\Forms\Support\FormToken;
use Arout\Forms\Validation\FieldValidator;
use Closure;
use Rhapsody\Core\Event;

/**
 * The submission pipeline, in the order that costs the least first:
 *
 *   1. honeypot        a bot filled the hidden field -> pretend it worked, store nothing
 *   2. time-trap token missing/forged/expired/too fast -> ask the visitor to try again
 *   3. validation      field rules (before the captcha, so a typo doesn't burn a captcha solve)
 *   4. throttle        too many from this visitor lately -> slow down
 *   5. captcha         only when configured and not turned off for this form
 *   6. FormSubmitting  listeners may veto
 *   7. store           saved BEFORE anything that can fail slowly (events, email)
 *   8. FormSubmitted   follow-up work for other modules
 *   9. email           best-effort, status recorded on the submission
 *
 * Everything outside the database is injected as a Closure or small object,
 * so the pipeline can be tested without the framework.
 */
final class SubmissionHandler
{
    public const TOKEN_TTL_SECONDS = 86400;

    /** @var array<string, mixed> */
    private array $options;

    private Closure $clock;

    /**
     * @param Closure(Event): Event          $dispatch      fires an event, returns it
     * @param Closure(string, ?string): bool $captchaVerify verifies a captcha token for a client IP
     * @param array<string, mixed>           $options       min_submit_seconds, trusted_proxy_header,
     *                                                      retention_days, default_notify_email, purge_one_in
     */
    public function __construct(
        private readonly SubmissionRepository $repository,
        private readonly FieldValidator $validator,
        private readonly NotificationMailer $notifier,
        private readonly string $secret,
        private readonly Closure $dispatch,
        private readonly Closure $captchaVerify,
        private readonly bool $captchaAvailable,
        array $options = [],
        ?Closure $clock = null,
    ) {
        $this->options = $options + [
            'min_submit_seconds'   => 2,
            'trusted_proxy_header' => '',
            'retention_days'       => 0,
            'default_notify_email' => '',
            'purge_one_in'         => 50,
        ];
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param array<string, mixed> $input  the posted fields
     * @param array<string, mixed> $server $_SERVER-style array
     */
    public function handle(FormDefinition $form, array $input, array $server): SubmissionResult
    {
        $now = ($this->clock)();

        // 1. Honeypot. Same response as success, so the bot has nothing to adapt to.
        if (trim((string) ($input['hp_website'] ?? '')) !== '') {
            return new SubmissionResult(SubmissionResult::OK, (string) $form->settings['success_message'], silent: true);
        }

        // 2. Time-trap
        $tokenState = FormToken::check(
            (string) ($input['_ts'] ?? ''),
            $form->slug,
            $now,
            $this->secret,
            (int) $this->options['min_submit_seconds'],
            self::TOKEN_TTL_SECONDS,
        );

        // Refill the form for every outcome below that sends the visitor back to it.
        $validation = $this->validator->validate($form, $input);
        $values     = $validation->values;

        if ($tokenState === FormToken::INVALID) {
            return new SubmissionResult(SubmissionResult::ERROR, 'Something went wrong. Please reload the page and try again.', values: $values);
        }
        if ($tokenState === FormToken::EXPIRED) {
            return new SubmissionResult(SubmissionResult::EXPIRED, 'This form has expired. Please check your details and send it again.', values: $values);
        }
        if ($tokenState === FormToken::TOO_FAST) {
            return new SubmissionResult(SubmissionResult::TOO_FAST, 'That was quick! Please check your details and send it again.', values: $values);
        }

        // 3. Validation
        if (! $validation->passes()) {
            return new SubmissionResult(SubmissionResult::INVALID, 'Please fix the highlighted fields.', $validation->errors, $values);
        }

        // 4. Throttle (counts stored submissions per visitor, hashed)
        $ip     = ClientIp::resolve($server, (string) $this->options['trusted_proxy_header']);
        $ipHash = ClientIp::hash($ip, $this->secret);
        $limit  = (int) $form->settings['throttle_per_hour'];

        if ($limit > 0) {
            try {
                $recent = $this->repository->countRecentByIp($form->slug, $ipHash, $now - 3600);
            } catch (\Throwable $e) {
                // If we can't even read the table we can't save the submission either:
                // say so now, before spending a captcha verification on it.
                error_log('Forms: throttle lookup failed for "' . $form->slug . '": ' . $e->getMessage());

                return new SubmissionResult(SubmissionResult::ERROR, "We couldn't save your message. Please try again.", values: $values);
            }

            if ($recent >= $limit) {
                return new SubmissionResult(SubmissionResult::THROTTLED, "You've sent several messages recently. Please try again a little later.", values: $values);
            }
        }

        // 5. Captcha
        if ($form->settings['captcha'] === 'auto' && $this->captchaAvailable) {
            $captchaToken = (string) ($input['g-recaptcha-response'] ?? '');

            $passed = false;
            if ($captchaToken !== '') {
                try {
                    $passed = ($this->captchaVerify)($captchaToken, $ip);
                } catch (\Throwable $e) {
                    // Google unreachable, allow_url_fopen off, a warning turned into an exception...
                    // Fail closed, but politely, and never as a 500.
                    error_log('Forms: captcha verification failed to run: ' . $e->getMessage());

                    return new SubmissionResult(SubmissionResult::CAPTCHA, "We couldn't verify the captcha. Please try again.", values: $values);
                }
            }

            if (! $passed) {
                return new SubmissionResult(SubmissionResult::CAPTCHA, 'Please verify that you are not a robot.', values: $values);
            }
        }

        // 6. Veto
        $submitting = new FormSubmitting(
            $form->slug,
            $values,
            ['ip' => $ip, 'user_agent' => $this->userAgent($server)],
        );
        $this->fire($submitting);

        if ($submitting->isRejected()) {
            return new SubmissionResult(
                SubmissionResult::REJECTED,
                $submitting->reason() !== '' ? $submitting->reason() : 'Your submission could not be accepted.',
                values: $values,
            );
        }

        // 7. Store
        try {
            $id = $this->repository->insert($form->slug, $values, $ipHash, $this->userAgent($server), $now);
        } catch (\Throwable $e) {
            error_log('Forms: could not save a submission to "' . $form->slug . '": ' . $e->getMessage());

            return new SubmissionResult(SubmissionResult::ERROR, "We couldn't save your message. Please try again.", values: $values);
        }

        // 8. Follow-up work
        $this->fire(new FormSubmitted($id, $form->slug, $form->name, $values));

        // 9. Email
        $to = (string) ($form->settings['notify_email'] ?? $this->options['default_notify_email']);
        $notifyStatus = $to === ''
            ? NotificationMailer::SKIPPED
            : $this->notifier->send($form, $id, $values, $to);

        try {
            $this->repository->setNotifyStatus($id, $notifyStatus);
        } catch (\Throwable $e) {
            error_log('Forms: could not record notify status for #' . $id . ': ' . $e->getMessage());
        }

        $this->maybePurge($now);

        return new SubmissionResult(SubmissionResult::OK, (string) $form->settings['success_message'], values: [], submissionId: $id);
    }

    /** Dispatching must never be the reason a real visitor sees an error. */
    private function fire(Event $event): void
    {
        try {
            ($this->dispatch)($event);
        } catch (\Throwable $e) {
            error_log('Forms: dispatching ' . $event::class . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Retention without a scheduler: occasionally clear one small batch of
     * expired submissions as a side effect of a real submission.
     */
    private function maybePurge(int $now): void
    {
        $days  = (int) $this->options['retention_days'];
        $oneIn = max(1, (int) $this->options['purge_one_in']);

        if ($days < 1 || random_int(1, $oneIn) !== 1) {
            return;
        }

        try {
            $this->repository->purgeOlderThan($days, $now);
        } catch (\Throwable $e) {
            error_log('Forms: retention purge failed: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $server */
    private function userAgent(array $server): string
    {
        $ua = $server['HTTP_USER_AGENT'] ?? '';

        return is_string($ua) ? mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', $ua), 0, 255) : '';
    }
}

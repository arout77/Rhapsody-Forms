<?php

namespace Arout\Forms\Events;

use Rhapsody\Core\Event;

/**
 * Fired after a submission has passed validation and spam checks, BEFORE it
 * is stored. A listener (a spam filter, say) can veto it with reject().
 *
 * Every listener still runs after one rejects: this is a "feedback" event
 * (the dispatching code reads the outcome afterwards), not a stop-
 * propagation event. Listener exceptions are isolated by the dispatcher and
 * count as "no objection", so a broken filter can't block real visitors.
 *
 * $meta carries 'ip' and 'user_agent' so filters like Akismet can work.
 * They are NOT stored with the submission (only a hash of the IP is).
 */
final class FormSubmitting extends Event
{
    private bool $rejected = false;
    private string $reason  = '';

    /**
     * @param array<string, string|bool> $data
     * @param array<string, string>      $meta
     */
    public function __construct(
        public readonly string $formSlug,
        public readonly array $data,
        public readonly array $meta = [],
    ) {
    }

    /** @param string $reason Shown to the visitor, so keep it polite and generic */
    public function reject(string $reason = ''): void
    {
        $this->rejected = true;
        if ($this->reason === '') {
            $this->reason = $reason;
        }
    }

    public function isRejected(): bool
    {
        return $this->rejected;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}

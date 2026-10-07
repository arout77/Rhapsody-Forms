<?php

namespace Arout\Forms\Events;

use Rhapsody\Core\Event;

/**
 * Fired after a submission has been saved. Safe to use for follow-up work
 * (add the visitor to a newsletter, post to a webhook). The submission is
 * already stored, so a failing listener can never lose it.
 *
 * Treat the property list as a public API: other packages may listen.
 */
final class FormSubmitted extends Event
{
    /** @param array<string, string|bool> $data field name => submitted value */
    public function __construct(
        public readonly int $submissionId,
        public readonly string $formSlug,
        public readonly string $formName,
        public readonly array $data,
    ) {
    }
}

<?php

namespace Arout\Forms\Validation;

final class ValidationResult
{
    /**
     * @param array<string, string[]>    $errors field name => messages
     * @param array<string, string|bool> $values cleaned values for every defined field
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $values,
    ) {
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }
}

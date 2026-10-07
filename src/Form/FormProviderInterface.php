<?php

namespace Arout\Forms\Form;

/**
 * Lets another package (Forms Pro's visual builder, for instance) supply
 * form definitions from somewhere other than PHP code.
 */
interface FormProviderInterface
{
    public function find(string $slug): ?FormDefinition;
}

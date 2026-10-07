<?php

namespace Arout\Forms\Form;

/**
 * Where forms are registered and looked up.
 *
 *   FormRegistry::register([
 *       'slug'   => 'contact',
 *       'name'   => 'Contact us',
 *       'fields' => [ ['name' => 'email', 'type' => 'email', 'rules' => 'required'], ... ],
 *   ]);
 *
 * Call it once per request from the application's bootstrap, before any
 * page renders {{ rhapsody_form('contact') }}. Forms registered here win over
 * forms supplied by a provider.
 */
final class FormRegistry
{
    /** @var array<string, FormDefinition> */
    private static array $forms = [];

    /** @var FormProviderInterface[] */
    private static array $providers = [];

    /** @param array<string, mixed>|FormDefinition $form */
    public static function register(array|FormDefinition $form): FormDefinition
    {
        $definition = $form instanceof FormDefinition ? $form : FormDefinition::fromArray($form);

        self::$forms[$definition->slug] = $definition;

        return $definition;
    }

    public static function addProvider(FormProviderInterface $provider): void
    {
        self::$providers[] = $provider;
    }

    public static function find(string $slug): ?FormDefinition
    {
        if (isset(self::$forms[$slug])) {
            return self::$forms[$slug];
        }

        foreach (self::$providers as $provider) {
            $found = $provider->find($slug);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return array<string, FormDefinition> only the forms registered directly, not provider-supplied ones */
    public static function registered(): array
    {
        return self::$forms;
    }

    /** Mainly for tests, and for long-running workers that rebuild their forms. */
    public static function reset(): void
    {
        self::$forms     = [];
        self::$providers = [];
    }
}

<?php

namespace Arout\Forms\Twig;

use Arout\Forms\Form\FormRegistry;
use Arout\Forms\Support\CaptchaBridge;
use Arout\Forms\Support\FlashState;
use Arout\Forms\Support\FormToken;
use Arout\Forms\Support\LocalPath;
use Arout\Forms\View\FormStyles;
use Arout\Forms\View\FormViewModel;
use Closure;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Adds {{ rhapsody_form('slug') }} to Twig.
 *
 * Registered as an extension (twig.extensions) rather than through the
 * facade's addFunction(), because addFunction() always prefixes the name
 * with mod_{slug}_ and the tag is meant to read cleanly in a template.
 */
final class FormsExtension extends AbstractExtension
{
    private bool $cssEmitted = false;

    /**
     * @param string                          $viewsNamespace the module's Twig namespace (its slug), e.g. "arout-rhapsody-forms"
     * @param Closure(): ?string              $secret         the signing secret, null when unavailable
     * @param Closure(): array<string, mixed> $options        the module-wide settings
     * @param string                          $baseUrl        APP_BASE_URL (path or full URL), no trailing slash
     * @param (Closure(): int)|null           $clock
     */
    public function __construct(
        private readonly string $viewsNamespace,
        private readonly Closure $secret,
        private readonly Closure $options,
        private readonly string $baseUrl,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('rhapsody_form', [$this, 'render'], [
                'needs_environment' => true,
                'needs_context'     => true,
                'is_safe'           => ['html'],
            ]),
        ];
    }

    /** @param array<string, mixed> $context */
    public function render(Environment $env, array $context, string $slug): string
    {
        $form = FormRegistry::find($slug);
        if ($form === null) {
            // A comment, not an error: the page still renders, and the developer can spot it in View Source.
            return '<!-- rhapsody_form: no form is registered with the slug "' . $this->commentSafe($slug) . '" -->';
        }

        $secret = ($this->secret)();
        if ($secret === null) {
            return '<!-- rhapsody_form: the signing secret is unavailable, see the PHP error log -->';
        }

        $options = ($this->options)();
        $captcha = ($form->settings['captcha'] === 'auto' && CaptchaBridge::isEnabled()) ? CaptchaBridge::widget($context) : '';
        $css     = null;
        if (($options['include_css'] ?? true) && ! $this->cssEmitted) {
            $css              = FormStyles::css();
            $this->cssEmitted = true;
        }

        $now = $this->clock !== null ? ($this->clock)() : time();

        $model = FormViewModel::build(
            $form,
            $this->baseUrl . '/forms/submit/' . $form->slug,
            FormToken::issue($form->slug, $now, $secret),
            LocalPath::sanitize($_SERVER['REQUEST_URI'] ?? null, '/'),
            FlashState::pull($form->slug),
            $captcha,
            $css,
        );

        return $env->render('@' . $this->viewsNamespace . '/form.twig', $model);
    }

    private function commentSafe(string $value): string
    {
        return htmlspecialchars(str_replace('--', '- -', $value), ENT_QUOTES, 'UTF-8');
    }
}

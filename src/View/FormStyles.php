<?php

namespace Arout\Forms\View;

/**
 * The default stylesheet, printed inline once per page (before the first
 * form) unless the include_css setting is off.
 *
 * Same approach as the framework's module baseline: colours come from
 * currentColor so it works on light and dark themes alike, every selector is
 * one class wide so a theme can override anything, and the optional
 * --rhapsody-* variables (primary colour, radius, ...) are honoured.
 */
final class FormStyles
{
    public static function css(): string
    {
        return <<<'CSS'
:where(.rforms){--_primary:var(--rhapsody-primary,#4f46e5);--_on-primary:var(--rhapsody-primary-contrast,#fff);--_danger:var(--rhapsody-danger,#dc2626);--_success:var(--rhapsody-success,#16a34a);--_radius:var(--rhapsody-radius,.5rem);--_gap:var(--rhapsody-gap,1rem);--_border:var(--rhapsody-border,color-mix(in oklab,currentColor 25%,transparent));--_surface:var(--rhapsody-surface,color-mix(in oklab,currentColor 6%,transparent));display:grid;gap:var(--_gap);max-inline-size:40rem;box-sizing:border-box;color:inherit;font:inherit;line-height:1.5;accent-color:var(--_primary)}
:where(.rforms) *,:where(.rforms) *::before,:where(.rforms) *::after{box-sizing:inherit}
:where(.rforms) .rforms__field{display:grid;gap:.35rem}
:where(.rforms) .rforms__label{font-size:.9rem;font-weight:600}
:where(.rforms) .rforms__help{font-size:.8rem;opacity:.7}
:where(.rforms) .rforms__input{inline-size:100%;padding:.55rem .75rem;color:inherit;font:inherit;background:transparent;border:1px solid var(--_border);border-radius:var(--_radius)}
:where(.rforms) textarea.rforms__input{min-block-size:7rem;resize:vertical}
:where(.rforms) .rforms__input:focus-visible{outline:2px solid var(--_primary);outline-offset:1px}
:where(.rforms) option{color:CanvasText;background:Canvas}
:where(.rforms) .rforms__choice{display:flex;align-items:center;gap:.5rem}
:where(.rforms) .rforms__field--error .rforms__input{border-color:var(--_danger)}
:where(.rforms) .rforms__error{font-size:.85rem;color:var(--_danger)}
:where(.rforms) .rforms__alert{padding:.75rem 1rem;border:1px solid var(--_alert,var(--_border));border-inline-start-width:4px;border-radius:var(--_radius);background:color-mix(in oklab,var(--_alert,currentColor) 12%,transparent)}
:where(.rforms) .rforms__alert--success{--_alert:var(--_success)}
:where(.rforms) .rforms__alert--error{--_alert:var(--_danger)}
:where(.rforms) .rforms__submit{justify-self:start;padding:.6rem 1.2rem;color:var(--_on-primary);font:inherit;font-weight:600;line-height:1.2;cursor:pointer;background:var(--_primary);border:1px solid transparent;border-radius:var(--_radius)}
:where(.rforms) .rforms__submit:hover{filter:brightness(1.1)}
:where(.rforms) .rforms__submit:focus-visible{outline:2px solid var(--_primary);outline-offset:2px}
CSS;
    }
}

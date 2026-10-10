<?php

namespace Arout\Forms\View;

/**
 * The few rules the forms need ON TOP of the framework's shared module
 * baseline (@core/partials/rhapsody-ui.css), printed with it once per page
 * unless the include_css setting is off.
 *
 * The look itself (inputs, buttons, alerts, colours, the optional
 * --rhapsody-* theme variables) comes from that shared baseline, exactly as on
 * module pages, so there is one place to fix dark themes and one contract for
 * themes to style. This file only adjusts two things the baseline can't know:
 *
 *  - The baseline's .rhapsody-ui root is built for a whole PAGE (72rem wide,
 *    centred, padded to clear fixed menus). A form sits inside the theme's own
 *    content area, so the wrapper is reset to "just be a box".
 *  - Radio buttons and checkboxes have no class in the shared contract.
 *
 * Both selectors are more specific than the baseline's zero-specificity root,
 * so they win regardless of order.
 */
final class FormStyles
{
    public static function css(): string
    {
        return <<<'CSS'
.rhapsody-ui.rforms-wrap{max-inline-size:none;margin:0;padding:0}
.rhapsody-ui.rforms-wrap .rforms__choice{display:flex;align-items:center;gap:.5rem}
CSS;
    }
}

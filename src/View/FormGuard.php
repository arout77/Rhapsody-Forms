<?php

namespace Arout\Forms\View;

/**
 * A small inline script, printed once per page with the first form, that
 * keeps form text readable on any theme.
 *
 * The shared baseline takes its text colour from the surrounding page
 * ("inherit") and derives borders from it. That is perfect when the theme
 * colours its content area, but plenty of dark themes colour individual
 * elements instead and leave the container at the browser default of black,
 * so a form dropped into such an area is black on dark: invisible.
 *
 * The framework's module pages solve this with a contrast guard in
 * rhapsody-ui.js, but that script only loads on module pages, and it also
 * measures fixed menus, which an embedded form has no use for. This is the
 * contrast half of it, for every .rforms-wrap on the page:
 *
 *   1. find the background actually painted behind the form (first mostly
 *      opaque background on the form's ancestors; if the theme never sets
 *      one, fall back to dark/light markers such as a "dark" class or
 *      data-theme="dark" on <html>/<body>)
 *   2. if the inherited text colour is below 4.5:1 against it (WCAG AA), put
 *      a readable colour on the wrapper
 *   3. set color-scheme (unless the theme declared one) so native controls,
 *      such as the open drop-down list, match light or dark
 *
 * A theme that already works is left alone. It re-checks when the page
 * loads and when a dark-mode switch flips a class or data-theme attribute.
 * Without JavaScript the form keeps its inherited colours.
 */
final class FormGuard
{
    public static function js(): string
    {
        return <<<'JS'
(function () {
  'use strict';
  if (window.__rformsGuard) { return; }
  window.__rformsGuard = true;

  var timer = 0;
  var cx = null;
  try {
    var cv = document.createElement('canvas');
    cv.width = 1;
    cv.height = 1;
    cx = cv.getContext('2d', { willReadFrequently: true });
  } catch (e) { cx = null; }

  // Computed colours are almost always rgb()/rgba(), parsed directly. Anything
  // fancier (oklch, color-mix results) goes through a 1x1 canvas, which accepts
  // every CSS colour syntax the browser understands.
  function toRGBA(css) {
    var m = /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,\/]\s*([\d.]+%?))?\s*\)$/i.exec(String(css).trim());
    if (m) {
      var a = m[4] === undefined ? 1 : (m[4].slice(-1) === '%' ? parseFloat(m[4]) / 100 : parseFloat(m[4]));
      return [parseFloat(m[1]), parseFloat(m[2]), parseFloat(m[3]), a];
    }
    if (!cx) { return null; }
    cx.clearRect(0, 0, 1, 1);
    cx.fillStyle = '#000';   // reset so an unparseable value can't reuse the last colour
    cx.fillStyle = css;
    cx.fillRect(0, 0, 1, 1);
    var d = cx.getImageData(0, 0, 1, 1).data;
    return [d[0], d[1], d[2], d[3] / 255];
  }

  // WCAG relative luminance and contrast ratio.
  function lum(c) {
    var v = [c[0], c[1], c[2]].map(function (n) {
      n /= 255;
      return n <= 0.03928 ? n / 12.92 : Math.pow((n + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
  }
  function contrast(a, b) {
    var l1 = lum(a);
    var l2 = lum(b);
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
  }

  // First mostly-opaque background colour walking up from the form.
  function backgroundBehind(el) {
    for (var n = el; n && n.nodeType === 1; n = n.parentElement) {
      var c = toRGBA(window.getComputedStyle(n).backgroundColor);
      if (c && c[3] >= 0.5) { return c; }
    }
    return null;
  }

  // Used only when no ancestor paints a background colour (e.g. a gradient or
  // image does the work): look for the usual "this page is dark" markers.
  function declaredDark() {
    var els = [document.documentElement, document.body];
    for (var i = 0; i < els.length; i++) {
      var e = els[i];
      if (!e) { continue; }
      var cls = String(e.className || '').toLowerCase().split(/\s+/);
      if (cls.indexOf('dark') !== -1 || cls.indexOf('dark-mode') !== -1 || cls.indexOf('theme-dark') !== -1) { return true; }
      var t = String(e.getAttribute('data-theme') || e.getAttribute('data-bs-theme') || '').toLowerCase();
      if (t.indexOf('dark') !== -1) { return true; }
      if (/dark/.test(window.getComputedStyle(e).colorScheme || '')) { return true; }
    }
    return false;
  }

  function guard(root) {
    // Undo our own earlier correction first, so we always judge what the
    // theme provides (matters after a dark/light switch).
    root.style.removeProperty('color');
    root.style.removeProperty('color-scheme');

    var bg = backgroundBehind(root);
    var dark = bg ? lum(bg) < 0.4 : declaredDark();
    var cs = window.getComputedStyle(root);

    if (!cs.colorScheme || cs.colorScheme === 'normal') {
      root.style.setProperty('color-scheme', dark ? 'dark' : 'light');
    }

    var fg = toRGBA(cs.color);
    if (!fg) { return; }

    var against = bg || (dark ? [11, 15, 25, 1] : [255, 255, 255, 1]);
    if (contrast(fg, against) < 4.5) {
      root.style.setProperty('color', dark ? '#f3f4f6' : '#111827');
    }
  }

  function run() {
    var roots = document.querySelectorAll('.rforms-wrap');
    for (var i = 0; i < roots.length; i++) { guard(roots[i]); }
  }

  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(run, 100);
  }

  run();
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', schedule); }
  window.addEventListener('load', schedule);
  document.addEventListener('transitionend', schedule, true);   // animated theme switches

  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    if (mq.addEventListener) { mq.addEventListener('change', schedule); }
  }

  // Dark-mode switches usually flip a class or data attribute on <html> or <body>.
  // Only those two elements are observed (not their subtrees), so our own style
  // writes on the form wrapper can never retrigger this.
  if (window.MutationObserver) {
    var mo = new MutationObserver(schedule);
    var opts = { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme'] };
    mo.observe(document.documentElement, opts);
    if (document.body) { mo.observe(document.body, opts); }
  }
})();
JS;
    }
}

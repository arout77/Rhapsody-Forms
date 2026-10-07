# Rhapsody Forms

Define a form in a few lines of PHP, drop it into any page with one Twig tag, and manage what people send you in an admin inbox. Spam protection, email notifications and a developer-friendly event system are built in.

**Package:** `arout/forms` &nbsp;|&nbsp; **Type:** Rhapsody module &nbsp;|&nbsp; **License:** proprietary

This is the **Core** tier of the "Rhapsody Core Team" module suite. It is written for Rhapsody developers and agencies building sites for clients.

---

## Contents

1. [What you get](#what-you-get)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Quick start: your first form in five minutes](#quick-start-your-first-form-in-five-minutes)
5. [Defining forms](#defining-forms)
6. [Showing a form on a page](#showing-a-form-on-a-page)
7. [What happens when someone submits](#what-happens-when-someone-submits)
8. [Spam protection](#spam-protection)
9. [Email notifications](#email-notifications)
10. [The inbox](#the-inbox)
11. [Settings](#settings)
12. [Events: reacting to submissions from your own code](#events-reacting-to-submissions-from-your-own-code)
13. [Where your data lives (and privacy)](#where-your-data-lives-and-privacy)
14. [Security notes](#security-notes)
15. [Troubleshooting](#troubleshooting)
16. [Known limitations](#known-limitations)
17. [Core and Pro](#core-and-pro)
18. [Uninstalling](#uninstalling)
19. [Changelog](#changelog)

---

## What you get

- **Forms defined in code.** A plain PHP array describes the fields, rules and settings. Mistakes (a misspelled rule, a duplicate field) are reported the moment the form is registered, not when a visitor hits submit.
- **One tag to display a form:** `{{ rhapsody_form('contact') }}`.
- **Ten field types:** text, email, tel, url, number, textarea, select, radio, checkbox, hidden.
- **Validation** using the same rule names as the framework (`required`, `email`, `min:5`, `in:a,b`, ...).
- **Five layers of spam protection** that need no setup, plus optional reCAPTCHA.
- **An admin inbox** to read, filter, mark as read, delete and export submissions as CSV.
- **Email notifications** with the visitor's address as Reply-To, so you answer by simply hitting Reply.
- **Events** (`FormSubmitting`, `FormSubmitted`) so other modules can veto spam or react to new submissions, such as adding the visitor to a newsletter.
- **Privacy-minded storage:** visitor IP addresses are never stored, only a one-way hash used for rate limiting.

---

## Requirements

| What | Why |
|------|-----|
| **Rhapsody core** with module support, and the core features listed below | The module relies on them. |
| **PHP 8.4.1 or newer** with the `mbstring` extension | Same as the framework. Names and messages are measured in characters, not bytes. |
| **MySQL or MariaDB** with `utf8mb4` | Submissions are stored as UTF-8 JSON (emoji included). |
| **Mail configured** (`MAIL_HOST` and friends in `.env`) | Only for email notifications. Forms work and save without it. |
| **An admin gate** (see [The inbox](#access-who-can-open-the-inbox)) | So only you can open the inbox. |

**Core features the module depends on.** Make sure the core release you run includes all of these:

- The `mail.send` module permission (`$context->mail()`).
- The `events.dispatch` module permission.
- The built-in `admin` route middleware.
- Route middleware that **fails closed**, meaning a route that names a middleware that isn't registered throws an error instead of silently running unprotected.
- `Recaptcha::isEnabled()`.

> **Safety check built in.** When the module starts it looks for the core `admin` middleware. If it isn't there, the module refuses to register the inbox routes and writes a line to the PHP error log, rather than risk exposing your submissions to the public.

---

## Installation

From your application's root folder (the one containing `composer.json`):

```bash
composer require arout/rhapsody-forms
php rhapsody module:install arout/rhapsody-forms
```

The second command activates the module. Composer having the package is not enough on its own: activation is a separate step, tracked by the framework.

Activation creates one database table, `mod_arout_forms_submissions` (see [Where your data lives](#where-your-data-lives-and-privacy)), and generates a private signing secret (see [Settings](#settings)).

---

## Quick start: your first form in five minutes

### Step 1. Decide who counts as an admin

Open your `.env` file and add the id of your own user account. If you don't know it, look at the `user_id` column of your `users` table.

```ini
ADMIN_USER_IDS=1
```

Several admins? Separate the ids with commas: `ADMIN_USER_IDS=1,7`. If your application stores admins differently (an `is_admin` column, a roles table), see [Access: who can open the inbox](#access-who-can-open-the-inbox) instead.

### Step 2. Tell the module where to email submissions

Open `storage/modules/arout-forms/settings.json` and set a default recipient:

```json
{
    "notify_email": "owner@example.com"
}
```

Skip this step if you only want submissions saved in the inbox. If your site's mail isn't set up yet, see [Email notifications](#email-notifications).

### Step 3. Register a form

Forms are registered in PHP, once per request, before any page is drawn. The simplest place is the `bootstrap.php` in your **application root**: the file your own code uses to hook into the framework's startup, not the one inside `vendor/`.

```php
<?php

use Arout\Forms\Form\FormRegistry;

FormRegistry::register([
    'slug'   => 'contact',
    'name'   => 'Contact us',
    'fields' => [
        ['name' => 'name',    'label' => 'Your name', 'rules' => 'required|max:100'],
        ['name' => 'email',   'label' => 'Email',     'type' => 'email', 'rules' => 'required'],
        ['name' => 'message', 'label' => 'Message',   'type' => 'textarea', 'rules' => 'required|min:10'],
    ],
]);
```

### Step 4. Put the form on a page

In any Twig template (a page, a partial, a footer):

```twig
<h1>Get in touch</h1>

{{ rhapsody_form('contact') }}
```

There is no `|raw` filter to add: the tag returns safe HTML.

### Step 5. Try it

1. Open the page, wait a couple of seconds, fill the form in and send it.
2. You should see the success message, and an email should arrive if mail is configured.
3. Log in with the admin account and open **`/forms/inbox`** to see the submission.

That's it. The rest of this document explains each piece in detail.

---

## Defining forms

A form is an array with four possible keys: `slug`, `name`, `fields` and `settings`.

```php
FormRegistry::register([
    'slug'     => 'quote-request',       // required: used in URLs and the inbox
    'name'     => 'Quote request',       // optional: shown in emails and the inbox
    'fields'   => [ /* see below */ ],   // required: at least one field
    'settings' => [ /* see below */ ],   // optional
]);
```

Any other key is rejected with a clear error. That is deliberate: it catches typos such as `fieldz` or `setting`.

### The form slug

- Lowercase letters, digits and dashes only, starting with a letter or digit, up to 64 characters.
- Good: `contact`, `quote-request`, `newsletter-2`. Bad: `Contact Us`, `contact_form`.
- The slug identifies the form everywhere: in `rhapsody_form('...')`, in the submit URL, and in the inbox. Changing it later means old submissions keep their old slug.

### Form settings

All optional. Anything you leave out gets the default.

| Setting | Default | What it does |
|---------|---------|--------------|
| `notify_email` | *(none)* | Where this form's submissions are emailed. If empty, the module-wide `notify_email` [setting](#settings) is used. If that is empty too, no email is sent. |
| `submit_label` | `Send` | Text on the submit button. |
| `success_message` | `Thanks! Your message has been sent.` | Shown after a successful submission. |
| `redirect` | *(none)* | A local path such as `/thanks`. After success the visitor is sent there instead of back to the page they were on. Full URLs (`https://...`) are refused. |
| `throttle_per_hour` | `5` | Maximum submissions per visitor, per form, per hour. Whole number; `0` means no limit. |
| `captcha` | `auto` | `auto` uses reCAPTCHA when your site has it configured. `off` skips it for this form. |

```php
'settings' => [
    'notify_email'      => 'sales@example.com',
    'submit_label'      => 'Request my quote',
    'success_message'   => 'Thank you! We will reply within one working day.',
    'redirect'          => '/thanks',
    'throttle_per_hour' => 3,
],
```

### Fields

Each field is an array. Only `name` is required.

| Key | Required | Meaning |
|-----|----------|---------|
| `name` | yes | The field's identifier. Lowercase letters, digits and underscores, starting with a letter, up to 64 characters (`first_name`, `phone2`). |
| `type` | no (`text`) | One of the [field types](#field-types). |
| `label` | no | Text shown to the visitor and in emails. Defaults to the name with underscores turned into spaces (`first_name` becomes "First name"). |
| `rules` | no | Validation rules joined with `\|`, for example `required\|max:100`. See [Rules](#rules). |
| `options` | select and radio only | The choices. See [Options](#options-for-select-and-radio). |
| `placeholder` | no | Faint hint text inside the box. |
| `help` | no | A small line of help under the field. |
| `default` | no | A starting value. |

**Reserved names.** You cannot use `_token`, `_ts`, `_form`, `_return`, `hp_website` or `g-recaptcha-response` as a field name. The module uses them internally.

**Only fields you define are saved.** If someone adds extra data to the request by hand, it is ignored.

### Field types

| Type | Renders as | Validated automatically as |
|------|------------|----------------------------|
| `text` | One-line text box | nothing extra |
| `email` | Email box | a valid email address |
| `tel` | Phone box | 5 to 30 characters: digits, `+ - ( ) .` and spaces |
| `url` | Web address box | a valid address starting with `http://` or `https://` (other schemes such as `javascript:` or `ftp:` are refused) |
| `number` | Number box | a number; `min` and `max` compare the value, not the length |
| `textarea` | Multi-line box | nothing extra |
| `select` | Drop-down | the value must be one of the options |
| `radio` | Radio buttons | the value must be one of the options |
| `checkbox` | Single tick box | stored as yes/no (see below) |
| `hidden` | Invisible input | nothing extra. Hidden fields are left out of notification emails. |

**Checkboxes** are single yes/no boxes. Ticked is stored as `true`, unticked as `false`. Give a checkbox the `required` rule to force it to be ticked ("I agree to the terms"). Groups of several tick boxes are not supported in Core.

### Options for select and radio

Three ways to write the list. Pick whichever reads best.

```php
// 1. A simple list: each text is both the value and the label
'options' => ['Sales', 'Support', 'Press'],

// 2. Value => label (use text keys; PHP turns numeric keys into integers)
'options' => ['sales' => 'Talk to sales', 'support' => 'Get support'],

// 3. A list of arrays: the safest choice, and the only one that handles numeric values
'options' => [
    ['value' => '0', 'label' => 'Not sure yet'],
    ['value' => '1', 'label' => 'Under 10 people'],
    ['value' => '2', 'label' => '10 people or more'],
],
```

Values must be unique within a field. The submitted value is checked against the list, so a visitor cannot send a choice you did not offer.

### Rules

Rules go in the `rules` string, separated by `|`. Some take a value after a colon.

| Rule | Passes when | Example |
|------|-------------|---------|
| `required` | the field is not empty. For a checkbox: it is ticked. | `required` |
| `accepted` | the value is `1`, `on`, `true` or `yes` | `accepted` |
| `email` | a valid email address | `email` |
| `url` | a valid `http`/`https` address | `url` |
| `numeric` | a number | `numeric` |
| `alpha` | only letters (accented letters count) | `alpha` |
| `alphaNum` | only letters and digits | `alphaNum` |
| `min:N` | at least N characters. For `number` fields, or with the `numeric` rule, the value is at least N. | `min:10` |
| `max:N` | at most N characters (value for numbers) | `max:50` |
| `in:a,b,c` | the value is one of the listed ones | `in:red,green` |
| `notIn:a,b,c` | the value is none of the listed ones | `notIn:admin,root` |
| `dateFormat:F` | a real date in format F (PHP date format) | `dateFormat:Y-m-d` |

Things worth knowing:

- **Optional empty fields are skipped.** Every rule except `required` only runs when the visitor typed something.
- **A value of `0` counts as filled in.** (The framework's own validator treats `0` as empty; this module deliberately does not.)
- **Length limits always apply.** Text fields allow up to 255 characters and textareas up to 5,000 unless you say otherwise with `max:`. Nothing over 20,000 characters is ever accepted. Counting is in characters, so `é` and emoji each count as one.
- **Whitespace is trimmed,** line endings are normalised, and control characters are stripped. Line breaks are only kept in textareas.
- **One message per field.** If a field breaks several rules, the visitor sees the first problem.
- **Mistakes in rules fail early.** `'rules' => 'reqiured'` throws an error at registration listing the allowed rule names.
- **`in:` values cannot contain commas.** If a choice needs a comma, use a `select` or `radio` field and list the options instead.

### A bigger example

```php
FormRegistry::register([
    'slug'   => 'quote-request',
    'name'   => 'Quote request',
    'fields' => [
        ['name' => 'company',  'label' => 'Company',           'rules' => 'required|max:120'],
        ['name' => 'contact',  'label' => 'Your name',         'rules' => 'required|alpha|max:80'],
        ['name' => 'email',    'label' => 'Work email',        'type' => 'email', 'rules' => 'required'],
        ['name' => 'phone',    'label' => 'Phone',             'type' => 'tel', 'help' => 'Optional'],
        ['name' => 'team',     'label' => 'Team size',         'type' => 'radio',
            'options' => [['value' => '1', 'label' => '1-10'], ['value' => '2', 'label' => '11-50'], ['value' => '3', 'label' => '51+']],
            'rules' => 'required'],
        ['name' => 'budget',   'label' => 'Budget (USD)',      'type' => 'number', 'rules' => 'min:500|max:1000000'],
        ['name' => 'website',  'label' => 'Current website',   'type' => 'url', 'placeholder' => 'https://'],
        ['name' => 'details',  'label' => 'Project details',   'type' => 'textarea', 'rules' => 'required|min:20|max:3000'],
        ['name' => 'terms',    'label' => 'I agree to be contacted about this request', 'type' => 'checkbox', 'rules' => 'required'],
    ],
    'settings' => [
        'notify_email'    => 'sales@example.com',
        'submit_label'    => 'Request a quote',
        'success_message' => 'Thank you! We will reply within one working day.',
        'redirect'        => '/thanks',
    ],
]);
```

### Where forms come from

`FormRegistry::register()` is the Core way. Other packages (the Forms Pro visual builder, for example) can supply forms from elsewhere by implementing `Arout\Forms\Form\FormProviderInterface` and calling `FormRegistry::addProvider()`. A form registered directly with `register()` always wins over one from a provider with the same slug.

---

## Showing a form on a page

```twig
{{ rhapsody_form('contact') }}
```

Place it anywhere in a template. You can put several different forms on one page. (Read the [captcha limitation](#known-limitations) first if you use reCAPTCHA.)

The tag draws the whole form: the fields, the submit button, the hidden spam traps, the security token, and the reCAPTCHA widget if your site has one configured.

### What the visitor experiences

- **A normal submit** sends the visitor back to the page they were on (or to the form's `redirect` page) with the success message shown, or with each problem field highlighted and everything they typed kept.
- **A JavaScript submit** (a `fetch()` call that sends `Accept: application/json`) gets a JSON answer instead of a redirect:
  ```json
  { "ok": true,  "message": "Thanks! Your message has been sent." }
  { "ok": false, "message": "Please fix the highlighted fields.", "errors": { "email": ["Email must be a valid email address."] } }
  ```
  The HTTP status is `200` for success, `422` for problems the visitor can fix, `429` when they are being rate limited, and `500` if the server could not save the submission.

### Styling

The form is plain, semantic HTML with predictable class names, so any theme can style it:

| Class | Element |
|-------|---------|
| `rforms` | the `<form>` |
| `rforms__field` | wrapper around one label and input |
| `rforms__field--error` | added to a field wrapper that failed validation |
| `rforms__label` | a field's label |
| `rforms__input` | text-like inputs, selects and textareas |
| `rforms__choice` | one radio button or checkbox with its label |
| `rforms__help` | the small help line |
| `rforms__error` | a field's error message |
| `rforms__alert`, `rforms__alert--success`, `rforms__alert--error` | the message banner above the form |
| `rforms__submit` | the submit button |

By default the module also includes a small stylesheet that adapts to light and dark backgrounds and reads the same optional CSS variables as the other Rhapsody modules (`--rhapsody-primary`, `--rhapsody-primary-contrast`, `--rhapsody-danger`, `--rhapsody-radius`, `--rhapsody-gap`). Set those in your theme's `:root` and the form follows.

If your theme styles the `rforms__*` classes itself, switch the built-in styles off with the `include_css` [setting](#settings).

---

## What happens when someone submits

Every submission goes through the same steps, **cheapest check first**:

| # | Step | If it fails |
|---|------|-------------|
| 1 | **Honeypot.** A hidden box real people never see. | A bot filled it in, so the visitor is shown the normal success message, but **nothing is saved, emailed or announced**. The bot learns nothing. |
| 2 | **Time-trap.** Every form carries a signed timestamp of when it was drawn. | *Tampered or missing:* "Something went wrong. Please reload the page and try again." *Sent too quickly:* "That was quick! Please check your details and send it again." *Older than 24 hours:* "This form has expired. Please check your details and send it again." |
| 3 | **Validation** of every field. | "Please fix the highlighted fields." plus a message per field. Everything typed is kept. |
| 4 | **Rate limit** per visitor, per form. | "You've sent several messages recently. Please try again a little later." |
| 5 | **reCAPTCHA**, when configured and not turned off for the form. | "Please verify that you are not a robot." |
| 6 | **`FormSubmitting` event.** Other code may veto. | The reason the listener gave, or "Your submission could not be accepted." |
| 7 | **Save** to the database. | "We couldn't save your message. Please try again." |
| 8 | **`FormSubmitted` event.** | Failures here are logged and never shown to the visitor. |
| 9 | **Email notification.** | Failures are logged and recorded on the submission. The visitor still sees success. |

Two design choices worth understanding:

- **The submission is saved before anything that can fail slowly.** If the mail server is down or another module's listener crashes, the message is still safely in your inbox.
- **Validation runs before the captcha.** A typo in an email address should not cost the visitor a captcha solve.

---

## Spam protection

You get four layers with **no configuration**, and a fifth if you set up reCAPTCHA.

1. **Honeypot field.** Catches the many bots that fill in every input they find.
2. **Signed time-trap.** Bots usually submit within milliseconds. The timestamp is signed with your site's private secret, so it cannot be forged or reused on another form. The minimum wait defaults to 2 seconds (`min_submit_seconds`).
3. **Rate limit.** By default one visitor can save at most **5 submissions per form per hour**. It is counted from saved submissions using a hash of the visitor's IP address (never the address itself). Change it per form with `throttle_per_hour`.
4. **Veto event.** Add your own filter (keywords, link counts, an Akismet check) by listening to `FormSubmitting`. See [Events](#events-reacting-to-submissions-from-your-own-code).
5. **reCAPTCHA (optional).** Add your Google reCAPTCHA v2 keys to `.env`:
   ```ini
   RECAPTCHA_SITE_KEY=your-site-key
   RECAPTCHA_SECRET_KEY=your-secret-key
   ```
   Forms then show the "I'm not a robot" box automatically. With **both** keys missing, forms skip the captcha instead of rejecting everyone. To skip it for one form even when keys exist, set `'captcha' => 'off'` in that form's settings.

### If your site is behind a proxy or CDN

This is the one thing to check before you go live.

The module normally identifies a visitor by the connection's address. Behind a proxy or CDN (Cloudflare, a load balancer), **every visitor appears to come from the proxy's address**, so the rate limit would apply to all your visitors combined.

Fix it by naming the header your proxy sets in the `trusted_proxy_header` [setting](#settings):

| Behind | Set `trusted_proxy_header` to |
|--------|-------------------------------|
| Cloudflare | `CF-Connecting-IP` |
| A typical load balancer or reverse proxy | `X-Forwarded-For` |
| Nothing (a direct connection) | leave it **empty** |

> **Only set this if you really are behind a proxy that you control.** Anyone can send a fake `X-Forwarded-For` header. If you name a header your server does not overwrite, a bot can pretend to be a different visitor on every request and walk straight past the rate limit.

---

## Email notifications

When a submission is saved, the module emails the recipient set in the form's `notify_email` (or the module-wide `notify_email` setting if the form has none).

- **Subject:** `New submission: <form name>`
- **Body:** a table of every field and the visitor's answer (checkboxes show Yes or No; hidden fields are left out), plus the submission number. A plain-text version is included. Everything the visitor typed is HTML-escaped.
- **From:** always your site's own address from its mail configuration. The module cannot change it.
- **Reply-To:** the visitor's address, taken from the first `email`-type field on the form (when it is a valid address). Hit Reply to answer them directly.

### Mail is best-effort

The submission is saved first, then the email is attempted. The inbox records the outcome for each submission:

| Status | Meaning |
|--------|---------|
| `sent` | The mail server accepted the message. |
| `failed` | Something went wrong (server down, bad credentials). The reason is in the PHP error log. The submission itself is safe. |
| `skipped` | No email was attempted: either the site has no mail configured (`MAIL_HOST` is empty) or there is no recipient for this form. |

To set up mail, fill in the `MAIL_*` settings in your `.env`. The module only uses what the framework is already configured with.

### A safety limit

A module may send at most 20 emails while handling a single request. A form only ever sends one, so you will never notice it.

---

## The inbox

The inbox is the admin area for everything people have sent you.

| Page | Address | What it does |
|------|---------|--------------|
| Inbox | `/forms/inbox` | Lists submissions, newest first, 25 per page. Filter by form or by new/read. |
| One submission | `/forms/inbox/{id}` | Shows every answer, the time received (UTC), and the email status. Opening a submission marks it as read. |
| Mark read/unread, delete | buttons on the pages above | Submissions are removed for good. |
| CSV export | `/forms/export` | Downloads submissions as a spreadsheet file. Add `?form=contact` to export one form only. |

All of these pages are guarded by the admin gate. If you have a subdirectory install (for example `/marketplace/`), the addresses start with your base path.

### Access: who can open the inbox

Every inbox page uses the framework's built-in `admin` middleware. It decides who is an admin in this order:

1. **Your application's own `admin` middleware**, if you registered one in your middleware map. It is used instead of the built-in one.
2. **A class you write that implements `AdminGateInterface`** and bind in the container. Use this when "admin" lives in your database:

   ```php
   <?php
   namespace App\Gates;

   use Rhapsody\Core\Contracts\AdminGateInterface;
   use Rhapsody\Core\Database;

   // Example rule: admins are users whose is_admin column is 1.
   class IsAdminColumnGate implements AdminGateInterface
   {
       public function __construct(private readonly Database $db) {}

       public function isAdmin(string $userId): bool
       {
           $stmt = $this->db->getConnection()->prepare(
               'SELECT is_admin FROM users WHERE user_id = :id LIMIT 1'
           );
           $stmt->execute(['id' => $userId]);

           return (bool) $stmt->fetchColumn();
       }
   }
   ```
3. **`ADMIN_USER_IDS` in `.env`**, a comma-separated list of user ids, used when nothing else is configured. No code needed.

Visitors who are not logged in are redirected to `/login`. Logged-in users who are not admins get a 403. **If nothing is configured, nobody is an admin**, so a fresh install is locked, never open.

### CSV export and spreadsheets

Columns are: submission id, form, received (UTC), status, then one column per field. The export is streamed in chunks, so large tables do not exhaust memory. Cells that begin with `=`, `+`, `-` or `@` are prefixed with a single quote, so a malicious visitor cannot smuggle a spreadsheet formula into your Excel file.

---

## Settings

Module-wide settings live in a small JSON file:

```
storage/modules/arout-forms/settings.json
```

(The folder is named after the module's slug.) Any setting you leave out uses its default.

| Setting | Default | What it does |
|---------|---------|--------------|
| `notify_email` | *(empty)* | Default recipient for notifications when a form does not set its own. Empty means no email is sent. |
| `min_submit_seconds` | `2` | How long a form must have been open before a submission is accepted. Bots submit instantly; people do not. |
| `trusted_proxy_header` | *(empty)* | The header your proxy or CDN uses to pass the visitor's address. **Leave empty unless you are behind a proxy you control.** See [If your site is behind a proxy or CDN](#if-your-site-is-behind-a-proxy-or-cdn). |
| `retention_days` | `0` | Delete submissions older than this many days. `0` keeps everything until you delete it. |
| `include_css` | `true` | Include the module's default form styles. Set `false` if your theme styles the `rforms__*` classes itself. |

Example:

```json
{
    "notify_email": "owner@example.com",
    "min_submit_seconds": 3,
    "trusted_proxy_header": "CF-Connecting-IP",
    "retention_days": 365,
    "include_css": true
}
```

**The signing secret.** On first activation the module generates a long random value and stores it in the same file as `signing_secret`. It signs the time-trap tokens and hashes visitor IP addresses. Don't share the file's contents, and don't commit it to version control. If you delete the entry, a new one is generated; the only effect is that forms already open in someone's browser will ask them to reload once, and the rate-limit history starts fresh.

**Retention without a scheduler.** There is no cron job. When `retention_days` is set, a small batch of expired submissions is cleared now and then as a side effect of new submissions (about one in every fifty). A very quiet site may keep expired items a little longer than the setting says.

---

## Events: reacting to submissions from your own code

The module announces two events. Use them to build spam filters, newsletters, CRM syncs and webhooks without touching the module itself. Both live in the `Arout\Forms\Events` namespace and are part of the module's public API, so their properties will not change within a major version.

### `FormSubmitting` (before saving, can be vetoed)

Fired after validation and the spam checks, **before** the submission is saved.

| Property or method | Meaning |
|--------------------|---------|
| `$event->formSlug` | Which form, for example `contact`. |
| `$event->data` | The cleaned values, keyed by field name. Checkboxes are `true`/`false`. |
| `$event->meta['ip']` | The visitor's IP address, so filters such as Akismet can work. It is **not stored**. |
| `$event->meta['user_agent']` | The visitor's browser string. |
| `$event->reject('Reason')` | Refuse the submission. The reason is shown to the visitor, so keep it polite. |
| `$event->isRejected()`, `$event->reason()` | Read back the outcome. |

If a listener rejects, nothing is saved or emailed. All other listeners still run. A listener that throws an error counts as "no objection" and the error is logged, so a broken filter can never block real visitors.

### `FormSubmitted` (after saving, for follow-up work)

Fired after the submission is saved. The data is already safe, so a failing listener cannot lose it.

| Property | Meaning |
|----------|---------|
| `$event->submissionId` | The new row's id. |
| `$event->formSlug`, `$event->formName` | Which form. |
| `$event->data` | The saved values, keyed by field name. |

### Listening from another module

Two things are needed: permission in your module's `module.json`, and a listener registered in your provider.

**1. `module.json`.** List the event with its **full class name**. Short names do not work.

```json
"permissions": {
    "events.listen": {
        "listen": [
            "Arout\\Forms\\Events\\FormSubmitting",
            "Arout\\Forms\\Events\\FormSubmitted"
        ]
    }
}
```

**2. Your provider's `boot()`:**

```php
use Arout\Forms\Events\FormSubmitted;
use Arout\Forms\Events\FormSubmitting;

// Inside boot(), where you have the ModuleContext as $context:

// A tiny spam filter: no links in the message.
$context->events()->listen(FormSubmitting::class, function (FormSubmitting $event) {
    if ($event->formSlug === 'contact'
        && preg_match('#https?://#i', (string) ($event->data['message'] ?? ''))) {
        $event->reject('Please remove links from your message.');
    }
});

// Follow-up work: add newsletter ticks to a mailing list.
$context->events()->listen(FormSubmitted::class, function (FormSubmitted $event) {
    if (($event->data['newsletter'] ?? false) === true) {
        // call your mailing-list service here with $event->data['email']
    }
});
```

A few rules of the road:

- **Listeners run inside the visitor's request.** A slow listener (an outbound web request, for instance) makes the visitor wait. Keep them quick and set short timeouts.
- **Do your own checks.** Treat `$event->data` like any user input before putting it anywhere sensitive.
- **Use the right event.** Veto in `FormSubmitting`. Do follow-up work in `FormSubmitted`, which only fires for submissions that were actually saved.

---

## Where your data lives (and privacy)

### The table

The module creates one table, `mod_arout_forms_submissions`. The prefix is derived from the package name; it is the only table the module may write to.

| Column | Contents |
|--------|----------|
| `id` | Submission number. |
| `form_slug` | Which form. |
| `data` | The answers as JSON (UTF-8, emoji supported). |
| `status` | `new` or `read`. |
| `notify_status` | `pending`, `sent`, `failed` or `skipped`. |
| `ip_hash` | A 64-character one-way hash of the visitor's IP address. Used only for the rate limit. |
| `user_agent` | The visitor's browser string, trimmed to 255 characters. |
| `created_at` | When it arrived, **in UTC**. |

### What is and is not stored

- **IP addresses are never stored.** Only an irreversible hash, made with your site's private secret, so it cannot be turned back into an address or matched against another site's data.
- The submission's answers and the browser string **are** stored.
- The IP address and browser string are shown briefly to `FormSubmitting` listeners (so spam filters can work) but are not saved by the module.

### Privacy checklist for client sites

- Tell visitors what you do with their messages, in your privacy policy.
- Set `retention_days` if you do not need to keep submissions forever.
- To honour a deletion request, find the submission in the inbox and delete it.

### Uninstalling keeps your data

Deactivating the module **does not delete submissions**. They are your site's data, and an accidental uninstall should never wipe them. See [Uninstalling](#uninstalling) for how to remove them deliberately.

---

## Security notes

For the developers and reviewers who want to know what the module does on your behalf.

- **CSRF protection** comes from the framework. Every form carries the framework's token, and the submit route is checked by the same middleware as the rest of your site.
- **Output is escaped.** The form, the inbox and notification emails escape everything a visitor typed.
- **`url` fields accept only `http`/`https`.** A stored `javascript:` link shown in an admin screen would be a script-injection route.
- **Redirects are local-only.** A form's `redirect` setting, and the "return to the page you were on" path, must be paths on your own site. Anything that looks like `//elsewhere.com` or contains a backslash is refused, so the form cannot become an open redirect.
- **Email headers cannot be injected.** Recipient and Reply-To addresses must be single valid addresses (line breaks are rejected) and subject lines have line breaks removed.
- **Spreadsheet formula injection is blocked** in CSV exports (see above).
- **The inbox fails closed.** If the admin middleware is not available, the inbox routes are not registered at all.
- **Fake proxy headers are ignored** unless you explicitly trust a header.
- **Least privilege.** The module declares exactly the permissions it needs: its own routes under `/forms`, its own table, sending mail, dispatching its two events, one Twig function and its settings file.

---

## Troubleshooting

**The form does not appear.**
Check that the slug in `rhapsody_form('...')` matches a registered form exactly, and that `FormRegistry::register()` runs on every request before the page renders (for example in your application's `bootstrap.php`). A mistake in a definition throws an error with a message naming the form and field.

**"Something went wrong. Please reload the page and try again."**
The form's time-trap token was missing or did not verify. Usual causes: the page is served from a **full-page cache** (the tokens are per visitor, so don't cache pages that contain forms), the signing secret was changed or deleted since the page loaded, or a script is posting to the form directly.

**"This form has expired."**
The page had been open for more than 24 hours. Sending again works. If this happens to everyone, the page is being cached; exclude it from your cache.

**"That was quick!" for a real person.**
They submitted in less than `min_submit_seconds` (default 2), usually via browser autofill. Sending again works. Lower the setting to `1` if it troubles your audience.

**Everyone suddenly gets "You've sent several messages recently".**
You are probably behind a proxy or CDN, so every visitor looks like the same address. Set `trusted_proxy_header`. See [If your site is behind a proxy or CDN](#if-your-site-is-behind-a-proxy-or-cdn).

**Submissions save but no email arrives.**
Open the submission in the inbox and read its email status:
- `skipped`: no `MAIL_HOST` configured, or no recipient. Set `notify_email` and check your `.env` mail settings.
- `failed`: the mail server refused it. Look for a line starting `Forms: notification for submission` in the PHP error log.
- `sent` but still missing: check spam folders. Make sure your sending domain has SPF and DKIM records; the From address is always your site's own.

**The captcha box does not appear.**
Both `RECAPTCHA_SITE_KEY` and `RECAPTCHA_SECRET_KEY` must be set in `.env`. With either missing the module treats reCAPTCHA as not configured.

**"We couldn't verify the captcha."**
Your server could not reach Google within 5 seconds. Check your firewall and that PHP's `allow_url_fopen` setting is on; the framework's captcha check relies on it.

**403 when opening the inbox.**
You are logged in but not an admin. See [Access: who can open the inbox](#access-who-can-open-the-inbox).

**An error that mentions a middleware "which is not registered".**
Your core is not new enough to include the built-in `admin` middleware, or your application overrides the middleware map without an `admin` entry. Update core, or add an `admin` entry. (The module's startup check normally prevents the inbox routes from being registered in this situation.)

**Inbox pages are not found.**
The module may not be activated. Run `php rhapsody list` to see the module commands your version provides, then `php rhapsody module:install forms`. Check the PHP error log for a line saying the inbox was not registered because the admin middleware is missing.

---

## Known limitations

These are deliberate boundaries of the Core tier, not bugs.

- **Forms are defined in code.** There is no visual form builder in Core (see [Core and Pro](#core-and-pro)).
- **One reCAPTCHA form per page.** Each form that shows the captcha loads Google's script tag, so two captcha-protected forms on one page can conflict. Use `'captcha' => 'off'` on all but one, or use one form per page.
- **The rate limit counts saved submissions only.** Visitors who keep sending invalid forms are not counted. Site-wide flood protection is the job of the framework's DDoS middleware.
- **Single tick boxes only,** not groups of checkboxes, and no multi-select lists.
- **No file uploads, multi-step forms or conditional fields** in Core.
- **Retention clean-up is opportunistic** (see [Settings](#settings)): it depends on new submissions arriving.
- **Listeners run synchronously,** so slow listeners slow the visitor's request.
- **Times are stored and shown in UTC.**
- **A failing veto listener fails open:** if a spam filter crashes, the submission goes through rather than blocking real visitors.

---

## Core and Pro

| | Core (`arout/forms`) | Pro (planned) |
|---|:---:|:---:|
| Forms defined in PHP, `rhapsody_form()` tag | yes | yes |
| Ten field types, validation rules | yes | yes |
| Honeypot, time-trap, rate limit, reCAPTCHA | yes | yes |
| Admin inbox, CSV export | yes | yes |
| Email notifications with Reply-To | yes | yes |
| `FormSubmitting` and `FormSubmitted` events | yes | yes |
| Visual drag-and-drop form builder | | yes |
| File uploads | | yes |
| Auto-reply email to the visitor | | yes |
| Conditional fields and multi-step forms | | yes |
| Webhooks and integrations | | yes |
| Submission notes and statuses | | yes |
| Retention policies per form | | yes |

Pro will be a separate package that depends on Core and builds on the events and `FormProviderInterface` described above.

---

## Uninstalling

1. **Deactivate the module** with the framework's module command, the counterpart of `module:install` (run `php rhapsody list` to see the exact name in your version, for example `module:uninstall`):
   ```bash
   php rhapsody module:uninstall forms
   ```
   This stops the module from running. **Your submissions stay in the database.**
2. **Remove the package** if you no longer want the code:
   ```bash
   composer remove arout/forms
   ```
3. **Remove the `rhapsody_form()` tags** from your templates and the `FormRegistry::register()` calls from your `bootstrap.php`.
4. **Delete the data, if you want it gone for good.** This cannot be undone, so export a CSV first. In your database tool run:
   ```sql
   DROP TABLE mod_arout_forms_submissions;
   ```
   and delete `storage/modules/arout-forms/`.

---

## Changelog

**1.0.0** &mdash; Initial release: code-defined forms, ten field types, validation, spam protection (honeypot, signed time-trap, rate limit, optional reCAPTCHA), admin inbox with CSV export, email notifications, and the `FormSubmitting` and `FormSubmitted` events.

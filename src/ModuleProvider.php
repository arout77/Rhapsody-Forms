<?php

namespace Arout\Forms;

use Arout\Forms\Form\FormRegistry;
use Arout\Forms\Http\InboxController;
use Arout\Forms\Http\SubmitController;
use Arout\Forms\Mail\NotificationMailer;
use Arout\Forms\Storage\Schema;
use Arout\Forms\Storage\SubmissionRepository;
use Arout\Forms\Submission\SubmissionHandler;
use Arout\Forms\Support\CaptchaBridge;
use Arout\Forms\Support\ModuleOptions;
use Arout\Forms\Support\SigningSecret;
use Arout\Forms\Twig\FormsExtension;
use Arout\Forms\Validation\FieldValidator;
use Rhapsody\Core\Event;
use Rhapsody\Core\Modules\Contracts\ModuleServiceProviderInterface;
use Rhapsody\Core\Modules\ModuleContext;
use Rhapsody\Core\Response;

class ModuleProvider implements ModuleServiceProviderInterface
{
    /**
     * Runs on every request, so it only wires things together: the database,
     * mail and event facades are created lazily, the first time a form is
     * submitted or the inbox is opened.
     */
    public function boot(ModuleContext $context): void
    {
        $settings = $context->settings();
        $baseUrl  = rtrim((string) getenv('APP_BASE_URL'), '/');

        $secretMemo = null;
        $secret     = function () use (&$secretMemo, $settings): ?string {
            return $secretMemo ??= SigningSecret::ensure($settings);
        };
        $options = static fn (): array => ModuleOptions::read($settings);

        // {{ rhapsody_form('slug') }}
        $context->twig()->addExtension(new FormsExtension($context->slug(), $secret, $options, $baseUrl));

        $prefix = $context->manifest()->tablePrefix();

        $handlerFactory = function () use ($context, $secret, $options, $prefix): ?SubmissionHandler {
            $key = $secret();
            if ($key === null) {
                return null;
            }

            $opts   = $options();
            $events = $context->events();

            return new SubmissionHandler(
                new SubmissionRepository($context->database(), $prefix),
                new FieldValidator(),
                new NotificationMailer($context->mail()),
                $key,
                static fn (Event $event): Event => $events->dispatch($event),
                static fn (string $token, ?string $ip): bool => CaptchaBridge::verify($token, $ip),
                CaptchaBridge::isEnabled(),
                [
                    'min_submit_seconds'   => $opts['min_submit_seconds'],
                    'trusted_proxy_header' => $opts['trusted_proxy_header'],
                    'retention_days'       => $opts['retention_days'],
                    'default_notify_email' => $opts['notify_email'],
                ],
            );
        };

        $routes = $context->routes();
        $routes->post('/submit/{slug}', [new SubmitController($handlerFactory, $baseUrl), 'submit']);

        // The inbox is guarded by core's `admin` middleware. On a core that doesn't have it, a route that names
        // an unknown middleware alias could run UNPROTECTED, so refuse to register the inbox at all.
        if (! class_exists(\Rhapsody\Core\Middleware\AdminMiddleware::class)) {
            error_log('Forms: the inbox was NOT registered because this Rhapsody core has no built-in "admin" middleware. Update Rhapsody to v2.3.0 or newer.');

            return;
        }

        $twig  = $context->twig();
        $inbox = new InboxController(
            static fn (): SubmissionRepository => new SubmissionRepository($context->database(), $prefix),
            static function (string $template, array $data) use ($twig): Response {
                // The theme's main layout prints meta.title / meta.description.
                $data['meta'] ??= ['title' => $data['title'] ?? null, 'description' => $data['description'] ?? null];

                return $twig->render($template, $data);
            },
            $baseUrl,
        );

        $routes->get('/inbox', [$inbox, 'index'])->middleware('admin');
        $routes->get('/inbox/{id}', [$inbox, 'show'])->middleware('admin');
        $routes->post('/inbox/{id}/read', [$inbox, 'markRead'])->middleware('admin');
        $routes->post('/inbox/{id}/unread', [$inbox, 'markUnread'])->middleware('admin');
        $routes->post('/inbox/{id}/delete', [$inbox, 'delete'])->middleware('admin');
        $routes->get('/export', [$inbox, 'export'])->middleware('admin');
    }

    /**
     * migrate() takes one statement per call. There is just one table.
     * Also creates the signing secret now, so the first visitor never has to.
     */
    public function install(ModuleContext $context): void
    {
        $context->database()->migrate(Schema::createSubmissions($context->manifest()->tablePrefix()));

        SigningSecret::ensure($context->settings());
    }

    /**
     * Submissions belong to the site owner, so they are kept by default. A
     * deliberate "delete_data_on_uninstall": true in the module settings
     * (set it BEFORE uninstalling) drops the table as well.
     */
    public function uninstall(ModuleContext $context): void
    {
        if (ModuleOptions::read($context->settings())['delete_data_on_uninstall']) {
            $context->database()->migrate(Schema::dropSubmissions($context->manifest()->tablePrefix()));
        }
    }
}

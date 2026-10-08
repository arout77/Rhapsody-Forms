<?php

namespace Arout\Forms\Http;

use Arout\Forms\Form\FormRegistry;
use Arout\Forms\Submission\SubmissionHandler;
use Arout\Forms\Submission\SubmissionResult;
use Arout\Forms\Support\FlashState;
use Arout\Forms\Support\LocalPath;
use Closure;
use Rhapsody\Core\RedirectResponse;
use Rhapsody\Core\Response;

/**
 * POST /forms/submit/{slug}: receives a form post, runs the pipeline, and
 * answers either with JSON (for fetch()/XHR) or with a redirect back to the
 * page the form is on.
 */
final class SubmitController
{
    /**
     * @param Closure(): ?SubmissionHandler $handlerFactory null when the module can't work (no signing secret)
     * @param string                        $baseUrl        APP_BASE_URL (path or full URL), no trailing slash
     */
    public function __construct(
        private readonly Closure $handlerFactory,
        private readonly string $baseUrl,
    ) {
    }

    /** @param object $request the framework Request (getBody(), getServerParams()) */
    public function submit(object $request, string $slug): Response
    {
        $server = $request->getServerParams();
        $server = is_array($server) ? $server : [];
        $input  = $request->getBody();
        $input  = is_array($input) ? $input : [];
        $json   = $this->wantsJson($server);
        $form   = FormRegistry::find($slug);

        if ($form === null) {
            return $this->respond($json, $slug, null, new SubmissionResult(SubmissionResult::NOT_FOUND, 'This form does not exist.'), $input);
        }

        $handler = ($this->handlerFactory)();
        if ($handler === null) {
            return $this->respond($json, $slug, $form->settings['redirect'], new SubmissionResult(SubmissionResult::ERROR, "We couldn't save your message. Please try again."), $input);
        }

        $result = $handler->handle($form, $input, $server);

        return $this->respond($json, $slug, $form->settings['redirect'], $result, $input);
    }

    /** @param array<string, mixed> $input */
    private function respond(bool $json, string $slug, ?string $redirectSetting, SubmissionResult $result, array $input): Response
    {
        $redirectTo = ($result->isOk() && $redirectSetting !== null) ? $this->baseUrl . $redirectSetting : null;

        if ($json) {
            $body = ['ok' => $result->isOk(), 'message' => $result->message];
            if ($result->errors !== []) {
                $body['errors'] = $result->errors;
            }
            if ($redirectTo !== null) {
                $body['redirect'] = $redirectTo;
            }

            $response = new Response();
            $response->setStatusCode($result->httpStatus());
            $response->setHeader('Content-Type', 'application/json');
            $response->setContent((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $response;
        }

        FlashState::put($slug, $result);

        if ($redirectTo !== null) {
            return new RedirectResponse($redirectTo);
        }

        // Back to the page the form is on, scrolled to the form so the message is seen.
        $returnRaw = $input['_return'] ?? null;
        $return    = (is_string($returnRaw) && LocalPath::isLocal($returnRaw)) ? $returnRaw : $this->baseUrl . '/';
        $return    = explode('#', $return, 2)[0];

        return new RedirectResponse($return . '#rforms-' . $slug);
    }

    /** @param array<string, mixed> $server */
    private function wantsJson(array $server): bool
    {
        $accept      = strtolower((string) ($server['HTTP_ACCEPT'] ?? ''));
        $contentType = strtolower((string) ($server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? ''));
        $requestedBy = strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? ''));

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || $requestedBy === 'xmlhttprequest';
    }
}

<?php

namespace Arout\Forms\Http;

use Arout\Forms\Export\CsvExporter;
use Arout\Forms\Form\FormRegistry;
use Arout\Forms\Storage\SubmissionRepository;
use Closure;
use Rhapsody\Core\Exceptions\HttpException;
use Rhapsody\Core\RedirectResponse;
use Rhapsody\Core\Response;

/**
 * The admin inbox. Every route to these methods is registered with the
 * `admin` middleware, so the actions here assume an admin is asking.
 */
final class InboxController
{
    public const PER_PAGE = 25;

    /**
     * @param Closure(): SubmissionRepository                 $repository
     * @param Closure(string, array<string, mixed>): Response $render     renders a module view
     * @param string                                          $baseUrl    APP_BASE_URL (path or full URL), no trailing slash
     */
    public function __construct(
        private readonly Closure $repository,
        private readonly Closure $render,
        private readonly string $baseUrl,
        private readonly string $prefix = '/forms',
    ) {
    }

    public function index(object $request): Response
    {
        $repo   = ($this->repository)();
        $form   = $this->slug($request->input('form'));
        $status = $this->status($request->input('status'));
        $page   = max(1, (int) $request->input('page', 1));

        $result = $repo->paginate($form, $status, $page, self::PER_PAGE);
        $pages  = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        if ($page > $pages) {
            $page   = $pages;
            $result = $repo->paginate($form, $status, $page, self::PER_PAGE);
        }

        $summary    = $repo->formsSummary();
        $forms      = [];
        $totalAll   = 0;
        $unreadAll  = 0;
        foreach ($summary as $s) {
            $definition = FormRegistry::find($s['form_slug']);
            $forms[]    = ['slug' => $s['form_slug'], 'name' => $definition?->name ?? $s['form_slug'], 'total' => $s['total'], 'unread' => $s['unread']];
            $totalAll  += $s['total'];
            $unreadAll += $s['unread'];
        }

        $rows = [];
        foreach ($result['rows'] as $row) {
            $definition = FormRegistry::find((string) $row['form_slug']);
            $rows[]     = [
                'id'            => $row['id'],
                'form_name'     => $definition?->name ?? (string) $row['form_slug'],
                'summary'       => SubmissionPresenter::summary($row, $definition),
                'status'        => (string) $row['status'],
                'notify_status' => (string) $row['notify_status'],
                'created_at'    => (string) $row['created_at'],
                'url'           => $this->url('/inbox/' . $row['id']),
            ];
        }

        $filters = array_filter(['form' => $form, 'status' => $status], static fn ($v) => $v !== null);

        return ($this->render)('admin/inbox.twig', [
            'title'       => 'Form submissions',
            'description' => 'Messages sent through your site\'s forms.',
            'base'        => $this->baseUrl,
            'prefix'      => $this->prefix,
            'rows'        => $rows,
            'forms'       => $forms,
            'total_all'   => $totalAll,
            'unread_all'  => $unreadAll,
            'total'       => $result['total'],
            'filter_form' => $form,
            'filter_status' => $status,
            'page'        => $page,
            'pages'       => $pages,
            'prev_url'    => $page > 1 ? $this->url('/inbox', $filters + ['page' => $page - 1]) : null,
            'next_url'    => $page < $pages ? $this->url('/inbox', $filters + ['page' => $page + 1]) : null,
            'export_url'  => $this->url('/export', $form !== null ? ['form' => $form] : []),
            'max_export'  => CsvExporter::MAX_ROWS,
        ]);
    }

    public function show(object $request, string $id): Response
    {
        $repo       = ($this->repository)();
        $submission = $this->find($repo, $id);

        if ($submission['status'] === SubmissionRepository::STATUS_NEW) {
            $repo->setStatus($submission['id'], SubmissionRepository::STATUS_READ);
            $submission['status'] = SubmissionRepository::STATUS_READ;
        }

        $definition = FormRegistry::find((string) $submission['form_slug']);

        return ($this->render)('admin/show.twig', [
            'title'       => 'Submission #' . $submission['id'],
            'description' => 'A message sent through one of your forms.',
            'base'        => $this->baseUrl,
            'prefix'      => $this->prefix,
            'submission'  => [
                'id'            => $submission['id'],
                'form_name'     => $definition?->name ?? (string) $submission['form_slug'],
                'status'        => (string) $submission['status'],
                'notify_status' => (string) $submission['notify_status'],
                'created_at'    => (string) $submission['created_at'],
            ],
            'rows'        => SubmissionPresenter::rows($submission, $definition),
            'back_url'    => $this->url('/inbox'),
            'actions'     => [
                'unread' => $this->url('/inbox/' . $submission['id'] . '/unread'),
                'delete' => $this->url('/inbox/' . $submission['id'] . '/delete'),
            ],
        ]);
    }

    public function markRead(object $request, string $id): Response
    {
        $repo       = ($this->repository)();
        $submission = $this->find($repo, $id);
        $repo->setStatus($submission['id'], SubmissionRepository::STATUS_READ);

        return $this->backToInbox('Marked as read.');
    }

    public function markUnread(object $request, string $id): Response
    {
        $repo       = ($this->repository)();
        $submission = $this->find($repo, $id);
        $repo->setStatus($submission['id'], SubmissionRepository::STATUS_NEW);

        return $this->backToInbox('Marked as unread.');
    }

    public function delete(object $request, string $id): Response
    {
        $repo       = ($this->repository)();
        $submission = $this->find($repo, $id);
        $repo->delete($submission['id']);

        return $this->backToInbox('Submission deleted.');
    }

    public function export(object $request): Response
    {
        $slug       = $this->slug($request->input('form'));
        $definition = $slug !== null ? FormRegistry::find($slug) : null;
        $repo       = ($this->repository)();

        $built = CsvExporter::build($repo->export($slug), $definition);

        $name = 'forms-' . ($slug ?? 'all') . '-' . gmdate('Y-m-d') . '.csv';

        $response = new Response();
        $response->setHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setContent($built['csv']);

        return $response;
    }

    /** @return array<string, mixed> */
    private function find(SubmissionRepository $repo, string $id): array
    {
        $submission = ctype_digit($id) ? $repo->find((int) $id) : null;

        if ($submission === null) {
            throw new HttpException(404, 'Submission not found');
        }

        return $submission;
    }

    private function backToInbox(string $message): Response
    {
        $_SESSION['flash_success'] = $message;

        return new RedirectResponse($this->url('/inbox'));
    }

    /** @param array<string, mixed> $query */
    private function url(string $path, array $query = []): string
    {
        return $this->baseUrl . $this->prefix . $path . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function slug(mixed $value): ?string
    {
        return (is_string($value) && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $value)) ? $value : null;
    }

    private function status(mixed $value): ?string
    {
        return in_array($value, [SubmissionRepository::STATUS_NEW, SubmissionRepository::STATUS_READ], true) ? $value : null;
    }
}

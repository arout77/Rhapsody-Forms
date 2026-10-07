<?php

namespace Arout\Forms\Storage;

use Rhapsody\Core\Modules\Facades\DatabaseFacade;

/**
 * Every query the module makes, in one place.
 *
 * Written against DatabaseFacade's limits: select() only does equality
 * filters, query() is SELECT-only, delete() takes equality only. So lists and
 * counts use query(), and range deletes (retention) select ids first and
 * delete them one by one. LIMIT/OFFSET are cast to int and written into the
 * SQL because PDO would otherwise bind them as quoted strings, which MySQL
 * rejects.
 */
final class SubmissionRepository
{
    public const STATUS_NEW  = 'new';
    public const STATUS_READ = 'read';

    private string $table;

    public function __construct(private readonly DatabaseFacade $db, string $tablePrefix)
    {
        $this->table = Schema::table($tablePrefix);
    }

    /**
     * @param array<string, string|bool> $data
     * @return int the new submission's id
     */
    public function insert(string $formSlug, array $data, ?string $ipHash, string $userAgent, int $now): int
    {
        return $this->db->insert($this->table, [
            'form_slug'  => $formSlug,
            'data'       => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'status'     => self::STATUS_NEW,
            'ip_hash'    => $ipHash,
            'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
    }

    public function setNotifyStatus(int $id, string $status): void
    {
        $this->db->update($this->table, ['notify_status' => $status], ['id' => $id]);
    }

    /** Submissions from this visitor (by IP hash) to this form since $sinceTs: the throttle's input. */
    public function countRecentByIp(string $formSlug, string $ipHash, int $sinceTs): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) AS c FROM ' . $this->table . ' WHERE form_slug = :slug AND ip_hash = :ip AND created_at >= :since',
            ['slug' => $formSlug, 'ip' => $ipHash, 'since' => gmdate('Y-m-d H:i:s', $sinceTs)]
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $rows = $this->db->select($this->table, ['id' => $id]);

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(?string $formSlug, ?string $status, int $page, int $perPage = 25): array
    {
        [$whereSql, $params] = $this->filters($formSlug, $status);

        $perPage = max(1, min(200, $perPage));
        $offset  = (max(1, $page) - 1) * $perPage;

        $total = (int) ($this->db->query('SELECT COUNT(*) AS c FROM ' . $this->table . $whereSql, $params)[0]['c'] ?? 0);

        $rows = $this->db->query(
            'SELECT id, form_slug, data, status, notify_status, created_at FROM ' . $this->table . $whereSql .
            ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => array_map(fn (array $r) => $this->hydrate($r), $rows), 'total' => $total];
    }

    /** @return array<int, array{form_slug: string, total: int, unread: int}> */
    public function formsSummary(): array
    {
        $rows = $this->db->query(
            'SELECT form_slug, COUNT(*) AS total, SUM(CASE WHEN status = \'new\' THEN 1 ELSE 0 END) AS unread ' .
            'FROM ' . $this->table . ' GROUP BY form_slug ORDER BY form_slug'
        );

        return array_map(
            static fn (array $r) => ['form_slug' => (string) $r['form_slug'], 'total' => (int) $r['total'], 'unread' => (int) $r['unread']],
            $rows
        );
    }

    public function unreadCount(): int
    {
        return (int) ($this->db->query('SELECT COUNT(*) AS c FROM ' . $this->table . ' WHERE status = :s', ['s' => self::STATUS_NEW])[0]['c'] ?? 0);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->update($this->table, ['status' => $status], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete($this->table, ['id' => $id]);
    }

    /**
     * Deletes up to $batch submissions older than $days days. Range deletes
     * aren't available through the facade, hence select-then-delete.
     *
     * @return int how many were deleted
     */
    public function purgeOlderThan(int $days, int $now, int $batch = 200): int
    {
        if ($days < 1) {
            return 0;
        }

        $rows = $this->db->query(
            'SELECT id FROM ' . $this->table . ' WHERE created_at < :cutoff ORDER BY id ASC LIMIT ' . max(1, min(1000, $batch)),
            ['cutoff' => gmdate('Y-m-d H:i:s', $now - $days * 86400)]
        );

        foreach ($rows as $row) {
            $this->db->delete($this->table, ['id' => (int) $row['id']]);
        }

        return count($rows);
    }

    /**
     * Streams every matching submission, oldest first, in chunks (CSV export
     * without loading the whole table into memory).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function export(?string $formSlug): \Generator
    {
        $lastId = 0;

        while (true) {
            $params = ['last' => $lastId];
            $sql    = 'SELECT id, form_slug, data, status, notify_status, created_at FROM ' . $this->table . ' WHERE id > :last';

            if ($formSlug !== null && $formSlug !== '') {
                $sql .= ' AND form_slug = :slug';
                $params['slug'] = $formSlug;
            }

            $rows = $this->db->query($sql . ' ORDER BY id ASC LIMIT 500', $params);

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                yield $this->hydrate($row);
            }
        }
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function filters(?string $formSlug, ?string $status): array
    {
        $where  = [];
        $params = [];

        if ($formSlug !== null && $formSlug !== '') {
            $where[]        = 'form_slug = :slug';
            $params['slug'] = $formSlug;
        }
        if ($status !== null && $status !== '') {
            $where[]          = 'status = :status';
            $params['status'] = $status;
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);

        $row['id']   = (int) $row['id'];
        $row['data'] = is_array($data) ? $data : [];

        return $row;
    }
}

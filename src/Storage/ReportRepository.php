<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

use IndieAuthRocks\Engine\Run;

/**
 * Published implementation reports: a named server or client, and snapshots
 * of test runs against it. Only the newest snapshot is shown publicly.
 */
final class ReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $results test id => result row
     * @return int The implementation id.
     */
    public function publish(int $testerId, string $name, ?string $url, Run $run, array $results): int
    {
        return $this->db->transaction(function (Database $db) use ($testerId, $name, $url, $run, $results): int {
            $now = Database::now();
            $id  = $db->value('SELECT id FROM implementations WHERE tester_id = ? AND kind = ? AND name = ?', [$testerId, $run->kind, $name]);

            if ($id === null) {
                $id = $db->insert('implementations', [
                    'tester_id'  => $testerId,
                    'kind'       => $run->kind,
                    'name'       => $name,
                    'url'        => $url,
                    'target'     => $run->targetUrl,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $db->execute('UPDATE implementations SET url = ?, target = ?, updated_at = ? WHERE id = ?', [$url, $run->targetUrl, $now, $id]);
            }

            $counts   = [];
            $snapshot = [];
            foreach ($results as $testId => $row) {
                $outcome              = (string) $row['outcome'];
                $counts[$outcome]     = ($counts[$outcome] ?? 0) + 1;
                $snapshot[$testId]    = ['outcome' => $outcome, 'summary' => (string) $row['summary']];
            }

            $db->insert('reports', [
                'implementation_id' => $id,
                'run_id'            => $run->id,
                'summary_json'      => json_encode([
                    'run'           => $run->publicId,
                    'target'        => $run->targetUrl,
                    'suite_version' => RunRepository::suiteVersion(),
                    'counts'        => $counts,
                    'results'       => $snapshot,
                ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'published_at'      => $now,
            ]);

            return (int) $id;
        });
    }

    /** @return list<array<string, mixed>> Implementations with their newest report. */
    public function all(): array
    {
        return array_map(self::decode(...), $this->db->all(
            'SELECT i.*, t.me, r.summary_json, r.published_at
             FROM implementations i
             JOIN testers t ON t.id = i.tester_id
             JOIN reports r ON r.id = (SELECT MAX(id) FROM reports WHERE implementation_id = i.id)
             ORDER BY i.kind, i.name',
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->one(
            'SELECT i.*, t.me, r.summary_json, r.published_at
             FROM implementations i
             JOIN testers t ON t.id = i.tester_id
             JOIN reports r ON r.id = (SELECT MAX(id) FROM reports WHERE implementation_id = i.id)
             WHERE i.id = ?',
            [$id],
        );

        return $row === null ? null : self::decode($row);
    }

    public function delete(int $testerId, int $id): bool
    {
        return $this->db->execute('DELETE FROM implementations WHERE id = ? AND tester_id = ?', [$id, $testerId]) > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $row['summary'] = json_decode((string) $row['summary_json'], true) ?: [];
        unset($row['summary_json']);

        return $row;
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

/**
 * What the admin pages report.
 *
 * Read-only, and every figure is one statement written out here rather than
 * composed, so what a page shows can be checked against a single query. Only
 * values are bound; where a limit or a column has to be interpolated it comes
 * from this file, never from a request.
 *
 * Everything counted is what is still stored: prune deletes unpublished runs
 * after RUN_RETENTION_DAYS and checkups after CHECKUP_RETENTION_DAYS.
 */
final class StatsRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, int> Runs by kind and status, testers, reports. */
    public function totals(): array
    {
        $totals = [
            'testers' => (int) $this->db->value('SELECT COUNT(*) FROM testers'),
            'reports' => (int) $this->db->value('SELECT COUNT(*) FROM reports'),
        ];

        foreach ($this->db->all('SELECT kind, status, COUNT(*) AS n FROM runs GROUP BY kind, status') as $row) {
            $kind = (string) $row['kind'];
            $totals[$kind]                            = ($totals[$kind] ?? 0) + (int) $row['n'];
            $totals[$kind . ':' . (string) $row['status']] = (int) $row['n'];
        }

        return $totals;
    }

    /**
     * Runs of one kind per day, every day present so a chart has no gaps.
     *
     * @return array<string, int> 'YYYY-MM-DD' => count
     */
    public function series(string $kind, int $days): array
    {
        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $series[gmdate('Y-m-d', time() - $i * 86_400)] = 0;
        }

        $rows = $this->db->all(
            'SELECT DATE(created_at) AS day, COUNT(*) AS n FROM runs
             WHERE kind = ? AND created_at >= ? GROUP BY day',
            [$kind, Database::now(-$days * 86_400)],
        );

        foreach ($rows as $row) {
            $day = (string) $row['day'];

            if (isset($series[$day])) {
                $series[$day] = (int) $row['n'];
            }
        }

        return $series;
    }

    /**
     * The tests failing most across every run of a suite.
     *
     * @return list<array<string, mixed>>
     */
    public function failingTests(string $kind, int $days, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT r.test_id,
                    SUM(r.outcome = \'fail\') AS fails,
                    SUM(r.outcome = \'warning\') AS warns,
                    COUNT(DISTINCT r.run_id) AS runs
             FROM results r
             JOIN runs ru ON ru.id = r.run_id
             WHERE ru.kind = ? AND ru.created_at >= ? AND r.outcome IN (\'fail\', \'warning\')
             GROUP BY r.test_id
             ORDER BY fails DESC, warns DESC, r.test_id
             LIMIT ' . max(1, $limit),
            [$kind, Database::now(-$days * 86_400)],
        );
    }

    /**
     * The sites tested most. Client runs have no target, so this is for the
     * kinds that do.
     *
     * @return list<array<string, mixed>>
     */
    public function targets(string $kind, int $days, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT target_url, COUNT(*) AS runs, MAX(created_at) AS last_at
             FROM runs
             WHERE kind = ? AND created_at >= ? AND target_url IS NOT NULL
             GROUP BY target_url
             ORDER BY runs DESC, last_at DESC
             LIMIT ' . max(1, $limit),
            [$kind, Database::now(-$days * 86_400)],
        );
    }

    /** @return list<array<string, mixed>> The newest runs of any kind. */
    public function recentRuns(int $limit = 20): array
    {
        return $this->db->all(
            'SELECT r.public_id, r.kind, r.target_url, r.status, r.mode, r.created_at, r.finished_at, t.me AS tester
             FROM runs r
             LEFT JOIN testers t ON t.id = r.tester_id
             ORDER BY r.id DESC
             LIMIT ' . max(1, $limit),
        );
    }

    /**
     * One page of runs of a kind, newest first, with how much was graded.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function runs(string $kind, int $page, int $perPage, bool $withState = false): array
    {
        $total = (int) $this->db->value('SELECT COUNT(*) FROM runs WHERE kind = ?', [$kind]);
        $rows  = $this->db->all(
            'SELECT r.public_id, r.target_url, r.status, r.mode, r.created_at, r.finished_at, r.options_json,'
            // The narrative is a MEDIUMTEXT, so it is read only where a page
            // tells the story: the checkup list, never the run lists.
            . ($withState ? ' r.state_json,' : '') . '
                    t.me AS tester,
                    (SELECT COUNT(*) FROM results x WHERE x.run_id = r.id) AS graded,
                    (SELECT SUM(x.outcome = \'fail\') FROM results x WHERE x.run_id = r.id) AS fails,
                    (SELECT MAX(x.recorded_at) FROM results x WHERE x.run_id = r.id) AS last_result_at
             FROM runs r
             LEFT JOIN testers t ON t.id = r.tester_id
             WHERE r.kind = ?
             ORDER BY r.id DESC
             LIMIT ' . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            [$kind],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Checkups in the period, newest first, with the narrative each one kept.
     *
     * Where a checkup stopped is read from state_json in PHP rather than in
     * SQL: the column is MEDIUMTEXT on MariaDB, and the classes that wrote the
     * steps already know how to read them.
     *
     * @return list<array<string, mixed>>
     */
    public function checkups(int $days, int $limit = 500): array
    {
        return $this->db->all(
            'SELECT public_id, target_url, status, created_at, finished_at, options_json, state_json
             FROM runs
             WHERE kind = \'checkup\' AND created_at >= ?
             ORDER BY id DESC
             LIMIT ' . max(1, $limit),
            [Database::now(-$days * 86_400)],
        );
    }

    /** @return list<array<string, mixed>> Who signs in, and how much they run. */
    public function testers(int $limit = 20): array
    {
        return $this->db->all(
            'SELECT t.me, t.created_at, t.last_login_at,
                    (SELECT COUNT(*) FROM runs r WHERE r.tester_id = t.id) AS runs
             FROM testers t
             ORDER BY t.last_login_at DESC, t.id DESC
             LIMIT ' . max(1, $limit),
        );
    }
}

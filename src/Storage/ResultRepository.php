<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

use IndieAuthRocks\Engine\Outcome;
use IndieAuthRocks\Engine\Verdict;

final class ResultRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function record(int $runId, Verdict $verdict, ?string $step = null, string $answeredBy = 'auto'): void
    {
        $this->db->execute(
            'INSERT INTO results (run_id, test_id, outcome, summary, details_json, step, answered_by, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE outcome = VALUES(outcome), summary = VALUES(summary), details_json = VALUES(details_json),
                 step = VALUES(step), answered_by = VALUES(answered_by), recorded_at = VALUES(recorded_at)',
            [
                $runId,
                $verdict->testId,
                $verdict->outcome->value,
                mb_substr($verdict->summary, 0, 1000),
                $verdict->details === [] ? null : json_encode($verdict->details, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                $step,
                $answeredBy,
                Database::now(),
            ],
        );
    }

    /** @return array<string, array<string, mixed>> Keyed by test id. */
    public function forRun(int $runId): array
    {
        $results = [];

        foreach ($this->db->all('SELECT * FROM results WHERE run_id = ? ORDER BY test_id', [$runId]) as $row) {
            $row['details']              = json_decode((string) $row['details_json'], true) ?: [];
            $results[(string) $row['test_id']] = $row;
        }

        return $results;
    }

    public function find(int $runId, string $testId): ?Verdict
    {
        $row = $this->db->one('SELECT * FROM results WHERE run_id = ? AND test_id = ?', [$runId, $testId]);

        return $row === null ? null : new Verdict((string) $row['test_id'], Outcome::from((string) $row['outcome']), (string) $row['summary']);
    }

    /** @return array<string, int> outcome => count */
    public function counts(int $runId): array
    {
        $counts = [];

        foreach ($this->db->all('SELECT outcome, COUNT(*) AS n FROM results WHERE run_id = ? GROUP BY outcome', [$runId]) as $row) {
            $counts[(string) $row['outcome']] = (int) $row['n'];
        }

        return $counts;
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

use IndieAuthRocks\Crypto\Random;
use IndieAuthRocks\Engine\Run;

final class RunRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $state
     */
    public function create(string $kind, ?string $targetUrl, string $mode, array $options, array $state, ?int $testerId, int $ttlDays): Run
    {
        $now = Database::now();

        $id = $this->db->insert('runs', [
            'public_id'     => Random::ulid(),
            'run_key'       => Random::base32(16),
            'tester_id'     => $testerId,
            'kind'          => $kind,
            'target_url'    => $targetUrl === null ? null : mb_substr($targetUrl, 0, 1000),
            'mode'          => $mode,
            'options_json'  => json_encode($options, JSON_UNESCAPED_SLASHES),
            'status'        => Run::RUNNING,
            'state_json'    => json_encode($state, JSON_UNESCAPED_SLASHES),
            'suite_version' => self::suiteVersion(),
            'created_at'    => $now,
            'updated_at'    => $now,
            'expires_at'    => Database::now($ttlDays * 86_400),
        ]);

        return $this->find($id) ?? throw new \RuntimeException('The run could not be created.');
    }

    public function find(int $id): ?Run
    {
        $row = $this->db->one('SELECT * FROM runs WHERE id = ?', [$id]);

        return $row === null ? null : Run::fromRow($row);
    }

    public function findByPublicId(string $publicId): ?Run
    {
        $row = $this->db->one('SELECT * FROM runs WHERE public_id = ?', [$publicId]);

        return $row === null ? null : Run::fromRow($row);
    }

    public function findByKey(string $runKey): ?Run
    {
        $row = $this->db->one('SELECT * FROM runs WHERE run_key = ?', [$runKey]);

        return $row === null ? null : Run::fromRow($row);
    }

    /** @param array<string, mixed> $state */
    public function save(Run $run, array $state, ?string $status = null): void
    {
        $run->state = $state;
        $finished   = in_array($status, [Run::COMPLETE, Run::ABANDONED], true);

        $this->db->execute(
            'UPDATE runs SET state_json = ?, status = COALESCE(?, status), updated_at = ?, finished_at = IF(?, ?, finished_at) WHERE id = ?',
            [json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $status, Database::now(), $finished ? 1 : 0, Database::now(), $run->id],
        );
    }

    /** Exchanges and results go with it, by foreign key. */
    public function delete(Run $run): void
    {
        $this->db->execute('DELETE FROM runs WHERE id = ?', [$run->id]);
    }

    public function bindTester(Run $run, int $testerId): void
    {
        $this->db->execute('UPDATE runs SET tester_id = ? WHERE id = ? AND tester_id IS NULL', [$testerId, $run->id]);
    }

    /** @return list<array<string, mixed>> */
    public function forTester(int $testerId, int $limit = 50): array
    {
        return $this->db->all(
            'SELECT id, public_id, kind, target_url, mode, status, created_at, finished_at FROM runs
             WHERE tester_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$testerId],
        );
    }

    /** The git commit the suite ran from, when available. */
    public static function suiteVersion(): ?string
    {
        $head = @file_get_contents(dirname(__DIR__, 2) . '/.git/HEAD');

        if (!is_string($head)) {
            return null;
        }

        if (str_starts_with($head, 'ref: ')) {
            $ref  = trim(substr($head, 5));
            $head = @file_get_contents(dirname(__DIR__, 2) . '/.git/' . $ref);
        }

        return is_string($head) ? substr(trim($head), 0, 40) : null;
    }
}

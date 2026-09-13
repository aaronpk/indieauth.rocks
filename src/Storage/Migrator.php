<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

/**
 * Applies database/migrations/*.sql in filename order, once each.
 *
 * Migrations are plain SQL so they can be read and applied by hand if needed.
 * Statements are split on semicolons at line ends; keep one statement per
 * semicolon and avoid semicolons at the end of lines inside string literals.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> Versions applied by this call. */
    public function migrate(): array
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(64) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );

        $applied = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');
        $done    = [];

        foreach ($this->pending($applied) as $version => $file) {
            foreach (self::statements((string) file_get_contents($file)) as $statement) {
                // DDL commits implicitly in MySQL, so there is no transaction to
                // wrap this in; a failure part-way leaves the version unrecorded.
                $this->db->pdo()->exec($statement);
            }

            $this->db->insert('schema_migrations', ['version' => $version, 'applied_at' => Database::now()]);
            $done[] = $version;
        }

        return $done;
    }

    /**
     * @param list<string> $applied
     * @return array<string, string> version => file
     */
    public function pending(array $applied = []): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files);

        $pending = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (!in_array($version, $applied, true)) {
                $pending[$version] = $file;
            }
        }

        return $pending;
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $lines = array_filter(
            explode("\n", $sql),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        );

        return array_values(array_filter(
            array_map('trim', preg_split('/;\s*$/m', implode("\n", $lines)) ?: []),
            static fn (string $s): bool => $s !== '',
        ));
    }
}

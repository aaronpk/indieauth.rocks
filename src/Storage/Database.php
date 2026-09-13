<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

use Closure;
use PDO;
use PDOException;
use Throwable;

/**
 * A thin layer over PDO: prepared statements only, UTC timestamps, and
 * connection failures translated into StorageUnavailableException so the Kernel
 * can answer 503 rather than 500.
 */
final class Database
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function connect(string $host, int $port, string $name, string $user, string $pass): self
    {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 3,
                ],
            );
        } catch (PDOException $e) {
            throw new StorageUnavailableException('Could not connect to MySQL: ' . $e->getMessage(), $e);
        }

        $pdo->exec("SET time_zone = '+00:00'");

        return new self($pdo);
    }

    /** The current time in the format stored in DATETIME columns. */
    public static function now(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Run a statement and return the number of affected rows.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): int
    {
        $columns = array_keys($row);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );

        $this->execute($sql, $row);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     * @param Closure(self): T $work
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work($this);
        }

        $this->pdo->beginTransaction();

        try {
            $result = $work($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Whether an exception is a unique-key violation. */
    public static function isDuplicateKey(Throwable $e): bool
    {
        return $e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062;
    }
}

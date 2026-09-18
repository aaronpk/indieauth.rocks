<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

use Closure;
use JsonException;
use Redis;
use RedisException;
use RuntimeException;

/**
 * Redis access for everything ephemeral: sessions, passkey challenges,
 * authorization codes, pending consent, cached client metadata, throttle
 * counters.
 *
 * Uses the built-in phpredis extension. Every key carries a prefix so several
 * deployments (or a test run) can share one server.
 */
final class RedisStore
{
    /**
     * @param (Closure(): Redis)|null $reopen How to make a fresh connection when
     *                                        this one has gone stale; null for a
     *                                        client that cannot be replaced.
     */
    public function __construct(
        private Redis $client,
        private readonly string $prefix = 'mia:',
        private readonly ?Closure $reopen = null,
    ) {
    }

    public static function connect(string $host, int $port, int $db = 0, string $prefix = 'mia:'): self
    {
        $open = static function () use ($host, $port, $db): Redis {
            $client = new Redis();
            $client->connect($host, $port, 2.0);
            $client->setOption(Redis::OPT_READ_TIMEOUT, 2.0);
            $client->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

            if ($db !== 0) {
                $client->select($db);
            }

            return $client;
        };

        try {
            $client = $open();
        } catch (RedisException $e) {
            throw new StorageUnavailableException('Could not connect to Redis: ' . $e->getMessage(), $e);
        }

        return new self($client, $prefix, $open);
    }

    public function get(string $key): ?string
    {
        $value = $this->call(fn (): mixed => $this->client->get($this->prefix . $key));

        return is_string($value) ? $value : null;
    }

    /**
     * @param list<string> $keys
     * @return array<string, string> Only the keys that exist.
     */
    public function getMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $values = $this->call(fn (): mixed => $this->client->mGet(array_map(fn (string $k): string => $this->prefix . $k, $keys)));
        $found  = [];

        foreach ($keys as $i => $key) {
            if (is_array($values) && is_string($values[$i] ?? null)) {
                $found[$key] = $values[$i];
            }
        }

        return $found;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        if ($ttl <= 0) {
            throw new RuntimeException('Refusing to store a key without a TTL.');
        }

        $this->call(fn (): mixed => $this->client->setex($this->prefix . $key, $ttl, $value));
    }

    /**
     * Store only if the key does not already exist.
     *
     * Returns false when the key was already present, which is how single-use
     * values detect a replay without a read-then-write race.
     */
    public function add(string $key, string $value, int $ttl): bool
    {
        if ($ttl <= 0) {
            throw new RuntimeException('Refusing to store a key without a TTL.');
        }

        return $this->call(fn (): mixed => $this->client->set($this->prefix . $key, $value, ['nx', 'ex' => $ttl])) === true;
    }

    /** @return array<string, mixed>|null */
    public function getJson(string $key): ?array
    {
        $raw = $this->get($key);

        return $raw === null ? null : $this->decode($raw);
    }

    /** @param array<string, mixed> $value */
    public function setJson(string $key, array $value, int $ttl): void
    {
        $this->set($key, $this->encode($value), $ttl);
    }

    /**
     * Atomically read and delete. This is what makes authorization codes and
     * passkey challenges single-use.
     *
     * @return array<string, mixed>|null
     */
    public function takeJson(string $key): ?array
    {
        $raw = $this->call(fn (): mixed => $this->client->getDel($this->prefix . $key));

        return is_string($raw) ? $this->decode($raw) : null;
    }

    public function delete(string ...$keys): int
    {
        if ($keys === []) {
            return 0;
        }

        $prefixed = array_map(fn (string $k): string => $this->prefix . $k, $keys);

        return (int) $this->call(fn (): mixed => $this->client->del($prefixed));
    }

    public function exists(string $key): bool
    {
        return (int) $this->call(fn (): mixed => $this->client->exists($this->prefix . $key)) > 0;
    }

    /**
     * Increment a counter, setting the TTL on first use only, so a burst of
     * attempts cannot keep pushing the expiry out.
     */
    public function increment(string $key, int $ttl): int
    {
        $full  = $this->prefix . $key;
        $count = (int) $this->call(fn (): mixed => $this->client->incr($full));

        if ($count === 1) {
            $this->call(fn (): mixed => $this->client->expire($full, $ttl));
        }

        return $count;
    }

    /** Seconds remaining, or null when the key is missing or has no expiry. */
    public function ttl(string $key): ?int
    {
        $ttl = (int) $this->call(fn (): mixed => $this->client->ttl($this->prefix . $key));

        return $ttl >= 0 ? $ttl : null;
    }

    /**
     * Every key matching a pattern, e.g. 'sess:*'. SCAN rather than KEYS.
     *
     * @return list<string> Keys with the prefix stripped.
     */
    public function scan(string $pattern): array
    {
        $found    = [];
        $iterator = null;

        do {
            $keys = $this->call(function () use (&$iterator, $pattern): mixed {
                return $this->client->scan($iterator, $this->prefix . $pattern, 200);
            });

            if (is_array($keys)) {
                foreach ($keys as $key) {
                    $found[] = substr((string) $key, strlen($this->prefix));
                }
            }
        } while ($iterator > 0);

        return array_values(array_unique($found));
    }

    public function ping(): void
    {
        $this->call(fn (): mixed => $this->client->ping());
    }

    private function call(Closure $command): mixed
    {
        try {
            return $command();
        } catch (RedisException $e) {
            if ($this->reopen === null || !self::isStaleConnection($e)) {
                throw new StorageUnavailableException('Redis command failed: ' . $e->getMessage(), $e);
            }
        }

        // The connection went stale — a read timed out, or the server closed
        // it while this process sat idle — which is not the same as Redis
        // being down. One fresh connection and one more try; a second failure
        // is reported as the outage it then is. The command closures read
        // $this->client when they run, so the retry uses the new one.
        try {
            $this->client = ($this->reopen)();

            return $command();
        } catch (RedisException $e) {
            throw new StorageUnavailableException('Redis command failed after reconnecting: ' . $e->getMessage(), $e);
        }
    }

    /** The errors phpredis reports for a connection that has gone bad, as opposed to a refused one. */
    private static function isStaleConnection(RedisException $e): bool
    {
        return preg_match('/read error on connection|went away|not connected|connection lost|broken pipe|reset by peer/i', $e->getMessage()) === 1;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Could not encode value for Redis: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array<string, mixed>|null */
    private function decode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        // A malformed entry is treated as absent: nothing in Redis is a source
        // of truth, so discarding is safer than failing the request.
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

/**
 * Counts attempts within a fixed window.
 *
 * The window does not extend on each hit — see RedisStore::increment — so a
 * steady stream of attempts cannot hold a bucket locked indefinitely. That
 * matters wherever the bucket is keyed on something an attacker chooses, such as
 * a username: otherwise locking someone out would be as easy as attempting to log
 * in as them forever.
 *
 * Keys are hashed, so a Redis dump does not enumerate the usernames, addresses or
 * invite codes that have been tried.
 */
final class RateLimiter
{
    public function __construct(private readonly RedisStore $redis)
    {
    }

    /** Record an attempt and report the running count. */
    public function hit(string $bucket, string $key, int $window): int
    {
        return $this->redis->increment($this->key($bucket, $key), $window);
    }

    public function count(string $bucket, string $key): int
    {
        return (int) ($this->redis->get($this->key($bucket, $key)) ?? '0');
    }

    public function exceeded(string $bucket, string $key, int $limit): bool
    {
        return $this->count($bucket, $key) >= $limit;
    }

    /** Seconds until the window resets, or zero when there is nothing recorded. */
    public function retryAfter(string $bucket, string $key): int
    {
        return $this->redis->ttl($this->key($bucket, $key)) ?? 0;
    }

    public function clear(string $bucket, string $key): void
    {
        $this->redis->delete($this->key($bucket, $key));
    }

    private function key(string $bucket, string $key): string
    {
        return 'throttle:' . $bucket . ':' . hash('sha256', strtolower(trim($key)));
    }
}

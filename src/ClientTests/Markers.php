<?php

declare(strict_types=1);

namespace IndieAuthRocks\ClientTests;

use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Storage\RedisStore;

/**
 * Evidence of what a client did during a client run: which pages it fetched,
 * whether it redeemed a code, whether it touched a decoy. Kept in Redis as
 * individual keys, so concurrent requests from the client never overwrite
 * each other.
 */
final class Markers
{
    public const PROFILE    = 'profile';
    public const METADATA   = 'metadata';
    public const AUTHORIZE  = 'authorize';
    public const ISSUED     = 'issued';
    public const REDEEMED   = 'redeemed';
    public const ME_FETCHED = 'me_fetched';
    public const DECOY      = 'decoy';
    public const NAIVE      = 'naive';
    public const REFRESHED  = 'refreshed';

    private const TTL = 30 * 86_400;

    public function __construct(private readonly RedisStore $redis)
    {
    }

    public function mark(Run $run, int $n, string $name, ?string $value = null): void
    {
        $this->redis->set($this->key($run, $n, $name), $value ?? (string) time(), self::TTL);
    }

    /** Set only the first time, so "when did this first happen" is kept. */
    public function first(Run $run, int $n, string $name): void
    {
        $this->redis->add($this->key($run, $n, $name), (string) time(), self::TTL);
    }

    /** @return array<string, string> name => value */
    public function all(Run $run, int $n): array
    {
        $prefix  = 'ct:m:' . $run->id . ':' . $n . ':';
        $markers = [];

        foreach ($this->redis->scan($prefix . '*') as $key) {
            $value = $this->redis->get($key);

            if ($value !== null) {
                $markers[substr($key, strlen($prefix))] = $value;
            }
        }

        return $markers;
    }

    /** @param array<string, mixed> $data */
    public function remember(Run $run, string $name, array $data): void
    {
        $this->redis->setJson('ct:r:' . $run->id . ':' . $name, $data, self::TTL);
    }

    /** @return array<string, mixed>|null */
    public function recall(Run $run, string $name): ?array
    {
        return $this->redis->getJson('ct:r:' . $run->id . ':' . $name);
    }

    /** Whether a value was seen before in this run (and remember it). */
    public function seenBefore(Run $run, string $kind, string $value): bool
    {
        return !$this->redis->add('ct:seen:' . $run->id . ':' . $kind . ':' . hash('sha256', $value), '1', self::TTL);
    }

    private function key(Run $run, int $n, string $name): string
    {
        return 'ct:m:' . $run->id . ':' . $n . ':' . $name;
    }
}

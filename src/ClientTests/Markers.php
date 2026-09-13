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

    /** In C403, the returned me's server was looked up (as a careful client does). */
    public const OTHER_SERVER_METADATA = 'other_server_metadata';

    private const NAMES = [
        self::PROFILE, self::METADATA, self::AUTHORIZE, self::ISSUED, self::REDEEMED,
        self::ME_FETCHED, self::DECOY, self::NAIVE, self::REFRESHED, self::OTHER_SERVER_METADATA,
    ];

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
        // One MGET of the known names: pages grade every test on each view, and
        // a SCAN per test would walk the whole keyspace dozens of times.
        $keys    = array_map(fn (string $name): string => $this->key($run, $n, $name), self::NAMES);
        $found   = $this->redis->getMany($keys);
        $markers = [];

        foreach (self::NAMES as $i => $name) {
            if (isset($found[$keys[$i]])) {
                $markers[$name] = $found[$keys[$i]];
            }
        }

        return $markers;
    }

    public function has(Run $run, int $n, string $name): bool
    {
        return $this->redis->exists($this->key($run, $n, $name));
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

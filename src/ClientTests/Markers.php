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
 *
 * Evidence is kept per attempt at a test. Fetching the profile URL, or a new
 * authorization request after one that already reached the server, begins an
 * attempt, and only the latest attempt is graded: a client that gets it right
 * the second time passes, and one that gets it wrong the second time fails.
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

    /** When the attempt began. */
    public const STARTED = 'started';

    private const NAMES = [
        self::PROFILE, self::METADATA, self::AUTHORIZE, self::ISSUED, self::REDEEMED,
        self::ME_FETCHED, self::DECOY, self::NAIVE, self::REFRESHED, self::OTHER_SERVER_METADATA,
        self::STARTED,
    ];

    /** Evidence that an attempt got further than fetching pages. */
    private const PROGRESS = [self::AUTHORIZE, self::DECOY, self::NAIVE, self::ISSUED, self::REDEEMED];

    /** How many attempts back to look for one that got somewhere. */
    private const LOOKBACK = 20;

    private const TTL = 30 * 86_400;

    public function __construct(private readonly RedisStore $redis)
    {
    }

    /**
     * The latest attempt at a test. Runs from before attempts were counted
     * have attempt 0, kept under the original key names.
     */
    public function current(Run $run, int $n): int
    {
        return (int) ($this->redis->get($this->counterKey($run, $n)) ?? 0);
    }

    /** Begin a new attempt at a test, and return its number. */
    public function begin(Run $run, int $n): int
    {
        $attempt = $this->redis->increment($this->counterKey($run, $n), self::TTL);
        $this->redis->set($this->key($run, $n, $attempt, self::STARTED), (string) time(), self::TTL);

        return $attempt;
    }

    /** @param int|null $attempt Null for the latest attempt. */
    public function mark(Run $run, int $n, string $name, ?int $attempt = null): void
    {
        $this->redis->set($this->key($run, $n, $attempt ?? $this->current($run, $n), $name), (string) time(), self::TTL);
    }

    /**
     * Set only the first time in an attempt, so "when did this first happen" is kept.
     *
     * @param int|null $attempt Null for the latest attempt.
     */
    public function first(Run $run, int $n, string $name, ?int $attempt = null): void
    {
        $this->redis->add($this->key($run, $n, $attempt ?? $this->current($run, $n), $name), (string) time(), self::TTL);
    }

    /** @return array<string, string> name => value */
    public function all(Run $run, int $n, int $attempt): array
    {
        // One MGET of the known names: pages grade every test on each view, and
        // a SCAN per test would walk the whole keyspace dozens of times.
        $keys    = array_map(fn (string $name): string => $this->key($run, $n, $attempt, $name), self::NAMES);
        $found   = $this->redis->getMany($keys);
        $markers = [];

        foreach (self::NAMES as $i => $name) {
            if (isset($found[$keys[$i]])) {
                $markers[$name] = $found[$keys[$i]];
            }
        }

        return $markers;
    }

    /**
     * The attempt that decides a test: the newest one that got further than
     * fetching pages. A profile URL fetched again to verify me, or looked at
     * by hand, begins an attempt that never hides the sign-in before it.
     *
     * @return array{attempt: int, markers: array<string, string>}
     */
    public function graded(Run $run, int $n): array
    {
        $latest = $this->current($run, $n);
        $newest = null;

        for ($attempt = $latest; $attempt >= max(0, $latest - self::LOOKBACK); $attempt--) {
            $markers  = $this->all($run, $n, $attempt);
            $newest ??= ['attempt' => $attempt, 'markers' => $markers];

            if (array_intersect_key($markers, array_flip(self::PROGRESS)) !== []) {
                return ['attempt' => $attempt, 'markers' => $markers];
            }
        }

        return $newest ?? ['attempt' => $latest, 'markers' => []];
    }

    /** @param int|null $attempt Null for the latest attempt. */
    public function has(Run $run, int $n, string $name, ?int $attempt = null): bool
    {
        return $this->redis->exists($this->key($run, $n, $attempt ?? $this->current($run, $n), $name));
    }

    /** Note the attempt that sent the latest authorization request. */
    public function noteAuthorized(Run $run, int $n, int $attempt): void
    {
        $this->redis->set('ct:authz:' . $run->id . ':' . $n, (string) $attempt, self::TTL);
    }

    /**
     * The attempt that sent the latest authorization request. Evidence that
     * follows a sign-in, like fetching the returned me, belongs to it even if
     * fetching a profile URL has begun a newer attempt since.
     */
    public function lastAuthorized(Run $run, int $n): int
    {
        $attempt = $this->redis->get('ct:authz:' . $run->id . ':' . $n);

        return $attempt === null ? $this->current($run, $n) : (int) $attempt;
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

    private function key(Run $run, int $n, int $attempt, string $name): string
    {
        return 'ct:m:' . $run->id . ':' . $n . ':' . ($attempt === 0 ? '' : $attempt . ':') . $name;
    }

    private function counterKey(Run $run, int $n): string
    {
        return 'ct:a:' . $run->id . ':' . $n;
    }
}

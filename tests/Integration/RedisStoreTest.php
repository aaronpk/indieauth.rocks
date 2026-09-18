<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Storage\RedisStore;
use IndieAuthRocks\Tests\Support\AppTestCase;

final class RedisStoreTest extends AppTestCase
{
    /**
     * A connection that has gone stale — here, a read that times out because
     * the server was paused — is reopened once rather than reported as an
     * outage. The pause outlasts the read timeout, so the first attempt fails
     * and the retry, on a fresh connection, waits out the rest.
     */
    public function test_a_stale_connection_is_reopened_once(): void
    {
        $config = $this->config();
        $store  = RedisStore::connect($config->redisHost, $config->redisPort, $config->redisDb, $config->redisPrefix . 'stale:');
        $store->set('key', 'before', 60);

        $other = new \Redis();
        $other->connect($config->redisHost, $config->redisPort, 1.0);

        try {
            $paused = $other->rawCommand('CLIENT', 'PAUSE', '3000', 'ALL');
        } catch (\RedisException) {
            $paused = false;
        }

        if ($paused !== true) {
            $this->markTestSkipped('CLIENT PAUSE is not available on this Redis.');
        }

        $started = microtime(true);
        $this->assertSame('before', $store->get('key'));
        $this->assertGreaterThan(1.5, microtime(true) - $started, 'The first read should have timed out before the retry succeeded.');
    }
}

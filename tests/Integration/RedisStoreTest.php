<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Storage\RedisStore;
use IndieAuthRocks\Tests\Support\AppTestCase;

final class RedisStoreTest extends AppTestCase
{
    /**
     * takeJson must not depend on GETDEL, which needs Redis 6.2. phpredis
     * answers an unknown command with false, which reads here as a missing
     * key, so on an older server every one-shot record looked expired.
     */
    public function test_a_record_is_read_and_removed_in_one_step(): void
    {
        $config = $this->config();
        $store  = RedisStore::connect($config->redisHost, $config->redisPort, $config->redisDb, $config->redisPrefix . 'take:');

        $store->setJson('pending', ['state' => 'kept'], 60);

        $this->assertSame(['state' => 'kept'], $store->takeJson('pending'));
        $this->assertNull($store->takeJson('pending'), 'the second read finds nothing');
        $this->assertNull($store->takeJson('never-there'));
    }

    /**
     * An error from the server is an outage, not an absence: reporting it as a
     * missing key is what made a broken command look like an expired sign-in.
     */
    public function test_an_error_reply_is_reported_rather_than_read_as_missing(): void
    {
        $config = $this->config();
        $store  = RedisStore::connect($config->redisHost, $config->redisPort, $config->redisDb, $config->redisPrefix . 'wrongtype:');

        // A list where the code expects a string: the server answers WRONGTYPE.
        $client = new \Redis();
        $client->connect($config->redisHost, $config->redisPort, 1.0);
        $client->select($config->redisDb);
        $client->del($config->redisPrefix . 'wrongtype:list');
        $client->rPush($config->redisPrefix . 'wrongtype:list', 'x');

        try {
            $this->expectException(\IndieAuthRocks\Storage\StorageUnavailableException::class);
            $store->get('list');
        } finally {
            $client->del($config->redisPrefix . 'wrongtype:list');
        }
    }

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

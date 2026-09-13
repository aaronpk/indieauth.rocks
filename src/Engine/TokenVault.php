<?php

declare(strict_types=1);

namespace IndieAuthRocks\Engine;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Storage\RedisStore;

/**
 * Secrets obtained or created during a run — access and refresh tokens, PKCE
 * verifiers, a pasted introspection credential — kept only in Redis, encrypted,
 * and only for as long as a run could reasonably take.
 */
final class TokenVault
{
    // Long enough for a browser run left open overnight; finish() clears it,
    // and a step whose secrets expired anyway runs again rather than failing.
    private const TTL = 86_400;

    public function __construct(
        private readonly RedisStore $redis,
        private readonly SiteConfig $config,
    ) {
    }

    public function put(Run $run, string $name, string $value): void
    {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($value, $nonce, $this->config->secretKey());

        $this->redis->set($this->key($run, $name), base64_encode($nonce . $cipher), self::TTL);
    }

    public function get(Run $run, string $name): ?string
    {
        $stored = $this->redis->get($this->key($run, $name));

        if ($stored === null) {
            return null;
        }

        $raw = base64_decode($stored, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->config->secretKey(),
        );

        return $plain === false ? null : $plain;
    }

    public function forget(Run $run, string $name): void
    {
        $this->redis->delete($this->key($run, $name));
    }

    public function clear(Run $run): void
    {
        foreach ($this->redis->scan('vault:' . $run->id . ':*') as $key) {
            $this->redis->delete($key);
        }
    }

    private function key(Run $run, string $name): string
    {
        return 'vault:' . $run->id . ':' . $name;
    }
}

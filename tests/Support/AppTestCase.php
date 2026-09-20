<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Support;

use IndieAuthRocks\Bootstrap;
use IndieAuthRocks\Config\Env;
use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Console\Application;
use IndieAuthRocks\Container;
use IndieAuthRocks\Fetch\HttpClient;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Storage\Database;
use IndieAuthRocks\Storage\RedisStore;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Boots the real application against a disposable MySQL database and an
 * isolated Redis key prefix, with the network replaced by FakeHttpClient.
 */
abstract class AppTestCase extends TestCase
{
    // No ports: client tests hand these out as profile URLs, which cannot have one.
    protected const BASE = 'http://rocks.example/';
    protected const ALT  = 'http://alt.rocks.example/';

    protected Container $container;
    protected FakeHttpClient $http;

    /** @var array<string, string> */
    protected array $cookies = [];

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        Env::load(SiteConfig::root() . '/.env');
        putenv('LOG_FILE=' . sys_get_temp_dir() . '/indieauthrocks-tests.log');

        $dbName = (string) Env::get('TEST_DB_NAME', 'indieauthrocks_test');

        if (!str_ends_with($dbName, '_test')) {
            $this->markTestSkipped('TEST_DB_NAME must end in _test, because the database is wiped.');
        }

        $config = new SiteConfig(
            siteName:    'IndieAuth Rocks Test',
            baseUrl:     self::BASE,
            altBaseUrl:  self::ALT,
            dbHost:      (string) Env::get('DB_HOST', '127.0.0.1'),
            dbPort:      Env::int('DB_PORT', 3306),
            dbName:      $dbName,
            dbUser:      (string) Env::get('DB_USER', 'indieauthrocks'),
            dbPass:      (string) Env::get('DB_PASS', ''),
            redisHost:   (string) Env::get('REDIS_HOST', '127.0.0.1'),
            redisPort:   Env::int('REDIS_PORT', 6379),
            redisDb:     Env::int('TEST_REDIS_DB', 14),
            redisPrefix: 'iartest:' . bin2hex(random_bytes(6)) . ':',
            appSecret:   str_repeat('ab', 32),
        );

        $this->container = Bootstrap::container($config);
        $this->http      = new FakeHttpClient();
        $http            = $this->http;
        $this->container->set(HttpClient::class, static fn (): HttpClient => $http);

        try {
            $db = $this->container->get(Database::class);
            $this->container->get(RedisStore::class)->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Test storage is not reachable: ' . $e->getMessage());
        }

        $this->resetDatabase($db);
    }

    protected function tearDown(): void
    {
        try {
            $redis = $this->container->get(RedisStore::class);
            foreach ($redis->scan('*') as $key) {
                $redis->delete($key);
            }
        } catch (Throwable) {
        }
    }

    private function resetDatabase(Database $db): void
    {
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');

        if (!self::$schemaReady) {
            foreach ($db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $row) {
                $db->pdo()->exec('DROP TABLE `' . $row['t'] . '`');
            }
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');

            Application::migrator($this->container)->migrate();
            self::$schemaReady = true;

            return;
        }

        foreach ($db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name <> 'schema_migrations'") as $row) {
            $db->pdo()->exec('TRUNCATE TABLE `' . $row['t'] . '`');
        }

        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function config(): SiteConfig
    {
        return $this->container->get(SiteConfig::class);
    }

    /**
     * Make these profile URLs admins.
     *
     * Admin is a deployment setting, so there is nothing to give a tester in
     * the database: the container's SiteConfig is replaced instead. Services
     * resolve lazily, so this takes effect on the next request.
     */
    protected function adminIs(string ...$profiles): void
    {
        $base   = $this->config();
        $config = new SiteConfig(
            siteName:    $base->siteName,
            baseUrl:     $base->baseUrl,
            altBaseUrl:  $base->altBaseUrl,
            dbHost:      $base->dbHost,
            dbPort:      $base->dbPort,
            dbName:      $base->dbName,
            dbUser:      $base->dbUser,
            dbPass:      $base->dbPass,
            redisHost:   $base->redisHost,
            redisPort:   $base->redisPort,
            redisDb:     $base->redisDb,
            redisPrefix: $base->redisPrefix,
            appSecret:   $base->appSecret,
            adminUsers:  array_values($profiles),
        );

        $this->container->set(SiteConfig::class, static fn (): SiteConfig => $config);
    }

    /** Dispatch a request to the app in-process, carrying cookies. */
    protected function request(string $method, string $url, array $post = [], array $headers = []): Response
    {
        $parts = parse_url($url);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $response = Bootstrap::kernel($this->container)->handle(new Request(
            method: $method,
            path: (string) ($parts['path'] ?? '/'),
            query: array_map('strval', array_filter($query, 'is_string')),
            post: $post,
            headers: $headers + ($post !== [] ? ['content-type' => 'application/x-www-form-urlencoded'] : []),
            cookies: $this->cookies,
            body: http_build_query($post),
            host: (string) ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : ''),
            queryString: (string) ($parts['query'] ?? ''),
        ));

        foreach ($response->headers()['set-cookie'] ?? [] as $header) {
            [$pair]  = explode(';', $header);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if ($value === '' || stripos($header, 'max-age=0') !== false) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = rawurldecode($value);
            }
        }

        return $response;
    }
}

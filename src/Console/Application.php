<?php

declare(strict_types=1);

namespace IndieAuthRocks\Console;

use IndieAuthRocks\Bootstrap;
use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Container;
use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Storage\Database;
use IndieAuthRocks\Storage\Migrator;
use IndieAuthRocks\Storage\RedisStore;
use Throwable;

/**
 * bin/indieauth-rocks: maintenance commands and the command-line test runner.
 */
final class Application
{
    private const COMMANDS = [
        'migrate' => 'Apply pending database migrations',
        'doctor'  => 'Check configuration, database, Redis and hostnames',
        'catalog' => 'List tests: catalog [--suite=server|client] [--format=text|md]',
        'prune'   => 'Delete unpublished runs older than RUN_RETENTION_DAYS',
        'run-server' => 'Run the server suite: run-server <profile-url> [--driver=myindieauth] [--extended] [--json]',
        'driver:myindieauth:enroll' => 'Create a driver account on a myindieauth server: --base=URL [--profile=URL]',
        'help'    => 'Show this list',
    ];

    private readonly Container $container;

    public function __construct(?Container $container = null, private readonly Output $out = new Output())
    {
        $this->container = $container ?? Bootstrap::container();
    }

    /** @param list<string> $argv Arguments after the script name. */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        $args    = new Args(array_slice($argv, 1));

        try {
            return match ($command) {
                'migrate'              => $this->migrate(),
                'doctor'               => $this->doctor(),
                'catalog'              => $this->catalog($args),
                'prune'                => $this->prune(),
                'run-server'           => (new RunServerCommand($this->out, $this->container))->run($args),
                'driver:myindieauth:enroll' => (new EnrollCommand($this->out, $this->container->get(SiteConfig::class)))->run($args),
                'help', '--help', '-h' => $this->help(),
                default                => $this->unknown($command),
            };
        } catch (Throwable $e) {
            $this->out->error($e->getMessage());

            if (Bootstrap::debug()) {
                $this->out->error($e->getTraceAsString());
            }

            return 1;
        }
    }

    public static function migrator(Container $container): Migrator
    {
        return new Migrator($container->get(Database::class), SiteConfig::root() . '/database/migrations');
    }

    private function migrate(): int
    {
        $applied = self::migrator($this->container)->migrate();

        if ($applied === []) {
            $this->out->line('Nothing to migrate; the database is up to date.');
        }

        foreach ($applied as $version) {
            $this->out->pass('Applied ' . $version);
        }

        return 0;
    }

    private function doctor(): int
    {
        $failures = 0;
        $config   = $this->container->get(SiteConfig::class);

        $this->out->heading('Configuration');
        $this->out->info('Site', $config->baseUrl);
        $this->out->info('Alternate origin', $config->altBaseUrl);

        if (!$config->hasDistinctAltOrigin()) {
            $this->out->fail('ALT_BASE_URL', 'must be a different origin from BASE_URL, or cross-origin tests cannot run');
            $failures++;
        }

        try {
            $config->secretKey();
            $this->out->pass('APP_SECRET set');
        } catch (Throwable $e) {
            $this->out->fail('APP_SECRET', $e->getMessage());
            $failures++;
        }

        if ($config->allowLoopbackFetch || $config->allowPrivateFetch) {
            $this->out->warn('Loopback or private fetching enabled', 'development only');
        }

        $this->out->heading('Storage');

        try {
            $db      = $this->container->get(Database::class);
            $applied = [];
            try {
                $applied = array_column($db->all('SELECT version FROM schema_migrations'), 'version');
            } catch (Throwable) {
            }
            $pending = self::migrator($this->container)->pending($applied);
            $this->out->pass('MySQL reachable', $config->dbName);

            if ($pending === []) {
                $this->out->pass('Migrations applied');
            } else {
                $this->out->fail('Pending migrations', implode(', ', array_keys($pending)));
                $failures++;
            }
        } catch (Throwable $e) {
            $this->out->fail('MySQL', $e->getMessage());
            $failures++;
        }

        try {
            $this->container->get(RedisStore::class)->ping();
            $this->out->pass('Redis reachable');
        } catch (Throwable $e) {
            $this->out->fail('Redis', $e->getMessage());
            $failures++;
        }

        $this->out->line();

        return $failures === 0 ? 0 : 1;
    }

    private function prune(): int
    {
        $days    = $this->container->get(SiteConfig::class)->runRetentionDays;
        $removed = $this->container->get(Database::class)->execute(
            'DELETE FROM runs WHERE created_at < ? AND id NOT IN (SELECT run_id FROM reports)',
            [Database::now(-$days * 86_400)],
        );

        $this->out->line(sprintf('Removed %d unpublished run(s) older than %d days.', $removed, $days));

        return 0;
    }

    private function catalog(Args $args): int
    {
        $markdown = $args->option('format', 'text') === 'md';
        $suites   = $args->option('suite') !== null ? [(string) $args->option('suite')] : [Catalog::SERVER, Catalog::CLIENT];

        foreach ($suites as $suite) {
            $this->out->line($markdown ? '# ' . ucfirst($suite) . ' tests' : strtoupper($suite) . ' TESTS');

            foreach (Catalog::sections($suite) as $section => $tests) {
                $this->out->line();
                $this->out->line($markdown ? '## ' . $section : $section);

                if ($markdown) {
                    $this->out->line();
                    $this->out->line('| ID | Test | Level | Mode | Spec |');
                    $this->out->line('|---|---|---|---|---|');
                }

                foreach ($tests as $test) {
                    $this->out->line($markdown
                        ? sprintf('| %s | %s%s | %s | %s | %s |', $test->id, $test->title, $test->extended ? ' (extended)' : '', $test->level->label(), $test->mode->label(), $test->spec)
                        : sprintf('  %s  %-58s %-13s %s', $test->id, $test->title, $test->level->label(), $test->mode->label()));
                }
            }

            $this->out->line();
        }

        return 0;
    }

    private function help(): int
    {
        $this->out->line('Usage: bin/indieauth-rocks <command> [options]');
        $this->out->line();

        foreach (self::COMMANDS as $name => $description) {
            $this->out->line(sprintf('  %-26s %s', $name, $description));
        }

        return 0;
    }

    private function unknown(string $command): int
    {
        $this->out->error(sprintf('Unknown command "%s".', $command));
        $this->help();

        return 1;
    }
}

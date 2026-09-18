<?php

declare(strict_types=1);

namespace IndieAuthRocks\Console;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Container;
use IndieAuthRocks\Drivers\DriverHttp;
use IndieAuthRocks\Drivers\DriverOutcome;
use IndieAuthRocks\Drivers\IndieKey\DriverConfigFile;
use IndieAuthRocks\Drivers\IndieKey\IndieKeyDriver;
use IndieAuthRocks\Drivers\UserAgentDriver;
use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\ServerTests\Action;
use IndieAuthRocks\ServerTests\ServerRunner;
use IndieAuthRocks\Storage\ResultRepository;
use IndieAuthRocks\Storage\RunRepository;
use Throwable;

/**
 * run-server — run the server suite from the command line. With a driver the
 * whole run is unattended; without one, only the automatic tests run.
 */
final class RunServerCommand
{
    private const OPTIONS = ['driver', 'driver-config', 'ca-file', 'credential', 'introspection-scope', 'extended', 'slow', 'json'];

    public function __construct(
        private readonly Output $out,
        private readonly Container $container,
    ) {
    }

    public function run(Args $args): int
    {
        $profile = $args->positional(0);
        $unknown = $args->unknownOptions(self::OPTIONS);

        if ($profile === null || $unknown !== []) {
            $this->out->error('Usage: run-server <profile-url> [--driver=indiekey] [--driver-config=path] [--ca-file=path] [--credential=secret] [--introspection-scope=create] [--extended] [--slow] [--json]');

            return 1;
        }

        $config  = $this->container->get(SiteConfig::class);
        $runner  = $this->container->get(ServerRunner::class);
        $runs    = $this->container->get(RunRepository::class);
        $results = $this->container->get(ResultRepository::class);
        $json    = $args->flag('json');
        $driver  = $this->driver($args, $config);

        $run = $runner->start($profile, 'cli', $args->flag('extended'), $args->flag('slow'));
        $this->progress($json, 'Run: ' . $config->url('runs/' . $run->publicId));

        $last = null;

        for ($i = 0; $i < 500; $i++) {
            $run    = $runs->find($run->id) ?? $run;
            $action = $runner->next($run);

            if ($action->type === Action::FINISHED) {
                break;
            }

            if ($action->type === Action::WAIT) {
                $this->progress($json, sprintf('  %s: waiting %d seconds', $action->step, max(0, (int) $action->until - time())));
                sleep(max(0, (int) $action->until - time()) + 1);
                $runner->resume($run);
                continue;
            }

            if ($driver === null) {
                $runner->skip($run);
                continue;
            }

            if ($action->type === Action::ASK) {
                $answer = $action->question === null ? null : $driver->answer($action->question, $last);
                $answer === null ? $runner->skip($run) : $runner->answer($run, $action->question->key, $answer, 'driver');
                continue;
            }

            $this->progress($json, '  ' . $action->title);

            try {
                $last = $driver->navigate($action);
            } catch (Throwable $e) {
                $this->out->error('    ' . $e->getMessage());
                $runner->skip($run);
                continue;
            }

            if ($last->kind === DriverOutcome::LANDED) {
                $result = $runner->land($run, (string) $last->landedUrl);

                if ($result['message'] !== '') {
                    $this->progress($json, '    ' . $result['message']);
                }

                // A landing that did not finish its step (e.g. a probe that
                // redirected somewhere unexpected) still needs an answer.
                $run   = $runs->find($run->id) ?? $run;
                $still = $runner->next($run);

                if ($still->type === Action::NAVIGATE && $still->attemptId === $action->attemptId) {
                    $runner->skip($run);
                }

                continue;
            }

            if ($action->question !== null) {
                $runner->answer($run, $action->question->key, $driver->answer($action->question, $last) ?? 'other', 'driver');
            } else {
                $this->out->error(sprintf('    Stopped with HTTP %d instead of returning to this site.', $last->status));
                $runner->skip($run);
            }
        }

        $run    = $runs->find($run->id) ?? $run;
        $rows   = $results->forRun($run->id);
        $counts = $results->counts($run->id);

        if ($json) {
            $this->out->line((string) json_encode([
                'run'     => $config->url('runs/' . $run->publicId),
                'status'  => $run->status,
                'counts'  => $counts,
                'results' => array_map(static fn (array $r): array => ['outcome' => $r['outcome'], 'summary' => $r['summary']], $rows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->out->heading(sprintf(
                'Passed %d · failed %d · warnings %d · skipped %d · errors %d',
                $counts['pass'] ?? 0, $counts['fail'] ?? 0, $counts['warning'] ?? 0, $counts['skipped'] ?? 0, $counts['error'] ?? 0,
            ));

            foreach ($rows as $id => $row) {
                $title = Catalog::find($id)?->title ?? '';

                match ($row['outcome']) {
                    'fail', 'error' => $this->out->fail($id . ' ' . $title, (string) $row['summary']),
                    'warning'       => $this->out->warn($id . ' ' . $title, (string) $row['summary']),
                    default         => null,
                };
            }

            $this->out->line();
        }

        return (($counts['fail'] ?? 0) + ($counts['error'] ?? 0)) > 0 ? 1 : 0;
    }

    private function driver(Args $args, SiteConfig $config): ?UserAgentDriver
    {
        $name = $args->option('driver');

        if ($name === null) {
            return null;
        }

        if ($name !== 'indiekey') {
            throw new \RuntimeException('Unknown driver "' . $name . '". Available: indiekey');
        }

        $file = new DriverConfigFile((string) $args->option('driver-config', SiteConfig::root() . '/storage/drivers/indiekey.json'));

        if (!$file->exists()) {
            throw new \RuntimeException('No driver settings found. Run driver:indiekey:enroll first.');
        }

        return (new IndieKeyDriver(
            $config,
            new DriverHttp($args->option('ca-file')),
            $file->authenticator(),
            (string) $file->get('base'),
            $file,
        ))->withIntrospectionCredential($args->option('credential'))
            ->withIntrospectionScope($args->option('introspection-scope'));
    }

    private function progress(bool $json, string $line): void
    {
        if ($json) {
            fwrite(STDERR, $line . PHP_EOL);
        } else {
            $this->out->line($line);
        }
    }
}

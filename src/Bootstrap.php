<?php

declare(strict_types=1);

namespace IndieAuthRocks;

use IndieAuthRocks\Config\Env;
use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Controllers\AccountController;
use IndieAuthRocks\Controllers\AltController;
use IndieAuthRocks\Controllers\CatalogController;
use IndieAuthRocks\Controllers\ServerRunController;
use IndieAuthRocks\Controllers\ClientDocsController;
use IndieAuthRocks\Controllers\HomeController;
use IndieAuthRocks\Engine\TokenVault;
use IndieAuthRocks\Fetch\HttpClient;
use IndieAuthRocks\Fetch\RecordingHttpClient;
use IndieAuthRocks\Fetch\SafeFetcher;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Identity\Sessions;
use IndieAuthRocks\Logging\ErrorLog;
use IndieAuthRocks\ServerTests\ServerRunner;
use IndieAuthRocks\Storage\Database;
use IndieAuthRocks\Storage\ExchangeRepository;
use IndieAuthRocks\Storage\RateLimiter;
use IndieAuthRocks\Storage\RedisStore;
use IndieAuthRocks\Storage\ResultRepository;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\Storage\TesterRepository;
use IndieAuthRocks\View\Template;

/**
 * The wiring. Every service and every route is declared here, explicitly.
 */
final class Bootstrap
{
    public static function container(?SiteConfig $config = null): Container
    {
        $container = new Container();

        $container->set(SiteConfig::class, static fn (): SiteConfig => $config ?? SiteConfig::fromEnvironment());

        $container->set(ErrorLog::class, static fn (): ErrorLog => ErrorLog::fromEnvironment());

        $container->set(Database::class, static function (Container $c): Database {
            $cfg = $c->get(SiteConfig::class);

            return Database::connect($cfg->dbHost, $cfg->dbPort, $cfg->dbName, $cfg->dbUser, $cfg->dbPass);
        });

        $container->set(RedisStore::class, static function (Container $c): RedisStore {
            $cfg = $c->get(SiteConfig::class);

            return RedisStore::connect($cfg->redisHost, $cfg->redisPort, $cfg->redisDb, $cfg->redisPrefix);
        });

        $container->set(Template::class, static fn (): Template => new Template(SiteConfig::root() . '/templates'));

        $container->set(HttpClient::class, static function (Container $c): HttpClient {
            $cfg = $c->get(SiteConfig::class);

            return new SafeFetcher(
                $cfg->allowLoopbackFetch,
                $cfg->allowPrivateFetch,
                $cfg->extraCaFile,
                sprintf('%s (+%s)', $cfg->siteName, $cfg->baseUrl),
            );
        });

        $container->set(RecordingHttpClient::class, static fn (Container $c): RecordingHttpClient => new RecordingHttpClient(
            $c->get(HttpClient::class),
            $c->get(ExchangeRepository::class),
        ));

        foreach ([TesterRepository::class, RunRepository::class, ResultRepository::class, ExchangeRepository::class] as $repository) {
            $container->set($repository, static fn (Container $c): object => new $repository($c->get(Database::class)));
        }

        $container->set(Sessions::class, static fn (Container $c): Sessions => new Sessions(
            $c->get(RedisStore::class),
            $c->get(SiteConfig::class),
        ));

        $container->set(Guard::class, static fn (Container $c): Guard => new Guard(
            $c->get(Sessions::class),
            $c->get(TesterRepository::class),
        ));

        $container->set(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter($c->get(RedisStore::class)));

        $container->set(TokenVault::class, static fn (Container $c): TokenVault => new TokenVault(
            $c->get(RedisStore::class),
            $c->get(SiteConfig::class),
        ));

        $container->set(ServerRunner::class, static fn (Container $c): ServerRunner => new ServerRunner(
            $c->get(SiteConfig::class),
            $c->get(RunRepository::class),
            $c->get(ResultRepository::class),
            $c->get(ExchangeRepository::class),
            $c->get(RecordingHttpClient::class),
            $c->get(TokenVault::class),
            $c->get(TesterRepository::class),
        ));

        // ------------------------------------------------------------ controllers

        foreach ([HomeController::class, CatalogController::class] as $class) {
            $container->set($class, static fn (Container $c): object => new $class(
                $c->get(Template::class),
                $c->get(SiteConfig::class),
                $c->get(Guard::class),
            ));
        }

        $container->set(ServerRunController::class, static fn (Container $c): ServerRunController => new ServerRunController(
            $c->get(Template::class),
            $c->get(SiteConfig::class),
            $c->get(Guard::class),
            $c->get(ServerRunner::class),
            $c->get(RunRepository::class),
            $c->get(ResultRepository::class),
            $c->get(ExchangeRepository::class),
            $c->get(RateLimiter::class),
        ));

        $container->set(AltController::class, static fn (Container $c): AltController => new AltController(
            $c->get(Template::class),
            $c->get(SiteConfig::class),
            $c->get(Guard::class),
            $c->get(ServerRunner::class),
            $c->get(RunRepository::class),
            $c->get(ExchangeRepository::class),
        ));

        $container->set(AccountController::class, static fn (Container $c): AccountController => new AccountController(
            $c->get(Template::class),
            $c->get(SiteConfig::class),
            $c->get(Guard::class),
            $c->get(RunRepository::class),
        ));

        $container->set(ClientDocsController::class, static fn (Container $c): ClientDocsController => new ClientDocsController(
            $c->get(SiteConfig::class),
            $c->get(RunRepository::class),
            $c->get(ExchangeRepository::class),
        ));

        return $container;
    }

    public static function mainRouter(): Router
    {
        $router = new Router();

        $router->get('/', [HomeController::class, 'index']);
        $router->get('/server-tests', [CatalogController::class, 'serverTests']);
        $router->get('/client-tests', [CatalogController::class, 'clientTests']);
        $router->get('/tests/{id}', [CatalogController::class, 'test']);

        $router->get('/account', [AccountController::class, 'show']);
        $router->post('/logout', [AccountController::class, 'logout']);

        // Server test runs
        $router->post('/server-runs', [ServerRunController::class, 'start']);
        $router->get('/runs/{run}', [ServerRunController::class, 'show']);
        $router->get('/runs/{run}/go/{attempt}', [ServerRunController::class, 'go']);
        $router->post('/runs/{run}/answer', [ServerRunController::class, 'answer']);
        $router->post('/runs/{run}/skip', [ServerRunController::class, 'skip']);
        $router->post('/runs/{run}/resume', [ServerRunController::class, 'resume']);
        $router->get('/runs/{run}/tests/{test}', [ServerRunController::class, 'transcript']);

        // The client this site plays: its redirect URI and client_id documents.
        $router->get('/callback/{key}', [ServerRunController::class, 'callback']);
        $router->get('/clients/{key}/{variant}', [ClientDocsController::class, 'document']);

        return $router;
    }

    /** The alternate origin: only what tests need to land on or fetch. */
    public static function altRouter(): Router
    {
        $router = new Router();

        $router->get('/', [HomeController::class, 'alt']);
        $router->get('/catch/{key}/{test}', [AltController::class, 'landing']);
        $router->get('/catch/{key}/{test}/{suffix}', [AltController::class, 'landing']);
        $router->get('/cb/{key}', [AltController::class, 'landing']);
        $router->get('/cb/{key}/{suffix}', [AltController::class, 'landing']);
        $router->get('/stranger/{key}', [AltController::class, 'stranger']);

        return $router;
    }

    public static function fixtureRouter(): Router
    {
        return new Router();
    }

    public static function hostRouter(SiteConfig $config): HostRouter
    {
        $routers = ['main' => self::mainRouter()];

        if ($config->hasDistinctAltOrigin()) {
            $routers['alt'] = self::altRouter();
        }

        if ($config->fixtureBaseUrl !== null) {
            $routers['fixture'] = self::fixtureRouter();
        }

        return new HostRouter($config, $routers);
    }

    public static function kernel(?Container $container = null): Kernel
    {
        $container ??= self::container();
        $config      = $container->get(SiteConfig::class);

        return new Kernel(
            $container,
            self::hostRouter($config),
            $container->get(ErrorLog::class),
            debug: $config->debug,
        );
    }

    public static function loadEnvironment(): void
    {
        Env::load(SiteConfig::root() . '/.env');
    }

    public static function debug(): bool
    {
        return Env::flag('DEBUG');
    }
}

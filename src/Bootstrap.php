<?php

declare(strict_types=1);

namespace IndieAuthRocks;

use IndieAuthRocks\Config\Env;
use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Controllers\AccountController;
use IndieAuthRocks\Controllers\AltController;
use IndieAuthRocks\ClientTests\Markers;
use IndieAuthRocks\ClientTests\TestServer;
use IndieAuthRocks\Checkup\Walkthrough;
use IndieAuthRocks\Controllers\CatalogController;
use IndieAuthRocks\Controllers\ClientRunController;
use IndieAuthRocks\Controllers\FixtureController;
use IndieAuthRocks\Controllers\ReportController;
use IndieAuthRocks\Storage\ReportRepository;
use IndieAuthRocks\Controllers\TestServerController;
use IndieAuthRocks\Controllers\ServerRunController;
use IndieAuthRocks\Controllers\CheckupController;
use IndieAuthRocks\Controllers\ClientDocsController;
use IndieAuthRocks\Controllers\HomeController;
use IndieAuthRocks\Controllers\SignInController;
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

        $container->set(Template::class, static fn (): Template => new Template(SiteConfig::root() . '/templates', SiteConfig::root() . '/public'));

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

        $container->set(SignInController::class, static fn (Container $c): SignInController => new SignInController(
            $c->get(Template::class),
            $c->get(SiteConfig::class),
            $c->get(Guard::class),
            $c->get(HttpClient::class),
            $c->get(RedisStore::class),
            $c->get(TesterRepository::class),
            $c->get(RateLimiter::class),
        ));

        $container->set(Walkthrough::class, static fn (Container $c): Walkthrough => new Walkthrough(
            $c->get(SiteConfig::class),
            $c->get(RecordingHttpClient::class),
            $c->get(RunRepository::class),
            $c->get(RedisStore::class),
        ));

        $container->set(CheckupController::class, static fn (Container $c): CheckupController => new CheckupController(
            $c->get(Template::class),
            $c->get(SiteConfig::class),
            $c->get(Guard::class),
            $c->get(Walkthrough::class),
            $c->get(RunRepository::class),
            $c->get(ExchangeRepository::class),
            $c->get(RateLimiter::class),
        ));

        $container->set(FixtureController::class, static fn (): FixtureController => new FixtureController());

        $container->set(ReportRepository::class, static fn (Container $c): ReportRepository => new ReportRepository($c->get(Database::class)));

        $container->set(ReportController::class, static fn (Container $c): ReportController => new ReportController(
            $c->get(Template::class),
            $c->get(SiteConfig::class),
            $c->get(Guard::class),
            $c->get(ReportRepository::class),
            $c->get(RunRepository::class),
            $c->get(ResultRepository::class),
        ));

        $container->set(Markers::class, static fn (Container $c): Markers => new Markers($c->get(RedisStore::class)));

        $container->set(TestServer::class, static fn (Container $c): TestServer => new TestServer(
            $c->get(SiteConfig::class),
            $c->get(RedisStore::class),
            $c->get(Markers::class),
            $c->get(ResultRepository::class),
            $c->get(RecordingHttpClient::class),
        ));

        $container->set(TestServerController::class, static fn (Container $c): TestServerController => new TestServerController(
            $c->get(Template::class),
            $c->get(SiteConfig::class),
            $c->get(Guard::class),
            $c->get(TestServer::class),
            $c->get(RunRepository::class),
            $c->get(ExchangeRepository::class),
            $c->get(RateLimiter::class),
        ));

        $container->set(ClientRunController::class, static fn (Container $c): ClientRunController => new ClientRunController(
            $c->get(Template::class),
            $c->get(SiteConfig::class),
            $c->get(Guard::class),
            $c->get(TestServer::class),
            $c->get(Markers::class),
            $c->get(RunRepository::class),
            $c->get(ResultRepository::class),
            $c->get(ExchangeRepository::class),
            $c->get(RateLimiter::class),
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

        // Signing in without testing a server
        $router->get('/sign-in', [SignInController::class, 'form']);
        $router->post('/sign-in', [SignInController::class, 'start']);
        $router->get('/sign-in/callback', [SignInController::class, 'callback']);
        $router->get('/sign-in/client', [SignInController::class, 'client']);

        // Checking a login, for the person whose login it is
        $router->get('/check', [CheckupController::class, 'form']);
        $router->post('/check', [CheckupController::class, 'start']);
        $router->get('/check/callback', [CheckupController::class, 'callback']);
        $router->get('/check/client', [CheckupController::class, 'clientDocument']);
        $router->get('/check/{run}', [CheckupController::class, 'show']);
        $router->get('/check/{run}/go', [CheckupController::class, 'go']);
        $router->post('/check/{run}/delete', [CheckupController::class, 'delete']);

        $router->get('/account', [AccountController::class, 'show']);
        $router->post('/logout', [AccountController::class, 'logout']);

        // Server test runs
        $router->post('/server-runs', [ServerRunController::class, 'start']);
        $router->get('/runs/{run}', [ServerRunController::class, 'show']);
        $router->post('/runs/{run}/start', [ServerRunController::class, 'startStep']);
        $router->get('/runs/{run}/step', [ServerRunController::class, 'step']);
        $router->get('/runs/{run}/go/{attempt}', [ServerRunController::class, 'go']);
        $router->post('/runs/{run}/answer', [ServerRunController::class, 'answer']);
        $router->post('/runs/{run}/skip', [ServerRunController::class, 'skip']);
        $router->post('/runs/{run}/resume', [ServerRunController::class, 'resume']);
        $router->post('/runs/{run}/restart', [ServerRunController::class, 'restart']);
        $router->get('/runs/{run}/tests/{test}', [ServerRunController::class, 'transcript']);
        $router->post('/runs/{run}/tests/{test}/rerun', [ServerRunController::class, 'rerun']);

        // The client this site plays: its redirect URI and client_id documents.
        $router->get('/callback/{key}', [ServerRunController::class, 'callback']);
        $router->get('/clients/{key}/{variant}', [ClientDocsController::class, 'document']);

        // Implementation reports
        $router->get('/implementations', [ReportController::class, 'index']);
        $router->get('/implementations/{id}', [ReportController::class, 'show']);
        $router->post('/implementations/{id}/delete', [ReportController::class, 'delete']);
        $router->post('/runs/{run}/publish', [ReportController::class, 'publish']);
        $router->post('/client-runs/{run}/publish', [ReportController::class, 'publish']);

        // Client test runs
        $router->get('/client-runs', [ClientRunController::class, 'index']);
        $router->post('/client-runs', [ClientRunController::class, 'start']);
        $router->get('/client-runs/{run}', [ClientRunController::class, 'show']);
        $router->get('/client-runs/{run}/unsolicited', [ClientRunController::class, 'unsolicited']);
        $router->get('/client-runs/{run}/replay', [ClientRunController::class, 'replay']);
        $router->get('/client-runs/{run}/tests/{id}', [ClientRunController::class, 'test']);
        $router->post('/client-runs/{run}/tests/{id}/answer', [ClientRunController::class, 'answer']);

        // Test profiles and the test IndieAuth server
        self::testServerRoutes($router);
        $router->get('/u/{key}/m/{n}', [TestServerController::class, 'relativeMetadata']);
        $router->get('/u/{key}/{n}/m/{x}', [TestServerController::class, 'naiveMetadata']);
        $router->get('/s/{key}/{n}/metadata', [TestServerController::class, 'metadata']);
        $router->add(['OPTIONS'], '/s/{key}/{n}/metadata', [TestServerController::class, 'preflight']);
        $router->get('/s/{key}/{n}/auth', [TestServerController::class, 'authorize']);
        $router->post('/s/{key}/{n}/auth', [TestServerController::class, 'redeemAtAuthorizationEndpoint']);
        $router->add(['OPTIONS'], '/s/{key}/{n}/auth', [TestServerController::class, 'preflight']);
        $router->post('/s/{key}/{n}/approve', [TestServerController::class, 'approve']);
        $router->post('/s/{key}/{n}/revoke', [TestServerController::class, 'revoke']);
        $router->add(['OPTIONS'], '/s/{key}/{n}/revoke', [TestServerController::class, 'preflight']);
        $router->any('/s/{key}/{n}/userinfo', [TestServerController::class, 'userinfo']);
        $router->add(['OPTIONS'], '/s/{key}/{n}/userinfo', [TestServerController::class, 'preflight']);

        return $router;
    }

    /** Routes the test server answers on both origins. */
    private static function testServerRoutes(Router $router): void
    {
        $router->get('/u/{key}/{n}', [TestServerController::class, 'profile']);
        $router->post('/s/{key}/{n}/token', [TestServerController::class, 'token']);
        $router->add(['OPTIONS'], '/s/{key}/{n}/token', [TestServerController::class, 'preflight']);
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
        self::testServerRoutes($router);

        return $router;
    }

    public static function fixtureRouter(): Router
    {
        $router = new Router();

        $router->get('/', [FixtureController::class, 'home']);

        return $router;
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

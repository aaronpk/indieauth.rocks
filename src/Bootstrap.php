<?php

declare(strict_types=1);

namespace IndieAuthRocks;

use IndieAuthRocks\Config\Env;
use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Controllers\CatalogController;
use IndieAuthRocks\Controllers\HomeController;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Identity\Sessions;
use IndieAuthRocks\Logging\ErrorLog;
use IndieAuthRocks\Storage\Database;
use IndieAuthRocks\Storage\RateLimiter;
use IndieAuthRocks\Storage\RedisStore;
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

        $container->set(TesterRepository::class, static fn (Container $c): TesterRepository => new TesterRepository($c->get(Database::class)));

        $container->set(Sessions::class, static fn (Container $c): Sessions => new Sessions(
            $c->get(RedisStore::class),
            $c->get(SiteConfig::class),
        ));

        $container->set(Guard::class, static fn (Container $c): Guard => new Guard(
            $c->get(Sessions::class),
            $c->get(TesterRepository::class),
        ));

        $container->set(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter($c->get(RedisStore::class)));

        // ------------------------------------------------------------ controllers

        foreach ([HomeController::class, CatalogController::class] as $class) {
            $container->set($class, static fn (Container $c): object => new $class(
                $c->get(Template::class),
                $c->get(SiteConfig::class),
                $c->get(Guard::class),
            ));
        }

        return $container;
    }

    public static function mainRouter(): Router
    {
        $router = new Router();

        $router->get('/', [HomeController::class, 'index']);
        $router->get('/server-tests', [CatalogController::class, 'serverTests']);
        $router->get('/client-tests', [CatalogController::class, 'clientTests']);
        $router->get('/tests/{id}', [CatalogController::class, 'test']);

        return $router;
    }

    /** The alternate origin: only what tests need to land on or fetch. */
    public static function altRouter(): Router
    {
        $router = new Router();

        $router->get('/', [HomeController::class, 'alt']);

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

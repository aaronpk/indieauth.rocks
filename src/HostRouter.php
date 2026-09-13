<?php

declare(strict_types=1);

namespace IndieAuthRocks;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Http\HttpException;

/**
 * Picks the route table for the hostname a request arrived on. The main site,
 * the alternate origin and the development fixture host share one app but
 * expose different routes.
 */
final class HostRouter
{
    /** @param array<string, Router> $routers Keyed by host role. */
    public function __construct(
        private readonly SiteConfig $config,
        private readonly array $routers,
    ) {
    }

    public function match(string $host, string $method, string $path): RouteMatch
    {
        $role = $this->config->hostRole($host);

        if ($role === null || !isset($this->routers[$role])) {
            throw HttpException::notFound();
        }

        return $this->routers[$role]->match($method, $path);
    }

    /** @return array<string, Router> */
    public function routers(): array
    {
        return $this->routers;
    }
}

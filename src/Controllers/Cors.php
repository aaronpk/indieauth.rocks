<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Http\Response;

/**
 * CORS for the endpoints browser-based clients call directly. These use no
 * cookies, so allowing any origin exposes nothing a server-side client could
 * not already fetch.
 */
final class Cors
{
    public static function allow(Response $response): Response
    {
        return $response->withHeader('access-control-allow-origin', '*');
    }

    public static function preflight(): Response
    {
        return Response::noContent()
            ->withHeader('access-control-allow-origin', '*')
            ->withHeader('access-control-allow-methods', 'GET, POST, OPTIONS')
            ->withHeader('access-control-allow-headers', 'Authorization, Content-Type, Accept')
            ->withHeader('access-control-max-age', '86400');
    }
}

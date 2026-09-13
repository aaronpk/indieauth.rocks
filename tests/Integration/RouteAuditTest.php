<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Bootstrap;
use IndieAuthRocks\Identity\Session;
use IndieAuthRocks\Identity\Sessions;
use IndieAuthRocks\Storage\TesterRepository;
use IndieAuthRocks\Tests\Support\AppTestCase;

/** Every state-changing form route rejects a POST without a CSRF token. */
final class RouteAuditTest extends AppTestCase
{
    /** Protocol endpoints called by other software, which have no browser session. */
    private const PROTOCOL = [
        '/s/{key}/{n}/auth', '/s/{key}/{n}/token', '/s/{key}/{n}/revoke', '/s/{key}/{n}/userinfo',
    ];

    public function test_every_form_post_requires_csrf(): void
    {
        $tester  = $this->container->get(TesterRepository::class)->signIn('https://tester.example/');
        $session = $this->container->get(Sessions::class)->promote(Session::createAnonymous(), (string) $tester->id, ['indieauth']);
        $this->cookies = [Sessions::COOKIE => $session->id];

        $checked = 0;

        foreach (Bootstrap::hostRouter($this->config())->routers() as $role => $router) {
            $base = $role === 'alt' ? self::ALT : self::BASE;

            foreach ($router->routes() as $route) {
                if (!in_array('POST', $route['methods'], true) || in_array($route['pattern'], self::PROTOCOL, true)) {
                    continue;
                }

                $path = strtr($route['pattern'], [
                    '{run}'  => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                    '{key}'  => 'abcdefghijklmnop',
                    '{n}'    => '101',
                    '{id}'   => '1',
                ]);

                $response = $this->request('POST', $base . ltrim($path, '/'), ['anything' => '1']);

                $this->assertContains($response->status, [400, 403], sprintf('POST %s accepted a request without a CSRF token (HTTP %d).', $route['pattern'], $response->status));
                $checked++;
            }
        }

        $this->assertGreaterThan(5, $checked);
    }
}

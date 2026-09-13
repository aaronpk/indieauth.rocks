<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Identity\Visitor;
use IndieAuthRocks\View\Template;

abstract class Controller
{
    public function __construct(
        protected readonly Template $view,
        protected readonly SiteConfig $config,
        protected readonly Guard $guard,
    ) {
    }

    /**
     * Render a page template inside the shared layout.
     *
     * Only pass arrays and scalars in $vars: the template layer escapes strings
     * recursively, but objects pass through untouched.
     *
     * @param array<string, mixed> $vars
     */
    protected function page(
        string $template,
        string $title,
        array $vars = [],
        int $status = 200,
        ?Visitor $visitor = null,
    ): Response {
        $shared = [
            'site_name' => $this->config->siteName,
            'home_url'  => $this->config->baseUrl,
            'nav_user'  => $visitor?->user?->displayName(),
            'nav_csrf'  => $visitor?->user !== null ? $visitor->session?->csrf : null,
        ];

        return Response::html($this->view->render('layout', $shared + [
            'title'   => $title,
            'content' => $this->view->partial($template, $vars + $shared),
            'script'  => isset($vars['script']) && is_string($vars['script']) ? $vars['script'] : null,
            'wide'    => (bool) ($vars['wide'] ?? false),
        ]), $status);
    }

    /** @param array<string, string> $params */
    protected static function param(array $params, string $name, string $pattern): string
    {
        $value = $params[$name] ?? '';

        if (preg_match($pattern, $value) !== 1) {
            throw HttpException::notFound();
        }

        return $value;
    }
}

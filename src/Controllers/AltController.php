<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\ServerTests\ServerRunner;
use IndieAuthRocks\Storage\ExchangeRepository;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\View\Template;

/**
 * Addresses on the second origin. A server that sends a browser to /catch/…
 * has redirected somewhere that was never registered.
 */
final class AltController extends Controller
{
    public function __construct(
        Template $view,
        SiteConfig $config,
        Guard $guard,
        private readonly ServerRunner $runner,
        private readonly RunRepository $runs,
        private readonly ExchangeRepository $exchanges,
    ) {
        parent::__construct($view, $config, $guard);
    }

    /** @param array<string, string> $params */
    public function landing(Request $request, array $params = []): Response
    {
        $key = $params['key'] ?? '';
        $run = preg_match('/^[0-9a-z]{16}$/', $key) === 1 ? $this->runs->findByKey($key) : null;

        if ($run === null) {
            throw HttpException::notFound();
        }

        $this->exchanges->recordInbound($run->id, 'landing', $request);

        $url    = $this->config->altUrl(ltrim($request->path, '/')) . ($request->queryString ? '?' . $request->queryString : '');
        $result = $this->runner->land($run, $url);
        $caught = str_starts_with(ltrim($request->path, '/'), 'catch/') || substr_count(trim($request->path, '/'), '/') >= 2;

        return $this->page('landing', $caught ? 'Unregistered redirect' : 'Back to your test run', [
            'caught'  => $caught,
            'handled' => $result['handled'],
            'message' => $result['message'],
            'run_url' => $this->config->url('runs/' . $run->publicId),
        ])->withNoStore();
    }

    /**
     * A profile URL on the other origin that belongs to nobody and declares
     * no authorization server.
     *
     * @param array<string, string> $params
     */
    public function stranger(Request $request, array $params = []): Response
    {
        return $this->page('message', 'Nobody', [
            'heading' => 'Nobody lives here',
            'message' => 'This page is used by tests as someone else\'s profile URL. It does not declare any IndieAuth server.',
        ]);
    }
}

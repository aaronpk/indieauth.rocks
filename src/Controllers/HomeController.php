<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;

final class HomeController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->begin($request);

        return $this->guard->commit($this->page('home', $this->config->siteName, [
            'csrf' => $visitor->session()->csrf,
            'wide' => true,
        ], visitor: $visitor), $visitor);
    }

    /** @param array<string, string> $params */
    public function micropub(Request $request, array $params = []): Response
    {
        return Response::json([
            'syndicate-to' => [],
            'categories'   => [],
            'channels'     => [],
            'post-types'   => [],
        ]);
    }

    /**
     * The root of the alternate origin. Nothing lives here; tests only use
     * specific paths on this host.
     *
     * @param array<string, string> $params
     */
    public function alt(Request $request, array $params = []): Response
    {
        return $this->page('message', $this->config->siteName, [
            'heading'    => 'This host is part of ' . $this->config->siteName,
            'message'    => 'Tests use this second origin to check how servers and clients handle other domains. There is nothing to see here.',
            'link'       => $this->config->baseUrl,
            'link_label' => 'Go to ' . $this->config->siteName,
        ]);
    }
}

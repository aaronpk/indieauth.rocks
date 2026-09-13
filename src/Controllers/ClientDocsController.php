<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\ServerTests\ClientUrls;
use IndieAuthRocks\Storage\ExchangeRepository;
use IndieAuthRocks\Storage\RunRepository;

/**
 * Client ID Metadata Documents for the client this site plays during a server
 * run. Every fetch is recorded, which is how S205 knows the server looked.
 */
final class ClientDocsController
{
    public function __construct(
        private readonly SiteConfig $config,
        private readonly RunRepository $runs,
        private readonly ExchangeRepository $exchanges,
    ) {
    }

    /** @param array<string, string> $params */
    public function document(Request $request, array $params = []): Response
    {
        $key = $params['key'] ?? '';
        $run = preg_match('/^[0-9a-z]{16}$/', $key) === 1 ? $this->runs->findByKey($key) : null;

        if ($run === null) {
            throw HttpException::notFound();
        }

        $variant  = (string) ($params['variant'] ?? '');
        $document = (new ClientUrls($this->config, $run->runKey))->document($variant);

        $this->exchanges->recordInbound($run->id, 'client:' . $variant, $request, $document === null ? 404 : 200);

        if ($document === null) {
            throw HttpException::notFound();
        }

        return Cors::allow(Response::json($document))->withHeader('cache-control', 'no-cache');
    }
}

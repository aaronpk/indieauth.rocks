<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

use IndieAuthRocks\Config\SiteConfig;

/**
 * The URLs this site uses as a client during one server run. Every URL
 * contains the run key, so a server's cache of client metadata from another
 * run cannot hide whether it fetched this one, and landings find their run.
 */
final class ClientUrls
{
    public const MAIN     = 'main';
    public const CROSS    = 'cross';
    public const MISMATCH = 'mismatch';

    public function __construct(
        private readonly SiteConfig $config,
        private readonly string $runKey,
    ) {
    }

    public function clientId(string $variant = self::MAIN): string
    {
        return $this->config->url('clients/' . $this->runKey . '/' . $variant);
    }

    /** A client_id that no metadata is published for, used for "wrong client" checks. */
    public function otherClientId(): string
    {
        return $this->config->url('clients/' . $this->runKey . '/other');
    }

    public function callback(bool $withQuery = false): string
    {
        return $this->config->url('callback/' . $this->runKey) . ($withQuery ? '?keep=1' : '');
    }

    /** A URL on the other origin that is never registered. */
    public function catch(string $testId): string
    {
        return $this->config->altUrl('catch/' . $this->runKey . '/' . $testId);
    }

    /** A URL on the other origin that is registered by the cross-origin client. */
    public function registeredCrossOrigin(): string
    {
        return $this->config->altUrl('cb/' . $this->runKey);
    }

    public function registeredCrossOriginWithSuffix(string $suffix): string
    {
        return $this->registeredCrossOrigin() . '/' . $suffix;
    }

    /**
     * https://main-host@alt-host/…: looks like it belongs to the client's host
     * to a careless parser, but actually points at the other origin.
     */
    public function userinfoTrick(string $testId): string
    {
        $main = (string) parse_url($this->config->baseUrl, PHP_URL_HOST);
        $port = parse_url($this->config->baseUrl, PHP_URL_PORT);
        $alt  = $this->config->altUrl('catch/' . $this->runKey . '/' . $testId);

        return (string) preg_replace('#^(https?://)#', '$1' . $main . ($port !== null ? ':' . $port : '') . '@', $alt);
    }

    /** A profile URL on the other origin that is nobody's. */
    public function strangerProfile(): string
    {
        return $this->config->altUrl('stranger/' . $this->runKey);
    }

    /**
     * The client metadata document for a variant, or null for unknown ones.
     *
     * @return array<string, mixed>|null
     */
    public function document(string $variant): ?array
    {
        $base = [
            'client_name' => $this->config->siteName . ' test client',
            'client_uri'  => $this->config->baseUrl,
        ];

        return match ($variant) {
            self::MAIN => ['client_id' => $this->clientId(self::MAIN)] + $base + [
                'redirect_uris' => [$this->callback(), $this->callback(true)],
            ],
            self::CROSS => ['client_id' => $this->clientId(self::CROSS)] + $base + [
                'redirect_uris' => [$this->callback(), $this->registeredCrossOrigin()],
            ],
            // Declares a different client_id from the URL it is served at, so a
            // server must ignore it — including the unregistered URI it lists.
            self::MISMATCH => ['client_id' => $this->otherClientId()] + $base + [
                'redirect_uris' => [$this->catch('S212')],
            ],
            default => null,
        };
    }
}

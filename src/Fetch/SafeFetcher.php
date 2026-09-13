<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

use CurlHandle;

/**
 * An HTTP GET hardened for fetching user- and client-supplied URLs.
 *
 *  1. Resolve DNS first, refuse special-use addresses, then pin the connection
 *     to the vetted address with CURLOPT_RESOLVE, closing the DNS-rebinding
 *     window between checking a name and connecting to it.
 *  2. Never follow redirects here. PageFetcher follows them one hop at a time,
 *     so every hop goes through defence 1 again.
 *  3. Cap the response during transfer, not after.
 *  4. Short timeouts, so a slow endpoint cannot tie up a worker.
 */
final class SafeFetcher implements HttpClient
{
    private const CONNECT_TIMEOUT = 4;
    private const TOTAL_TIMEOUT   = 8;

    private readonly IpGuard $guard;

    public function __construct(
        bool $allowLoopback = false,
        private readonly string $userAgent = 'IndieAuth',
    ) {
        $this->guard = new IpGuard($allowLoopback);
    }

    public function get(string $url, string $accept, int $maxBytes): FetchResult
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'], $parts['scheme'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw FetchException::refused('The URL could not be parsed.');
        }

        $host   = $parts['host'];
        $scheme = strtolower($parts['scheme']);
        $port   = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $address = $this->resolveAndVet($host);

        $body     = '';
        $exceeded = false;
        $headers  = [];

        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL             => $url,
            CURLOPT_HTTPGET         => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT         => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_HTTPHEADER      => ['Accept: ' . $accept, 'Expect:'],
            CURLOPT_USERAGENT       => $this->userAgent,
            CURLOPT_RESOLVE         => [sprintf('%s:%d:%s', trim($host, '[]'), $port, $address)],
            CURLOPT_HEADERFUNCTION  => static function (CurlHandle $h, string $line) use (&$headers): int {
                // A new status line starts a new header block (e.g. after 100 Continue).
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))][] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION   => static function (CurlHandle $h, string $chunk) use (&$body, &$exceeded, $maxBytes): int {
                $body .= $chunk;

                if (strlen($body) > $maxBytes) {
                    $exceeded = true;

                    return 0;
                }

                return strlen($chunk);
            },
        ]);

        $ok     = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);

        curl_close($handle);

        if ($exceeded) {
            throw FetchException::refused(sprintf('The response is larger than the %d byte limit.', $maxBytes));
        }

        if ($ok === false || $status === 0) {
            throw FetchException::transient('The request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        return new FetchResult($url, $status, $body, $headers);
    }

    /**
     * Resolve a hostname and refuse it unless every answer is acceptable. A host
     * that resolves to both a public and a private address is being used to
     * smuggle access to the private one.
     *
     * @throws FetchException
     */
    private function resolveAndVet(string $host): string
    {
        $host = trim($host, '[]');

        $packed = @inet_pton($host);
        if ($packed !== false) {
            if (!$this->guard->isAllowed($host)) {
                throw FetchException::refused($this->guard->reason($host));
            }

            return (string) inet_ntop($packed);
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            throw FetchException::transient(sprintf('"%s" could not be resolved.', $host));
        }

        foreach ($addresses as $address) {
            if (!$this->guard->isAllowed($address)) {
                throw FetchException::refused(sprintf(
                    '"%s" resolves to %s, and %s',
                    $host,
                    $address,
                    $this->guard->reason($address),
                ));
            }
        }

        return $addresses[0];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $addresses = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $address) {
                $addresses[] = $address;
            }
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

use CurlHandle;

/**
 * HTTP requests hardened for URLs supplied by the people running tests.
 *
 *  1. Resolve DNS first, refuse special-use addresses, then pin the connection
 *     to the vetted address with CURLOPT_RESOLVE, closing the DNS-rebinding
 *     window between checking a name and connecting to it.
 *  2. Never follow redirects. Callers follow them one hop at a time, so every
 *     hop is vetted again, and a redirect is itself something tests look at.
 *  3. Cap the response during transfer, not after.
 *  4. Short timeouts.
 */
final class SafeFetcher implements HttpClient
{
    private const CONNECT_TIMEOUT = 5;

    private readonly IpGuard $guard;
    private readonly bool $anyPort;

    public function __construct(
        bool $allowLoopback = false,
        bool $allowPrivate = false,
        private readonly ?string $caFile = null,
        private readonly string $userAgent = 'IndieAuth Rocks',
    ) {
        $this->guard   = new IpGuard($allowLoopback, $allowPrivate);
        $this->anyPort = $allowLoopback || $allowPrivate;
    }

    public function send(HttpRequest $request): FetchResult
    {
        $parts = parse_url($request->url);

        if ($parts === false || !isset($parts['host'], $parts['scheme'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw FetchException::refused('The URL could not be parsed.');
        }

        $host   = $parts['host'];
        $scheme = strtolower($parts['scheme']);
        $port   = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        // curl converts internationalised names itself, so its host would not
        // match the CURLOPT_RESOLVE pin and it would do its own DNS lookup.
        if (preg_match('/[^\x21-\x7e]/', $host) === 1) {
            throw FetchException::refused('Internationalised hostnames must be written in their xn-- (punycode) form.');
        }

        // Web servers, not other services that happen to listen on a host.
        if (!$this->anyPort && $port < 1024 && !in_array($port, [80, 443], true)) {
            throw FetchException::refused(sprintf('Port %d is not allowed; use 80, 443 or a port above 1023.', $port));
        }

        $address = $this->resolveAndVet($host);

        $body     = '';
        $exceeded = false;
        $headers  = [];
        $maxBytes = $request->maxBytes;

        $headerLines = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init();

        $options = [
            CURLOPT_URL             => $request->url,
            CURLOPT_CUSTOMREQUEST   => $request->method,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT         => $request->timeout,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_HTTPHEADER      => $headerLines,
            CURLOPT_USERAGENT       => $this->userAgent,
            CURLOPT_RESOLVE         => [sprintf('%s:%d:%s', trim($host, '[]'), $port, $address)],
            CURLOPT_HEADERFUNCTION  => static function (CurlHandle $h, string $line) use (&$headers): int {
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
        ];

        if ($request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        if ($this->caFile !== null && is_file($this->caFile)) {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }

        curl_setopt_array($handle, $options);

        $started = hrtime(true);
        $ok      = curl_exec($handle);
        $status  = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error   = curl_error($handle);
        $elapsed = (int) ((hrtime(true) - $started) / 1_000_000);

        curl_close($handle);

        if ($exceeded) {
            throw FetchException::refused(sprintf('The response is larger than the %d byte limit.', $maxBytes));
        }

        if ($ok === false || $status === 0) {
            throw FetchException::transient('The request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        return new FetchResult(
            url:            $request->url,
            status:         $status,
            body:           $body,
            headers:        $headers,
            method:         $request->method,
            requestHeaders: $request->headers,
            requestBody:    $request->body,
            durationMs:     $elapsed,
        );
    }

    /** @throws FetchException */
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
                throw FetchException::refused(sprintf('"%s" resolves to %s, and %s', $host, $address, $this->guard->reason($address)));
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

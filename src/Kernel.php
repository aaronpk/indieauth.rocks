<?php

declare(strict_types=1);

namespace IndieAuthRocks;

use Throwable;
use IndieAuthRocks\Config\ConfigurationException;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Logging\ErrorLog;
use IndieAuthRocks\Storage\StorageUnavailableException;
use IndieAuthRocks\View\Template;

/**
 * Turns a Request into a Response: match a route, resolve the controller,
 * invoke it, and convert anything thrown into an error page.
 *
 * Nothing is swallowed. Every unexpected throwable is written to the log with a
 * stack trace and a short request identifier that also appears on the error
 * page, so a report of "I got a 500" can be tied to one entry.
 */
final class Kernel
{
    public function __construct(
        private readonly Container $container,
        private readonly HostRouter $router,
        private readonly ErrorLog $log,
        private readonly bool $debug = false,
    ) {
    }

    public function handle(Request $request): Response
    {
        $requestId = substr(bin2hex(random_bytes(5)), 0, 8);
        $summary   = $request->method . ' ' . $request->path;

        try {
            $match = $this->router->match($request->host, $request->method, $request->path);

            [$class, $method] = $match->handler;
            $controller       = $this->container->get($class);

            /** @var Response $response */
            $response = $controller->{$method}($request, $match->params);

            return $this->withSecurityHeaders($response);
        } catch (HttpException $e) {
            // 404 and 405 are ordinary internet noise; anything else in the 4xx
            // range is worth a line, because it usually means a real client is
            // getting something wrong.
            if (!in_array($e->status, [404, 405], true)) {
                $this->log->warning(sprintf('%d %s', $e->status, $e->getMessage()), $requestId, $summary);
            }

            $response = $this->errorResponse($request, $e->status, $e->getMessage(), null);
            foreach ($e->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $this->withSecurityHeaders($response);
        } catch (ConfigurationException $e) {
            $this->log->write('ERROR', $e->logMessage(), $requestId, $summary, $e);

            // The message names the fix, so it is shown rather than hidden
            // behind "something went wrong".
            return $this->withSecurityHeaders(
                $this->errorResponse($request, 500, $e->getMessage(), $requestId),
            );
        } catch (StorageUnavailableException $e) {
            $this->log->exception($e, $requestId, $summary);

            // 503, not 500: nothing is wrong with the request, a dependency is
            // down. Naming Redis makes this fixable without reading the log.
            return $this->withSecurityHeaders(
                $this->errorResponse(
                    $request,
                    503,
                    'A storage service (MySQL or Redis) is unavailable. Try again shortly.',
                    $requestId,
                )->withHeader('retry-after', '10'),
            );
        } catch (Throwable $e) {
            $this->log->exception($e, $requestId, $summary);

            $message = $this->debug
                ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine())
                : 'Something went wrong.';

            return $this->withSecurityHeaders($this->errorResponse($request, 500, $message, $requestId));
        }
    }

    private function errorResponse(Request $request, int $status, string $message, ?string $requestId): Response
    {
        if ($request->wantsJson()) {
            $body = ['error' => 'request_failed', 'error_description' => $message];
            if ($requestId !== null) {
                $body['request_id'] = $requestId;
            }

            return Response::json($body, $status);
        }

        try {
            $body = $this->container->get(Template::class)->render('error', [
                'status'     => $status,
                'message'    => $message,
                'request_id' => $requestId,
                'log_path'   => $this->debug ? $this->log->path() : null,
            ]);

            return Response::html($body, $status);
        } catch (Throwable $e) {
            // Rendering the error page failed too. Log that separately and fall
            // back to plain text rather than recursing.
            $this->log->exception($e, $requestId, 'rendering error page');

            return Response::text(
                $status . ' ' . $message . ($requestId === null ? '' : "\n\nReference: " . $requestId),
                $status,
            );
        }
    }

    /**
     * The form-action source list for one response.
     *
     * `'self'` alone is wrong for an authorization server: the last hop of every
     * flow is a form submission that redirects to a client's redirect URI, which is
     * cross-origin by definition. WebKit and Chromium check `form-action` against a
     * navigation's whole redirect chain, so that hop is blocked — which is what
     * "Sending form data to …/consent violates … form-action 'self'" is reporting.
     *
     * Pages that are part of a flow therefore declare the client origins they may
     * reach, always from stored client configuration rather than from the request.
     * Every other page keeps the bare `'self'`.
     */
    private static function formAction(Response $response): string
    {
        return implode(' ', ["'self'", ...$response->formActionOrigins()]);
    }

    private function withSecurityHeaders(Response $response): Response
    {
        $response = $response
            ->withHeader('x-content-type-options', 'nosniff')
            ->withHeader('referrer-policy', 'no-referrer')
            ->withHeader('x-frame-options', 'DENY');

        if (str_starts_with((string) $response->header('content-type'), 'text/html')) {
            $response = $response->withHeader(
                'content-security-policy',
                // No inline script. Styles are a single stylesheet; images allow
                // data: for locally cached client logos.
                //
                // connect-src 'self' is required, not optional: the passkey
                // ceremonies fetch() their own endpoints, and with default-src
                // 'none' as the fallback a missing connect-src blocks every
                // same-origin request the browser makes.
                "default-src 'none'; script-src 'self'; style-src 'self'; "
                . "img-src 'self' data: https:; connect-src 'self'; "
                . 'form-action ' . self::formAction($response) . '; '
                . "frame-ancestors 'none'; base-uri 'none'",
            );
        }

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\ClientTests\Scenario;
use IndieAuthRocks\ClientTests\TestServer;
use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Http\Url;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Storage\ExchangeRepository;
use IndieAuthRocks\Storage\RateLimiter;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\View\Template;

/** Profile pages and the IndieAuth server that client tests sign in with. */
final class TestServerController extends Controller
{
    public function __construct(
        Template $view,
        SiteConfig $config,
        Guard $guard,
        private readonly TestServer $server,
        private readonly RunRepository $runs,
        private readonly ExchangeRepository $exchanges,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $config, $guard);
    }

    /** @param array<string, string> $params */
    public function profile(Request $request, array $params = []): Response
    {
        [$run, $n, $decoy, $variant] = $this->resolve($request, $params);

        return $this->server->profile($run, $n, $variant, $this->config->hostRole($request->host) === 'alt');
    }

    /** @param array<string, string> $params */
    public function relativeMetadata(Request $request, array $params = []): Response
    {
        [$run, $n] = $this->resolve($request, $params);

        return $this->server->relativeMetadata($run, $n);
    }

    /** @param array<string, string> $params */
    public function naiveMetadata(Request $request, array $params = []): Response
    {
        [$run, $n] = $this->resolve($request, $params);

        return $this->server->naiveMetadata($run, $n);
    }

    /** @param array<string, string> $params */
    public function metadata(Request $request, array $params = []): Response
    {
        [$run, $n, $decoy] = $this->resolve($request, $params);

        return $this->server->metadata($run, $n, $decoy);
    }

    /** @param array<string, string> $params */
    public function authorize(Request $request, array $params = []): Response
    {
        [$run, $n, $decoy] = $this->resolve($request, $params);

        $result  = $this->server->authorize($run, $n, $decoy, $request);
        $visitor = $this->guard->begin($request);

        if ($result['attempt'] === null) {
            return $this->guard->commit($this->page('testserver/error', 'Test server: request refused', [
                'test_id' => 'C' . $n,
                'errors'  => $result['errors'],
            ], 400, $visitor)->withNoStore(), $visitor);
        }

        $redirect = (string) $result['params']['redirect_uri'];

        return $this->guard->commit($this->page('testserver/consent', 'Test server: approve sign-in', [
            'test_id'      => 'C' . $n,
            'test_title'   => (string) Catalog::find('C' . $n)?->title,
            'decoy'        => $decoy,
            'attempt'      => $result['attempt'],
            'csrf'         => $visitor->session()->csrf,
            'client_id'    => (string) ($result['params']['client_id'] ?? ''),
            'redirect_uri' => $redirect,
            'scope'        => (string) ($result['params']['scope'] ?? ''),
            'me'           => (string) ($result['params']['me'] ?? ''),
            'warnings'     => $result['warnings'],
            'owner'        => $visitor->user !== null && $visitor->user->id === $run->testerId,
            'action'       => $this->server->urls($run)->approve($n, $decoy),
        ], visitor: $visitor)->allowingFormActionTo((string) Url::origin($redirect))->withNoStore(), $visitor);
    }

    /** @param array<string, string> $params */
    public function approve(Request $request, array $params = []): Response
    {
        [$run, $n, $decoy] = $this->resolve($request, $params);

        $visitor = $this->guard->begin($request);
        $this->guard->requireCsrf($visitor, $request);

        $attempt = $this->server->attempt($run, $n, (string) $request->post('attempt', ''));

        if ($attempt === null) {
            throw HttpException::badRequest('This sign-in request has expired. Start again from your client.');
        }

        $owner = $visitor->user !== null && $visitor->user->id === $run->testerId;
        $pin   = (string) ($run->state['pin'] ?? '');

        if (!$owner && ($pin === '' || !hash_equals($pin, trim((string) $request->post('pin', ''))))) {
            throw HttpException::forbidden('Enter the PIN shown on your client test run page to approve this test sign-in.');
        }

        $url = $this->server->approve($run, $n, $attempt, $request->post('action') === 'approve');

        return $this->guard->commit(Response::seeOther($url), $visitor);
    }

    /** @param array<string, string> $params */
    public function redeemAtAuthorizationEndpoint(Request $request, array $params = []): Response
    {
        [$run, $n, $decoy] = $this->resolve($request, $params);

        return $this->server->redeem($run, $n, $decoy, $request, false);
    }

    /** @param array<string, string> $params */
    public function token(Request $request, array $params = []): Response
    {
        [$run, $n, $decoy] = $this->resolve($request, $params);

        return $this->server->redeem($run, $n, $decoy, $request, true);
    }

    /** @param array<string, string> $params */
    public function revoke(Request $request, array $params = []): Response
    {
        [$run, $n] = $this->resolve($request, $params);

        return $this->server->revoke($run, $n, $request);
    }

    /** @param array<string, string> $params */
    public function userinfo(Request $request, array $params = []): Response
    {
        [$run, $n] = $this->resolve($request, $params);

        return $this->server->userinfo($run, $n, $request);
    }

    /** @param array<string, string> $params */
    public function preflight(Request $request, array $params = []): Response
    {
        return Cors::preflight();
    }

    /**
     * @param array<string, string> $params
     * @return array{0: Run, 1: int, 2: bool, 3: string} run, test number, decoy, page variant
     */
    private function resolve(Request $request, array $params): array
    {
        $key = $params['key'] ?? '';
        $run = preg_match('/^[0-9a-z]{16}$/', $key) === 1 ? $this->runs->findByKey($key) : null;

        if ($run === null || $run->kind !== Catalog::CLIENT
            || preg_match('/^(\d{3})(d?)(?:-(moved|final|other))?$/', $params['n'] ?? '', $m) !== 1) {
            throw HttpException::notFound();
        }

        $n = (int) $m[1];

        if (!Scenario::exists($n)) {
            throw HttpException::notFound();
        }

        // A client under test makes a handful of requests per sign-in; this only
        // stops the test server being used as a free endpoint for anything else.
        if ($this->limiter->hit('client-run', $run->runKey, 600) > 2000) {
            throw new HttpException(429, 'Too many requests for this client test run.');
        }

        $this->exchanges->recordInbound($run->id, 'C' . $n . ($m[2] === 'd' ? 'd' : ''), $request);

        return [$run, $n, $m[2] === 'd', $m[3] ?? ''];
    }
}

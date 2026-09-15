<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Crypto\Random;
use IndieAuthRocks\Fetch\HttpClient;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Identity\Visitor;
use IndieAuthRocks\IndieAuth\Discovery;
use IndieAuthRocks\IndieAuth\MeVerifier;
use IndieAuthRocks\IndieAuth\Pkce;
use IndieAuthRocks\ServerTests\TokenClient;
use IndieAuthRocks\Storage\RateLimiter;
use IndieAuthRocks\Storage\RedisStore;
use IndieAuthRocks\Storage\TesterRepository;
use IndieAuthRocks\View\Template;

/**
 * Signing in to this site with IndieAuth, for people who only test a client.
 * (Testing a server signs you in as well, as its first step.)
 *
 * Here this site is an ordinary IndieAuth client that only asks who you are:
 * the code is redeemed at the authorization endpoint and no tokens are issued.
 */
final class SignInController extends Controller
{
    private const PENDING_TTL  = 600;
    private const START_BUCKET = 'signin:ip';
    private const START_LIMIT  = 20;
    private const START_WINDOW = 600;
    private const DEFAULT_NEXT = '/client-runs';

    public function __construct(
        Template $view,
        SiteConfig $config,
        Guard $guard,
        private readonly HttpClient $http,
        private readonly RedisStore $redis,
        private readonly TesterRepository $testers,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $config, $guard);
    }

    /** @param array<string, string> $params */
    public function form(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->begin($request);
        $next    = self::safeNext($request->query('next'));

        if ($visitor->isAuthenticated()) {
            return $this->guard->commit(Response::seeOther($next), $visitor);
        }

        return $this->renderForm($visitor, $next);
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->visitor($request);
        $this->guard->requireCsrf($visitor, $request);

        $next = self::safeNext($request->post('next'));
        $me   = trim((string) $request->post('me', ''));

        if ($me === '') {
            return $this->renderForm($visitor, $next, 'Enter your profile URL.', status: 422);
        }

        // Discovery fetches whatever URL was typed in.
        if ($this->limiter->hit(self::START_BUCKET, $request->ip, self::START_WINDOW) > self::START_LIMIT) {
            throw new HttpException(429, 'Too many sign-in attempts from your address. Try again in a few minutes.');
        }

        $profile   = Discovery::normalizeInput($me);
        $discovery = (new Discovery($this->http))->discover($profile);
        $endpoint  = $discovery->authorizationEndpoint();

        if ($endpoint === null) {
            return $this->renderForm($visitor, $next, sprintf(
                '%s does not declare an IndieAuth server%s.',
                $profile,
                $discovery->profileError !== null ? ' (' . $discovery->profileError . ')' : '',
            ), $me, 422);
        }

        $state    = Random::token(24);
        $verifier = Random::token(48);

        $this->redis->setJson(self::pendingKey($state), [
            'session'        => hash('sha256', $visitor->session()->id),
            'profile'        => $profile,
            'discovery_urls' => $discovery->discoveryUrls(),
            'authorization'  => $endpoint,
            'issuer'         => $discovery->string('issuer'),
            'verifier'       => $verifier,
            'next'           => $next,
        ], self::PENDING_TTL);

        $query = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->clientId(),
            'redirect_uri'          => $this->callbackUrl(),
            'state'                 => $state,
            'code_challenge'        => Pkce::challengeFor($verifier),
            'code_challenge_method' => 'S256',
            'me'                    => $profile,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->guard->commit(
            Response::seeOther($endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query)->withNoStore(),
            $visitor,
        );
    }

    /**
     * The redirect URI.
     *
     * @param array<string, string> $params
     */
    public function callback(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->begin($request);
        $state   = (string) $request->query('state', '');
        $pending = $state === '' ? null : $this->redis->takeJson(self::pendingKey($state));

        // Only the browser that started signing in may finish it. Otherwise a
        // crafted link could sign someone in as another person (login CSRF).
        if ($pending === null || !hash_equals((string) ($pending['session'] ?? ''), hash('sha256', $visitor->session()->id))) {
            return $this->renderForm($visitor, self::DEFAULT_NEXT, 'This sign-in has expired, or was started in another browser. Please sign in again.', status: 400);
        }

        $next    = self::safeNext($pending['next'] ?? null);
        $profile = (string) $pending['profile'];
        $refuse  = fn (string $reason): Response => $this->renderForm($visitor, $next, $reason, $profile, 400);

        $error = $request->query('error');
        if ($error !== null) {
            $description = mb_substr(trim((string) $request->query('error_description', '')), 0, 200);

            return $refuse($error === 'access_denied'
                ? 'Signing in was cancelled.'
                : sprintf('Your server returned an error: %s%s.', $error, $description !== '' ? ' (' . $description . ')' : ''));
        }

        $issuer = $pending['issuer'] ?? null;
        if (is_string($issuer) && $request->query('iss') !== $issuer) {
            return $refuse('The response came from a different issuer than your server\'s metadata declares, so it was not trusted.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $refuse('Your server did not return an authorization code.');
        }

        $reply = (new TokenClient($this->http))->redeem((string) $pending['authorization'], $code, $this->clientId(), $this->callbackUrl(), (string) $pending['verifier']);
        $me    = $reply->isSuccess() ? $reply->string('me') : null;

        if ($me === null) {
            return $refuse('Your authorization endpoint did not confirm who you are: ' . $reply->describe() . '.');
        }

        $verified = (new MeVerifier(new Discovery($this->http)))->verify($me, (array) $pending['discovery_urls'], (string) $pending['authorization']);

        if (!$verified['ok']) {
            return $refuse(sprintf('Your server said you are %s, but that could not be confirmed: %s', $me, $verified['reason']));
        }

        $visitor = $this->guard->logIn($visitor, $this->testers->signIn($me));

        return $this->guard->commit(Response::seeOther($next), $visitor);
    }

    /**
     * The client ID metadata document for signing in.
     *
     * @param array<string, string> $params
     */
    public function client(Request $request, array $params = []): Response
    {
        return Cors::allow(Response::json([
            'client_id'     => $this->clientId(),
            'client_name'   => $this->config->siteName,
            'client_uri'    => $this->config->baseUrl,
            'redirect_uris' => [$this->callbackUrl()],
        ]));
    }

    private function renderForm(Visitor $visitor, string $next, ?string $error = null, string $me = '', int $status = 200): Response
    {
        // Signing in posts here and is then redirected to the person's own
        // server. Browsers check form-action against that whole redirect
        // chain, so this page cannot restrict it to 'self'.
        return $this->guard->commit($this->page('sign-in', 'Sign in', [
            'csrf'  => $visitor->session()->csrf,
            'next'  => $next,
            'error' => $error,
            'me'    => $me,
        ], $status, $visitor)->withoutFormActionRestriction()->withNoStore(), $visitor);
    }

    private function clientId(): string
    {
        return $this->config->url('sign-in/client');
    }

    private function callbackUrl(): string
    {
        return $this->config->url('sign-in/callback');
    }

    private static function pendingKey(string $state): string
    {
        return 'signin:' . hash('sha256', $state);
    }

    /** Only a path on this site, so the form cannot be used to send people elsewhere. */
    private static function safeNext(mixed $next): string
    {
        return is_string($next) && preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $next) === 1 ? $next : self::DEFAULT_NEXT;
    }
}

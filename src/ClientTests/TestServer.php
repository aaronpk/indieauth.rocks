<?php

declare(strict_types=1);

namespace IndieAuthRocks\ClientTests;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Crypto\Random;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Engine\Verdict;
use IndieAuthRocks\Fetch\FetchException;
use IndieAuthRocks\Fetch\HttpRequest;
use IndieAuthRocks\Fetch\RecordingHttpClient;
use IndieAuthRocks\Fetch\UrlResolver;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Http\Url;
use IndieAuthRocks\IndieAuth\ClientId;
use IndieAuthRocks\IndieAuth\Pkce;
use IndieAuthRocks\IndieAuth\Scopes;
use IndieAuthRocks\ServerTests\Landing;
use IndieAuthRocks\Storage\RedisStore;
use IndieAuthRocks\Storage\ResultRepository;

/**
 * The IndieAuth server this site plays for client tests: strict about
 * requests, and misbehaving on purpose where a test calls for it.
 */
final class TestServer
{
    private const CODE_TTL  = 600;
    private const TOKEN_TTL = 3600;

    public function __construct(
        private readonly SiteConfig $config,
        private readonly RedisStore $redis,
        private readonly Markers $markers,
        private readonly ResultRepository $results,
        private readonly RecordingHttpClient $http,
    ) {
    }

    public function urls(Run $run): ClientTestUrls
    {
        return new ClientTestUrls($this->config, $run->runKey);
    }

    // ============================================================ discovery

    public function profile(Run $run, int $n, string $variant, bool $alt): Response
    {
        $urls     = $this->urls($run);
        $scenario = Scenario::for($n);

        if ($alt) {
            // Other-domain profiles returned as me by 403 and 404.
            $this->markers->mark($run, $n, Markers::ME_FETCHED);

            return $this->page($n, sprintf('<link rel="indieauth-metadata" href="%s">', htmlspecialchars($urls->metadata($n, $n === 403))));
        }

        if ($variant === '') {
            $this->markers->first($run, $n, Markers::PROFILE);

            if ($n === 106) {
                return Response::redirect($urls->profile(106, 'moved'), 301);
            }

            if ($n === 405) {
                return Response::redirect($urls->profile(405, 'final'), 302);
            }
        }

        if ($variant === 'moved' && $n === 106) {
            return Response::redirect($urls->profile(106, 'final'), 302);
        }

        if ($variant === 'other' && $n === 402) {
            $this->markers->mark($run, $n, Markers::ME_FETCHED);

            return $this->page($n, sprintf('<link rel="indieauth-metadata" href="%s">', htmlspecialchars($urls->metadata(402))));
        }

        if ($variant !== '' && !($variant === 'final' && in_array($n, [106, 405], true))) {
            return Response::html('Not found', 404);
        }

        $head     = '';
        $rel      = $scenario->multiRel ? 'me indieauth-metadata' : 'indieauth-metadata';
        $response = null;

        foreach ($scenario->htmlLinks as $real) {
            $href  = $scenario->relativeLink ? 'm/' . $n : $urls->metadata($n, !$real);
            $head .= sprintf("\n    <link rel=\"%s\" href=\"%s\">", $rel, htmlspecialchars($href));
        }

        if ($scenario->legacyLinks !== null) {
            $decoy = !$scenario->legacyLinks;
            $head .= sprintf("\n    <link rel=\"authorization_endpoint\" href=\"%s\">", htmlspecialchars($urls->authorization($n, $decoy)));
            $head .= sprintf("\n    <link rel=\"token_endpoint\" href=\"%s\">", htmlspecialchars($urls->token($n, $decoy)));
        }

        $response = $this->page($n, $head);

        if ($scenario->headerLink !== null) {
            $response = $response->withHeader('link', sprintf('<%s>; rel="indieauth-metadata"', $urls->metadata($n, !$scenario->headerLink)));
        }

        return $response;
    }

    /** The metadata a relative "m/{n}" link points to. */
    public function relativeMetadata(Run $run, int $n): Response
    {
        $this->markers->first($run, $n, Markers::METADATA);

        return $this->json($this->urls($run)->metadataDocument($n));
    }

    /** Where a naive string join of the relative link lands. */
    public function naiveMetadata(Run $run, int $n): Response
    {
        $this->markers->mark($run, $n, Markers::NAIVE);

        return Response::json(['error' => 'This is not the metadata URL: the relative link was resolved incorrectly.'], 404);
    }

    public function metadata(Run $run, int $n, bool $decoy): Response
    {
        // In C403 the returned me links to this "other" server, and a careful
        // client reads its metadata to find out it is not the same server.
        $marker = match (true) {
            !$decoy     => Markers::METADATA,
            $n === 403  => Markers::OTHER_SERVER_METADATA,
            default     => Markers::DECOY,
        };

        $this->markers->first($run, $n, $marker);

        return $this->json($this->urls($run)->metadataDocument($n, $decoy));
    }

    // ========================================================= authorization

    /**
     * Validate an authorization request, record the client checks, and keep the
     * attempt for the consent screen.
     *
     * @return array{attempt: ?string, errors: list<string>, warnings: list<string>, params: array<string, string>}
     */
    public function authorize(Run $run, int $n, bool $decoy, Request $request): array
    {
        $this->markers->first($run, $n, $decoy ? Markers::DECOY : Markers::AUTHORIZE);

        $params   = Landing::parseQuery((string) $request->queryString);
        $errors   = [];
        $warnings = [];
        $step     = 'C' . $n;

        // A reload of the same request (or Back after a wrong PIN) is not a
        // client reusing state or a challenge.
        // The path is included, so the same state sent to two different tests
        // still counts as reuse.
        $repeat = $this->markers->seenBefore($run, 'request', $request->path . '?' . $request->queryString);

        foreach ($request->repeatedQueryParameters() as $name) {
            $errors[] = sprintf('The %s parameter was sent more than once.', $name);
        }

        // C201
        $responseType = $params['response_type'] ?? null;
        $this->observe($run, Verdict::check('C201', $responseType === 'code', 'response_type is "code".', sprintf('response_type was %s.', json_encode($responseType))), $step);
        if ($responseType !== 'code') {
            $errors[] = 'response_type must be "code".';
        }

        // C202
        $clientId = $params['client_id'] ?? '';
        $problem  = $clientId === '' ? 'it is missing' : ClientId::problem($clientId);
        // Summaries can end up in public reports, so they never repeat URLs
        // that contain the run key.
        $this->observe($run, Verdict::check('C202', $problem === null, 'client_id is valid.', 'client_id is not valid: ' . $problem . '.'), $step);
        if ($problem !== null) {
            $errors[] = 'client_id is not valid: ' . $problem . '.';
        }

        // C205, C206
        $challenge = $params['code_challenge'] ?? null;
        $method    = $params['code_challenge_method'] ?? null;
        $pkceOk    = $challenge !== null && Pkce::isValidChallenge($challenge) && $method === 'S256';
        $this->observe($run, Verdict::check('C205', $pkceOk, 'PKCE with S256 is used.', 'The request did not include a valid S256 code_challenge.'), $step);
        if (!$pkceOk) {
            $errors[] = 'PKCE is required: send a 43-character code_challenge with code_challenge_method=S256.';
        } else {
            $this->observe($run, Verdict::check('C206', $repeat || !$this->markers->seenBefore($run, 'challenge', $challenge),
                'A new code_challenge was used.', 'A code_challenge was reused from an earlier request.'), $step);
        }

        // C204
        $state = $params['state'] ?? null;
        $this->observe($run, match (true) {
            $state === null || $state === ''                  => Verdict::fail('C204', 'The request had no state parameter.'),
            strlen($state) < 16                               => Verdict::warning('C204', sprintf('state is only %d characters long.', strlen($state))),
            !$repeat && $this->markers->seenBefore($run, 'state', $state) => Verdict::warning('C204', 'A state value was reused from an earlier request.'),
            default                                           => Verdict::pass('C204', 'state is present and unique.'),
        }, $step);
        if ($state === null || $state === '') {
            $warnings[] = 'The request has no state, so the response cannot be matched to it.';
        }

        // C207
        $me = $params['me'] ?? null;
        $this->observe($run, Verdict::check('C207', $me !== null && $me !== '', 'me was sent.', 'The request did not include me.'), $step);

        // C210
        $scopes = Scopes::parse($params['scope'] ?? '');
        $this->observe($run, match (true) {
            $scopes === null                                                             => Verdict::fail('C210', 'The scope parameter is malformed.'),
            in_array('email', $scopes, true) && !in_array('profile', $scopes, true) => Verdict::fail('C210', 'email was requested without profile.'),
            default                                                                      => Verdict::pass('C210', 'Scopes are valid' . ($scopes !== [] ? ': ' . implode(' ', $scopes) : ' (none requested).')),
        }, $step);

        // C203, C208, C209
        $redirectUri = $params['redirect_uri'] ?? '';
        $redirectProblem = $redirectUri === '' ? 'it is missing' : ClientId::redirectUriProblem($redirectUri);

        if ($redirectProblem !== null) {
            $errors[] = 'redirect_uri is not valid: ' . $redirectProblem . '.';
        } elseif ($problem === null) {
            $registered = $this->clientRedirects($run, $n, $clientId);
            $allowed    = ClientId::sameOrigin($clientId, $redirectUri) || in_array($redirectUri, $registered, true);

            $this->observe($run, Verdict::check('C203', $allowed,
                'redirect_uri is allowed for the client_id.',
                'redirect_uri is on a different origin from client_id and is not listed in the client metadata.'), $step);

            if (!$allowed) {
                $warnings[] = 'redirect_uri is on a different origin from client_id and is not in the client metadata. A careful server would refuse this request.';
            }
        }

        if ($errors !== []) {
            return ['attempt' => null, 'errors' => $errors, 'warnings' => $warnings, 'params' => $params];
        }

        $id = Random::token(18);
        $this->redis->setJson('ct:attempt:' . $id, [
            'run_id' => $run->id,
            'n'      => $n,
            'decoy'  => $decoy,
            'params' => $params,
        ], self::TOKEN_TTL);

        return ['attempt' => $id, 'errors' => [], 'warnings' => $warnings, 'params' => $params];
    }

    /** Attempts are single-use: approving or denying consumes them. */
    public function forgetAttempt(string $id): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{24}$/', $id) === 1) {
            $this->redis->delete('ct:attempt:' . $id);
        }
    }

    /** @return array<string, mixed>|null */
    public function attempt(Run $run, int $n, string $id): ?array
    {
        $attempt = preg_match('/^[A-Za-z0-9_-]{24}$/', $id) === 1 ? $this->redis->getJson('ct:attempt:' . $id) : null;

        return $attempt !== null && (int) $attempt['run_id'] === $run->id && (int) $attempt['n'] === $n ? $attempt : null;
    }

    /** Approve or deny an attempt and return where to send the browser. */
    public function approve(Run $run, int $n, array $attempt, bool $approved): string
    {
        $urls     = $this->urls($run);
        $scenario = Scenario::for($n);
        $params   = $attempt['params'];
        $decoy    = (bool) $attempt['decoy'];
        $redirect = (string) $params['redirect_uri'];
        $state    = $params['state'] ?? null;
        $issuer   = $urls->issuer($n, $decoy);

        $response = [];

        if (!$approved || $scenario->authorizationResponse === 'error') {
            $this->markers->first($run, $n, Markers::ISSUED);
            $response['error'] = 'access_denied';
        } else {
            $code = Random::token(32);
            $this->redis->setJson('ct:code:' . hash('sha256', $code), [
                'run_id'         => $run->id,
                'n'              => $n,
                'client_id'      => (string) $params['client_id'],
                'redirect_uri'   => $redirect,
                'code_challenge' => (string) ($params['code_challenge'] ?? ''),
                'scope'          => (string) ($params['scope'] ?? ''),
            ], self::CODE_TTL);

            $this->markers->first($run, $n, Markers::ISSUED);
            $response['code'] = $code;
        }

        $response['state'] = match ($scenario->authorizationResponse) {
            'wrong_state' => ($state ?? 'unexpected') . 'x',
            default       => $state,
        };

        if ($response['state'] === null) {
            unset($response['state']);
        }

        $iss = match ($scenario->authorizationResponse) {
            'no_iss'    => null,
            'wrong_iss' => $urls->issuer($n, true),
            'near_iss'  => rtrim($issuer, '/'),
            default     => $scenario->sendsIss() ? $issuer : null,
        };

        if ($iss !== null) {
            $response['iss'] = $iss;
        }

        $url = Url::appendQuery($redirect, $response);

        // Remembered only after the owner (or PIN holder) approved, so nobody
        // else can point C306 at their own redirect URI.
        if ($n === 101 && isset($response['code'])) {
            $this->markers->remember($run, 'callback', ['url' => $url, 'code_hash' => hash('sha256', $response['code'])]);
            $this->markers->remember($run, 'client', ['client_id' => (string) $params['client_id'], 'redirect_uri' => $redirect]);
        }

        return $url;
    }

    /** C306: a valid code sent to the client's redirect URI with a state it never created. */
    public function unsolicited(Run $run): ?string
    {
        $client = $this->markers->recall($run, 'client');

        if ($client === null) {
            return null;
        }

        $code = Random::token(32);
        $this->redis->setJson('ct:code:' . hash('sha256', $code), [
            'run_id'         => $run->id,
            'n'              => 306,
            'client_id'      => (string) $client['client_id'],
            'redirect_uri'   => (string) $client['redirect_uri'],
            'code_challenge' => '',
            'scope'          => '',
        ], self::CODE_TTL);

        $this->markers->first($run, 306, Markers::ISSUED);

        return Url::appendQuery((string) $client['redirect_uri'], [
            'code'  => $code,
            'state' => Random::token(16),
            'iss'   => $this->urls($run)->issuer(101),
        ]);
    }

    /** C307: the redirect from the first C101 sign-in, again. */
    public function replay(Run $run): ?string
    {
        $callback = $this->markers->recall($run, 'callback');

        if ($callback === null) {
            return null;
        }

        $this->markers->first($run, 307, Markers::ISSUED);

        return (string) $callback['url'];
    }

    // ============================================================ back channel

    public function redeem(Run $run, int $n, bool $decoy, Request $request, bool $atTokenEndpoint): Response
    {
        if ($decoy) {
            $this->markers->first($run, $n, Markers::DECOY);
        }

        $params = $request->post;

        if ($atTokenEndpoint && ($params['grant_type'] ?? null) === 'refresh_token') {
            return $this->refresh($run, $n, $request);
        }

        if (($params['grant_type'] ?? null) !== 'authorization_code') {
            return $this->error('unsupported_grant_type', 'Use grant_type=authorization_code.');
        }

        $hash = hash('sha256', (string) ($params['code'] ?? ''));
        $code = $this->redis->takeJson('ct:code:' . $hash);

        if ($code === null) {
            // C307 fails only if the replayed code itself comes back, not any
            // code that happens to be redeemed twice after the replay.
            $used     = $this->redis->getJson('ct:used:' . $hash);
            $callback = $this->markers->recall($run, 'callback');
            $replayed = $this->markers->has($run, 307, Markers::ISSUED)
                && hash_equals((string) ($callback['code_hash'] ?? ''), $hash);

            if ($used !== null && (int) $used['run_id'] === $run->id && $replayed) {
                $this->markers->first($run, 307, Markers::REDEEMED);
            }

            return $this->error('invalid_grant', 'The code is invalid, expired or already used.');
        }

        if ((int) $code['run_id'] !== $run->id) {
            return $this->error('invalid_grant', 'The code belongs to another test run.');
        }

        $test = (int) $code['n'];
        $this->markers->first($run, $test, Markers::REDEEMED);
        $this->redis->setJson('ct:used:' . $hash, ['run_id' => $run->id, 'n' => $test], self::TOKEN_TTL);

        if ($test !== 306) {
            $problems = [];
            if (!$request->isForm()) {
                $problems[] = 'the body was not form-encoded';
            }
            if (($params['client_id'] ?? null) !== $code['client_id']) {
                $problems[] = 'client_id did not match the authorization request';
            }
            if (($params['redirect_uri'] ?? null) !== $code['redirect_uri']) {
                $problems[] = 'redirect_uri did not match the authorization request';
            }
            if (!Pkce::verify((string) ($params['code_verifier'] ?? ''), (string) $code['code_challenge'])) {
                $problems[] = 'code_verifier did not match the code_challenge';
            }

            $this->observe($run, $problems === []
                ? Verdict::pass('C401', 'The code redemption request was correct.')
                : Verdict::fail('C401', 'The code redemption request was wrong: ' . implode('; ', $problems) . '.'), 'C' . $test);
        }

        $scopes = array_values(array_filter(explode(' ', (string) $code['scope'])));

        if ($scopes === []) {
            $this->observe($run, Verdict::pass('C410', $atTokenEndpoint
                ? 'Your client redeemed a sign-in-only code at the token endpoint.'
                : 'Your client redeemed a sign-in-only code at the authorization endpoint.'), 'C' . $test);
        }

        if (in_array($test, Scenario::REJECT_BEFORE_REDEEMING, true)) {
            return $this->error('invalid_grant', sprintf('Test C%d expected your client to reject the authorization response instead of redeeming this code.', $test));
        }

        return $this->tokenResponse($run, $test, (string) $code['client_id'], $scopes, $atTokenEndpoint);
    }

    public function userinfo(Run $run, int $n, Request $request): Response
    {
        $token = $request->bearerToken();

        if ($token === null && ($request->query('access_token') ?? $request->post('access_token')) !== null) {
            $token = (string) ($request->query('access_token') ?? $request->post('access_token'));
            $this->observe($run, Verdict::warning('C501', 'Your client sent an access token in the URL or body instead of the Authorization header.'), 'C' . $n);
        } elseif ($token !== null) {
            $this->observe($run, Verdict::pass('C501', 'Your client sent the access token in the Authorization header.'), 'C' . $n);
        }

        $data = $token === null ? null : $this->redis->getJson('ct:token:' . hash('sha256', $token));

        if ($data === null || (int) $data['run_id'] !== $run->id) {
            return Response::json(['error' => 'invalid_token'], 401)->withHeader('www-authenticate', 'Bearer error="invalid_token"')->withNoStore();
        }

        return $this->json(['name' => 'Test profile C' . $data['n'], 'url' => $data['me']]);
    }

    public function revoke(Run $run, int $n, Request $request): Response
    {
        $token = (string) $request->post('token', '');

        if ($token !== '') {
            $this->redis->delete('ct:token:' . hash('sha256', $token), 'ct:refresh:' . hash('sha256', $token));
            $this->observe($run, Verdict::pass('C505', 'Your client revoked a token.'), 'C' . $n);
        }

        return Response::make(200)->withHeader('access-control-allow-origin', '*');
    }

    // ================================================================= helpers

    /** @param list<string> $scopes */
    private function tokenResponse(Run $run, int $n, string $clientId, array $scopes, bool $atTokenEndpoint): Response
    {
        $urls     = $this->urls($run);
        $scenario = Scenario::for($n);

        if ($scenario->tokenResponse === 'invalid_grant') {
            return $this->error('invalid_grant', 'Test C409: the token endpoint refuses on purpose. Your client should show an error.');
        }

        $me = match ($scenario->tokenResponse) {
            'me_same_domain'  => $urls->profile(402, 'other'),
            'me_other_server' => $urls->altProfile(403),
            'me_other_domain' => $urls->altProfile(404),
            'me_final'        => $urls->profile(405, 'final'),
            // A port and a fragment: not a valid profile URL (§3.2).
            'invalid_me'      => 'https://' . parse_url($this->config->baseUrl, PHP_URL_HOST) . ':443/u/' . $run->runKey . '/407#me',
            default           => $urls->profile($n),
        };

        $body = [];

        if ($scenario->tokenResponse !== 'no_me') {
            $body['me'] = $me;
        }

        if (in_array('profile', $scopes, true)) {
            $body['profile'] = $scenario->tokenResponse === 'spoofed_profile'
                ? ['name' => 'Someone Else', 'url' => 'https://someone-else.example/', 'email' => 'someone@else.example']
                : ['name' => 'Test profile C' . $n, 'url' => $me];
        }

        if ($atTokenEndpoint && $scopes !== []) {
            $body += $this->issueTokens($run, $n, $clientId, $scopes, $me, $scenario->tokenResponse === 'expiring' ? 120 : self::TOKEN_TTL, 0);
        }

        return $this->json($body);
    }

    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    private function issueTokens(Run $run, int $n, string $clientId, array $scopes, string $me, int $expiresIn, int $generation): array
    {
        $access  = Random::token(32);
        $refresh = Random::token(32);
        $data    = ['run_id' => $run->id, 'n' => $n, 'client_id' => $clientId, 'scope' => implode(' ', $scopes), 'me' => $me];

        // The token really expires when expires_in says, so clients that only
        // refresh after a 401 can pass C502.
        $this->redis->setJson('ct:token:' . hash('sha256', $access), $data, max(1, $expiresIn));
        $this->redis->setJson('ct:refresh:' . hash('sha256', $refresh), $data + ['generation' => $generation, 'expires_in' => $expiresIn], 30 * 86_400);

        return [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'scope'         => implode(' ', $scopes),
            'expires_in'    => $expiresIn,
            'refresh_token' => $refresh,
        ];
    }

    private function refresh(Run $run, int $n, Request $request): Response
    {
        $hash = hash('sha256', (string) $request->post('refresh_token', ''));
        $data = $this->redis->getJson('ct:refresh:' . $hash);

        if ($data === null || (int) $data['run_id'] !== $run->id) {
            return $this->error('invalid_grant', 'The refresh token is invalid.');
        }

        $test = (int) $data['n'];

        if (!empty($data['used'])) {
            $this->observe($run, Verdict::fail('C503', 'Your client used a refresh token that had already been replaced.'), 'C' . $test);

            return $this->error('invalid_grant', 'This refresh token was already used. Clients must use the new refresh token from each response.');
        }

        if (($request->post('client_id') ?? null) !== $data['client_id']) {
            return $this->error('invalid_grant', 'client_id does not match the refresh token.');
        }

        $granted   = explode(' ', (string) $data['scope']);
        $requested = Scopes::parse((string) $request->post('scope', '')) ?? [];

        if ($requested !== [] && array_diff($requested, $granted) !== []) {
            $this->observe($run, Verdict::fail('C504', 'Your client asked for more scope when refreshing than was originally granted.'), 'C' . $test);

            return $this->error('invalid_scope', 'A refresh cannot add scopes.');
        }

        $this->observe($run, Verdict::pass('C504', 'Refresh requests did not ask for more scope.'), 'C' . $test);

        if ((int) $data['generation'] > 0) {
            $this->observe($run, Verdict::pass('C503', 'Your client used the newest refresh token.'), 'C' . $test);
        }

        $data['used'] = true;
        $this->redis->setJson('ct:refresh:' . $hash, $data, 30 * 86_400);
        $this->markers->mark($run, $test, Markers::REFRESHED);

        return $this->json(['me' => $data['me']] + $this->issueTokens(
            $run,
            $test,
            (string) $data['client_id'],
            $requested !== [] ? $requested : $granted,
            (string) $data['me'],
            (int) ($data['expires_in'] ?? self::TOKEN_TTL),
            (int) $data['generation'] + 1,
        ));
    }

    /** @return list<string> Redirect URIs from the client's metadata document. */
    private function clientRedirects(Run $run, int $n, string $clientId): array
    {
        $step = 'C' . $n;

        if (ClientId::isLoopback($clientId)) {
            return [];
        }

        $this->http->context($run->id, $step);

        try {
            $response = $this->http->send(HttpRequest::get($clientId, 'application/json'));
        } catch (FetchException $e) {
            $this->observe($run, Verdict::warning('C208', 'The client_id could not be fetched: ' . $e->getMessage()), $step);

            return [];
        } finally {
            $this->http->context(null);
        }

        $document = $response->status === 200 && $response->isJson() ? $response->json() : null;

        if ($document === null) {
            $this->observe($run, Verdict::warning('C208', sprintf('The client_id did not return a JSON client metadata document (HTTP %d, %s).', $response->status, $response->contentType() ?: 'no content type')), $step);

            return [];
        }

        if (($document['client_id'] ?? null) !== $clientId) {
            $this->observe($run, Verdict::fail('C208', 'The client metadata document declares a different client_id from the URL it is served at.'), $step);

            return [];
        }

        $this->observe($run, Verdict::pass('C208', 'A client metadata document was published' . (isset($document['client_name']) ? ' for "' . $document['client_name'] . '"' : '') . '.'), $step);

        if (is_string($document['client_uri'] ?? null)) {
            $this->observe($run, Verdict::check('C209', str_starts_with($clientId, $document['client_uri']),
                'client_uri is a prefix of client_id.', 'client_uri is not a prefix of client_id.'), $step);
        }

        $uris = [];
        foreach ((array) ($document['redirect_uris'] ?? []) as $uri) {
            $resolved = is_string($uri) ? UrlResolver::resolve($clientId, $uri) : null;
            if ($resolved !== null) {
                $uris[] = $resolved;
            }
        }

        return $uris;
    }

    private function observe(Run $run, Verdict $verdict, string $step): void
    {
        $this->results->recordWorst($run->id, $verdict, $step);
    }

    private function page(int $n, string $head): Response
    {
        return Response::html(sprintf(<<<'HTML'
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>IndieAuth Rocks test profile C%d</title>%s
            </head>
            <body class="h-card">
                <h1 class="p-name">Test profile C%d</h1>
                <p>A profile URL for an IndieAuth Rocks client test. Sign in to your client with this URL.</p>
            </body>
            </html>
            HTML, $n, $head, $n))->withHeader('access-control-allow-origin', '*');
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): Response
    {
        return Response::json($data)->withNoStore()->withHeader('access-control-allow-origin', '*');
    }

    private function error(string $error, string $description): Response
    {
        return Response::json(['error' => $error, 'error_description' => $description], 400)
            ->withNoStore()
            ->withHeader('access-control-allow-origin', '*');
    }
}

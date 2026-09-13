<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests\Checks;

use IndieAuthRocks\Engine\Verdict;
use IndieAuthRocks\Fetch\HttpRequest;
use IndieAuthRocks\ServerTests\ClientUrls;
use IndieAuthRocks\ServerTests\Reply;
use IndieAuthRocks\ServerTests\TokenClient;

/** Checks that need no authorization code or human (S330–S334, S604, S702, S703, S901, S902, S906). */
final class EndpointProbes
{
    public function __construct(private readonly TokenClient $client)
    {
    }

    /**
     * @param array<string, string|null> $endpoints authorization, token, revocation, userinfo
     * @return list<Verdict>
     */
    public function run(array $endpoints, ClientUrls $urls): array
    {
        $verdicts = [];
        $token    = $endpoints['token'] ?? null;

        if ($token === null) {
            foreach (['S330', 'S331', 'S332', 'S333', 'S334', 'S901', 'S906'] as $id) {
                $verdicts[] = Verdict::skipped($id, 'No token endpoint.');
            }
        } else {
            $unknown = $this->client->redeem($token, TokenClient::madeUp(), $urls->clientId(), $urls->callback(), TokenClient::madeUp() . 'xyz');

            // S330
            $verdicts[] = match (true) {
                $unknown->isSuccess()                                               => Verdict::fail('S330', 'A made-up code was accepted: ' . $unknown->describe() . '.'),
                $unknown->status() === 400 && $unknown->string('error') === 'invalid_grant' => Verdict::pass('S330', 'Refused with HTTP 400 invalid_grant.'),
                $unknown->isClientError()                                           => Verdict::warning('S330', 'Refused, but with ' . $unknown->describe() . ' instead of HTTP 400 invalid_grant.'),
                default                                                             => Verdict::fail('S330', 'Unexpected response: ' . $unknown->describe() . '.'),
            };

            // S331
            $missing     = $this->client->send(HttpRequest::postForm($token, ['code' => TokenClient::madeUp(), 'client_id' => $urls->clientId()]));
            $unsupported = $this->client->send(HttpRequest::postForm($token, ['grant_type' => 'password', 'username' => 'test', 'password' => 'test', 'client_id' => $urls->clientId()]));

            if ($missing->isSuccess() || $unsupported->isSuccess()) {
                $verdicts[] = Verdict::fail('S331', 'A request without a valid grant_type was accepted.');
            } elseif ($missing->string('error') === 'invalid_request' && $unsupported->string('error') === 'unsupported_grant_type') {
                $verdicts[] = Verdict::pass('S331', 'Refused with invalid_request and unsupported_grant_type.');
            } elseif ($missing->isClientError() && $unsupported->isClientError()) {
                $verdicts[] = Verdict::warning('S331', sprintf('Refused, but with %s and %s.', $missing->describe(), $unsupported->describe()));
            } else {
                $verdicts[] = Verdict::fail('S331', sprintf('Unexpected responses: %s and %s.', $missing->describe(), $unsupported->describe()));
            }

            // S332
            $bad = array_filter([$unknown, $missing, $unsupported], static fn (Reply $r): bool => !$r->isSuccess() && $r->string('error') === null);
            $verdicts[] = Verdict::check('S332', $bad === [], 'Error responses are JSON with an error code.', 'Some error responses were not JSON objects with an "error" member.');

            // S333
            $get = $this->client->send(HttpRequest::get($token . (str_contains($token, '?') ? '&' : '?') . http_build_query([
                'grant_type' => 'authorization_code', 'code' => TokenClient::madeUp(), 'client_id' => $urls->clientId(),
            ]), 'application/json'));
            $verdicts[] = Verdict::check('S333', !$get->isSuccess() || $get->string('error') !== null,
                'GET requests are refused (' . $get->describe() . ').', 'The token endpoint answered a GET request with ' . $get->describe() . '.');

            // S334
            $options = $this->client->send(new HttpRequest('OPTIONS', $token, [
                'Origin' => 'https://browser-app.example', 'Access-Control-Request-Method' => 'POST',
            ]));
            $verdicts[] = Verdict::pass('S334', $options->header('access-control-allow-origin') !== null
                ? 'Preflight allowed for ' . $options->header('access-control-allow-origin') . '.'
                : 'No CORS preflight support; browser-based apps cannot call the token endpoint directly.');

            // S901
            if (!str_starts_with($token, 'https://')) {
                $verdicts[] = Verdict::skipped('S901', 'The token endpoint is not https to begin with.');
            } else {
                $plain = $this->client->redeem('http://' . substr($token, 8), TokenClient::madeUp(), $urls->clientId(), $urls->callback(), TokenClient::madeUp() . 'xyz');
                $processed = $plain->result !== null && !$plain->result->isRedirect() && $plain->status() !== 403 && $plain->string('error') !== null;

                $verdicts[] = Verdict::check('S901', !$processed,
                    'Plain http requests are not processed (' . $plain->describe() . ').',
                    'The token endpoint processed a request sent over plain http (' . $plain->describe() . ').');
            }

            // S906
            $wildcard = $unknown->header('access-control-allow-origin') === '*'
                && strtolower((string) $unknown->header('access-control-allow-credentials')) === 'true';
            $verdicts[] = Verdict::check('S906', !$wildcard, 'No credentialed wildcard CORS.', 'Access-Control-Allow-Origin: * is combined with Access-Control-Allow-Credentials: true.');
        }

        // S902
        if (($endpoints['authorization'] ?? null) === null) {
            $verdicts[] = Verdict::skipped('S902', 'No authorization endpoint.');
        } else {
            $page    = $this->client->send(HttpRequest::get((string) $endpoints['authorization'], 'text/html'));
            $frame   = $page->header('x-frame-options');
            $csp     = (string) $page->header('content-security-policy');
            $verdicts[] = Verdict::check('S902', $frame !== null || str_contains($csp, 'frame-ancestors'),
                'Framing is prevented (' . ($frame !== null ? 'X-Frame-Options: ' . $frame : 'CSP frame-ancestors') . ').',
                'The authorization endpoint does not send X-Frame-Options or CSP frame-ancestors, so it could be framed for clickjacking.');
        }

        // S604
        if (($endpoints['revocation'] ?? null) === null) {
            $verdicts[] = Verdict::skipped('S604', 'No revocation endpoint.');
        } else {
            $revoke = $this->client->revoke((string) $endpoints['revocation'], TokenClient::madeUp());
            $verdicts[] = Verdict::check('S604', $revoke->status() === 200, 'Returned HTTP 200.', 'Revoking an unknown token returned ' . $revoke->describe() . ' instead of HTTP 200.');
        }

        // S702, S703
        if (($endpoints['userinfo'] ?? null) === null) {
            $verdicts[] = Verdict::skipped('S702', 'No userinfo endpoint.');
            $verdicts[] = Verdict::skipped('S703', 'No userinfo endpoint.');
        } else {
            $none = $this->client->userinfo((string) $endpoints['userinfo'], null);
            $verdicts[] = Verdict::check('S702', $none->status() === 401, 'Returned HTTP 401.', 'A request without a token returned ' . $none->describe() . ' instead of HTTP 401.');

            $invalid = $this->client->userinfo((string) $endpoints['userinfo'], TokenClient::madeUp());
            $verdicts[] = Verdict::check('S703', $invalid->status() === 401,
                'Returned HTTP 401' . (str_contains((string) $invalid->header('www-authenticate'), 'invalid_token') ? ' with error="invalid_token".' : '.'),
                'A made-up token returned ' . $invalid->describe() . ' instead of HTTP 401.');
        }

        return $verdicts;
    }
}

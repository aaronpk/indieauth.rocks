<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests\Checks;

use Closure;
use IndieAuthRocks\Engine\Verdict;
use IndieAuthRocks\IndieAuth\ProfileUrl;
use IndieAuthRocks\ServerTests\Reply;

/** Grades a token endpoint response to a code exchange (S401–S410). */
final class TokenChecks
{
    /**
     * @param list<string>                                           $requestedScopes
     * @param Closure(string): array{ok: bool, fetched: bool, reason: string} $verifyMe
     * @return array{verdicts: list<Verdict>, me: ?string, verified: bool}
     */
    public static function grade(Reply $reply, array $requestedScopes, Closure $verifyMe): array
    {
        $verdicts = [];
        $json     = $reply->json();

        // S401
        if ($reply->status() !== 200) {
            $verdicts[] = Verdict::fail('S401', 'The token endpoint returned ' . $reply->describe() . '.');
        } elseif ($json === null) {
            $verdicts[] = Verdict::fail('S401', 'The response is not a JSON object.');
        } elseif (!$reply->result?->isJson()) {
            $verdicts[] = Verdict::warning('S401', sprintf('The response is JSON but is served as "%s".', $reply->result?->contentType()));
        } else {
            $verdicts[] = Verdict::pass('S401', 'HTTP 200 with a JSON object.');
        }

        $token = $reply->string('access_token');

        // S402, S403, S410
        if ($requestedScopes === []) {
            foreach (['S402', 'S403', 'S410'] as $id) {
                $verdicts[] = Verdict::skipped($id, 'No scope was requested.');
            }
        } else {
            $verdicts[] = Verdict::check('S402', $token !== null, 'An access token was issued.', 'No access_token in the response.');

            if ($token === null) {
                $verdicts[] = Verdict::skipped('S403', 'No access token.');
                $verdicts[] = Verdict::skipped('S410', 'No access token.');
            } else {
                $type = $reply->string('token_type');
                $verdicts[] = Verdict::check('S403', $type !== null && strcasecmp($type, 'Bearer') === 0,
                    'token_type is Bearer.', $type === null ? 'token_type is missing.' : 'token_type is ' . $type . '.');
                $verdicts[] = Verdict::check('S410', strlen($token) >= 22,
                    'The access token is long enough.', sprintf('The access token is only %d characters long.', strlen($token)));
            }
        }

        // S404, S405
        $me       = $reply->string('me');
        $verified = false;

        if ($me === null) {
            $verdicts[] = Verdict::fail('S404', 'The response has no me.');
            $verdicts[] = Verdict::skipped('S405', 'No me to verify.');
        } elseif (ProfileUrl::parse($me) === null) {
            $verdicts[] = Verdict::fail('S404', sprintf('me is not a valid profile URL: %s', $me));
            $verdicts[] = Verdict::skipped('S405', 'me is not a valid profile URL.');
        } else {
            $verdicts[] = Verdict::pass('S404', 'me is ' . $me);
            $check      = $verifyMe($me);
            $verified   = $check['ok'];
            $verdicts[] = $verified ? Verdict::pass('S405', $check['reason']) : Verdict::fail('S405', $check['reason']);
        }

        // S406
        $cache = strtolower((string) $reply->header('cache-control'));
        $verdicts[] = Verdict::check('S406', str_contains($cache, 'no-store'),
            'Cache-Control: no-store is set.', 'The response does not send Cache-Control: no-store, so tokens could be cached.');

        if ($token !== null) {
            // S407
            $expires = $json['expires_in'] ?? null;
            $verdicts[] = Verdict::check('S407', is_int($expires) && $expires > 0,
                'expires_in is ' . (is_scalar($expires) ? $expires : '') . ' seconds.',
                'expires_in is missing, so the client cannot tell when the token expires.');

            // S408
            $verdicts[] = Verdict::check('S408', $reply->string('scope') !== null,
                'scope is "' . $reply->string('scope') . '".', 'scope is missing from the response.');
        } else {
            $verdicts[] = Verdict::skipped('S407', 'No access token.');
            $verdicts[] = Verdict::skipped('S408', 'No access token.');
        }

        // S409
        if (!in_array('profile', $requestedScopes, true)) {
            $verdicts[] = Verdict::skipped('S409', 'The profile scope was not requested.');
        } elseif (!isset($json['profile'])) {
            $verdicts[] = Verdict::pass('S409', 'No profile information was returned, which is allowed.');
        } else {
            $profile = $json['profile'];
            $ok      = is_array($profile) && ($profile === [] || !array_is_list($profile));

            foreach (['name', 'url', 'photo', 'email'] as $key) {
                if ($ok && isset($profile[$key]) && !is_string($profile[$key])) {
                    $ok = false;
                }
            }

            $verdicts[] = Verdict::check('S409', $ok, 'The profile object is well formed.', 'profile should be an object whose name, url, photo and email are strings.');
        }

        return ['verdicts' => $verdicts, 'me' => $me, 'verified' => $verified];
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

use IndieAuthRocks\Engine\Verdict;

/** Follow-up checks that use tokens obtained during a run. */
final class TokenFlows
{
    public function __construct(private readonly TokenClient $client)
    {
    }

    /**
     * S701, S801 and S802, with the access token from signing in.
     *
     * @param array<string, string|null> $endpoints
     * @param array<string, mixed>|null  $profile   From the token response.
     * @return list<Verdict>
     */
    public function afterSignIn(array $endpoints, string $access, ?array $profile): array
    {
        $verdicts = [];

        if ($endpoints['userinfo'] === null) {
            $verdicts[] = Verdict::skipped('S701', 'No userinfo endpoint.');
        } else {
            $reply = $this->client->userinfo($endpoints['userinfo'], $access);
            $json  = $reply->json();

            $verdicts[] = match (true) {
                $reply->status() !== 200 || $json === null => Verdict::fail('S701', 'The userinfo endpoint returned ' . $reply->describe() . '.'),
                $profile !== null && ($json['url'] ?? null) !== ($profile['url'] ?? null) => Verdict::warning('S701', 'The userinfo response does not match the profile from the token response.'),
                default => Verdict::pass('S701', 'Returned the profile' . (isset($json['url']) ? ' for ' . $json['url'] : '') . '.'),
            };
        }

        if ($endpoints['introspection'] === null) {
            $verdicts[] = Verdict::skipped('S801', 'No introspection endpoint.');
            $verdicts[] = Verdict::skipped('S802', 'No introspection endpoint.');
        } else {
            $reply = $this->client->introspect($endpoints['introspection'], $access, null);
            $json  = $reply->json() ?? [];

            $verdicts[] = Verdict::check('S801', $reply->status() === 401,
                'Unauthenticated introspection was refused with HTTP 401.',
                'Unauthenticated introspection returned ' . $reply->describe() . ' instead of HTTP 401.');
            $verdicts[] = Verdict::check('S802', ($json['active'] ?? null) !== true && !isset($json['me']),
                'The refusal reveals nothing about the token.',
                'Without authorization, the response still revealed that the token is active or whose it is.');
        }

        return $verdicts;
    }

    /**
     * S501–S505. Returns the newest tokens that should still work, so they can
     * be revoked when the run ends.
     *
     * @param array<string, string|null> $endpoints
     * @return array{verdicts: list<Verdict>, access: ?string, refresh: ?string}
     */
    public function refreshChain(array $endpoints, string $clientId, string $otherClientId, string $access, ?string $refresh): array
    {
        $ids = ['S501', 'S502', 'S503', 'S504', 'S505'];

        if ($endpoints['token'] === null || $refresh === null) {
            return ['verdicts' => array_map(static fn (string $id): Verdict => Verdict::skipped($id, 'No refresh token was issued.'), $ids), 'access' => $access, 'refresh' => null];
        }

        $token    = $endpoints['token'];
        $verdicts = [];

        // S501
        $first = $this->client->refresh($token, $refresh, $clientId);

        if (!$first->isSuccess() || $first->string('access_token') === null) {
            $verdicts[] = Verdict::fail('S501', 'Refreshing returned ' . $first->describe() . '.');

            foreach (['S502', 'S503', 'S504', 'S505'] as $id) {
                $verdicts[] = Verdict::skipped($id, 'The refresh token could not be used.');
            }

            return ['verdicts' => $verdicts, 'access' => $access, 'refresh' => $refresh];
        }

        $problems = [];
        if ($first->string('me') === null) {
            $problems[] = 'no me';
        }
        if (!str_contains(strtolower((string) $first->header('cache-control')), 'no-store')) {
            $problems[] = 'no Cache-Control: no-store';
        }

        $verdicts[] = $problems === []
            ? Verdict::pass('S501', 'Refreshing returned a new access token.')
            : Verdict::fail('S501', 'Refreshing worked, but the response had ' . implode(' and ', $problems) . '.');

        $liveAccess  = (string) $first->string('access_token');
        $liveRefresh = $first->string('refresh_token') ?? $refresh;
        $rotated     = $first->string('refresh_token') !== null && $first->string('refresh_token') !== $refresh;

        // S503
        $granted  = array_filter(explode(' ', (string) $first->string('scope')));
        $escalate = $this->client->refresh($token, $liveRefresh, $clientId, trim(implode(' ', array_unique([...$granted, 'profile', 'create', 'delete']))));

        if ($escalate->isSuccess()) {
            $newScopes  = array_filter(explode(' ', (string) $escalate->string('scope')));
            $extra      = array_diff($newScopes, [...$granted, 'profile']);
            $verdicts[] = $extra === []
                ? Verdict::pass('S503', 'The refresh succeeded without adding scopes.')
                : Verdict::fail('S503', 'Refreshing added scopes that were never granted: ' . implode(', ', $extra) . '.');
            $liveAccess  = $escalate->string('access_token') ?? $liveAccess;
            $liveRefresh = $escalate->string('refresh_token') ?? $liveRefresh;
        } else {
            $verdicts[] = Verdict::pass('S503', 'Asking for more scope was refused (' . $escalate->describe() . ').');
        }

        // S502
        $other = $this->client->refresh($token, $liveRefresh, $otherClientId);

        if ($other->isSuccess()) {
            $verdicts[]  = Verdict::fail('S502', 'A different client_id was able to use the refresh token.');
            $liveAccess  = $other->string('access_token') ?? $liveAccess;
            $liveRefresh = $other->string('refresh_token') ?? $liveRefresh;
        } else {
            $verdicts[] = Verdict::pass('S502', 'Refused for a different client_id (' . $other->describe() . ').');
        }

        // S504, S505
        if (!$rotated) {
            $verdicts[] = Verdict::skipped('S504', 'Your server does not rotate refresh tokens.');
            $verdicts[] = Verdict::skipped('S505', 'Your server does not rotate refresh tokens.');
        } else {
            $reuse = $this->client->refresh($token, $refresh, $clientId);

            if ($reuse->isSuccess()) {
                $verdicts[]  = Verdict::warning('S504', 'The replaced refresh token still works.');
                $verdicts[]  = Verdict::skipped('S505', 'Reuse was not detected.');
                $liveAccess  = $reuse->string('access_token') ?? $liveAccess;
                $liveRefresh = $reuse->string('refresh_token') ?? $liveRefresh;
            } else {
                $verdicts[] = Verdict::pass('S504', 'The replaced refresh token was refused (' . $reuse->describe() . ').');

                if ($endpoints['userinfo'] === null) {
                    $verdicts[] = Verdict::skipped('S505', 'No userinfo endpoint to check whether the newest tokens were revoked.');
                } else {
                    $check = $this->client->userinfo($endpoints['userinfo'], $liveAccess);
                    $verdicts[] = Verdict::check('S505', !$check->isSuccess(),
                        'Reusing the old refresh token revoked the newest tokens too.',
                        'After an old refresh token was reused, the newest access token still works.');

                    if (!$check->isSuccess()) {
                        return ['verdicts' => $verdicts, 'access' => null, 'refresh' => null];
                    }
                }
            }
        }

        return ['verdicts' => $verdicts, 'access' => $liveAccess, 'refresh' => $liveRefresh];
    }

    /**
     * S601–S603.
     *
     * @param array<string, string|null> $endpoints
     * @return list<Verdict>
     */
    public function revocation(array $endpoints, string $clientId, string $access, ?string $refresh, bool $alreadyRevoked): array
    {
        if ($endpoints['revocation'] === null) {
            return [
                Verdict::skipped('S601', 'No revocation endpoint.'),
                Verdict::skipped('S602', 'No revocation endpoint.'),
                Verdict::skipped('S603', 'No revocation endpoint.'),
            ];
        }

        if ($alreadyRevoked) {
            $reason = 'The tokens from this sign-in were already revoked when the code was replayed.';

            return [Verdict::skipped('S601', $reason), Verdict::skipped('S602', $reason), Verdict::skipped('S603', $reason)];
        }

        $verdicts = [];

        $revoke     = $this->client->revoke($endpoints['revocation'], $access);
        $verdicts[] = Verdict::check('S601', $revoke->status() === 200, 'Returned HTTP 200.', 'Revoking an access token returned ' . $revoke->describe() . '.');

        if ($endpoints['userinfo'] === null) {
            $verdicts[] = Verdict::skipped('S602', 'No userinfo endpoint to check the revoked token with.');
        } else {
            $check      = $this->client->userinfo($endpoints['userinfo'], $access);
            $verdicts[] = Verdict::check('S602', !$check->isSuccess(), 'The revoked token is refused (' . $check->describe() . ').', 'The revoked access token still works.');
        }

        if ($refresh === null || $endpoints['token'] === null) {
            $verdicts[] = Verdict::skipped('S603', 'No refresh token was issued.');
        } else {
            $revokeRefresh = $this->client->revoke($endpoints['revocation'], $refresh);
            $use           = $this->client->refresh($endpoints['token'], $refresh, $clientId);

            $verdicts[] = match (true) {
                $revokeRefresh->status() !== 200 => Verdict::fail('S603', 'Revoking the refresh token returned ' . $revokeRefresh->describe() . '.'),
                $use->isSuccess()                => Verdict::fail('S603', 'The revoked refresh token still works.'),
                default                          => Verdict::pass('S603', 'The revoked refresh token is refused (' . $use->describe() . ').'),
            };
        }

        return $verdicts;
    }
}

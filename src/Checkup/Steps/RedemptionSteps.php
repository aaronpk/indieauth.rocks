<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup\Steps;

use IndieAuthRocks\Checkup\Step;
use IndieAuthRocks\Checkup\StepState;
use IndieAuthRocks\ServerTests\Reply;

/**
 * The last exchange: the app swaps its one-time code for an answer to "who is
 * this?", and checks that answer really belongs to the person.
 */
final class RedemptionSteps
{
    /**
     * @param  array{ok: bool, fetched: bool, reason: string}|null $verified Result of MeVerifier::verify.
     * @return list<Step>
     */
    public static function from(Reply $reply, string $endpoint, string $profileUrl, bool $wantedToken, ?array $verified): array
    {
        $exchange = self::exchange($reply, $endpoint, $wantedToken);
        $steps    = [$exchange];

        if ($exchange->state->stopsTheLogin()) {
            return $steps;
        }

        $steps[] = self::identity((string) $reply->string('me'), $profileUrl, $verified);

        return $steps;
    }

    private static function exchange(Reply $reply, string $endpoint, bool $wantedToken): Step
    {
        $title = 'Exchanging the code for your identity';
        $urls  = [$endpoint];

        if ($reply->result === null) {
            return new Step(
                id: 'redeem',
                title: $title,
                state: StepState::Problem,
                headline: sprintf('%s could not be reached: %s', $endpoint, (string) $reply->error),
                facts: ['Address' => $endpoint],
                meaning: 'Your browser never talks to this address — the app does, from its own server. If it is '
                    . 'behind a firewall, or only resolves inside your own network, apps fail right here while '
                    . 'everything looks fine in your browser.',
                fix: 'Check that this address is reachable from the public internet.',
                urls: $urls,
            );
        }

        $error  = $reply->string('error');
        $status = $reply->status();
        $facts  = array_filter([
            'Address'      => $endpoint,
            'Status'       => sprintf('HTTP %d', $status),
            'Error'        => $error,
            'Your server said' => $reply->string('error_description'),
        ]);

        if (!$reply->isSuccess()) {
            return new Step(
                id: 'redeem',
                title: $title,
                state: StepState::Problem,
                headline: sprintf('Your server refused to exchange the code: %s.', $reply->describe()),
                facts: $facts,
                meaning: self::errorMeaning($error),
                fix: self::errorAdvice($error),
                urls: $urls,
            );
        }

        if ($reply->json() === null) {
            return new Step(
                id: 'redeem',
                title: $title,
                state: StepState::Problem,
                headline: 'Your server answered the exchange with something other than a JSON document.',
                facts: $facts,
                meaning: 'Apps expect a small JSON reply here saying who you are. Anything else, including a web '
                    . 'page, leaves them with nothing to sign you in as.',
                urls: $urls,
            );
        }

        $me = $reply->string('me');

        if ($me === null) {
            return new Step(
                id: 'redeem',
                title: $title,
                state: StepState::Problem,
                headline: 'Your server accepted the code but did not say who you are.',
                facts: $facts,
                meaning: 'The reply has no me value, which is the one thing an app needs: it is the address you '
                    . 'get signed in as.',
                fix: 'Whoever runs your server needs to include me in this reply.',
                urls: $urls,
            );
        }

        $token   = $reply->string('access_token');
        $granted = $reply->string('scope');

        $facts = array_filter([
            'Address'        => $endpoint,
            'Status'         => sprintf('HTTP %d', $status),
            'It says you are' => $me,
            'Token type'     => $reply->string('token_type'),
            'Permissions granted' => $granted,
        ]);

        $notes = [];

        if ($wantedToken && $token !== null) {
            if ($reply->string('token_type') === null) {
                $notes[] = 'The reply leaves out token_type, which should be Bearer.';
            }

            if (!str_contains(strtolower((string) $reply->header('cache-control')), 'no-store')) {
                $notes[] = 'The reply does not send Cache-Control: no-store, so something between your server and '
                    . 'an app could keep a copy of the token.';
            }

            if ($granted === null) {
                $notes[] = 'The reply does not say which permissions were granted, so an app cannot tell what it '
                    . 'actually got.';
            }
        }

        if ($notes !== []) {
            return new Step(
                id: 'redeem',
                title: $title,
                state: StepState::Note,
                headline: sprintf('Your server confirmed you are %s, with a few things worth tidying.', $me),
                facts: $facts,
                meaning: implode(' ', $notes),
                urls: $urls,
            );
        }

        return new Step(
            id: 'redeem',
            title: $title,
            state: StepState::Ok,
            headline: sprintf('Your server confirmed you are %s.', $me),
            facts: $facts,
            meaning: $token !== null
                ? 'Your server also issued an access token. It is hidden in the log below, and this check throws '
                    . 'it away as soon as it finishes.'
                : null,
            urls: $urls,
        );
    }

    /** @param array{ok: bool, fetched: bool, reason: string}|null $verified */
    private static function identity(string $me, string $profileUrl, ?array $verified): Step
    {
        $title = 'Checking the answer really is you';
        $facts = ['You entered' => $profileUrl, 'Your server says you are' => $me];

        if ($verified !== null && $verified['ok'] === false) {
            return new Step(
                id: 'identity',
                title: $title,
                state: StepState::Problem,
                headline: sprintf('Your server says you are %s, but that could not be confirmed.', $me),
                facts: $facts + ['Why not' => $verified['reason']],
                meaning: 'When a server answers with a different address from the one you entered, apps fetch that '
                    . 'address and check it names the same server. This one does not, and apps will refuse it — '
                    . 'rightly, because otherwise any server could claim to speak for anybody.',
                fix: 'Whoever runs your server needs to return an address that names it as its IndieAuth server.',
            );
        }

        if ($me !== $profileUrl) {
            return new Step(
                id: 'identity',
                title: $title,
                state: StepState::Note,
                headline: sprintf('Your server says you are %s, not the %s you entered.', $me, $profileUrl),
                facts: $facts,
                meaning: 'We checked that address and it names the same server, so apps should accept it. But it '
                    . 'is the address they will know you by, and the one your existing accounts are tied to.',
                fix: sprintf('Sign in to apps as %s from now on.', $me),
            );
        }

        return new Step(
            id: 'identity',
            title: $title,
            state: StepState::Ok,
            headline: sprintf('Your server says you are %s — the address you entered.', $me),
            facts: $facts,
        );
    }

    private static function errorMeaning(?string $error): ?string
    {
        return match ($error) {
            'invalid_grant' => 'That is what a server says when the one-time code has expired, has already been '
                . 'used, or when the proof that the app started this login did not match. It is one of the '
                . 'commonest causes of a login that fails right at the end.',
            'invalid_client' => 'Your server did not accept the app\'s identity. It would have fetched the app\'s '
                . 'details first; if it only allows apps registered with it in advance, every other app fails here '
                . 'the same way.',
            'invalid_request' => 'Your server considered the exchange malformed. This is an ordinary, current '
                . 'IndieAuth exchange, so apps will be sending much the same thing.',
            default => null,
        };
    }

    private static function errorAdvice(?string $error): ?string
    {
        return match ($error) {
            'invalid_grant' => 'Worth checking with whoever runs your server: that nothing uses the code up before '
                . 'the app does — a link preview, a virus scanner or browser prefetch will do exactly that by '
                . 'loading the return address first; that the server and the app agree on the redirect address; '
                . 'and that PKCE is implemented correctly.',
            'invalid_client' => 'Either register the apps you use with your server, or allow any app that '
                . 'publishes its own details, which is how IndieAuth expects this to work.',
            default => null,
        };
    }
}

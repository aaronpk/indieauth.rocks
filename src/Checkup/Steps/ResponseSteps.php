<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup\Steps;

use IndieAuthRocks\Checkup\Step;
use IndieAuthRocks\Checkup\StepState;

/**
 * What happened while the person was away at their own server, as far as it can
 * be told from what came back.
 *
 * Pure: it sees the query parameters of the return, what we sent, and how long
 * it took.
 */
final class ResponseSteps
{
    /**
     * @param  array<string, string> $query      What the server sent us back.
     * @param  string                $sentState  The state we sent it.
     * @param  ?string               $issuer     The issuer its settings declared.
     * @param  ?int                  $elapsed    Seconds spent away, when known.
     * @return list<Step>
     */
    public static function from(array $query, string $sentState, ?string $issuer, ?int $elapsed = null): array
    {
        return [self::approval($query, $elapsed), self::returned($query, $sentState, $issuer)];
    }

    /** @param array<string, string> $query */
    private static function approval(array $query, ?int $elapsed): Step
    {
        $title = 'Approving at your server';
        $error = $query['error'] ?? null;

        if ($error === 'access_denied') {
            return new Step(
                id: 'approval',
                title: $title,
                state: StepState::Note,
                headline: 'You chose to cancel, so your server did not sign you in.',
                meaning: 'Nothing is wrong: that is exactly what cancelling should do.',
                fix: 'Start the check again and approve the request, and we can check the rest of the login.',
            );
        }

        if (is_string($error) && $error !== '') {
            $description = trim((string) ($query['error_description'] ?? ''));

            return new Step(
                id: 'approval',
                title: $title,
                state: StepState::Problem,
                headline: sprintf('Your server refused the request: %s', self::errorPhrase($error, $description)),
                facts: array_filter(['Error' => $error, 'Your server said' => $description !== '' ? $description : null]),
                meaning: 'Your server turned the request down rather than asking you to approve it. An app making '
                    . 'the same request gets the same refusal, usually showing you nothing more than "login failed".',
                fix: self::requestAdvice($error),
            );
        }

        if ($elapsed !== null && $elapsed < 3) {
            return new Step(
                id: 'approval',
                title: $title,
                state: StepState::Note,
                headline: 'Your server sent you straight back without asking you to approve anything.',
                facts: ['Time away' => sprintf('%d seconds', $elapsed)],
                meaning: 'That normally means it remembered an approval you gave earlier, which is allowed and '
                    . 'quite common.',
            );
        }

        return new Step(
            id: 'approval',
            title: $title,
            state: StepState::Unknown,
            headline: 'You approved the request at your own server.',
            meaning: 'This part happens entirely on your server, so it is the one step we cannot see. Whatever it '
                . 'showed you, it then sent you back here.',
        );
    }

    /** @param array<string, string> $query */
    private static function returned(array $query, string $sentState, ?string $issuer): Step
    {
        $title = 'Your server sends you back';
        $state = $query['state'] ?? null;
        $iss   = $query['iss'] ?? null;
        $code  = $query['code'] ?? null;

        $facts = array_filter([
            'state we sent'      => $sentState,
            'state it returned'  => is_string($state) ? $state : 'nothing',
            'iss it returned'    => is_string($iss) ? $iss : 'nothing',
            'Your server is'     => $issuer,
            'One-time code'      => is_string($code) && $code !== '' ? 'yes' : 'no',
        ]);

        if (!is_string($state) || $state === '') {
            return new Step(
                id: 'return',
                title: $title,
                state: StepState::Problem,
                headline: 'Your server sent you back without the state value the request gave it.',
                facts: $facts,
                meaning: 'Apps send a state value and expect it back unchanged, so they can tell their own request '
                    . 'apart from a reply somebody else planted. A careful app stops here, which may be the exact '
                    . 'failure you are seeing. We stopped too: a reply that cannot be tied to a request is not safe '
                    . 'to use.',
                fix: 'Whoever runs your server needs to return the state parameter exactly as it was sent.',
            );
        }

        if (!hash_equals($sentState, $state)) {
            return new Step(
                id: 'return',
                title: $title,
                state: StepState::Problem,
                headline: 'Your server sent back a different state value from the one it was given.',
                facts: $facts,
                meaning: 'Apps compare these two values and refuse to go on when they differ, because a reply that '
                    . 'does not match the request could have been made for somebody else.',
                fix: 'Your server has to return the state parameter unchanged, character for character.',
            );
        }

        if ($issuer !== null && is_string($iss) && $iss !== $issuer) {
            return new Step(
                id: 'return',
                title: $title,
                state: StepState::Problem,
                headline: sprintf('Your server said the reply came from %s, but its settings say it is %s.', $iss, $issuer),
                facts: $facts,
                meaning: 'Apps compare those so a reply from one server cannot be passed off as another\'s. While '
                    . 'they disagree, careful apps stop here.',
                fix: 'The iss value your server returns has to match the issuer in its settings document exactly.',
            );
        }

        // A refusal is still a reply, and it has to carry state and iss just as
        // a code would. Whether the refusal itself matters is the step above.
        if (is_string($query['error'] ?? null) && $query['error'] !== '') {
            return new Step(
                id: 'return',
                title: $title,
                state: StepState::Ok,
                headline: 'Your server reported that back to the app properly, with the state it was given.',
                facts: $facts + ['It reported' => (string) $query['error']],
                meaning: 'An app can tell this reply apart from one meant for somebody else, and show you what '
                    . 'happened rather than failing silently.',
            );
        }

        if (!is_string($code) || $code === '') {
            return new Step(
                id: 'return',
                title: $title,
                state: StepState::Problem,
                headline: 'You came back with neither a one-time code nor an error message.',
                facts: $facts,
                meaning: 'There is nothing for an app to exchange, and nothing explaining why. No app can sign you '
                    . 'in from this.',
                fix: 'Your server should either return a code, or return an error saying what went wrong.',
            );
        }

        if ($issuer !== null && !is_string($iss)) {
            return new Step(
                id: 'return',
                title: $title,
                state: StepState::Note,
                headline: 'You came back with a one-time code and the right state, but no iss value.',
                facts: $facts,
                meaning: 'The iss parameter is how an app confirms which server answered. It is recommended, and '
                    . 'some apps now insist on it.',
                fix: 'Ask whoever runs your server to include iss in the redirect back.',
            );
        }

        return new Step(
            id: 'return',
            title: $title,
            state: StepState::Ok,
            headline: 'You came back with a one-time code, the same state that was sent, and the expected iss.',
            facts: $facts,
        );
    }

    private static function errorPhrase(string $error, string $description): string
    {
        $phrase = match ($error) {
            'invalid_request'          => 'it considered the request malformed',
            'unauthorized_client'      => 'it does not allow this app',
            'unsupported_response_type' => 'it does not support this kind of login request',
            'invalid_scope'            => 'it did not accept the permissions asked for',
            'server_error'             => 'something went wrong inside it',
            'temporarily_unavailable'  => 'it is temporarily unavailable',
            default                    => $error,
        };

        return $description !== '' ? sprintf('%s (%s)', $phrase, mb_substr($description, 0, 200)) : $phrase;
    }

    private static function requestAdvice(string $error): ?string
    {
        return match ($error) {
            'invalid_request', 'unsupported_response_type' => 'Compare the request above with what your server '
                . 'expects: this is a plain, current IndieAuth request, so an app will send much the same thing.',
            'unauthorized_client' => 'Your server only allows apps it knows about. Every app not registered with '
                . 'it will fail the same way, so either register them or allow any app that publishes its details.',
            'invalid_scope'       => 'The only permission asked for here is profile. If your server refuses that, '
                . 'it will refuse most apps.',
            default               => null,
        };
    }
}

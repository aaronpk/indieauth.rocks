<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup\Steps;

use IndieAuthRocks\Checkup\Step;
use IndieAuthRocks\Checkup\StepState;

/**
 * The request the person is about to be sent with, explained parameter by
 * parameter — shown before they leave, so the page they come back to is not the
 * first time they see it.
 */
final class RequestSteps
{
    /** What each parameter is for, in the order they appear in the URL. */
    public const GLOSSARY = [
        'response_type'         => 'asks for a one-time code, which is the only kind of login apps should use',
        'client_id'             => 'who is asking. Your server fetches this address to show you what the app is',
        'redirect_uri'          => 'where your server sends you back to afterwards',
        'state'                 => 'a random value your server must return unchanged, so a reply meant for '
            . 'somebody else cannot be slipped in',
        'code_challenge'        => 'proof, without revealing the secret behind it, that whoever finishes this '
            . 'login is whoever started it',
        'code_challenge_method' => 'how that proof is computed. S256 is the one IndieAuth requires',
        'me'                    => 'a hint about who you say you are. Your server is free to ignore it',
        'scope'                 => 'the permissions being asked for. This check asks only to see your profile',
    ];

    /** @param array<string, string> $params */
    public static function pending(string $url, array $params): Step
    {
        $facts = [];

        foreach ($params as $name => $value) {
            $facts[$name] = $value;
        }

        return new Step(
            id: 'request',
            title: 'Sending you to your server',
            state: StepState::Pending,
            headline: 'This is the request an app makes to sign you in.',
            facts: $facts,
            meaning: 'Nothing has been sent yet. When you continue, your browser goes to your own server with '
                . 'these parameters, and your server decides what to show you.',
            urls: [$url],
        );
    }

    /** Once they have gone but not come back. */
    public static function waiting(Step $request): Step
    {
        return new Step(
            id: $request->id,
            title: $request->title,
            state: StepState::Unknown,
            headline: 'You were sent to your server, and have not come back yet.',
            facts: $request->facts,
            meaning: 'If your server showed you an error, compare it with the request above. If you approved and '
                . 'nothing happened, your server may be sending you somewhere other than the address in '
                . 'redirect_uri.',
            urls: $request->urls,
        );
    }

    public static function sent(Step $request): Step
    {
        return $request->with(StepState::Ok, 'Your browser was sent to your server with this request.');
    }
}

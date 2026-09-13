<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests\Checks;

use IndieAuthRocks\Engine\Verdict;
use IndieAuthRocks\ServerTests\AuthorizationAttempt;
use IndieAuthRocks\ServerTests\Landing;

/** Checks applied to every redirect back from the server under test. */
final class LandingChecks
{
    /**
     * S201, S202 and S905. These accumulate across a run: the runner keeps
     * the worst outcome seen.
     *
     * @return list<Verdict>
     */
    public static function everyLanding(Landing $landing, AuthorizationAttempt $attempt, ?string $issuer): array
    {
        $verdicts = [];

        $sent = $attempt->param('state');
        if ($sent !== null) {
            $received = $landing->param('state');

            $verdicts[] = $received === $sent
                ? Verdict::pass('S201', 'state was returned unmodified.')
                : Verdict::fail('S201', $received === null
                    ? sprintf('The redirect for %s had no state parameter.', $attempt->step)
                    : sprintf('state was changed from %s to %s.', json_encode($sent), json_encode($received)));
        }

        if ($issuer === null) {
            $verdicts[] = Verdict::skipped('S202', 'The metadata has no issuer to compare with.');
        } else {
            $iss = $landing->param('iss');

            $verdicts[] = match (true) {
                $iss === $issuer => Verdict::pass('S202', 'iss matched the issuer on every redirect.'),
                $iss === null    => Verdict::fail('S202', sprintf('The redirect for %s had no iss parameter.', $attempt->step)),
                default          => Verdict::fail('S202', sprintf('iss was %s, but the issuer is %s.', $iss, $issuer)),
            };
        }

        $leaked = array_intersect(array_keys($landing->query), ['access_token', 'refresh_token', 'id_token']);
        $verdicts[] = $leaked === []
            ? Verdict::pass('S905', 'No tokens appeared in redirect URLs.')
            : Verdict::fail('S905', sprintf('A redirect URL contained %s.', implode(', ', $leaked)));

        return $verdicts;
    }

    public static function code(Landing $landing): Verdict
    {
        $code = $landing->code();

        if ($code === null) {
            return Verdict::fail('S203', $landing->error() !== null
                ? sprintf('The server returned error=%s instead of a code.', $landing->error())
                : 'The redirect had no code parameter.');
        }

        return strlen($code) < 22
            ? Verdict::warning('S203', sprintf('The code is only %d characters long, which may be guessable.', strlen($code)))
            : Verdict::pass('S203', 'A code was issued.');
    }

    public static function preservedQuery(Landing $landing): Verdict
    {
        return $landing->param('keep') === '1'
            ? Verdict::pass('S204', 'The query string of the redirect URI was kept.')
            : Verdict::fail('S204', 'The redirect URI had ?keep=1, but the redirect back did not.');
    }
}

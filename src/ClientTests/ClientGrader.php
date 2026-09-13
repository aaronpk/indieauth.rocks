<?php

declare(strict_types=1);

namespace IndieAuthRocks\ClientTests;

use IndieAuthRocks\Engine\Verdict;

/**
 * Grades the sign-in client tests from what the client was seen doing and
 * what the person says their client showed.
 */
final class ClientGrader
{
    /** A must-reject test with no redemption this long after the response passes. */
    public const TIMEOUT = 600;

    public const ANSWER_EXPECTED = 'signed_in';
    public const ANSWER_OTHER    = 'signed_in_other';
    public const ANSWER_ERROR    = 'error';
    public const ANSWER_NOTHING  = 'nothing';

    /** @param array<string, string> $markers */
    public static function grade(int $n, array $markers, ?string $answer, int $now): ?Verdict
    {
        $id       = 'C' . $n;
        $signedIn = in_array($answer, [self::ANSWER_EXPECTED, self::ANSWER_OTHER], true);
        $refused  = in_array($answer, [self::ANSWER_ERROR, self::ANSWER_NOTHING], true);
        $issued   = isset($markers[Markers::ISSUED]) ? (int) $markers[Markers::ISSUED] : null;
        $redeemed = isset($markers[Markers::REDEEMED]);

        if (isset($markers[Markers::DECOY])) {
            return Verdict::unmet($id, 'Your client used the decoy server, which it should have ignored.');
        }

        if (isset($markers[Markers::NAIVE])) {
            return Verdict::fail($id, 'Your client resolved the relative metadata URL incorrectly.');
        }

        // Rejected before redeeming: a redemption is proof of failure.
        if (in_array($n, [301, 302, 303, 304, 306], true)) {
            return match (true) {
                $redeemed                                   => Verdict::fail($id, 'Your client redeemed the code instead of rejecting the response.'),
                $issued === null                            => null,
                $signedIn                                   => Verdict::fail($id, 'You reported that your client signed you in.'),
                $refused                                    => Verdict::pass($id, 'Your client did not redeem the code.'),
                $now - $issued > self::TIMEOUT              => Verdict::pass($id, 'Your client did not redeem the code within 10 minutes (not confirmed by you).'),
                default                                     => new Verdict($id, \IndieAuthRocks\Engine\Outcome::NEEDS_INPUT, 'Tell us what your client showed.'),
            };
        }

        if ($n === 305 || $n === 307) {
            return match (true) {
                $issued === null                => null,
                $n === 307 && $redeemed         => Verdict::unmet($id, 'Your client tried to redeem the code from the replayed response.'),
                $signedIn                       => Verdict::unmet($id, 'You reported that your client signed you in.'),
                $refused                        => Verdict::pass($id, $n === 305 ? 'Your client handled the error response.' : 'Your client did not accept the replayed response.'),
                $now - $issued > self::TIMEOUT  => Verdict::pass($id, 'Nothing was redeemed within 10 minutes (not confirmed by you).'),
                default                         => new Verdict($id, \IndieAuthRocks\Engine\Outcome::NEEDS_INPUT, 'Tell us what your client showed.'),
            };
        }

        // Rejected after redeeming: only the person can tell.
        if (in_array($n, [403, 406, 407, 409], true)) {
            return match (true) {
                !$redeemed && $issued === null => null,
                $signedIn                      => Verdict::unmet($id, 'You reported that your client signed you in.'),
                $refused                       => Verdict::pass($id, 'Your client refused the sign-in.'),
                default                        => new Verdict($id, \IndieAuthRocks\Engine\Outcome::NEEDS_INPUT, 'Tell us what your client showed.'),
            };
        }

        if ($n === 502) {
            return match (true) {
                isset($markers[Markers::REFRESHED])                                    => Verdict::pass($id, 'Your client refreshed its access token.'),
                $redeemed && $now - (int) ($markers[Markers::ISSUED] ?? $now) > 300    => Verdict::skipped($id, 'Your client did not refresh within 5 minutes.'),
                default                                                                => null,
            };
        }

        // Everything else is a sign-in that should succeed.
        $started = isset($markers[Markers::PROFILE]) || isset($markers[Markers::AUTHORIZE]) || $issued !== null;

        if (!$redeemed) {
            return match (true) {
                !$started                  => null,
                $n === 108 && $refused     => Verdict::warning($id, 'Your client did not fall back to legacy endpoint discovery.'),
                $n === 111 && $refused     => Verdict::pass($id, 'Your client requires a full URL, which is allowed.'),
                $refused                   => Verdict::fail($id, 'The sign-in did not complete.'),
                default                    => new Verdict($id, \IndieAuthRocks\Engine\Outcome::NEEDS_INPUT, 'The sign-in has not finished yet.'),
            };
        }

        if (in_array($n, [402, 404], true) && !isset($markers[Markers::ME_FETCHED])) {
            return Verdict::fail($id, 'Your client accepted a different profile URL without fetching it to confirm it uses this server.');
        }

        return match ($answer) {
            self::ANSWER_EXPECTED => Verdict::pass($id, 'Your client signed you in as expected.'),
            self::ANSWER_OTHER    => Verdict::fail($id, $n === 408
                ? 'Your client identified you by the profile information instead of me.'
                : 'Your client signed you in as the wrong profile URL.'),
            self::ANSWER_ERROR, self::ANSWER_NOTHING => Verdict::fail($id, 'Your client did not finish a valid sign-in.'),
            default => new Verdict($id, \IndieAuthRocks\Engine\Outcome::NEEDS_INPUT, 'The code was redeemed. Tell us what your client showed.'),
        };
    }

    /** The profile URL a person should end up signed in as, for the question. */
    public static function expectedMe(ClientTestUrls $urls, int $n): string
    {
        return match ($n) {
            402     => $urls->profile(402, 'other'),
            404     => $urls->altProfile(404),
            405     => $urls->profile(405, 'final'),
            default => $urls->profile($n),
        };
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\ClientTests;

use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Mode;

/**
 * The client checks nobody runs on purpose: they are made while the sign-in
 * tests are run, from the requests a client happens to make. Their pages have
 * nothing to do, so each says what would exercise it.
 */
final class AutomaticChecks
{
    /** What a check is waiting for, when it is not simply every authorization request. */
    private const WAITS_FOR = [
        'C203' => 'this site fetches your client_id and compares its redirect URIs with the request',
        'C208' => 'this site fetches your client_id to read its metadata document',
        'C209' => 'this site reads the client_uri in your client metadata document',
        'C401' => 'your client redeems an authorization code',
        'C410' => 'your client redeems a code from a sign-in that asked for no scope',
        'C501' => 'your client calls the userinfo endpoint with an access token, which needs a sign-in that asked for a scope',
        'C503' => 'your client refreshes an access token, which the C502 profile hands out with a two-minute lifetime',
        'C504' => 'your client refreshes an access token',
        'C505' => 'your client calls the revocation endpoint, as it would when you sign out',
    ];

    public static function isAutomatic(string $testId): bool
    {
        return Catalog::find($testId)?->mode === Mode::AUTO && !Scenario::exists((int) substr($testId, 1));
    }

    /** One line saying what exercises the check. */
    public static function hint(string $testId): string
    {
        return sprintf(
            'This check is made while you run the other client tests, whenever %s. There is nothing to start here.',
            self::WAITS_FOR[$testId] ?? 'your client sends an authorization request',
        );
    }
}

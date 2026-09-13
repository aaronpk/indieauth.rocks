<?php

declare(strict_types=1);

namespace IndieAuthRocks\ClientTests;

/**
 * How the profile page and test server behave for one client test.
 */
final class Scenario
{
    /** Tests a person runs by signing in to their client with a profile URL. */
    public const SIGNIN_TESTS = [
        101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111,
        301, 302, 303, 304, 305, 306, 307,
        402, 403, 404, 405, 406, 407, 408, 409,
        502,
    ];

    /** Tests where the client must not end up signed in. */
    public const MUST_REJECT = [301, 302, 303, 304, 305, 306, 403, 406, 407, 409];

    /** Tests where the rejection is visible because the client does not redeem the code. */
    public const REJECT_BEFORE_REDEEMING = [301, 302, 303, 304, 306];

    public function __construct(
        public readonly int $n,
        /** Metadata link in an HTTP Link header, pointing at the real (true) or decoy (false) server. */
        public readonly ?bool $headerLink,
        /** @var list<bool> HTML <link> elements in order: true real, false decoy. */
        public readonly array $htmlLinks,
        public readonly bool $relativeLink = false,
        public readonly bool $multiRel = false,
        /** Legacy authorization_endpoint/token_endpoint links: true real, false decoy, null none. */
        public readonly ?bool $legacyLinks = null,
        /** The profile URL redirects before reaching the page with the links. */
        public readonly bool $redirects = false,
        /** normal, wrong_state, no_iss, wrong_iss, near_iss, error */
        public readonly string $authorizationResponse = 'normal',
        /** normal, me_same_domain, me_other_server, me_other_domain, me_final, no_me, invalid_me, spoofed_profile, invalid_grant, expiring */
        public readonly string $tokenResponse = 'normal',
    ) {
    }

    public static function for(int $n): self
    {
        return match ($n) {
            102     => new self($n, true, []),
            103     => new self($n, true, [false]),
            104     => new self($n, null, [true, false]),
            105     => new self($n, null, [true], relativeLink: true),
            106     => new self($n, null, [true], relativeLink: true, redirects: true),
            107     => new self($n, null, [true], multiRel: true),
            108     => new self($n, null, [], legacyLinks: true),
            109     => new self($n, null, [true], legacyLinks: false),
            301     => new self($n, null, [true], authorizationResponse: 'wrong_state'),
            302     => new self($n, null, [true], authorizationResponse: 'no_iss'),
            303     => new self($n, null, [true], authorizationResponse: 'wrong_iss'),
            304     => new self($n, null, [true], authorizationResponse: 'near_iss'),
            305     => new self($n, null, [true], authorizationResponse: 'error'),
            402     => new self($n, null, [true], tokenResponse: 'me_same_domain'),
            403     => new self($n, null, [true], tokenResponse: 'me_other_server'),
            404     => new self($n, null, [true], tokenResponse: 'me_other_domain'),
            405     => new self($n, null, [true], redirects: true, tokenResponse: 'me_final'),
            406     => new self($n, null, [true], tokenResponse: 'no_me'),
            407     => new self($n, null, [true], tokenResponse: 'invalid_me'),
            408     => new self($n, null, [true], tokenResponse: 'spoofed_profile'),
            409     => new self($n, null, [true], tokenResponse: 'invalid_grant'),
            502     => new self($n, null, [true], tokenResponse: 'expiring'),
            default => new self($n, null, [true]),
        };
    }

    public static function exists(int $n): bool
    {
        return in_array($n, self::SIGNIN_TESTS, true);
    }

    /** Whether the test server for this test sends iss (not for legacy-only discovery). */
    public function sendsIss(): bool
    {
        return $this->legacyLinks !== true;
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Engine;

/**
 * Tests for IndieAuth clients. This site plays the server, with a profile URL
 * per test that each behave in one specific way.
 */
final class ClientCatalog
{
    public const DISCOVERY = 'Discovery';
    public const REQUEST   = 'Authorization requests';
    public const RESPONSE  = 'Authorization responses';
    public const IDENTITY  = 'Redemption and identity';
    public const TOKENS    = 'Tokens';

    /** @var array<string, TestDefinition>|null */
    private static ?array $tests = null;

    /** @return array<string, TestDefinition> */
    public static function tests(): array
    {
        if (self::$tests !== null) {
            return self::$tests;
        }

        $tests = [];
        $add   = static function (
            string $id,
            string $section,
            string $title,
            Level $level,
            Mode $mode,
            string $spec,
            string $description,
            string $passes,
            array $dependsOn = [],
        ) use (&$tests): void {
            $tests[$id] = new TestDefinition($id, Catalog::CLIENT, $section, $title, $level, $mode, $spec, $description, $passes, $dependsOn);
        };

        // ------------------------------------------------------------ 100s

        $add('C101', self::DISCOVERY, 'Discovers the server from an HTML link', Level::MUST, Mode::SIGNIN, '§4.1',
            'The profile page has <link rel="indieauth-metadata"> in its HTML. Sign in with it.',
            'Your client fetches the profile and metadata, sends a valid authorization request, redeems the code, and you end up signed in as the profile URL.');
        $add('C102', self::DISCOVERY, 'Discovers the server from a Link header', Level::MUST, Mode::SIGNIN, '§4.1',
            'The metadata URL is only in an HTTP Link header.',
            'Your client finds it and completes the sign-in.');
        $add('C103', self::DISCOVERY, 'Prefers the Link header over HTML', Level::MUST, Mode::SIGNIN, '§4.1',
            'The Link header points at the real server; the HTML link points at a decoy server.',
            'Your client uses the server from the Link header. Any request to the decoy is a failure.');
        $add('C104', self::DISCOVERY, 'Uses the first HTML link', Level::MUST, Mode::SIGNIN, '§4.1',
            'The page has two indieauth-metadata links; the second points at a decoy.',
            'Your client uses the first link.');
        $add('C105', self::DISCOVERY, 'Resolves a relative metadata URL', Level::MUST, Mode::SIGNIN, '§4.1',
            'The metadata link is a relative URL.',
            'Your client resolves it against the profile URL. A naively joined URL lands on a decoy.');
        $add('C106', self::DISCOVERY, 'Follows redirects during discovery', Level::MUST, Mode::SIGNIN, '§4.1',
            'The profile URL redirects twice before reaching a page with a relative metadata link.',
            'Your client follows the redirects and resolves the link against the final URL.');
        $add('C107', self::DISCOVERY, 'Handles multiple rel values', Level::MUST, Mode::SIGNIN, '§4.1',
            'The link is rel="me indieauth-metadata".',
            'Your client recognizes indieauth-metadata among the rel values.');
        $add('C108', self::DISCOVERY, 'Supports legacy endpoint discovery', Level::SHOULD, Mode::SIGNIN, '§4.1',
            'The page has only rel="authorization_endpoint" and rel="token_endpoint" links, as older servers publish.',
            'Your client falls back to them and completes the sign-in.');
        $add('C109', self::DISCOVERY, 'Prefers metadata over legacy links', Level::SHOULD, Mode::SIGNIN, '§4.1',
            'The page has both, and the legacy links point at a decoy.',
            'Your client uses the metadata.');
        $add('C110', self::DISCOVERY, 'Uses endpoints on another host', Level::MUST, Mode::SIGNIN, '§4.1.1',
            'The metadata lists a token endpoint on a different host, plus fields your client will not recognize.',
            'Your client redeems the code at the listed token endpoint.');
        $add('C111', self::DISCOVERY, 'Accepts a URL typed without a scheme', Level::MAY, Mode::SIGNIN, '§3.4',
            'Type the profile URL without https://.',
            'Your client turns it into a valid URL and signs in. Clients that require a full URL are noted.');
        $add('C112', self::DISCOVERY, 'Ignores a metadata link in the page body', Level::SHOULD, Mode::SIGNIN, '§4.1',
            'The real link is in <head>. A second indieauth-metadata link, pointing at a decoy server, is in the page body — where comments, bios and other people\'s content end up on many sites.',
            'Your client uses the link from <head> and never touches the decoy. Reading links from the body lets anyone who can put HTML on a page choose its server.');

        // ------------------------------------------------------------ 200s

        $add('C201', self::REQUEST, 'Requests an authorization code', Level::MUST, Mode::AUTO, '§5.2',
            'Checked on every authorization request your client sends.',
            'response_type is "code".', ['C101']);
        $add('C202', self::REQUEST, 'Sends a valid client_id', Level::MUST, Mode::AUTO, '§3.3',
            'Checks the client_id against the client identifier rules.',
            'client_id is an http(s) URL with a path, no fragment or credentials, on a domain name or loopback address.', ['C101']);
        $add('C203', self::REQUEST, 'Uses an allowed redirect_uri', Level::SHOULD, Mode::AUTO, '§4.2.2',
            'Checks the redirect_uri against the client_id.',
            'The redirect_uri shares the client_id origin, or is listed in the client metadata.', ['C101']);
        $add('C204', self::REQUEST, 'Sends an unguessable state', Level::MUST, Mode::AUTO, '§5.2, RFC 9700 §4.7',
            'Checks the state parameter across attempts.',
            'state is present. Short or repeated values are a warning.', ['C101']);
        $add('C205', self::REQUEST, 'Uses PKCE with S256', Level::MUST, Mode::AUTO, '§5.2',
            'Checks code_challenge and code_challenge_method.',
            'A 43-character S256 challenge is sent.', ['C101']);
        $add('C206', self::REQUEST, 'Uses a new PKCE challenge each time', Level::SHOULD, Mode::AUTO, 'RFC 7636 §4.1',
            'Compares challenges across attempts.',
            'No challenge is reused.', ['C101']);
        $add('C207', self::REQUEST, 'Sends the me parameter', Level::SHOULD, Mode::AUTO, '§5.2',
            'Checks for me in the authorization request.',
            'me is the profile URL that was entered.', ['C101']);
        $add('C208', self::REQUEST, 'Publishes client metadata', Level::SHOULD, Mode::AUTO, '§4.2, §4.2.1',
            'The test server fetches your client_id.',
            'A JSON client metadata document is served, and its client_id matches the URL. A mismatch is a failure.', ['C101']);
        $add('C209', self::REQUEST, 'client_uri is a prefix of client_id', Level::MUST, Mode::AUTO, '§4.2.1',
            'When your metadata includes client_uri, checks it.',
            'client_uri is a prefix of client_id.', ['C208']);
        $add('C210', self::REQUEST, 'Requests valid scopes', Level::MUST, Mode::AUTO, '§5.3.4, RFC 6749 §3.3',
            'Checks the scope parameter.',
            'Scopes are valid tokens, and email is never requested without profile.', ['C101']);

        // ------------------------------------------------------------ 300s

        $add('C301', self::RESPONSE, 'Rejects a mismatched state', Level::MUST, Mode::SIGNIN, '§5.2.1',
            'The test server redirects back with a changed state value.',
            'Your client does not redeem the code and shows an error.', ['C101']);
        $add('C302', self::RESPONSE, 'Rejects a response without iss', Level::MUST, Mode::SIGNIN, '§5.2.1, RFC 9207 §2.4',
            'The metadata says iss is supported, but the redirect leaves it out.',
            'Your client does not redeem the code.', ['C101']);
        $add('C303', self::RESPONSE, 'Rejects iss from another issuer', Level::MUST, Mode::SIGNIN, '§5.2.1, RFC 9207 §2.4',
            'The redirect carries the issuer of a different server.',
            'Your client does not redeem the code.', ['C101']);
        $add('C304', self::RESPONSE, 'Compares iss exactly', Level::MUST, Mode::SIGNIN, 'RFC 9207 §2.4',
            'The redirect carries an iss that differs from the issuer only by a trailing slash.',
            'Your client does not redeem the code.', ['C101']);
        $add('C305', self::RESPONSE, 'Handles an error response', Level::MUST, Mode::SIGNIN, '§5.2.1, RFC 6749 §4.1.2.1',
            'The test server redirects back with error=access_denied.',
            'Your client shows an error and does not try to redeem anything.', ['C101']);
        $add('C306', self::RESPONSE, 'Rejects an unsolicited response', Level::MUST, Mode::SIGNIN, '§5.2.1, RFC 9700 §4.7',
            'A link sends your browser straight to your redirect_uri with a valid code and a state your client never created.',
            'Your client does not redeem the code (this prevents login CSRF).', ['C101']);
        $add('C307', self::RESPONSE, 'Does not accept a replayed response', Level::SHOULD, Mode::SIGNIN, 'RFC 9700 §4.2',
            'A link replays the redirect from your first sign-in.',
            'Your client does not sign you in again from the old response.', ['C101']);

        // ------------------------------------------------------------ 400s

        $add('C401', self::IDENTITY, 'Redeems the code correctly', Level::MUST, Mode::AUTO, '§5.3.1',
            'Checks the code redemption request.',
            'A form-encoded POST with grant_type, code, the same client_id and redirect_uri, and a verifier matching the challenge.', ['C101']);
        $add('C402', self::IDENTITY, 'Verifies a different me on the same domain', Level::MUST, Mode::SIGNIN, '§5.4',
            'The server returns a different profile URL on this domain, whose page declares the same server.',
            'Your client fetches the returned URL, confirms it, and signs you in as it.', ['C101']);
        $add('C403', self::IDENTITY, 'Rejects a me that uses another server', Level::MUST, Mode::SIGNIN, '§5.4',
            'The server returns a profile URL on another domain whose page declares a different server.',
            'Your client refuses the sign-in. Signing in as that URL is a failure.', ['C101']);
        $add('C404', self::IDENTITY, 'Accepts a me on another domain that uses this server', Level::MUST, Mode::SIGNIN, '§5.4',
            'The server returns a profile URL on another domain that declares the same server.',
            'Your client fetches it, confirms it, and signs you in as it.', ['C101']);
        $add('C405', self::IDENTITY, 'Accepts a me seen during discovery redirects', Level::MUST, Mode::SIGNIN, '§5.4',
            'The profile URL redirects, and the server returns the final URL as me.',
            'Your client signs you in as the final URL.', ['C101']);
        $add('C406', self::IDENTITY, 'Rejects a response without me', Level::MUST, Mode::SIGNIN, '§5.3.2, §5.3.3',
            'The token response leaves out me.',
            'Your client does not sign you in.', ['C101']);
        $add('C407', self::IDENTITY, 'Rejects an invalid me', Level::SHOULD, Mode::SIGNIN, '§3.2',
            'The server returns a me with a port and a fragment, which is not a valid profile URL.',
            'Your client does not sign you in.', ['C101']);
        $add('C408', self::IDENTITY, 'Does not trust profile information for identity', Level::MUST, Mode::SIGNIN, '§5.3.4',
            'The profile object claims a different url and someone else\'s email.',
            'Your client identifies you by me, not by the profile information.', ['C101']);
        $add('C409', self::IDENTITY, 'Handles a token endpoint error', Level::MUST, Mode::SIGNIN, 'RFC 6749 §5.2',
            'The token endpoint returns invalid_grant.',
            'Your client shows an error and does not sign you in.', ['C101']);
        $add('C410', self::IDENTITY, 'Where sign-in-only codes are redeemed', Level::INFO, Mode::AUTO, '§5.3.2',
            'Notes whether your client redeems codes without scope at the authorization endpoint or the token endpoint.',
            'Informational.', ['C101']);

        // ------------------------------------------------------------ 500s

        $add('C501', self::TOKENS, 'Sends access tokens in the Authorization header', Level::SHOULD, Mode::AUTO, 'RFC 6750 §2.1, RFC 9700 §4.3.2',
            'Watches requests your client makes to the test server\'s userinfo endpoint.',
            'Tokens are sent as Authorization: Bearer, never in the URL.', ['C101']);
        $add('C502', self::TOKENS, 'Refreshes expiring tokens', Level::MAY, Mode::AUTO, '§5.5',
            'Access tokens from this test expire after two minutes and come with a refresh token.',
            'Your client uses grant_type=refresh_token with its client_id. Skipped if it never refreshes.', ['C101']);
        $add('C503', self::TOKENS, 'Replaces rotated refresh tokens', Level::MUST, Mode::AUTO, '§5.5',
            'Every refresh returns a new refresh token.',
            'Your client uses the newest one. Using an old one is a failure.', ['C502']);
        $add('C504', self::TOKENS, 'Does not ask for more scope on refresh', Level::MUST, Mode::AUTO, '§5.5',
            'Checks the scope parameter of refresh requests.',
            'Refresh requests ask for no more than was granted.', ['C502']);
        $add('C505', self::TOKENS, 'Revokes tokens on sign-out', Level::INFO, Mode::AUTO, '§7',
            'Notes whether your client calls the revocation endpoint when you sign out.',
            'Informational.', ['C101']);

        return self::$tests = $tests;
    }
}

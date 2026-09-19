<?php

declare(strict_types=1);

namespace IndieAuthRocks\Engine;

/**
 * Tests for IndieAuth servers. This site plays the client.
 *
 * Numbers are stable once published: new tests take unused numbers in their
 * hundred, and retired numbers are never reused.
 */
final class ServerCatalog
{
    public const DISCOVERY     = 'Discovery';
    public const AUTHORIZATION = 'Authorization requests';
    public const REDEMPTION    = 'Code redemption';
    public const TOKENS        = 'Token responses';
    public const REFRESH       = 'Refresh tokens';
    public const REVOCATION    = 'Revocation';
    public const USERINFO      = 'Userinfo';
    public const INTROSPECTION = 'Introspection';
    public const HYGIENE       = 'Security hygiene';

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
            bool $extended = false,
        ) use (&$tests): void {
            $tests[$id] = new TestDefinition($id, Catalog::SERVER, $section, $title, $level, $mode, $spec, $description, $passes, $dependsOn, $extended);
        };

        // ------------------------------------------------------------ 100s

        $add('S101', self::DISCOVERY, 'Profile URL is valid and can be fetched', Level::MUST, Mode::AUTO, '§3.2, §4.1',
            'Fetches the profile URL you entered, following redirects, after checking it is a valid user profile URL.',
            'The URL is a valid profile URL and returns HTTP 200, possibly after redirects.');
        $add('S102', self::DISCOVERY, 'Advertises indieauth-metadata', Level::MUST, Mode::AUTO, '§4.1',
            'Looks for rel="indieauth-metadata" in an HTTP Link header, then in an HTML <link> element.',
            'The metadata URL is found. Only legacy authorization_endpoint/token_endpoint links is a warning.', ['S101']);
        $add('S103', self::DISCOVERY, 'Link header and HTML agree', Level::SHOULD, Mode::AUTO, '§4.1',
            'When both a Link header and an HTML link are present, compares them. Clients use the header first.',
            'Both point at the same metadata URL, or only one is present.', ['S102']);
        $add('S104', self::DISCOVERY, 'Metadata is a JSON document', Level::MUST, Mode::AUTO, '§4.1.1, RFC 8414 §3.2',
            'Fetches the metadata URL.',
            'Returns HTTP 200 with a JSON object and a JSON content type, without redirecting.', ['S102']);
        $add('S105', self::DISCOVERY, 'Issuer identifier is valid', Level::MUST, Mode::AUTO, '§3.1, §4.1.1',
            'Checks the issuer value in the metadata.',
            'issuer is an https URL with no query or fragment, and either is a prefix of the metadata URL or the metadata URL is its RFC 8414 well-known location.', ['S104']);
        $add('S106', self::DISCOVERY, 'Authorization endpoint is listed', Level::MUST, Mode::AUTO, '§4.1.1',
            'Checks authorization_endpoint in the metadata.',
            'authorization_endpoint is an absolute URL.', ['S104']);
        $add('S107', self::DISCOVERY, 'Token endpoint is listed', Level::MUST, Mode::AUTO, '§4.1.1',
            'Checks token_endpoint in the metadata. Without one, token tests are skipped.',
            'token_endpoint is an absolute URL.', ['S104']);
        $add('S108', self::DISCOVERY, 'Introspection endpoint is listed', Level::SHOULD, Mode::AUTO, '§4.1.1, §6',
            'Checks introspection_endpoint, which resource servers such as Micropub endpoints use to verify tokens.',
            'introspection_endpoint is an absolute URL.', ['S104']);
        $add('S109', self::DISCOVERY, 'PKCE S256 is supported', Level::MUST, Mode::AUTO, '§4.1.1',
            'Checks code_challenge_methods_supported, which is required in IndieAuth metadata.',
            'The list is present and includes "S256".', ['S104']);
        $add('S110', self::DISCOVERY, 'Advertises the iss response parameter', Level::SHOULD, Mode::AUTO, '§4.1.1, RFC 9207 §3',
            'Checks authorization_response_iss_parameter_supported.',
            'The value is true.', ['S104']);
        $add('S111', self::DISCOVERY, 'Lists supported scopes', Level::SHOULD, Mode::AUTO, '§4.1.1',
            'Checks scopes_supported.',
            'scopes_supported is a list of strings.', ['S104']);
        $add('S112', self::DISCOVERY, 'Response and grant types are consistent', Level::SHOULD, Mode::AUTO, 'RFC 8414 §2, RFC 9700 §2.1.2',
            'Checks response_types_supported and grant_types_supported when present.',
            'They include "code" and "authorization_code", and do not advertise the implicit "token" response type.', ['S104']);
        $add('S113', self::DISCOVERY, 'Revocation needs no client authentication', Level::SHOULD, Mode::AUTO, '§4.1.1, §7',
            'When a revocation_endpoint is listed, checks revocation_endpoint_auth_methods_supported.',
            'The value is ["none"].', ['S104']);
        $add('S114', self::DISCOVERY, 'Metadata can be read by browser-based apps', Level::INFO, Mode::AUTO, 'RFC 8414',
            'Notes whether the metadata response allows cross-origin reads.',
            'Informational.', ['S104']);
        $add('S115', self::DISCOVERY, 'Legacy endpoint links match the metadata', Level::SHOULD, Mode::AUTO, '§4.1',
            'If the page also has authorization_endpoint or token_endpoint links for older clients, compares them with the metadata.',
            'Any legacy links point at the same endpoints as the metadata.', ['S102', 'S104']);
        $add('S116', self::DISCOVERY, 'Endpoints use https', Level::MUST, Mode::AUTO, 'RFC 6749 §3.1, §3.2',
            'Checks every endpoint URL in the metadata.',
            'All endpoints are https URLs.', ['S104']);
        $add('S117', self::DISCOVERY, 'Serves metadata at the RFC 8414 well-known URL', Level::SHOULD, Mode::AUTO, 'RFC 8414 §3',
            'Derives the well-known URL from the issuer — /.well-known/oauth-authorization-server inserted between the host and the issuer\'s path — and fetches it.',
            'The document is served there and names the same issuer. Not serving it is a warning: a client that starts from the issuer rather than from a profile page cannot find the metadata.', ['S105']);

        // ------------------------------------------------------------ 200s

        $add('S201', self::AUTHORIZATION, 'Returns state unmodified', Level::MUST, Mode::AUTO, '§5.2.1',
            'Every time your server redirects back here, compares the state parameter with the one sent. The state includes characters that are easy to mangle.',
            'state is byte-for-byte identical on every redirect.', ['S106']);
        $add('S202', self::AUTHORIZATION, 'Returns the iss parameter', Level::MUST, Mode::AUTO, '§5.2.1, RFC 9207 §2',
            'Every time your server redirects back here, including with errors, checks the iss parameter.',
            'iss is present and exactly equal to the issuer in the metadata.', ['S105']);
        $add('S203', self::AUTHORIZATION, 'Issues an authorization code', Level::MUST, Mode::APPROVE, '§5.2.1, RFC 6749 §10.10',
            'After you approve the sign-in request, checks the code parameter.',
            'A code is present. A code shorter than 22 characters is a warning.', ['S106']);
        $add('S204', self::AUTHORIZATION, 'Keeps the query string of the redirect URI', Level::MUST, Mode::APPROVE, 'RFC 6749 §3.1.2',
            'Uses a registered redirect URI that already has a query string, and checks it survives.',
            'The redirect keeps the original query parameter and adds code, state and iss.', ['S106']);
        $add('S205', self::AUTHORIZATION, 'Fetches client information', Level::SHOULD, Mode::AUTO, '§4.2, §10.1',
            'This site publishes a client metadata document at a client_id unique to your test run, and watches for your server fetching it.',
            'The client_id document is fetched during the sign-in.', ['S106']);
        $add('S206', self::AUTHORIZATION, 'Consent screen identifies the application', Level::MUST, Mode::MANUAL, '§5.2, §10.1',
            'Asks what your approval screen showed.',
            'The screen shows the application name or its client_id. Showing only a name from the metadata, without the client_id, is a warning.', ['S203']);
        $add('S207', self::AUTHORIZATION, 'Reports a denied request to the client', Level::MUST, Mode::PROBE, 'RFC 6749 §4.1.2.1, RFC 9207',
            'Sends a normal request, which you cancel.',
            'Your server redirects back with error=access_denied, the original state and iss.', ['S106']);
        $add('S208', self::AUTHORIZATION, 'Refuses an unregistered cross-origin redirect URI', Level::MUST, Mode::PROBE, '§4.2.2, §10.1, RFC 6749 §4.1.2.1',
            'Sends a request whose redirect_uri is on a different host from the client_id and is not listed in the client metadata.',
            'Your server shows an error and never sends the browser to the unregistered URI. Showing the full redirect URL on the consent screen instead is a warning; redirecting with a code is a failure.', ['S106']);
        $add('S209', self::AUTHORIZATION, 'Accepts a registered cross-origin redirect URI', Level::MUST, Mode::PROBE, '§4.2.2',
            'Sends a request whose cross-origin redirect_uri is listed in the client metadata, which you cancel.',
            'Your server allows the request and redirects there with error=access_denied after you cancel.', ['S106']);
        $add('S210', self::AUTHORIZATION, 'Matches redirect URIs exactly', Level::MUST, Mode::PROBE, '§4.2.2',
            'Registers a cross-origin redirect URI and sends a request for a longer URL that starts with it.',
            'Your server refuses it: prefix matches are not matches.', ['S106']);
        $add('S211', self::AUTHORIZATION, 'Is not fooled by credentials in a URL', Level::MUST, Mode::PROBE, '§4.2.2, §10.1',
            'Sends a redirect_uri like https://client-host@other-host/, which looks like the client host but is not.',
            'Your server refuses it.', ['S106']);
        $add('S212', self::AUTHORIZATION, 'Ignores client metadata for a different client_id', Level::MUST, Mode::PROBE, '§4.2.1',
            'Serves a client metadata document whose client_id does not match the URL it came from, listing an unregistered redirect URI.',
            'Your server does not trust the document and refuses the redirect URI.', ['S106']);
        $add('S213', self::AUTHORIZATION, 'Rejects a repeated redirect_uri parameter', Level::MUST, Mode::PROBE, 'RFC 6749 §3.1',
            'Sends redirect_uri twice: once registered, once not.',
            'Your server refuses the request rather than picking one of the values.', ['S106']);
        $add('S214', self::AUTHORIZATION, 'Is not an open redirector', Level::MUST, Mode::PROBE, 'RFC 6749 §4.1.2.1',
            'Sends a request with no client_id and an unregistered redirect_uri.',
            'Your server shows an error instead of redirecting.', ['S106']);
        $add('S215', self::AUTHORIZATION, 'Requires PKCE', Level::SHOULD, Mode::PROBE, '§5.2, RFC 9700 §2.1.1',
            'Sends a request without code_challenge. The spec allows servers to accept this for older clients, but current best practice is to require PKCE.',
            'Your server refuses the request.', ['S106']);
        $add('S216', self::AUTHORIZATION, 'Refuses the plain PKCE method', Level::SHOULD, Mode::PROBE, 'RFC 9700 §2.1.1',
            'Sends code_challenge_method=plain.',
            'Your server refuses the request, unless it advertises plain support.', ['S109']);
        $add('S217', self::AUTHORIZATION, 'Refuses the implicit flow', Level::MUST, Mode::PROBE, '§5.2, RFC 9700 §2.1.2',
            'Sends response_type=token.',
            'Your server refuses the request and never puts a token in the URL.', ['S106']);
        $add('S218', self::AUTHORIZATION, 'Handles a missing state parameter', Level::SHOULD, Mode::PROBE, '§5.2',
            'Sends a request without state, which IndieAuth clients are required to include.',
            'Your server refuses the request.', ['S106']);
        $add('S219', self::AUTHORIZATION, 'Refuses an invalid client_id', Level::SHOULD, Mode::PROBE, '§3.3',
            'Sends a client_id containing a ".." path segment, which is not a valid client identifier.',
            'Your server refuses the request.', ['S106']);
        $add('S220', self::AUTHORIZATION, 'Does not trust the me parameter', Level::MUST, Mode::PROBE, '§5.2',
            'Sends me= set to someone else\'s profile URL.',
            'Your server either refuses, or signs you in as yourself. Returning the requested me without verifying it is a failure.', ['S106']);
        $add('S221', self::AUTHORIZATION, 'Refuses a PKCE method without a challenge', Level::SHOULD, Mode::PROBE, '§5.2, RFC 7636 §4.3',
            'Sends code_challenge_method=S256 with no code_challenge. A server that still allows older clients to sign in without PKCE can mistake this for one of them, and a challenge stripped from a real client\'s request leaves a code anyone can redeem.',
            'Your server refuses the request.', ['S106']);
        $add('S222', self::AUTHORIZATION, 'Refuses a challenge without a method', Level::SHOULD, Mode::PROBE, 'RFC 7636 §4.3, RFC 9700 §2.1.1',
            'Sends code_challenge with no code_challenge_method. RFC 7636 says a missing method means plain, which is not allowed here.',
            'Your server refuses the request, unless it advertises plain support.', ['S109']);

        // ------------------------------------------------------------ 300s

        $add('S301', self::REDEMPTION, 'Exchanges a code at the token endpoint', Level::MUST, Mode::APPROVE, '§5.3.1, §5.3.3',
            'Exchanges the code from your sign-in, with the correct PKCE verifier.',
            'The token endpoint returns HTTP 200.', ['S107', 'S203']);
        $add('S302', self::REDEMPTION, 'Codes can only be used once', Level::MUST, Mode::AUTO, '§5.2.1',
            'Presents an already-exchanged code again.',
            'The token endpoint returns an error, ideally invalid_grant.', ['S301']);
        $add('S303', self::REDEMPTION, 'Reusing a code revokes its tokens', Level::SHOULD, Mode::AUTO, 'RFC 6749 §4.1.2, RFC 9700 §4.5',
            'After replaying a code, checks whether the tokens first issued for it still work at the userinfo endpoint.',
            'The tokens no longer work. Skipped without a userinfo endpoint.', ['S302']);
        $add('S304', self::REDEMPTION, 'Codes expire', Level::MUST, Mode::APPROVE, '§5.2.1',
            'Waits 11 minutes after approval before exchanging a code.',
            'The token endpoint refuses the expired code.', ['S107'], true);
        $add('S310', self::REDEMPTION, 'Refuses a wrong code_verifier', Level::MUST, Mode::APPROVE, '§5.3.1',
            'Exchanges a fresh code with a verifier that does not match the challenge.',
            'The token endpoint returns an error and issues no tokens.', ['S107']);
        $add('S311', self::REDEMPTION, 'Refuses a missing code_verifier', Level::MUST, Mode::APPROVE, '§5.3.1',
            'Exchanges a code that was issued with a challenge, without sending a verifier.',
            'The exchange is refused.', ['S106']);
        $add('S312', self::REDEMPTION, 'Refuses a code presented by another client', Level::MUST, Mode::APPROVE, '§5.3.1',
            'Exchanges a code with a different client_id from the authorization request.',
            'The exchange is refused.', ['S106']);
        $add('S313', self::REDEMPTION, 'Refuses a mismatched redirect_uri', Level::MUST, Mode::APPROVE, '§5.3.1',
            'Exchanges a code with a different redirect_uri from the authorization request.',
            'The exchange is refused.', ['S106']);
        $add('S320', self::REDEMPTION, 'Exchanges a code at the authorization endpoint', Level::MUST, Mode::APPROVE, '§5.3.2',
            'Signs in without requesting any scope, then exchanges the code at the authorization endpoint.',
            'Returns HTTP 200 with me, and no access_token.', ['S106']);
        $add('S321', self::REDEMPTION, 'No access token without a scope', Level::MUST, Mode::APPROVE, '§5.3.3',
            'Signs in without requesting any scope, then exchanges the code at the token endpoint.',
            'Returns me but no access_token.', ['S107'], true);
        $add('S322', self::REDEMPTION, 'Refuses a verifier for a code issued without PKCE', Level::MUST, Mode::APPROVE, '§5.3.1',
            'Only when your server accepted a request without PKCE: exchanges that code with a code_verifier.',
            'The exchange is refused.', ['S215'], true);
        $add('S330', self::REDEMPTION, 'Refuses an unknown code', Level::MUST, Mode::AUTO, 'RFC 6749 §5.2',
            'Sends a made-up code to the token endpoint.',
            'Returns HTTP 400 with error=invalid_grant.', ['S107']);
        $add('S331', self::REDEMPTION, 'Refuses missing or unsupported grant types', Level::MUST, Mode::AUTO, 'RFC 6749 §5.2',
            'Sends one request without grant_type and one with grant_type=password.',
            'Both are refused, with invalid_request and unsupported_grant_type. Other error codes are a warning.', ['S107']);
        $add('S332', self::REDEMPTION, 'Errors are JSON with an error code', Level::MUST, Mode::AUTO, 'RFC 6749 §5.2',
            'Checks the error responses from the token endpoint.',
            'Errors are JSON objects with an "error" member.', ['S330']);
        $add('S333', self::REDEMPTION, 'Token endpoint refuses GET requests', Level::SHOULD, Mode::AUTO, 'RFC 6749 §3.2',
            'Sends token request parameters in a GET query string.',
            'The request is refused.', ['S107']);
        $add('S334', self::REDEMPTION, 'Token endpoint supports CORS preflight', Level::INFO, Mode::AUTO, '§5.3',
            'Sends an OPTIONS preflight, as browser-based apps would.',
            'Informational.', ['S107']);

        // ------------------------------------------------------------ 400s

        $add('S401', self::TOKENS, 'Token response is JSON', Level::MUST, Mode::AUTO, '§5.3.3',
            'Checks the response to the token exchange from your sign-in.',
            'HTTP 200 with a JSON object and a JSON content type.', ['S301']);
        $add('S402', self::TOKENS, 'Includes an access token', Level::MUST, Mode::AUTO, '§5.3.3',
            'Checks for access_token when a scope was granted.',
            'access_token is a non-empty string.', ['S301']);
        $add('S403', self::TOKENS, 'Token type is Bearer', Level::SHOULD, Mode::AUTO, 'RFC 6749 §5.1',
            'Checks token_type.',
            'token_type is "Bearer" (case-insensitive).', ['S402']);
        $add('S404', self::TOKENS, 'Returns a valid me', Level::MUST, Mode::AUTO, '§5.3.3, §3.2',
            'Checks the me value.',
            'me is a valid user profile URL.', ['S301']);
        $add('S405', self::TOKENS, 'The returned me is verified', Level::MUST, Mode::AUTO, '§5.4',
            'If me differs from the URL you entered, fetches it and checks it declares the same authorization server.',
            'me is the entered URL, a URL seen while discovering it, or declares the same authorization endpoint.', ['S404']);
        $add('S406', self::TOKENS, 'Token responses are not cached', Level::MUST, Mode::AUTO, 'RFC 6749 §5.1',
            'Checks the Cache-Control header of the token response.',
            'Cache-Control includes no-store.', ['S301']);
        $add('S407', self::TOKENS, 'Says when the token expires', Level::SHOULD, Mode::AUTO, '§5.3.3',
            'Checks expires_in.',
            'expires_in is a positive number.', ['S402']);
        $add('S408', self::TOKENS, 'Reports the granted scope', Level::SHOULD, Mode::AUTO, '§5.3.3, RFC 6749 §5.1',
            'Checks the scope value.',
            'scope is present.', ['S402']);
        $add('S409', self::TOKENS, 'Profile information is well formed', Level::SHOULD, Mode::AUTO, '§5.3.4',
            'When the profile scope was granted, checks the profile object.',
            'profile is an object whose name, url, photo and email, when present, are strings.', ['S301']);
        $add('S410', self::TOKENS, 'Access tokens are hard to guess', Level::SHOULD, Mode::AUTO, 'RFC 6749 §10.10',
            'Checks the length of the access token.',
            'The token is at least 22 characters.', ['S402']);
        $add('S420', self::TOKENS, 'Access token format', Level::INFO, Mode::AUTO, 'RFC 9068',
            'Looks at whether your access tokens are JWTs or opaque strings. Both are fine; the checks below only apply to JWTs.',
            'Informational.', ['S402']);
        $add('S421', self::TOKENS, 'JWT header marks it an access token', Level::SHOULD, Mode::AUTO, 'RFC 9068 §2.1',
            'When the access token is a JWT, checks its header.',
            'typ is "at+jwt", so it cannot be confused with an ID token, and alg is a real signing algorithm.', ['S420']);
        $add('S422', self::TOKENS, 'JWT verifies against the published keys', Level::MUST, Mode::AUTO, 'RFC 9068 §4, RFC 8414 §2',
            'Fetches jwks_uri from your metadata and checks the token\'s signature with the key naming its kid, as a resource server would.',
            'The metadata lists jwks_uri, it returns a JWKS, and the signature verifies.', ['S420']);
        $add('S423', self::TOKENS, 'JWT claims are complete', Level::MUST, Mode::AUTO, 'RFC 9068 §2.2',
            'Checks the claims a resource server needs.',
            'iss, aud, exp, iat and sub are present, iss matches the issuer, exp is in the future, and client_id and scope match the token response.', ['S420']);

        // ------------------------------------------------------------ 500s

        $add('S501', self::REFRESH, 'Refreshes an access token', Level::MUST, Mode::AUTO, '§5.5',
            'Only if a refresh_token was issued: uses it to get a new access token.',
            'Returns a new access token and me, with Cache-Control: no-store.', ['S301']);
        $add('S502', self::REFRESH, 'Refuses a refresh by another client', Level::MUST, Mode::AUTO, 'RFC 6749 §6',
            'Presents a refresh token with a different client_id.',
            'The refresh is refused.', ['S301']);
        $add('S503', self::REFRESH, 'Refuses to add scopes on refresh', Level::MUST, Mode::AUTO, '§5.5, RFC 6749 §6',
            'Requests a scope that was not originally granted.',
            'The refresh is refused, or the new token does not include the extra scope.', ['S501']);
        $add('S504', self::REFRESH, 'Old refresh tokens stop working after rotation', Level::SHOULD, Mode::AUTO, 'RFC 9700 §4.14.2',
            'If refreshing returned a new refresh token, tries the old one again.',
            'The old refresh token is refused.', ['S501']);
        $add('S505', self::REFRESH, 'Refresh token reuse revokes the grant', Level::SHOULD, Mode::AUTO, 'RFC 9700 §4.14.2',
            'After reusing an old refresh token, checks whether the newest tokens still work.',
            'The newest tokens are revoked too. Skipped without a userinfo endpoint.', ['S504']);

        // ------------------------------------------------------------ 600s

        $add('S601', self::REVOCATION, 'Revokes an access token', Level::MUST, Mode::AUTO, '§7.1',
            'Only if a revocation_endpoint is listed: revokes an access token.',
            'Returns HTTP 200.', ['S301']);
        $add('S602', self::REVOCATION, 'Revoked tokens stop working', Level::MUST, Mode::AUTO, '§7, RFC 7009 §2.2',
            'Uses the revoked access token at the userinfo endpoint.',
            'The token is refused. Skipped without a userinfo endpoint.', ['S601']);
        $add('S603', self::REVOCATION, 'Revokes a refresh token', Level::MUST, Mode::AUTO, 'RFC 7009 §2.1',
            'Revokes a refresh token, then tries to use it.',
            'Revocation returns HTTP 200 and the refresh token no longer works.', ['S501']);
        $add('S604', self::REVOCATION, 'Revoking an unknown token succeeds', Level::MUST, Mode::AUTO, '§7.1',
            'Revokes a made-up token.',
            'Returns HTTP 200.', ['S104']);

        // ------------------------------------------------------------ 700s

        $add('S701', self::USERINFO, 'Returns profile information', Level::MUST, Mode::AUTO, '§9',
            'Only if a userinfo_endpoint is listed: requests it with the access token from your sign-in.',
            'HTTP 200 with a JSON object matching the profile from the token response.', ['S402']);
        $add('S702', self::USERINFO, 'Refuses a request without a token', Level::SHOULD, Mode::AUTO, '§9, RFC 6750 §3',
            'Requests the userinfo endpoint without a token.',
            'Returns HTTP 401.', ['S104']);
        $add('S703', self::USERINFO, 'Refuses an invalid token', Level::SHOULD, Mode::AUTO, '§9, RFC 6750 §3.1',
            'Requests the userinfo endpoint with a made-up token.',
            'Returns HTTP 401, ideally with error="invalid_token".', ['S104']);

        // ------------------------------------------------------------ 800s

        $add('S801', self::INTROSPECTION, 'Introspection requires authorization', Level::MUST, Mode::AUTO, '§6.1',
            'Only if an introspection_endpoint is listed: asks it about your access token without authenticating.',
            'Returns HTTP 401.', ['S108', 'S402']);
        $add('S802', self::INTROSPECTION, 'Refused introspection reveals nothing', Level::MUST, Mode::AUTO, '§6.1',
            'Checks the body of the unauthenticated introspection response.',
            'The response does not include active: true or the me value.', ['S801']);
        $add('S803', self::INTROSPECTION, 'Describes an active token', Level::MUST, Mode::MANUAL, '§6.2',
            'If you paste the credential your resource servers use, and say which scope that server accepts, introspects a token with that scope.',
            'Returns active: true, me and client_id. Skipped if you do not provide a credential.', ['S108', 'S402'], true);
        $add('S804', self::INTROSPECTION, 'Describes an invalid token', Level::MUST, Mode::MANUAL, '§6.2',
            'With the same credential, introspects a made-up token.',
            'Returns HTTP 200 with active: false. Any other members are a warning.', ['S803'], true);

        // ------------------------------------------------------------ 900s

        $add('S901', self::HYGIENE, 'Token endpoint requires TLS', Level::SHOULD, Mode::AUTO, 'RFC 6749 §3.2',
            'Sends a token request to the http:// version of the token endpoint.',
            'The request is refused or redirected rather than processed.', ['S107']);
        $add('S902', self::HYGIENE, 'Authorization pages cannot be framed', Level::SHOULD, Mode::AUTO, 'RFC 9700 §4.16',
            'Checks the authorization endpoint response for X-Frame-Options or a CSP frame-ancestors directive.',
            'One of them prevents framing by other sites (clickjacking).', ['S106']);
        $add('S905', self::HYGIENE, 'Tokens never appear in URLs', Level::MUST, Mode::AUTO, 'RFC 9700 §2.1.2',
            'Checks every redirect back to this site during the run.',
            'No access_token or refresh_token appears in a redirect URL.', ['S106']);
        $add('S906', self::HYGIENE, 'No credentialed wildcard CORS', Level::SHOULD, Mode::AUTO, 'Fetch standard, RFC 9700',
            'Checks CORS headers on the token endpoint.',
            'Access-Control-Allow-Origin: * is never combined with Access-Control-Allow-Credentials: true.', ['S107']);

        return self::$tests = $tests;
    }
}

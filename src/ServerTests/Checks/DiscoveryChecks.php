<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests\Checks;

use IndieAuthRocks\Engine\Verdict;
use IndieAuthRocks\IndieAuth\DiscoveryResult;
use IndieAuthRocks\IndieAuth\ProfileUrl;

/** Grades S101–S116 from a discovery result. No requests are made here. */
final class DiscoveryChecks
{
    private const ENDPOINT_KEYS = [
        'authorization_endpoint', 'token_endpoint', 'introspection_endpoint', 'revocation_endpoint', 'userinfo_endpoint',
    ];

    /** @return list<Verdict> */
    public static function grade(DiscoveryResult $d): array
    {
        $verdicts = [];

        // S101
        if (ProfileUrl::parse($d->profileUrl) === null) {
            $verdicts[] = Verdict::fail('S101', sprintf('%s is not a valid user profile URL (no ports, fragments, credentials or IP addresses).', $d->profileUrl));
        } elseif ($d->profileError !== null) {
            $verdicts[] = Verdict::fail('S101', 'The profile URL could not be fetched: ' . $d->profileError);
        } elseif ($d->profile?->status !== 200) {
            $verdicts[] = Verdict::fail('S101', sprintf('The profile URL returned HTTP %d.', (int) $d->profile?->status));
        } else {
            $verdicts[] = Verdict::pass('S101', $d->redirects === []
                ? 'Fetched with HTTP 200.'
                : sprintf('Fetched with HTTP 200 after redirecting to %s.', $d->finalUrl()));
        }

        // S102, S103
        if ($d->metadataUrl !== null) {
            $verdicts[] = Verdict::pass('S102', sprintf('Found %s in %s.', $d->metadataUrl, $d->headerMetadata !== [] ? 'the Link header' : 'the HTML'));
        } elseif ($d->legacyAuthorizationEndpoint !== null) {
            $verdicts[] = Verdict::warning('S102', 'Only legacy rel="authorization_endpoint" was found. Current clients look for rel="indieauth-metadata".');
        } else {
            $verdicts[] = Verdict::fail('S102', 'No rel="indieauth-metadata" link was found.');
        }

        if ($d->headerMetadata !== [] && $d->htmlMetadata !== [] && $d->headerMetadata[0] !== $d->htmlMetadata[0]) {
            $verdicts[] = Verdict::warning('S103', sprintf('The Link header points to %s but the HTML points to %s.', $d->headerMetadata[0], $d->htmlMetadata[0]));
        } else {
            $verdicts[] = Verdict::pass('S103', $d->headerMetadata !== [] && $d->htmlMetadata !== [] ? 'Both point to the same URL.' : 'Only one of them is present.');
        }

        // S104
        $response = $d->metadataResponse;

        if ($d->metadataUrl === null) {
            $verdicts[] = Verdict::skipped('S104', 'No metadata URL was found.');
        } elseif ($response === null) {
            $verdicts[] = Verdict::fail('S104', 'The metadata could not be fetched: ' . ($d->metadataError ?? 'no response'));
        } elseif ($response->isRedirect()) {
            $verdicts[] = Verdict::fail('S104', sprintf('The metadata URL redirects (HTTP %d). Serve the document at the advertised URL.', $response->status));
        } elseif ($d->metadata === null) {
            $verdicts[] = Verdict::fail('S104', (string) $d->metadataError);
        } elseif (!$response->isJson()) {
            $verdicts[] = Verdict::warning('S104', sprintf('The document is JSON but is served as "%s" instead of application/json.', $response->contentType()));
        } else {
            $verdicts[] = Verdict::pass('S104', 'HTTP 200 with a JSON object.');
        }

        if ($d->metadata === null) {
            foreach (['S105', 'S106', 'S107', 'S108', 'S109', 'S110', 'S111', 'S112', 'S113', 'S114', 'S115', 'S116'] as $id) {
                $verdicts[] = Verdict::skipped($id, 'No metadata document to check.');
            }

            return $verdicts;
        }

        $m = $d->metadata;

        // S105
        $issuer = $d->string('issuer');
        if ($issuer === null) {
            $verdicts[] = Verdict::fail('S105', 'The metadata has no issuer.');
        } else {
            $problems = [];
            if (!str_starts_with($issuer, 'https://')) {
                $problems[] = 'it is not https';
            }
            if (str_contains($issuer, '?') || str_contains($issuer, '#')) {
                $problems[] = 'it has a query or fragment';
            }
            if (!str_starts_with((string) $d->metadataUrl, $issuer)) {
                $problems[] = sprintf('it is not a prefix of the metadata URL %s', $d->metadataUrl);
            }

            $verdicts[] = $problems === []
                ? Verdict::pass('S105', 'issuer is ' . $issuer)
                : Verdict::fail('S105', sprintf('issuer %s is invalid: %s.', $issuer, implode('; ', $problems)));
        }

        // S106–S108
        foreach (['S106' => 'authorization_endpoint', 'S107' => 'token_endpoint', 'S108' => 'introspection_endpoint'] as $id => $key) {
            $value = $d->string($key);

            $verdicts[] = $value !== null && self::isAbsoluteHttpUrl($value)
                ? Verdict::pass($id, $key . ' is ' . $value)
                : Verdict::unmet($id, $value === null ? sprintf('The metadata has no %s.', $key) : sprintf('%s is not an absolute URL: %s', $key, $value));
        }

        // S109
        $methods = $m['code_challenge_methods_supported'] ?? null;
        $verdicts[] = is_array($methods) && in_array('S256', $methods, true)
            ? Verdict::pass('S109', 'S256 is listed.')
            : Verdict::fail('S109', is_array($methods) ? 'code_challenge_methods_supported does not include S256.' : 'code_challenge_methods_supported is missing; IndieAuth requires it.');

        // S110
        $verdicts[] = Verdict::check('S110', ($m['authorization_response_iss_parameter_supported'] ?? null) === true,
            'authorization_response_iss_parameter_supported is true.',
            'authorization_response_iss_parameter_supported is not true, so clients cannot rely on iss for mix-up protection.');

        // S111
        $scopes = $m['scopes_supported'] ?? null;
        $verdicts[] = Verdict::check('S111', is_array($scopes) && array_is_list($scopes) && $scopes === array_filter($scopes, 'is_string'),
            'scopes_supported lists ' . (is_array($scopes) ? implode(', ', array_filter($scopes, 'is_string')) : ''),
            'scopes_supported is missing or is not a list of strings.');

        // S112
        $problems = [];
        if (isset($m['response_types_supported'])) {
            $types = (array) $m['response_types_supported'];
            if (!in_array('code', $types, true)) {
                $problems[] = 'response_types_supported does not include "code"';
            }
            if (in_array('token', $types, true)) {
                $problems[] = 'response_types_supported includes the implicit "token" type';
            }
        }
        if (isset($m['grant_types_supported']) && !in_array('authorization_code', (array) $m['grant_types_supported'], true)) {
            $problems[] = 'grant_types_supported does not include "authorization_code"';
        }
        $verdicts[] = Verdict::check('S112', $problems === [], 'Consistent (or using the defaults).', ucfirst(implode('; ', $problems)) . '.');

        // S113
        if ($d->string('revocation_endpoint') === null) {
            $verdicts[] = Verdict::skipped('S113', 'No revocation_endpoint is listed.');
        } else {
            $verdicts[] = Verdict::check('S113', ($m['revocation_endpoint_auth_methods_supported'] ?? null) === ['none'],
                'revocation_endpoint_auth_methods_supported is ["none"].',
                'revocation_endpoint_auth_methods_supported should be ["none"], so clients know they can revoke without authenticating.');
        }

        // S114
        $cors = $response?->header('access-control-allow-origin');
        $verdicts[] = Verdict::pass('S114', $cors === null ? 'No CORS headers; browser-based apps cannot read the metadata directly.' : 'Access-Control-Allow-Origin: ' . $cors);

        // S115
        $mismatches = [];
        if ($d->legacyAuthorizationEndpoint !== null && $d->legacyAuthorizationEndpoint !== $d->string('authorization_endpoint')) {
            $mismatches[] = 'authorization_endpoint link ' . $d->legacyAuthorizationEndpoint;
        }
        if ($d->legacyTokenEndpoint !== null && $d->legacyTokenEndpoint !== $d->string('token_endpoint')) {
            $mismatches[] = 'token_endpoint link ' . $d->legacyTokenEndpoint;
        }
        $verdicts[] = Verdict::check('S115', $mismatches === [],
            $d->legacyAuthorizationEndpoint === null && $d->legacyTokenEndpoint === null ? 'No legacy links.' : 'Legacy links match the metadata.',
            'Legacy links disagree with the metadata: ' . implode(', ', $mismatches) . '.');

        // S116
        $insecure = [];
        foreach (self::ENDPOINT_KEYS as $key) {
            $value = $d->string($key);
            if ($value !== null && !str_starts_with($value, 'https://')) {
                $insecure[] = $key;
            }
        }
        $verdicts[] = Verdict::check('S116', $insecure === [], 'All endpoints use https.', 'Not https: ' . implode(', ', $insecure) . '.');

        return $verdicts;
    }

    private static function isAbsoluteHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['host']) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true);
    }
}

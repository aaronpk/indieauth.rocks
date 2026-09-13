<?php

declare(strict_types=1);

namespace IndieAuthRocks\IndieAuth;

/**
 * Verifies a profile URL returned by a server (§5.4). If it is not one of the
 * URLs seen while discovering the entered profile, it must itself declare the
 * same authorization endpoint.
 */
final class MeVerifier
{
    public function __construct(private readonly Discovery $discovery)
    {
    }

    /**
     * @param list<string> $discoveryUrls The entered URL and every URL seen fetching it.
     * @return array{ok: bool, fetched: bool, reason: string}
     */
    public function verify(string $me, array $discoveryUrls, string $authorizationEndpoint): array
    {
        if (in_array($me, $discoveryUrls, true)) {
            return ['ok' => true, 'fetched' => false, 'reason' => 'me matches a URL seen during discovery.'];
        }

        $result   = $this->discovery->discover($me);
        $declared = $result->authorizationEndpoint();

        if ($declared === null) {
            return [
                'ok'      => false,
                'fetched' => true,
                'reason'  => sprintf('%s does not declare an authorization server%s.', $me, $result->profileError !== null ? ' (' . $result->profileError . ')' : ''),
            ];
        }

        if ($declared !== $authorizationEndpoint) {
            return [
                'ok'      => false,
                'fetched' => true,
                'reason'  => sprintf('%s declares the authorization endpoint %s, not %s.', $me, $declared, $authorizationEndpoint),
            ];
        }

        return ['ok' => true, 'fetched' => true, 'reason' => sprintf('%s declares the same authorization endpoint.', $me)];
    }
}

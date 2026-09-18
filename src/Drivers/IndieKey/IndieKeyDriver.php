<?php

declare(strict_types=1);

namespace IndieAuthRocks\Drivers\IndieKey;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Drivers\DriverHttp;
use IndieAuthRocks\Drivers\DriverOutcome;
use IndieAuthRocks\Drivers\UserAgentDriver;
use IndieAuthRocks\Fetch\UrlResolver;
use IndieAuthRocks\ServerTests\Action;
use IndieAuthRocks\ServerTests\AuthorizationAttempt;
use IndieAuthRocks\ServerTests\ProbeGrader;
use IndieAuthRocks\ServerTests\Question;
use RuntimeException;

/**
 * Drives IndieKey: signs in with a software passkey enrolled by
 * `driver:indiekey:enroll`, then approves or denies on its consent screen.
 */
final class IndieKeyDriver implements UserAgentDriver
{
    private const MAX_HOPS = 12;

    private ?string $credential = null;

    private ?string $introspectionScope = null;

    public function __construct(
        private readonly SiteConfig $config,
        private readonly DriverHttp $http,
        private readonly SoftwareAuthenticator $authenticator,
        private readonly string $base,
        private readonly DriverConfigFile $file,
    ) {
    }

    public function withIntrospectionCredential(?string $credential): self
    {
        $this->credential = $credential;

        return $this;
    }

    /** The scope the credential's resource server accepts, e.g. "create" for Micropub. */
    public function withIntrospectionScope(?string $scope): self
    {
        $this->introspectionScope = $scope;

        return $this;
    }

    public function navigate(Action $action): DriverOutcome
    {
        $url     = (string) $action->url;
        $consent = null;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $response = $this->http->get($url);
            $location = $response['headers']['location'][0] ?? null;

            if ($location !== null && $response['status'] >= 300 && $response['status'] < 400) {
                $next = UrlResolver::resolve($url, $location) ?? throw new RuntimeException('Invalid redirect from ' . $url);

                if ($this->config->hostRole($next) !== null) {
                    return DriverOutcome::landed($next, $consent);
                }

                if (str_starts_with($next, $this->base . 'login')) {
                    $url = $this->signIn($next);
                    continue;
                }

                $url = $next;
                continue;
            }

            if ($response['status'] === 200 && preg_match('/name="interaction" value="([^"]+)"/', $response['body'], $m) === 1) {
                $consent = $response['body'];

                return $this->decide($action, $response['body'], $m[1], $url);
            }

            return DriverOutcome::stopped(DriverOutcome::ERROR, $response['status'], $response['body']);
        }

        return DriverOutcome::stopped(DriverOutcome::UNKNOWN, 0, 'Too many redirects');
    }

    public function answer(Question $question, ?DriverOutcome $last): ?string
    {
        if ($question->key === 'credential') {
            return $this->credential ?? '';
        }

        if ($question->key === 'introspection-scope') {
            return $this->introspectionScope ?? '';
        }

        if ($question->key === 'S206') {
            $html = $last?->consentHtml;

            if ($html === null) {
                return 'no_screen';
            }

            $hasName     = str_contains($html, htmlspecialchars($this->config->siteName . ' test client'));
            $hasClientId = str_contains($html, htmlspecialchars($this->config->url('clients/')));

            return match (true) {
                $hasName && $hasClientId => 'both',
                $hasClientId             => 'client_id',
                $hasName                 => 'name',
                default                  => 'neither',
            };
        }

        if (in_array($question->key, ProbeGrader::ALL, true)) {
            return match ($last?->kind) {
                DriverOutcome::ERROR => 'error',
                default              => $last?->consentHtml !== null ? 'consent' : 'other',
            };
        }

        return null;
    }

    private function decide(Action $action, string $html, string $interaction, string $pageUrl): DriverOutcome
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $html, $csrf);

        $fields = [
            '_csrf'       => html_entity_decode($csrf[1] ?? ''),
            'interaction' => html_entity_decode($interaction),
            'action'      => $action->intent === AuthorizationAttempt::APPROVE ? 'approve' : 'deny',
            'lifetime'    => 'expiring',
        ];

        preg_match_all('/name="(scope_\d+)"/', $html, $scopes);
        foreach ($scopes[1] as $field) {
            $fields[$field] = '1';
        }

        $response = $this->http->postForm($this->base . 'auth/approve', $fields);
        $location = $response['headers']['location'][0] ?? null;

        if ($location !== null) {
            $next = UrlResolver::resolve($pageUrl, $location) ?? $location;

            return $this->config->hostRole($next) !== null
                ? DriverOutcome::landed($next, $html)
                : DriverOutcome::stopped(DriverOutcome::UNKNOWN, $response['status'], $response['body'], $html);
        }

        return DriverOutcome::stopped(DriverOutcome::ERROR, $response['status'], $response['body'], $html);
    }

    /** Sign in with the passkey and return the URL to continue with. */
    private function signIn(string $loginUrl): string
    {
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $query);
        $next = is_string($query['next'] ?? null) ? $query['next'] : '/account';

        $page = $this->http->get($this->base . 'login');
        preg_match('/data-csrf="([^"]+)"/', $page['body'], $m);
        $csrf = html_entity_decode($m[1] ?? '');

        $options = $this->http->postJson($this->base . 'login/challenge', [], $csrf);
        $challenge = $options['publicKey']['challenge'] ?? null;

        if (!is_string($challenge)) {
            throw new RuntimeException('IndieKey did not return a passkey challenge: ' . json_encode($options));
        }

        $assertion = $this->authenticator->authenticate(self::unb64($challenge));
        $this->file->saveSignCount($this->authenticator->signCount());

        $result = $this->http->postJson($this->base . 'login', [
            'id'                => self::b64($this->authenticator->credentialId()),
            'clientDataJSON'    => self::b64($assertion['clientDataJSON']),
            'authenticatorData' => self::b64($assertion['authenticatorData']),
            'signature'         => self::b64($assertion['signature']),
            'userHandle'        => '',
            'next'              => $next,
        ], $csrf);

        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException('Passkey sign-in to IndieKey failed: ' . ($result['error'] ?? json_encode($result)));
        }

        return rtrim($this->base, '/') . (string) $result['redirect'];
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Console;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Controllers\FixtureController;
use IndieAuthRocks\Drivers\DriverHttp;
use IndieAuthRocks\Drivers\MyIndieAuth\DriverConfigFile;
use IndieAuthRocks\Drivers\MyIndieAuth\MyIndieAuthDriver;
use IndieAuthRocks\Drivers\MyIndieAuth\SoftwareAuthenticator;
use RuntimeException;

/**
 * driver:myindieauth:enroll — create an account on a myindieauth.com server
 * with a software passkey, add and verify the fixture profile domain, and save
 * what the driver needs to sign in later.
 */
final class EnrollCommand
{
    public function __construct(
        private readonly Output $out,
        private readonly SiteConfig $config,
    ) {
    }

    public function run(Args $args): int
    {
        $base     = rtrim((string) $args->option('base', ''), '/') . '/';
        $profile  = (string) ($args->option('profile') ?? $this->config->fixtureUrl() ?? '');
        $username = (string) $args->option('username', 'rocks-e2e');
        $path     = (string) $args->option('config', SiteConfig::root() . '/storage/drivers/myindieauth.json');
        $http     = new DriverHttp($args->option('ca-file'));

        if ($base === '/' || $profile === '') {
            $this->out->error('Usage: driver:myindieauth:enroll --base=https://myindieauth.example/ [--profile=https://profile.example/] [--username=rocks-e2e]');

            return 1;
        }

        $authenticator = new SoftwareAuthenticator((string) parse_url($base, PHP_URL_HOST), rtrim($base, '/'));

        // 1. Register with a passkey.
        $csrf    = $this->csrf($http->get($base . 'register')['body'], 'data-csrf');
        $options = $http->postJson($base . 'register/challenge', ['username' => $username], $csrf);

        if (($options['ok'] ?? false) !== true) {
            throw new RuntimeException('Registration failed: ' . ($options['error'] ?? json_encode($options)) . ' (try another --username)');
        }

        $attestation = $authenticator->register(MyIndieAuthDriver::unb64((string) $options['publicKey']['challenge']));
        $created     = $http->postJson($base . 'register', [
            'clientDataJSON'    => MyIndieAuthDriver::b64($attestation['clientDataJSON']),
            'attestationObject' => MyIndieAuthDriver::b64($attestation['attestationObject']),
            'label'             => 'IndieAuth Rocks driver',
            'next'              => '/account',
        ], $csrf);

        if (($created['ok'] ?? false) !== true) {
            throw new RuntimeException('Registration failed: ' . ($created['error'] ?? json_encode($created)));
        }

        $this->out->pass('Registered ' . $username . ' on ' . $base);

        $file = new DriverConfigFile($path);
        $file->write([
            'base'          => $base,
            'profile'       => $profile,
            'username'      => $username,
            'credential_id' => MyIndieAuthDriver::b64($authenticator->credentialId()),
            'private_key'   => $authenticator->exportPrivateKey(),
            'sign_count'    => $authenticator->signCount(),
        ]);

        // 2. Add the profile domain.
        $form     = $http->get($base . 'account/domains/new')['body'];
        $added    = $http->postForm($base . 'account/domains', ['_csrf' => $this->csrf($form, 'name="_csrf" value'), 'domain' => (string) parse_url($profile, PHP_URL_HOST)]);
        $location = $added['headers']['location'][0] ?? '';

        if (preg_match('#/account/domains/(\d+)$#', $location, $m) !== 1) {
            throw new RuntimeException('Adding the domain failed (HTTP ' . $added['status'] . ').');
        }

        $domainPage = $http->get($base . 'account/domains/' . $m[1])['body'];

        if (preg_match('#(https?://[^"\s<&]+/u/[a-z0-9]{16}/metadata)#', $domainPage, $metadata) !== 1) {
            throw new RuntimeException('Could not find the metadata URL on the domain page.');
        }

        // 3. Publish the link on the fixture profile page, then verify.
        file_put_contents(FixtureController::file(), json_encode(['metadata_url' => $metadata[1]], JSON_UNESCAPED_SLASHES));
        $this->out->pass('Profile page now links to ' . $metadata[1]);

        $verify = $http->postForm($base . 'account/domains/' . $m[1] . '/verify', [
            '_csrf'  => $this->csrf($domainPage, 'name="_csrf" value'),
            'return' => '',
        ]);

        if (!str_contains($verify['headers']['location'][0] ?? '', 'notice=verified')) {
            $page = $http->get($base . 'account/domains/' . $m[1])['body'];
            preg_match('#<div class="alert"[^>]*>(.*?)</div>#s', $page, $error);

            throw new RuntimeException('Verification failed: ' . trim(strip_tags(html_entity_decode($error[1] ?? 'unknown error'))));
        }

        $this->out->pass('Verified ' . $profile);
        $this->out->line('Saved driver settings to ' . $path);

        return 0;
    }

    private function csrf(string $html, string $attribute): string
    {
        if (preg_match('/' . preg_quote($attribute, '/') . '="([^"]+)"/', $html, $m) !== 1) {
            throw new RuntimeException('No CSRF token found on the page.');
        }

        return html_entity_decode($m[1]);
    }
}

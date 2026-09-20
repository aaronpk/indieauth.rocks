<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup\Steps;

use IndieAuthRocks\Checkup\KnownServers;
use IndieAuthRocks\Checkup\Step;
use IndieAuthRocks\Checkup\StepState;
use IndieAuthRocks\IndieAuth\DiscoveryResult;
use IndieAuthRocks\IndieAuth\LinkRelParser;

/**
 * Everything that can be said before the person leaves for their own server.
 *
 * A pure function of what discovery found: no network, no database, so the
 * wording — which is the whole point of this tool — can be read and tested on
 * its own. Nothing here grades anything; a Problem means "this is why your
 * login fails", not "you scored badly".
 */
final class DiscoverySteps
{
    private const METADATA_LINK = '<link rel="indieauth-metadata" href="https://…">';

    /** @return list<Step> */
    public static function from(DiscoveryResult $d, string $typed): array
    {
        $steps = [self::address($d, $typed)];

        $page = self::homePage($d);
        $steps[] = $page;

        if ($page->state->stopsTheLogin()) {
            return $steps;
        }

        if ($d->redirects !== []) {
            $steps[] = self::canonical($d);
        }

        $link = self::serverLink($d);
        $steps[] = $link;

        if ($link->state->stopsTheLogin()) {
            return $steps;
        }

        // Legacy links alone mean there is no metadata document to fetch, but
        // there is still an authorization endpoint to use.
        if ($d->metadataUrl !== null) {
            $settings = self::settings($d);
            $steps[]  = $settings;

            if ($settings->state->stopsTheLogin()) {
                return $steps;
            }
        }

        $steps[] = self::endpoints($d);

        // When the page already points at the RFC 8414 address, the well-known
        // fetch found the same document by the same route: there is no second
        // way to describe.
        if ($d->wellKnownUrl !== null && !self::sameDocument($d->wellKnownUrl, $d->metadataUrl)) {
            $steps[] = self::wellKnown($d);
        }

        return $steps;
    }

    private static function address(DiscoveryResult $d, string $typed): Step
    {
        $facts = ['You typed' => $typed, 'Apps will read that as' => $d->profileUrl];

        if (KnownServers::isSocial($d->profileUrl)) {
            return new Step(
                id: 'address',
                title: 'The address you entered',
                state: StepState::Note,
                headline: sprintf('%s is your page on somebody else\'s site.', $d->profileUrl),
                facts: $facts,
                meaning: 'IndieAuth signs you in with an address you control, which normally means your own '
                    . 'domain name. We will carry on and show you what happens, but a page on a site somebody '
                    . 'else runs can only work if that site offers IndieAuth itself.',
                fix: 'If you do not have your own domain yet, that is the thing to sort out first.',
                links: [['text' => 'Why a personal domain', 'url' => 'https://indieweb.org/personal-domain']],
            );
        }

        if (str_starts_with($d->profileUrl, 'http://')) {
            return new Step(
                id: 'address',
                title: 'The address you entered',
                state: StepState::Note,
                headline: 'You entered an address that starts with http://, not https://.',
                facts: $facts,
                meaning: 'Outside local testing, IndieAuth requires https, and most apps refuse an http address '
                    . 'outright.',
                fix: 'If your site works over https, use that address everywhere instead.',
            );
        }

        return new Step(
            id: 'address',
            title: 'The address you entered',
            state: StepState::Ok,
            headline: sprintf('Apps will read what you typed as %s.', $d->profileUrl),
            facts: $facts,
            meaning: 'That address is your identity: it is both what you type into an app and what the app ends '
                . 'up knowing you as.',
        );
    }

    private static function homePage(DiscoveryResult $d): Step
    {
        $title = 'Fetching your home page';
        $urls  = $d->discoveryUrls();

        if ($d->profileError !== null) {
            return new Step(
                id: 'home-page',
                title: $title,
                state: StepState::Problem,
                headline: sprintf('Your website could not be fetched: %s', $d->profileError),
                facts: ['Address' => $d->profileUrl],
                meaning: 'Every app starts by fetching this page, because it is where your site says which '
                    . 'IndieAuth server handles your login. While it cannot be fetched, no app can sign you in.',
                fix: self::fetchAdvice($d->profileError),
                urls: $urls,
            );
        }

        $page   = $d->profile;
        $status = $page?->status ?? 0;
        $facts  = [
            'Fetched'      => $d->finalUrl(),
            'Status'       => sprintf('HTTP %d', $status),
            'Content type' => $page?->contentType() ?: 'not given',
            'Took'         => sprintf('%d ms', $page?->durationMs ?? 0),
        ];

        // A site that answers a browser but not us is the least self-evident
        // failure there is: the person can see their own page perfectly well.
        if (in_array($status, [401, 403, 406, 429], true)) {
            return new Step(
                id: 'home-page',
                title: $title,
                state: StepState::Problem,
                headline: sprintf('Your home page answered HTTP %d when we fetched it.', $status),
                facts: $facts,
                meaning: 'Your page almost certainly loads fine in your browser. Sites behind a CDN, or with bot '
                    . 'protection turned up, routinely block requests that do not come from one. Every IndieAuth '
                    . 'app fetches this page exactly as we just did, so every one of them is being turned away too.',
                fix: 'Allow requests for your home page that are not from a browser — in Cloudflare that means '
                    . 'turning off Bot Fight Mode, or adding a rule that skips it for your home page.',
                urls: $urls,
            );
        }

        if ($status >= 400 || $status === 0) {
            return new Step(
                id: 'home-page',
                title: $title,
                state: StepState::Problem,
                headline: sprintf('Your home page returned HTTP %d.', $status),
                facts: $facts,
                meaning: 'Apps fetch this exact address to find your login settings, so it has to load.',
                fix: $status === 404
                    ? 'Check that the address you entered is the page you meant: apps read the exact address you '
                        . 'type, not a page elsewhere on the site.'
                    : 'Whatever is wrong with the page has to be fixed before any app can sign you in.',
                urls: $urls,
            );
        }

        return new Step(
            id: 'home-page',
            title: $title,
            state: StepState::Ok,
            headline: sprintf('Fetched %s — HTTP %d.', $d->finalUrl(), $status),
            facts: $facts,
            meaning: 'This is the page every app reads first.',
            urls: $urls,
        );
    }

    private static function canonical(DiscoveryResult $d): Step
    {
        return new Step(
            id: 'canonical',
            title: 'Which address is really yours',
            state: StepState::Note,
            headline: sprintf('%s redirects to %s.', $d->profileUrl, $d->finalUrl()),
            facts: ['You entered' => $d->profileUrl, 'It ends up at' => $d->finalUrl()],
            meaning: 'Both addresses load, but only one of them is your identity: the one your server reports at '
                . 'the end of a login. When an app says "that is not you" after you have already approved, a '
                . 'mismatch like this is usually why.',
            fix: 'Keep the redirect — it is how apps find you either way — and sign in everywhere with whichever '
                . 'address your server reports. This check will tell you which one that is.',
        );
    }

    private static function serverLink(DiscoveryResult $d): Step
    {
        $title = 'Finding which server handles your login';

        if ($d->metadataUrl !== null) {
            $where = $d->headerMetadata !== [] ? 'in the Link header of your page' : 'in a <link> tag in your page\'s <head>';
            $step  = new Step(
                id: 'server-link',
                title: $title,
                state: StepState::Ok,
                headline: sprintf('Your page points at %s, found %s.', $d->metadataUrl, $where),
                facts: array_filter([
                    'Your server\'s settings' => $d->metadataUrl,
                    'Found in'                => $where,
                ]),
            );

            if ($d->headerMetadata !== [] && $d->htmlMetadata !== [] && $d->headerMetadata[0] !== $d->htmlMetadata[0]) {
                return $step->with(
                    StepState::Note,
                    sprintf(
                        'Your page names two different servers: the Link header says %s, the HTML says %s.',
                        $d->headerMetadata[0],
                        $d->htmlMetadata[0],
                    ),
                );
            }

            return $step;
        }

        // There is a link, but somewhere apps deliberately do not look.
        $inBody = $d->profile === null
            ? []
            : array_diff(
                LinkRelParser::fromHtml($d->profile->body, $d->profile->url, 'indieauth-metadata', false),
                $d->htmlMetadata,
            );

        if ($inBody !== []) {
            return new Step(
                id: 'server-link',
                title: $title,
                state: StepState::Problem,
                headline: 'Your page has an indieauth-metadata link, but it is in the body rather than the <head>.',
                facts: ['Link found' => (string) reset($inBody), 'Where it is' => 'in the page body', 'Where it has to be' => 'in the <head>'],
                meaning: 'Apps only trust this link when it is in the <head>, because anything in the body may '
                    . 'have been put there by somebody else — a comment, a bio, a quoted page.',
                fix: 'Move the <link> tag up into the <head> section of your page and this will start working.',
            );
        }

        if ($d->legacyAuthorizationEndpoint !== null) {
            return new Step(
                id: 'server-link',
                title: $title,
                state: StepState::Note,
                headline: 'Your page uses the older authorization_endpoint link rather than indieauth-metadata.',
                facts: array_filter([
                    'Authorization endpoint' => $d->legacyAuthorizationEndpoint,
                    'Token endpoint'         => $d->legacyTokenEndpoint,
                ]),
                meaning: 'Apps written before the current specification will still sign you in. Newer ones look '
                    . 'for the metadata link first, and some of them will not fall back to this.',
                fix: 'Ask whoever runs your IndieAuth server to publish a metadata document and link to it with '
                    . 'rel="indieauth-metadata". We will carry on with the older link.',
            );
        }

        return new Step(
            id: 'server-link',
            title: $title,
            state: StepState::Problem,
            headline: 'Your home page does not say which IndieAuth server handles your login.',
            facts: ['What apps look for' => self::METADATA_LINK],
            meaning: 'Apps read your home page looking for one line naming your server. Without it there is '
                . 'nowhere to send you to sign in, and this is almost certainly the failure you are seeing.',
            fix: 'If your site runs on something with IndieAuth built in — Micro.blog, WordPress with the '
                . 'IndieAuth plugin, Known, Drupal — turn it on there and it will add the line for you. '
                . 'Otherwise choose a hosted IndieAuth server and paste the line it gives you into your <head>.',
            links: [['text' => 'IndieAuth servers and plugins', 'url' => 'https://indieweb.org/IndieAuth']],
        );
    }

    private static function settings(DiscoveryResult $d): Step
    {
        $title    = 'Asking your server what it can do';
        $url      = (string) $d->metadataUrl;
        $response = $d->metadataResponse;
        $status   = $response?->status;
        $facts    = array_filter([
            'Settings document' => $url,
            'Status'            => $status === null ? null : sprintf('HTTP %d', $status),
            'Content type'      => $response?->contentType() ?: null,
        ]);

        if ($response === null) {
            return new Step(
                id: 'server-settings',
                title: $title,
                state: StepState::Problem,
                headline: sprintf('Your server\'s settings could not be fetched: %s', (string) $d->metadataError),
                facts: $facts,
                meaning: 'Your home page points here, so every app will try to fetch it and stop where we did.',
                fix: self::fetchAdvice($d->metadataError),
                urls: [$url],
            );
        }

        if ($d->metadata === null) {
            $isHtml = str_contains($response->contentType(), 'html');

            return new Step(
                id: 'server-settings',
                title: $title,
                state: StepState::Problem,
                headline: $status === 200 && $isHtml
                    ? 'Your server\'s settings address returned a web page instead of a settings document.'
                    : sprintf('Your server\'s settings address returned HTTP %d.', (int) $status),
                facts: $facts,
                meaning: 'Apps expect a small JSON document here describing your server. Without it they cannot '
                    . 'tell where to send you to approve the login.',
                fix: $status === 404
                    ? 'Your home page points at an address with nothing at it. Either the link has a typo, or '
                        . 'your server has moved and the link needs updating.'
                    : ($isHtml
                        ? 'This usually means the link points at an ordinary page, or that your site answers '
                            . 'unknown addresses with its "not found" page but a 200 status.'
                        : 'Whoever runs your IndieAuth server needs to look at what this address returns.'),
                urls: [$url],
            );
        }

        $step = new Step(
            id: 'server-settings',
            title: $title,
            state: StepState::Ok,
            headline: sprintf('%s answered with your server\'s settings.', $url),
            facts: $facts,
            urls: [$url],
        );

        if (!$response->isJson()) {
            return new Step(
                id: 'server-settings',
                title: $title,
                state: StepState::Note,
                headline: sprintf('Your server\'s settings are correct, but served as %s.', $response->contentType() ?: 'an unknown type'),
                facts: $facts,
                meaning: 'Some apps check the content type and refuse anything that is not application/json.',
                fix: 'Serve that document as application/json.',
                urls: [$url],
            );
        }

        return $step;
    }

    private static function endpoints(DiscoveryResult $d): Step
    {
        $title         = 'Reading your server\'s settings';
        $authorization = $d->authorizationEndpoint();
        $token         = $d->tokenEndpoint();
        $issuer        = $d->string('issuer');
        $methods       = $d->metadata['code_challenge_methods_supported'] ?? null;
        $scopes        = $d->metadata['scopes_supported'] ?? null;

        $facts = array_filter([
            'Your server'                => $issuer,
            'Where you approve logins'   => $authorization,
            'Where apps exchange codes'  => $token,
            'Login protection (PKCE)'    => is_array($methods) ? implode(', ', array_map('strval', $methods)) : null,
            'Permissions it offers'      => is_array($scopes) ? implode(' ', array_map('strval', $scopes)) : null,
        ]);

        if ($authorization === null) {
            return new Step(
                id: 'endpoints',
                title: $title,
                state: StepState::Problem,
                headline: 'Your server\'s settings do not say where to send you to approve a login.',
                facts: $facts,
                meaning: 'The settings document has no authorization_endpoint, so there is nowhere for an app to '
                    . 'send you, and nothing further can be checked.',
                fix: 'Whoever runs your IndieAuth server needs to add it to the settings document.',
            );
        }

        $known = KnownServers::noteAbout($authorization) ?? KnownServers::noteAbout($issuer);

        if ($known !== null) {
            return new Step(
                id: 'endpoints',
                title: $title,
                state: StepState::Note,
                headline: $known,
                facts: $facts,
            );
        }

        if ($d->metadata !== null && (!is_array($methods) || !in_array('S256', array_map('strval', $methods), true))) {
            return new Step(
                id: 'endpoints',
                title: $title,
                state: StepState::Note,
                headline: 'Your server does not say it supports S256, the standard protection for a login in progress.',
                facts: $facts,
                meaning: 'That protection is called PKCE, and IndieAuth requires it. Careful apps check this list '
                    . 'first and refuse to start a login without it.',
                fix: 'Ask whoever runs your server to support S256 and list it in the settings document. We will '
                    . 'carry on so you can see what happens.',
            );
        }

        $name = KnownServers::name($authorization) ?? KnownServers::name($issuer);

        return new Step(
            id: 'endpoints',
            title: $title,
            state: StepState::Ok,
            headline: $name !== null
                ? sprintf('Your login is handled by %s, and it is set up correctly.', $name)
                : sprintf('Your server sends people to %s to approve a login.', $authorization),
            facts: $facts,
        );
    }

    private static function wellKnown(DiscoveryResult $d): Step
    {
        $url    = (string) $d->wellKnownUrl;
        $status = $d->wellKnownResponse?->status;

        return new Step(
            id: 'well-known',
            title: 'A second way apps can find your server',
            state: $d->wellKnownMetadata !== null ? StepState::Ok : StepState::Note,
            headline: $d->wellKnownMetadata !== null
                ? 'Your server also publishes its settings at the standard OAuth address.'
                : sprintf('Your server does not publish its settings at %s.', $url),
            facts: array_filter(['Address' => $url, 'Status' => $status === null ? 'could not be fetched' : sprintf('HTTP %d', $status)]),
            meaning: $d->wellKnownMetadata !== null
                ? null
                : 'Most IndieAuth apps start from your home page and never look here. A few apps built on general '
                    . 'OAuth libraries do, and those would not find your server.',
            urls: [$url],
        );
    }

    /** Two addresses that fetch the same settings document. */
    private static function sameDocument(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && rtrim($a, '/') === rtrim($b, '/');
    }

    /** Turn a fetch failure into something to actually do. */
    private static function fetchAdvice(?string $error): ?string
    {
        $error = strtolower((string) $error);

        return match (true) {
            str_contains($error, 'resolve')     => 'Check that the domain is still registered and pointing at your site.',
            str_contains($error, 'certificate') => 'Your certificate needs renewing or fixing. A browser lets you '
                . 'click past a certificate warning; apps cannot, so they simply stop here.',
            str_contains($error, 'https to')    => 'A redirect that drops from https to http is refused, because '
                . 'anything in between could rewrite the line that says which server handles your login.',
            str_contains($error, 'timed out'), str_contains($error, 'timeout') => 'Most apps wait less time than '
                . 'we do, so a page this slow will fail for them even when it eventually loads for us.',
            str_contains($error, 'refused')     => 'Nothing answered on that address. Check the site is running '
                . 'and reachable from outside your own network.',
            default                             => null,
        };
    }
}

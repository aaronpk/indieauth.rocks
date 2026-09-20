<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\Checkup;

use IndieAuthRocks\Checkup\Step;
use IndieAuthRocks\Checkup\StepState;
use IndieAuthRocks\Checkup\Steps\DiscoverySteps;
use IndieAuthRocks\Fetch\FetchResult;
use IndieAuthRocks\IndieAuth\DiscoveryResult;
use PHPUnit\Framework\TestCase;

/**
 * The wording is the feature here, so these tests pin the distinguishing
 * phrase of each explanation rather than the whole sentence.
 */
final class DiscoveryStepsTest extends TestCase
{
    private const METADATA_URL = 'https://server.example/metadata';

    private const METADATA = [
        'issuer'                           => 'https://server.example/',
        'authorization_endpoint'           => 'https://server.example/auth',
        'token_endpoint'                   => 'https://server.example/token',
        'code_challenge_methods_supported' => ['S256'],
    ];

    public function test_a_working_site_is_narrated_without_any_problem(): void
    {
        $steps = DiscoverySteps::from($this->discovery(), 'example.com');

        foreach ($steps as $step) {
            $this->assertNotSame(StepState::Problem, $step->state, $step->id . ' should not stop the login');
        }

        $this->assertSame('https://server.example/auth', $this->step($steps, 'endpoints')->facts['Where you approve logins']);
    }

    /** A page can carry several link relations; the step says which one found the server. */
    public function test_the_link_relation_that_found_the_server_is_named(): void
    {
        $step = $this->step(DiscoverySteps::from($this->discovery(), 'example.com'), 'server-link');

        $this->assertSame('rel="indieauth-metadata"', $step->facts['Link relation']);
        $this->assertStringContainsString('rel="indieauth-metadata"', $step->headline);
        $this->assertSame('a <link> tag in your page\'s <head>', $step->facts['Found in']);

        $legacy = $this->step(
            DiscoverySteps::from($this->discovery(metadataUrl: null, legacy: 'https://server.example/auth'), 'example.com'),
            'server-link',
        );

        $this->assertSame('https://server.example/auth', $legacy->facts['rel="authorization_endpoint"']);
    }

    public function test_a_site_with_no_metadata_link_is_told_what_to_add(): void
    {
        $step = $this->step(DiscoverySteps::from($this->discovery(metadataUrl: null), 'example.com'), 'server-link');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('does not say which IndieAuth server', $step->headline);
        $this->assertStringContainsString('rel="indieauth-metadata"', implode(' ', $step->facts));
        $this->assertStringContainsString('Micro.blog', (string) $step->fix);
    }

    /** The one case the headOnly flag exists for: the link is there, just ignored. */
    public function test_a_metadata_link_in_the_body_is_pointed_out(): void
    {
        $body = '<html><head><title>Me</title></head><body>'
            . '<link rel="indieauth-metadata" href="' . self::METADATA_URL . '"></body></html>';

        $steps = DiscoverySteps::from($this->discovery(metadataUrl: null, body: $body), 'example.com');
        $step  = $this->step($steps, 'server-link');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('in the body rather than the <head>', $step->headline);
        $this->assertStringContainsString('Move the <link> tag', (string) $step->fix);
    }

    public function test_legacy_links_still_work_but_are_only_a_note(): void
    {
        $steps = DiscoverySteps::from(
            $this->discovery(metadataUrl: null, legacy: 'https://server.example/auth'),
            'example.com',
        );
        $step = $this->step($steps, 'server-link');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('older authorization_endpoint link', $step->headline);
        $this->assertSame('https://server.example/auth', $this->step($steps, 'endpoints')->facts['Where you approve logins']);
    }

    public function test_a_home_page_blocked_by_bot_protection_is_explained(): void
    {
        $steps = DiscoverySteps::from($this->discovery(status: 403), 'example.com');
        $step  = $this->step($steps, 'home-page');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('loads fine in your browser', (string) $step->meaning);
        $this->assertStringContainsString('Bot Fight Mode', (string) $step->fix);
        $this->assertCount(2, $steps, 'nothing after the home page can be checked');
    }

    public function test_a_site_that_cannot_be_fetched_says_what_to_check(): void
    {
        $result = new DiscoveryResult('https://example.com/', profileError: 'Could not resolve host: example.com');
        $step   = $this->step(DiscoverySteps::from($result, 'example.com'), 'home-page');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('Could not resolve host', $step->headline);
        $this->assertStringContainsString('still registered', (string) $step->fix);
    }

    public function test_an_expired_certificate_is_explained_as_apps_see_it(): void
    {
        $result = new DiscoveryResult('https://example.com/', profileError: 'SSL certificate problem: certificate has expired');
        $step   = $this->step(DiscoverySteps::from($result, 'example.com'), 'home-page');

        $this->assertStringContainsString('click past a certificate warning', (string) $step->fix);
    }

    public function test_a_redirect_names_the_address_that_counts(): void
    {
        $steps = DiscoverySteps::from(
            $this->discovery(finalUrl: 'https://www.example.com/', redirects: ['https://example.com/']),
            'example.com',
        );
        $step = $this->step($steps, 'canonical');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('that is not you', (string) $step->meaning);
    }

    public function test_metadata_that_is_a_web_page_is_explained(): void
    {
        $steps = DiscoverySteps::from(
            $this->discovery(metadata: null, metadataBody: '<html>Not found</html>', metadataType: 'text/html'),
            'example.com',
        );
        $step = $this->step($steps, 'server-settings');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('web page instead of a settings document', $step->headline);
    }

    public function test_metadata_returning_404_blames_the_link_not_the_person(): void
    {
        $steps = DiscoverySteps::from($this->discovery(metadata: null, metadataStatus: 404), 'example.com');
        $step  = $this->step($steps, 'server-settings');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('typo', (string) $step->fix);
    }

    public function test_a_server_without_s256_is_a_note_and_the_check_continues(): void
    {
        $metadata = self::METADATA;
        unset($metadata['code_challenge_methods_supported']);

        $steps = DiscoverySteps::from($this->discovery(metadata: $metadata), 'example.com');
        $step  = $this->step($steps, 'endpoints');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('PKCE', (string) $step->meaning);
    }

    public function test_a_server_with_no_token_endpoint_is_a_note(): void
    {
        $metadata = self::METADATA;
        unset($metadata['token_endpoint']);

        $step = $this->step(DiscoverySteps::from($this->discovery(metadata: $metadata), 'example.com'), 'endpoints');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('do not list a token endpoint', $step->headline);
    }

    public function test_a_server_with_no_authorization_endpoint_stops_the_check(): void
    {
        $metadata = self::METADATA;
        unset($metadata['authorization_endpoint']);

        $step = $this->step(DiscoverySteps::from($this->discovery(metadata: $metadata), 'example.com'), 'endpoints');

        $this->assertSame(StepState::Problem, $step->state);
        $this->assertStringContainsString('nowhere', (string) $step->meaning);
    }

    public function test_indieauth_com_is_named_as_being_retired(): void
    {
        $metadata = [
            'issuer'                 => 'https://indieauth.com/',
            'authorization_endpoint' => 'https://indieauth.com/auth',
            'token_endpoint'         => 'https://indieauth.com/token',
        ];

        $step = $this->step(DiscoverySteps::from($this->discovery(metadata: $metadata), 'example.com'), 'endpoints');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('being', $step->headline);
        $this->assertStringContainsString('retired', $step->headline);

        // Somewhere to go, rather than an instruction to go looking.
        $this->assertSame([['text' => 'IndieKey', 'url' => 'https://indiekey.id/migrate']], $step->links);
        $this->assertStringContainsString('IndieKey', (string) $step->fix);
    }

    /**
     * Calling it "a second way apps can find your server" only makes sense when
     * it is a different address from the one the page already points at.
     */
    public function test_the_well_known_address_is_not_called_a_second_way_when_it_is_the_only_one(): void
    {
        $wellKnown = 'https://server.example/.well-known/oauth-authorization-server';

        $sameDocument = $this->discovery(metadataUrl: $wellKnown, wellKnownUrl: $wellKnown);
        foreach (DiscoverySteps::from($sameDocument, 'example.com') as $step) {
            $this->assertNotSame('well-known', $step->id);
        }

        $separate = $this->discovery(wellKnownUrl: $wellKnown);
        $this->assertSame(StepState::Ok, $this->step(DiscoverySteps::from($separate, 'example.com'), 'well-known')->state);
    }

    public function test_a_social_profile_is_flagged_without_stopping(): void
    {
        $result = $this->discovery(profileUrl: 'https://github.com/someone');
        $step   = $this->step(DiscoverySteps::from($result, 'github.com/someone'), 'address');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('somebody else', $step->headline);
    }

    public function test_an_http_address_is_flagged(): void
    {
        $step = $this->step(DiscoverySteps::from($this->discovery(profileUrl: 'http://example.com/'), 'http://example.com'), 'address');

        $this->assertSame(StepState::Note, $step->state);
        $this->assertStringContainsString('https://', $step->headline);
    }

    /** @param list<string> $redirects */
    private function discovery(
        string $profileUrl = 'https://example.com/',
        ?string $finalUrl = null,
        int $status = 200,
        string $body = '<html><head><title>Me</title></head><body></body></html>',
        array $redirects = [],
        ?string $metadataUrl = self::METADATA_URL,
        ?array $metadata = self::METADATA,
        int $metadataStatus = 200,
        string $metadataBody = '',
        string $metadataType = 'application/json',
        ?string $legacy = null,
        ?string $wellKnownUrl = null,
    ): DiscoveryResult {
        $page = new FetchResult($finalUrl ?? $profileUrl, $status, $body, ['content-type' => ['text/html']], $redirects);

        return new DiscoveryResult(
            profileUrl:                  $profileUrl,
            profile:                     $page,
            redirects:                   $redirects,
            htmlMetadata:                $metadataUrl === null ? [] : [$metadataUrl],
            legacyAuthorizationEndpoint: $legacy,
            metadataUrl:                 $metadataUrl,
            metadataResponse:            $metadataUrl === null ? null : new FetchResult(
                $metadataUrl,
                $metadataStatus,
                $metadataBody !== '' ? $metadataBody : (string) json_encode($metadata),
                ['content-type' => [$metadataType]],
            ),
            metadataError:               $metadataUrl !== null && $metadata === null ? 'The metadata is not a JSON object.' : null,
            metadata:                    $metadata,
            wellKnownUrl:                $wellKnownUrl,
            wellKnownResponse:           $wellKnownUrl === null ? null : new FetchResult(
                $wellKnownUrl,
                200,
                (string) json_encode($metadata),
                ['content-type' => ['application/json']],
            ),
            wellKnownMetadata:           $wellKnownUrl === null ? null : $metadata,
        );
    }

    /** @param list<Step> $steps */
    private function step(array $steps, string $id): Step
    {
        foreach ($steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        $this->fail(sprintf('No "%s" step. Got: %s', $id, implode(', ', array_map(static fn (Step $s): string => $s->id, $steps))));
    }
}

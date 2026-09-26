<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Bootstrap;
use IndieAuthRocks\ClientTests\ClientGrader;
use IndieAuthRocks\ClientTests\ClientTestUrls;
use IndieAuthRocks\ClientTests\Markers;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Session;
use IndieAuthRocks\Identity\Sessions;
use IndieAuthRocks\Storage\ResultRepository;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\Storage\TesterRepository;
use IndieAuthRocks\Tests\Support\AppTestCase;
use IndieAuthRocks\Tests\Support\FakeIndieAuthClient;
use IndieAuthRocks\Tests\Support\KernelHttpClient;

final class ClientTestsTest extends AppTestCase
{
    private Run $run;
    private ClientTestUrls $urls;

    protected function setUp(): void
    {
        parent::setUp();

        // A signed-in tester.
        $tester   = $this->container->get(TesterRepository::class)->signIn('https://tester.example/');
        $sessions = $this->container->get(Sessions::class);
        $session  = $sessions->promote(Session::createAnonymous(), (string) $tester->id, ['indieauth']);
        $this->cookies[Sessions::COOKIE] = $session->id;

        // The client publishes metadata at its client_id.
        $this->http->json(FakeIndieAuthClient::CLIENT_ID, [
            'client_id'     => FakeIndieAuthClient::CLIENT_ID,
            'client_name'   => 'Fake Client',
            'client_uri'    => FakeIndieAuthClient::CLIENT_ID,
            'redirect_uris' => [FakeIndieAuthClient::REDIRECT_URI],
        ]);

        $page  = $this->request('GET', self::BASE . 'client-runs');
        preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $csrf);
        $start = $this->request('POST', self::BASE . 'client-runs', ['_csrf' => $csrf[1] ?? '']);

        $this->assertSame(303, $start->status);
        $this->run  = $this->container->get(RunRepository::class)->findByPublicId(basename((string) $start->header('location')));
        $this->urls = new ClientTestUrls($this->config(), $this->run->runKey);
    }

    private function client(string ...$defects): FakeIndieAuthClient
    {
        $dispatch = function (string $method, string $url, array $post, array $headers, string $body): Response {
            $parts = parse_url($url);
            parse_str((string) ($parts['query'] ?? ''), $query);

            return Bootstrap::kernel($this->container)->handle(new Request(
                method: $method,
                path: (string) $parts['path'],
                query: array_map('strval', array_filter($query, 'is_string')),
                post: $post,
                headers: $headers,
                body: $body,
                host: $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''),
                queryString: (string) ($parts['query'] ?? ''),
            ));
        };

        $http = new KernelHttpClient($dispatch, ['rocks.example', 'alt.rocks.example']);

        return new FakeIndieAuthClient(
            $http,
            fn (string $method, string $url, array $post): Response => $this->request($method, $url, $post),
            ...$defects,
        );
    }

    private function answer(int $n, string $value): void
    {
        $page = $this->request('GET', self::BASE . 'client-runs/' . $this->run->publicId . '/tests/C' . $n);
        preg_match('/name="_csrf" value="([^"]+)"/', $page->body, $csrf);

        $this->request('POST', self::BASE . 'client-runs/' . $this->run->publicId . '/tests/C' . $n . '/answer', ['_csrf' => $csrf[1] ?? '', 'value' => $value]);
    }

    /** Sign in with test n and answer honestly with what the client reported. */
    private function play(FakeIndieAuthClient $client, int $n): array
    {
        $result = $client->signIn($this->urls->profile($n));

        $this->answer($n, match (true) {
            $result['outcome'] !== 'signed_in'                             => ClientGrader::ANSWER_ERROR,
            $result['me'] === ClientGrader::expectedMe($this->urls, $n)    => ClientGrader::ANSWER_EXPECTED,
            default                                                        => ClientGrader::ANSWER_OTHER,
        });

        return $result;
    }

    /** @return array<string, array<string, mixed>> The result rows, after the run page has graded the sign-in tests. */
    private function results(): array
    {
        $this->request('GET', self::BASE . 'client-runs/' . $this->run->publicId);

        return $this->container->get(ResultRepository::class)->forRun($this->run->id);
    }

    /** @return array<string, string> */
    private function outcomes(): array
    {
        return array_map(static fn (array $r): string => (string) $r['outcome'], $this->results());
    }

    public function test_a_careful_client_passes(): void
    {
        $client = $this->client();

        foreach ([101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 112, 301, 302, 303, 304, 305, 402, 403, 404, 405, 406, 407, 408, 409] as $n) {
            $this->play($client, $n);
        }

        $outcomes = $this->outcomes();

        foreach ([101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 112, 301, 302, 303, 304, 305, 402, 403, 404, 405, 406, 407, 408, 409] as $n) {
            $this->assertSame('pass', $outcomes['C' . $n] ?? 'missing', 'C' . $n);
        }

        foreach (['C201', 'C202', 'C203', 'C204', 'C205', 'C206', 'C207', 'C208', 'C209', 'C210', 'C401'] as $id) {
            $this->assertSame('pass', $outcomes[$id] ?? 'missing', $id);
        }
    }

    public function test_client_test_profiles_advertise_the_dummy_micropub_endpoint(): void
    {
        $profile = $this->request('GET', $this->urls->profile(101));

        $this->assertMatchesRegularExpression('/<link rel="micropub" href="([^"]+)">/', $profile->body);
        preg_match('/<link rel="micropub" href="([^"]+)">/', $profile->body, $match);

        $endpoint = html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5);
        $this->assertSame($this->config()->url('micropub'), $endpoint);

        $response = $this->request('GET', $endpoint);

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->header('content-type'));
        $this->assertSame([
            'syndicate-to' => [],
            'categories'   => [],
            'channels'     => [],
            'post-types'   => [],
        ], json_decode($response->body, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{0: string, 1: list<int>, 2: list<string>}> */
    public static function defects(): iterable
    {
        yield 'ignores state'          => ['skipStateCheck', [301], ['C301']];
        yield 'ignores iss'            => ['skipIssCheck', [302, 303, 304], ['C302', 'C303', 'C304']];
        yield 'trusts me'              => ['noMeVerification', [402, 403, 404], ['C402', 'C403', 'C404']];
        yield 'ignores Link headers'   => ['headerIgnored', [102, 103], ['C102', 'C103']];
        yield 'joins relative URLs'    => ['naiveRelative', [105], ['C105']];
        yield 'no PKCE'                => ['noPkce', [101], ['C101', 'C205']];
        yield 'reads body links'       => ['bodyLinks', [112], ['C112']];
    }

    /**
     * @param list<int>    $tests
     * @param list<string> $failing
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('defects')]
    public function test_each_client_defect_is_caught(string $defect, array $tests, array $failing): void
    {
        $client = $this->client($defect);

        foreach ($tests as $n) {
            $this->play($client, $n);
        }

        $outcomes = $this->outcomes();

        foreach ($failing as $id) {
            $this->assertContains($outcomes[$id] ?? 'missing', ['fail', 'warning'], sprintf('%s should flag %s, got %s', $defect, $id, $outcomes[$id] ?? 'missing'));
        }
    }

    public function test_unsolicited_and_replayed_responses(): void
    {
        $careful = $this->client();
        $this->play($careful, 101);

        $unsolicited = $this->request('GET', self::BASE . 'client-runs/' . $this->run->publicId . '/unsolicited');
        $this->assertSame(302, $unsolicited->status);
        $this->assertSame('error', $careful->callback((string) $unsolicited->header('location'))['outcome']);
        $this->answer(306, ClientGrader::ANSWER_ERROR);

        $replay = $this->request('GET', self::BASE . 'client-runs/' . $this->run->publicId . '/replay');
        $this->assertSame('error', $careful->callback((string) $replay->header('location'))['outcome']);
        $this->answer(307, ClientGrader::ANSWER_ERROR);

        $outcomes = $this->outcomes();
        $this->assertSame('pass', $outcomes['C306'] ?? 'missing');
        $this->assertSame('pass', $outcomes['C307'] ?? 'missing');

        // A careless client redeems the unsolicited code.
        $careless = $this->client('skipStateCheck');
        $careless->signIn($this->urls->profile(101));
        $unsolicited = $this->request('GET', self::BASE . 'client-runs/' . $this->run->publicId . '/unsolicited');
        $careless->callback((string) $unsolicited->header('location'));

        $this->assertSame('fail', $this->outcomes()['C306'] ?? 'missing');
    }

    public function test_the_latest_attempt_decides_the_result(): void
    {
        $this->play($this->client('naiveRelative'), 105);
        $this->assertSame('fail', $this->outcomes()['C105'] ?? 'missing');

        // Getting it right the second time passes.
        $this->play($this->client(), 105);
        $this->assertSame('pass', $this->outcomes()['C105'] ?? 'missing');

        // The evidence shown is the latest attempt's. (Requests are split into
        // this attempt and earlier ones by time, to the second, which a test
        // running within one second cannot show.)
        $page = $this->request('GET', self::BASE . 'client-runs/' . $this->run->publicId . '/tests/C105');
        $this->assertStringNotContainsString('Requested a wrongly resolved relative URL', $page->body);

        // Getting it wrong afterwards, even by hand, fails again.
        $this->request('GET', $this->urls->profile(105));
        $this->request('GET', self::BASE . 'u/' . $this->run->runKey . '/105/m/105');
        $this->assertSame('fail', $this->outcomes()['C105'] ?? 'missing');
    }

    public function test_a_redemption_from_an_earlier_attempt_does_not_count(): void
    {
        $this->play($this->client('skipStateCheck'), 301);
        $this->assertSame('fail', $this->outcomes()['C301'] ?? 'missing');

        $this->play($this->client(), 301);
        $this->assertSame('pass', $this->outcomes()['C301'] ?? 'missing');
    }

    public function test_an_answer_is_about_one_attempt(): void
    {
        $this->client()->signIn($this->urls->profile(101));
        $this->answer(101, ClientGrader::ANSWER_ERROR);
        $this->assertSame('fail', $this->outcomes()['C101'] ?? 'missing');

        $this->client()->signIn($this->urls->profile(101));
        $this->assertSame('needs_input', $this->outcomes()['C101'] ?? 'missing');
    }

    public function test_fetching_the_profile_again_after_signing_in_keeps_the_attempt(): void
    {
        $this->play($this->client(), 402);
        $this->request('GET', $this->urls->profile(402));

        $this->assertSame('pass', $this->outcomes()['C402'] ?? 'missing');
    }

    public function test_the_latest_observation_decides_automatic_checks(): void
    {
        $this->play($this->client('noPkce'), 101);
        $this->assertSame('fail', $this->outcomes()['C205'] ?? 'missing');

        $this->play($this->client(), 102);
        $this->assertSame('pass', $this->outcomes()['C205'] ?? 'missing');
    }

    public function test_reloading_an_authorization_request_is_the_same_attempt(): void
    {
        $markers   = $this->container->get(Markers::class);
        $authorize = $this->urls->authorization(101) . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => FakeIndieAuthClient::CLIENT_ID,
            'redirect_uri'          => FakeIndieAuthClient::REDIRECT_URI,
            'state'                 => str_repeat('s', 20),
            'code_challenge'        => str_repeat('c', 43),
            'code_challenge_method' => 'S256',
            'me'                    => $this->urls->profile(101),
        ]);

        $this->request('GET', $authorize);
        $this->assertSame(1, $markers->current($this->run, 101));

        $this->request('GET', $authorize);
        $this->assertSame(1, $markers->current($this->run, 101));
    }

    public function test_evidence_from_before_attempts_were_counted_is_graded_until_the_next_attempt(): void
    {
        $this->container->get(Markers::class)->first($this->run, 105, Markers::NAIVE, 0);
        $this->assertSame('fail', $this->outcomes()['C105'] ?? 'missing');

        $this->play($this->client(), 105);
        $this->assertSame('pass', $this->outcomes()['C105'] ?? 'missing');
    }

    public function test_a_sign_in_that_asks_for_no_scope_is_noted_by_c410(): void
    {
        $client = $this->client();

        $this->assertSame('signed_in', $client->signIn($this->urls->profile(101), '')['outcome']);
        $this->answer(101, ClientGrader::ANSWER_EXPECTED);

        $results = $this->results();
        $this->assertSame('pass', $results['C101']['outcome'] ?? 'missing');
        $this->assertSame('pass', $results['C410']['outcome'] ?? 'missing');
        $this->assertStringContainsString('authorization endpoint', (string) ($results['C410']['summary'] ?? ''));
        $this->assertStringContainsString('none requested', (string) ($results['C210']['summary'] ?? ''));
    }

    public function test_c410_notes_a_sign_in_only_code_redeemed_at_the_token_endpoint(): void
    {
        $client = $this->client('redeemsSignInAtTokenEndpoint');

        $this->assertSame('signed_in', $client->signIn($this->urls->profile(101), '')['outcome']);

        $results = $this->results();
        $this->assertSame('pass', $results['C410']['outcome'] ?? 'missing');
        $this->assertStringContainsString('token endpoint', (string) ($results['C410']['summary'] ?? ''));
    }

    public function test_consent_requires_the_owner_or_the_pin(): void
    {
        $client = $this->client();
        $owner  = $this->cookies;

        $this->cookies = [];
        $result = $client->signIn($this->urls->profile(101));

        $this->assertSame('error', $result['outcome']);

        $this->cookies = $owner;
        $this->assertSame('signed_in', $client->signIn($this->urls->profile(101))['outcome']);
    }

    public function test_pin_guessing_is_limited(): void
    {
        $authorize = $this->urls->authorization(101) . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => FakeIndieAuthClient::CLIENT_ID,
            'redirect_uri'          => FakeIndieAuthClient::REDIRECT_URI,
            'state'                 => str_repeat('s', 20),
            'code_challenge'        => str_repeat('c', 43),
            'code_challenge_method' => 'S256',
            'me'                    => $this->urls->profile(101),
        ]);

        // Someone who is not the owner, without the PIN.
        $this->cookies = [];
        $consent = $this->request('GET', $authorize);
        $this->assertSame(200, $consent->status);
        preg_match('/name="_csrf" value="([^"]+)"/', $consent->body, $csrf);
        preg_match('/name="attempt" value="([^"]+)"/', $consent->body, $attempt);

        $guess = fn (string $pin): int => $this->request('POST', $this->urls->approve(101), [
            '_csrf' => $csrf[1] ?? '', 'attempt' => $attempt[1] ?? '', 'action' => 'approve', 'pin' => $pin,
        ])->status;

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(403, $guess('000000'));
        }

        $this->assertSame(429, $guess((string) $this->run->state['pin']));
    }

    public function test_client_runs_are_private(): void
    {
        $this->cookies = [];

        $this->assertSame(403, $this->request('GET', self::BASE . 'client-runs/' . $this->run->publicId)->status);
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\ClientTests\ClientGrader;
use IndieAuthRocks\ClientTests\Markers;
use IndieAuthRocks\ClientTests\Scenario;
use IndieAuthRocks\ClientTests\TestServer;
use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Outcome;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Identity\Visitor;
use IndieAuthRocks\Storage\ExchangeRepository;
use IndieAuthRocks\Storage\RateLimiter;
use IndieAuthRocks\Storage\ResultRepository;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\View\BodyFormat;
use IndieAuthRocks\View\Template;

/** Client test runs: the profile URLs to sign in with, and their results. */
final class ClientRunController extends Controller
{
    public function __construct(
        Template $view,
        SiteConfig $config,
        Guard $guard,
        private readonly TestServer $server,
        private readonly Markers $markers,
        private readonly RunRepository $runs,
        private readonly ResultRepository $results,
        private readonly ExchangeRepository $exchanges,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $config, $guard);
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->begin($request);

        if (!$visitor->isAuthenticated()) {
            return $this->guard->commit(Response::seeOther('/sign-in?next=%2Fclient-runs'), $visitor);
        }

        $runs = array_values(array_filter(
            $this->runs->forTester($visitor->user()->id),
            static fn (array $r): bool => $r['kind'] === Catalog::CLIENT,
        ));

        return $this->guard->commit($this->page('client-run/index', 'Client tests', [
            'csrf' => $visitor->session()->csrf,
            'runs' => array_map(static fn (array $r): array => ['public_id' => (string) $r['public_id'], 'created' => (string) $r['created_at']], $runs),
        ], visitor: $visitor), $visitor);
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->visitor($request);
        $this->guard->requireCsrf($visitor, $request);

        if (!$visitor->isAuthenticated()) {
            throw HttpException::forbidden('Sign in first.');
        }

        if ($this->limiter->hit('client-run-start', (string) $visitor->user()->id, 86_400) > 50) {
            throw new HttpException(429, 'You have started a lot of client runs today. Reuse an existing one, or try again tomorrow.');
        }

        $pin = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $run = $this->runs->create(Catalog::CLIENT, null, 'browser', [], ['pin' => $pin, 'answers' => []], $visitor->user()->id, $this->config->runRetentionDays);

        return Response::seeOther('/client-runs/' . $run->publicId);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        [$run, $visitor] = $this->owned($request, $params);
        $this->gradeAll($run);

        $results  = $this->results->forRun($run->id);
        $urls     = $this->server->urls($run);
        $sections = [];

        foreach (Catalog::sections(Catalog::CLIENT) as $name => $tests) {
            $rows = [];
            foreach ($tests as $test) {
                $n      = $test->number();
                $result = $results[$test->id] ?? null;
                $rows[] = [
                    'id'      => $test->id,
                    'title'   => $test->title,
                    'outcome' => (string) ($result['outcome'] ?? Outcome::NOT_RUN->value),
                    'summary' => (string) ($result['summary'] ?? ''),
                    'profile' => Scenario::exists($n) && !in_array($n, [306, 307], true) ? $urls->profile($n) : null,
                ];
            }
            $sections[] = ['name' => $name, 'rows' => $rows];
        }

        return $this->page('client-run/show', 'Client test run', [
            'run_id'   => $run->publicId,
            'pin'      => (string) ($run->state['pin'] ?? ''),
            'csrf'     => $visitor->session()->csrf,
            'counts'   => $this->results->counts($run->id),
            'sections' => $sections,
            'script'   => 'copy.js',
            'wide'     => true,
        ], visitor: $visitor)->withNoStore();
    }

    /** @param array<string, string> $params */
    public function test(Request $request, array $params = []): Response
    {
        [$run, $visitor] = $this->owned($request, $params);

        $test = Catalog::find(strtoupper($params['id'] ?? ''));
        if ($test === null || $test->suite !== Catalog::CLIENT) {
            throw HttpException::notFound();
        }

        $this->gradeAll($run);

        $n       = $test->number();
        $urls    = $this->server->urls($run);
        $result  = $this->results->forRun($run->id)[$test->id] ?? null;
        $signin  = Scenario::exists($n);
        $graded  = $signin ? $this->markers->graded($run, $n) : ['attempt' => 0, 'markers' => []];
        $markers = $graded['markers'];
        $flash   = $this->guard->takeFlash($visitor);

        // Requests from before this attempt began are kept, but set apart.
        $started  = isset($markers[Markers::STARTED]) ? (int) $markers[Markers::STARTED] : null;
        $requests = $signin ? [...$this->exchanges->forStep($run->id, 'C' . $n), ...$this->exchanges->forStep($run->id, 'C' . $n . 'd')] : [];
        usort($requests, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        $current = [];
        $earlier = [];
        foreach ($requests as $e) {
            $row = [
                'method' => (string) $e['method'],
                'url'    => (string) $e['url'],
                'at'     => substr((string) $e['created_at'], 11),
                'body'   => BodyFormat::pretty((string) ($e['request_body'] ?? '')),
            ];

            if ($started !== null && (int) strtotime((string) $e['created_at'] . ' UTC') < $started) {
                $earlier[] = $row;
            } else {
                $current[] = $row;
            }
        }

        $evidence = [];
        foreach ([
            Markers::PROFILE    => 'Fetched the profile URL',
            Markers::METADATA   => 'Fetched the metadata',
            Markers::AUTHORIZE  => 'Sent an authorization request',
            Markers::ISSUED     => 'The test server responded to the authorization request',
            Markers::REDEEMED   => 'Redeemed the code',
            Markers::ME_FETCHED => 'Fetched the returned profile URL to verify it',
            Markers::REFRESHED  => 'Refreshed an access token',
            Markers::DECOY      => 'Used the decoy server',
            Markers::NAIVE      => 'Requested a wrongly resolved relative URL',
        ] as $name => $label) {
            if (isset($markers[$name])) {
                $evidence[] = ['label' => $label, 'at' => gmdate('H:i:s', (int) $markers[$name]) . ' UTC', 'bad' => in_array($name, [Markers::DECOY, Markers::NAIVE], true)];
            }
        }

        $expected = $signin ? ClientGrader::expectedMe($urls, $n) : null;

        return $this->guard->commit($this->page('client-run/test', $test->id . ': ' . $test->title, [
            'run_id'    => $run->publicId,
            'test'      => $test->toArray(),
            'outcome'   => (string) ($result['outcome'] ?? Outcome::NOT_RUN->value),
            'summary'   => (string) ($result['summary'] ?? ''),
            'signin'    => $signin,
            'profile'   => $signin && !in_array($n, [306, 307], true) ? $urls->profile($n) : null,
            'typed'     => $n === 111 ? (string) preg_replace('#^https?://#', '', $urls->profile($n)) : null,
            'special'   => $n === 306 ? 'unsolicited' : ($n === 307 ? 'replay' : null),
            'evidence'  => $evidence,
            'attempt'   => $graded['attempt'],
            'answer'    => $signin ? (string) $this->answerFor($run, $n, $graded['attempt']) : '',
            'choices'   => [
                ClientGrader::ANSWER_EXPECTED => 'It signed me in as ' . $expected,
                ClientGrader::ANSWER_OTHER    => 'It signed me in as a different URL',
                ClientGrader::ANSWER_ERROR    => 'It showed an error or refused to sign me in',
                ClientGrader::ANSWER_NOTHING  => 'Nothing happened',
            ],
            'pin'       => (string) ($run->state['pin'] ?? ''),
            'csrf'      => $visitor->session()->csrf,
            'notice'    => is_string($flash['message'] ?? null) ? $flash['message'] : null,
            'requests'  => $current,
            'earlier'   => $earlier,
            'script'    => 'copy.js',
            'wide'      => true,
        ], visitor: $visitor)->withNoStore(), $visitor);
    }

    /** @param array<string, string> $params */
    public function answer(Request $request, array $params = []): Response
    {
        $this->guard->requireCsrf($this->guard->visitor($request), $request);
        [$run] = $this->owned($request, $params);

        $id    = strtoupper($params['id'] ?? '');
        $n     = (int) substr($id, 1);
        $value = (string) $request->post('value', '');

        if (!Scenario::exists($n) || !in_array($value, [ClientGrader::ANSWER_EXPECTED, ClientGrader::ANSWER_OTHER, ClientGrader::ANSWER_ERROR, ClientGrader::ANSWER_NOTHING], true)) {
            throw HttpException::badRequest('That answer is not one of the choices.');
        }

        // An answer is about one attempt; signing in again asks for a new one.
        $state                         = $run->state;
        $state['answers'][$n]          = $value;
        $state['answer_attempts'][$n]  = $this->markers->graded($run, $n)['attempt'];
        $this->runs->save($run, $state);

        return Response::seeOther('/client-runs/' . $run->publicId . '/tests/' . $id);
    }

    /** @param array<string, string> $params */
    public function unsolicited(Request $request, array $params = []): Response
    {
        return $this->special($request, $params, 'C306', fn (Run $run): ?string => $this->server->unsolicited($run));
    }

    /** @param array<string, string> $params */
    public function replay(Request $request, array $params = []): Response
    {
        return $this->special($request, $params, 'C307', fn (Run $run): ?string => $this->server->replay($run));
    }

    /** @param callable(Run): ?string $url */
    private function special(Request $request, array $params, string $testId, callable $url): Response
    {
        [$run, $visitor] = $this->owned($request, $params);
        $target          = $url($run);

        if ($target === null) {
            $visitor = $this->guard->flash($visitor, ['message' => 'Sign in with C101 first, so this site knows your client\'s redirect URI.']);

            return $this->guard->commit(Response::seeOther('/client-runs/' . $run->publicId . '/tests/' . $testId), $visitor);
        }

        return Response::redirect($target)->withNoStore();
    }

    private function gradeAll(Run $run): void
    {
        $existing = $this->results->forRun($run->id);

        foreach (Scenario::SIGNIN_TESTS as $n) {
            $graded  = $this->markers->graded($run, $n);
            $answer  = $this->answerFor($run, $n, $graded['attempt']);
            $verdict = ClientGrader::grade($n, $graded['markers'], $answer, time());

            if ($verdict !== null) {
                $this->results->record($run->id, $verdict, 'C' . $n, $answer !== null ? 'human' : 'auto');
            } elseif (isset($existing['C' . $n])) {
                // Only the latest attempt counts, so one with nothing to grade
                // yet clears what an earlier attempt showed.
                $this->results->forget($run->id, 'C' . $n);
            }
        }
    }

    /** The person's answer, if it was given for this attempt. Answers do not carry over to a new attempt. */
    private function answerFor(Run $run, int $n, int $attempt): ?string
    {
        $answer = $run->state['answers'][$n] ?? null;

        return is_string($answer) && (int) ($run->state['answer_attempts'][$n] ?? 0) === $attempt ? $answer : null;
    }

    /**
     * @param array<string, string> $params
     * @return array{0: Run, 1: Visitor}
     */
    private function owned(Request $request, array $params): array
    {
        $id      = $params['run'] ?? '';
        $run     = preg_match('/^[0-9A-Z]{26}$/', $id) === 1 ? $this->runs->findByPublicId($id) : null;
        $visitor = $this->guard->begin($request);

        if ($run === null || $run->kind !== Catalog::CLIENT) {
            throw HttpException::notFound();
        }

        if ($visitor->user === null || $visitor->user->id !== $run->testerId) {
            throw HttpException::forbidden('Only the tester who created this client run can see it.');
        }

        return [$run, $visitor];
    }
}

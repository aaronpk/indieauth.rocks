<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Outcome;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Engine\TokenVault;
use IndieAuthRocks\Engine\Verdict;
use IndieAuthRocks\Fetch\RecordingHttpClient;
use IndieAuthRocks\Identity\Tester;
use IndieAuthRocks\IndieAuth\Discovery;
use IndieAuthRocks\IndieAuth\MeVerifier;
use IndieAuthRocks\ServerTests\Checks\DiscoveryChecks;
use IndieAuthRocks\ServerTests\Checks\EndpointProbes;
use IndieAuthRocks\ServerTests\Checks\JwtChecks;
use IndieAuthRocks\ServerTests\Checks\LandingChecks;
use IndieAuthRocks\ServerTests\Checks\TokenChecks;
use IndieAuthRocks\Storage\ExchangeRepository;
use IndieAuthRocks\Storage\ResultRepository;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\Storage\TesterRepository;

/**
 * Runs the server test suite as a sequence of steps.
 *
 * Automatic checks run as soon as they can. Steps that need the browser
 * produce an Action; whoever drives the run (a person or a CLI driver) carries
 * it out and reports back through land() or answer().
 *
 * Code redemption checks are grouped to keep approvals to a minimum: each
 * approval yields one code, which is first presented incorrectly and then
 * correctly. Whether the server stops accepting a code after a failed attempt
 * is learned on the way, and extra approvals are added only if it does.
 */
final class ServerRunner
{
    /** Tests each step records, used when a step is skipped. */
    private const STEP_TESTS = [
        'login'         => ['S203', 'S205', 'S206', 'S301', 'S401', 'S402', 'S403', 'S404', 'S405', 'S406', 'S407', 'S408', 'S409', 'S410', 'S420', 'S421', 'S422', 'S423', 'S501', 'S502', 'S503', 'S504', 'S505', 'S701', 'S801', 'S802'],
        'c2'            => ['S204', 'S310', 'S302', 'S303', 'S601', 'S602', 'S603'],
        'c2b'           => ['S302', 'S303', 'S601', 'S602', 'S603'],
        'c3'            => ['S311', 'S312', 'S313', 'S320'],
        'c3b'           => ['S320'],
        'c4'            => ['S311'],
        'c5'            => ['S312'],
        'c8'            => ['S313'],
        'c6'            => ['S321'],
        'c7'            => ['S601', 'S602', 'S603'],
        'S322'          => ['S322'],
        'S304'          => ['S304'],
        'introspection' => ['S803', 'S804'],
    ];

    private const STEPS_WITHOUT_RECORDED_SKIP = ['c2b', 'c3b', 'c4', 'c5', 'c8'];

    /** Steps whose checks need no browser, so they can run again at any time. */
    public const AUTOMATIC_STEPS = ['discovery', 'probes'];

    /** Tests checked on every landing, keeping the worst outcome, so one more landing cannot clear them. */
    private const STICKY_TESTS = ['S201', 'S202', 'S905'];

    /** A code back this soon after the browser left most likely means no approval screen was shown. */
    private const FAST_REDIRECT_SECONDS = 3;

    /** Steps that are only needed when a code stops working after a failed redemption. */
    private const IF_TOKEN_ENDPOINT_BURNS = ['c2b'];
    private const IF_AUTHORIZATION_BURNS  = ['c3b', 'c4', 'c5', 'c8'];

    private readonly TokenClient $client;
    private readonly TokenFlows $flows;

    public function __construct(
        private readonly SiteConfig $config,
        private readonly RunRepository $runs,
        private readonly ResultRepository $results,
        private readonly ExchangeRepository $exchanges,
        private readonly RecordingHttpClient $http,
        private readonly TokenVault $vault,
        private readonly TesterRepository $testers,
    ) {
        $this->client = new TokenClient($this->http);
        $this->flows  = new TokenFlows($this->client);
    }

    // =================================================================== start

    public function start(string $input, string $mode = 'browser', bool $extended = false, bool $slow = false, ?int $testerId = null): Run
    {
        $profile = Discovery::normalizeInput($input);
        $run     = $this->runs->create(Catalog::SERVER, $profile, $mode, ['extended' => $extended, 'slow' => $slow], [], $testerId, $this->config->runRetentionDays);

        $state = [
            'profile_url' => $profile,
            'queue'       => [],
            'current'     => null,
            'attempts'    => [],
            'completed'   => [],
            'flags'       => [],
            'sticky'      => [],
        ];

        $this->runAutomaticChecks($run, $state);

        if ($state['endpoints']['authorization'] !== null) {
            $state['queue'] = ['login', ...ProbeGrader::ALL, 'c2', 'c2b', 'c3', 'c3b'];

            if ($extended) {
                array_push($state['queue'], 'c4', 'c5', 'c8', 'c6', 'S322', 'introspection');
            }

            if ($slow) {
                $state['queue'][] = 'S304';
            }
        }

        $this->runs->save($run, $state);

        return $run;
    }

    /** Discovery and the endpoint checks, which need nobody's help. */
    private function runAutomaticChecks(Run $run, array &$state): void
    {
        $profile = (string) $state['profile_url'];

        $this->http->context($run->id, 'discovery');
        $discovery = (new Discovery($this->http))->discover($profile);
        $this->recordAll($run, DiscoveryChecks::grade($discovery), 'discovery');

        $state['discovery_urls'] = $discovery->discoveryUrls();
        $state['issuer']         = $discovery->string('issuer');
        $state['jwks_uri']       = $discovery->string('jwks_uri');
        $state['methods']        = array_values(array_filter((array) ($discovery->metadata['code_challenge_methods_supported'] ?? []), 'is_string'));
        $state['endpoints']      = [
            'authorization' => $discovery->authorizationEndpoint(),
            'token'         => $discovery->tokenEndpoint(),
            'revocation'    => $discovery->string('revocation_endpoint'),
            'userinfo'      => $discovery->string('userinfo_endpoint'),
            'introspection' => $discovery->string('introspection_endpoint'),
        ];

        $this->http->context($run->id, 'probes');
        $this->recordAll($run, (new EndpointProbes($this->client))->run($state['endpoints'], $this->urls($run)), 'probes');

        $this->http->context(null);
    }

    // ==================================================================== next

    public function next(Run $run): Action
    {
        if ($run->isFinished()) {
            return new Action(Action::FINISHED);
        }

        $state = $run->state;

        if (($state['current'] ?? null) !== null) {
            return $this->actionFor($run, $state['current']);
        }

        while (($step = array_shift($state['queue'])) !== null) {
            $skip = $this->skipReason($state, $step);

            if ($skip !== null) {
                if (!in_array($step, self::STEPS_WITHOUT_RECORDED_SKIP, true)) {
                    foreach ($this->testsFor($step) as $id) {
                        $this->results->record($run->id, Verdict::skipped($id, $skip), $step);
                    }
                }

                continue;
            }

            if ($step === 'introspection') {
                $state['current'] = ['step' => $step, 'awaiting' => 'answer', 'question' => (new Question(
                    'credential',
                    'Paste the credential a resource server uses to call your introspection endpoint.',
                    freeText: true,
                    hint: 'It is sent only to your introspection endpoint, kept encrypted for the rest of the run, then discarded. Leave it empty to skip these tests.',
                ))->toArray()];
            } else {
                $attempt = $this->attemptFor($run, $state, $step);
                $this->remember($run, $state, $attempt);
                $state['current'] = ['step' => $step, 'attempt' => $attempt->id, 'awaiting' => 'landing'];
            }

            $this->runs->save($run, $state);

            return $this->actionFor($run, $state['current']);
        }

        $this->runs->save($run, $state);
        $this->finish($run);

        return new Action(Action::FINISHED);
    }

    /**
     * The step the run is on, or the one it will start next, without
     * starting anything.
     *
     * @return array{step: string, title: string, tests: list<string>, in_progress: bool}|null Null when no step is left to run.
     */
    public function upcoming(Run $run): ?array
    {
        if ($run->isFinished()) {
            return null;
        }

        $state = $run->state;
        $step  = $state['current']['step'] ?? null;

        if ($step === null) {
            foreach ($state['queue'] as $queued) {
                if ($this->skipReason($state, $queued) === null) {
                    $step = $queued;
                    break;
                }
            }
        }

        return $step === null ? null : [
            'step'        => (string) $step,
            'title'       => $this->title((string) $step),
            'tests'       => $this->testsFor((string) $step),
            'in_progress' => ($state['current'] ?? null) !== null,
        ];
    }

    /** What the step in progress needs, or null when no step has been started. */
    public function currentAction(Run $run): ?Action
    {
        $current = $run->state['current'] ?? null;

        return $current === null || $run->isFinished() ? null : $this->actionFor($run, $current);
    }

    // ==================================================================== land

    /**
     * The browser came back from the server under test.
     *
     * @return array{handled: bool, message: string, tester: ?Tester, step: ?string}
     */
    public function land(Run $run, string $url, bool $trusted = true): array
    {
        $landing = Landing::parse($this->config, $url);

        if ($landing === null || $landing->runKey !== $run->runKey) {
            return ['handled' => false, 'message' => 'This address is not part of this test run.', 'tester' => null, 'step' => null];
        }

        $state   = $run->state;
        $current = $state['current'] ?? null;

        $attemptId = $landing->attemptId();
        if ($attemptId === null || !isset($state['attempts'][$attemptId])) {
            // The state was lost or mangled. The request in progress stands in
            // only when the landing is known to come from the run's own
            // browser, or its path names that step (/catch/{key}/{step}).
            $namesStep = $landing->segment !== null && $landing->segment === ($current['step'] ?? null);
            $attemptId = $trusted || $namesStep ? ($current['attempt'] ?? null) : null;
        }

        if ($attemptId === null || !isset($state['attempts'][$attemptId])) {
            return ['handled' => false, 'message' => 'No test is waiting for this redirect.', 'tester' => null, 'step' => null];
        }

        if (isset($state['completed'][$attemptId])) {
            return ['handled' => false, 'message' => 'This redirect was already recorded.', 'tester' => null, 'step' => null];
        }

        $attempt  = AuthorizationAttempt::fromArray($state['attempts'][$attemptId], $this->vault->get($run, 'verifier:' . $attemptId));
        $step     = $attempt->step;
        $openedAt = $state['attempts'][$attemptId]['opened_at'] ?? null;

        $state['completed'][$attemptId] = true;
        if (($current['attempt'] ?? null) === $attemptId) {
            $state['current'] = null;
        }

        // Redeeming without the verifier would fail for this site's reasons,
        // not the server's, so the step runs again instead of being graded.
        if ($attempt->verifier === null && $attempt->intent === AuthorizationAttempt::APPROVE) {
            if ($state['current'] === null) {
                array_unshift($state['queue'], $step);
            }

            $this->runs->save($run, $state);

            return ['handled' => true, 'message' => 'This step was left waiting so long that its saved PKCE verifier expired, so it will run again.', 'tester' => null, 'step' => $step];
        }

        $this->http->context($run->id, $step);

        foreach (LandingChecks::everyLanding($landing, $attempt, $state['issuer'] ?? null) as $verdict) {
            $this->recordSticky($run, $state, $verdict, $step);
        }

        $tester  = null;
        $message = '';

        switch (true) {
            case $step === 'login':
                $tester = $this->onLogin($run, $state, $attempt, $landing, $message);
                break;
            case in_array($step, ProbeGrader::ALL, true):
                $this->onProbe($run, $state, $attempt, $landing, $message);
                break;
            case in_array($step, ['c2', 'c2b'], true):
                $this->onTokenCode($run, $state, $attempt, $landing, $message);
                break;
            case in_array($step, ['c3', 'c3b', 'c4', 'c5', 'c8'], true):
                $this->onScopelessCode($run, $state, $attempt, $landing, $message);
                break;
            case $step === 'c7':
                $this->onRevocationCode($run, $state, $attempt, $landing, $message);
                break;
            case $step === 'c6':
                $this->onScopelessAtTokenEndpoint($run, $state, $attempt, $landing, $message);
                break;
            case $step === 'S322':
                $this->onVerifierWithoutChallenge($run, $state, $attempt, $landing, $message);
                break;
            case $step === 'S304':
                $this->onExpiry($run, $state, $attempt, $landing, $message);
                break;
            case $step === 'introspection':
                $this->onIntrospection($run, $state, $attempt, $landing, $message);
                break;
        }

        $this->http->context(null);
        $this->runs->save($run, $state);

        return ['handled' => true, 'message' => $this->landingMessage($attempt, $landing, is_int($openedAt) ? $openedAt : null, $message), 'tester' => $tester, 'step' => $step];
    }

    /** Note when the browser was sent to the server, to spot a redirect that came back without anyone clicking. */
    public function markOpened(Run $run, string $attemptId): void
    {
        $state = $run->state;

        if (!isset($state['attempts'][$attemptId])) {
            return;
        }

        $state['attempts'][$attemptId]['opened_at'] = time();
        $this->runs->save($run, $state);
    }

    /**
     * Say that the browser came back and with what. A server that redirects
     * straight back leaves nothing on screen to show it happened, so this is
     * always said, followed by whatever the step handler had to add.
     */
    private function landingMessage(AuthorizationAttempt $attempt, Landing $landing, ?int $openedAt, string $message): string
    {
        $step  = $attempt->step;
        $label = in_array($step, ProbeGrader::ALL, true)
            ? $step . ' (' . (Catalog::find($step)?->title ?? '') . ')'
            : 'this step (' . $this->title($step) . ')';

        $parts = [sprintf('Your server redirected back %s for %s.', $landing->describe(), $label), $message];

        $elapsed = $openedAt === null ? null : max(0, time() - $openedAt);

        if ($elapsed !== null && $elapsed <= self::FAST_REDIRECT_SECONDS && $landing->code() !== null && $attempt->intent === AuthorizationAttempt::CANCEL) {
            $parts[] = sprintf(
                'It came back %s after you opened the request, so your server probably approved it without showing an approval screen.',
                $elapsed < 1 ? 'less than a second' : ($elapsed === 1 ? '1 second' : $elapsed . ' seconds'),
            );
            $parts[] = $step === 'S207'
                ? 'If it remembers approving this site, revoke that approval in your server so you can cancel this request, or skip this step.'
                : 'Check that it only skips the approval screen when the whole request matches an earlier approval, redirect URI included, and not just the client_id.';
        }

        return implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    // =================================================================== rerun

    /**
     * Why a test cannot be run again on its own, or null if it can.
     *
     * @param array<string, mixed>|null $result The test's row from ResultRepository::forRun().
     */
    public function rerunReason(Run $run, string $testId, ?array $result): ?string
    {
        $step = $result['step'] ?? null;

        return match (true) {
            $run->status === Run::ABANDONED                => 'This run was abandoned. Start a new run to test your server again.',
            in_array($testId, self::STICKY_TESTS, true)    => 'This is checked on every redirect back from your server, keeping the worst result. Start a new run to check it again.',
            $step === null                                 => 'This test has not run yet.',
            in_array($step, self::AUTOMATIC_STEPS, true)   => null,
            in_array($testId, ProbeGrader::ALL, true) && $step === $testId
                                                           => $this->skipReason($run->state, $testId),
            default                                        => sprintf('This is checked together with other tests during this step: %s. Start a new run to check it again.', $this->title((string) $step)),
        };
    }

    /**
     * Run one test again. Automatic checks run on the spot; a browser check
     * becomes the next step, reopening the run if it had finished.
     *
     * @return string A notice for the person.
     */
    public function rerun(Run $run, string $testId): string
    {
        $result = $this->results->forRun($run->id)[$testId] ?? null;
        $reason = $this->rerunReason($run, $testId, $result);

        if ($reason !== null) {
            throw new \LogicException($testId . ' cannot run again: ' . $reason);
        }

        $state = $run->state;

        if (in_array($result['step'], self::AUTOMATIC_STEPS, true)) {
            $this->runAutomaticChecks($run, $state);
            $this->runs->save($run, $state);

            return 'The discovery and endpoint checks ran again.';
        }

        // finish() revoked the tokens and cleared the vault. Browser checks
        // need neither: a fresh attempt stores its own verifier, and next()
        // finishes the run again once the queue is empty.
        $status         = $run->isFinished() ? Run::RUNNING : null;
        $current        = $state['current'] ?? null;
        $state['queue'] = array_values(array_filter($state['queue'], static fn (string $step): bool => $step !== $testId));

        if (($current['step'] ?? null) === $testId) {
            $this->runs->save($run, $state, $status);

            return $testId . ' is already the next step.';
        }

        $message = $testId . ' is now the next step.';

        if ($current !== null && $current['awaiting'] === 'landing') {
            // The request that was next goes back in line. If the browser
            // still comes back from it, that redirect is ignored.
            $state['completed'][$current['attempt']] = true;
            array_unshift($state['queue'], $current['step']);
            $state['current'] = null;
        } elseif ($current !== null) {
            $message = $testId . ' will run after the step in progress.';
        }

        array_unshift($state['queue'], $testId);
        $this->runs->save($run, $state, $status);

        return $message;
    }

    // ================================================================== answer

    public function answer(Run $run, string $key, string $value, string $by = 'human'): bool
    {
        $state   = $run->state;
        $current = $state['current'] ?? null;

        if ($current === null) {
            return false;
        }

        if ($key === 'S206' && $current['step'] === 'login' && $current['awaiting'] === 'answer') {
            $verdict = match ($value) {
                'both'      => Verdict::pass('S206', 'The approval screen showed the application name and client_id.'),
                'client_id' => Verdict::pass('S206', 'The approval screen showed the client_id.'),
                'name'      => Verdict::warning('S206', 'The approval screen showed only the name from the client metadata. Showing the client_id too lets people spot an app pretending to be another.'),
                'neither'   => Verdict::fail('S206', 'The approval screen did not say which application was asking.'),
                'no_screen' => Verdict::fail('S206', 'There was no approval screen, so the person could not see which application was asking.'),
                default     => null,
            };

            if ($verdict === null) {
                return false;
            }

            $this->results->record($run->id, $verdict, 'login', $by);
            $state['current'] = null;
        } elseif (in_array($key, ProbeGrader::ALL, true) && $current['step'] === $key) {
            $verdict = ProbeGrader::fromAnswer($key, $value);
            $this->results->record($run->id, $verdict, $key, $by);

            if ($key === 'S215' && $value === 'consent') {
                $state['flags']['accepts_no_pkce'] = true;
            }

            if (isset($current['attempt'])) {
                $state['completed'][$current['attempt']] = true;
            }

            $state['current'] = null;
        } elseif ($key === 'credential' && $current['step'] === 'introspection' && $current['awaiting'] === 'answer') {
            if (trim($value) === '') {
                $this->results->record($run->id, Verdict::skipped('S803', 'No credential was provided.'), 'introspection', $by);
                $this->results->record($run->id, Verdict::skipped('S804', 'No credential was provided.'), 'introspection', $by);
                $state['current'] = null;
            } else {
                $this->vault->put($run, 'credential', trim($value));

                // Some servers issue a token per resource server, so the
                // credential only sees tokens meant for its own endpoint.
                $state['current'] = ['step' => 'introspection', 'awaiting' => 'answer', 'question' => (new Question(
                    'introspection-scope',
                    'Which scope should the access token have for that resource server to accept it?',
                    freeText: true,
                    hint: 'For a Micropub endpoint this is usually "create". Leave it empty to use "profile".',
                ))->toArray()];
            }
        } elseif ($key === 'introspection-scope' && $current['step'] === 'introspection' && $current['awaiting'] === 'answer') {
            $scope   = (string) preg_replace('/[^\x21\x23-\x5B\x5D-\x7E ]/', '', trim($value));
            $attempt = AuthorizationAttempt::build('introspection', AuthorizationAttempt::APPROVE, $this->urls($run)->clientId(), $this->urls($run)->callback(), [
                'scope' => $scope === '' ? 'profile' : mb_substr($scope, 0, 200),
                'me'    => (string) $state['profile_url'],
            ]);
            $this->remember($run, $state, $attempt);
            $state['current'] = ['step' => 'introspection', 'attempt' => $attempt->id, 'awaiting' => 'landing'];
        } else {
            return false;
        }

        $this->runs->save($run, $state);

        return true;
    }

    /** Skip the current step, recording its tests as skipped. */
    public function skip(Run $run): void
    {
        $state   = $run->state;
        $current = $state['current'] ?? null;

        if ($current === null) {
            return;
        }

        $existing = $this->results->forRun($run->id);

        foreach ($this->testsFor($current['step']) as $id) {
            if (!isset($existing[$id])) {
                $this->results->record($run->id, Verdict::skipped($id, 'You skipped this step.'), $current['step'], 'human');
            }
        }

        if (isset($current['attempt'])) {
            $state['completed'][$current['attempt']] = true;
        }

        $state['current'] = null;
        $this->runs->save($run, $state);
    }

    /** Continue a step that was waiting for time to pass (S304). */
    public function resume(Run $run): bool
    {
        $state   = $run->state;
        $current = $state['current'] ?? null;

        if (($current['awaiting'] ?? null) !== 'wait' || time() < (int) $current['until']) {
            return false;
        }

        $attempt = AuthorizationAttempt::fromArray($state['attempts'][$current['attempt']], $this->vault->get($run, 'verifier:' . $current['attempt']));
        $code    = $this->vault->get($run, 'code:S304');
        $urls    = $this->urls($run);

        // A missing code would be refused whether or not codes expire.
        if ($code === null || $attempt->verifier === null) {
            $this->results->record($run->id, Verdict::error('S304', 'The saved code expired from this site\'s storage before it could be tried. Run the slow tests again.'), 'S304');
            $state['current'] = null;
            $this->runs->save($run, $state);

            return true;
        }

        $this->http->context($run->id, 'S304');
        $reply = $this->client->redeem((string) $state['endpoints']['authorization'], $code, $urls->clientId(), $urls->callback(), $attempt->verifier);

        $this->results->record($run->id, $reply->isSuccess()
            ? Verdict::fail('S304', 'A code was still accepted 11 minutes after it was issued.')
            : Verdict::pass('S304', 'The expired code was refused (' . $reply->describe() . ').'), 'S304');

        $state['current'] = null;
        $this->http->context(null);
        $this->runs->save($run, $state);

        return true;
    }

    /** Revoke anything still live, discard secrets, and mark the run complete. */
    public function finish(Run $run): void
    {
        $revocation = $run->state['endpoints']['revocation'] ?? null;

        if ($revocation !== null) {
            $this->http->context($run->id, 'cleanup');

            foreach (['live:A:access', 'live:A:refresh', 'live:B:access', 'live:B:refresh'] as $name) {
                $token = $this->vault->get($run, $name);

                if ($token !== null) {
                    $this->client->revoke($revocation, $token);
                }
            }

            $this->http->context(null);
        }

        $this->vault->clear($run);
        $state            = $run->state;
        $state['current'] = null;
        $state['queue']   = [];
        $this->runs->save($run, $state, Run::COMPLETE);
    }

    /** The number of approvals still ahead, as far as is known. */
    public function approvalsRemaining(Run $run): int
    {
        $state = $run->state;
        $count = 0;

        foreach ($state['queue'] ?? [] as $step) {
            if (in_array($step, ['login', 'c2', 'c3', 'c6', 'c7', 'S304', 'introspection', 'S220'], true)
                || $this->neededBecauseCodesBurn($state, $step)
                || ($step === 'S322' && ($state['flags']['accepts_no_pkce'] ?? false))) {
                $count++;
            }
        }

        return $count;
    }

    // =========================================================== step handlers

    private function onLogin(Run $run, array &$state, AuthorizationAttempt $attempt, Landing $landing, string &$message): ?Tester
    {
        $this->results->record($run->id, LandingChecks::code($landing), 'login');

        $code = $landing->code();
        if ($code === null) {
            array_unshift($state['queue'], 'login');
            $message = 'Signing in did not complete, so this step will run again.';

            return null;
        }

        $urls      = $this->urls($run);
        $endpoints = $state['endpoints'];

        $fetched = $this->exchanges->inboundSeen($run->id, '/clients/' . $run->runKey . '/' . ClientUrls::MAIN);
        $this->results->record($run->id, Verdict::check('S205', $fetched,
            'Your server fetched the client_id document.',
            'Your server did not fetch the client_id document, so it could not show the app\'s name or check its redirect URIs.'), 'login');

        $verifyMe = fn (string $me): array => (new MeVerifier(new Discovery($this->http)))
            ->verify($me, (array) $state['discovery_urls'], (string) $endpoints['authorization']);

        $tester = null;

        if ($endpoints['token'] !== null) {
            $reply = $this->client->redeem($endpoints['token'], $code, $urls->clientId(), $urls->callback(), $attempt->verifier);

            $this->results->record($run->id, Verdict::check('S301', $reply->isSuccess(),
                'The code was exchanged for tokens.', 'The token endpoint returned ' . $reply->describe() . '.'), 'login');

            $graded = TokenChecks::grade($reply, ['profile'], $verifyMe);
            $this->recordAll($run, $graded['verdicts'], 'login');

            $access  = $reply->string('access_token');
            $refresh = $reply->string('refresh_token');

            $this->recordAll($run, JwtChecks::grade(
                $access,
                isset($state['jwks_uri']) ? (string) $state['jwks_uri'] : null,
                isset($state['issuer']) ? (string) $state['issuer'] : null,
                $urls->clientId(),
                $reply->string('scope'),
                $this->http,
            ), 'login');

            if ($access !== null) {
                $profile = $reply->json()['profile'] ?? null;
                $this->recordAll($run, $this->flows->afterSignIn($endpoints, $access, is_array($profile) ? $profile : null), 'login');

                $chain = $this->flows->refreshChain($endpoints, $urls->clientId(), $urls->otherClientId(), $access, $refresh);
                $this->recordAll($run, $chain['verdicts'], 'login');

                $this->keepLive($run, 'A', $chain['access'], $chain['refresh']);
            } else {
                foreach (['S501', 'S502', 'S503', 'S504', 'S505', 'S701', 'S801', 'S802'] as $id) {
                    $this->results->record($run->id, Verdict::skipped($id, 'No access token was issued.'), 'login');
                }
            }
        } else {
            $reply  = $this->client->redeem((string) $endpoints['authorization'], $code, $urls->clientId(), $urls->callback(), $attempt->verifier);
            $graded = TokenChecks::grade($reply, [], $verifyMe);

            foreach ($graded['verdicts'] as $verdict) {
                if (in_array($verdict->testId, ['S404', 'S405'], true)) {
                    $this->results->record($run->id, $verdict, 'login');
                }
            }

            foreach (['S301', 'S401', 'S402', 'S403', 'S406', 'S407', 'S408', 'S409', 'S410', 'S420', 'S421', 'S422', 'S423', 'S501', 'S502', 'S503', 'S504', 'S505', 'S701', 'S801', 'S802'] as $id) {
                $this->results->record($run->id, Verdict::skipped($id, 'No token endpoint.'), 'login');
            }
        }

        if ($graded['verified'] && $graded['me'] !== null) {
            $state['login_me'] = $graded['me'];
            $tester            = $this->testers->signIn($graded['me']);
            $this->runs->bindTester($run, $tester->id);
        }

        $state['current'] = ['step' => 'login', 'attempt' => $attempt->id, 'awaiting' => 'answer', 'question' => (new Question(
            'S206',
            'What did your server\'s approval screen show about this application?',
            [
                'both'      => 'Its name and its URL (' . $urls->clientId() . ')',
                'client_id' => 'Only its URL',
                'name'      => 'Only its name',
                'neither'   => 'Neither',
                'no_screen' => 'There was no approval screen',
            ],
        ))->toArray()];

        $message = $tester !== null ? 'You are signed in as ' . $tester->displayName() . '.' : '';

        return $tester;
    }

    private function onProbe(Run $run, array &$state, AuthorizationAttempt $attempt, Landing $landing, string &$message): void
    {
        $id      = $attempt->step;
        $verdict = ProbeGrader::fromLanding($id, $landing);

        if ($verdict === null && $id === 'S220') {
            $urls  = $this->urls($run);
            $reply = $this->client->redeem((string) $state['endpoints']['authorization'], (string) $landing->code(), $urls->clientId(), $urls->callback(), $attempt->verifier);
            $me    = $reply->string('me');

            $verdict = match (true) {
                $me === null                   => Verdict::error('S220', 'The code could not be redeemed to check whose profile it was for: ' . $reply->describe() . '.'),
                strtolower(rtrim($me, '/')) === strtolower(rtrim($urls->strangerProfile(), '/')) => Verdict::fail('S220', 'Your server returned the profile URL the request asked for, without verifying it belongs to you.'),
                default                        => Verdict::pass('S220', 'Your server returned ' . $me . ' rather than the profile URL the request asked for.'),
            };
        }

        if ($verdict === null) {
            return;
        }

        $this->results->record($run->id, $verdict, $id);

        if ($id === 'S215' && in_array($verdict->outcome, [Outcome::WARNING, Outcome::FAIL], true)) {
            $state['flags']['accepts_no_pkce'] = true;
        }

        if ($id === 'S207' && $verdict->outcome === Outcome::ERROR) {
            array_unshift($state['queue'], 'S207');
            $message = 'That request was approved rather than cancelled, so it will be asked again.';
        }
    }

    /** c2 and c2b: codes with scope, redeemed at the token endpoint. */
    private function onTokenCode(Run $run, array &$state, AuthorizationAttempt $attempt, Landing $landing, string &$message): void
    {
        $step = $attempt->step;
        $code = $landing->code();

        if ($code === null) {
            array_unshift($state['queue'], $step);
            $message = 'That request did not return a code, so it will be asked again.';

            return;
        }

        $this->results->record($run->id, LandingChecks::preservedQuery($landing), $step);

        $urls      = $this->urls($run);
        $endpoints = $state['endpoints'];
        $token     = (string) $endpoints['token'];
        $redirect  = $urls->callback(true);
        $reply     = null;

        if ($step === 'c2') {
            $wrong = $this->client->redeem($token, $code, $urls->clientId(), $redirect, TokenClient::madeUp() . 'Zz9');

            if ($wrong->isSuccess()) {
                $this->results->record($run->id, Verdict::fail('S310', 'Tokens were issued for a code_verifier that does not match the challenge.'), $step);
                $reply = $wrong;
            } else {
                $this->results->record($run->id, Verdict::pass('S310', 'The wrong verifier was refused (' . $wrong->describe() . ').'), $step);

                $reply = $this->client->redeem($token, $code, $urls->clientId(), $redirect, $attempt->verifier);

                if ($reply->isSuccess()) {
                    $state['flags']['burns_on_failure'] = false;
                } else {
                    $state['flags']['burns_on_failure'] = true;
                    $message = 'Your server stopped accepting that code after a failed attempt, which is allowed. One more approval is needed to finish these tests.';

                    return;
                }
            }
        } else {
            $reply = $this->client->redeem($token, $code, $urls->clientId(), $redirect, $attempt->verifier);

            if (!$reply->isSuccess()) {
                foreach (['S302', 'S303', 'S601', 'S602', 'S603'] as $id) {
                    $this->results->record($run->id, Verdict::error($id, 'The code could not be exchanged: ' . $reply->describe() . '.'), $step);
                }

                return;
            }
        }

        $access  = $reply->string('access_token');
        $refresh = $reply->string('refresh_token');
        $this->keepLive($run, 'B', $access, $refresh);

        // S303 compares before and after: a token that never worked at the
        // userinfo endpoint would otherwise look revoked.
        $worked = $access !== null && $endpoints['userinfo'] !== null
            && $this->client->userinfo($endpoints['userinfo'], $access)->isSuccess();

        // S302, S303
        $replay = $this->client->redeem($token, $code, $urls->clientId(), $redirect, $attempt->verifier);
        $this->results->record($run->id, $replay->isSuccess()
            ? Verdict::fail('S302', 'The same code was exchanged a second time.')
            : Verdict::pass('S302', 'The used code was refused (' . $replay->describe() . ').'), $step);

        $revokedByReplay = false;

        if ($access === null || $endpoints['userinfo'] === null) {
            $this->results->record($run->id, Verdict::skipped('S303', $access === null ? 'No access token was issued.' : 'No userinfo endpoint to check the tokens with.'), $step);
        } elseif (!$worked) {
            $this->results->record($run->id, Verdict::skipped('S303', 'The access token did not work at the userinfo endpoint even before the code was replayed, so revocation could not be checked.'), $step);
        } else {
            $check           = $this->client->userinfo($endpoints['userinfo'], $access);
            $revokedByReplay = !$check->isSuccess();

            $this->results->record($run->id, Verdict::check('S303', $revokedByReplay,
                'The tokens issued for the code were revoked when it was replayed.',
                'The tokens issued for the code still work after the code was replayed.'), $step);
        }

        if ($access === null) {
            foreach (['S601', 'S602', 'S603'] as $id) {
                $this->results->record($run->id, Verdict::skipped($id, 'No access token was issued.'), $step);
            }

            return;
        }

        $this->keepLive($run, 'B', null, null);

        // A server that revoked these tokens on replay did the right thing, but
        // left nothing to test revocation with: that needs one more sign-in.
        if ($revokedByReplay && $endpoints['revocation'] !== null) {
            array_unshift($state['queue'], 'c7');
            $message = 'Your server revoked the tokens when the code was replayed. One more approval is needed for the revocation tests.';

            return;
        }

        $this->recordAll($run, $this->flows->revocation($endpoints, $urls->clientId(), $access, $refresh, false), $step);
    }

    /** c7: fresh tokens for the revocation tests. */
    private function onRevocationCode(Run $run, array &$state, AuthorizationAttempt $attempt, Landing $landing, string &$message): void
    {
        $code = $landing->code();

        if ($code === null) {
            array_unshift($state['queue'], 'c7');
            $message = 'That request did not return a code, so it will be asked again.';

            return;
        }

        $urls      = $this->urls($run);
        $endpoints = $state['endpoints'];
        $reply     = $this->client->redeem((string) $endpoints['token'], $code, $urls->clientId(), $urls->callback(true), $attempt->verifier);
        $access    = $reply->string('access_token');

        if ($access === null) {
            foreach (['S601', 'S602', 'S603'] as $id) {
                $this->results->record($run->id, Verdict::error($id, 'No access token to revoke: ' . $reply->describe() . '.'), 'c7');
            }

            return;
        }

        $this->recordAll($run, $this->flows->revocation($endpoints, $urls->clientId(), $access, $reply->string('refresh_token'), false), 'c7');
    }

    /** c3, c3b, c4, c5: codes without scope, redeemed at the authorization endpoint. */
    private function onScopelessCode(Run $run, array &$state, AuthorizationAttempt $attempt, Landing $landing, string &$message): void
    {
        $step = $attempt->step;
        $code = $landing->code();

        if ($code === null) {
            array_unshift($state['queue'], $step);
            $message = 'That request did not return a code, so it will be asked again.';

            return;
        }

        $urls     = $this->urls($run);
        $endpoint = (string) $state['endpoints']['authorization'];
        $client   = $urls->clientId();
        $redirect = $urls->callback();
        // Learned separately from the token endpoint's behaviour: a server may
        // treat failed redemptions differently at each endpoint.
        $burns    = $state['flags']['burns_at_authorization'] ?? null;

        $negatives = match ($step) {
            'c3'    => ['S311', 'S312', 'S313'],
            'c4'    => ['S311'],
            'c5'    => ['S312'],
            'c8'    => ['S313'],
            default => [],
        };

        $recorded = [];
        foreach ($negatives as $id) {
            $reply = match ($id) {
                'S311' => $this->client->redeem($endpoint, $code, $client, $redirect, null),
                'S312' => $this->client->redeem($endpoint, $code, $urls->otherClientId(), $redirect, $attempt->verifier),
                'S313' => $this->client->redeem($endpoint, $code, $client, $urls->callback(true), $attempt->verifier),
            };

            $verdict = $reply->isSuccess()
                ? Verdict::fail($id, 'The code was accepted: ' . $reply->describe() . '.')
                : Verdict::pass($id, 'Refused (' . $reply->describe() . ').');

            $this->results->record($run->id, $verdict, $step);
            $recorded[] = $id;
        }

        if (!in_array($step, ['c3', 'c3b'], true)) {
            return;
        }

        $reply = $this->client->redeem($endpoint, $code, $client, $redirect, $attempt->verifier);

        if ($reply->isSuccess()) {
            $this->results->record($run->id, match (true) {
                $reply->string('access_token') !== null => Verdict::fail('S320', 'The authorization endpoint returned an access token.'),
                $reply->string('me') === null           => Verdict::fail('S320', 'The response has no me.'),
                default                                 => Verdict::pass('S320', 'Returned me (' . $reply->string('me') . ') and no access token.'),
            }, $step);

            if ($burns === null && $recorded !== []) {
                $state['flags']['burns_at_authorization'] = false;
            }

            return;
        }

        if ($step === 'c3' && $recorded !== [] && $burns !== false) {
            // The code stopped working after the first rejected attempt, so only
            // that first rejection proves anything.
            $state['flags']['burns_at_authorization'] = true;

            foreach (array_slice($recorded, 1) as $id) {
                $this->results->record($run->id, Verdict::skipped($id, 'Inconclusive: the code was no longer valid after an earlier rejected attempt. Run the extended tests to check this separately.'), $step);
            }

            // c3b is already queued; it runs now that burning is known.
            $message = 'Your server stopped accepting that code after a failed attempt. One more approval is needed.';

            return;
        }

        $this->results->record($run->id, Verdict::fail('S320', 'Redeeming the code at the authorization endpoint returned ' . $reply->describe() . '.'), $step);
    }

    private function onScopelessAtTokenEndpoint(Run $run, array &$state, AuthorizationAttempt $attempt, Landing $landing, string &$message): void
    {
        $code = $landing->code();

        if ($code === null) {
            array_unshift($state['queue'], 'c6');

            return;
        }

        $urls  = $this->urls($run);
        $reply = $this->client->redeem((string) $state['endpoints']['token'], $code, $urls->clientId(), $urls->callback(), $attempt->verifier);

        $this->results->record($run->id, match (true) {
            $reply->string('access_token') !== null => Verdict::fail('S321', 'An access token was issued for a code without any scope.'),
            $reply->isSuccess()                     => Verdict::pass('S321', 'Returned me without an access token.'),
            default                                 => Verdict::pass('S321', 'No access token was issued (' . $reply->describe() . ').'),
        }, 'c6');

        if ($reply->string('access_token') !== null) {
            $this->keepLive($run, 'C', $reply->string('access_token'), $reply->string('refresh_token'));
        }
    }

    private function onVerifierWithoutChallenge(Run $run, array &$state, AuthorizationAttempt $attempt, Landing $landing, string &$message): void
    {
        $code = $landing->code();

        if ($code === null) {
            $this->results->record($run->id, Verdict::pass('S322', 'Your server did not issue a code for a request without PKCE this time.'), 'S322');

            return;
        }

        $urls  = $this->urls($run);
        $reply = $this->client->redeem((string) $state['endpoints']['authorization'], $code, $urls->clientId(), $urls->callback(), TokenClient::madeUp() . 'Zz9');

        $this->results->record($run->id, $reply->isSuccess()
            ? Verdict::fail('S322', 'A code_verifier was accepted for a code issued without a code_challenge.')
            : Verdict::pass('S322', 'Refused (' . $reply->describe() . ').'), 'S322');
    }

    private function onExpiry(Run $run, array &$state, AuthorizationAttempt $attempt, Landing $landing, string &$message): void
    {
        $code = $landing->code();

        if ($code === null) {
            array_unshift($state['queue'], 'S304');

            return;
        }

        $this->vault->put($run, 'code:S304', $code);
        $state['current'] = ['step' => 'S304', 'attempt' => $attempt->id, 'awaiting' => 'wait', 'until' => time() + 660];
        $message          = 'Now wait 11 minutes before the code is used.';
    }

    private function onIntrospection(Run $run, array &$state, AuthorizationAttempt $attempt, Landing $landing, string &$message): void
    {
        $code       = $landing->code();
        $credential = $this->vault->get($run, 'credential');
        $endpoints  = $state['endpoints'];

        if ($code === null || $credential === null) {
            foreach (['S803', 'S804'] as $id) {
                $this->results->record($run->id, Verdict::error($id, $code === null ? 'No code was issued.' : 'The credential expired; run this step again.'), 'introspection');
            }

            return;
        }

        $urls   = $this->urls($run);
        $reply  = $this->client->redeem((string) $endpoints['token'], $code, $urls->clientId(), $urls->callback(), $attempt->verifier);
        $access = $reply->string('access_token');

        if ($access === null) {
            foreach (['S803', 'S804'] as $id) {
                $this->results->record($run->id, Verdict::error($id, 'No access token to introspect: ' . $reply->describe() . '.'), 'introspection');
            }

            return;
        }

        $active = $this->client->introspect((string) $endpoints['introspection'], $access, $credential);
        $json   = $active->json() ?? [];

        $this->results->record($run->id, match (true) {
            $active->status() !== 200                  => Verdict::fail('S803', 'Introspection returned ' . $active->describe() . '.'),
            ($json['active'] ?? null) !== true         => Verdict::fail('S803', 'A valid access token was reported as not active.'),
            !isset($json['me'])                        => Verdict::fail('S803', 'The response has no me.'),
            ($json['client_id'] ?? null) !== $urls->clientId() => Verdict::warning('S803', 'The client_id in the response does not match this client.'),
            default                                    => Verdict::pass('S803', 'The token is active for ' . $json['me'] . '.'),
        }, 'introspection');

        $invalid = $this->client->introspect((string) $endpoints['introspection'], TokenClient::madeUp(), $credential);
        $json    = $invalid->json();

        $this->results->record($run->id, match (true) {
            $invalid->status() !== 200 || $json === null || ($json['active'] ?? null) !== false => Verdict::fail('S804', 'A made-up token returned ' . $invalid->describe() . ' instead of HTTP 200 with active: false.'),
            count($json) > 1 => Verdict::warning('S804', 'The response for an invalid token included more than active: false.'),
            default          => Verdict::pass('S804', 'Returned active: false.'),
        }, 'introspection');

        if ($endpoints['revocation'] !== null) {
            $this->client->revoke($endpoints['revocation'], $access);
        }

        $this->vault->forget($run, 'credential');
    }

    // ================================================================= helpers

    private function actionFor(Run $run, array $current): Action
    {
        $step  = (string) $current['step'];
        $tests = $this->testsFor($step);

        if ($current['awaiting'] === 'answer') {
            return new Action(Action::ASK, $step, $tests, $this->title($step), '', question: Question::fromArray($current['question']));
        }

        if ($current['awaiting'] === 'wait') {
            return new Action(Action::WAIT, $step, $tests, $this->title($step), 'Waiting for the code to expire before trying it.', until: (int) $current['until']);
        }

        $attempt = AuthorizationAttempt::fromArray($run->state['attempts'][$current['attempt']], null);
        $url     = $attempt->url((string) $run->state['endpoints']['authorization']);

        return new Action(
            Action::NAVIGATE,
            $step,
            $tests,
            $this->title($step),
            $this->instructions($run, $step),
            $attempt->id,
            $url,
            $attempt->intent,
            in_array($step, ProbeGrader::ALL, true)
                ? ProbeGrader::question($step, (string) parse_url($this->config->altBaseUrl, PHP_URL_HOST))
                : null,
        );
    }

    private function title(string $step): string
    {
        return match ($step) {
            'login'         => 'Sign in with your server',
            'c2'            => 'Approve a second request',
            'c2b', 'c3b'    => 'Approve one more request',
            'c3'            => 'Approve a request without permissions',
            'c4', 'c5', 'c8' => 'Approve a request to test a single wrong redemption',
            'c6'            => 'Approve a request without permissions (token endpoint)',
            'c7'            => 'Approve one more request for the revocation tests',
            'S322'          => 'Approve a request without PKCE',
            'S304'          => 'Approve a request, then wait for the code to expire',
            'introspection' => 'Introspection with your credential',
            default         => $step . ': ' . (Catalog::find($step)?->title ?? ''),
        };
    }

    private function instructions(Run $run, string $step): string
    {
        return match ($step) {
            'login' => sprintf('Your browser goes to your authorization endpoint to sign in to this site as %s. Approve the request. This is also how you sign in here.', $run->state['profile_url']),
            'c2'    => 'Approve this request. The code is first presented with a wrong PKCE verifier, then correctly, and the tokens are used for the replay and revocation tests.',
            'c2b', 'c3b' => 'Your server stopped accepting the previous code after a rejected attempt, so another code is needed to finish these tests. Approve this request.',
            'c3'    => 'Approve this request, which asks for no permissions. Its code is presented with a missing verifier, a different client_id and a different redirect URI before being redeemed at your authorization endpoint.',
            'c7'    => 'Your server revoked earlier tokens when a code was replayed, which is good. Approve this request to get fresh tokens for the revocation tests.',
            'c4'    => 'Approve this request. Its code is presented once without a verifier.',
            'c5'    => 'Approve this request. Its code is presented once by a different client.',
            'c8'    => 'Approve this request. Its code is presented once with a different redirect URI.',
            'c6'    => 'Approve this request, which asks for no permissions. Its code is redeemed at your token endpoint, which must not issue an access token.',
            'S322'  => 'Approve this request, which has no PKCE challenge. Its code is then presented with a verifier.',
            'S304'  => 'Approve this request. The code is kept for 11 minutes before it is used.',
            'introspection' => 'Approve this request. Its access token is checked with your introspection endpoint using your credential.',
            default => ProbeGrader::instructions($step),
        };
    }

    /** @return string|null Why a step cannot run, or null. */
    private function skipReason(array $state, string $step): ?string
    {
        $endpoints = $state['endpoints'];

        return match (true) {
            $endpoints['authorization'] === null                                          => 'No authorization endpoint.',
            in_array($step, [...self::IF_TOKEN_ENDPOINT_BURNS, ...self::IF_AUTHORIZATION_BURNS], true)
                && !$this->neededBecauseCodesBurn($state, $step)                          => 'Not needed.',
            in_array($step, ['S208', 'S209', 'S210', 'S211', 'S212', 'S213', 'S214'], true) && !$this->config->hasDistinctAltOrigin()
                                                                                          => 'This site has no second origin configured.',
            $step === 'S216' && in_array('plain', (array) $state['methods'], true)        => 'Your server advertises the plain method.',
            in_array($step, ['c2', 'c2b', 'c6', 'introspection'], true) && $endpoints['token'] === null
                                                                                          => 'No token endpoint.',
            $step === 'introspection' && $endpoints['introspection'] === null             => 'No introspection endpoint.',
            $step === 'S322' && !($state['flags']['accepts_no_pkce'] ?? false)            => 'Your server requires PKCE.',
            default                                                                       => null,
        };
    }

    private function neededBecauseCodesBurn(array $state, string $step): bool
    {
        return (in_array($step, self::IF_TOKEN_ENDPOINT_BURNS, true) && ($state['flags']['burns_on_failure'] ?? null) === true)
            || (in_array($step, self::IF_AUTHORIZATION_BURNS, true) && ($state['flags']['burns_at_authorization'] ?? null) === true);
    }

    private function attemptFor(Run $run, array $state, string $step): AuthorizationAttempt
    {
        $urls    = $this->urls($run);
        $me      = (string) $state['profile_url'];
        $approve = AuthorizationAttempt::APPROVE;
        $cancel  = AuthorizationAttempt::CANCEL;
        $main    = $urls->clientId();

        $attempt = match ($step) {
            'login'           => AuthorizationAttempt::build($step, $approve, $main, $urls->callback(), ['scope' => 'profile', 'me' => $me]),
            'S207'            => AuthorizationAttempt::build($step, $cancel, $main, $urls->callback(), ['scope' => 'profile', 'me' => $me]),
            'S208'            => AuthorizationAttempt::build($step, $cancel, $main, $urls->catch($step), ['me' => $me]),
            'S209'            => AuthorizationAttempt::build($step, $cancel, $urls->clientId(ClientUrls::CROSS), $urls->registeredCrossOrigin(), ['me' => $me]),
            'S210'            => AuthorizationAttempt::build($step, $cancel, $urls->clientId(ClientUrls::CROSS), $urls->registeredCrossOriginWithSuffix($step), ['me' => $me]),
            'S211'            => AuthorizationAttempt::build($step, $cancel, $main, $urls->userinfoTrick($step), ['me' => $me]),
            'S212'            => AuthorizationAttempt::build($step, $cancel, $urls->clientId(ClientUrls::MISMATCH), $urls->catch($step), ['me' => $me]),
            'S213'            => AuthorizationAttempt::build($step, $cancel, $main, $urls->callback(), ['me' => $me])->withExtraParam(['redirect_uri', $urls->catch($step)]),
            'S214'            => AuthorizationAttempt::build($step, $cancel, $main, $urls->catch($step), ['client_id' => null, 'me' => $me]),
            'S215', 'S322'    => AuthorizationAttempt::build($step, $step === 'S322' ? $approve : $cancel, $main, $urls->callback(), ['code_challenge' => null, 'code_challenge_method' => null, 'me' => $me]),
            'S216'            => AuthorizationAttempt::build($step, $cancel, $main, $urls->callback(), ['code_challenge_method' => 'plain', 'me' => $me]),
            'S217'            => AuthorizationAttempt::build($step, $cancel, $main, $urls->callback(), ['response_type' => 'token', 'me' => $me]),
            'S218'            => AuthorizationAttempt::build($step, $cancel, $main, $urls->callback(), ['state' => null, 'me' => $me]),
            'S219'            => AuthorizationAttempt::build($step, $cancel, $urls->clientId('x/../' . ClientUrls::MAIN), $urls->callback(), ['me' => $me]),
            'S220'            => AuthorizationAttempt::build($step, $approve, $main, $urls->callback(), ['me' => $urls->strangerProfile()]),
            'c2', 'c2b', 'c7' => AuthorizationAttempt::build($step, $approve, $main, $urls->callback(true), ['scope' => 'profile', 'me' => $me]),
            'c3', 'c3b', 'c4', 'c5', 'c8', 'c6', 'S304' => AuthorizationAttempt::build($step, $approve, $main, $urls->callback(), ['me' => $me]),
        };

        // A plain challenge is the verifier itself.
        if ($step === 'S216') {
            $params = array_map(
                static fn (array $pair): array => $pair[0] === 'code_challenge' ? ['code_challenge', (string) $attempt->verifier] : $pair,
                $attempt->params,
            );
            $attempt = new AuthorizationAttempt($attempt->id, $step, $params, $attempt->intent, $attempt->verifier);
        }

        return $attempt;
    }

    private function remember(Run $run, array &$state, AuthorizationAttempt $attempt): void
    {
        $state['attempts'][$attempt->id] = $attempt->toArray();

        if ($attempt->verifier !== null) {
            $this->vault->put($run, 'verifier:' . $attempt->id, $attempt->verifier);
        }
    }

    private function keepLive(Run $run, string $family, ?string $access, ?string $refresh): void
    {
        foreach (['access' => $access, 'refresh' => $refresh] as $kind => $token) {
            $name = 'live:' . $family . ':' . $kind;

            $token === null ? $this->vault->forget($run, $name) : $this->vault->put($run, $name, $token);
        }
    }

    /** @return list<string> */
    private function testsFor(string $step): array
    {
        return self::STEP_TESTS[$step] ?? (Catalog::find($step) !== null ? [$step] : []);
    }

    private function urls(Run $run): ClientUrls
    {
        return new ClientUrls($this->config, $run->runKey);
    }

    /** @param list<Verdict> $verdicts */
    private function recordAll(Run $run, array $verdicts, string $step): void
    {
        foreach ($verdicts as $verdict) {
            $this->results->record($run->id, $verdict, $step);
        }
    }

    /** For tests checked on every landing: keep the worst outcome seen. */
    private function recordSticky(Run $run, array &$state, Verdict $verdict, string $step): void
    {
        $previous = isset($state['sticky'][$verdict->testId])
            ? new Verdict($verdict->testId, Outcome::from($state['sticky'][$verdict->testId]))
            : null;

        if ($verdict->isWorseThan($previous)) {
            $state['sticky'][$verdict->testId] = $verdict->outcome->value;
            $this->results->record($run->id, $verdict, $step);
        }
    }
}

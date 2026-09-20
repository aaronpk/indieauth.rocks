<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Crypto\Random;
use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Outcome;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Engine\TestDefinition;
use IndieAuthRocks\Http\Cookie;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Identity\Visitor;
use IndieAuthRocks\ServerTests\Action;
use IndieAuthRocks\ServerTests\ServerRunner;
use IndieAuthRocks\ServerTests\TestOptions;
use IndieAuthRocks\Storage\ExchangeRepository;
use IndieAuthRocks\Storage\RateLimiter;
use IndieAuthRocks\Storage\ResultRepository;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\View\ExchangeRows;
use IndieAuthRocks\View\Template;

/** Server test runs in the browser. */
final class ServerRunController extends Controller
{
    // Somebody fixing their server starts run after run; these are here to stop
    // the site being used to flood a third party, not to pace that work.
    private const START_BUCKET = 'start:ip';
    private const START_LIMIT  = 30;
    private const START_WINDOW = 900;

    private const RERUN_BUCKET = 'rerun:ip';
    private const RERUN_LIMIT  = 60;

    public function __construct(
        Template $view,
        SiteConfig $config,
        Guard $guard,
        private readonly ServerRunner $runner,
        private readonly RunRepository $runs,
        private readonly ResultRepository $results,
        private readonly ExchangeRepository $exchanges,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $config, $guard);
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->begin($request);
        $this->guard->requireCsrf($visitor, $request);

        $me = trim((string) $request->post('me', ''));

        if ($me === '') {
            return $this->guard->commit(Response::seeOther('/'), $visitor);
        }

        if ($this->limiter->hit(self::START_BUCKET, $request->ip, self::START_WINDOW) > self::START_LIMIT) {
            throw new HttpException(429, 'Too many test runs started from your address. Try again in a few minutes.');
        }

        $run = $this->runner->start($me, 'browser', $request->post('extended') === '1', $request->post('slow') === '1', $visitor->user?->id);

        // Whoever started the run can drive it, even before signing in.
        $secret                = Random::token(24);
        $state                 = $run->state;
        $state['owner_hash']   = hash('sha256', $secret);
        $this->runs->save($run, $state);

        return $this->guard->commit(
            Response::seeOther('/runs/' . $run->publicId)->withCookie(new Cookie(
                name:    self::ownerCookie($run),
                value:   $secret,
                expires: time() + 7 * 86_400,
                secure:  $this->config->secureCookies(),
            )),
            $visitor,
        );
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $run     = $this->run($params);
        $visitor = $this->guard->begin($request);
        $owner    = $this->isOwner($request, $visitor, $run);
        $upcoming = $owner ? $this->runner->upcoming($run) : null;

        // Only steps that cannot run are left: next() records them as skipped and finishes the run.
        if ($owner && $upcoming === null && !$run->isFinished()) {
            $this->runner->next($run);
        }

        $run     = $this->runs->find($run->id) ?? $run;
        $flash   = $this->guard->takeFlash($visitor);
        $results = $this->results->forRun($run->id);

        $sections = [];
        foreach (Catalog::sections(Catalog::SERVER) as $name => $tests) {
            $rows = [];

            foreach ($tests as $test) {
                if ($test->extended && !$run->option('extended') && !isset($results[$test->id])) {
                    continue;
                }

                $result = $results[$test->id] ?? null;
                $rows[] = [
                    'id'      => $test->id,
                    'title'   => $test->title,
                    'level'   => $test->level->value,
                    'outcome' => $result['outcome'] ?? Outcome::NOT_RUN->value,
                    'label'   => Outcome::tryFrom((string) ($result['outcome'] ?? 'not_run'))?->label() ?? '',
                    'summary' => (string) ($result['summary'] ?? ''),
                    'has_log' => $owner && $result !== null && $result['step'] !== null,
                    'next'    => $upcoming !== null && in_array($test->id, $upcoming['tests'], true),
                ];
            }

            if ($rows !== []) {
                $sections[] = ['name' => $name, 'rows' => $rows];
            }
        }

        return $this->guard->commit($this->page('run/show', 'Server test run', [
            'menu_section' => 'server-tests',
            'run'        => [
                'public_id' => $run->publicId,
                'target'    => (string) $run->targetUrl,
                'status'    => $run->status,
                'created'   => $run->createdAt,
                'extended'  => (bool) $run->option('extended'),
                'signed_in' => $run->state['login_me'] ?? null,
            ],
            'owner'      => $owner,
            'can_publish' => $run->status === Run::COMPLETE && $visitor->user !== null && $visitor->user->id === $run->testerId,
            'upcoming'   => $upcoming,
            'finished'   => $run->isFinished(),
            'approvals'  => $owner ? $this->runner->approvalsRemaining($run) : 0,
            'counts'     => $this->results->counts($run->id),
            'sections'   => $sections,
            'csrf'       => $visitor->session()->csrf,
            'notice'     => is_string($flash['message'] ?? null) && $flash['message'] !== '' ? $flash['message'] : null,
            'wide'       => true,
        ], visitor: $visitor)->withNoStore(), $visitor);
    }

    /** @param array<string, string> $params */
    public function go(Request $request, array $params = []): Response
    {
        $run     = $this->run($params);
        $visitor = $this->guard->visitor($request);

        if (!$this->isOwner($request, $visitor, $run)) {
            throw HttpException::forbidden('Only the person who started this run can continue it.');
        }

        $action = $this->runner->currentAction($run);

        if ($action?->type !== Action::NAVIGATE || $action->attemptId !== ($params['attempt'] ?? null) || $action->url === null) {
            return Response::seeOther($this->afterStepUrl($run));
        }

        $this->runner->markOpened($run, $action->attemptId);

        return Response::redirect($action->url)->withNoStore();
    }

    /**
     * Start the next step. The run page only says what it is, so nobody
     * loses their place when a step finishes.
     *
     * @param array<string, string> $params
     */
    public function startStep(Request $request, array $params = []): Response
    {
        return $this->ownerAction($request, $params, function (Run $run): ?string {
            $this->runner->next($run);

            return null;
        });
    }

    /**
     * The step in progress: its instructions, the request to open, and any question.
     *
     * @param array<string, string> $params
     */
    public function step(Request $request, array $params = []): Response
    {
        $run = $this->run($params);

        if (!$this->isOwner($request, $this->guard->visitor($request), $run)) {
            throw HttpException::forbidden('Only the person who started this run can continue it.');
        }

        $visitor = $this->guard->begin($request);
        $action  = $this->runner->currentAction($run);

        if ($action === null) {
            return $this->guard->commit(Response::seeOther('/runs/' . $run->publicId), $visitor);
        }

        $flash = $this->guard->takeFlash($visitor);

        return $this->guard->commit($this->page('run/step', $action->title, [
            'menu_section' => 'server-tests',
            'run'       => ['public_id' => $run->publicId, 'target' => (string) $run->targetUrl],
            'action'    => $action->toArray(),
            'approvals' => $this->runner->approvalsRemaining($run),
            'csrf'      => $visitor->session()->csrf,
            'notice'    => is_string($flash['message'] ?? null) && $flash['message'] !== '' ? $flash['message'] : null,
        ], visitor: $visitor)->withNoStore(), $visitor);
    }

    /** @param array<string, string> $params */
    public function answer(Request $request, array $params = []): Response
    {
        return $this->ownerAction($request, $params, function (Run $run) use ($request): ?string {
            $action = $this->runner->currentAction($run);

            if ($action === null || !$this->runner->answer($run, (string) $request->post('key', ''), (string) $request->post('value', ''))) {
                return 'That answer did not match the step in progress, so nothing was recorded.';
            }

            return $this->finishedNotice($run, $action);
        });
    }

    /** @param array<string, string> $params */
    public function skip(Request $request, array $params = []): Response
    {
        return $this->ownerAction($request, $params, function (Run $run): ?string {
            $action = $this->runner->currentAction($run);
            $this->runner->skip($run);

            return $action === null ? null : 'You skipped ' . $action->title . '.';
        });
    }

    /**
     * Start the step in progress again, for the person who opened its request
     * and never came back.
     *
     * @param array<string, string> $params
     */
    public function restart(Request $request, array $params = []): Response
    {
        return $this->ownerAction($request, $params, function (Run $run): ?string {
            $action  = $this->runner->currentAction($run);
            $refused = $this->runner->restart($run);

            if ($refused !== null) {
                return $refused;
            }

            // Mint the fresh request straight away, so the step page has one.
            $this->runner->next($this->runs->find($run->id) ?? $run);

            return 'Starting ' . ($action?->title ?? 'that step') . ' again.';
        });
    }

    /** @param array<string, string> $params */
    public function resume(Request $request, array $params = []): Response
    {
        return $this->ownerAction($request, $params, function (Run $run): ?string {
            $action = $this->runner->currentAction($run);

            return $action !== null && $this->runner->resume($run) ? $this->finishedNotice($run, $action) : null;
        });
    }

    /**
     * One test in a run: what it checks, its result, how to run it again, and
     * the HTTP log.
     *
     * @param array<string, string> $params
     */
    public function transcript(Request $request, array $params = []): Response
    {
        $run = $this->run($params);

        if (!$this->isOwner($request, $this->guard->visitor($request), $run)) {
            throw HttpException::forbidden('Transcripts are only visible to the person who ran the tests.');
        }

        $visitor = $this->guard->begin($request);
        $test    = $this->serverTest($params);
        $flash   = $this->guard->takeFlash($visitor);
        $result  = $this->results->forRun($run->id)[$test->id] ?? null;
        $step    = $result['step'] ?? null;
        $options = $this->runner->optionsFor($run, $test->id, $result);

        $exchanges = $step === null ? [] : ExchangeRows::format($this->exchanges->forTest($run->id, (string) $step, $test->id));

        // Tests graded together share a step; requests made only for the others are left out.
        $hidden = $step === null ? 0 : count($this->exchanges->forStep($run->id, (string) $step)) - count($exchanges);

        return $this->guard->commit($this->page('run/transcript', $test->id . ' ' . $test->title, [
            'menu_section' => 'server-tests',
            'run_id'       => $run->publicId,
            'test'         => $test->toArray(),
            'outcome'      => (string) ($result['outcome'] ?? 'not_run'),
            'summary'      => (string) ($result['summary'] ?? ''),
            'options'      => $options->toArray(),
            'csrf'         => $visitor->session()->csrf,
            'notice'       => is_string($flash['message'] ?? null) && $flash['message'] !== '' ? $flash['message'] : null,
            'exchanges'    => $exchanges,
            'exchanges_html' => $this->view->partial('partials/exchanges', ['exchanges' => $exchanges]),
            'hidden_count' => $hidden,
            'wide'         => true,
        ], visitor: $visitor)->withNoStore(), $visitor);
    }

    /** @param array<string, string> $params */
    public function rerun(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->visitor($request);
        $this->guard->requireCsrf($visitor, $request);

        $run = $this->run($params);

        if (!$this->isOwner($request, $visitor, $run)) {
            throw HttpException::forbidden('Only the person who started this run can run its tests again.');
        }

        $test    = $this->serverTest($params);
        $result  = $this->results->forRun($run->id)[$test->id] ?? null;
        $testUrl = '/runs/' . $run->publicId . '/tests/' . $test->id;
        $options = $this->runner->optionsFor($run, $test->id, $result);

        if ($options->kind !== TestOptions::RUN) {
            return $this->guard->commit(
                Response::seeOther($options->kind === TestOptions::CONTINUE ? '/runs/' . $run->publicId . '/step' : $testUrl),
                $this->guard->flash($visitor, ['message' => $options->reason ?? 'That step is already in progress.']),
            );
        }

        $automatic = in_array($options->step, ServerRunner::AUTOMATIC_STEPS, true);

        // Automatic checks fetch from the server under test straight away, like starting a run does.
        if ($automatic && $this->limiter->hit(self::RERUN_BUCKET, $request->ip, self::START_WINDOW) > self::RERUN_LIMIT) {
            throw new HttpException(429, 'Too many checks were run again from your address. Try again in a few minutes.');
        }

        $message = $this->runner->rerun($run, $test->id);
        $run     = $this->runs->find($run->id) ?? $run;

        // A step asked for by name starts straight away, unless another is in progress.
        if (!$automatic && $this->runner->currentAction($run) === null && $this->runner->next($run)->step === $options->step) {
            $message = $result === null ? 'Running ' . $test->id . ' now.' : 'Running ' . $test->id . ' again.';
        }

        return $this->guard->commit(
            Response::seeOther($automatic ? $testUrl : $this->afterStepUrl($run)),
            $this->guard->flash($visitor, ['message' => $message]),
        );
    }

    /**
     * The registered redirect URI of the client this site plays.
     *
     * @param array<string, string> $params
     */
    public function callback(Request $request, array $params = []): Response
    {
        $key = $params['key'] ?? '';
        $run = preg_match('/^[0-9a-z]{16}$/', $key) === 1 ? $this->runs->findByKey($key) : null;

        if ($run === null) {
            throw HttpException::notFound();
        }

        $exchangeId = $this->exchanges->recordInbound($run->id, 'landing', $request);

        $visitor = $this->guard->begin($request);

        // Only the browser that started the run may complete its steps.
        // Otherwise a crafted callback URL could sign a victim in as the
        // attacker's profile (login CSRF) or write results into the run.
        if (!$this->isOwner($request, $visitor, $run)) {
            return $this->guard->commit($this->page('message', 'Not your test run', [
                'heading' => 'This redirect belongs to someone else\'s test run',
                'message' => 'Nothing was recorded. If you started this run in another browser, continue it there.',
            ], 403, $visitor), $visitor);
        }

        $url    = $this->config->url(ltrim($request->path, '/')) . ($request->queryString ? '?' . $request->queryString : '');
        $result = $this->runner->land($run, $url);

        if ($result['step'] !== null) {
            $this->exchanges->setStep($exchangeId, $result['step']);
        }

        if ($result['tester'] !== null) {
            $visitor = $this->guard->logIn($visitor, $result['tester']);
        }

        $visitor = $this->guard->flash($visitor, ['message' => $result['message']]);

        return $this->guard->commit(Response::seeOther($this->afterStepUrl($run)), $visitor);
    }

    // ================================================================= helpers

    /** Back to the step while it still needs something, otherwise to the list of tests. */
    private function afterStepUrl(Run $run): string
    {
        $run = $this->runs->find($run->id) ?? $run;

        return '/runs/' . $run->publicId . ($this->runner->currentAction($run) !== null ? '/step' : '');
    }

    /** How a step that has just finished came out, or null while it still needs something. */
    private function finishedNotice(Run $run, Action $action): ?string
    {
        if ($this->runner->currentAction($run) !== null) {
            return null;
        }

        $results = $this->results->forRun($run->id);
        $labels  = [];

        foreach ($action->tests as $id) {
            if (isset($results[$id])) {
                $labels[] = Outcome::tryFrom((string) $results[$id]['outcome'])?->label() ?? '';
            }
        }

        $labels = array_values(array_filter($labels));

        if ($labels === []) {
            return 'Finished ' . $action->title . '.';
        }

        if (count($action->tests) === 1) {
            return sprintf('Finished %s. Result: %s.', $action->title, $labels[0]);
        }

        $counts = [];
        foreach (array_count_values($labels) as $label => $n) {
            $counts[] = $n . ' ' . strtolower((string) $label) . ($label === 'Warning' && $n !== 1 ? 's' : '');
        }

        return sprintf('Finished %s. Results: %s.', $action->title, implode(', ', $counts));
    }

    /** @param array<string, string> $params */
    private function run(array $params): Run
    {
        $id = $params['run'] ?? '';

        $run = preg_match('/^[0-9A-Z]{26}$/', $id) === 1 ? $this->runs->findByPublicId($id) : null;

        // Runs of other kinds have their own pages: a checkup rendered here
        // would be a server run with none of its state.
        return $run !== null && $run->kind === Catalog::SERVER ? $run : throw HttpException::notFound();
    }

    /** @param array<string, string> $params */
    private function serverTest(array $params): TestDefinition
    {
        $test = Catalog::find(strtoupper($params['test'] ?? ''));

        return $test !== null && $test->suite === Catalog::SERVER ? $test : throw HttpException::notFound();
    }

    private function isOwner(Request $request, Visitor $visitor, Run $run): bool
    {
        $cookie = $request->cookie(self::ownerCookie($run));
        $hash   = $run->state['owner_hash'] ?? null;

        if ($cookie !== null && is_string($hash) && hash_equals($hash, hash('sha256', $cookie))) {
            return true;
        }

        return $visitor->user !== null && $run->testerId === $visitor->user->id;
    }

    /**
     * @param array<string, string>  $params
     * @param callable(Run): ?string $work Returns a notice to show, if any.
     */
    private function ownerAction(Request $request, array $params, callable $work): Response
    {
        $visitor = $this->guard->visitor($request);
        $this->guard->requireCsrf($visitor, $request);

        $run = $this->run($params);

        if (!$this->isOwner($request, $visitor, $run)) {
            throw HttpException::forbidden('Only the person who started this run can continue it.');
        }

        $notice = $work($run);

        if ($notice !== null) {
            $visitor = $this->guard->flash($visitor, ['message' => $notice]);
        }

        return $this->guard->commit(Response::seeOther($this->afterStepUrl($run)), $visitor);
    }

    private static function ownerCookie(Run $run): string
    {
        return 'iar_run_' . strtolower($run->publicId);
    }
}

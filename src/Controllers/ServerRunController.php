<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Crypto\Random;
use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Outcome;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Http\Cookie;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Identity\Visitor;
use IndieAuthRocks\ServerTests\Action;
use IndieAuthRocks\ServerTests\ServerRunner;
use IndieAuthRocks\Storage\ExchangeRepository;
use IndieAuthRocks\Storage\RateLimiter;
use IndieAuthRocks\Storage\ResultRepository;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\View\Template;

/** Server test runs in the browser. */
final class ServerRunController extends Controller
{
    private const START_BUCKET = 'start:ip';
    private const START_LIMIT  = 10;
    private const START_WINDOW = 600;

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
        $owner   = $this->isOwner($request, $visitor, $run);
        $action  = $owner ? $this->runner->next($run) : null;
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
                ];
            }

            if ($rows !== []) {
                $sections[] = ['name' => $name, 'rows' => $rows];
            }
        }

        return $this->guard->commit($this->page('run/show', 'Server test run', [
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
            'action'     => $action?->toArray(),
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

        $action = $this->runner->next($run);

        if ($action->type !== Action::NAVIGATE || $action->attemptId !== ($params['attempt'] ?? null) || $action->url === null) {
            return Response::seeOther('/runs/' . $run->publicId);
        }

        return Response::redirect($action->url)->withNoStore();
    }

    /** @param array<string, string> $params */
    public function answer(Request $request, array $params = []): Response
    {
        return $this->ownerAction($request, $params, function (Run $run) use ($request): void {
            $this->runner->answer($run, (string) $request->post('key', ''), (string) $request->post('value', ''));
        });
    }

    /** @param array<string, string> $params */
    public function skip(Request $request, array $params = []): Response
    {
        return $this->ownerAction($request, $params, fn (Run $run) => $this->runner->skip($run));
    }

    /** @param array<string, string> $params */
    public function resume(Request $request, array $params = []): Response
    {
        return $this->ownerAction($request, $params, fn (Run $run) => $this->runner->resume($run));
    }

    /** @param array<string, string> $params */
    public function transcript(Request $request, array $params = []): Response
    {
        $run     = $this->run($params);
        $visitor = $this->guard->visitor($request);

        if (!$this->isOwner($request, $visitor, $run)) {
            throw HttpException::forbidden('Transcripts are only visible to the person who ran the tests.');
        }

        $test   = Catalog::find(strtoupper($params['test'] ?? '')) ?? throw HttpException::notFound();
        $result = $this->results->forRun($run->id)[$test->id] ?? null;
        $step   = $result['step'] ?? null;

        $exchanges = $step === null ? [] : array_map(static fn (array $e): array => [
            'direction'        => (string) $e['direction'],
            'method'           => (string) $e['method'],
            'url'              => (string) $e['url'],
            'status'           => $e['status'] !== null ? (int) $e['status'] : null,
            'request_headers'  => self::headerText($e['request_headers']),
            'request_body'     => (string) ($e['request_body'] ?? ''),
            'response_headers' => self::headerText($e['response_headers']),
            'response_body'    => (string) ($e['response_body'] ?? ''),
            'error'            => (string) ($e['error'] ?? ''),
            'created_at'       => (string) $e['created_at'],
        ], $this->exchanges->forStep($run->id, (string) $step));

        return $this->page('run/transcript', $test->id . ' transcript', [
            'run_id'    => $run->publicId,
            'test'      => $test->toArray(),
            'outcome'   => (string) ($result['outcome'] ?? 'not_run'),
            'summary'   => (string) ($result['summary'] ?? ''),
            'exchanges' => $exchanges,
            'wide'      => true,
        ], visitor: $visitor)->withNoStore();
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

        $this->exchanges->recordInbound($run->id, 'landing', $request);

        $url    = $this->config->url(ltrim($request->path, '/')) . ($request->queryString ? '?' . $request->queryString : '');
        $result = $this->runner->land($run, $url);

        $visitor = $this->guard->begin($request);

        if ($result['tester'] !== null) {
            $visitor = $this->guard->logIn($visitor, $result['tester']);
        }

        $visitor = $this->guard->flash($visitor, ['message' => $result['message']]);

        return $this->guard->commit(Response::seeOther('/runs/' . $run->publicId), $visitor);
    }

    // ================================================================= helpers

    /** @param array<string, string> $params */
    private function run(array $params): Run
    {
        $id = $params['run'] ?? '';

        return (preg_match('/^[0-9A-Z]{26}$/', $id) === 1 ? $this->runs->findByPublicId($id) : null)
            ?? throw HttpException::notFound();
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
     * @param array<string, string> $params
     * @param callable(Run): mixed   $work
     */
    private function ownerAction(Request $request, array $params, callable $work): Response
    {
        $run     = $this->run($params);
        $visitor = $this->guard->visitor($request);

        $this->guard->requireCsrf($visitor, $request);

        if (!$this->isOwner($request, $visitor, $run)) {
            throw HttpException::forbidden('Only the person who started this run can continue it.');
        }

        $work($run);

        return Response::seeOther('/runs/' . $run->publicId);
    }

    private static function ownerCookie(Run $run): string
    {
        return 'iar_run_' . strtolower($run->publicId);
    }

    /** @param array<string, string|list<string>> $headers */
    private static function headerText(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            foreach ((array) $value as $v) {
                $lines[] = $name . ': ' . $v;
            }
        }

        return implode("\n", $lines);
    }
}

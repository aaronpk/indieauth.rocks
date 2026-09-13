<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Outcome;
use IndieAuthRocks\Engine\Run;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Storage\ReportRepository;
use IndieAuthRocks\Storage\ResultRepository;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\View\Template;

/** Implementation reports: publishing a run's results, and the public pages. */
final class ReportController extends Controller
{
    public function __construct(
        Template $view,
        SiteConfig $config,
        Guard $guard,
        private readonly ReportRepository $reports,
        private readonly RunRepository $runs,
        private readonly ResultRepository $results,
    ) {
        parent::__construct($view, $config, $guard);
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $groups = [Catalog::SERVER => [], Catalog::CLIENT => []];

        foreach ($this->reports->all() as $row) {
            $counts = (array) ($row['summary']['counts'] ?? []);

            $groups[(string) $row['kind']][] = [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'url'       => (string) ($row['url'] ?? ''),
                'tester'    => (string) preg_replace('#^https?://#', '', rtrim((string) $row['me'], '/')),
                'published' => substr((string) $row['published_at'], 0, 10),
                'pass'      => (int) ($counts['pass'] ?? 0),
                'fail'      => (int) ($counts['fail'] ?? 0),
                'warning'   => (int) ($counts['warning'] ?? 0),
            ];
        }

        return $this->page('reports/index', 'Implementation reports', [
            'servers' => $groups[Catalog::SERVER],
            'clients' => $groups[Catalog::CLIENT],
            'wide'    => true,
        ], visitor: $this->guard->visitor($request));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $id      = (int) self::param($params, 'id', '/^[1-9]\d{0,9}$/');
        $row     = $this->reports->find($id) ?? throw HttpException::notFound();
        $visitor = $this->guard->begin($request);
        $results = (array) ($row['summary']['results'] ?? []);

        $sections = [];
        foreach (Catalog::sections((string) $row['kind']) as $name => $tests) {
            $rows = [];

            foreach ($tests as $test) {
                if (!isset($results[$test->id]) && $test->extended) {
                    continue;
                }

                $rows[] = [
                    'id'      => $test->id,
                    'title'   => $test->title,
                    'outcome' => (string) ($results[$test->id]['outcome'] ?? Outcome::NOT_RUN->value),
                    'summary' => (string) ($results[$test->id]['summary'] ?? ''),
                ];
            }

            $sections[] = ['name' => $name, 'rows' => $rows];
        }

        $counts = (array) ($row['summary']['counts'] ?? []);

        return $this->guard->commit($this->page('reports/show', (string) $row['name'], [
            'report'   => [
                'id'        => $id,
                'name'      => (string) $row['name'],
                'url'       => (string) ($row['url'] ?? ''),
                'kind'      => (string) $row['kind'],
                'tester'    => (string) $row['me'],
                'target'    => (string) ($row['summary']['target'] ?? ''),
                'published' => (string) $row['published_at'],
                'version'   => substr((string) ($row['summary']['suite_version'] ?? ''), 0, 7),
            ],
            'counts'   => array_map('intval', $counts),
            'sections' => $sections,
            'owner'    => $visitor->user !== null && $visitor->user->id === (int) $row['tester_id'],
            'csrf'     => $visitor->session()->csrf,
            'wide'     => true,
        ], visitor: $visitor), $visitor);
    }

    /** @param array<string, string> $params */
    public function publish(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->visitor($request);
        $this->guard->requireCsrf($visitor, $request);

        $id  = self::param($params, 'run', '/^[0-9A-Z]{26}$/');
        $run = $this->runs->findByPublicId($id) ?? throw HttpException::notFound();

        if ($visitor->user === null || $visitor->user->id !== $run->testerId) {
            throw HttpException::forbidden('Only the tester who ran these tests can publish them.');
        }

        if ($run->kind === Catalog::SERVER && $run->status !== Run::COMPLETE) {
            throw HttpException::badRequest('Finish the run before publishing it.');
        }

        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $request->post('name', '')));
        $url  = trim((string) $request->post('url', ''));

        if ($name === '' || mb_strlen($name) > 100) {
            throw HttpException::badRequest('Give the implementation a name of up to 100 characters.');
        }

        if ($url !== '' && (preg_match('#^https?://#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false)) {
            throw HttpException::badRequest('The project URL must be an http or https URL.');
        }

        $implementation = $this->reports->publish($visitor->user->id, $name, $url === '' ? null : mb_substr($url, 0, 1000), $run, $this->results->forRun($run->id));

        return Response::seeOther('/implementations/' . $implementation);
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->visitor($request);
        $this->guard->requireCsrf($visitor, $request);

        if ($visitor->user === null) {
            throw HttpException::forbidden();
        }

        $this->reports->delete($visitor->user->id, (int) self::param($params, 'id', '/^[1-9]\d{0,9}$/'));

        return Response::seeOther('/implementations');
    }
}

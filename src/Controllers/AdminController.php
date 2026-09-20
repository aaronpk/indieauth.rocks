<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Checkup\Checkup;
use IndieAuthRocks\Checkup\StepState;
use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Identity\Visitor;
use IndieAuthRocks\Storage\StatsRepository;
use IndieAuthRocks\View\Bars;
use IndieAuthRocks\View\Template;

/**
 * What is happening across the site.
 *
 * Open to the profile URLs in ADMIN_USERS and nobody else; to anyone else
 * these pages do not exist. Every action is a GET: an admin reads other
 * people's runs through the ordinary pages, and nothing here can change a
 * test result.
 */
final class AdminController extends Controller
{
    private const PER_PAGE = 50;
    private const PERIODS  = [7, 30, 90, 365];

    public function __construct(
        Template $view,
        SiteConfig $config,
        Guard $guard,
        private readonly StatsRepository $stats,
    ) {
        parent::__construct($view, $config, $guard);
    }

    /** @param array<string, string> $params */
    public function overview(Request $request, array $params = []): Response
    {
        return $this->adminPage($request, function (Visitor $visitor) use ($request): Response {
            $days     = self::period($request);
            $checkups = $this->stats->checkups($days);

            return $this->page('admin/overview', 'Admin', [
                'menu_section'  => 'admin',
                'wide'          => true,
                'days'          => $days,
                'period_html'   => $this->view->partial('admin/_period', ['days' => $days, 'path' => '/admin']),
                'totals'        => $this->stats->totals(),
                'charts'        => [
                    'checkup' => Bars::svg($this->stats->series('checkup', $days)),
                    'server'  => Bars::svg($this->stats->series('server', $days)),
                    'client'  => Bars::svg($this->stats->series('client', $days)),
                ],
                'server_failures' => $this->named($this->stats->failingTests('server', $days, 8)),
                'client_failures' => $this->named($this->stats->failingTests('client', $days, 8)),
                'stops'           => self::stops($checkups),
                'checkup_counts'  => self::checkupCounts($checkups),
                'recent'          => $this->stats->recentRuns(20),
                'testers'         => $this->stats->testers(10),
            ], visitor: $visitor);
        });
    }

    /** @param array<string, string> $params */
    public function checkups(Request $request, array $params = []): Response
    {
        return $this->adminPage($request, function (Visitor $visitor) use ($request): Response {
            $days     = self::period($request);
            $page     = self::pageNumber($request);
            $checkups = $this->stats->checkups($days);
            $list     = $this->stats->runs(Checkup::KIND, $page, self::PER_PAGE, withState: true);

            return $this->page('admin/checkups', 'Checkups · Admin', [
                'menu_section' => 'admin-checkups',
                'wide'         => true,
                'days'         => $days,
                'period_html'  => $this->view->partial('admin/_period', ['days' => $days, 'path' => '/admin/checkups']),
                'chart'        => Bars::svg($this->stats->series('checkup', $days)),
                'counts'       => self::checkupCounts($checkups),
                'stops'        => self::stops($checkups),
                'targets'      => $this->stats->targets('checkup', $days, 10),
                'rows'         => array_map(self::checkupRow(...), $list['rows']),
                'pager'        => self::pager('/admin/checkups', $page, $list['total'], $days),
            ], visitor: $visitor);
        });
    }

    /** @param array<string, string> $params */
    public function serverTests(Request $request, array $params = []): Response
    {
        return $this->adminPage($request, fn (Visitor $visitor): Response => $this->suite($request, $visitor, Catalog::SERVER));
    }

    /** @param array<string, string> $params */
    public function clientTests(Request $request, array $params = []): Response
    {
        return $this->adminPage($request, fn (Visitor $visitor): Response => $this->suite($request, $visitor, Catalog::CLIENT));
    }

    private function suite(Request $request, Visitor $visitor, string $kind): Response
    {
        $days = self::period($request);
        $page = self::pageNumber($request);
        $path = '/admin/' . $kind . '-tests';
        $list = $this->stats->runs($kind, $page, self::PER_PAGE);

        return $this->page('admin/' . $kind . '-tests', ucfirst($kind) . ' tests · Admin', [
            'menu_section' => 'admin-' . $kind,
            'wide'         => true,
            'days'         => $days,
            'period_html'  => $this->view->partial('admin/_period', ['days' => $days, 'path' => $path]),
            'chart'        => Bars::svg($this->stats->series($kind, $days)),
            'totals'       => $this->stats->totals(),
            'failures'     => $this->named($this->stats->failingTests($kind, $days, 20)),
            'targets'      => $kind === Catalog::SERVER ? $this->stats->targets($kind, $days, 15) : [],
            'rows'         => array_map(self::runRow(...), $list['rows']),
            'pager'        => self::pager($path, $page, $list['total'], $days),
        ], visitor: $visitor);
    }

    /**
     * The visitor, if they may be here.
     *
     * Not signed in: the sign-in page, which comes back here. Signed in but
     * not an admin: 404, so the pages are not advertised by a 403.
     *
     * @param callable(Visitor): Response $render
     */
    private function adminPage(Request $request, callable $render): Response
    {
        $visitor = $this->guard->visitor($request);

        if (!$visitor->isAuthenticated()) {
            return Response::seeOther('/sign-in?next=' . rawurlencode($request->path));
        }

        if (!$this->config->isAdmin($visitor->user()->me)) {
            throw HttpException::notFound();
        }

        return $render($visitor)->withNoStore();
    }

    /**
     * Test ids with the catalog's title beside them.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function named(array $rows): array
    {
        return array_map(static function (array $row): array {
            $test = Catalog::find((string) $row['test_id']);

            return $row + [
                'title'   => $test?->title ?? '',
                'section' => $test?->section ?? '',
            ];
        }, $rows);
    }

    /**
     * Where checkups stopped, most common first.
     *
     * Read from the stored narrative rather than from SQL: the steps are the
     * same ones the person was shown, so the answer is in their words.
     *
     * @param  list<array<string, mixed>> $checkups
     * @return list<array{step: string, title: string, count: int}>
     */
    private static function stops(array $checkups): array
    {
        $counts = [];
        $titles = [];

        foreach ($checkups as $row) {
            foreach (self::steps($row) as $step) {
                if (($step['state'] ?? '') !== StepState::Problem->value) {
                    continue;
                }

                $id          = (string) ($step['id'] ?? 'unknown');
                $counts[$id] = ($counts[$id] ?? 0) + 1;
                $titles[$id] = (string) ($step['title'] ?? $id);

                break;
            }
        }

        arsort($counts);

        $stops = [];
        foreach ($counts as $id => $count) {
            $stops[] = ['step' => $id, 'title' => $titles[$id] ?? $id, 'count' => $count];
        }

        return $stops;
    }

    /**
     * @param  list<array<string, mixed>> $checkups
     * @return array<string, int>
     */
    private static function checkupCounts(array $checkups): array
    {
        $counts = ['all' => count($checkups), 'worked' => 0, 'stopped' => 0, 'away' => 0, 'unfinished' => 0];

        foreach ($checkups as $row) {
            $steps   = self::steps($row);
            $stopped = false;

            foreach ($steps as $step) {
                if (($step['state'] ?? '') === StepState::Problem->value) {
                    $stopped = true;

                    break;
                }
            }

            $state = json_decode((string) ($row['state_json'] ?? ''), true);
            $phase = is_array($state) ? (string) ($state['phase'] ?? '') : '';

            match (true) {
                $stopped                     => $counts['stopped']++,
                $phase === Checkup::FINISHED => $counts['worked']++,
                $phase === Checkup::SENT     => $counts['away']++,
                default                      => $counts['unfinished']++,
            };
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private static function steps(array $row): array
    {
        $state = json_decode((string) ($row['state_json'] ?? ''), true);
        $steps = is_array($state) && is_array($state['steps'] ?? null) ? $state['steps'] : [];

        return array_values(array_filter($steps, 'is_array'));
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function checkupRow(array $row): array
    {
        $state   = json_decode((string) ($row['state_json'] ?? ''), true);
        $state   = is_array($state) ? $state : [];
        $stopped = null;

        foreach (self::steps($row) as $step) {
            if (($step['state'] ?? '') === StepState::Problem->value) {
                $stopped = (string) ($step['title'] ?? '');

                break;
            }
        }

        return [
            'id'      => (string) $row['public_id'],
            'target'  => (string) ($row['target_url'] ?? ''),
            'when'    => (string) $row['created_at'],
            'outcome' => match (true) {
                $stopped !== null                                       => 'Stopped at ' . $stopped,
                ($state['phase'] ?? '') === Checkup::FINISHED           => 'Worked',
                ($state['phase'] ?? '') === Checkup::SENT               => 'Never came back',
                default                                                 => 'Not finished',
            },
            'me'      => is_string($state['me'] ?? null) ? $state['me'] : '',
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function runRow(array $row): array
    {
        return [
            'id'        => (string) $row['public_id'],
            'target'    => (string) ($row['target_url'] ?? ''),
            'tester'    => (string) ($row['tester'] ?? ''),
            'status'    => (string) $row['status'],
            'mode'      => (string) $row['mode'],
            'when'      => (string) $row['created_at'],
            'graded'    => (int) ($row['graded'] ?? 0),
            'fails'     => (int) ($row['fails'] ?? 0),
            'last'      => (string) ($row['last_result_at'] ?? ''),
            'finished'  => $row['finished_at'] === null ? '' : (string) $row['finished_at'],
        ];
    }

    /** @return array<string, mixed> */
    private static function pager(string $path, int $page, int $total, int $days): array
    {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $link  = static fn (int $to): string => $path . '?' . http_build_query(['days' => $days, 'page' => $to]);

        return [
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
            'newer' => $page > 1 ? $link($page - 1) : null,
            'older' => $page < $pages ? $link($page + 1) : null,
        ];
    }

    /** The chart period from ?days=, one of the offered values. */
    private static function period(Request $request): int
    {
        $days = (int) $request->query('days', '30');

        return in_array($days, self::PERIODS, true) ? $days : 30;
    }

    private static function pageNumber(Request $request): int
    {
        return max(1, (int) $request->query('page', '1'));
    }
}

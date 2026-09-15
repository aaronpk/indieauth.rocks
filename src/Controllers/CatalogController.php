<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\TestDefinition;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;

/** The public list of tests and a page per test. */
final class CatalogController extends Controller
{
    /** @param array<string, string> $params */
    public function serverTests(Request $request, array $params = []): Response
    {
        return $this->list($request, Catalog::SERVER, 'Server tests',
            'These tests check an IndieAuth server: its discovery, authorization and token endpoints. This site acts as the client.');
    }

    /** @param array<string, string> $params */
    public function clientTests(Request $request, array $params = []): Response
    {
        return $this->list($request, Catalog::CLIENT, 'Client tests',
            'These tests check an IndieAuth client. This site acts as the server, with a profile URL for each test that behaves in one specific way.');
    }

    /** @param array<string, string> $params */
    public function test(Request $request, array $params = []): Response
    {
        $test = Catalog::find(strtoupper($params['id'] ?? '')) ?? throw HttpException::notFound();

        $dependencies = array_map(
            static fn (string $id): array => ['id' => $id, 'title' => (string) Catalog::find($id)?->title],
            $test->dependsOn,
        );

        return $this->page('catalog/test', $test->id . ': ' . $test->title, [
            'test'         => $test->toArray(),
            'dependencies' => $dependencies,
            'list_url'     => $test->suite === Catalog::SERVER ? '/server-tests' : '/client-tests',
            'list_label'   => $test->suite === Catalog::SERVER ? 'Server tests' : 'Client tests',
        ], visitor: $this->guard->visitor($request));
    }

    private function list(Request $request, string $suite, string $title, string $intro): Response
    {
        // A run can be started from the list, which needs a session for the form's CSRF token.
        $visitor  = $this->guard->begin($request);
        $sections = [];
        foreach (Catalog::sections($suite) as $name => $tests) {
            $sections[] = [
                'name'  => $name,
                'tests' => array_map(static fn (TestDefinition $t): array => $t->toArray(), $tests),
            ];
        }

        return $this->guard->commit($this->page('catalog/list', $title, [
            'heading'   => $title,
            'intro'     => $intro,
            'suite'     => $suite,
            'signed_in' => $visitor->isAuthenticated(),
            'csrf'      => $visitor->session()->csrf,
            'sections'  => $sections,
            'count'     => count(Catalog::suite($suite)),
            'wide'      => true,
        ], visitor: $visitor), $visitor);
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\View\Template;

final class AccountController extends Controller
{
    public function __construct(
        Template $view,
        SiteConfig $config,
        Guard $guard,
        private readonly RunRepository $runs,
    ) {
        parent::__construct($view, $config, $guard);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->visitor($request);

        if (!$visitor->isAuthenticated()) {
            return Response::redirect('/');
        }

        return $this->page('account', 'Your test runs', [
            'menu_section' => 'account',
            'me'   => $visitor->user()->me,
            'runs' => array_map(static fn (array $r): array => [
                'public_id' => (string) $r['public_id'],
                'kind'      => (string) $r['kind'],
                'target'    => (string) ($r['target_url'] ?? ''),
                'status'    => (string) $r['status'],
                'created'   => (string) $r['created_at'],
            ], $this->runs->forTester($visitor->user()->id)),
            'wide' => true,
        ], visitor: $visitor);
    }

    /** @param array<string, string> $params */
    public function logout(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->visitor($request);

        if ($visitor->session !== null) {
            $this->guard->requireCsrf($visitor, $request);
        }

        return $this->guard->commit(Response::seeOther('/'), $this->guard->logOut($visitor));
    }
}

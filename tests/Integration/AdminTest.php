<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Integration;

use IndieAuthRocks\Checkup\Checkup;
use IndieAuthRocks\Identity\Session;
use IndieAuthRocks\Identity\Sessions;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\Storage\TesterRepository;
use IndieAuthRocks\Tests\Support\AppTestCase;

/** The admin pages: read everything, change nothing. */
final class AdminTest extends AppTestCase
{
    private const PAGES = ['admin', 'admin/checkups', 'admin/server-tests', 'admin/client-tests'];
    private const ADMIN = 'https://admin.example/';
    private const OTHER = 'https://someone.example/';

    /** Sign in as this profile URL, the way the rest of the suite does. */
    private function signIn(string $me): int
    {
        $tester  = $this->container->get(TesterRepository::class)->signIn($me);
        $session = $this->container->get(Sessions::class)->promote(Session::createAnonymous(), (string) $tester->id, ['indieauth']);

        $this->cookies[Sessions::COOKIE] = $session->id;

        return $tester->id;
    }

    public function test_the_pages_exist_only_for_admins(): void
    {
        $this->adminIs(self::ADMIN);

        // Nobody signed in: the sign-in page, which comes back here.
        foreach (self::PAGES as $path) {
            $page = $this->request('GET', self::BASE . $path);
            $this->assertSame(303, $page->status, $path);
            $this->assertSame('/sign-in?next=%2F' . str_replace('/', '%2F', $path), $page->header('location'), $path);
        }

        // Signed in, but not an admin: these pages do not exist.
        $this->signIn(self::OTHER);
        foreach (self::PAGES as $path) {
            $this->assertSame(404, $this->request('GET', self::BASE . $path)->status, $path);
        }

        $this->signIn(self::ADMIN);
        foreach (self::PAGES as $path) {
            $page = $this->request('GET', self::BASE . $path);
            $this->assertSame(200, $page->status, $path);
            $this->assertStringContainsString('no-store', (string) $page->header('cache-control'), $path);
        }
    }

    public function test_the_menu_offers_admin_only_to_admins(): void
    {
        $this->adminIs(self::ADMIN);

        $this->signIn(self::OTHER);
        $this->assertStringNotContainsString('>Overview<', $this->request('GET', self::BASE . 'account')->body);

        $this->signIn(self::ADMIN);
        $this->assertStringContainsString('>Overview<', $this->request('GET', self::BASE . 'account')->body);
        $this->assertStringContainsString(
            'href="' . self::BASE . 'admin" aria-current="page"',
            $this->request('GET', self::BASE . 'admin')->body,
        );
    }

    public function test_the_overview_counts_what_has_happened(): void
    {
        $this->adminIs(self::ADMIN);
        $tester = $this->signIn(self::ADMIN);
        $runs   = $this->container->get(RunRepository::class);

        $runs->create('server', 'https://tested.example/', 'browser', [], [], $tester, 30);
        $runs->create('client', null, 'browser', [], [], $tester, 30);
        $runs->create(Checkup::KIND, 'https://checked.example/', 'browser', ['wants_token' => false], [
            'phase' => Checkup::FINISHED,
            'steps' => [
                ['id' => 'home-page', 'title' => 'Fetching your home page', 'state' => 'ok', 'headline' => ''],
                ['id' => 'server-link', 'title' => 'Finding which server handles your login', 'state' => 'problem', 'headline' => ''],
            ],
        ], null, 7);

        $page = $this->request('GET', self::BASE . 'admin');

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('https://tested.example/', $page->body, 'the server run is listed');
        $this->assertStringContainsString('https://checked.example/', $page->body, 'the checkup is listed');
        $this->assertStringContainsString('Finding which server handles your login', $page->body, 'and where it stopped');
        $this->assertStringContainsString('<svg class="bars"', $page->body);
    }

    public function test_the_checkup_page_says_how_checkups_ended(): void
    {
        $this->adminIs(self::ADMIN);
        $this->signIn(self::ADMIN);
        $runs = $this->container->get(RunRepository::class);

        $runs->create(Checkup::KIND, 'https://worked.example/', 'browser', [], [
            'phase' => Checkup::FINISHED,
            'steps' => [['id' => 'identity', 'title' => 'Checking the answer really is you', 'state' => 'ok', 'headline' => '']],
        ], null, 7);
        $runs->create(Checkup::KIND, 'https://away.example/', 'browser', [], [
            'phase' => Checkup::SENT,
            'steps' => [['id' => 'request', 'title' => 'Sending you to your server', 'state' => 'pending', 'headline' => '']],
        ], null, 7);

        $page = $this->request('GET', self::BASE . 'admin/checkups');

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Worked', $page->body);
        $this->assertStringContainsString('Never came back', $page->body);
        $this->assertStringContainsString('https://worked.example/', $page->body);
    }

    /** Nothing under /admin accepts a POST, which is how "read everything, change nothing" is enforced. */
    public function test_the_admin_area_has_no_post_routes(): void
    {
        foreach (\IndieAuthRocks\Bootstrap::mainRouter()->routes() as $route) {
            if (str_starts_with((string) $route['pattern'], '/admin')) {
                $this->assertSame([], array_diff($route['methods'], ['GET', 'HEAD']), (string) $route['pattern']);
            }
        }
    }

}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Checkup\Checkup;
use IndieAuthRocks\Checkup\Transcript;
use IndieAuthRocks\Checkup\Walkthrough;
use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Crypto\Random;
use IndieAuthRocks\Http\Cookie;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Identity\Guard;
use IndieAuthRocks\Identity\Visitor;
use IndieAuthRocks\Storage\ExchangeRepository;
use IndieAuthRocks\Storage\RateLimiter;
use IndieAuthRocks\Storage\RunRepository;
use IndieAuthRocks\View\Prose;
use IndieAuthRocks\View\Template;

/**
 * "Check your login": one honest IndieAuth login, narrated, for the person
 * whose login it is.
 *
 * Nobody signs in to use it, and using it never signs anyone in here — the
 * people it is for often cannot sign in anywhere, which is why they came.
 */
final class CheckupController extends Controller
{
    // Somebody sorting their site out checks, edits, and checks again, several
    // times in a sitting, so these are set well above that: they exist to stop
    // the tool being used to hammer somebody, not to ration ordinary use.
    private const IP_BUCKET     = 'checkup:ip';
    private const IP_LIMIT      = 60;
    private const IP_WINDOW     = 900;
    private const HOST_BUCKET   = 'checkup:host';
    private const HOST_LIMIT    = 30;
    private const HOST_WINDOW   = 3600;
    private const RETURN_BUCKET = 'checkup:back';
    private const RETURN_LIMIT  = 120;
    private const RETURN_WINDOW = 900;

    public function __construct(
        Template $view,
        SiteConfig $config,
        Guard $guard,
        private readonly Walkthrough $walkthrough,
        private readonly RunRepository $runs,
        private readonly ExchangeRepository $exchanges,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $config, $guard);
    }

    /** @param array<string, string> $params */
    public function form(Request $request, array $params = []): Response
    {
        return $this->renderForm($this->guard->begin($request));
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->visitor($request);
        $this->guard->requireCsrf($visitor, $request);

        $typed = trim((string) $request->post('me', ''));
        $error = self::unusable($typed);

        if ($error !== null) {
            return $this->renderForm($this->guard->begin($request), $error, $typed, 422);
        }

        // One submission fetches a stranger's site several times, so limit both
        // who is asking and who is being asked about.
        if ($this->limiter->hit(self::IP_BUCKET, $request->ip, self::IP_WINDOW) > self::IP_LIMIT) {
            throw new HttpException(429, 'Too many checks from your address. ' . $this->tryAgain(self::IP_BUCKET, $request->ip));
        }

        $host = strtolower((string) parse_url(str_contains($typed, '://') ? $typed : 'https://' . $typed, PHP_URL_HOST));

        if ($host !== '' && $this->limiter->hit(self::HOST_BUCKET, $host, self::HOST_WINDOW) > self::HOST_LIMIT) {
            throw new HttpException(429, 'That site has been checked many times in the last hour. ' . $this->tryAgain(self::HOST_BUCKET, $host));
        }

        $secret  = Random::token(24);
        $checkup = $this->walkthrough->begin($typed, $request->post('token') === '1', $secret);

        return $this->guard->commit(
            Response::seeOther('/check/' . $checkup->publicId())->withCookie($this->ownerCookie($checkup, $secret)),
            $this->guard->begin($request),
        );
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $checkup = $this->checkup($params);
        $visitor = $this->guard->begin($request);
        $owner   = $checkup->isOwner($request->cookie(self::cookieName($checkup)));

        // They left and never came back: say so rather than showing a step that
        // claims to still be in progress.
        if ($owner && $checkup->phase() === Checkup::SENT) {
            $this->walkthrough->abandon($checkup);
            $checkup = $this->checkup($params);
        }

        $steps = $checkup->steps();

        return $this->guard->commit(
            $this->page('checkup/show', 'Check your login', [
                'menu_section' => 'check',
                'checkup'  => [
                    'id'      => $checkup->publicId(),
                    'target'  => $checkup->profileUrl(),
                    'phase'   => $checkup->phase(),
                    'can_go'  => $owner && $checkup->canDepart(),
                    'is_owner' => $owner,
                ],
                'summary'  => self::summaryVars($checkup),
                'steps'    => $this->withTranscripts(Transcript::group($steps, $this->exchanges->forRun($checkup->run->id))),
                'csrf'     => $visitor->session()->csrf,
                'wide'     => false,
            ], visitor: $visitor)
                // A shared link should not turn up in search results.
                ->withHeader('X-Robots-Tag', 'noindex')
                ->withNoStore(),
            $visitor,
        );
    }

    /**
     * Leave for the person's own server.
     *
     * Owner-only: without that, anyone could start a checkup against any
     * address and send the link out as an open redirector.
     *
     * @param array<string, string> $params
     */
    public function go(Request $request, array $params = []): Response
    {
        $checkup = $this->checkup($params);
        $visitor = $this->guard->begin($request);

        if (!$checkup->isOwner($request->cookie(self::cookieName($checkup)))) {
            throw new HttpException(403, 'Only the browser that started this check can continue it. Start your own check instead.');
        }

        if (!$checkup->canDepart()) {
            return $this->guard->commit(Response::seeOther('/check/' . $checkup->publicId()), $visitor);
        }

        $departure = $this->walkthrough->depart($checkup, $visitor->session()->id);

        return $this->guard->commit(Response::seeOther($departure['url'])->withNoStore(), $visitor);
    }

    /**
     * The redirect URI. Never signs anybody in.
     *
     * @param array<string, string> $params
     */
    public function callback(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->begin($request);

        if ($this->limiter->hit(self::RETURN_BUCKET, $request->ip, self::RETURN_WINDOW) > self::RETURN_LIMIT) {
            throw new HttpException(429, 'Too many replies from your address. ' . $this->tryAgain(self::RETURN_BUCKET, $request->ip));
        }

        $state   = (string) $request->query('state', '');
        $pending = $this->walkthrough->pending($state, $visitor->session()->id);

        if ($pending === null) {
            return $this->renderForm(
                $visitor,
                'That reply could not be matched to a check. It may have expired, been used already, or been '
                    . 'started in a different browser. Starting again takes a moment.',
                status: 400,
            );
        }

        $run = $this->runs->find((int) $pending['run']);

        if ($run === null) {
            return $this->renderForm($visitor, 'That check is no longer here.', status: 404);
        }

        $checkup = new Checkup($run);
        $query   = array_map('strval', $request->query);

        $this->exchanges->recordInbound($run->id, 'callback', $request);
        $this->walkthrough->finish($checkup, $query, $pending);

        return $this->guard->commit(Response::seeOther('/check/' . $checkup->publicId()), $visitor);
    }

    /**
     * The client_id metadata document.
     *
     * Its own, not the one signing in uses: the consent screen has to say what
     * the person is actually doing, and a code issued for a checkup must never
     * arrive at a handler that creates a session.
     *
     * @param array<string, string> $params
     */
    public function clientDocument(Request $request, array $params = []): Response
    {
        return Cors::allow(Response::json([
            'client_id'     => $this->walkthrough->clientId(),
            'client_name'   => $this->config->siteName . ' — Check your login',
            'client_uri'    => $this->config->url('check'),
            'redirect_uris' => [$this->walkthrough->callbackUrl()],
        ]));
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params = []): Response
    {
        $visitor = $this->guard->visitor($request);
        $this->guard->requireCsrf($visitor, $request);

        $checkup = $this->checkup($params);

        if (!$checkup->isOwner($request->cookie(self::cookieName($checkup)))) {
            throw new HttpException(403, 'Only the browser that started this check can delete it.');
        }

        $this->runs->delete($checkup->run);

        return $this->guard->commit(
            Response::seeOther('/check')->withCookie(Cookie::expire(self::cookieName($checkup), $this->config->secureCookies())),
            $visitor,
        );
    }

    /**
     * Render each step's HTTP once, here, so the page template does not have to
     * unescape rows to pass them on.
     *
     * @param  list<array<string, mixed>> $steps
     * @return list<array<string, mixed>>
     */
    private function withTranscripts(array $steps): array
    {
        foreach ($steps as $i => $step) {
            $exchanges = is_array($step['exchanges'] ?? null) ? $step['exchanges'] : [];
            $steps[$i]['exchanges_html'] = $exchanges === []
                ? null
                : $this->view->partial('partials/exchanges', ['exchanges' => $exchanges]);
            $steps[$i]['exchange_count'] = count($exchanges);
            unset($steps[$i]['exchanges']);
        }

        return $steps;
    }

    /** @param array<string, string> $params */
    private function checkup(array $params): Checkup
    {
        $run     = $this->runs->findByPublicId(self::param($params, 'run', '/^[0-9A-Z]{26}$/'));
        $checkup = $run === null ? null : new Checkup($run);

        if ($checkup === null || !$checkup->isCheckup()) {
            throw new HttpException(404, 'There is no check here. It may have been deleted, or have expired.');
        }

        return $checkup;
    }

    /** @return array<string, mixed> */
    private static function summaryVars(Checkup $checkup): array
    {
        $summary = $checkup->summary();

        return [
            'state'    => $summary->state->value,
            'headline' => Prose::html($summary->headline)['html'],
            'fix'      => $summary->fix === null ? null : Prose::html($summary->fix)['html'],
            'notes'    => $summary->notesLine(),
        ];
    }

    private function renderForm(Visitor $visitor, ?string $error = null, string $me = '', int $status = 200): Response
    {
        return $this->guard->commit(
            $this->page('checkup/form', 'Check your login', [
                'menu_section' => 'check',
                'csrf'  => $visitor->session()->csrf,
                'error' => $error,
                'me'    => $me,
            ], $status, $visitor)->withNoStore(),
            $visitor,
        );
    }

    /** When the limit lifts, so nobody has to guess what "later" means. */
    private function tryAgain(string $bucket, string $key): string
    {
        $seconds = $this->limiter->retryAfter($bucket, $key);
        $minutes = (int) ceil($seconds / 60);

        return match (true) {
            $seconds <= 0 => 'Try again shortly.',
            $minutes <= 1 => 'Try again in a minute.',
            default       => sprintf('Try again in %d minutes.', $minutes),
        };
    }

    /** Things worth refusing before fetching anything at all. */
    private static function unusable(string $typed): ?string
    {
        if ($typed === '') {
            return 'Enter your website address.';
        }

        if (str_contains($typed, '@') && !str_contains($typed, '://')) {
            return sprintf('%s looks like an email address. IndieAuth signs you in with a website address, like https://example.com/.', $typed);
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $typed) === 1 && preg_match('#^https?://#i', $typed) !== 1) {
            return 'Only http and https addresses can be checked.';
        }

        if (preg_match('#^\S+\.\S+#', $typed) !== 1) {
            return sprintf('%s does not look like a website address. It should look like example.com.', $typed);
        }

        return null;
    }

    private function ownerCookie(Checkup $checkup, string $secret): Cookie
    {
        return new Cookie(
            name:    self::cookieName($checkup),
            value:   $secret,
            expires: time() + 7 * 86_400,
            secure:  $this->config->secureCookies(),
        );
    }

    private static function cookieName(Checkup $checkup): string
    {
        return 'iar_checkup_' . strtolower($checkup->publicId());
    }
}

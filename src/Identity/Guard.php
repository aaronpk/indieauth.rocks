<?php

declare(strict_types=1);

namespace IndieAuthRocks\Identity;

use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;
use IndieAuthRocks\Storage\TesterRepository;

/**
 * Resolves the current visitor and enforces CSRF. There is no middleware
 * layer, so this is the one place that knows how a request becomes an identity.
 */
final class Guard
{
    public const CSRF_FIELD  = '_csrf';
    public const CSRF_HEADER = 'x-csrf-token';

    public function __construct(
        private readonly Sessions $sessions,
        private readonly TesterRepository $testers,
    ) {
    }

    /** Resolve the visitor without creating a session. */
    public function visitor(Request $request): Visitor
    {
        $session = $this->sessions->load($request);

        if ($session === null) {
            return new Visitor();
        }

        $tester = null;
        if ($session->sub !== null && preg_match('/^[1-9][0-9]*$/', $session->sub) === 1) {
            $tester = $this->testers->find((int) $session->sub);
        }

        return new Visitor($session, $tester);
    }

    /** Resolve the visitor, creating a session if there is not one. */
    public function begin(Request $request): Visitor
    {
        $visitor = $this->visitor($request);

        if ($visitor->session !== null) {
            return $visitor;
        }

        $session = Session::createAnonymous();
        $this->sessions->save($session);

        return new Visitor($session);
    }

    /** Log a tester in, rotating the session identifier. */
    public function logIn(Visitor $visitor, Tester $tester): Visitor
    {
        $session = $visitor->session ?? Session::createAnonymous();

        return new Visitor($this->sessions->promote($session, (string) $tester->id, ['indieauth']), $tester);
    }

    public function logOut(Visitor $visitor): Visitor
    {
        if ($visitor->session !== null) {
            $this->sessions->destroy($visitor->session);
        }

        return new Visitor();
    }

    public function commit(Response $response, Visitor $visitor): Response
    {
        if ($visitor->session === null) {
            return $this->sessions->clearCookie($response);
        }

        $this->sessions->save($visitor->session);

        return $this->sessions->attach($response, $visitor->session);
    }

    /** @param array<string, mixed> $data */
    public function flash(Visitor $visitor, array $data): Visitor
    {
        if ($visitor->session === null) {
            return $visitor;
        }

        return new Visitor($visitor->session->withFlash($data), $visitor->user);
    }

    /** @return array<string, mixed> */
    public function takeFlash(Visitor $visitor): array
    {
        if ($visitor->session === null || $visitor->session->flash === []) {
            return [];
        }

        $flash = $visitor->session->flash;
        $this->sessions->save($visitor->session->withFlash([]));

        return $flash;
    }

    /** @throws HttpException 400 when the token is missing or wrong. */
    public function requireCsrf(Visitor $visitor, Request $request): void
    {
        $submitted = $request->post(self::CSRF_FIELD) ?? $request->header(self::CSRF_HEADER);
        $expected  = $visitor->session?->csrf;

        if ($expected === null || $submitted === null || !hash_equals($expected, $submitted)) {
            throw HttpException::badRequest(
                'This form is no longer valid, most likely because your session expired. Please try again.',
            );
        }
    }
}

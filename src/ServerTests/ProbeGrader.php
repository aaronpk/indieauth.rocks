<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Verdict;

/**
 * The browser checks S207–S222: deliberately wrong (or deliberately
 * cancelled) authorization requests, graded from where the browser ended up
 * or from what the person says they saw.
 */
final class ProbeGrader
{
    /** Requests the server must refuse because the redirect URI is not registered. */
    public const UNREGISTERED = ['S208', 'S210', 'S211', 'S212', 'S213', 'S214'];

    /** Requests the server should refuse for other reasons. */
    public const INVALID = ['S215', 'S216', 'S217', 'S218', 'S219', 'S221', 'S222'];

    public const ALL = ['S207', 'S208', 'S209', 'S210', 'S211', 'S212', 'S213', 'S214', 'S215', 'S216', 'S217', 'S218', 'S219', 'S220', 'S221', 'S222'];

    public static function question(string $testId, string $altHost): Question
    {
        return match (true) {
            in_array($testId, ['S207', 'S209'], true) => new Question($testId, 'Could you cancel the request?', [
                'error'     => 'No, the server showed an error for this request',
                'no_cancel' => 'No, there was no way to cancel',
                'other'     => 'Something else happened',
            ], hint: 'You only need to answer if cancelling did not bring you back here.'),
            in_array($testId, self::UNREGISTERED, true) => new Question($testId, 'What did your server show?', [
                'error'   => 'An error, with no way to continue to the app',
                'consent' => sprintf('An approval screen, and cancelling did not send me to %s', $altHost),
                'other'   => 'Something else',
            ], hint: 'Answer after you see an error page. If the server sends you to another page, the result is recorded automatically.'),
            $testId === 'S220' => new Question($testId, 'What did your server show?', [
                'error' => 'An error',
                'other' => 'Something else',
            ], hint: 'If your server shows an approval screen, approve it: this test checks whose profile URL comes back.'),
            default => new Question($testId, 'What did your server show?', [
                'error'   => 'An error',
                'consent' => 'An approval screen',
                'other'   => 'Something else',
            ], hint: 'If you see an approval screen, cancel it first.'),
        };
    }

    public static function instructions(string $testId): string
    {
        $test = Catalog::find($testId);

        $intro = match (true) {
            $testId === 'S207'                           => 'This is a normal request. Open it and cancel (deny) it on the approval screen.',
            $testId === 'S209'                           => 'This request uses a cross-origin redirect URI that the client has registered. Open it and cancel it on the approval screen.',
            $testId === 'S220'                           => 'This request asks for someone else\'s profile URL. If your server shows an approval screen, approve it.',
            default                                      => 'This request is deliberately wrong, and your server should refuse it. If you see an approval screen, cancel it.',
        };

        return $intro . ' ' . ($test?->description ?? '');
    }

    /** A verdict from where the browser landed, or null if the runner must do more (S220 with a code). */
    public static function fromLanding(string $testId, Landing $landing): ?Verdict
    {
        $code  = $landing->code();
        $error = $landing->error();

        if ($testId === 'S207') {
            return match (true) {
                $error === 'access_denied' => Verdict::pass('S207', 'Cancelling returned error=access_denied.'),
                $code !== null             => Verdict::error('S207', 'The request was approved rather than cancelled. Run this step again and cancel it.'),
                default                    => Verdict::fail('S207', sprintf('Cancelling returned error=%s instead of access_denied.', $error ?? '(none)')),
            };
        }

        if ($testId === 'S209') {
            return match (true) {
                $landing->role === Landing::CROSS && ($error !== null || $code !== null) => Verdict::pass('S209', 'The registered cross-origin redirect URI was used.'),
                default => Verdict::fail('S209', 'The browser did not arrive at the registered redirect URI.'),
            };
        }

        if (in_array($testId, self::UNREGISTERED, true)) {
            if ($landing->isUnregistered()) {
                return match (true) {
                    $code !== null             => Verdict::fail($testId, 'Your server sent an authorization code to an unregistered redirect URI.'),
                    $error === 'access_denied' => Verdict::warning($testId, 'Your server showed an approval screen for an unregistered redirect URI and sent the browser there when you cancelled. It should show an error instead.'),
                    default                    => Verdict::fail($testId, sprintf('Your server redirected an error (%s) to an unregistered redirect URI, making it an open redirector.', $error ?? 'none')),
                };
            }

            // Only S213 can land on the callback: the server picked the registered duplicate.
            return $code !== null
                ? Verdict::warning($testId, 'Your server accepted a request with two redirect_uri values and used one of them.')
                : Verdict::warning($testId, 'Your server redirected back to the registered redirect URI instead of refusing the request.');
        }

        if (in_array($testId, self::INVALID, true)) {
            return match (true) {
                $testId === 'S217' && ($code !== null || isset($landing->query['access_token'])) => Verdict::fail('S217', 'Your server issued a code or token for response_type=token.'),
                $code !== null             => Verdict::unmet($testId, 'Your server accepted the request and issued a code.'),
                $error === 'access_denied' => Verdict::unmet($testId, 'Your server showed an approval screen for this request instead of refusing it.'),
                default                    => Verdict::pass($testId, sprintf('Your server refused the request with error=%s.', $error ?? '(none)')),
            };
        }

        if ($testId === 'S220') {
            return $code === null
                ? Verdict::pass('S220', sprintf('Your server refused the request (error=%s).', $error ?? 'none'))
                : null;
        }

        return null;
    }

    public static function fromAnswer(string $testId, string $answer): Verdict
    {
        if ($answer === 'other') {
            return Verdict::skipped($testId, 'Inconclusive: you reported something other than the expected outcomes.');
        }

        if (in_array($testId, ['S207', 'S209'], true)) {
            return Verdict::fail($testId, $answer === 'error'
                ? 'Your server showed an error for a request it should have allowed.'
                : 'There was no way to cancel the request.');
        }

        if (in_array($testId, self::UNREGISTERED, true)) {
            return $answer === 'error'
                ? Verdict::pass($testId, 'Your server showed an error and did not redirect.')
                : Verdict::warning($testId, 'Your server showed an approval screen for an unregistered redirect URI instead of an error.');
        }

        if ($testId === 'S220') {
            return Verdict::pass('S220', 'Your server refused the request.');
        }

        return $answer === 'error'
            ? Verdict::pass($testId, 'Your server showed an error.')
            : Verdict::unmet($testId, 'Your server showed an approval screen for this request instead of refusing it.');
    }
}

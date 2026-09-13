<?php

declare(strict_types=1);

namespace IndieAuthRocks\Http;

/**
 * Sanitises a caller-supplied return path.
 *
 * `?next=` values reach us from links and form fields, so they are attacker
 * controlled. Anything that could leave this origin is discarded rather than
 * corrected — a login page that can be made to redirect off-site is a
 * ready-made phishing step.
 */
final class LocalUrl
{
    public static function safePath(?string $candidate, string $fallback = '/'): string
    {
        if ($candidate === null || $candidate === '') {
            return $fallback;
        }

        // Control characters, including the newline and tab that browsers strip
        // before parsing, which is how "/\tevil.com" becomes a scheme-relative URL.
        if (preg_match('/[\x00-\x1f\x7f]/', $candidate) === 1) {
            return $fallback;
        }

        // Must be an absolute path on this origin.
        if (!str_starts_with($candidate, '/')) {
            return $fallback;
        }

        // "//host" and "/\host" are scheme-relative: they leave the origin.
        if (str_starts_with($candidate, '//') || str_starts_with($candidate, '/\\')) {
            return $fallback;
        }

        // A backslash anywhere is not useful in a path here and is normalised to
        // a slash by some browsers.
        if (str_contains($candidate, '\\')) {
            return $fallback;
        }

        // Reject anything parse_url reads as carrying a scheme or authority.
        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme'], $parts['host'], $parts['port'], $parts['user'])) {
            return $fallback;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            return $fallback;
        }

        return $candidate;
    }
}

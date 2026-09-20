<?php

declare(strict_types=1);

namespace IndieAuthRocks\View;

/**
 * A sentence from a checkup, ready to render.
 *
 * Addresses are set in code, as they are in the facts beside them: a bare URL
 * in running text reads as a link that someone forgot to make, and these
 * sentences are full of addresses that are quotations, not destinations.
 *
 * The text is escaped first and only then marked up, so whatever a stranger's
 * website returned stays text. Any link whose name appears in the sentence is
 * applied to that name by InlineLinks.
 */
final class Prose
{
    /** http(s) up to whitespace or a tag, minus the punctuation a sentence ends with. */
    private const URL = '#(https?://[^\s<]*[^\s<.,;:!?\'")\]])#i';

    /**
     * @param  list<array{text: string, url: string}> $links
     * @return array{html: Raw, rest: list<array{text: string, url: string}>}
     */
    public static function html(string $text, array $links = []): array
    {
        $linked = InlineLinks::in($text, $links);

        return ['html' => new Raw(self::markUpUrls($linked['html']->html)), 'rest' => $linked['rest']];
    }

    /**
     * Wrap addresses in <code>, leaving tags — and the text of any link already
     * made — alone.
     */
    private static function markUpUrls(string $html): string
    {
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $depth = 0;
        $out   = '';

        foreach ($parts as $part) {
            if (str_starts_with($part, '<')) {
                $depth += match (true) {
                    (bool) preg_match('#^<a\b#i', $part)  => 1,
                    (bool) preg_match('#^</a\s*>#i', $part) => -1,
                    default                                 => 0,
                };

                $out .= $part;

                continue;
            }

            $out .= $depth > 0 ? $part : (string) preg_replace(self::URL, '<code>$1</code>', $part);
        }

        return $out;
    }
}

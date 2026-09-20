<?php

declare(strict_types=1);

namespace IndieAuthRocks\View;

/**
 * Turns a named thing in a sentence into a link to it.
 *
 * Steps carry plain text and a separate list of links, because much of what
 * they say quotes whatever a stranger's website returned and must never be
 * treated as markup. This keeps that property: the sentence is escaped first,
 * and only then is the escaped form of a link's own text — our text, not the
 * website's — replaced with an anchor.
 */
final class InlineLinks
{
    /**
     * @param  list<array{text: string, url: string}> $links
     * @return array{html: Raw, rest: list<array{text: string, url: string}>} The
     *         sentence, and the links that did not appear in it and so still
     *         need showing separately.
     */
    public static function in(string $text, array $links): array
    {
        $html = self::escape($text);
        $rest = [];

        foreach ($links as $link) {
            $needle = self::escape($link['text']);
            $at     = $needle === '' ? false : strpos($html, $needle);

            if ($at === false || !self::isWebUrl($link['url'])) {
                $rest[] = $link;

                continue;
            }

            $anchor = '<a href="' . self::escape($link['url']) . '">' . $needle . '</a>';
            $html   = substr_replace($html, $anchor, $at, strlen($needle));
        }

        return ['html' => new Raw($html), 'rest' => $rest];
    }

    private static function isWebUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

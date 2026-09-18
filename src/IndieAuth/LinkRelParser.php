<?php

declare(strict_types=1);

namespace IndieAuthRocks\IndieAuth;

use DOMDocument;
use DOMElement;
use IndieAuthRocks\Fetch\FetchResult;
use IndieAuthRocks\Fetch\UrlResolver;

/**
 * Finds rel values in HTTP Link headers and HTML <link> elements, resolved to
 * absolute URLs. Link headers come first, matching discovery order in the spec.
 */
final class LinkRelParser
{
    /** @return list<string> */
    public static function find(FetchResult $page, string $rel, bool $headOnly = true): array
    {
        $found = [];

        foreach ($page->headerValues('link') as $header) {
            foreach (self::fromLinkHeader($header, $rel) as $href) {
                $url = UrlResolver::resolve($page->url, $href);
                if ($url !== null) {
                    $found[] = $url;
                }
            }
        }

        $type = $page->contentType();
        if ($type === 'text/html' || $type === 'application/xhtml+xml' || $type === '') {
            foreach (self::fromHtml($page->body, $page->url, $rel, $headOnly) as $url) {
                $found[] = $url;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Parse one Link header value (RFC 8288), returning hrefs whose rel list
     * contains $rel.
     *
     * @return list<string>
     */
    public static function fromLinkHeader(string $header, string $rel): array
    {
        $hrefs = [];

        if (preg_match_all('/<([^>]*)>((?:\s*;\s*[^;,]+)*)/', $header, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $match) {
            if (preg_match('/;\s*rel\s*=\s*(?:"([^"]*)"|([^\s;,]+))/i', $match[2], $relMatch) !== 1) {
                continue;
            }

            $rels = preg_split('/\s+/', strtolower(trim($relMatch[1] !== '' ? $relMatch[1] : ($relMatch[2] ?? '')))) ?: [];

            if (in_array(strtolower($rel), $rels, true)) {
                $hrefs[] = $match[1];
            }
        }

        return $hrefs;
    }

    /**
     * Only a <link> inside <head> counts. A link in the body is where injected
     * content lands — a comment, a bio, a reflected search term — and for
     * indieauth-metadata that link decides which server speaks for the site.
     * The head, like the HTTP headers, is the part of a page only its
     * operator writes.
     *
     * @param bool $headOnly False to read body links too, for saying where a
     *                       misplaced tag is.
     *
     * @return list<string>
     */
    public static function fromHtml(string $html, string $baseUrl, string $rel, bool $headOnly = true): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The XML declaration hint makes libxml read the document as UTF-8.
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $base = $baseUrl;
        foreach ($document->getElementsByTagName('base') as $element) {
            if ($element instanceof DOMElement && $element->hasAttribute('href')) {
                $base = UrlResolver::resolve($baseUrl, $element->getAttribute('href')) ?? $baseUrl;
                break;
            }
        }

        $found = [];
        foreach ($document->getElementsByTagName('link') as $element) {
            if (!$element instanceof DOMElement || !$element->hasAttribute('href')) {
                continue;
            }

            if ($headOnly && !self::inHead($element)) {
                continue;
            }

            $rels = preg_split('/\s+/', strtolower(trim($element->getAttribute('rel')))) ?: [];

            if (in_array(strtolower($rel), $rels, true)) {
                $url = UrlResolver::resolve($base, $element->getAttribute('href'));
                if ($url !== null) {
                    $found[] = $url;
                }
            }
        }

        return $found;
    }

    /**
     * Whether an element sits under <head>. libxml leaves a <link> where it
     * found it rather than hoisting it, so a body link really is in the body.
     */
    private static function inHead(DOMElement $element): bool
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (strtolower($node->tagName) === 'head') {
                return true;
            }
        }

        return false;
    }
}

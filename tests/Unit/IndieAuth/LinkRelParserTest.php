<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\IndieAuth;

use IndieAuthRocks\IndieAuth\LinkRelParser;
use IndieAuthRocks\Fetch\FetchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinkRelParser::class)]
final class LinkRelParserTest extends TestCase
{
    public function test_reads_link_headers(): void
    {
        $header = '<https://a.example/one>; rel="indieauth-metadata", </two>; rel="me authorization_endpoint", <https://x/>; rel=stylesheet';

        $this->assertSame(['https://a.example/one'], LinkRelParser::fromLinkHeader($header, 'indieauth-metadata'));
        $this->assertSame(['/two'], LinkRelParser::fromLinkHeader($header, 'authorization_endpoint'));
        $this->assertSame([], LinkRelParser::fromLinkHeader($header, 'micropub'));
    }

    public function test_reads_html_link_elements_and_resolves_them(): void
    {
        $html = <<<'HTML'
            <!doctype html><html><head>
            <link rel="stylesheet" href="/style.css">
            <LINK REL="me indieauth-metadata" HREF="/meta">
            <a rel="indieauth-metadata" href="/ignored-anchor">no</a>
            </head><body></body></html>
            HTML;

        $page = new FetchResult('https://example.com/', 200, $html, ['content-type' => ['text/html']]);

        $this->assertSame(['https://example.com/meta'], LinkRelParser::find($page, 'indieauth-metadata'));
    }

    public function test_honours_base_href(): void
    {
        $html = '<html><head><base href="https://cdn.example/sub/"><link rel="indieauth-metadata" href="meta"></head></html>';

        $this->assertSame(['https://cdn.example/sub/meta'], LinkRelParser::fromHtml($html, 'https://example.com/', 'indieauth-metadata'));
    }

    public function test_headers_come_before_html(): void
    {
        $page = new FetchResult(
            'https://example.com/',
            200,
            '<link rel="indieauth-metadata" href="https://html.example/">',
            ['content-type' => ['text/html'], 'link' => ['<https://header.example/>; rel="indieauth-metadata"']],
        );

        $this->assertSame(['https://header.example/', 'https://html.example/'], LinkRelParser::find($page, 'indieauth-metadata'));
    }

    public function test_ignores_html_in_non_html_responses(): void
    {
        $page = new FetchResult('https://example.com/', 200, '<link rel="indieauth-metadata" href="/x">', ['content-type' => ['application/json']]);

        $this->assertSame([], LinkRelParser::find($page, 'indieauth-metadata'));
    }
}

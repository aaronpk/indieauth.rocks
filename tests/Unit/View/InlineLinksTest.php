<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\View;

use IndieAuthRocks\View\InlineLinks;
use PHPUnit\Framework\TestCase;

final class InlineLinksTest extends TestCase
{
    public function test_a_named_thing_becomes_the_link(): void
    {
        $result = InlineLinks::in('Move your site to IndieKey, which replaces it.', [
            ['text' => 'IndieKey', 'url' => 'https://indiekey.id/migrate'],
        ]);

        $this->assertSame(
            'Move your site to <a href="https://indiekey.id/migrate">IndieKey</a>, which replaces it.',
            $result['html']->html,
        );
        $this->assertSame([], $result['rest'], 'nothing left to show separately');
    }

    public function test_a_link_that_is_not_named_is_left_for_the_page_to_show(): void
    {
        $link   = ['text' => 'IndieAuth servers and plugins', 'url' => 'https://indieweb.org/IndieAuth'];
        $result = InlineLinks::in('Turn it on in your site software.', [$link]);

        $this->assertSame('Turn it on in your site software.', $result['html']->html);
        $this->assertSame([$link], $result['rest']);
    }

    /** The sentence may quote whatever a stranger's website returned. */
    public function test_the_sentence_is_escaped_before_anything_is_linked(): void
    {
        $result = InlineLinks::in('Move the <link> tag into your <head>, then try IndieKey.', [
            ['text' => 'IndieKey', 'url' => 'https://indiekey.id/migrate'],
        ]);

        $this->assertSame(
            'Move the &lt;link&gt; tag into your &lt;head&gt;, then try '
                . '<a href="https://indiekey.id/migrate">IndieKey</a>.',
            $result['html']->html,
        );
    }

    public function test_only_web_addresses_are_linked(): void
    {
        $link   = ['text' => 'this', 'url' => 'javascript:alert(1)'];
        $result = InlineLinks::in('Try this.', [$link]);

        $this->assertSame('Try this.', $result['html']->html);
        $this->assertSame([$link], $result['rest']);
    }

    public function test_only_the_first_mention_is_linked(): void
    {
        $result = InlineLinks::in('IndieKey replaces it. IndieKey uses passkeys.', [
            ['text' => 'IndieKey', 'url' => 'https://indiekey.id/migrate'],
        ]);

        $this->assertSame(1, substr_count($result['html']->html, '<a href'));
    }
}

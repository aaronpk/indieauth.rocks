<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\View;

use IndieAuthRocks\View\Prose;
use PHPUnit\Framework\TestCase;

final class ProseTest extends TestCase
{
    public function test_an_address_is_set_in_code(): void
    {
        $this->assertSame(
            'Fetched <code>https://example.com/</code> — HTTP 200.',
            Prose::html('Fetched https://example.com/ — HTTP 200.')['html']->html,
        );
    }

    /** The sentence's own punctuation is not part of the address. */
    public function test_trailing_punctuation_is_left_outside(): void
    {
        $this->assertSame(
            'It answered at <code>https://example.com/token</code>, eventually.',
            Prose::html('It answered at https://example.com/token, eventually.')['html']->html,
        );

        $this->assertSame(
            'Try <code>https://example.com/</code>.',
            Prose::html('Try https://example.com/.')['html']->html,
        );
    }

    public function test_several_addresses_are_each_marked_up(): void
    {
        $result = Prose::html('Your server said https://www.example.com/ but you entered https://example.com/.');

        $this->assertSame(2, substr_count($result['html']->html, '<code>'));
    }

    /** What a stranger's website returned is still only text. */
    public function test_the_sentence_is_escaped_first(): void
    {
        $this->assertSame(
            'Move the &lt;link&gt; tag, then refetch <code>https://example.com/</code>.',
            Prose::html('Move the <link> tag, then refetch https://example.com/.')['html']->html,
        );

        // A closing tag in the text cannot end the code element around an address.
        $this->assertStringNotContainsString(
            '</code>evil',
            Prose::html('See https://example.com/</code>evil')['html']->html,
        );
    }

    public function test_a_linked_name_is_not_also_marked_up(): void
    {
        $result = Prose::html('Move your site to IndieKey, which replaces it.', [
            ['text' => 'IndieKey', 'url' => 'https://indiekey.id/migrate'],
        ]);

        $this->assertSame(
            'Move your site to <a href="https://indiekey.id/migrate">IndieKey</a>, which replaces it.',
            $result['html']->html,
        );
    }

    /** An address inside a link is already marked as a destination. */
    public function test_an_address_used_as_link_text_is_left_alone(): void
    {
        $result = Prose::html('See https://indiekey.id/ for more.', [
            ['text' => 'https://indiekey.id/', 'url' => 'https://indiekey.id/'],
        ]);

        $this->assertSame(
            'See <a href="https://indiekey.id/">https://indiekey.id/</a> for more.',
            $result['html']->html,
        );
    }

    public function test_a_link_that_is_not_named_is_still_handed_back(): void
    {
        $link   = ['text' => 'indieweb.org', 'url' => 'https://indieweb.org/IndieAuth'];
        $result = Prose::html('Nothing names it here.', [$link]);

        $this->assertSame([$link], $result['rest']);
    }
}

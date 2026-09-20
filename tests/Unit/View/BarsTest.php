<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\View;

use IndieAuthRocks\View\Bars;
use PHPUnit\Framework\TestCase;

final class BarsTest extends TestCase
{
    /** The CSP allows no inline style and no script, so a chart is markup or nothing. */
    public function test_a_chart_is_plain_markup(): void
    {
        $svg = Bars::svg(['2026-09-18' => 2, '2026-09-19' => 0, '2026-09-20' => 5])->html;

        $this->assertStringContainsString('<svg class="bars"', $svg);
        $this->assertStringNotContainsString('style=', $svg);
        $this->assertStringNotContainsString('<script', $svg);
    }

    /** Every bar says its own value, so the chart reads without a pointer. */
    public function test_every_bar_carries_its_value(): void
    {
        $svg = Bars::svg(['2026-09-19' => 2, '2026-09-20' => 5])->html;

        $this->assertStringContainsString('<title>2026-09-19: 2</title>', $svg);
        $this->assertStringContainsString('<title>2026-09-20: 5</title>', $svg);
        $this->assertStringContainsString('aria-label="2026-09-19: 2, 2026-09-20: 5"', $svg);
        $this->assertSame(2, substr_count($svg, '<rect'));
    }

    /** A month of days gets a label a week, and none of them overlap at the end. */
    public function test_daily_labels_are_thinned(): void
    {
        $series = [];

        for ($i = 29; $i >= 0; $i--) {
            $series[gmdate('Y-m-d', 1_790_000_000 - $i * 86_400)] = 1;
        }

        preg_match_all('/class="bar-label">([^<]*)</', Bars::svg($series)->html, $matches);
        $labels = array_values(array_filter($matches[1]));

        $this->assertCount(5, $labels, 'one a week, and no label crowding the last');
    }

    public function test_nothing_to_chart_renders_nothing(): void
    {
        $this->assertSame('', Bars::svg([])->html);
    }
}

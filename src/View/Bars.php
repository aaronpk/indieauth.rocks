<?php

declare(strict_types=1);

namespace IndieAuthRocks\View;

/**
 * A bar chart as inline SVG, for a series of counts by month.
 *
 * Markup, not script or style: the content security policy allows neither
 * inline scripts nor inline style attributes, and a chart library would be
 * the first dependency with a build step. Every bar carries its value as a
 * <title>, so the chart reads without hovering too.
 */
final class Bars
{
    private const WIDTH  = 640;
    private const HEIGHT = 140;
    private const LABELS = 22;

    /** @param array<string, int> $series 'YYYY-MM' => count, in order. */
    public static function svg(array $series): Raw
    {
        $n = count($series);

        if ($n === 0) {
            return new Raw('');
        }

        $max    = max(1, max($series));
        $slot   = self::WIDTH / $n;
        $gap    = max(2, $slot * 0.2);
        $plot   = self::HEIGHT - self::LABELS;
        $bars   = '';
        $i      = 0;

        $daily = strlen((string) array_key_first($series)) === 10;

        foreach ($series as $month => $count) {
            $height = $count === 0 ? 0 : max(2, round($count / $max * $plot, 1));
            $x      = round($i * $slot + $gap / 2, 1);
            $y      = round($plot - $height, 1);
            // Months: the year at January and the start. Days: one label a
            // week, so a quarter's worth still reads.
            $label = match (true) {
                // One label a week, and the last day too — unless it would sit
                // on top of the week's label, which is what a 30-day chart did.
                $daily && ($i % 7 === 0 || ($i === $n - 1 && $i % 7 >= 3)) => substr($month, 5),
                $daily                                    => '',
                substr($month, 5, 2) === '01' || $i === 0 => $month,
                default                                   => substr($month, 5),
            };
            $label = htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $bars .= sprintf(
                '<g><title>%s: %d</title><rect x="%s" y="%s" width="%s" height="%s" rx="2" class="bar"/>'
                . '<text x="%s" y="%d" text-anchor="middle" class="bar-label">%s</text></g>',
                htmlspecialchars($month, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $count,
                $x,
                $y,
                round($slot - $gap, 1),
                $height,
                round($i * $slot + $slot / 2, 1),
                self::HEIGHT - 6,
                $label,
            );
            $i++;
        }

        return new Raw(sprintf(
            '<svg class="bars" viewBox="0 0 %d %d" width="100%%" role="img" aria-label="%s">%s</svg>',
            self::WIDTH,
            self::HEIGHT,
            htmlspecialchars(self::describe($series), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $bars,
        ));
    }

    /** @param array<string, int> $series */
    private static function describe(array $series): string
    {
        $parts = [];
        foreach ($series as $month => $count) {
            $parts[] = $month . ': ' . $count;
        }

        return implode(', ', $parts);
    }
}

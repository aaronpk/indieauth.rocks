<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup;

use IndieAuthRocks\View\ExchangeRows;
use IndieAuthRocks\View\InlineLinks;

/**
 * Puts each recorded request under the step it belongs to.
 *
 * Discovery makes its requests inside one call, so they all carry the same step
 * name; which of them illustrates which part of the story is decided here, by
 * the URLs each step says are its own.
 */
final class Transcript
{
    /**
     * @param  list<Step>                 $steps
     * @param  list<array<string, mixed>> $rows  Exchanges for the whole checkup.
     * @return list<array<string, mixed>>        One entry per step, for the template.
     */
    public static function group(array $steps, array $rows): array
    {
        $formatted = ExchangeRows::format($rows);
        $taken     = [];
        $out       = [];

        foreach ($steps as $step) {
            $mine = [];

            foreach ($formatted as $i => $row) {
                if (isset($taken[$i]) || !self::belongs($step, $row)) {
                    continue;
                }

                $taken[$i] = true;
                $mine[]    = $row;
            }

            // A link to something the advice names reads better on the name
            // itself than as a bare address underneath it.
            $fix = $step->fix === null ? null : InlineLinks::in($step->fix, $step->links);

            $out[] = [
                'id'        => $step->id,
                'title'     => $step->title,
                'state'     => $step->state->value,
                'state_label' => $step->state->label(),
                'badge'     => $step->state->badgeClass(),
                'headline'  => $step->headline,
                'facts'     => $step->facts,
                'meaning'   => $step->meaning,
                'fix'       => $fix === null ? null : $fix['html'],
                'links'     => $fix === null ? $step->links : $fix['rest'],
                'exchanges' => $mine,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function belongs(Step $step, array $row): bool
    {
        $url = self::withoutQuery((string) ($row['url'] ?? ''));

        foreach ($step->urls as $candidate) {
            if ($url === self::withoutQuery($candidate)) {
                return true;
            }
        }

        // The reply from someone's server arrives at our own callback.
        return $step->id === 'return' && ($row['direction'] ?? '') === 'inbound';
    }

    private static function withoutQuery(string $url): string
    {
        return rtrim(explode('#', explode('?', $url)[0])[0], '/');
    }
}

<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup;

/**
 * One step of a login, told to the person whose login it is.
 *
 * Every field is plain text. Nothing here is ever HTML, so the template layer's
 * escaping covers the whole page and View\Raw is never needed — which matters,
 * because much of this text quotes whatever a stranger's website returned.
 */
final class Step
{
    /**
     * @param string                             $id       A slug, never a test number: see Summary.
     * @param array<string, string>              $facts    Label => value, shown as a definition list.
     * @param list<array{text: string, url: string}> $links
     * @param list<string>                       $urls     Requests to these URLs belong to this step.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly StepState $state,
        public readonly string $headline,
        public readonly array $facts = [],
        public readonly ?string $meaning = null,
        public readonly ?string $fix = null,
        public readonly array $links = [],
        public readonly array $urls = [],
    ) {
    }

    public function with(StepState $state, string $headline): self
    {
        return new self($this->id, $this->title, $state, $headline, $this->facts, $this->meaning, $this->fix, $this->links, $this->urls);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'title'    => $this->title,
            'state'    => $this->state->value,
            'headline' => $this->headline,
            'facts'    => $this->facts,
            'meaning'  => $this->meaning,
            'fix'      => $this->fix,
            'links'    => $this->links,
            'urls'     => $this->urls,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        /** @var array<string, string> $facts */
        $facts = is_array($row['facts'] ?? null) ? $row['facts'] : [];
        /** @var list<array{text: string, url: string}> $links */
        $links = is_array($row['links'] ?? null) ? array_values($row['links']) : [];
        /** @var list<string> $urls */
        $urls = is_array($row['urls'] ?? null) ? array_values($row['urls']) : [];

        return new self(
            id:       (string) ($row['id'] ?? ''),
            title:    (string) ($row['title'] ?? ''),
            state:    StepState::tryFrom((string) ($row['state'] ?? '')) ?? StepState::Unknown,
            headline: (string) ($row['headline'] ?? ''),
            facts:    $facts,
            meaning:  isset($row['meaning']) && is_string($row['meaning']) ? $row['meaning'] : null,
            fix:      isset($row['fix']) && is_string($row['fix']) ? $row['fix'] : null,
            links:    $links,
            urls:     $urls,
        );
    }

    /**
     * @param  list<Step>                 $steps
     * @return list<array<string, mixed>>
     */
    public static function listToArray(array $steps): array
    {
        return array_map(static fn (self $step): array => $step->toArray(), $steps);
    }

    /**
     * @param  array<int, mixed> $rows
     * @return list<Step>
     */
    public static function listFromArray(array $rows): array
    {
        $steps = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $steps[] = self::fromArray($row);
            }
        }

        return $steps;
    }
}

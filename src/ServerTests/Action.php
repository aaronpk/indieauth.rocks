<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

/**
 * What a run needs next from whoever is driving it: a person in a browser, or
 * a driver on the command line.
 */
final class Action
{
    /** Send the browser to a URL on the server under test. */
    public const NAVIGATE = 'navigate';

    /** Answer a question. */
    public const ASK = 'ask';

    /** Wait until a time, then continue. */
    public const WAIT = 'wait';

    public const FINISHED = 'finished';

    /** @param list<string> $tests */
    public function __construct(
        public readonly string $type,
        public readonly ?string $step = null,
        public readonly array $tests = [],
        public readonly string $title = '',
        public readonly string $instructions = '',
        public readonly ?string $attemptId = null,
        public readonly ?string $url = null,
        public readonly ?string $intent = null,
        public readonly ?Question $question = null,
        public readonly ?int $until = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type'         => $this->type,
            'step'         => $this->step,
            'tests'        => $this->tests,
            'title'        => $this->title,
            'instructions' => $this->instructions,
            'attempt_id'   => $this->attemptId,
            'url'          => $this->url,
            'intent'       => $this->intent,
            'question'     => $this->question?->toArray(),
            'until'        => $this->until,
        ];
    }
}

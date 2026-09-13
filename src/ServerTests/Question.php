<?php

declare(strict_types=1);

namespace IndieAuthRocks\ServerTests;

/** Something only the person running the test can tell us. */
final class Question
{
    /** @param array<string, string> $choices value => label; empty for free text. */
    public function __construct(
        public readonly string $key,
        public readonly string $prompt,
        public readonly array $choices = [],
        public readonly bool $freeText = false,
        public readonly string $hint = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key'       => $this->key,
            'prompt'    => $this->prompt,
            'choices'   => $this->choices,
            'free_text' => $this->freeText,
            'hint'      => $this->hint,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['key'],
            (string) $data['prompt'],
            (array) ($data['choices'] ?? []),
            (bool) ($data['free_text'] ?? false),
            (string) ($data['hint'] ?? ''),
        );
    }
}

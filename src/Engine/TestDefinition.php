<?php

declare(strict_types=1);

namespace IndieAuthRocks\Engine;

/**
 * One numbered test. Catalogs are plain data: the checks that grade a test
 * live with the runners, keyed by this id.
 */
final class TestDefinition
{
    /** @param list<string> $dependsOn Test ids whose outcome this test needs. */
    public function __construct(
        public readonly string $id,
        public readonly string $suite,
        public readonly string $section,
        public readonly string $title,
        public readonly Level $level,
        public readonly Mode $mode,
        public readonly string $spec,
        public readonly string $description,
        public readonly string $passes,
        public readonly array $dependsOn = [],
        public readonly bool $extended = false,
    ) {
    }

    public function number(): int
    {
        return (int) substr($this->id, 1);
    }

    /** A link for the spec reference, when it names a document we can link to. */
    public function specUrl(): string
    {
        if (preg_match('/RFC\s*(\d{4})/', $this->spec, $m) === 1) {
            return 'https://www.rfc-editor.org/rfc/rfc' . $m[1];
        }

        return 'https://indieauth.spec.indieweb.org/';
    }

    /** @return array<string, mixed> For templates, which escape strings but not objects. */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'suite'       => $this->suite,
            'section'     => $this->section,
            'title'       => $this->title,
            'level'       => $this->level->value,
            'level_label' => $this->level->label(),
            'mode'        => $this->mode->value,
            'mode_label'  => $this->mode->label(),
            'spec'        => $this->spec,
            'spec_url'    => $this->specUrl(),
            'description' => $this->description,
            'passes'      => $this->passes,
            'depends_on'  => $this->dependsOn,
            'extended'    => $this->extended,
        ];
    }
}

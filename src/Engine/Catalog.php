<?php

declare(strict_types=1);

namespace IndieAuthRocks\Engine;

/** Looks up tests across both suites. */
final class Catalog
{
    public const SERVER = 'server';
    public const CLIENT = 'client';

    /** @return array<string, TestDefinition> */
    public static function suite(string $suite): array
    {
        return match ($suite) {
            self::SERVER => ServerCatalog::tests(),
            self::CLIENT => ClientCatalog::tests(),
            default      => [],
        };
    }

    public static function find(string $id): ?TestDefinition
    {
        $suite = match ($id[0] ?? '') {
            'S'     => self::SERVER,
            'C'     => self::CLIENT,
            default => null,
        };

        return $suite === null ? null : (self::suite($suite)[$id] ?? null);
    }

    /**
     * Tests grouped by section, in catalog order.
     *
     * @return array<string, list<TestDefinition>>
     */
    public static function sections(string $suite): array
    {
        $sections = [];

        foreach (self::suite($suite) as $test) {
            $sections[$test->section][] = $test;
        }

        return $sections;
    }
}

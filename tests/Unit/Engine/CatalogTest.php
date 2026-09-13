<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\Engine;

use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\ClientCatalog;
use IndieAuthRocks\Engine\ServerCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Catalog::class)]
#[CoversClass(ServerCatalog::class)]
#[CoversClass(ClientCatalog::class)]
final class CatalogTest extends TestCase
{
    public function test_ids_are_well_formed_and_match_their_suite(): void
    {
        foreach ([Catalog::SERVER => 'S', Catalog::CLIENT => 'C'] as $suite => $prefix) {
            foreach (Catalog::suite($suite) as $id => $test) {
                $this->assertMatchesRegularExpression('/^' . $prefix . '[1-9]\d{2}$/', $id);
                $this->assertSame($id, $test->id);
                $this->assertSame($suite, $test->suite);
                $this->assertNotSame('', trim($test->title));
                $this->assertNotSame('', trim($test->description), $id);
                $this->assertNotSame('', trim($test->passes), $id);
                $this->assertNotSame('', trim($test->spec), $id);
            }
        }
    }

    public function test_dependencies_exist_in_the_same_suite_and_come_earlier_or_nearby(): void
    {
        foreach ([Catalog::SERVER, Catalog::CLIENT] as $suite) {
            $tests = Catalog::suite($suite);

            foreach ($tests as $id => $test) {
                foreach ($test->dependsOn as $dependency) {
                    $this->assertArrayHasKey($dependency, $tests, sprintf('%s depends on unknown %s', $id, $dependency));
                    $this->assertNotSame($id, $dependency);
                }
            }
        }
    }

    public function test_sections_follow_the_hundreds(): void
    {
        foreach ([Catalog::SERVER, Catalog::CLIENT] as $suite) {
            foreach (Catalog::sections($suite) as $section => $tests) {
                $hundreds = array_unique(array_map(static fn ($t): int => intdiv($t->number(), 100), $tests));
                $this->assertCount(1, $hundreds, sprintf('Section "%s" spans more than one hundred', $section));
            }
        }
    }

    public function test_find_looks_up_either_suite(): void
    {
        $this->assertSame('S208', Catalog::find('S208')?->id);
        $this->assertSame('C403', Catalog::find('C403')?->id);
        $this->assertNull(Catalog::find('S999'));
        $this->assertNull(Catalog::find(''));
    }
}

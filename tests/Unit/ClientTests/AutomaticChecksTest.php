<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\ClientTests;

use IndieAuthRocks\ClientTests\AutomaticChecks;
use IndieAuthRocks\ClientTests\Scenario;
use IndieAuthRocks\Engine\Catalog;
use IndieAuthRocks\Engine\Mode;
use PHPUnit\Framework\TestCase;

final class AutomaticChecksTest extends TestCase
{
    public function test_every_check_nobody_runs_says_what_exercises_it(): void
    {
        $automatic = [];

        foreach (Catalog::suite(Catalog::CLIENT) as $id => $test) {
            if ($test->mode === Mode::AUTO && !Scenario::exists($test->number())) {
                $automatic[] = $id;
                $this->assertTrue(AutomaticChecks::isAutomatic($id), $id);
                $this->assertStringContainsString('while you run the other client tests', AutomaticChecks::hint($id), $id);
            }
        }

        $this->assertContains('C410', $automatic);
        $this->assertContains('C201', $automatic);
    }

    public function test_a_sign_in_test_is_not_an_automatic_check(): void
    {
        $this->assertFalse(AutomaticChecks::isAutomatic('C101'));
        $this->assertFalse(AutomaticChecks::isAutomatic('C502'));
    }

    public function test_a_check_with_its_own_condition_names_it(): void
    {
        $this->assertStringContainsString('asked for no scope', AutomaticChecks::hint('C410'));
        $this->assertStringContainsString('revocation endpoint', AutomaticChecks::hint('C505'));
        $this->assertStringContainsString('authorization request', AutomaticChecks::hint('C201'));
    }
}

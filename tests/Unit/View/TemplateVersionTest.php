<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\View;

use IndieAuthRocks\View\Template;
use PHPUnit\Framework\TestCase;

final class TemplateVersionTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir() . '/iar-assets-' . bin2hex(random_bytes(6));
        mkdir($this->public . '/assets', 0o775, true);
        file_put_contents($this->public . '/assets/app.css', 'body{}');
    }

    protected function tearDown(): void
    {
        @unlink($this->public . '/assets/app.css');
        @rmdir($this->public . '/assets');
        @rmdir($this->public);
    }

    public function test_the_version_is_the_files_modification_time(): void
    {
        touch($this->public . '/assets/app.css', 1_700_000_000);

        $this->assertSame('1700000000', $this->template()->version('assets/app.css'));
    }

    public function test_editing_the_file_changes_the_version(): void
    {
        touch($this->public . '/assets/app.css', 1_700_000_000);
        $before = $this->template()->version('assets/app.css');

        touch($this->public . '/assets/app.css', 1_700_000_500);
        // PHP remembers stat results for the rest of the request, which is the
        // point: within one request the version is stable, and the next request
        // is a fresh process that sees the edit.
        clearstatcache(true, $this->public . '/assets/app.css');

        $this->assertNotSame($before, $this->template()->version('assets/app.css'));
    }

    /** A missing stylesheet is a deploy problem; it should not take the page down. */
    public function test_a_missing_file_has_no_version(): void
    {
        $this->assertSame('', $this->template()->version('assets/nope.css'));
        $this->assertSame('', $this->template()->version('../composer.json'));
    }

    private function template(): Template
    {
        return new Template($this->public, $this->public);
    }
}

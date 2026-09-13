<?php

declare(strict_types=1);

namespace IndieAuthRocks\Console;

/**
 * Terminal output with colour when attached to a TTY.
 */
final class Output
{
    private readonly bool $color;

    /** @var resource */
    private readonly mixed $errorStream;

    public function __construct(private readonly mixed $stream = STDOUT, mixed $errorStream = null)
    {
        $this->color = getenv('NO_COLOR') === false && stream_isatty($this->stream);

        // Defaults to STDERR, but follows an injected stream so a test capturing
        // output does not spray messages across the test runner's own output.
        $this->errorStream = $errorStream ?? ($this->stream === STDOUT ? STDERR : $this->stream);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stream, $text . PHP_EOL);
    }

    public function heading(string $text): void
    {
        $this->line();
        $this->line($this->style($text, '1'));
    }

    public function pass(string $label, string $detail = ''): void
    {
        $this->result('ok', '32', $label, $detail);
    }

    public function warn(string $label, string $detail = ''): void
    {
        $this->result('warn', '33', $label, $detail);
    }

    public function fail(string $label, string $detail = ''): void
    {
        $this->result('FAIL', '31', $label, $detail);
    }

    public function info(string $label, string $detail = ''): void
    {
        $this->result('--', '90', $label, $detail);
    }

    private function result(string $tag, string $code, string $label, string $detail): void
    {
        $this->line(sprintf(
            '  %s  %s%s',
            $this->style(str_pad($tag, 4, ' ', STR_PAD_LEFT), $code),
            $label,
            $detail === '' ? '' : $this->style('  — ' . $detail, '90'),
        ));
    }

    public function error(string $text): void
    {
        fwrite($this->errorStream, $this->style($text, '31') . PHP_EOL);
    }

    private function style(string $text, string $code): string
    {
        return $this->color ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}

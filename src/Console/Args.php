<?php

declare(strict_types=1);

namespace IndieAuthRocks\Console;

/**
 * Minimal argv parsing: --flag, --key=value, and positional arguments.
 */
final class Args
{
    /** @var array<string, string|true> */
    private array $options = [];

    /** @var list<string> */
    private array $positional = [];

    /** @param list<string> $argv */
    public function __construct(array $argv)
    {
        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '--')) {
                $this->positional[] = $arg;
                continue;
            }

            $body = substr($arg, 2);
            if (str_contains($body, '=')) {
                [$key, $value]       = explode('=', $body, 2);
                $this->options[$key] = $value;
            } else {
                $this->options[$body] = true;
            }
        }
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function flag(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function positional(int $index, ?string $default = null): ?string
    {
        return $this->positional[$index] ?? $default;
    }

    /** @return list<string> Option names that were not in the allowed list. */
    public function unknownOptions(array $allowed): array
    {
        return array_values(array_diff(array_keys($this->options), $allowed));
    }
}

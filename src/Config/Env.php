<?php

declare(strict_types=1);

namespace IndieAuthRocks\Config;

/**
 * Loads a .env file into the process environment.
 *
 * Deliberately tiny: KEY=value lines, optional surrounding quotes, # comments.
 * Variables already present in the real environment win, so a deployment can
 * override anything in the file without editing it.
 */
final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        foreach ((array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                continue;
            }

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) === false) {
                putenv($name . '=' . $value);
            }
        }
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function flag(string $name, bool $default = false): bool
    {
        $value = self::get($name);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * A comma-separated setting, trimmed, with empties dropped.
     *
     * @return list<string>
     */
    public static function list(string $name): array
    {
        $value = self::get($name);

        if ($value === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $item): string => trim($item), explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    public static function int(string $name, int $default): int
    {
        $value = self::get($name);

        return $value !== null && preg_match('/^\d+$/', $value) === 1 ? (int) $value : $default;
    }
}

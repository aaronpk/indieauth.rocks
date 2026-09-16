<?php

declare(strict_types=1);

namespace IndieAuthRocks\View;

/**
 * Request and response bodies, laid out to be read.
 *
 * A form-encoded body becomes one parameter per line, percent-escapes
 * decoded; JSON is pretty printed. Anything else is left alone.
 */
final class BodyFormat
{
    public static function pretty(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return '';
        }

        return self::json($trimmed) ?? self::form($trimmed) ?? $body;
    }

    private static function json(string $body): ?string
    {
        if (!str_starts_with($body, '{') && !str_starts_with($body, '[')) {
            return null;
        }

        try {
            $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $pretty = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $pretty === false ? null : $pretty;
    }

    private static function form(string $body): ?string
    {
        // One or more name=value pairs, and nothing that suggests another format.
        if (preg_match('/^[^&=\s]+=[^&\s]*(&[^&=\s]+=[^&\s]*)*$/', $body) !== 1) {
            return null;
        }

        $lines = [];

        foreach (explode('&', $body) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $lines[]        = self::readable(rawurldecode($name)) . '=' . self::readable(rawurldecode($value));
        }

        return implode("\n", $lines);
    }

    /** Decoded values are shown as text, so control characters cannot rearrange the layout. */
    private static function readable(string $value): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/u', '·', $value);
    }
}

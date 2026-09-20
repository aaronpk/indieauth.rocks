<?php

declare(strict_types=1);

namespace IndieAuthRocks\View;

/**
 * Recorded exchanges, shaped for display: headers as text, bodies pretty
 * printed. Shared by the test-run transcript and a checkup, so both show the
 * same thing the same way.
 */
final class ExchangeRows
{
    /**
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function format(array $rows): array
    {
        return array_map(static fn (array $e): array => [
            'direction'        => (string) $e['direction'],
            'method'           => (string) $e['method'],
            'url'              => (string) $e['url'],
            'status'           => $e['status'] !== null ? (int) $e['status'] : null,
            'request_headers'  => self::headerText($e['request_headers']),
            'request_body'     => BodyFormat::pretty((string) ($e['request_body'] ?? '')),
            'response_headers' => self::headerText($e['response_headers']),
            'response_body'    => BodyFormat::pretty((string) ($e['response_body'] ?? '')),
            'error'            => (string) ($e['error'] ?? ''),
            'created_at'       => (string) $e['created_at'],
        ], array_values($rows));
    }

    /** @param mixed $headers Decoded header map, or anything else. */
    public static function headerText(mixed $headers): string
    {
        if (!is_array($headers)) {
            return '';
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            foreach ((array) $value as $v) {
                $lines[] = $name . ': ' . $v;
            }
        }

        return implode("\n", $lines);
    }
}

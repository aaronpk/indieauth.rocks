<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

use IndieAuthRocks\Fetch\FetchResult;
use IndieAuthRocks\Fetch\HttpRequest;
use IndieAuthRocks\Fetch\Redactor;
use IndieAuthRocks\Http\Request;

/**
 * HTTP transcripts for a run: the requests this site makes (outbound) and the
 * requests other software makes to it (inbound). Always redacted.
 */
final class ExchangeRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param list<string>|null $testIds The tests the request was made for; null for the whole step. */
    public function recordOutbound(int $runId, ?string $step, HttpRequest $request, ?FetchResult $result, ?string $error = null, ?array $testIds = null): int
    {
        return $this->db->insert('exchanges', [
            'run_id'                => $runId,
            'step'                  => $step,
            'test_ids'              => $testIds === null || $testIds === [] ? null : implode(',', $testIds),
            'direction'             => 'outbound',
            'method'                => $request->method,
            'url'                   => mb_substr(Redactor::url($request->url), 0, 2000),
            'request_headers_json'  => self::encode(Redactor::headers($request->headers)),
            'request_body'          => Redactor::body($request->body, (string) $request->header('content-type'), Redactor::MAX_REQUEST_BODY),
            'status'                => $result?->status,
            'response_headers_json' => $result === null ? null : self::encode(Redactor::headers($result->headers)),
            'response_body'         => $result === null ? null : self::utf8(Redactor::body($result->body, (string) $result->header('content-type'), Redactor::MAX_RESPONSE_BODY)),
            'duration_ms'           => $result?->durationMs,
            'error'                 => $error === null ? null : mb_substr($error, 0, 1000),
            'created_at'            => Database::now(),
        ]);
    }

    public function recordInbound(int $runId, ?string $step, Request $request, ?int $status = null): int
    {
        $headers = $request->headers;
        unset($headers['cookie']);

        return $this->db->insert('exchanges', [
            'run_id'               => $runId,
            'step'                 => $step,
            'direction'            => 'inbound',
            'method'               => $request->method,
            'url'                  => mb_substr(Redactor::url($request->path . ($request->queryString ? '?' . $request->queryString : '')), 0, 2000),
            'request_headers_json' => self::encode(Redactor::headers($headers)),
            'request_body'         => self::utf8(Redactor::body($request->body, (string) $request->header('content-type'), Redactor::MAX_REQUEST_BODY)),
            'status'               => $status,
            'remote_ip'            => $request->ip,
            'created_at'           => Database::now(),
        ]);
    }

    /** File a request under the step it turned out to belong to, once that is known. */
    public function setStep(int $exchangeId, string $step): void
    {
        $this->db->execute('UPDATE exchanges SET step = ? WHERE id = ?', [$step, $exchangeId]);
    }

    /** @return list<array<string, mixed>> */
    public function forStep(int $runId, string $step): array
    {
        return $this->decodeRows($this->db->all('SELECT * FROM exchanges WHERE run_id = ? AND step = ? ORDER BY id', [$runId, $step]));
    }

    /**
     * The requests in a step that one test used: those made for it, and those
     * that belong to the whole step, like the landing on the callback.
     *
     * @return list<array<string, mixed>>
     */
    public function forTest(int $runId, string $step, string $testId): array
    {
        return $this->decodeRows($this->db->all(
            'SELECT * FROM exchanges WHERE run_id = ? AND step = ? AND (test_ids IS NULL OR FIND_IN_SET(?, test_ids) > 0) ORDER BY id',
            [$runId, $step, $testId],
        ));
    }

    /** @return list<array<string, mixed>> */
    public function forRun(int $runId, ?string $direction = null): array
    {
        return $this->decodeRows($direction === null
            ? $this->db->all('SELECT * FROM exchanges WHERE run_id = ? ORDER BY id', [$runId])
            : $this->db->all('SELECT * FROM exchanges WHERE run_id = ? AND direction = ? ORDER BY id', [$runId, $direction]));
    }

    /** Whether an inbound request to a path prefix was recorded for the run. */
    public function inboundSeen(int $runId, string $pathPrefix, ?string $sinceId = null): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM exchanges WHERE run_id = ? AND direction = ? AND url LIKE ? AND id > ?',
            [$runId, 'inbound', addcslashes($pathPrefix, '%_\\') . '%', (int) $sinceId],
        ) > 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decodeRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['request_headers']  = json_decode((string) $row['request_headers_json'], true) ?: [];
            $row['response_headers'] = json_decode((string) $row['response_headers_json'], true) ?: [];
        }

        return $rows;
    }

    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function utf8(?string $value): ?string
    {
        return $value === null ? null : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}

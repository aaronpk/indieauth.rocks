<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

use IndieAuthRocks\Storage\ExchangeRepository;

/**
 * Records every request sent during a run step, so each result can show the
 * exact HTTP that produced it. Requests sent inside forTests() are also tagged
 * with the tests they were made for.
 */
final class RecordingHttpClient implements HttpClient
{
    private ?int $runId = null;
    private ?string $step = null;

    /** @var list<list<string>> Innermost last. */
    private array $tests = [];

    public function __construct(
        private readonly HttpClient $inner,
        private readonly ExchangeRepository $exchanges,
    ) {
    }

    /** Attribute subsequent requests to a run and step. */
    public function context(?int $runId, ?string $step = null): void
    {
        $this->runId = $runId;
        $this->step  = $step;
        $this->tests = [];
    }

    /**
     * Tag the requests $fn sends with the tests they are made for. Nested calls
     * narrow the tags, e.g. a discovery made while grading one test.
     *
     * @template T
     * @param list<string>  $testIds
     * @param callable(): T $fn
     * @return T
     */
    public function forTests(array $testIds, callable $fn): mixed
    {
        $this->tests[] = array_values($testIds);

        try {
            return $fn();
        } finally {
            array_pop($this->tests);
        }
    }

    public function step(): ?string
    {
        return $this->step;
    }

    public function send(HttpRequest $request): FetchResult
    {
        try {
            $result = $this->inner->send($request);
        } catch (FetchException $e) {
            if ($this->runId !== null) {
                $this->exchanges->recordOutbound($this->runId, $this->step, $request, null, $e->getMessage(), $this->currentTests());
            }

            throw $e;
        }

        if ($this->runId !== null) {
            $this->exchanges->recordOutbound($this->runId, $this->step, $request, $result, testIds: $this->currentTests());
        }

        return $result;
    }

    /** @return list<string>|null */
    private function currentTests(): ?array
    {
        return $this->tests === [] ? null : $this->tests[array_key_last($this->tests)];
    }
}

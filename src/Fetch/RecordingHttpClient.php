<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

use IndieAuthRocks\Storage\ExchangeRepository;

/**
 * Records every request sent during a run step, so each result can show the
 * exact HTTP that produced it.
 */
final class RecordingHttpClient implements HttpClient
{
    private ?int $runId = null;
    private ?string $step = null;

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
                $this->exchanges->recordOutbound($this->runId, $this->step, $request, null, $e->getMessage());
            }

            throw $e;
        }

        if ($this->runId !== null) {
            $this->exchanges->recordOutbound($this->runId, $this->step, $request, $result);
        }

        return $result;
    }
}

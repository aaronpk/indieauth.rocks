<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup;

use IndieAuthRocks\Engine\Run;

/**
 * A checkup, read from the run row that holds it.
 *
 * The run's state_json carries the steps as told so far, the endpoints they
 * found, and which phase the login is in. It never carries the PKCE verifier or
 * an access token: those live in Redis for the minutes the login is in flight,
 * and are gone afterwards.
 */
final class Checkup
{
    public const KIND = 'checkup';

    /** Waiting to be sent to the person's server. */
    public const DISCOVERED = 'discovered';
    /** They have left for their server and have not come back. */
    public const SENT = 'sent';
    public const FINISHED = 'finished';

    public function __construct(public readonly Run $run)
    {
    }

    public function isCheckup(): bool
    {
        return $this->run->kind === self::KIND;
    }

    public function publicId(): string
    {
        return $this->run->publicId;
    }

    public function phase(): string
    {
        $phase = $this->run->state['phase'] ?? self::DISCOVERED;

        return is_string($phase) ? $phase : self::DISCOVERED;
    }

    /** What the person typed, before it was turned into a URL. */
    public function typed(): string
    {
        return is_string($this->run->state['typed'] ?? null) ? $this->run->state['typed'] : '';
    }

    public function profileUrl(): string
    {
        return $this->run->targetUrl ?? '';
    }

    /** The address the person's server says is theirs, once it has said so. */
    public function me(): ?string
    {
        $me = $this->run->state['me'] ?? null;

        return is_string($me) && $me !== '' ? $me : null;
    }

    public function wantsToken(): bool
    {
        return (bool) ($this->run->options['wants_token'] ?? false);
    }

    /** @return list<Step> */
    public function steps(): array
    {
        $steps = $this->run->state['steps'] ?? [];

        return is_array($steps) ? Step::listFromArray($steps) : [];
    }

    public function summary(): Summary
    {
        return Summary::of($this->steps(), $this->phase(), $this->me(), $this->profileUrl());
    }

    /** Whether the login can still be started, or started again. */
    public function canDepart(): bool
    {
        if ($this->phase() === self::FINISHED) {
            return false;
        }

        foreach ($this->steps() as $step) {
            if ($step->state->stopsTheLogin()) {
                return false;
            }
        }

        return $this->endpoint('authorization') !== null;
    }

    public function endpoint(string $name): ?string
    {
        $endpoints = $this->run->state['endpoints'] ?? [];
        $value     = is_array($endpoints) ? ($endpoints[$name] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether this browser is the one that started the checkup.
     *
     * The page itself is readable by anyone with the link — that is how it gets
     * shown to whoever is helping. Leaving for someone else's server, and
     * deleting the checkup, are not.
     */
    public function isOwner(?string $secret): bool
    {
        $hash = $this->run->state['owner_hash'] ?? null;

        return is_string($hash) && $hash !== '' && is_string($secret) && $secret !== ''
            && hash_equals($hash, hash('sha256', $secret));
    }
}

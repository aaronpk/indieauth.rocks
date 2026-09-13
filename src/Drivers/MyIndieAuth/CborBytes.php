<?php

declare(strict_types=1);

namespace IndieAuthRocks\Drivers\MyIndieAuth;

/**
 * Marks a string as a CBOR byte string rather than a text string.
 *
 * The two are different major types, and WebAuthn structures depend on the
 * distinction: `authData` must be bytes, `fmt` must be text.
 */
final class CborBytes
{
    public function __construct(public readonly string $bytes)
    {
    }
}

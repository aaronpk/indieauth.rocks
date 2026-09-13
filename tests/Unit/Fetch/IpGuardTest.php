<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\Fetch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use IndieAuthRocks\Fetch\IpGuard;

#[CoversClass(IpGuard::class)]
final class IpGuardTest extends TestCase
{
    #[DataProvider('blockedAddresses')]
    public function test_special_use_addresses_are_refused(string $ip): void
    {
        $this->assertFalse((new IpGuard())->isAllowed($ip), $ip);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedAddresses(): iterable
    {
        // The ones an attacker actually reaches for.
        yield 'loopback'            => ['127.0.0.1'];
        yield 'loopback range'      => ['127.1.2.3'];
        yield 'cloud metadata'      => ['169.254.169.254'];
        yield 'link local'          => ['169.254.0.1'];
        yield 'private 10'          => ['10.0.0.1'];
        yield 'private 172'         => ['172.16.0.1'];
        yield 'private 172 upper'   => ['172.31.255.255'];
        yield 'private 192'         => ['192.168.1.1'];
        yield 'cgnat'               => ['100.64.0.1'];
        yield 'this network'        => ['0.0.0.0'];
        yield 'multicast'           => ['224.0.0.1'];
        yield 'broadcast'           => ['255.255.255.255'];
        yield 'reserved'            => ['240.0.0.1'];
        yield 'benchmarking'        => ['198.18.0.1'];
        yield 'documentation'       => ['192.0.2.1'];
        yield 'protocol assignment' => ['192.0.0.1'];

        yield 'ipv6 loopback'    => ['::1'];
        yield 'ipv6 unspecified' => ['::'];
        yield 'ipv6 ula'         => ['fc00::1'];
        yield 'ipv6 ula upper'   => ['fdff::1'];
        yield 'ipv6 link local'  => ['fe80::1'];
        yield 'ipv6 multicast'   => ['ff02::1'];
        yield 'ipv6 teredo'      => ['2001:0::1'];
        yield 'ipv6 doc'         => ['2001:db8::1'];

        // IPv4-mapped IPv6 would otherwise smuggle the whole v4 blocklist past
        // an implementation that only checks v6 ranges.
        yield 'mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'mapped metadata' => ['::ffff:169.254.169.254'];
        yield 'mapped private' => ['::ffff:10.0.0.1'];
    }

    #[DataProvider('allowedAddresses')]
    public function test_public_addresses_are_allowed(string $ip): void
    {
        $this->assertTrue((new IpGuard())->isAllowed($ip), $ip);
    }

    /** @return iterable<string, array{string}> */
    public static function allowedAddresses(): iterable
    {
        yield 'public v4'         => ['93.184.216.34'];
        yield 'google dns'        => ['8.8.8.8'];
        yield 'just above 10'     => ['11.0.0.1'];
        yield 'just below 10'     => ['9.255.255.255'];
        yield 'just above 172.31' => ['172.32.0.1'];
        yield 'just below 172.16' => ['172.15.255.255'];
        yield 'just above 192.168' => ['192.169.0.1'];
        yield 'public v6'         => ['2606:2800:220:1:248:1893:25c8:1946'];
        yield 'just above ula'    => ['fe00::1'];
    }

    #[DataProvider('malformedAddresses')]
    public function test_unparseable_addresses_are_refused(string $ip): void
    {
        // Refused rather than guessed at: something further down the stack may
        // interpret a malformed value differently.
        $this->assertFalse((new IpGuard())->isAllowed($ip), $ip);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAddresses(): iterable
    {
        yield 'empty'          => [''];
        yield 'hostname'       => ['example.com'];
        yield 'decimal'        => ['2130706433'];
        yield 'truncated'      => ['127.1'];
        yield 'out of range'   => ['999.999.999.999'];
        yield 'trailing dot'   => ['127.0.0.1.'];
        yield 'with port'      => ['127.0.0.1:80'];
        yield 'whitespace'     => [' 127.0.0.1'];
        yield 'hex'            => ['0x7f.0.0.1'];
    }

    #[DataProvider('ambiguousAddresses')]
    public function test_ambiguously_written_addresses_are_refused(string $ip): void
    {
        // The bypass this closes: a parser that accepts "0177.0.0.1" reads it as
        // the public address 177.0.0.1, while resolvers widely read the leading
        // zero as octal and connect to 127.0.0.1. Validating one reading and
        // connecting with the other is exploitable, so the spelling is refused.
        // A strict inet_pton refuses these anyway; the guard is what makes the
        // refusal independent of which parser is underneath.
        $this->assertFalse((new IpGuard())->isAllowed($ip), $ip);
        $this->assertFalse((new IpGuard(allowLoopback: true))->isAllowed($ip), $ip);
    }

    /** @return iterable<string, array{string}> */
    public static function ambiguousAddresses(): iterable
    {
        yield 'octal loopback'   => ['0177.0.0.1'];
        yield 'octal private'    => ['010.0.0.1'];
        yield 'padded octet'     => ['127.000.000.001'];
        yield 'padded public'    => ['08.8.8.8'];
        yield 'zero padded last' => ['93.184.216.034'];
    }

    public function test_the_ambiguity_refusal_is_explained(): void
    {
        $this->assertStringContainsString('ambiguously', (new IpGuard())->reason('0177.0.0.1'));
    }

    public function test_loopback_can_be_permitted_for_development(): void
    {
        $guard = new IpGuard(allowLoopback: true);

        $this->assertTrue($guard->isAllowed('127.0.0.1'));
        $this->assertTrue($guard->isAllowed('::1'));
    }

    public function test_permitting_loopback_does_not_permit_anything_else(): void
    {
        // The development convenience must not become a general SSRF hole.
        $guard = new IpGuard(allowLoopback: true);

        $this->assertFalse($guard->isAllowed('169.254.169.254'));
        $this->assertFalse($guard->isAllowed('10.0.0.1'));
        $this->assertFalse($guard->isAllowed('192.168.1.1'));
        $this->assertFalse($guard->isAllowed('fc00::1'));
    }

    public function test_the_refusal_reason_is_explanatory(): void
    {
        $guard = new IpGuard();

        $this->assertStringContainsString('special-use', $guard->reason('10.0.0.1'));
        $this->assertStringContainsString('not a valid IP', $guard->reason('nonsense'));
    }
}

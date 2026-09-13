<?php

declare(strict_types=1);

namespace IndieAuthRocks\Fetch;

/**
 * Decides whether an IP address may be connected to.
 *
 * Fetching a URL supplied by a client is a server-side request forgery
 * primitive by construction, so the address ranges that reach internal
 * infrastructure are refused. The list is the RFC 6890 special-purpose registry;
 * of these, cloud metadata services on 169.254.169.254 and private ranges are
 * the ones an attacker is actually reaching for.
 */
final class IpGuard
{
    /**
     * @var list<string> RFC 6890 special-purpose IPv4 blocks, plus multicast and
     *                   reserved space.
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // "this host on this network"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, including cloud metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved
        '255.255.255.255/32', // broadcast
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::/128',           // unspecified
        '::1/128',          // loopback
        '::ffff:0:0/96',    // IPv4-mapped, which would otherwise smuggle v4 space
        '64:ff9b::/96',     // NAT64
        '100::/64',         // discard-only
        '2001::/32',        // Teredo
        '2001:10::/28',     // ORCHID
        '2001:db8::/32',    // documentation
        'fc00::/7',         // unique local
        'fe80::/10',        // link-local
        'ff00::/8',         // multicast
    ];

    /** @var list<string> Permitted when the deployment opts into loopback. */
    private const LOOPBACK = ['127.0.0.0/8', '::1/128'];

    public function __construct(private readonly bool $allowLoopback = false)
    {
    }

    public function isAllowed(string $ip): bool
    {
        $packed = @inet_pton($ip);

        // Unparseable is refused: better than guessing at a value that will be
        // interpreted by something further down the stack.
        if ($packed === false) {
            return false;
        }

        // And so is anything whose spelling is ambiguous. See below: this is the
        // check that stops an address passing the range test as one value and
        // being connected to as another.
        if (!self::isUnambiguousLiteral($ip)) {
            return false;
        }

        if ($this->allowLoopback) {
            foreach (self::LOOPBACK as $range) {
                if (self::inRange($packed, $range)) {
                    return true;
                }
            }
        }

        $blocked = strlen($packed) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6;

        foreach ($blocked as $range) {
            if (self::inRange($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    /** The reason an address was refused, for the log. */
    public function reason(string $ip): string
    {
        // Ambiguity is reported ahead of unparseability because it is the more
        // specific answer, and because which of the two a platform reports is not
        // fixed: glibc's inet_pton refuses "0177.0.0.1" outright, while the
        // parsers that accept it read it as 177.0.0.1. The spelling is what was
        // wrong either way, so say so.
        if (!self::isUnambiguousLiteral($ip)) {
            return sprintf('"%s" is written ambiguously and different resolvers would disagree', $ip);
        }

        if (@inet_pton($ip) === false) {
            return sprintf('"%s" is not a valid IP address', $ip);
        }

        return sprintf('%s is in a special-use range that must not be reached', $ip);
    }

    /**
     * Whether an address has exactly one plausible reading.
     *
     * This closes a parser differential that defeats naive SSRF filters. A
     * leading zero in an IPv4 octet is read as *octal* by resolvers and by
     * inet_aton — 0177 = 127, the loopback interface — but as decimal by parsers
     * that accept the spelling at all, giving the public address 177.0.0.1.
     * Validating one reading and connecting with the other is exploitable, so the
     * spelling is refused rather than resolved.
     *
     * Deliberately decided from the text alone, not from a parse: a strict
     * inet_pton (glibc's, among others) already rejects these, and depending on
     * that would make the rule a no-op wherever the parse happens to be lenient —
     * which is the only case where it matters.
     *
     * IPv6 has no octal notation, and IPv4-mapped addresses are handled by the
     * ::ffff:0:0/96 block, so IPv6 literals need no equivalent rule.
     */
    private static function isUnambiguousLiteral(string $ip): bool
    {
        // Four dot-separated runs of decimal digits: an IPv4 literal, well spelled
        // or not. Anything else — IPv6, hex, shorthand, junk — is not this rule's
        // business and is left to inet_pton. The runs are unbounded in length
        // because padding is exactly what is being looked for: "0177" is four
        // characters wide.
        if (preg_match('/^[0-9]+(\.[0-9]+){3}$/', $ip) !== 1) {
            return true;
        }

        // The rule itself: no octet may carry a leading zero. Whether the octets
        // are in range is inet_pton's business, not this one's, so "999.999.999.999"
        // passes here and is refused as unparseable rather than as ambiguous.
        return preg_match('/^(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*)){3}$/', $ip) === 1;
    }

    /**
     * Whether a packed address falls inside a CIDR block.
     *
     * Compares whole bytes then masks the remainder, which avoids the
     * 32-bit-integer traps of ip2long arithmetic and works identically for both
     * address families.
     */
    private static function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);

        $networkPacked = @inet_pton($network);
        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits       = (int) $bits;
        $wholeBytes = intdiv($bits, 8);
        $extraBits  = $bits % 8;

        if ($wholeBytes > 0 && !hash_equals(
            substr($networkPacked, 0, $wholeBytes),
            substr($packed, 0, $wholeBytes),
        )) {
            return false;
        }

        if ($extraBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $extraBits)) - 1) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
    }
}

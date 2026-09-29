<?php

namespace justinholtweb\controltower\helpers;

/**
 * Is this address somewhere Control Tower is allowed to send a webhook?
 *
 * Copied from craft-eye, where it is proven. A webhook sender that will post to any address
 * typed into the CP is a way to read the cloud metadata service, the database, the queue, and
 * everything else on the private network that trusts requests coming from the web server. This
 * class is the thing that says no.
 *
 * Deliberately not written as "filter_var and hope": PHP's `FILTER_FLAG_NO_RES_RANGE` misses
 * carrier-grade NAT, the documentation ranges, 6to4 and NAT64, and an IPv4-mapped IPv6 address
 * sails past every IPv4 check because it is not an IPv4 address.
 */
class Ip
{
    /**
     * Blocked IPv4 ranges, as [network, prefix length].
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8],          // "this network"
        ['10.0.0.0', 8],         // private
        ['100.64.0.0', 10],      // carrier-grade NAT — not "reserved" to filter_var
        ['127.0.0.0', 8],        // loopback
        ['169.254.0.0', 16],     // link-local, and so the cloud metadata endpoint
        ['172.16.0.0', 12],      // private
        ['192.0.0.0', 24],       // IETF protocol assignments
        ['192.0.2.0', 24],       // TEST-NET-1
        ['192.88.99.0', 24],     // 6to4 relay anycast
        ['192.168.0.0', 16],     // private
        ['198.18.0.0', 15],      // benchmarking
        ['198.51.100.0', 24],    // TEST-NET-2
        ['203.0.113.0', 24],     // TEST-NET-3
        ['224.0.0.0', 4],        // multicast
        ['240.0.0.0', 4],        // reserved, incl. 255.255.255.255
    ];

    /**
     * Blocked IPv6 ranges. IPv4-mapped and IPv4-compatible addresses are handled separately,
     * by unwrapping them and re-checking the IPv4 inside.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const BLOCKED_V6 = [
        ['::', 128],             // unspecified
        ['::1', 128],            // loopback
        ['64:ff9b::', 96],       // NAT64
        ['100::', 64],           // discard-only
        ['2001::', 32],          // Teredo
        ['2001:db8::', 32],      // documentation
        ['2002::', 16],          // 6to4
        ['fc00::', 7],           // unique local
        ['fe80::', 10],          // link-local
        ['ff00::', 8],           // multicast
    ];

    /**
     * Whether Control Tower may open a connection to this address.
     *
     * Anything that is not a well-formed public unicast address is refused, including anything
     * this class cannot parse — an address it does not understand is not one it can vouch for.
     */
    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return !self::inAnyRange($packed, self::BLOCKED_V4);
        }

        if (strlen($packed) !== 16) {
            return false;
        }

        // ::ffff:a.b.c.d and ::a.b.c.d are IPv4 wearing a hat. `::ffff:127.0.0.1` reaches
        // loopback and passes every IPv4 test, because it is not an IPv4 address.
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return self::isPublic(inet_ntop(substr($packed, 12)) ?: '');
        }

        if (str_starts_with($packed, str_repeat("\0", 12)) && $packed !== str_repeat("\0", 16)) {
            return self::isPublic(inet_ntop(substr($packed, 12)) ?: '');
        }

        return !self::inAnyRange($packed, self::BLOCKED_V6);
    }

    /**
     * Every address a host name resolves to.
     *
     * A host with no addresses returns an empty array, which callers must treat as a refusal
     * rather than as "nothing to check".
     *
     * @return string[]
     */
    public static function resolve(string $host): array
    {
        $host = trim($host, '[]');

        // An IP literal is its own answer — and must still be checked.
        if (@inet_pton($host) !== false) {
            return [$host];
        }

        $addresses = [];

        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            $addresses = $v4;
        }

        // `dns_get_record` is the only way to see AAAA records from PHP, and it is allowed to
        // fail (no resolver, restricted environment) — in which case an IPv6-only host simply
        // has no addresses and is refused.
        $v6 = @dns_get_record($host, DNS_AAAA);

        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (!empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Resolve a host and refuse it unless *every* address it answers with is public.
     *
     * All, not any: a host that returns one public address and one private one is a
     * DNS-rebinding attempt, and which of the two the connection lands on is not up to us.
     *
     * @return string[] The validated addresses, or an empty array if the host is refused.
     */
    public static function resolvePublic(string $host): array
    {
        $addresses = self::resolve($host);

        if (!$addresses) {
            return [];
        }

        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                return [];
            }
        }

        return $addresses;
    }

    /** @param array<int, array{0: string, 1: int}> $ranges */
    private static function inAnyRange(string $packed, array $ranges): bool
    {
        foreach ($ranges as [$network, $prefix]) {
            if (self::inRange($packed, (string)inet_pton($network), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $packed, string $network, int $prefix): bool
    {
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && strncmp($packed, $network, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xff << (8 - $remainingBits) & 0xff;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}

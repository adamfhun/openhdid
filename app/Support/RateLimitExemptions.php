<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The networks free of the limits keyed on the client address
 * (HDID_RATE_LIMIT_EXEMPT_NETWORKS): a company whose users all reach the
 * system from one NAT address would otherwise lock itself out. The route
 * throttles without a user (ThrottleRequestsUnlessExempt) and the panel's
 * login limit skip these addresses; the container's nginx reads the same
 * variable for its limit_req and limit_conn. The limits per e-mail address,
 * phone number and system, the machine interfaces' named limiters and
 * sign-in itself stay as they are.
 *
 * An entry is an IPv4 or IPv6 address or network; a network wider than /8
 * (IPv4) or /32 (IPv6), "*" and anything unreadable are refused, and one
 * refused entry voids the whole list, so a typo never widens it. Behind a
 * load balancer the client address must be the real one (TRUSTED_PROXIES,
 * in the container OPENHDID_TLS=off with realip), or the balancer's own
 * address would be the only one ever seen.
 */
final class RateLimitExemptions
{
    /** The widest network an entry may name, per address family. */
    public const MIN_IPV4_PREFIX = 8;

    public const MIN_IPV6_PREFIX = 32;

    /** @var list<string> */
    private array $entries;

    public function __construct()
    {
        $this->entries = array_values(array_filter(
            preg_split('/[\s,]+/', trim((string) config('hdid.rate_limit_exempt_networks', ''))) ?: [],
            fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * The networks in force: all entries, or none while one is refused.
     *
     * @return list<string>
     */
    public function networks(): array
    {
        return $this->invalid() === [] ? $this->entries : [];
    }

    /**
     * The entries refused as unreadable or too wide.
     *
     * @return list<string>
     */
    public function invalid(): array
    {
        return array_values(array_filter($this->entries, fn (string $entry): bool => ! self::acceptable($entry)));
    }

    /**
     * Whether the address lies in a listed network. An IPv4-mapped IPv6
     * address ("::ffff:192.0.2.7") is looked up as its IPv4 address, as
     * nginx's geo does: an IPv6 entry such as "::/32" never covers IPv4.
     */
    public function covers(?string $ip): bool
    {
        $networks = $this->networks();

        if ($networks === [] || ! is_string($ip) || $ip === '') {
            return false;
        }

        $mapped = ClientAddress::limitKey($ip);

        return IpUtils::checkIp(filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $mapped : $ip, $networks);
    }

    /**
     * An address, or a network no wider than /8 (IPv4) or /32 (IPv6).
     */
    public static function acceptable(string $entry): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $ipv6 = str_contains((string) $ip, ':');

        return ctype_digit($prefix)
            && (int) $prefix <= ($ipv6 ? 128 : 32)
            && (int) $prefix >= ($ipv6 ? self::MIN_IPV6_PREFIX : self::MIN_IPV4_PREFIX);
    }
}

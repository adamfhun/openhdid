<?php

namespace App\Support;

/**
 * The key of the limits per client address: an IPv4 address counts alone,
 * an IPv6 address by its /64 network, since one subscriber gets a whole /64
 * and could otherwise rotate through its addresses to get a fresh budget on
 * every request (the container's nginx keys its limit_req and limit_conn the
 * same way). An IPv4-mapped IPv6 address ("::ffff:192.0.2.7") counts as its
 * IPv4 address; anything that is no address is used as it is.
 */
final class ClientAddress
{
    public static function limitKey(?string $ip): string
    {
        $ip = (string) $ip;
        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($ip) : false;

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}

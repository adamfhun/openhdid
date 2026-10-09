<?php

namespace App\Sync;

use Illuminate\Http\Client\ConnectionException;

/**
 * Why an EMD request could not even get an answer, in words an operator can act
 * on. The exception itself is never shown or kept, because its request may carry
 * credentials; only the cURL error class and the host and port are taken from it.
 */
class ConnectionFailure
{
    public static function describe(ConnectionException $exception, ?string $url): string
    {
        $host = is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : '';
        $port = is_string($url) ? (parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'http' ? 80 : 443)) : null;
        $target = $host !== '' ? $host.':'.$port : __('the configured address');

        $code = preg_match('/cURL error (\d+)/', $exception->getMessage(), $match) === 1 ? (int) $match[1] : null;

        $reason = match ($code) {
            6 => __('the name does not resolve inside the container (DNS); the container does not see the host /etc/hosts, so a name listed only there needs an extra_hosts entry in compose.override.yaml, and an internal DNS the host reaches through a local resolver needs the dns key of /etc/docker/daemon.json'),
            5 => __('the proxy name does not resolve'),
            7 => __('the connection was refused or the address is unreachable from the container (firewall, routing, wrong port)'),
            28 => __('no answer in time (a firewall dropping the packets, or an overloaded server)'),
            35, 58, 59 => __('the TLS handshake failed'),
            51, 60, 77, 83 => __('the server certificate cannot be verified; with an internal certificate authority put its certificate (PEM) into the ca folder and restart the services'),
            52, 56 => __('the server closed the connection without an answer'),
            default => $code !== null ? __('connection error (cURL :code)', ['code' => $code]) : __('connection error'),
        };

        return $target.': '.$reason;
    }
}

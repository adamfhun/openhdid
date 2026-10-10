<?php

namespace App\Sync;

use App\Support\OutboundProxy;
use Illuminate\Http\Client\ConnectionException;

/**
 * Why an EMD request could not even get an answer, in words an operator can act
 * on. The exception itself is never shown or kept, because its request may carry
 * credentials; only the cURL error class and the host and port are taken from it.
 * Through the outbound proxy the same codes mean the proxy (unreachable, asking
 * for a password, refusing the address), so those get their own words.
 */
class ConnectionFailure
{
    public static function describe(ConnectionException $exception, ?string $url): string
    {
        $host = is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : '';
        $port = is_string($url) ? (parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'http' ? 80 : 443)) : null;
        $target = $host !== '' ? $host.':'.$port : __('the configured address');

        $code = preg_match('/cURL error (\d+)/', $exception->getMessage(), $match) === 1 ? (int) $match[1] : null;

        if (OutboundProxy::routes($url)) {
            return __(':target via the proxy :proxy', ['target' => $target, 'proxy' => OutboundProxy::display()]).': '
                .(self::proxyReason($code, $exception->getMessage()) ?? self::reason($code));
        }

        return $target.': '.self::reason($code);
    }

    /**
     * What a failure through the proxy means, or null when it means the same
     * as without one (a certificate or TLS problem of the address itself).
     */
    private static function proxyReason(?int $code, string $message): ?string
    {
        // cURL reports a refused CONNECT as "CONNECT tunnel failed, response 407"
        // (older versions: "Received HTTP code 407 from proxy after CONNECT").
        $status = preg_match('/(?:response|HTTP code) (\d{3})/', $message, $match) === 1 ? (int) $match[1] : null;

        return match (true) {
            $code === 5 => __('the proxy name does not resolve (DNS); check HDID_HTTP_PROXY'),
            $code === 7 => __('the proxy refused the connection or is unreachable from the container (HDID_HTTP_PROXY, firewall, port)'),
            $code === 28 => __('no answer in time, from the proxy or from the address behind it'),
            $status === 407 => __('the proxy asks for a user name and password: give them in HDID_HTTP_PROXY (http://user:password@host:port); proxies with Windows (NTLM) sign-in are not supported'),
            $status === 403 => __('the proxy does not allow this address: ask its administrator to allow it, or list an internal server in HDID_NO_PROXY'),
            $status !== null && $status >= 500 => __('the proxy could not reach the address (HTTP :status); an internal server belongs in HDID_NO_PROXY', ['status' => $status]),
            $status !== null => __('the proxy refused the connection (HTTP :status)', ['status' => $status]),
            default => null,
        };
    }

    private static function reason(?int $code): string
    {
        return match ($code) {
            6 => __('the name does not resolve inside the container (DNS); the container does not see the host /etc/hosts, so a name listed only there needs an extra_hosts entry in compose.override.yaml, and an internal DNS the host reaches through a local resolver needs the dns key of /etc/docker/daemon.json'),
            5 => __('the proxy name does not resolve'),
            7 => __('the connection was refused or the address is unreachable from the container (firewall, routing, wrong port)'),
            28 => __('no answer in time (a firewall dropping the packets, or an overloaded server)'),
            35, 58, 59 => __('the TLS handshake failed'),
            51, 60, 77, 83 => __('the server certificate cannot be verified; with an internal certificate authority put its certificate (PEM) into the ca folder and restart the services'),
            52, 56 => __('the server closed the connection without an answer'),
            default => $code !== null ? __('connection error (cURL :code)', ['code' => $code]) : __('connection error'),
        };
    }
}

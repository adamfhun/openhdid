<?php

namespace App\Support;

use GuzzleHttp\ProxyOptions;
use GuzzleHttp\Psr7\Uri;
use Throwable;

/**
 * The proxy of the application's outbound HTTP(S) requests (master data,
 * Exchange, SMS gateway, ADFS and Entra), set once on the shared HTTP client:
 * HDID_HTTP_PROXY, except for the HDID_NO_PROXY hosts and this machine.
 * Without it every request goes direct, whatever HTTP_PROXY or HTTPS_PROXY
 * the process inherited, so the web server and the workers behave alike.
 * The address may carry a password: only its scheme, host and port are shown.
 */
final class OutboundProxy
{
    /** This machine is never reached through the proxy. */
    public const ALWAYS_DIRECT = ['localhost', '127.0.0.0/8', '::1'];

    /** Proxy schemes the cURL handler speaks. */
    private const SCHEMES = ['http', 'https', 'socks4', 'socks4a', 'socks5', 'socks5h'];

    /**
     * Request options for every outbound request. An empty proxy switches
     * the proxy variables of the environment off as well.
     *
     * @return array{proxy: string|array{http: string, https: string, no: list<string>}}
     */
    public static function options(): array
    {
        $proxy = self::address();

        if ($proxy === null) {
            return ['proxy' => ''];
        }

        return ['proxy' => ['http' => $proxy, 'https' => $proxy, 'no' => [...self::ALWAYS_DIRECT, ...self::exceptions()]]];
    }

    /**
     * Whether a request to this address goes through the proxy.
     */
    public static function routes(?string $url): bool
    {
        if (self::address() === null || ! is_string($url) || $url === '') {
            return false;
        }

        try {
            return ! ProxyOptions::isUriInNoProxy(new Uri($url), [...self::ALWAYS_DIRECT, ...self::exceptions()]);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The proxy as it may be shown (scheme, host, port), or null without one.
     */
    public static function display(): ?string
    {
        $proxy = self::address();

        if ($proxy === null) {
            return null;
        }

        $parts = parse_url($proxy);

        if ($parts === false || empty($parts['host'])) {
            return __('an invalid address');
        }

        return strtolower($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Why HDID_HTTP_PROXY cannot be used, or null when it can (or is empty).
     * The value itself is never part of the answer.
     */
    public static function problem(): ?string
    {
        $proxy = self::address();

        if ($proxy === null) {
            return null;
        }

        $parts = parse_url($proxy);
        $valid = $parts !== false
            && in_array(strtolower($parts['scheme'] ?? ''), self::SCHEMES, true)
            && ! empty($parts['host'])
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);

        return $valid ? null : __('HDID_HTTP_PROXY is not a proxy address such as http://proxy.example.org:3128 (the value is not shown, it may contain a password).');
    }

    /**
     * The HDID_NO_PROXY entries.
     *
     * @return list<string>
     */
    public static function exceptions(): array
    {
        return array_values(array_filter(
            preg_split('/[\s,]+/', (string) config('hdid.http.no_proxy')) ?: [],
            fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * The configured proxy; without a scheme it is an HTTP proxy, as for cURL.
     */
    private static function address(): ?string
    {
        $proxy = trim((string) config('hdid.http.proxy'));

        if ($proxy === '') {
            return null;
        }

        return str_contains($proxy, '://') ? $proxy : 'http://'.$proxy;
    }
}

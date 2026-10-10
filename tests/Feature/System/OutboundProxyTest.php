<?php

use App\Support\OutboundProxy;
use App\Sync\ConnectionFailure;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * A PHP built-in server standing in for the proxy and for an internal server
 * at once: through a proxy cURL asks for the absolute address, directly only
 * for the path, and the answer echoes which of the two arrived.
 *
 * @return array{0: resource, 1: int, 2: string}
 */
function outboundEchoServer(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $router = sys_get_temp_dir().'/hdid-echo-'.bin2hex(random_bytes(4)).'.php';
    file_put_contents($router, '<?php header("Content-Type: application/json"); echo json_encode(["uri" => $_SERVER["REQUEST_URI"], "auth" => $_SERVER["HTTP_PROXY_AUTHORIZATION"] ?? null]);');

    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    for ($attempt = 0; $attempt < 100; $attempt++) {
        if (($socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1)) !== false) {
            fclose($socket);
            break;
        }
        usleep(50_000);
    }

    return [$server, $port, $router];
}

it('sends outbound requests through HDID_HTTP_PROXY except to this machine and the HDID_NO_PROXY hosts', function (): void {
    [$server, $port, $router] = outboundEchoServer();

    try {
        config(['hdid.http.proxy' => "http://hdid:s3%40cr%24t@127.0.0.1:{$port}", 'hdid.http.no_proxy' => 'internal.test, 10.0.0.0/8']);
        $internalName = ['curl' => [CURLOPT_RESOLVE => ["emd.internal.test:{$port}:127.0.0.1"]]];

        expect(Http::get('http://directory.example.org/export?page=1')->json())
            ->toBe(['uri' => 'http://directory.example.org/export?page=1', 'auth' => 'Basic '.base64_encode('hdid:s3@cr$t')])
            ->and(Http::get("http://127.0.0.1:{$port}/local")->json('uri'))->toBe('/local')
            ->and(Http::withOptions($internalName)->get("http://emd.internal.test:{$port}/internal")->json('uri'))->toBe('/internal');

        config(['hdid.http.no_proxy' => null]);

        expect(Http::withOptions($internalName)->get("http://emd.internal.test:{$port}/internal")->json('uri'))
            ->toBe("http://emd.internal.test:{$port}/internal");
    } finally {
        proc_terminate($server);
        @unlink($router);
    }
});

it('goes direct without HDID_HTTP_PROXY even when the environment names a proxy', function (): void {
    [$server, $port, $router] = outboundEchoServer();
    putenv('http_proxy=http://127.0.0.1:9');

    try {
        config(['hdid.http.proxy' => null]);

        expect(fn () => app(Factory::class)->withoutGlobalConfiguration(fn () => Http::timeout(2)->get("http://127.0.0.1:{$port}/direct")))
            ->toThrow(ConnectionException::class)
            ->and(Http::get("http://127.0.0.1:{$port}/direct")->json('uri'))->toBe('/direct');
    } finally {
        putenv('http_proxy');
        proc_terminate($server);
        @unlink($router);
    }
});

it('shows the proxy without its password and knows which addresses it carries', function (): void {
    config(['hdid.http.proxy' => 'http://hdid:s3cret@proxy.example.org:3128', 'hdid.http.no_proxy' => '.internal.test,10.0.0.0/8 emd.example.org']);

    expect(OutboundProxy::display())->toBe('http://proxy.example.org:3128')
        ->and(OutboundProxy::problem())->toBeNull()
        ->and(OutboundProxy::exceptions())->toBe(['.internal.test', '10.0.0.0/8', 'emd.example.org'])
        ->and(OutboundProxy::routes('https://login.microsoftonline.com/tenant/v2.0'))->toBeTrue()
        ->and(OutboundProxy::routes('https://adfs.internal.test/adfs'))->toBeFalse()
        ->and(OutboundProxy::routes('https://10.1.2.3:8443/api'))->toBeFalse()
        ->and(OutboundProxy::routes('https://emd.example.org/export'))->toBeFalse()
        ->and(OutboundProxy::routes('http://localhost:9501/api'))->toBeFalse()
        ->and(OutboundProxy::routes('http://127.0.0.1:9501/api'))->toBeFalse();

    config(['hdid.http.proxy' => null]);

    expect(OutboundProxy::display())->toBeNull()
        ->and(OutboundProxy::routes('https://login.microsoftonline.com/tenant/v2.0'))->toBeFalse()
        ->and(OutboundProxy::options())->toBe(['proxy' => '']);
});

it('reads a proxy without a scheme as an HTTP proxy and refuses an address cURL cannot use', function (): void {
    config(['hdid.http.proxy' => 'proxy.example.org:3128']);

    expect(OutboundProxy::problem())->toBeNull()
        ->and(OutboundProxy::display())->toBe('http://proxy.example.org:3128');

    foreach (['ftp://hdid:s3cret@proxy.example.org:21', 'http://proxy.example.org:3128/path', 'http://:3128', 'http://proxy.example.org:3128?x=1'] as $unusable) {
        config(['hdid.http.proxy' => $unusable]);

        expect(OutboundProxy::problem())->toContain('HDID_HTTP_PROXY')->not->toContain('s3cret');
    }
});

it('shows the outbound proxy on the status page without its password', function (): void {
    $checks = app(HealthChecks::class);

    expect(collect($checks->all())->pluck('key'))->toContain('proxy')
        ->and($checks->outboundProxy()->status)->toBe(CheckStatus::Ok)
        ->and($checks->outboundProxy()->detail)->toBe('None: outbound requests go direct.');

    config(['hdid.http.proxy' => 'http://hdid:s3cret@proxy.example.org:3128', 'hdid.http.no_proxy' => 'internal.test']);

    expect($checks->outboundProxy()->status)->toBe(CheckStatus::Ok)
        ->and($checks->outboundProxy()->detail)->toBe('Outbound requests go through http://proxy.example.org:3128; direct: localhost, 127.0.0.0/8, ::1, internal.test.');

    config(['hdid.http.proxy' => 'ftp://hdid:s3cret@proxy.example.org']);

    expect($checks->outboundProxy()->status)->toBe(CheckStatus::Fail)
        ->and($checks->outboundProxy()->detail)->not->toContain('s3cret');
});

it('explains a master data connection failure through the proxy', function (): void {
    config(['hdid.http.proxy' => 'http://hdid:s3cret@proxy.example.org:3128', 'hdid.http.no_proxy' => 'internal.test']);
    $describe = fn (string $message, string $url = 'https://directory.example.org/export'): string => ConnectionFailure::describe(new ConnectionException($message), $url);

    expect($describe('cURL error 56: CONNECT tunnel failed, response 407'))
        ->toStartWith('directory.example.org:443 via the proxy http://proxy.example.org:3128: ')
        ->toContain('user name and password')
        ->not->toContain('s3cret')
        ->and($describe('cURL error 56: CONNECT tunnel failed, response 403'))->toContain('does not allow this address')
        ->and($describe('cURL error 56: Received HTTP code 502 from proxy after CONNECT'))->toContain('HDID_NO_PROXY')
        ->and($describe('cURL error 7: Failed to connect to proxy.example.org port 3128'))->toContain('the proxy refused the connection')
        ->and($describe('cURL error 5: Could not resolve proxy: proxy.example.org'))->toContain('proxy name does not resolve')
        ->and($describe('cURL error 60: SSL certificate problem: unable to get local issuer certificate'))->toContain('ca folder')
        ->and($describe('cURL error 6: Could not resolve host: emd.internal.test', 'https://emd.internal.test/export'))
        ->toBe('emd.internal.test:443: '.__('the name does not resolve inside the container (DNS); the container does not see the host /etc/hosts, so a name listed only there needs an extra_hosts entry in compose.override.yaml, and an internal DNS the host reaches through a local resolver needs the dns key of /etc/docker/daemon.json'));
});

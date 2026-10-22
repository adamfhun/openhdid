<?php

use App\Mail\Transport\EwsTransport;
use App\Models\OutboundMessage;
use App\System\CheckStatus;
use App\System\HealthChecks;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mail.default', 'ews');
    config()->set('hdid.ews', ['url' => 'https://mail.example.test/EWS/Exchange.asmx', 'auth' => 'basic', 'username' => 'svc', 'password' => 'secret', 'oauth' => [], 'verify_tls' => true]);
});

function testMailError(): string
{
    test()->artisan('hdid:test-mail', ['address' => 'ops@example.test', '--now' => true])->assertFailed();

    return (string) OutboundMessage::query()->latest('id')->firstOrFail()->error;
}

it('names the status and the offered authentication methods when Exchange refuses the sign-in with an empty body', function (): void {
    Http::fake(['https://mail.example.test/*' => Http::response('', 401, ['WWW-Authenticate' => 'Negotiate, NTLM'])]);

    $error = testMailError();

    expect($error)->toContain('HTTP 401')
        ->and($error)->toContain('the server offers: Negotiate, NTLM')
        ->and($error)->toContain('empty response body');
});

it('reports the EWS response code and message of a SOAP error answer', function (): void {
    Http::fake(['https://mail.example.test/*' => Http::response(<<<'XML'
<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><m:CreateItemResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages">
<m:ResponseMessages><m:CreateItemResponseMessage ResponseClass="Error"><m:MessageText>The SMTP address has no mailbox associated with it.</m:MessageText><m:ResponseCode>ErrorNonExistentMailbox</m:ResponseCode></m:CreateItemResponseMessage></m:ResponseMessages>
</m:CreateItemResponse></s:Body></s:Envelope>
XML, 200)]);

    $error = testMailError();

    expect($error)->toContain('HTTP 200')
        ->and($error)->toContain('EWS ErrorNonExistentMailbox')
        ->and($error)->toContain('The SMTP address has no mailbox associated with it.');
});

it('tells when the answer is a sign-in page instead of EWS, and when the server is unreachable', function (): void {
    Http::fake(['https://mail.example.test/*' => Http::response('<html><body><form>Sign in</form></body></html>', 200, ['Content-Type' => 'text/html'])]);
    expect(testMailError())->toContain('HTML answer (sign-in page or proxy?)');

    Http::fake(['https://mail.example.test/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect')]);
    expect(testMailError())->toContain('could not be reached')->toContain('EWS_URL');
});

it('refuses ntlm with a clear message where the libcurl has no NTLM, and the status page says the same', function (): void {
    config()->set('hdid.ews.auth', 'ntlm');
    Http::fake();
    EwsTransport::$ntlmSupported = false;

    try {
        expect(testMailError())->toContain('no NTLM support')->toContain('MAIL_MAILER=smtp')
            ->and(app(HealthChecks::class)->mail()->status)->toBe(CheckStatus::Fail)
            ->and(app(HealthChecks::class)->mail()->detail)->toContain('no NTLM support');
        Http::assertNothingSent();

        EwsTransport::$ntlmSupported = true;
        expect(app(HealthChecks::class)->mail()->status)->toBe(CheckStatus::Ok);
    } finally {
        EwsTransport::$ntlmSupported = null;
    }
});

/**
 * A stand-in Exchange on a local port, answering like IIS with Windows authentication: a request
 * without credentials or with an NTLM type-1 message gets 401 and an NTLM challenge (type-2), a
 * type-3 message gets a successful CreateItem answer. It keeps the connection open, as NTLM binds
 * the authentication to the connection, and logs every request as a JSON line (authorization
 * header, body length).
 *
 * @return array{0: resource, 1: int, 2: string, 3: string}
 */
function fakeNtlmExchange(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $id = bin2hex(random_bytes(4));
    $log = sys_get_temp_dir().'/hdid-ntlm-'.$id.'.log';
    $script = sys_get_temp_dir().'/hdid-ntlm-'.$id.'.php';
    file_put_contents($script, <<<'PHP'
<?php
[$port, $log] = [(int) $argv[1], $argv[2]];
$type2 = "NTLMSSP\0".pack('V', 2).pack('vvV', 0, 0, 32).pack('V', 0x00008201)."\x01\x23\x45\x67\x89\xab\xcd\xef";
$server = stream_socket_server("tcp://127.0.0.1:{$port}");
while (($client = @stream_socket_accept($server, 30)) !== false) {
    while (($head = stream_get_line($client, 65536, "\r\n\r\n")) !== false && $head !== '') {
        preg_match('/^Authorization: *(.*)$/mi', $head, $auth);
        preg_match('/^Content-Length: *(\d+)$/mi', $head, $length);
        $body = '';
        while (strlen($body) < (int) ($length[1] ?? 0) && ($chunk = fread($client, (int) $length[1] - strlen($body))) !== false && $chunk !== '') {
            $body .= $chunk;
        }
        $authorization = trim($auth[1] ?? '');
        file_put_contents($log, json_encode(['authorization' => $authorization, 'length' => strlen($body)]).PHP_EOL, FILE_APPEND);
        if (str_starts_with($authorization, 'NTLM TlRMTVNTUAAD')) {
            $answer = '<m:CreateItemResponseMessage ResponseClass="Success"/>';
            fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/xml\r\nContent-Length: ".strlen($answer)."\r\n\r\n".$answer);
        } else {
            $challenge = $authorization === '' ? '' : ' '.base64_encode($type2);
            fwrite($client, "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: NTLM{$challenge}\r\nContent-Length: 0\r\n\r\n");
        }
    }
    fclose($client);
}
PHP);

    $server = proc_open([PHP_BINARY, $script, (string) $port, $log], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    for ($attempt = 0; $attempt < 100; $attempt++) {
        if (($socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1)) !== false) {
            fclose($socket);
            break;
        }
        usleep(50_000);
    }

    return [$server, $port, $script, $log];
}

it('runs the NTLM handshake itself with EWS_AUTH=ntlm: type-1 without a body, type-3 with the SOAP body', function (): void {
    if (! (curl_version()['features'] & CURL_VERSION_NTLM)) {
        test()->markTestSkipped('the libcurl of this PHP has no NTLM');
    }

    [$server, $port, $script, $log] = fakeNtlmExchange();
    config()->set('hdid.ews', ['url' => "http://127.0.0.1:{$port}/EWS/Exchange.asmx", 'auth' => 'ntlm', 'username' => 'DOMAIN\\svc', 'password' => 'secret', 'oauth' => [], 'verify_tls' => true]);

    try {
        $this->artisan('hdid:test-mail', ['address' => 'ops@example.test', '--now' => true])->assertSuccessful();
        expect(OutboundMessage::query()->latest('id')->firstOrFail()->error)->toBeNull();

        $requests = array_values(array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_filter(explode("\n", (string) file_get_contents($log)))));
        $negotiations = array_values(array_filter($requests, fn (array $request): bool => str_starts_with($request['authorization'], 'NTLM ')));

        // Guzzle 8 silently dropped the `auth => [..., 'ntlm']` option: every request went out
        // without credentials and Exchange answered 401 "NTLM" to each one.
        expect($negotiations)->toHaveCount(2)
            ->and($negotiations[0]['authorization'])->toStartWith('NTLM TlRMTVNTUAAB')
            ->and($negotiations[0]['length'])->toBe(0)
            ->and($negotiations[1]['authorization'])->toStartWith('NTLM TlRMTVNTUAAD')
            ->and($negotiations[1]['length'])->toBeGreaterThan(1000)
            ->and(end($requests))->toBe($negotiations[1]);
    } finally {
        proc_terminate($server);
        @unlink($script);
        @unlink($log);
    }
});

it('accepts a successful CreateItem answer', function (): void {
    Http::fake(['https://mail.example.test/*' => Http::response('<m:CreateItemResponseMessage ResponseClass="Success"/>', 200)]);

    $this->artisan('hdid:test-mail', ['address' => 'ops@example.test', '--now' => true])->assertSuccessful();
    expect(OutboundMessage::query()->latest('id')->firstOrFail()->error)->toBeNull();
});

it('describes a response on its own', function (): void {
    expect(EwsTransport::describe(new Illuminate\Http\Client\Response(new Response(503, [], ''))))->toBe('HTTP 503 Service Unavailable · empty response body');
});

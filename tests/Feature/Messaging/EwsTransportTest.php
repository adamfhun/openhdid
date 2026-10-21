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

it('accepts a successful CreateItem answer', function (): void {
    Http::fake(['https://mail.example.test/*' => Http::response('<m:CreateItemResponseMessage ResponseClass="Success"/>', 200)]);

    $this->artisan('hdid:test-mail', ['address' => 'ops@example.test', '--now' => true])->assertSuccessful();
    expect(OutboundMessage::query()->latest('id')->firstOrFail()->error)->toBeNull();
});

it('describes a response on its own', function (): void {
    expect(EwsTransport::describe(new Illuminate\Http\Client\Response(new Response(503, [], ''))))->toBe('HTTP 503 Service Unavailable · empty response body');
});

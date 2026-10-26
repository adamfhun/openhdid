<?php

use App\Mail\TemplatedMail;
use App\Mail\Transport\EwsTransport;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Models\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Exchange keeps a copy of every SendAndSaveCopy message in the sending
 * mailbox's Sent Items. A login link or a sign-in code must not stay there
 * (the application wipes its own copy once sent), so every message goes out
 * SendOnly unless its mailable marks it as carrying no secret; that one keeps
 * its saved copy. The internal marker never reaches Exchange.
 */
beforeEach(function (): void {
    config()->set('mail.default', 'ews');
    config()->set('hdid.ews', ['url' => 'https://mail.example.test/EWS/Exchange.asmx', 'auth' => 'basic', 'username' => 'svc', 'password' => 'secret', 'oauth' => [], 'verify_tls' => true]);
    Http::fake(['https://mail.example.test/*' => Http::response('<m:CreateItemResponseMessage ResponseClass="Success"/>', 200)]);
});

/**
 * The SOAP body of the only CreateItem request, and the MIME message inside it.
 *
 * @return array{0: string, 1: string}
 */
function sentEwsRequest(): array
{
    $requests = Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://mail.example.test/'));
    expect($requests)->toHaveCount(1);

    $soap = $requests->first()[0]->body();
    preg_match('#<t:MimeContent CharacterSet="UTF-8">([^<]*)</t:MimeContent>#', $soap, $mime);

    return [$soap, quoted_printable_decode((string) base64_decode($mime[1] ?? '', true))];
}

it('sends a login link without a copy in the Sent Items of the mailbox', function (): void {
    Client::factory()->synced()->create(['email' => 'victim@x.hu']);

    $this->withHeader('Referer', config('app.url'))
        ->postJson('/api/v1/client/auth/magic-link', ['email' => 'victim@x.hu'])
        ->assertOk();

    [$soap, $mime] = sentEwsRequest();

    expect($soap)->toContain('<m:CreateItem MessageDisposition="SendOnly">')
        ->and($soap)->not->toContain('SendAndSaveCopy')
        ->and($soap)->not->toContain('SavedItemFolderId')
        ->and($soap)->not->toContain('sentitems')
        ->and($mime)->toContain('#token=')
        ->and($mime)->not->toContain('X-HDID-');
});

it('sends a staff sign-in code by e-mail without a saved copy', function (): void {
    // The call StaffMfa::sendCode makes for an e-mail code.
    app(Messenger::class)->sendRaw(Channel::Email, 'agent@corp.hu', 'Sign-in code', 'Your sign-in code: 123456.', carriesSecret: true);

    [$soap, $mime] = sentEwsRequest();

    expect($soap)->toContain('MessageDisposition="SendOnly"')
        ->and($soap)->not->toContain('sentitems')
        ->and($mime)->toContain('123456')
        ->and($mime)->not->toContain('X-HDID-');
});

it('keeps the saved copy of a message that carries no secret', function (): void {
    Mail::mailer('ews')->to('ops@example.test')->send(new TemplatedMail('A notice', '<p>Nothing secret.</p>', carriesSecret: false));

    [$soap, $mime] = sentEwsRequest();

    expect($soap)->toContain('<m:CreateItem MessageDisposition="SendAndSaveCopy">')
        ->and($soap)->toContain('<t:DistinguishedFolderId Id="sentitems"/>')
        ->and($mime)->toContain('Nothing secret.')
        ->and($mime)->not->toContain('X-HDID-');
});

it('keeps the saved copy of a queued message without a secret, such as a test mail', function (): void {
    // The call hdid:test-mail and the console account warnings make.
    app(Messenger::class)->sendRaw(Channel::Email, 'ops@example.test', 'HDID test', '<p>Nothing secret.</p>');

    [$soap, $mime] = sentEwsRequest();

    expect($soap)->toContain('<m:CreateItem MessageDisposition="SendAndSaveCopy">')
        ->and($soap)->toContain('<t:DistinguishedFolderId Id="sentitems"/>')
        ->and($mime)->toContain('Nothing secret.')
        ->and($mime)->not->toContain('X-HDID-');
});

it('gives the internal marker only to a mailer that sends through EWS', function (): void {
    config()->set('mail.default', 'array');
    Mail::to('client@x.hu')->send(new TemplatedMail('A notice', '<p>Nothing secret.</p>', carriesSecret: false));

    $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1)
        ->and($sent->first()->toString())->toContain('Nothing secret.')
        ->and($sent->first()->toString())->not->toContain('X-HDID-')
        ->and(EwsTransport::keepSavedCopyHeaders('smtp'))->toBe([])
        ->and(EwsTransport::keepSavedCopyHeaders('ews'))->toBe([EwsTransport::KEEP_SAVED_COPY_HEADER => '1']);

    // A failover mailer with an EWS leg: EWS still has to see the marker.
    config()->set('mail.mailers.failover.mailers', ['ews', 'smtp']);
    expect(EwsTransport::keepSavedCopyHeaders('failover'))->toBe([EwsTransport::KEEP_SAVED_COPY_HEADER => '1']);
});

it('keeps no copy of a mail whose mailable says nothing, such as a new mailable or a framework notification', function (): void {
    Mail::mailer('ews')->to('ops@example.test')->send(new class extends Mailable
    {
        public function envelope(): Envelope
        {
            return new Envelope(subject: 'Reset your password');
        }

        public function content(): Content
        {
            return new Content(htmlString: '<p>https://hdid.test/reset/abc123</p>');
        }
    });

    [$soap, $mime] = sentEwsRequest();

    expect($soap)->toContain('MessageDisposition="SendOnly"')
        ->and($soap)->not->toContain('sentitems')
        ->and($mime)->toContain('abc123');
});

it('keeps the Exchange access token of one app registration apart from another installation\'s', function (): void {
    config()->set('hdid.ews', ['url' => 'https://mail.example.test/EWS/Exchange.asmx', 'auth' => 'oauth', 'username' => null, 'password' => null, 'oauth' => ['tenant' => 'tenant-a', 'client_id' => 'app-a', 'client_secret' => 's'], 'verify_tls' => true]);
    // What another installation on the same cache left under the old shared key.
    Cache::put('ews.oauth.token', 'token-of-another-installation', 3000);
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'token-a'], 200),
        'https://mail.example.test/*' => Http::response('<m:CreateItemResponseMessage ResponseClass="Success"/>', 200),
    ]);

    Mail::mailer('ews')->to('ops@example.test')->send(new TemplatedMail('A notice', '<p>Nothing secret.</p>', carriesSecret: false));

    $ews = Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), 'https://mail.example.test/'))->first()[0];
    expect($ews->header('Authorization')[0] ?? null)->toBe('Bearer token-a');
});

it('treats a templated mail as secret unless it is told otherwise', function (): void {
    Mail::mailer('ews')->to('ops@example.test')->send(new TemplatedMail('A code', '<p>Code: 654321</p>'));

    [$soap] = sentEwsRequest();

    expect($soap)->toContain('MessageDisposition="SendOnly"');
});

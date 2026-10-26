<?php

use App\Enums\OutboundMessageStatus;
use App\Jobs\SendOutboundMessage;
use App\Mail\TemplatedMail;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Models\OutboundMessage;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * SMTP on port 587 only encrypts when the server offers STARTTLS. Someone in
 * the network path who removes the offer would otherwise receive the SMTP
 * password and every e-mail (login links, sign-in codes) in plain text, and
 * the message would still count as sent. STARTTLS is therefore required
 * unless MAIL_REQUIRE_TLS=false, and the status page says when it is off.
 */

/**
 * A stand-in SMTP server on a local port that offers AUTH LOGIN but no
 * STARTTLS, accepts everything and logs every line it receives.
 *
 * @return array{0: resource, 1: int, 2: string, 3: string}
 */
function startSmtpServerWithoutStarttls(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $id = bin2hex(random_bytes(4));
    $log = sys_get_temp_dir().'/hdid-smtp-'.$id.'.log';
    $script = sys_get_temp_dir().'/hdid-smtp-'.$id.'.php';
    file_put_contents($log, '');
    file_put_contents($script, <<<'PHP'
<?php
[$port, $log] = [(int) $argv[1], $argv[2]];
$server = stream_socket_server("tcp://127.0.0.1:{$port}");
while (($client = @stream_socket_accept($server, 30)) !== false) {
    fwrite($client, "220 fake ESMTP\r\n");
    [$state, $authLines] = ['command', 0];
    while (($line = fgets($client)) !== false) {
        file_put_contents($log, $line, FILE_APPEND);
        if ($state === 'data') {
            if (rtrim($line, "\r\n") === '.') {
                $state = 'command';
                fwrite($client, "250 queued\r\n");
            }
            continue;
        }
        if ($state === 'auth') {
            $authLines++;
            fwrite($client, $authLines === 1 ? "334 UGFzc3dvcmQ6\r\n" : "235 accepted\r\n");
            $state = $authLines === 1 ? 'auth' : 'command';
            continue;
        }
        $verb = strtoupper(substr($line, 0, 4));
        if ($verb === 'EHLO') {
            fwrite($client, "250-fake\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n");
        } elseif ($verb === 'AUTH') {
            [$state, $authLines] = ['auth', 0];
            fwrite($client, "334 VXNlcm5hbWU6\r\n");
        } elseif ($verb === 'DATA') {
            $state = 'data';
            fwrite($client, "354 go on\r\n");
        } elseif ($verb === 'QUIT') {
            fwrite($client, "221 bye\r\n");
            break;
        } else {
            fwrite($client, "250 ok\r\n");
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

/**
 * The smtp mailer as config/mail.php builds it with the given environment.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function smtpMailerWithEnv(array $env): array
{
    $saved = [];
    foreach ($env as $key => $value) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        return (require config_path('mail.php'))['mailers']['smtp'];
    } finally {
        foreach ($saved as $key => [$envValue, $serverValue]) {
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }
        }
    }
}

it('requires STARTTLS on the smtp mailer unless MAIL_REQUIRE_TLS=false', function (): void {
    expect(config('mail.mailers.smtp.require_tls'))->toBeTrue()
        ->and(Mail::mailer('smtp')->getSymfonyTransport()->isTlsRequired())->toBeTrue()
        ->and(smtpMailerWithEnv(['MAIL_REQUIRE_TLS' => 'false'])['require_tls'])->toBeFalse()
        ->and(smtpMailerWithEnv(['MAIL_REQUIRE_TLS' => 'true'])['require_tls'])->toBeTrue()
        // Only an explicit false turns it off: an emptied or garbled line keeps STARTTLS required.
        ->and(smtpMailerWithEnv(['MAIL_REQUIRE_TLS' => ''])['require_tls'])->toBeTrue()
        ->and(smtpMailerWithEnv(['MAIL_REQUIRE_TLS' => 'garbage'])['require_tls'])->toBeTrue();
});

it('resolves the sample failover and round-robin chains by mailer name', function (): void {
    expect(config('mail.mailers.failover.mailers'))->toBe(['ews', 'smtp', 'log'])
        ->and(collect(config('mail.mailers.roundrobin.mailers'))->every(fn (mixed $leg): bool => is_string($leg)))->toBeTrue();
});

it('says on the message and on the e-mail tile that the SMTP server offers no STARTTLS', function (): void {
    [$server, $port, $script, $log] = startSmtpServerWithoutStarttls();
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp', array_merge(config('mail.mailers.smtp'), [
        'scheme' => null, 'host' => '127.0.0.1', 'port' => $port, 'username' => 'hdid-mailer', 'password' => 'Smtp-Secret-123',
    ]));
    Queue::fake();

    try {
        expect(app(HealthChecks::class)->mail()->status)->toBe(CheckStatus::Ok);

        $message = app(Messenger::class)->sendRaw(Channel::Email, 'client@x.hu', 'Test', '<p>Test</p>');
        for ($i = 0; $i <= OutboundMessage::MAX_ATTEMPTS; $i++) {
            app()->call([new SendOutboundMessage($message->id), 'handle']);
        }
        $message->refresh();

        expect($message->status)->toBe(OutboundMessageStatus::Failed)
            ->and($message->error)->toContain('offers no STARTTLS')
            ->and($message->error)->toContain('MAIL_REQUIRE_TLS')
            ->and($message->error)->toContain('MAIL_SCHEME=smtps');
        $tile = app(HealthChecks::class)->mail();
        expect($tile->status)->toBe(CheckStatus::Fail)
            ->and($tile->detail)->toContain('offers no STARTTLS')
            ->and(HealthChecks::hint('mail'))->toContain('resend the failed messages');
    } finally {
        proc_terminate($server);
        @unlink($script);
        @unlink($log);
    }
});

it('sends neither the password nor the mail to an SMTP server that offers no STARTTLS', function (): void {
    [$server, $port, $script, $log] = startSmtpServerWithoutStarttls();
    config()->set('mail.mailers.smtp', array_merge(config('mail.mailers.smtp'), [
        'scheme' => null, 'host' => '127.0.0.1', 'port' => $port, 'username' => 'hdid-mailer', 'password' => 'Smtp-Secret-123',
    ]));

    try {
        expect(fn () => Mail::mailer('smtp')->to('client@x.hu')->send(new TemplatedMail('Login link', '<p>#token=abc123</p>')))
            ->toThrow(TransportException::class, 'TLS required');

        $received = (string) file_get_contents($log);
        expect($received)->toContain('EHLO')
            ->and($received)->not->toContain(base64_encode('Smtp-Secret-123'))
            ->and($received)->not->toContain('AUTH')
            ->and($received)->not->toContain('token=abc123');
    } finally {
        proc_terminate($server);
        @unlink($script);
        @unlink($log);
    }
});

it('shows the e-mail tile yellow with the key to change while SMTP may send unencrypted', function (): void {
    $checks = app(HealthChecks::class);
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.scheme', null);
    config()->set('mail.mailers.smtp.port', 587);

    expect($checks->mail()->status)->toBe(CheckStatus::Ok);

    config()->set('mail.mailers.smtp.require_tls', false);
    expect($checks->mail()->status)->toBe(CheckStatus::Warn)
        ->and($checks->mail()->detail)->toContain('MAIL_REQUIRE_TLS=false')
        ->and(HealthChecks::hint('mail'))->toContain('MAIL_REQUIRE_TLS');

    // Direct TLS (smtps, or port 465 without a scheme) is encrypted from the first byte.
    config()->set('mail.mailers.smtp.scheme', 'smtps');
    expect($checks->mail()->status)->toBe(CheckStatus::Ok);
    config()->set('mail.mailers.smtp.scheme', null);
    config()->set('mail.mailers.smtp.port', 465);
    expect($checks->mail()->status)->toBe(CheckStatus::Ok);

    // A failover chain with an SMTP leg is judged by that leg too.
    config()->set('mail.mailers.smtp.port', 587);
    config()->set('mail.default', 'failover');
    expect($checks->mail()->status)->toBe(CheckStatus::Warn);
});

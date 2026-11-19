<?php

use App\Mail\TemplatedMail;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Models\OutboundMessage;
use App\System\ErrorMail;
use App\System\HealthChecks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/**
 * A queued job that always fails, for the worker's report.
 */
class ErrorMailTestFailingJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('The job failed on purpose');
    }
}

beforeEach(function (): void {
    Mail::fake();
    config(['hdid.error_mail.to' => 'ops@example.test', 'hdid.error_mail.statuses' => '']);

    // POST routes: the portal's catch-all GET route was registered first.
    Route::post('/_error-mail/throw', fn () => throw new RuntimeException('Something broke, password=hunter2'));
    Route::post('/_error-mail/abort/{status}', fn (int $status) => abort($status));
    Route::post('/_error-mail/answer/{status}', fn (int $status) => response('plain answer', $status));
    Route::post('/_error-mail/handled', function () {
        report(new RuntimeException('Handled and reported'));

        return 'fine';
    });
    Route::post('/_error-mail/query', fn () => DB::select('select * from error_mail_no_such_table where email = ?', ['bound.secret@example.test']));
});

/**
 * The HTTP 500 Reports handed to the mailer.
 *
 * @return Collection<int, TemplatedMail>
 */
function errorReports(): Collection
{
    return Mail::sent(TemplatedMail::class)->filter(fn (TemplatedMail $mail): bool => str_contains($mail->subjectLine, 'Server error'))->values();
}

it('sends nothing while no address is set', function (): void {
    config(['hdid.error_mail.to' => '']);

    $this->postJson('/_error-mail/throw')->assertStatus(500);
    report(new RuntimeException('Outside a request'));

    expect(errorReports())->toBeEmpty()
        ->and(OutboundMessage::query()->count())->toBe(0);
});

it('reports an unexpected error of a web request once, in full, with the secrets masked', function (): void {
    $this->withHeaders(['Authorization' => 'Bearer bearer-secret-value', 'X-Api-Key' => 'api-header-secret', 'Cookie' => 'hdid_session=cookie-secret'])
        ->postJson('/_error-mail/throw?api_key=query-secret&term=hello', [
            'name' => 'Kovács Anna',
            'password' => 'input-password-secret',
            'nested' => ['pin' => '482913', 'answers' => ['q1' => 'mother maiden name']],
            'components' => [[
                'snapshot' => json_encode(['data' => ['pin' => '771166', 'note' => 'visible note'], 'memo' => ['name' => 'set-pin-dialog']]),
                'calls' => [['method' => 'save']],
            ]],
        ])
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Server Error']);

    $reports = errorReports();
    expect($reports)->toHaveCount(1);

    $mail = $reports->first();
    expect($mail->hasTo('ops@example.test'))->toBeTrue()
        ->and($mail->subjectLine)->toContain('(500)', 'RuntimeException')
        ->and($mail->htmlBody)->toContain(
            'RuntimeException', 'Something broke, password=***', 'tests/Feature/System/ErrorMailTest.php',
            'POST', '/_error-mail/throw', 'term=hello', 'api_key=***', 'Kovács Anna', 'visible note',
            'set-pin-dialog → save', 'web request', '#0 ',
        )
        ->and($mail->htmlBody)->not->toContain(
            'hunter2', 'query-secret', 'bearer-secret-value', 'api-header-secret', 'cookie-secret',
            'input-password-secret', '482913', 'mother maiden name', '771166',
        );

    // One row per address, its body wiped once out (personal data), marked as a report.
    $message = OutboundMessage::query()->sole();
    expect($message->channel)->toBe(Channel::Email)
        ->and($message->body)->toBe('')
        ->and($message->redacted_at)->not->toBeNull();
});

it('sends one report per address and leaves out an entry that is not an address', function (): void {
    config(['hdid.error_mail.to' => 'ops@example.test, second@example.test not-an-address']);

    $this->postJson('/_error-mail/throw')->assertStatus(500);

    expect(errorReports())->toHaveCount(2)
        ->and(app(ErrorMail::class)->recipients())->toBe(['ops@example.test', 'second@example.test'])
        ->and(app(ErrorMail::class)->ignoredEntries())->toBe(['HDID_ERROR_MAIL_TO: not-an-address']);
});

it('reports the same error at most once in the repeat window and counts the repeats in the next report', function (): void {
    $this->postJson('/_error-mail/throw')->assertStatus(500);
    $this->postJson('/_error-mail/throw')->assertStatus(500);
    $this->postJson('/_error-mail/throw')->assertStatus(500);

    expect(errorReports())->toHaveCount(1);

    $this->travel(ErrorMail::REPEAT_MINUTES + 1)->minutes();
    $this->postJson('/_error-mail/throw')->assertStatus(500);

    $reports = errorReports();
    expect($reports)->toHaveCount(2)
        ->and($reports->last()->htmlBody)->toContain('This error occurred 2 more time(s) since its last report');
});

it('caps all reports together at the hourly limit and says how many were held back', function (): void {
    $line = new ReflectionProperty(Exception::class, 'line');

    foreach (range(1, ErrorMail::HOURLY_LIMIT + 3) as $index) {
        $exception = new RuntimeException('Distinct error '.$index);
        $line->setValue($exception, 10000 + $index);
        report($exception);
    }

    expect(errorReports())->toHaveCount(ErrorMail::HOURLY_LIMIT);

    $this->travel(61)->minutes();
    $exception = new RuntimeException('After the hour');
    $line->setValue($exception, 20000);
    report($exception);

    expect(errorReports())->toHaveCount(ErrorMail::HOURLY_LIMIT + 1)
        ->and(errorReports()->last()->htmlBody)->toContain('3 report(s) of other errors were held back by the hourly limit');
});

it('reports only the listed 5xx answers, 500 when the list is empty', function (): void {
    $this->post('/_error-mail/abort/503')->assertStatus(503);
    $this->post('/_error-mail/answer/502')->assertStatus(502);
    expect(errorReports())->toBeEmpty();

    config(['hdid.error_mail.statuses' => '500,502, 503']);
    $this->post('/_error-mail/abort/503')->assertStatus(503);
    $this->post('/_error-mail/answer/502')->assertStatus(502);
    $this->post('/_error-mail/abort/504')->assertStatus(504);

    $reports = errorReports();
    expect($reports)->toHaveCount(2)
        ->and($reports[0]->subjectLine)->toContain('(503)', 'HttpException')
        ->and($reports[1]->subjectLine)->toContain('(502)', '502 Bad Gateway')
        ->and($reports[1]->htmlBody)->toContain('no exception behind the answer', '/_error-mail/answer/502');

    // Without 500 in the list an unexpected error sends nothing.
    config(['hdid.error_mail.statuses' => '503']);
    $this->postJson('/_error-mail/throw')->assertStatus(500);
    expect(errorReports())->toHaveCount(2)
        ->and(app(ErrorMail::class)->statuses())->toBe([503]);
});

it('reports an error the request reported and answered without a 5xx as a 500', function (): void {
    $this->post('/_error-mail/handled')->assertOk()->assertSee('fine');

    $reports = errorReports();
    expect($reports)->toHaveCount(1)
        ->and($reports->first()->htmlBody)->toContain('Handled and reported', '(the request was answered with 200)');
});

it('gives a database error its SQL without the bound values', function (): void {
    $this->post('/_error-mail/query')->assertStatus(500);

    $body = errorReports()->sole()->htmlBody;
    expect($body)->toContain('error_mail_no_such_table', 'where email = ?', '(1 bound value(s) left out)')
        ->and($body)->not->toContain('bound.secret@example.test');
});

it('reports an error outside a web request with the command line, options with a secret name masked', function (): void {
    $argv = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['artisan', 'hdid:make-admin', 'admin@example.test', '--password=Sup3r-Secret', '--force'];

    try {
        report(new RuntimeException('Outside a request'));
    } finally {
        $_SERVER['argv'] = $argv;
    }

    $body = errorReports()->sole()->htmlBody;
    expect($body)->toContain('Outside a request', 'command or scheduled task', 'hdid:make-admin admin@example.test --password=*** --force')
        ->and($body)->not->toContain('Sup3r-Secret');
});

it('names the queue job a worker failed on', function (): void {
    ErrorMailTestFailingJob::dispatch()->onConnection('database');

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    $body = errorReports()->sole()->htmlBody;
    expect($body)->toContain('The job failed on purpose', 'queue job', 'ErrorMailTestFailingJob', 'database / default');
});

it('does not report the failed delivery of a report again', function (): void {
    $this->mock(Mailer::class, fn ($mailer) => $mailer->shouldReceive('to')->andThrow(new RuntimeException('Mail server down')));

    // A report whose own delivery fails: logged, no further report.
    app(Messenger::class)->sendRaw(Channel::Email, 'ops@example.test', 'Server error report', '<p>report</p>', carriesSecret: true, meta: ['error_report' => true]);
    expect(OutboundMessage::query()->count())->toBe(1);

    // An ordinary message whose delivery fails is reported, and that report failing ends there.
    app(Messenger::class)->sendRaw(Channel::Email, 'someone@example.test', 'Hello', '<p>hello</p>');
    $reports = OutboundMessage::query()->where('recipient', 'ops@example.test')->get();

    expect(OutboundMessage::query()->count())->toBe(3)
        ->and($reports)->toHaveCount(2)
        ->and($reports->pluck('error')->filter()->all())->each->toContain('Mail server down');
});

it('shows on the status page only whether the report is on, yellow for an entry left out', function (): void {
    $checks = app(HealthChecks::class);

    expect($checks->errorMail()->status->value)->toBe('info')
        ->and($checks->errorMail()->detail)->toBe('On.')
        ->and($checks->errorMail()->detail)->not->toContain('ops@example.test');

    config(['hdid.error_mail.to' => '']);
    expect($checks->errorMail()->status->value)->toBe('ok')
        ->and($checks->errorMail()->detail)->toBe('Off.');

    config(['hdid.error_mail.to' => 'ops@example.test', 'hdid.error_mail.statuses' => '500,404']);
    expect($checks->errorMail()->status->value)->toBe('warn')
        ->and($checks->errorMail()->detail)->toContain('On.', 'HDID_ERROR_MAIL_STATUSES: 404');
});

it('sends a sample report with the test command, and refuses while the report is off', function (): void {
    $this->artisan('hdid:test-error-mail')->assertSuccessful();

    expect(errorReports())->toHaveCount(1)
        ->and(errorReports()->first()->htmlBody)->toContain('HTTP 500 Report test');

    config(['hdid.error_mail.to' => '']);
    $this->artisan('hdid:test-error-mail')->expectsOutputToContain('HDID_ERROR_MAIL_TO')->assertFailed();
});

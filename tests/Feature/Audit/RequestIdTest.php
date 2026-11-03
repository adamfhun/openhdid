<?php

use App\Models\AuditLog;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;

/*
 * The request id of an audit entry is always the server's own (and is
 * echoed in the X-Request-Id response header): a caller cannot choose it,
 * so an anonymous request cannot plant an id that ties its entries to
 * someone else's. A well-formed id the caller sent is kept as a detail.
 */

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    config()->set('hdid.callcenter.api_key', null);
});

it('stamps audit entries with a server-generated request id even when the caller sends one, and keeps a well-formed one as a detail', function (): void {
    $response = $this->withHeader('X-Request-Id', 'chosen-by-caller')->getJson('/api/v1/callcenter/lookup?number=%2B36301234567')->assertUnauthorized();

    $entry = AuditLog::query()->where('event', 'api_key.rejected')->sole();
    expect(Str::isUuid($entry->request_id))->toBeTrue()
        ->and($entry->request_id)->not->toBe('chosen-by-caller')
        ->and($entry->context['caller_request_id'] ?? null)->toBe('chosen-by-caller')
        ->and($response->headers->get('X-Request-Id'))->toBe($entry->request_id);
});

it('drops a caller request id that is not a short token', function (string $given): void {
    $this->withHeader('X-Request-Id', $given)->getJson('/api/v1/callcenter/lookup?number=%2B36301234567')->assertUnauthorized();

    $entry = AuditLog::query()->where('event', 'api_key.rejected')->sole();
    expect($entry->context)->not->toHaveKey('caller_request_id')
        ->and(Str::isUuid($entry->request_id))->toBeTrue();
})->with([
    'with a space' => ['two words'],
    'with markup' => ['<b>id</b>'],
    'too long' => [str_repeat('a', 65)],
]);

it('keeps an ingress-style hex request id as caller_request_id', function (): void {
    $hex = bin2hex(random_bytes(16));

    $this->withHeader('X-Request-Id', $hex)->getJson('/api/v1/callcenter/lookup?number=%2B36301234567')->assertUnauthorized();

    $entry = AuditLog::query()->where('event', 'api_key.rejected')->sole();
    expect($entry->context['caller_request_id'])->toBe($hex)
        ->and($entry->request_id)->not->toBe($hex);
});

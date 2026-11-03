<?php

use App\Enums\PhoneNumberSource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Question;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

/*
 * The portal's write endpoints that had no limit of their own (an answer
 * saved or removed, a number removed or made primary) share one named
 * bucket of 30 a minute per client: plenty for a person clicking, not for
 * a script filling the audit log or flipping the SMS target.
 */

beforeEach(function (): void {
    RateLimiter::clear('client-write');
});

it('puts the portal write endpoints into one named bucket', function (string $name): void {
    $route = collect(app('router')->getRoutes())->first(fn ($route) => $route->getName() === $name);

    expect(collect($route->gatherMiddleware())->contains('throttle:30,1,client-write'))->toBeTrue();
})->with([
    'api.v1.client.answers.store',
    'api.v1.client.answers.destroy',
    'api.v1.client.phone-numbers.destroy',
    'api.v1.client.phone-numbers.primary',
]);

it('answers 429 to the thirty-first answer save of a client within a minute, and counts the bucket per client', function (): void {
    $question = Question::factory()->create();
    $client = Client::factory()->synced()->create();
    Sanctum::actingAs($client, guard: 'client');

    foreach (range(1, 30) as $i) {
        $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => 'answer '.$i])->assertOk();
    }
    $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => 'answer 31'])->assertTooManyRequests();

    expect(AuditLog::query()->where('event', 'client_answer.saved')->count())->toBe(30);

    Sanctum::actingAs(Client::factory()->synced()->create(), guard: 'client');
    $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => 'another client'])->assertOk();
});

it('counts removing and re-saving an answer against the same bucket', function (): void {
    $question = Question::factory()->create();
    Sanctum::actingAs(Client::factory()->synced()->create(), guard: 'client');

    foreach (range(1, 15) as $i) {
        $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => 'Rex'])->assertOk();
        $this->deleteJson('/api/v1/client/answers/'.$question->id)->assertOk();
    }

    $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => 'Rex'])->assertTooManyRequests();
});

it('records no audit entry when the same answer is saved again', function (): void {
    $question = Question::factory()->create();
    Sanctum::actingAs(Client::factory()->synced()->create(), guard: 'client');

    $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => 'Rex'])->assertOk();
    $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => 'Rex'])->assertOk()->assertJsonPath('message', __('Answer saved.'));

    expect(AuditLog::query()->where('event', 'client_answer.saved')->count())->toBe(1);
});

it('still records a changed spelling and a revived answer', function (): void {
    $question = Question::factory()->create();
    Sanctum::actingAs(Client::factory()->synced()->create(), guard: 'client');

    $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => 'Rex'])->assertOk();
    $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => 'rex'])->assertOk();
    $this->deleteJson('/api/v1/client/answers/'.$question->id)->assertOk();
    $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => 'rex'])->assertOk();

    expect(AuditLog::query()->where('event', 'client_answer.saved')->count())->toBe(3);
});

it('answers 429 when the primary number is switched back and forth too often', function (): void {
    $client = Client::factory()->synced()->create();
    $first = $client->phoneNumbers()->create(['number_e164' => '+36301111111', 'source' => PhoneNumberSource::Sync, 'is_primary' => true]);
    $second = $client->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::Sync]);
    Sanctum::actingAs($client, guard: 'client');

    foreach (range(1, 15) as $i) {
        $this->postJson('/api/v1/client/phone-numbers/'.$second->id.'/primary')->assertOk();
        $this->postJson('/api/v1/client/phone-numbers/'.$first->id.'/primary')->assertOk();
    }

    $this->postJson('/api/v1/client/phone-numbers/'.$second->id.'/primary')->assertTooManyRequests();
});

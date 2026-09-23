<?php

use App\Identification\AnswerNormalizer;
use App\Identification\ClientAnswers;
use App\Identification\QuestionCatalog;
use App\Models\Client;
use App\Models\Question;

it('creates a question with its first version and orders questions', function (): void {
    $catalog = app(QuestionCatalog::class);

    $first = $catalog->create('First pet?', 'the pet');
    $second = $catalog->create('Birth city?');

    expect($first->text)->toBe('First pet?')
        ->and($first->hint)->toBe('the pet')
        ->and($first->currentVersion->version)->toBe(1)
        ->and($catalog->activeQuestions()->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('keeps answers on a typo fix and invalidates them on a meaning change', function (): void {
    $catalog = app(QuestionCatalog::class);
    $answers = app(ClientAnswers::class);
    $question = $catalog->create('Frist pet?');
    $client = Client::factory()->create();
    $answers->save($client, $question, 'Rex');

    $catalog->publishVersion($question, 'First pet?', null, keepsAnswers: true);
    expect($answers->usableCount($client))->toBe(1)
        ->and($question->fresh()->currentVersion->version)->toBe(2)
        ->and($answers->usable($client)->first()->answer)->toBe('Rex');

    $catalog->publishVersion($question->fresh(), 'Name of your first car?', null, keepsAnswers: false);
    expect($answers->usableCount($client))->toBe(0)
        ->and($answers->stale($client))->toHaveCount(1);

    $answers->save($client, $question->fresh(), 'Trabant');
    expect($answers->usableCount($client))->toBe(1)->and($answers->stale($client))->toHaveCount(0);
});

it('ignores inactive questions', function (): void {
    $question = Question::factory()->inactive()->create();
    $client = Client::factory()->create();
    app(ClientAnswers::class)->save($client, $question, 'x');

    expect(app(ClientAnswers::class)->usableCount($client))->toBe(0)
        ->and(app(QuestionCatalog::class)->activeQuestions())->toHaveCount(0);
});

it('normalises answers for hashing', function (): void {
    $n = new AnswerNormalizer;

    expect($n->normalize('  Kék  Autó! '))->toBe('kek auto')
        ->and($n->matches('kek AUTO', $n->hash('Kék autó')))->toBeTrue();
});

it('keys the answer digest with the application key', function (): void {
    $n = new AnswerNormalizer;

    // A plain digest of the normalised answer would be a dictionary lookup
    // for anyone holding a database copy without the application key.
    expect($n->hash('Kék autó'))->not->toBe(hash('sha256', 'kek auto'))
        ->and($n->hash('Kék autó'))->toBe(hash_hmac('sha256', 'client-answer:kek auto', (string) config('app.key')))
        ->and($n->hash('Kék autó'))->toHaveLength(64);
});

it('stores answers encrypted', function (): void {
    $question = Question::factory()->create();
    $client = Client::factory()->create();
    $answer = app(ClientAnswers::class)->save($client, $question, 'secret');

    expect($answer->getRawOriginal('answer'))->not->toContain('secret')
        ->and($answer->fresh()->answer)->toBe('secret');
});

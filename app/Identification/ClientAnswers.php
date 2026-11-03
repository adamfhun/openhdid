<?php

namespace App\Identification;

use App\Audit\Auditor;
use App\Models\Client;
use App\Models\ClientAnswer;
use App\Models\Question;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

/**
 * A client's answers and their eligibility for Q-A identification.
 */
class ClientAnswers
{
    public function __construct(
        private readonly AnswerNormalizer $normalizer,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
    ) {}

    public function save(Client $client, Question $question, string $answer): ClientAnswer
    {
        // A removal deletes the answer for good; a soft-deleted row is left
        // only by the staff tab of an older release, and re-answering revives it.
        $record = ClientAnswer::withTrashed()->firstOrNew(['client_id' => $client->id, 'question_id' => $question->id]);
        $record->fill([
            'question_version_id' => $question->current_version_id,
            'answer' => trim($answer),
            'answer_hash' => $this->normalizer->hash($answer),
        ]);

        // The same answer again changes nothing: no write, no audit entry
        // (the encrypted answer compares by its plain value).
        if ($record->exists && ! $record->trashed() && ! $record->isDirty()) {
            return $record;
        }

        if ($record->trashed()) {
            $record->restore();
        }

        $record->save();

        $this->auditor->record('client_answer.saved', $record, ['question_id' => $question->id, 'question_version_id' => $question->current_version_id]);

        return $record;
    }

    /**
     * Removes the answer for good, the encrypted answer and its hash with it
     * (a new answer takes a fresh row), and records who removed it: the
     * client on the portal, a staff member on the client page. The actor is
     * passed in, because a staff session open in the same browser would
     * otherwise be taken for the client. Nothing removed, nothing recorded.
     */
    public function remove(Client $client, Question $question, Authenticatable $actor, string $via = 'portal'): bool
    {
        $answers = ClientAnswer::withTrashed()->where('client_id', $client->id)->where('question_id', $question->id);
        $versionId = (clone $answers)->value('question_version_id');

        if ($answers->forceDelete() === 0) {
            return false;
        }

        $this->auditor->record('client_answer.removed', $client, ['question_id' => $question->id, 'question_version_id' => $versionId, 'via' => $via], $actor);

        return true;
    }

    /**
     * Answers usable for identification right now: the question is active
     * and the answer was given to (or moved to) its current wording.
     *
     * @return Collection<int, ClientAnswer>
     */
    public function usable(Client $client): Collection
    {
        return ClientAnswer::query()
            ->where('client_id', $client->id)
            ->whereHas('question', fn ($q) => $q->active()->whereColumn('questions.current_version_id', 'client_answers.question_version_id'))
            ->with(['question.currentVersion', 'questionVersion'])
            ->get();
    }

    /**
     * Answers given to an older wording of a still-active question.
     *
     * @return Collection<int, ClientAnswer>
     */
    public function stale(Client $client): Collection
    {
        return ClientAnswer::query()
            ->where('client_id', $client->id)
            ->whereHas('question', fn ($q) => $q->active()->whereColumn('questions.current_version_id', '!=', 'client_answers.question_version_id'))
            ->get();
    }

    public function usableCount(Client $client): int
    {
        return $this->usable($client)->count();
    }

    public function requiredCount(): int
    {
        return $this->settings->int(SettingKey::QaMinAnsweredQuestionsRequired);
    }

    public function isEligible(Client $client): bool
    {
        return $this->usableCount($client) >= $this->requiredCount();
    }
}

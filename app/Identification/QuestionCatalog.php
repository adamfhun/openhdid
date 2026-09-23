<?php

namespace App\Identification;

use App\Audit\Auditor;
use App\Models\ClientAnswer;
use App\Models\Question;
use App\Models\QuestionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Questions and their versions. Each question is versioned on its own:
 * publishing a new wording bumps only that question.
 */
class QuestionCatalog
{
    public function __construct(private readonly Auditor $auditor) {}

    public function create(string $text, ?string $hint = null, ?User $by = null, bool $isActive = true): Question
    {
        return DB::transaction(function () use ($text, $hint, $by, $isActive): Question {
            $question = Question::query()->create([
                'is_active' => $isActive,
                'position' => ((int) Question::query()->withTrashed()->max('position')) + 1,
            ]);

            $this->publishVersion($question, $text, $hint, keepsAnswers: true, by: $by);

            return $question->refresh();
        });
    }

    /**
     * Publish a new wording. With `keepsAnswers` existing answers are moved
     * to the new version (a typo fix); without it they stay on the old
     * version and clients have to answer again.
     */
    public function publishVersion(Question $question, string $text, ?string $hint, bool $keepsAnswers, ?User $by = null): QuestionVersion
    {
        return DB::transaction(function () use ($question, $text, $hint, $keepsAnswers, $by): QuestionVersion {
            $previous = $question->current_version_id;

            $version = $question->versions()->create([
                'version' => ((int) $question->versions()->max('version')) + 1,
                'text' => trim($text),
                'hint' => $hint !== null && trim($hint) !== '' ? trim($hint) : null,
                'keeps_answers' => $keepsAnswers,
                'published_by_user_id' => $by?->id,
            ]);

            $question->forceFill(['current_version_id' => $version->id])->save();

            $moved = 0;
            if ($keepsAnswers && $previous !== null) {
                $moved = ClientAnswer::query()->where('question_id', $question->id)->where('question_version_id', $previous)->update(['question_version_id' => $version->id]);
            }

            $this->auditor->record('question.version_published', $question, [
                'version' => $version->version,
                'keeps_answers' => $keepsAnswers,
                'answers_moved' => $moved,
            ], $by);

            return $version;
        });
    }

    /**
     * Active questions with their current wording, in display order.
     *
     * @return Collection<int, Question>
     */
    public function activeQuestions(): Collection
    {
        return Question::query()->active()->with('currentVersion')->orderBy('position')->get();
    }

    /**
     * @return list<string>
     */
    public function activeQuestionIds(): array
    {
        return Question::query()->active()->pluck('id')->all();
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Identification\ClientAnswers;
use App\Identification\QuestionCatalog;
use App\Models\Client;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The client's own security questions and answers.
 */
class AnswersController extends Controller
{
    public function __construct(
        private readonly QuestionCatalog $catalog,
        private readonly ClientAnswers $answers,
    ) {}

    /**
     * Active questions with whether the client has answered them.
     *
     * @response array{data: list<array{id: string, text: string, hint: ?string, answered: bool, needs_update: bool}>, meta: array{answered: int, required: int, eligible: bool}}
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();
        $usable = $this->answers->usable($client)->pluck('question_id')->all();
        $stale = $this->answers->stale($client)->pluck('question_id')->all();

        $questions = $this->catalog->activeQuestions()->map(fn (Question $q) => [
            'id' => $q->id,
            'text' => $q->text,
            'hint' => $q->hint,
            'answered' => in_array($q->id, $usable, true),
            'needs_update' => in_array($q->id, $stale, true),
        ])->values();

        return response()->json([
            'data' => $questions,
            'meta' => [
                'answered' => $this->answers->usableCount($client),
                'required' => $this->answers->requiredCount(),
                'eligible' => $this->answers->isEligible($client),
            ],
        ]);
    }

    /**
     * Save (or replace) the answer to a question. Answers are write-only.
     *
     * @response array{message: string}
     */
    public function store(Request $request, Question $question): JsonResponse
    {
        $data = $request->validate(['answer' => ['required', 'string', 'min:1', 'max:200']]);

        abort_unless($question->is_active && $question->current_version_id !== null, 404);

        $this->answers->save($request->user(), $question, $data['answer']);

        return response()->json(['message' => __('Answer saved.')]);
    }

    /**
     * Remove the answer to a question.
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, Question $question): JsonResponse
    {
        $this->answers->remove($request->user(), $question);

        return response()->json(['message' => __('Answer removed.')]);
    }
}

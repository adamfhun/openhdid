<?php

use App\Identification\AnswerNormalizer;
use App\Models\ClientAnswer;
use Illuminate\Database\Migrations\Migration;

/**
 * The answer digest became keyed (HMAC with the application key), so the
 * digests stored with the previous plain SHA-256 have to be recomputed from
 * the encrypted answer; without this every stored answer would stop
 * matching. Rows whose answer cannot be decrypted are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $normalizer = app(AnswerNormalizer::class);

        ClientAnswer::withTrashed()->chunkById(500, function ($answers) use ($normalizer): void {
            foreach ($answers as $answer) {
                try {
                    $plain = $answer->answer;
                } catch (Throwable) {
                    continue;
                }

                if (! is_string($plain) || $plain === '') {
                    continue;
                }

                $hash = $normalizer->hash($plain);

                if ($hash !== $answer->answer_hash) {
                    $answer->forceFill(['answer_hash' => $hash])->saveQuietly();
                }
            }
        });
    }

    public function down(): void
    {
        // The previous digest cannot be restored from the keyed one; the
        // rows stay consistent with the current hashing.
    }
};

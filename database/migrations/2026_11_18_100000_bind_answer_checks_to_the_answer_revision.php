<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A blind check that matched lets the agent see the stored answer. The step
 * only knew the question, so an answer the client saved after the match was
 * shown as well, although the caller never proved it. Every answer now
 * carries a random revision that changes whenever its text does, and the
 * step keeps the revision it compared: nothing of the answer itself. An
 * answer saved before this migration gets its revision when it first matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_answers', function (Blueprint $table): void {
            $table->uuid('revision')->nullable()->after('answer_hash');
        });

        Schema::table('id_session_steps', function (Blueprint $table): void {
            $table->uuid('checked_answer_revision')->nullable()->after('check_distance');
        });
    }

    public function down(): void
    {
        Schema::table('id_session_steps', function (Blueprint $table): void {
            $table->dropColumn('checked_answer_revision');
        });

        Schema::table('client_answers', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};

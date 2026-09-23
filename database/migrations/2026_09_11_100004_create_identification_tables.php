<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('current_version_id')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('question_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('text', 500);
            $table->string('hint', 500)->nullable();
            $table->boolean('keeps_answers')->default(true);
            $table->uuid('published_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['question_id', 'version']);
        });

        Schema::create('client_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('question_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('question_version_id')->constrained('question_versions')->cascadeOnDelete();
            $table->text('answer');
            $table->string('answer_hash', 64);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['client_id', 'question_id']);
        });

        Schema::create('calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('external_call_id')->unique();
            $table->string('caller_number_raw', 50)->nullable();
            $table->string('caller_number_e164', 20)->nullable()->index();
            $table->foreignUuid('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->index();
            $table->string('queue')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('arrived_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->uuid('handled_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('id_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('call_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);
            $table->string('method', 20);
            $table->string('status', 20)->index();
            $table->unsignedSmallInteger('required_accepted')->default(0);
            $table->unsignedSmallInteger('max_questions')->default(0);
            $table->unsignedSmallInteger('max_rejected')->default(0);
            $table->unsignedSmallInteger('accepted_count')->default(0);
            $table->unsignedSmallInteger('rejected_count')->default(0);
            $table->unsignedSmallInteger('undecided_count')->default(0);
            $table->string('outcome_reason', 100)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('id_session_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('id_session_id')->constrained('id_sessions')->cascadeOnDelete();
            $table->foreignUuid('question_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('question_version_id')->nullable()->constrained('question_versions')->nullOnDelete();
            $table->string('verdict', 20)->default('pending');
            $table->timestamp('shown_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['id_session_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_session_steps');
        Schema::dropIfExists('id_sessions');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('client_answers');
        Schema::dropIfExists('question_versions');
        Schema::dropIfExists('questions');
    }
};

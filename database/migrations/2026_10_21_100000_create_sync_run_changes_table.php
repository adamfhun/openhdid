<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an EMD sync run changed, one row per directory record and run: the
 * record's own diff and the diffs of the account and phone numbers it drove.
 * These rows used to be audit_log entries (two or three per changed record,
 * tens of thousands on a first import); the audit log keeps one summary row
 * per run and the account lifecycle events, this table keeps the detail
 * under the short-lived retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_run_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('external_id')->index();
            $table->string('kind', 20)->index();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->json('changes');
            $table->timestamp('created_at')->index();

            $table->index(['sync_run_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_run_changes');
    }
};

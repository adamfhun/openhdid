<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source', 20);
            $table->string('status', 20)->index();
            $table->string('file_name')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->uuid('triggered_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('external_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('external_id')->unique();
            $table->string('kind', 20)->index();
            $table->string('company')->nullable()->index();
            $table->string('name');
            $table->string('email')->index();
            $table->string('email_domain')->nullable()->index();
            $table->string('implicit_package')->nullable();
            $table->string('explicit_package')->nullable();
            $table->json('phones')->nullable();
            $table->json('attributes')->nullable();
            $table->foreignUuid('first_seen_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->foreignUuid('last_seen_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('missed_runs')->default(0);
            $table->timestamp('missing_since')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_records');
        Schema::dropIfExists('sync_runs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event', 100)->index();
            $table->string('actor_type')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['actor_type', 'actor_id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('settings');
    }
};

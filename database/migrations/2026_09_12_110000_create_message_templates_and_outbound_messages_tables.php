<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 40);
            $table->string('locale', 5);
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->foreignUuid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'locale']);
        });

        Schema::create('outbound_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('channel', 10);
            $table->string('template_key', 40)->nullable();
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->json('meta')->nullable();
            $table->foreignUuid('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->index();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('message_templates');
    }
};

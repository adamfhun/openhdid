<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_time_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30)->index();
            $table->string('code_hash');
            $table->string('destination')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_time_codes');
    }
};

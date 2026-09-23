<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->foreignUuid('external_record_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->index();
            $table->string('closed_reason')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->rememberToken();
            $table->foreignUuid('external_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('implicit_package')->nullable();
            $table->string('explicit_package')->nullable();
            $table->string('pin_hash')->nullable();
            $table->timestamp('pin_set_at')->nullable();
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();
            $table->timestamp('closed_at')->nullable()->index();
            $table->string('closed_reason')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('client_phone_numbers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->string('number_e164', 20)->index();
            $table->string('label', 50)->nullable();
            $table->string('source', 10);
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['client_id', 'number_e164']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_phone_numbers');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};

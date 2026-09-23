<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('one_time_codes', function (Blueprint $table): void {
            $table->string('lookup', 64)->nullable()->index()->after('code_hash');
            $table->text('secret_encrypted')->nullable()->after('lookup');
        });

        Schema::create('api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('scope', 30)->index();
            $table->string('key_hash', 64)->unique();
            $table->string('key_prefix', 12);
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');

        Schema::table('one_time_codes', function (Blueprint $table): void {
            $table->dropColumn(['lookup', 'secret_encrypted']);
        });
    }
};

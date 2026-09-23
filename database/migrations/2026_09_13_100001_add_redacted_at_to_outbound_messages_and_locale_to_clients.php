<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->timestamp('redacted_at')->nullable()->after('last_attempt_at');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropColumn('redacted_at');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });
    }
};

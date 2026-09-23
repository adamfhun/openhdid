<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->boolean('dry_run')->default(false)->after('status');
            $table->json('skipped_rows')->nullable()->after('stats');
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->dropColumn(['dry_run', 'skipped_rows']);
        });
    }
};

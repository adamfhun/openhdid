<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('handles_tiers')->nullable()->after('external_record_id');
            $table->string('active_tier', 10)->nullable()->after('handles_tiers');
        });

        Schema::table('calls', function (Blueprint $table): void {
            $table->string('tier', 10)->nullable()->index()->after('queue');
        });

        DB::table('calls')->whereNull('tier')->update(['tier' => 'premium']);
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table): void {
            $table->dropColumn('tier');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['handles_tiers', 'active_tier']);
        });
    }
};

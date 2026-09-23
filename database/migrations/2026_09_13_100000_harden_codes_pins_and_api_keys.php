<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Live one-time secrets must be unique system-wide; consumed ones drop
        // their lookup so the unique index only covers what can still be used.
        DB::table('one_time_codes')->whereNotNull('consumed_at')->update(['lookup' => null]);
        DB::table('one_time_codes')->where('expires_at', '<', now())->update(['lookup' => null]);

        // Numeric codes are verified per client by hash, never found by lookup;
        // only the system-wide unique secrets keep one (see OneTimeCodes::store()).
        DB::table('one_time_codes')->whereIn('purpose', ['login_otp', 'phone_verify'])->update(['lookup' => null]);

        // Any remaining duplicate keeps only its newest row addressable.
        DB::table('one_time_codes')->select('lookup')->whereNotNull('lookup')
            ->groupBy('lookup')->havingRaw('count(*) > 1')->pluck('lookup')
            ->each(function (string $lookup): void {
                $keep = DB::table('one_time_codes')->where('lookup', $lookup)->max('id');
                DB::table('one_time_codes')->where('lookup', $lookup)->where('id', '!=', $keep)->update(['lookup' => null]);
            });

        Schema::table('one_time_codes', function (Blueprint $table): void {
            $table->dropIndex(['lookup']);
            $table->unique('lookup');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->unsignedSmallInteger('pin_lockout_count')->default(0)->after('pin_locked_until');
        });

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->text('hmac_secret')->nullable()->after('key_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('hmac_secret');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('pin_lockout_count');
        });

        Schema::table('one_time_codes', function (Blueprint $table): void {
            $table->dropUnique(['lookup']);
            $table->index('lookup');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity stamp of an account, written at most once in five minutes by the
 * request that saw it. The capacity tile of the system status counts the
 * accounts active in the last quarter of an hour from it, whatever the
 * session store is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->nullable()->index()->after('last_login_at');
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->nullable()->index()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};

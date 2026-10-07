<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a client link ended (ClientLinks: a sponsor that lost its implicit
 * premium package), and the grace counter for a directory record whose
 * classification (staff/client) changed with its e-mail domain
 * (SyncExternalRecords: the old account closes only after the configured
 * number of runs, like a missing record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_links', function (Blueprint $table): void {
            $table->string('ended_reason', 40)->nullable()->after('ended_at');
        });

        Schema::table('external_records', function (Blueprint $table): void {
            $table->string('pending_kind', 20)->nullable()->after('kind');
            $table->unsignedInteger('pending_kind_runs')->default(0)->after('pending_kind');
        });
    }

    public function down(): void
    {
        Schema::table('client_links', function (Blueprint $table): void {
            $table->dropColumn('ended_reason');
        });

        Schema::table('external_records', function (Blueprint $table): void {
            $table->dropColumn(['pending_kind', 'pending_kind_runs']);
        });
    }
};

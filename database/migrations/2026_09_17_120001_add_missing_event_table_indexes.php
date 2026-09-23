<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The event tables grow without bound while every dashboard, report,
 * retention pass and hourly clean-up filters them by time, queue or status.
 * Foreign keys carry their own index on MariaDB; these are the columns that
 * do not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table): void {
            // Reports and the dashboard group by arrival, the call list and
            // the missed-call widget sort by it, retention prunes by it.
            $table->index('arrived_at');
            // Statistics group by queue; the settings page offers the seen queues.
            $table->index('queue');
            // The hourly clean-up: ended or missed calls older than the cap.
            $table->index(['status', 'ended_at']);
            // The missed-call list: not yet handled, by status.
            $table->index(['status', 'handled_at']);
        });

        Schema::table('id_sessions', function (Blueprint $table): void {
            // Identification statistics and retention run on the start time.
            $table->index('started_at');
            // Every identification start looks for the client's open session.
            $table->index(['client_id', 'status']);
        });

        Schema::table('outbound_messages', function (Blueprint $table): void {
            // The minutely re-queue looks for stuck rows by status and age.
            $table->index(['status', 'updated_at']);
        });

        Schema::table('sync_runs', function (Blueprint $table): void {
            // Retention prunes by start; the guard and the status page read
            // the latest successful run.
            $table->index('started_at');
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table): void {
            $table->dropIndex(['arrived_at']);
            $table->dropIndex(['queue']);
            $table->dropIndex(['status', 'ended_at']);
            $table->dropIndex(['status', 'handled_at']);
        });

        Schema::table('id_sessions', function (Blueprint $table): void {
            $table->dropIndex(['started_at']);
            $table->dropIndex(['client_id', 'status']);
        });

        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropIndex(['status', 'updated_at']);
        });

        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->dropIndex(['started_at']);
            $table->dropIndex(['status', 'started_at']);
        });
    }
};

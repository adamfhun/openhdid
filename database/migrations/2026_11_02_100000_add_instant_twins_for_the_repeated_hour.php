<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Times are stored as local wall-clock text, and on the night the clocks go
 * back 02:00–03:00 passes twice: compared as text, an expiry, a budget or a
 * freshness window measured in the first pass comes back in the second.
 * Each time column that guards such a limit gets an epoch twin
 * (HasInstantTwins), and the limits compare the twin. Rows from before this
 * migration have no twin and fall back to the wall clock.
 *
 * The outbound_messages indexes are built on the largest event table; on a
 * large installation this takes a while (online on MariaDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('one_time_codes', function (Blueprint $table): void {
            $table->unsignedBigInteger('expires_unix')->nullable()->after('expires_at');
        });

        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_unix')->nullable()->after('created_at');
            // The per-number SMS login-code budget (ClientPasswordlessLogin).
            $table->index(['template_key', 'recipient', 'created_unix'], 'outbound_messages_template_recipient_created_unix_index');
            // The per-client SMS login-code budget, whatever the number.
            $table->index(['template_key', 'client_id', 'created_unix'], 'outbound_messages_template_client_created_unix_index');
        });

        Schema::table('id_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('expires_unix')->nullable()->after('expires_at');
            $table->unsignedBigInteger('decided_unix')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('id_sessions', function (Blueprint $table): void {
            $table->dropColumn(['expires_unix', 'decided_unix']);
        });

        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropIndex('outbound_messages_template_client_created_unix_index');
            $table->dropIndex('outbound_messages_template_recipient_created_unix_index');
            $table->dropColumn('created_unix');
        });

        Schema::table('one_time_codes', function (Blueprint $table): void {
            $table->dropColumn('expires_unix');
        });
    }
};

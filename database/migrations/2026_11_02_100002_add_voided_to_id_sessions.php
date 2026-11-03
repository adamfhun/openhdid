<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving a call to another client ends every identification made on it
 * before (voided_at, voided_reason): a pass for one client never vouches for
 * another, and moving the call back does not bring an ended pass back. The
 * row stays for the history and the reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('id_sessions', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable();
            $table->string('voided_reason', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('id_sessions', function (Blueprint $table): void {
            $table->dropColumn(['voided_at', 'voided_reason']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blind check of question-and-answer identification: the stored answer stays
 * hidden, the agent types what the caller says and the system compares.
 * The mode and the typo tolerance are kept per session (a switch in the
 * settings does not change an open session); each step keeps how the typed
 * answers compared (never the typed text).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('id_sessions', function (Blueprint $table): void {
            $table->boolean('blind_check')->default(false)->after('max_rejected');
            $table->unsignedTinyInteger('typo_tolerance')->nullable()->after('blind_check');
        });

        Schema::table('id_session_steps', function (Blueprint $table): void {
            $table->unsignedTinyInteger('check_attempts')->default(0)->after('verdict');
            $table->string('check_result', 20)->nullable()->after('check_attempts');
            $table->unsignedTinyInteger('check_distance')->nullable()->after('check_result');
        });
    }

    public function down(): void
    {
        Schema::table('id_session_steps', function (Blueprint $table): void {
            $table->dropColumn(['check_attempts', 'check_result', 'check_distance']);
        });

        Schema::table('id_sessions', function (Blueprint $table): void {
            $table->dropColumn(['blind_check', 'typo_tolerance']);
        });
    }
};

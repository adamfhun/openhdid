<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The blind check keeps the typo distance only for a matching answer (the
 * page shows it there); for a close or a clear miss it told how far the
 * typed answer was from the stored one. Data migration: the distances of
 * missed checks stored so far are cleared (audit rows already written keep
 * theirs until they age out).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('id_session_steps')
            ->whereNotNull('check_result')
            ->where('check_result', '!=', 'matched')
            ->update(['check_distance' => null]);
    }

    public function down(): void
    {
        // The cleared distances cannot be restored.
    }
};

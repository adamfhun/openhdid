<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A removed security answer was soft-deleted and kept its encrypted answer
 * and hash for good. Removal now deletes it for good (ClientAnswers::remove);
 * data migration: the answers removed so far go the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_answers')->whereNotNull('deleted_at')->delete();
    }

    public function down(): void
    {
        // The removed answers cannot be restored.
    }
};

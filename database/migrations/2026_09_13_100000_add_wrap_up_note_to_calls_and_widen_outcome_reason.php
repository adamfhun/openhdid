<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table): void {
            $table->text('wrap_up_note')->nullable()->after('handled_by_user_id');
        });

        // Manual identification keeps the agent's reason on the session.
        Schema::table('id_sessions', function (Blueprint $table): void {
            $table->string('outcome_reason', 600)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table): void {
            $table->dropColumn('wrap_up_note');
        });

        Schema::table('id_sessions', function (Blueprint $table): void {
            $table->string('outcome_reason', 100)->nullable()->change();
        });
    }
};

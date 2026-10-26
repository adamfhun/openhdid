<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An administrator's e-mail change that hands a free account to no EMD
 * record yet marks the account: the first later link to a directory record
 * then counts as a change of person (AccountProvisioner), and what the
 * previous holder set up is removed, as on the save that links at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->timestamp('email_changed_by_admin_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('email_changed_by_admin_at');
            });
        }
    }
};

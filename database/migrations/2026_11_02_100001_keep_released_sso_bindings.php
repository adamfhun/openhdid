<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A binding that a closed or deleted account yielded to another account
 * stays on the old account as a marker (lookup emptied, released_* set):
 * when the old account is reopened or restored, every company sign-in of it
 * is refused until an administrator clears the binding, instead of binding
 * whichever identity signs in first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_identities', function (Blueprint $table): void {
            $table->char('lookup', 64)->nullable()->change();
            $table->timestamp('released_at')->nullable();
            $table->string('released_to_type', 16)->nullable();
            $table->uuid('released_to_id')->nullable();
        });
    }

    public function down(): void
    {
        DB::table('sso_identities')->whereNotNull('released_at')->delete();

        Schema::table('sso_identities', function (Blueprint $table): void {
            $table->dropColumn(['released_at', 'released_to_type', 'released_to_id']);
            $table->char('lookup', 64)->nullable(false)->change();
        });
    }
};

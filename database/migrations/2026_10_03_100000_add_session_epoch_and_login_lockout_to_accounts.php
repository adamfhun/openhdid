<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * session_epoch: raised by revokeAccess(); every browser session carries the
 * value it started with, so "sign out everywhere" ends sessions whatever the
 * session store. failed_login_attempts / locked_until: the account lockout
 * after repeated wrong passwords or SMS codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->unsignedInteger('session_epoch')->default(0);
                $table->unsignedSmallInteger('failed_login_attempts')->default(0);
                $table->timestamp('locked_until')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn(['session_epoch', 'failed_login_attempts', 'locked_until']);
            });
        }
    }
};

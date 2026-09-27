<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who or what confirmed a phone number: the directory, the operator who
 * added it, the SMS code, a staff confirmation, or an identified call. The
 * rows verified so far are labelled by their source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_phone_numbers', function (Blueprint $table): void {
            $table->string('verified_via', 20)->nullable()->after('verified_at');
            $table->uuid('verified_by_user_id')->nullable()->after('verified_via');
        });

        foreach (['sync' => 'directory', 'admin' => 'admin', 'self' => 'sms'] as $source => $via) {
            DB::table('client_phone_numbers')
                ->where('source', $source)
                ->whereNotNull('verified_at')
                ->whereNull('verified_via')
                ->update(['verified_via' => $via]);
        }
    }

    public function down(): void
    {
        Schema::table('client_phone_numbers', function (Blueprint $table): void {
            $table->dropColumn(['verified_via', 'verified_by_user_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the sync moves a client from one EMD record to another, the end of
 * the run decides whether the previous holder's data (PIN, security answers,
 * numbers added by hand) goes. That open decision was only kept in memory: a
 * run that failed after saving the link lost it, and the rerun found nothing
 * to decide. The record the client moved from is now kept on the client until
 * a run decides, and the PIN and the questions are suspended meanwhile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->uuid('moved_from_record_id')->nullable()->after('external_record_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex(['moved_from_record_id']);
            $table->dropColumn('moved_from_record_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The EMD ID list (e.g. organizations) as last read from its query, with the
 * staff's selection. Rows are never deleted: an ID that left the list keeps
 * its row with removed_at, so the history stays traceable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_id_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('external_id', 191)->unique();
            $table->string('name');
            $table->boolean('selected')->default(false)->index();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('removed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_id_list_items');
    }
};

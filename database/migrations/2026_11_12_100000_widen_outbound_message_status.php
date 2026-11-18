<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery reports (1.11.1) brought the "undeliverable" state, 13
 * characters, into a 10-character status column. In MariaDB's strict mode
 * the first SMS the network reported undeliverable failed at the update,
 * stopped the minutely check and stayed first in its queue, so no delivery
 * report was processed after it. The longer column takes the waiting
 * messages through on the next check. A VARCHAR growing within 255 bytes
 * changes in place on MariaDB, without rebuilding the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->string('status', 16)->change();
        });
    }

    public function down(): void
    {
        // Shrinking it back would cut the "undeliverable" rows: the column stays.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The body of an outbound message is the template (up to 64 KB of HTML)
 * inside the branded layout, so it can be longer than a TEXT column holds
 * (65 535 bytes on MariaDB). In strict mode a long template would make
 * every e-mail of its kind, the login link too, fail at the insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->mediumText('body')->change();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->text('body')->change();
        });
    }
};

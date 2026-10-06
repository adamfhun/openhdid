<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The login-code SMS budget counts the messages sent to one number in the
 * last minute and hour (ClientPasswordlessLogin::smsBudgetExhausted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->index(['template_key', 'recipient', 'created_at'], 'outbound_messages_template_recipient_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropIndex('outbound_messages_template_recipient_created_index');
        });
    }
};

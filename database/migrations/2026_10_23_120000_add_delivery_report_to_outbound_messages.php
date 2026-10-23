<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery reports of sent SMS (SmsDeliveryChecks): the gateway's own id
 * of the message, the report's status as mapped and as the gateway spelled
 * it, and the bookkeeping of the scheduled status queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->string('gateway_message_id', 64)->nullable()->after('sent_at');
            $table->string('delivery_status', 16)->nullable()->after('gateway_message_id');
            $table->string('delivery_status_raw', 80)->nullable()->after('delivery_status');
            $table->unsignedTinyInteger('delivery_checks')->default(0)->after('delivery_status_raw');
            $table->timestamp('next_delivery_check_at')->nullable()->after('delivery_checks');
            $table->timestamp('delivery_updated_at')->nullable()->after('next_delivery_check_at');

            $table->index('next_delivery_check_at');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropIndex(['next_delivery_check_at']);
            $table->dropColumn(['gateway_message_id', 'delivery_status', 'delivery_status_raw', 'delivery_checks', 'next_delivery_check_at', 'delivery_updated_at']);
        });
    }
};

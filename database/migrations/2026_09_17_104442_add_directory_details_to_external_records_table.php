<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('external_records', function (Blueprint $table) {
            $table->string('login_name')->nullable();
            $table->string('room')->nullable();
            $table->string('employment_status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_records', function (Blueprint $table) {
            $table->dropColumn(['login_name', 'room', 'employment_status']);
        });
    }
};

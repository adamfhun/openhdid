<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second factor of staff sign-in (StaffMfa): the authenticator app's secret
 * (encrypted), the e-mail factor, the chosen EMD mobile number of the SMS
 * factor, and the lockout of wrong codes, kept apart from the password
 * lockout so that guessing passwords never locks a colleague out of SSO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('mfa_app_secret')->nullable()->after('locked_until');
            $table->timestamp('mfa_email_enabled_at')->nullable()->after('mfa_app_secret');
            $table->string('mfa_sms_number', 32)->nullable()->after('mfa_email_enabled_at');
            $table->unsignedSmallInteger('failed_mfa_attempts')->default(0)->after('mfa_sms_number');
            $table->timestamp('mfa_locked_until')->nullable()->after('failed_mfa_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['mfa_app_secret', 'mfa_email_enabled_at', 'mfa_sms_number', 'failed_mfa_attempts', 'mfa_locked_until']);
        });
    }
};

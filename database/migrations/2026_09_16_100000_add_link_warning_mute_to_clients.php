<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client exception to the "explicit premium without a link" warning:
 * own columns, so the directory sync never touches them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->timestamp('link_warning_muted_at')->nullable()->after('package_override_until');
            $table->timestamp('link_warning_muted_until')->nullable()->after('link_warning_muted_at');
            $table->string('link_warning_muted_reason', 500)->nullable()->after('link_warning_muted_until');
            $table->foreignUuid('link_warning_muted_by_user_id')->nullable()->after('link_warning_muted_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('link_warning_muted_by_user_id');
            $table->dropColumn(['link_warning_muted_at', 'link_warning_muted_until', 'link_warning_muted_reason']);
        });
    }
};

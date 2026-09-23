<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('package_override')->nullable()->after('explicit_package');
            $table->string('package_override_reason', 500)->nullable()->after('package_override');
            $table->foreignUuid('package_override_by_user_id')->nullable()->after('package_override_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('package_override_from')->nullable()->after('package_override_by_user_id');
            $table->timestamp('package_override_until')->nullable()->index()->after('package_override_from');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('package_override_by_user_id');
            $table->dropColumn(['package_override', 'package_override_reason', 'package_override_from', 'package_override_until']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('explicit_package');
        });

        Schema::create('client_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sponsor_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('linked_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();

            $table->index(['linked_client_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_links');

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};

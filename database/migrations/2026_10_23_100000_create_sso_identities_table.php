<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The directory identity each account's company sign-in is bound to
 * (SsoSubjectBinding): the provider's stable subject (Entra object id, ADFS
 * subject) with the issuer and the client registration it was issued for.
 * One row per account and provider (unique index); one subject binds at
 * most one account of a kind, enforced by the hashed lookup's unique index
 * (the five text columns together would exceed the index width of MariaDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('principal_type', 16);
            $table->uuid('principal_id');
            $table->string('provider', 16);
            $table->string('issuer');
            $table->string('client_id');
            $table->string('subject');
            $table->char('lookup', 64)->unique();
            $table->string('claim', 32);
            $table->string('bound_via', 32);
            $table->timestamp('bound_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_mismatch_at')->nullable();
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->timestamps();

            $table->unique(['principal_type', 'principal_id', 'provider']);
            $table->index('last_mismatch_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_identities');
    }
};

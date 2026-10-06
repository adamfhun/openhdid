<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remember-me became a switch, off by default (2026-10-03). Single sign-on
 * used to set a 400-day cookie unasked; clearing the stored tokens makes
 * every such cookie worthless. Open sessions are not affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->update(['remember_token' => null]);
        DB::table('clients')->update(['remember_token' => null]);
    }

    public function down(): void
    {
        //
    }
};

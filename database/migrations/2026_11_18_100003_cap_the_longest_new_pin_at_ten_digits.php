<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A new PIN may be 4 to 10 digits long from 1.16.0 (it was 6 to 12). A stored
 * "longest PIN" of 11 or 12 comes down to 10; PINs already set with 11 or 12
 * digits stay valid and are still checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->whereIn('key', ['identification.pin.max_length', 'identification.pin.min_length'])
            ->whereIn('value', ['11', '12', '"11"', '"12"'])
            ->update(['value' => '10']);
    }

    public function down(): void
    {
        //
    }
};

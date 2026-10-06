<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The login link is valid for 12 hours instead of 3 days (client decision,
 * 2026-10-03): a stored value still equal to the old default follows the new
 * one, and stored login-link e-mails state the validity in hours, because
 * {{ days }} would round 12 hours up to one day.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'client_login.magic_link.ttl_minutes')
            ->whereIn('value', ['4320', '"4320"'])
            ->update(['value' => '720']);

        foreach (DB::table('message_templates')->where('key', 'magic_link')->get() as $template) {
            $body = str_replace(['{{ days }} napig', '{{ days }} days'], ['{{ hours }} óráig', '{{ hours }} hours'], (string) $template->body);
            if ($body !== $template->body) {
                DB::table('message_templates')->where('id', $template->id)->update(['body' => $body]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};

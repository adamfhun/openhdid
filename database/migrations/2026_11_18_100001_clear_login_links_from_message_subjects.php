<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A customised login link e-mail could have the link placeholder in its
 * subject. The rendered link was then stored in the subject of the outbound
 * message, where it stayed after the body was wiped and where staff with the
 * audit permission could read it. The subject no longer takes the secret
 * placeholders; data migration: the subjects that hold an address go, and a
 * template subject with the link placeholder falls back to the default one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('outbound_messages')
            ->where('template_key', 'magic_link')
            ->where('subject', 'like', '%://%')
            ->update(['subject' => null]);

        DB::table('message_templates')
            ->where('key', 'magic_link')
            ->whereNotNull('subject')
            ->orderBy('id')
            ->get(['id', 'subject'])
            ->filter(fn (object $template): bool => preg_match('/\{\{\s*link\s*\}\}/i', (string) $template->subject) === 1)
            ->each(fn (object $template) => DB::table('message_templates')->where('id', $template->id)->update(['subject' => null]));
    }

    public function down(): void
    {
        // The removed subjects cannot be restored.
    }
};

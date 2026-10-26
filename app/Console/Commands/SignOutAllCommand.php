<?php

namespace App\Console\Commands;

use App\System\SignOutEveryone;
use Illuminate\Console\Command;

/**
 * Signs every staff member and client out everywhere (SignOutEveryone):
 * the step after a restored backup, so that sign-ins revoked since the
 * backup do not come back. The OpenHDID backup.sh runs it after a restore
 * and on "signout"; on a classic installation the operator runs it by hand
 * after the database was restored. Its last line is machine-readable for
 * the script: "signed-out <staff> <clients> <tokens> <codes> <session store>
 * <cache store> <shared|separate>".
 */
class SignOutAllCommand extends Command
{
    protected $signature = 'hdid:sign-out-all
        {--force : Do not ask for confirmation}
        {--reason=manual : Why, for the audit entry (for example backup_restore)}';

    protected $description = 'Sign every staff member and client out everywhere (after a restored backup): session epochs, remember-me and mobile tokens, live one-time codes, the session store and the cache';

    public function handle(SignOutEveryone $signOut): int
    {
        $reason = trim((string) $this->option('reason')) ?: 'manual';

        if (! $this->option('force')) {
            $this->components->warn('Every staff member and client is signed out everywhere, in the mobile app too: the session epochs move on, the remember-me and mobile tokens are deleted, the live login links and codes (SMS, mobile, IVR) are used up, and the session store and the cache are emptied (a Valkey database entirely, waiting queue jobs with it when the queue shares it). Stop the queue workers and the scheduler first: emptying the cache drops the locks of running work.');

            if (! $this->confirm('Sign everybody out now?')) {
                $this->line('Nothing changed.');

                return self::FAILURE;
            }
        }

        $result = $signOut->run($reason);

        $this->components->info(sprintf(
            'Everybody is signed out: the session epoch of %d staff and %d client account(s) moved on, the remember-me tokens are gone, %d mobile token(s) deleted, %d live login link(s) and code(s) used up.',
            $result['users'],
            $result['clients'],
            $result['tokens'],
            $result['codes'],
        ));
        $this->line(sprintf('The session store (%s) and the cache (%s) are empty; audit entry: access.revoked_all (reason %s).', $result['session_store'], $result['cache_store'], $reason));

        if ($result['session_store'] === 'redis') {
            $this->line($result['sessions_separate']
                ? 'The sessions had a Valkey of their own, which is now empty; the queue in the main Valkey is intact.'
                : 'The sessions shared the main Valkey: its session and cache databases are empty, waiting queue jobs with them (QUEUE_CONNECTION=redis); the scheduler requeues unsent messages within five minutes.');
        }

        $this->line(sprintf(
            'signed-out %d %d %d %d %s %s %s',
            $result['users'],
            $result['clients'],
            $result['tokens'],
            $result['codes'],
            $result['session_store'],
            $result['cache_store'],
            $result['sessions_separate'] ? 'separate' : 'shared',
        ));

        return self::SUCCESS;
    }
}

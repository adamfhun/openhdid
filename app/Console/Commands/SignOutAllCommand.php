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
 * <cache store> <shared|separate>"; the last word says whether the sessions
 * had a Valkey of their own. Either way the queued jobs stay: on Valkey only
 * this installation's keys go, by key prefix.
 */
class SignOutAllCommand extends Command
{
    protected $signature = 'hdid:sign-out-all
        {--force : Do not ask for confirmation}
        {--reason=manual : Why, for the audit entry (for example backup_restore)}';

    protected $description = 'Sign every staff member and client out everywhere (after a restored backup): session epochs, remember-me and mobile tokens, unused one-time codes, and this installation\'s sessions, cache entries and locks (on Valkey by key prefix: the queue and the replay guards stay)';

    public function handle(SignOutEveryone $signOut): int
    {
        $reason = trim((string) $this->option('reason')) ?: 'manual';

        if (! $this->option('force')) {
            $this->components->warn('Every staff member and client is signed out everywhere, in the mobile app too: the session epochs move on, the remember-me and mobile tokens are deleted, the unused login links and codes (SMS, mobile, IVR) are used up, and the sessions, the cache entries and the locks are deleted. On Valkey only the keys under this installation\'s prefix go (key by key, no FLUSHDB): the queued jobs, the replay guards and the keys of other prefixes stay; a database cache keeps its replay guards, a file or memcached store is emptied whole. Stop the queue workers and the scheduler first: deleting the locks frees running work.');

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
        $this->line(sprintf(
            'Deleted from the session store (%s): %s; from the cache (%s): %s, and %s; audit entry: access.revoked_all (reason %s).',
            $result['session_store'],
            self::described($result['session_keys'], 'session key(s)'),
            $result['cache_store'],
            self::described($result['cache_keys'], 'cache key(s)'),
            self::described($result['lock_keys'], 'lock(s)'),
            $reason,
        ));

        if ($result['session_store'] === 'redis' || $result['cache_store'] === 'redis') {
            $sessions = match (true) {
                $result['session_store'] !== 'redis' => '',
                $result['sessions_separate'] => ' (the sessions in a Valkey of their own)',
                default => ' (the sessions in the main Valkey)',
            };
            $this->line('On Valkey only the keys under this installation\'s prefix were deleted, key by key'.$sessions.': the queued jobs, the replay guards and the keys of other prefixes are kept.');
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

    /**
     * A count with its noun, or the whole store where nothing was counted.
     */
    private static function described(?int $count, string $noun): string
    {
        return $count === null ? 'the whole store' : $count.' '.$noun;
    }
}

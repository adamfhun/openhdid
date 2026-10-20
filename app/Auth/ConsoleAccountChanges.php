<?php

namespace App\Auth;

use App\Audit\Auditor;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Account changes made from the command line (hdid:make-admin,
 * hdid:mfa-reset) bypass the panel and its second factor: each one leaves
 * its own audit entry with the operating-system user and host, a warning in
 * the application log, and an e-mail to every other super-admin, so that a
 * console change never goes unnoticed.
 */
class ConsoleAccountChanges
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly Messenger $messenger,
    ) {}

    /**
     * @param  array<string, mixed>  $context  what changed (no secrets)
     */
    public function record(string $event, ?User $subject, string $summary, array $context = []): void
    {
        $context = ['via' => 'console', 'os_user' => self::osUser(), 'host' => (string) gethostname()] + $context;

        $this->auditor->record($event, $subject, $context);
        Log::warning('Console account change: '.$event, ['subject' => $subject?->email] + $context);

        $recipients = User::query()->open()->role(Role::SuperAdmin->value)
            ->when($subject !== null, fn ($query) => $query->whereKeyNot($subject->getKey()))
            ->pluck('email');

        foreach ($recipients as $email) {
            $this->messenger->sendRaw(Channel::Email, $email, __('An administrator account was changed from the command line'), __(':summary Command-line user: :user, host: :host. If you do not know about it, check the audit log at once.', [
                'summary' => $summary,
                'user' => $context['os_user'],
                'host' => $context['host'],
            ]));
        }
    }

    private static function osUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());

            if (is_array($entry) && isset($entry['name'])) {
                return (string) $entry['name'];
            }
        }

        return (string) (getenv('USER') ?: get_current_user());
    }
}

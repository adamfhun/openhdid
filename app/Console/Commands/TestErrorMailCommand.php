<?php

namespace App\Console\Commands;

use App\System\ErrorMail;
use Illuminate\Console\Command;

/**
 * Sends a sample HTTP 500 Report to the addresses of HDID_ERROR_MAIL_TO,
 * past the repeat limits; with --now it is delivered in this process.
 */
class TestErrorMailCommand extends Command
{
    protected $signature = 'hdid:test-error-mail {--now : Send in this process instead of the queue}';

    protected $description = 'Send a sample HTTP 500 Report to the addresses of HDID_ERROR_MAIL_TO';

    public function handle(ErrorMail $errorMail): int
    {
        foreach ($errorMail->ignoredEntries() as $entry) {
            $this->warn('Left out (not an e-mail address or a status 500–599): '.$entry);
        }

        if (! $errorMail->enabled()) {
            $this->error('The HTTP 500 Report is off: HDID_ERROR_MAIL_TO lists no e-mail address (in the .env file or the environment of the containers; reload the configuration after a change).');

            return self::FAILURE;
        }

        $messages = $errorMail->sendSample();

        if ($this->option('now')) {
            $this->callSilently('hdid:messages:flush');
        }

        $failed = false;

        foreach ($messages as $message) {
            $message->refresh();
            $failed = $failed || $message->error !== null;
            $this->line("Message {$message->id} to {$message->recipient}: {$message->status->value}".($message->error ? " · {$message->error}" : ''));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

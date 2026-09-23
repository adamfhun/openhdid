<?php

namespace App\Console\Commands;

use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Support\PhoneNormalizer;
use Illuminate\Console\Command;

/**
 * Smoke test of the SMS gateway without touching any client.
 */
class TestSmsCommand extends Command
{
    protected $signature = 'hdid:test-sms {number : Recipient phone number} {--now : Send in this process instead of the queue}';

    protected $description = 'Send a test SMS through the configured gateway';

    public function handle(Messenger $messenger, PhoneNormalizer $phones): int
    {
        $number = $phones->normalize((string) $this->argument('number'));

        if ($number === null) {
            $this->error('Not a valid phone number.');

            return self::FAILURE;
        }

        $message = $messenger->sendRaw(Channel::Sms, $number, null, 'HDID SMS test '.now()->format('H:i'));

        if ($this->option('now')) {
            $this->callSilently('hdid:messages:flush');
        }

        $message->refresh();
        $this->line("Message {$message->id} to {$number}: {$message->status->value}".($message->error ? " · {$message->error}" : ''));

        return $message->error ? self::FAILURE : self::SUCCESS;
    }
}

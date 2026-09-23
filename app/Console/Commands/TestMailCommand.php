<?php

namespace App\Console\Commands;

use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Models\OutboundMessage;
use Illuminate\Console\Command;

/**
 * Smoke test of the e-mail transport without touching any client: queues
 * a plain message and, with --now, delivers it in this process.
 */
class TestMailCommand extends Command
{
    protected $signature = 'hdid:test-mail {address : Recipient e-mail address} {--now : Send in this process instead of the queue}';

    protected $description = 'Send a test e-mail through the configured mail transport';

    public function handle(Messenger $messenger): int
    {
        $address = (string) $this->argument('address');

        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->error('Not a valid e-mail address.');

            return self::FAILURE;
        }

        $message = $messenger->sendRaw(Channel::Email, $address, 'HDID test '.now()->format('Y-m-d H:i'), '<p>HDID mail transport test from '.e(config('app.url')).' at '.now()->toDateTimeString().'.</p>');

        return $this->report($message, (bool) $this->option('now'));
    }

    private function report(OutboundMessage $message, bool $now): int
    {
        if ($now) {
            $this->callSilently('hdid:messages:flush');
        }

        $message->refresh();
        $this->line("Message {$message->id}: {$message->status->value}".($message->error ? " · {$message->error}" : ''));

        return $message->error ? self::FAILURE : self::SUCCESS;
    }
}

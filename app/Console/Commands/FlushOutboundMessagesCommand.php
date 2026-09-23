<?php

namespace App\Console\Commands;

use App\Jobs\SendOutboundMessage;
use App\Models\OutboundMessage;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Sends every queued message right now, without a worker.
 */
class FlushOutboundMessagesCommand extends Command
{
    protected $signature = 'hdid:messages:flush';

    protected $description = 'Send all queued outbound messages synchronously';

    public function handle(Dispatcher $bus): int
    {
        $ids = OutboundMessage::query()->queued()->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $bus->dispatchNow(new SendOutboundMessage($id));
        }

        $this->info("Processed {$ids->count()} message(s).");

        return self::SUCCESS;
    }
}

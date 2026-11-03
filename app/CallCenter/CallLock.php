<?php

namespace App\CallCenter;

use App\Identification\IdentificationException;
use App\Models\Call;
use App\Models\User;
use App\System\Installation;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The lock of one call. Every write to a call, and every identification
 * decision made on it, runs under it on a freshly read row: call-center
 * events, take-over, release, moving it to another client, and the PIN,
 * code, question-and-answer and manual decisions. It is taken exactly once,
 * at each entry point: a method that holds it never calls another that
 * takes it. Lock order: the call lock first, then a client's lock and the
 * database transaction.
 */
final class CallLock
{
    public static function nameFor(string $externalCallId): string
    {
        return Installation::lockName('hdid:call:'.$externalCallId);
    }

    /**
     * @template T
     *
     * @param  Closure(Call): T  $work
     * @return T
     *
     * @throws LockTimeoutException while another action holds the call
     */
    public static function run(Call $call, Closure $work): mixed
    {
        return Cache::lock(self::nameFor((string) $call->external_call_id), 10)->block(5, function () use ($call, $work): mixed {
            $call->refresh();

            return $work($call);
        });
    }

    /**
     * An agent's decision on a call: the call has to be theirs to work on
     * (held by them, or a missed call nobody handled) in a tier they handle,
     * as it stands when the lock is taken, not when the page was loaded.
     *
     * @template T
     *
     * @param  Closure(Call): T  $work
     * @return T
     *
     * @throws IdentificationException when the call is not the agent's any more, or busy
     */
    public static function runForAgent(Call $call, User $agent, Closure $work): mixed
    {
        try {
            return self::run($call, function (Call $call) use ($agent, $work): mixed {
                if (! $call->isAttachableBy($agent) || ! in_array($call->tier, $agent->handledTiers(), true)) {
                    throw new IdentificationException(__('This call is no longer yours: it was handed back to the queue or a colleague has taken it over.'));
                }

                return $work($call);
            });
        } catch (LockTimeoutException) {
            throw new IdentificationException(__('The call is busy with another action; try again in a moment.'));
        }
    }

    /**
     * As run(), for a panel path without the agent check: a busy call is a
     * readable refusal, not a server error.
     *
     * @template T
     *
     * @param  Closure(Call): T  $work
     * @return T
     *
     * @throws IdentificationException while another action holds the call
     */
    public static function runOrRefuse(Call $call, Closure $work): mixed
    {
        try {
            return self::run($call, $work);
        } catch (LockTimeoutException) {
            throw new IdentificationException(__('The call is busy with another action; try again in a moment.'));
        }
    }
}

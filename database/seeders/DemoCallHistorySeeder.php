<?php

namespace Database\Seeders;

use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Models\Call;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Two weeks of finished calls for the dashboard's agent chart and the
 * reports: three agents, two queues, a few missed calls per day. Ended
 * calls older than a day are soft-deleted the way the call retention would
 * leave them. Idempotent: does nothing when the history already exists.
 */
class DemoCallHistorySeeder extends Seeder
{
    public const PREFIX = 'demo-hist-';

    public function run(): void
    {
        if (Call::withTrashed()->where('external_call_id', 'like', self::PREFIX.'%')->exists()) {
            return;
        }

        $agents = collect([
            [200001, 'Ági Ügynök', 'agent@example.test', 'password-agent-1'],
            [200002, 'Bence Ügynök', 'bence@example.test', 'password-agent-2'],
            [200003, 'Csilla Ügynök', 'csilla@example.test', 'password-agent-3'],
        ])->map(fn (array $a) => User::query()->where('email', $a[2])->first() ?? DemoSeeder::agent(...$a));

        // Calls per agent per day, per queue: the first agent is the busiest.
        $plan = [
            ['Ügyfélszolgálat' => 6, 'Prémium vonal' => 3],
            ['Ügyfélszolgálat' => 4, 'Prémium vonal' => 2],
            ['Ügyfélszolgálat' => 2, 'Prémium vonal' => 0],
        ];

        $n = 0;
        for ($daysAgo = 13; $daysAgo >= 1; $daysAgo--) {
            $day = today()->subDays($daysAgo);
            // Weekends are quieter.
            $factor = $day->isWeekend() ? 0.3 : 1.0;

            foreach ($agents as $i => $agent) {
                foreach ($plan[$i] as $queue => $perDay) {
                    $count = (int) round($perDay * $factor * (0.7 + (($daysAgo * 7 + $i * 3) % 7) / 10));
                    for ($k = 0; $k < $count; $k++) {
                        $arrived = $day->copy()->setTime(8 + ($k * 2 + $i) % 10, ($k * 17 + $daysAgo * 3) % 60);
                        $call = Call::query()->create([
                            'external_call_id' => self::PREFIX.(++$n),
                            'caller_number_raw' => '3630'.str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT),
                            'caller_number_e164' => '+3630'.str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT),
                            'agent_user_id' => $agent->id,
                            'status' => CallStatus::Ended,
                            'queue' => $queue,
                            'tier' => $queue === 'Prémium vonal' ? ClientTier::Premium : ClientTier::Standard,
                            'arrived_at' => $arrived,
                            'answered_at' => $arrived->copy()->addSeconds(20),
                            'ended_at' => $arrived->copy()->addMinutes(2 + ($k + $i) % 6),
                        ]);
                        $call->delete();
                    }
                }
            }

            for ($m = 0; $m < ($day->isWeekend() ? 1 : 2); $m++) {
                $arrived = $day->copy()->setTime(12 + $m, 15);
                $call = Call::query()->create([
                    'external_call_id' => self::PREFIX.(++$n),
                    'caller_number_raw' => '3620'.str_pad((string) (2000000 + $n), 7, '0', STR_PAD_LEFT),
                    'caller_number_e164' => '+3620'.str_pad((string) (2000000 + $n), 7, '0', STR_PAD_LEFT),
                    'status' => CallStatus::Missed,
                    'queue' => 'Ügyfélszolgálat',
                    'tier' => ClientTier::Standard,
                    'arrived_at' => $arrived,
                    'ended_at' => $arrived->copy()->addMinute(),
                    'handled_at' => $m === 0 ? $arrived->copy()->addHour() : null,
                    'handled_by_user_id' => $m === 0 ? $agents[1]->id : null,
                ]);
                $call->delete();
            }
        }
    }
}

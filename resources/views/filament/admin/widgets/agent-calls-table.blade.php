@php
    use App\Reporting\AgentCallStats;
    use App\Support\HuDate;
    use Carbon\CarbonImmutable;
@endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('Calls taken by agent and queue, per day') }}</x-slot>
        <x-slot name="description">{{ __('Last :days days. Each cell lists the calls per queue; the last column and the last row are totals.', ['days' => $days]) }}</x-slot>

        @if ($summary->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No agent took a call in this period.') }}</p>
        @else
            @if (count($summary->queues) > 1)
                <div class="mb-3 flex flex-wrap gap-4 text-xs text-gray-600 dark:text-gray-300">
                    @foreach ($summary->queues as $i => $queue)
                        <span class="inline-flex items-center gap-1.5">
                            <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ AgentCallStats::colorFor($i) }}"></span>
                            {{ AgentCallStats::queueLabel($queue) }}
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-3 font-medium">{{ __('Agent') }}</th>
                            @foreach ($summary->days as $day)
                                <th class="px-2 py-2 text-right font-medium">{{ CarbonImmutable::parse($day)->format(HuDate::MONTH_DAY) }}</th>
                            @endforeach
                            <th class="py-2 pl-3 text-right font-medium">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($summary->agents as $agent)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-3 font-semibold whitespace-nowrap">{{ $agent['name'] }}</td>
                                @foreach ($summary->days as $day)
                                    <td class="px-2 py-2 text-right align-top tabular-nums">
                                        @if ($summary->dayCount($agent, $day) === 0)
                                            <span class="text-gray-300 dark:text-gray-600">–</span>
                                        @elseif (count($summary->queues) === 1)
                                            {{ $summary->dayCount($agent, $day) }}
                                        @else
                                            @foreach ($summary->queues as $i => $queue)
                                                @if ($summary->count($agent, $day, $queue) > 0)
                                                    <div class="inline-flex items-center justify-end gap-1 w-full">
                                                        <span class="inline-block h-1.5 w-1.5 rounded-full" style="background: {{ AgentCallStats::colorFor($i) }}"></span>{{ $summary->count($agent, $day, $queue) }}
                                                    </div>
                                                @endif
                                            @endforeach
                                        @endif
                                    </td>
                                @endforeach
                                <td class="py-2 pl-3 text-right align-top tabular-nums">
                                    @if (count($summary->queues) > 1)
                                        @foreach ($summary->queues as $i => $queue)
                                            @if (($agent['by_queue'][$queue] ?? 0) > 0)
                                                <div class="inline-flex items-center justify-end gap-1 w-full text-gray-600 dark:text-gray-300">
                                                    <span class="inline-block h-1.5 w-1.5 rounded-full" style="background: {{ AgentCallStats::colorFor($i) }}"></span>{{ $agent['by_queue'][$queue] }}
                                                </div>
                                            @endif
                                        @endforeach
                                    @endif
                                    <div class="font-bold">{{ $agent['total'] }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-bold">
                            <td class="py-2 pr-3">{{ __('Total') }}</td>
                            @foreach ($summary->days as $day)
                                <td class="px-2 py-2 text-right tabular-nums">{{ $summary->dayTotals[$day] ?? 0 }}</td>
                            @endforeach
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $summary->total }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

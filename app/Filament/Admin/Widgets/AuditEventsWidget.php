<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\Filament\Admin\Widgets\Concerns\AuthorizesEveryRequest;
use App\Models\AuditLog;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/**
 * Which events fill the audit log: the counts of the last seven days by
 * event name, so a noisy producer is seen from the data, not guessed.
 */
class AuditEventsWidget extends Widget
{
    use AuthorizesEveryRequest;

    public const DAYS = 7;

    public const CACHE_SECONDS = 300;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.audit-events';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::AuditView->value) ?? false;
    }

    /**
     * @return list<array{event: string, n: int}>
     */
    public function getEvents(): array
    {
        return Cache::remember('hdid.audit.events.'.self::DAYS, self::CACHE_SECONDS, fn (): array => AuditLog::query()
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->selectRaw('event, COUNT(*) as n')
            ->groupBy('event')
            ->orderByDesc('n')
            ->orderBy('event')
            ->limit(20)
            ->get()
            ->map(fn ($row): array => ['event' => (string) $row->event, 'n' => (int) $row->n])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $events = $this->getEvents();

        return ['events' => $events, 'total' => (int) array_sum(array_column($events, 'n')), 'days' => self::DAYS];
    }
}

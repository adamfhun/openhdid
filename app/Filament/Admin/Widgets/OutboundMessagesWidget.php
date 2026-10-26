<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\Enums\OutboundMessageStatus;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Filament\Admin\Widgets\Concerns\AuthorizesEveryRequest;
use App\Filament\Concerns\LimitsSearchInput;
use App\Models\OutboundMessage;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The outbound message list on the Queues page: the very table of
 * Content › Outbound messages (same columns, filters and actions), so the
 * e-mails and SMS are handled next to the jobs that carry them.
 */
class OutboundMessagesWidget extends TableWidget
{
    use AuthorizesEveryRequest;
    use LimitsSearchInput;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public function table(Table $table): Table
    {
        $queued = OutboundMessage::query()->queued()->count();
        $failed = OutboundMessage::query()->where('status', OutboundMessageStatus::Failed)->where('created_at', '>=', now()->subDay())->count();

        return OutboundMessageResource::table($table->query(fn (): Builder => OutboundMessage::query()))
            ->heading(__('Outbound messages'))
            ->description(__(':q queued · :f failed in the last 24 hours. The same list and actions as Content › Outbound messages.', ['q' => $queued, 'f' => $failed]));
    }
}

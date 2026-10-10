<?php

namespace App\Filament\Admin\Resources\OutboundMessages;

use App\Auth\Permission;
use App\Clients\ClientTiers;
use App\Enums\OutboundMessageStatus;
use App\Filament\Admin\Clusters\Content;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ManageOutboundMessages;
use App\Messaging\Channel;
use App\Messaging\MessageKey;
use App\Messaging\MessageNotRetryableException;
use App\Messaging\Messenger;
use App\Models\OutboundMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Every e-mail and SMS the system tried to send, with its outcome.
 */
class OutboundMessageResource extends BaseResource
{
    protected static ?string $model = OutboundMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $cluster = Content::class;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('outbound message');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Outbound messages');
    }

    public static function getNavigationLabel(): string
    {
        return __('Outbound messages');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('channel')->label(__('Channel'))->badge()->formatStateUsing(fn (Channel $state) => $state->label()),
                TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (OutboundMessageStatus $state) => $state->label())->color(fn (OutboundMessageStatus $state) => $state->color()),
                TextEntry::make('template_key')->label(__('Message'))->formatStateUsing(fn (?MessageKey $state) => $state?->label())->placeholder('-'),
                TextEntry::make('recipient')->label(__('Recipient'))
                    ->state(fn (OutboundMessage $record): string => static::visibleRecipient($record)),
                TextEntry::make('client.name')->label(__('Client'))->placeholder('-')
                    ->state(fn (OutboundMessage $record): ?string => app(ClientTiers::class)->servedName($record->client)),
                TextEntry::make('attempts')->label(__('Attempts')),
                TextEntry::make('created_at')->label(__('Queued'))->dateTime(),
                TextEntry::make('last_attempt_at')->label(__('Last attempt'))->dateTime()->placeholder('-'),
                TextEntry::make('sent_at')->label(__('Sent'))->dateTime()->placeholder('-'),
                TextEntry::make('error')->label(__('Error'))->color('danger')->placeholder('-')->columnSpanFull()
                    ->state(fn (OutboundMessage $record): ?string => static::visibleText($record, $record->error)),
                TextEntry::make('subject')->label(__('Subject'))->placeholder('-')->columnSpanFull()
                    ->state(fn (OutboundMessage $record): ?string => static::visibleText($record, $record->subject)),
                TextEntry::make('body')->label(__('Body'))->columnSpanFull()
                    ->state(fn (OutboundMessage $record) => match (true) {
                        static::hiddenClient($record) => __('hidden (client not served at present)'),
                        $record->carriesSecret() => __('Confidential content (PIN, code or login link): not shown, and wiped once the message is out.'),
                        $record->channel === Channel::Email => new HtmlString('<iframe title="message" sandbox="" class="w-full rounded-lg border border-gray-200 bg-white dark:border-white/10" style="height: 480px" srcdoc="'.e($record->body).'"></iframe>'),
                        default => $record->body,
                    }),
            ]),
        ]);
    }

    /**
     * A message to a client no longer served (premium-only mode) keeps its
     * address, subject, body and error text out of view, like the client;
     * a search by address does not find it either.
     */
    public static function hiddenClient(OutboundMessage $record): bool
    {
        return $record->client_id !== null && ! app(ClientTiers::class)->isServed($record->client);
    }

    public static function visibleRecipient(OutboundMessage $record): string
    {
        return static::hiddenClient($record) ? __('hidden (client not served at present)') : $record->recipient;
    }

    public static function visibleText(OutboundMessage $record, ?string $text): ?string
    {
        return app(ClientTiers::class)->servedText($record->client, $text);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client'))
            ->columns([
                TextColumn::make('created_at')->label(__('Queued'))->dateTime()->sortable(),
                TextColumn::make('channel')->label(__('Channel'))->badge()->formatStateUsing(fn (Channel $state) => $state->label()),
                TextColumn::make('template_key')->label(__('Message'))->formatStateUsing(fn (?MessageKey $state) => $state?->label())->placeholder(__('test / manual')),
                TextColumn::make('recipient')->label(__('Recipient'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('recipient', 'like', '%'.$search.'%')
                        ->where(fn (Builder $served) => $served->whereNull('client_id')
                            ->orWhereHas('client', fn (Builder $client) => app(ClientTiers::class)->scopeServed($client))))
                    ->state(fn (OutboundMessage $record): string => static::visibleRecipient($record))
                    ->description(fn (OutboundMessage $record): ?string => app(ClientTiers::class)->servedName($record->client)),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (OutboundMessageStatus $state) => $state->label())->color(fn (OutboundMessageStatus $state) => $state->color())->sortable(),
                TextColumn::make('attempts')->label(__('Attempts'))->alignCenter(),
                TextColumn::make('sent_at')->label(__('Sent'))->since()->placeholder('-'),
                TextColumn::make('error')->label(__('Error'))->limit(50)->placeholder('-')
                    ->state(fn (OutboundMessage $record): ?string => static::visibleText($record, $record->error))
                    ->tooltip(fn (OutboundMessage $record): ?string => static::visibleText($record, $record->error)),
            ])
            ->filters([
                SelectFilter::make('channel')->label(__('Channel'))->options(collect(Channel::cases())->mapWithKeys(fn (Channel $c) => [$c->value => $c->label()])->all()),
                SelectFilter::make('status')->label(__('Status'))->options(collect(OutboundMessageStatus::cases())->mapWithKeys(fn (OutboundMessageStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
                Action::make('retry')->label(__('Retry'))->icon('heroicon-o-arrow-path')->color('warning')->requiresConfirmation()
                    ->modalDescription(__('The same message is queued again and sent to the same recipient; an SMS costs again.'))
                    ->authorize(fn () => auth()->user()?->can(Permission::SettingsManage->value) ?? false)
                    ->visible(fn (OutboundMessage $record) => $record->status === OutboundMessageStatus::Failed)
                    ->disabled(fn (OutboundMessage $record) => $record->isRedacted() || static::hiddenClient($record))
                    ->tooltip(fn (OutboundMessage $record) => match (true) {
                        static::hiddenClient($record) => __('The client is not served at present (only premium clients are served), so nothing is sent to them.'),
                        $record->isRedacted() => __('The confidential content was wiped after the final failure; send a new message instead.'),
                        default => null,
                    })
                    ->action(function (OutboundMessage $record): void {
                        try {
                            app(Messenger::class)->retry($record);
                        } catch (MessageNotRetryableException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('cancel')->label(__('Cancel'))->icon('heroicon-o-x-mark')->color('gray')
                    ->authorize(fn () => auth()->user()?->can(Permission::SettingsManage->value) ?? false)
                    ->visible(fn (OutboundMessage $record) => $record->status === OutboundMessageStatus::Queued)
                    ->action(function (OutboundMessage $record): void {
                        if (! app(Messenger::class)->cancel($record)) {
                            Notification::make()->title(__('The message was already picked up for sending and cannot be cancelled.'))->warning()->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return ['index' => ManageOutboundMessages::route('/')];
    }
}

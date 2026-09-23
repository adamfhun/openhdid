<?php

namespace App\Filament\Admin\Resources\AuditLogs;

use App\Filament\Admin\Clusters\System;
use App\Filament\Admin\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Admin\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\Calls\CallResource;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\ExternalRecords\ExternalRecordResource;
use App\Filament\Admin\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Admin\Resources\NewsPosts\NewsPostResource;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Filament\Admin\Resources\Questions\QuestionResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\SyncRuns\SyncRunResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Models\ClientAnswer;
use App\Models\ClientLink;
use App\Models\ClientPhoneNumber;
use App\Models\ExternalRecord;
use App\Models\IdSession;
use App\Models\MessageTemplate;
use App\Models\NewsPost;
use App\Models\OneTimeCode;
use App\Models\OutboundMessage;
use App\Models\Question;
use App\Models\QuestionVersion;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\HuDate;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AuditLogResource extends BaseResource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 9;

    public static function getModelLabel(): string
    {
        return __('audit entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Audit log');
    }

    public static function getNavigationLabel(): string
    {
        return __('Audit log');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label(__('Time'))->dateTime(),
            TextEntry::make('event')->label(__('Event')),
            TextEntry::make('actor')->label(__('Actor'))->state(fn (AuditLog $record) => static::actorLabel($record))->url(fn (AuditLog $record) => static::describe($record->actor)['url'])->placeholder(__('system')),
            TextEntry::make('subject')->label(__('Subject'))->state(fn (AuditLog $record) => static::subjectLabel($record))->url(fn (AuditLog $record) => static::describe($record->subject)['url'])->placeholder('-'),
            TextEntry::make('actor_id')->label(__('Actor id'))->placeholder('-')->copyable()->fontFamily('mono')
                ->visible(fn (AuditLog $record) => $record->actor_id !== null && static::describe($record->actor)['url'] === null),
            TextEntry::make('subject_id')->label(__('Subject id'))->placeholder('-')->copyable()->fontFamily('mono')
                ->visible(fn (AuditLog $record) => $record->subject_id !== null && static::describe($record->subject)['url'] === null),
            TextEntry::make('ip_address')->label(__('IP address'))->placeholder('-'),
            TextEntry::make('request_id')->label(__('Request id'))->placeholder(__('none'))->copyable()->fontFamily('mono')
                ->helperText(__('Every entry written while serving one web request shares this id and the list can be filtered by it; scheduled tasks, commands and entries from before this feature have none.')),
            TextEntry::make('user_agent')->label(__('User agent'))->placeholder('-')->columnSpanFull(),
            TextEntry::make('context')->label(__('Details'))->columnSpanFull()->placeholder('-')->copyable()->fontFamily('mono')
                ->state(fn (AuditLog $record) => filled($record->context) ? json_encode($record->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'actor',
                'subject' => fn (MorphTo $subject) => $subject->morphWith([
                    Call::class => ['client'],
                    IdSession::class => ['client'],
                    ClientPhoneNumber::class => ['client'],
                    ClientAnswer::class => ['client'],
                    QuestionVersion::class => ['question'],
                    ClientLink::class => ['sponsor', 'linked'],
                    OneTimeCode::class => ['client'],
                    Question::class => ['currentVersion'],
                ]),
            ]))
            ->columns([
                TextColumn::make('created_at')->label(__('Time'))->dateTime(HuDate::DATETIME_SECONDS)->sortable(),
                TextColumn::make('event')->label(__('Event'))->searchable()->badge(),
                TextColumn::make('actor')->label(__('Actor'))->state(fn (AuditLog $record) => static::actorLabel($record))
                    ->url(fn (AuditLog $record) => static::describe($record->actor)['url'])->color(fn (AuditLog $record) => $record->actor ? 'primary' : null)
                    ->placeholder(__('system')),
                TextColumn::make('actor_id')->label(__('Actor id'))->searchable()->copyable()->fontFamily('mono')->size('xs')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('subject')->label(__('Subject'))->state(fn (AuditLog $record) => static::subjectLabel($record))
                    ->url(fn (AuditLog $record) => static::describe($record->subject)['url'])->color(fn (AuditLog $record) => $record->subject ? 'primary' : null)
                    ->placeholder('-')->wrap(),
                TextColumn::make('subject_id')->label(__('Subject id'))->searchable()->copyable()->fontFamily('mono')->size('xs')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ip_address')->label(__('IP address'))->placeholder('-')->toggleable(),
            ])
            ->filters([
                Filter::make('event')->label(__('Event'))->schema([TextInput::make('event')->label(__('Event'))])->query(fn (Builder $query, array $data) => $query->when($data['event'], fn ($q, $v) => $q->where('event', 'like', $v.'%'))),
                Filter::make('subject_id')->schema([TextInput::make('subject_id')->label(__('Subject id'))])->query(fn (Builder $query, array $data) => $query->when($data['subject_id'], fn ($q, $v) => $q->where('subject_id', $v))),
                Filter::make('actor_id')->schema([TextInput::make('actor_id')->label(__('Actor id'))])->query(fn (Builder $query, array $data) => $query->when($data['actor_id'], fn ($q, $v) => $q->where('actor_id', $v))),
                Filter::make('request_id')->schema([TextInput::make('request_id')->label(__('Request id'))])->query(fn (Builder $query, array $data) => $query->when($data['request_id'], fn ($q, $v) => $q->where('request_id', $v))),
                Filter::make('period')->label(__('Period'))->schema([
                    DatePicker::make('from')->label(__('From')),
                    DatePicker::make('until')->label(__('Until')),
                ])->columns(2)->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
                    ->when($data['until'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v)->addDay()->startOfDay()))),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc');
    }

    /**
     * Who did it, in words: the name of the user or client, or the type and
     * a short id when the account is gone.
     */
    public static function actorLabel(AuditLog $log): ?string
    {
        if ($log->actor_type === null) {
            return null;
        }

        return static::describe($log->actor)['label'] ?? static::fallbackLabel($log->actor_type, $log->actor_id);
    }

    public static function subjectLabel(AuditLog $log): ?string
    {
        if ($log->subject_type === null) {
            return null;
        }

        return static::describe($log->subject)['label'] ?? static::fallbackLabel($log->subject_type, $log->subject_id);
    }

    /**
     * Human label and, where the panel has a page for it, a link to the record
     * itself. Child records of a client (session, phone number, link, code,
     * answer) lead to the client, whose page lists them. The ids in the modal
     * only appear when there is nothing to link to (no page, or the record
     * is gone for good).
     *
     * @return array{label: ?string, url: ?string}
     */
    public static function describe(?Model $model): array
    {
        return match (true) {
            $model === null => ['label' => null, 'url' => null],
            $model instanceof User => ['label' => $model->name, 'url' => UserResource::getUrl('edit', ['record' => $model])],
            $model instanceof Client => ['label' => $model->name, 'url' => ClientResource::getUrl('view', ['record' => $model])],
            $model instanceof Call => [
                'label' => __('Call').' · '.($model->callerNumber() ?? '?').($model->client ? ' · '.$model->client->name : '').' · '.$model->arrived_at?->format(HuDate::DATETIME),
                'url' => $model->external_call_id ? CallResource::getUrl('index', ['tableSearch' => $model->external_call_id]) : null,
            ],
            $model instanceof IdSession => [
                'label' => __('Identification').' · '.($model->client?->name ?? '?').' · '.$model->started_at?->format(HuDate::DATETIME),
                'url' => $model->client ? ClientResource::getUrl('view', ['record' => $model->client]) : null,
            ],
            $model instanceof ClientPhoneNumber => [
                'label' => __('Phone number').' · '.$model->number_e164.($model->client ? ' · '.$model->client->name : ''),
                'url' => $model->client ? ClientResource::getUrl('view', ['record' => $model->client]) : null,
            ],
            $model instanceof ClientLink => [
                'label' => __('Link').' · '.($model->sponsor?->name ?? '?').' → '.($model->linked?->name ?? '?'),
                'url' => $model->sponsor ? ClientResource::getUrl('view', ['record' => $model->sponsor]) : null,
            ],
            $model instanceof OneTimeCode => [
                'label' => __('One-time code').($model->client ? ' · '.$model->client->name : ''),
                'url' => $model->client ? ClientResource::getUrl('view', ['record' => $model->client]) : null,
            ],
            $model instanceof ClientAnswer => [
                'label' => __('Answer').($model->client ? ' · '.$model->client->name : ''),
                'url' => $model->client ? ClientResource::getUrl('view', ['record' => $model->client]) : null,
            ],
            $model instanceof Question => ['label' => __('Question').' · '.Str::limit((string) $model->currentVersion?->text, 60), 'url' => QuestionResource::getUrl('view', ['record' => $model])],
            $model instanceof QuestionVersion => [
                'label' => __('Question version').' '.$model->version.' · '.Str::limit((string) $model->text, 60),
                'url' => $model->question ? QuestionResource::getUrl('view', ['record' => $model->question]) : null,
            ],
            $model instanceof Role => ['label' => __('Role').' · '.$model->name, 'url' => RoleResource::getUrl('index')],
            $model instanceof ApiKey => ['label' => __('API key').' · '.$model->name, 'url' => ApiKeyResource::getUrl('index')],
            $model instanceof SyncRun => ['label' => __('EMD sync run').' · '.$model->started_at?->format(HuDate::DATETIME), 'url' => SyncRunResource::getUrl('index')],
            $model instanceof ExternalRecord => ['label' => __('EMD record').' · '.($model->name ?: $model->email), 'url' => ExternalRecordResource::getUrl('index', ['tableSearch' => $model->email])],
            $model instanceof OutboundMessage => ['label' => __('Message').' · '.$model->recipient, 'url' => OutboundMessageResource::getUrl('index')],
            $model instanceof NewsPost => ['label' => __('News').' · '.$model->title, 'url' => NewsPostResource::getUrl('index')],
            $model instanceof MessageTemplate => ['label' => $model->key->label().' · '.strtoupper($model->locale), 'url' => MessageTemplateResource::getUrl('edit', ['record' => $model])],
            default => ['label' => static::fallbackLabel($model->getMorphClass(), (string) $model->getKey()), 'url' => null],
        };
    }

    private static function fallbackLabel(string $type, ?string $id): string
    {
        return class_basename($type).($id ? ' · '.Str::limit($id, 8, '…') : '');
    }

    public static function getPages(): array
    {
        return ['index' => ManageAuditLogs::route('/')];
    }
}

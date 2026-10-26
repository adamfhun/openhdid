<?php

namespace App\Filament\Admin\Resources\Clients\RelationManagers;

use App\Auth\Permission;
use App\Clients\ClientLinks;
use App\Clients\ClientTiers;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Concerns\LimitsSearchInput;
use App\Filament\Concerns\RequiresServedOwner;
use App\Models\Client;
use App\Models\ClientLink;
use App\Support\InputLimits;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Clients whose premium access comes from this client (the sponsor).
 * Ended links stay listed with their end date.
 */
class LinkedClientsRelationManager extends RelationManager
{
    use LimitsSearchInput;
    use RequiresServedOwner;

    protected static string $relationship = 'sponsoredLinks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Linked clients');
    }

    /**
     * The client permission first (the links have no policy of their own,
     * so Filament's own viewAny check does not apply here), then whether the
     * client sponsors or ever sponsored anybody.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! (auth()->user()?->can(Permission::ClientsView->value) ?? false)) {
            return false;
        }

        return app(ClientLinks::class)->canSponsor($ownerRecord) || $ownerRecord->sponsoredLinks()->exists();
    }

    /**
     * Clients a sponsor can link, by name, e-mail, company or external id:
     * served now, not already linked, without a premium of their own.
     *
     * @return array<string, string>
     */
    public static function linkableClients(Client $sponsor, string $search): array
    {
        $links = app(ClientLinks::class);

        return static::servedClients()
            ->with(['externalRecord', 'phoneNumbers'])
            ->whereKeyNot($sponsor->getKey())
            ->whereDoesntHave('sponsorLinks', fn (Builder $q) => $q->whereNull('ended_at'))
            ->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('externalRecord', fn (Builder $r) => $r->where('company', 'like', "%{$search}%")->orWhere('external_id', ctype_digit($search) ? (int) $search : -1)))
            ->orderBy('name')->limit(20)->get()
            ->filter(fn (Client $c) => $links->needsSponsor($c))
            ->mapWithKeys(fn (Client $c) => [$c->id => static::optionLabel($c)])->all();
    }

    /**
     * @return Builder<Client>
     */
    private static function servedClients(): Builder
    {
        return Client::query()->tap(fn (Builder $query) => app(ClientTiers::class)->scopeServed($query));
    }

    /**
     * Enough detail to tell two similar names apart.
     */
    public static function optionLabel(Client $client): string
    {
        $details = array_filter([
            $client->email,
            $client->externalRecord?->company,
            $client->externalRecord?->external_id ? __('id :id', ['id' => $client->externalRecord->external_id]) : null,
            $client->primaryPhoneNumber(),
            trim(($client->implicit_package ?? '-').' / '.($client->explicit_package ?? '-')),
            $client->isClosed() ? __('closed') : null,
        ]);

        return '<div class="py-0.5"><div class="font-semibold">'.e($client->name).'</div><div class="text-xs text-gray-500">'.e(implode(' · ', $details)).'</div></div>';
    }

    /**
     * Why the "Link a client" button is greyed out right now, or null when
     * usable. The button stays on the tab so nobody has to guess where it went.
     */
    public static function linkBlocker(Client $sponsor, ClientLinks $links): ?string
    {
        if ($sponsor->isClosed()) {
            return __('The account is closed; reopen it first.');
        }

        if (! $links->canSponsor($sponsor)) {
            return __('Only a client with an implicit premium package can have linked clients.');
        }

        return null;
    }

    public function table(Table $table): Table
    {
        $links = app(ClientLinks::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['linked', 'createdBy', 'endedBy'])
                // Premium-only hides the links of clients not served now; a deleted client's link stays listed.
                ->when(app(ClientTiers::class)->premiumOnly(), fn (Builder $links) => $links
                    ->whereHas('linked', fn (Builder $linked) => app(ClientTiers::class)->scopeServed($linked->withTrashed()))))
            ->columns([
                TextColumn::make('linked.name')->label(__('Client'))->weight('semibold')
                    ->description(fn (ClientLink $record) => $record->linked?->email)
                    ->url(fn (ClientLink $record) => $record->linked ? ClientResource::getUrl('view', ['record' => $record->linked]) : null),
                TextColumn::make('linked.closed_at')->label(__('Account'))->badge()
                    ->state(fn (ClientLink $record) => $record->linked?->isClosed() ? __('Closed (:r)', ['r' => __((string) $record->linked->closed_reason)]) : __('Open'))
                    ->color(fn (ClientLink $record) => $record->linked?->isClosed() ? 'danger' : 'success'),
                TextColumn::make('created_at')->label(__('Linked on'))->dateTime()->description(fn (ClientLink $record) => $record->createdBy?->name),
                TextColumn::make('ended_at')->label(__('Ended on'))->dateTime()->placeholder(__('active'))
                    ->description(fn (ClientLink $record) => $record->endedBy?->name ?? ($record->ended_reason ? __('link-end.'.$record->ended_reason) : null)),
            ])
            ->filters([
                TernaryFilter::make('active')->label(__('Active link'))->default(true)->queries(
                    true: fn (Builder $query) => $query->whereNull('ended_at'),
                    false: fn (Builder $query) => $query->whereNotNull('ended_at'),
                ),
            ])
            ->headerActions([
                Action::make('link')
                    ->label(__('Link a client'))
                    ->icon('heroicon-o-link')
                    ->authorize(fn () => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->disabled(fn () => ! $links->canSponsor($this->getOwnerRecord()))
                    ->tooltip(fn () => static::linkBlocker($this->getOwnerRecord(), $links))
                    ->schema([
                        Select::make('client_id')->label(__('Client'))->required()->searchable()->allowHtml()
                            ->getSearchResultsUsing(fn (string $search) => static::linkableClients($this->getOwnerRecord(), InputLimits::search($search)))
                            ->getOptionLabelUsing(fn ($value) => ($c = static::servedClients()->with(['externalRecord', 'phoneNumbers'])->find($value)) ? static::optionLabel($c) : null)
                            ->helperText(__('Only clients without an implicit premium package of their own can be linked, and each client can be linked to one sponsor at a time.')),
                    ])
                    ->action(function (array $data) use ($links): void {
                        try {
                            $links->link($this->getOwnerRecord(), static::servedClients()->findOrFail($data['client_id']), auth()->user());
                            Notification::make()->title(__('Client linked'))->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title($e->validator->errors()->first())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('unlink')->label(__('End link'))->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()
                    ->modalDescription(__('The link ends now and stays in the list with its end date. The linked account is not closed by this.'))
                    ->authorize(fn () => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->visible(fn (ClientLink $record) => $record->isActive())
                    ->action(fn (ClientLink $record) => $links->unlink($record, auth()->user())),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

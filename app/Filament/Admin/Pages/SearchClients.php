<?php

namespace App\Filament\Admin\Pages;

use App\Audit\Auditor;
use App\Auth\Permission;
use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Filament\Admin\NavigationGroup;
use App\Identification\ClientAnswers;
use App\Identification\ClientSearch;
use App\Models\Call;
use App\Models\Client;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

class SearchClients extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?int $navigationSort = 1;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Helpdesk;

    protected string $view = 'filament.admin.search-clients';

    #[Url]
    public string $q = '';

    #[Url]
    public ?string $call = null;

    public static function getNavigationLabel(): string
    {
        return __('Find client');
    }

    public function getTitle(): string
    {
        return __('Find client');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ClientsView->value) ?? false;
    }

    public const LIMIT = 20;

    /**
     * One row more than shown, so the page can say the list is cut.
     *
     * @return Collection<int, Client>
     */
    public function getFoundProperty(): Collection
    {
        return app(ClientSearch::class)->search($this->q, self::LIMIT + 1);
    }

    /**
     * @return Collection<int, Client>
     */
    public function getResultsProperty(): Collection
    {
        return $this->found->take(self::LIMIT);
    }

    public function getIsTruncatedProperty(): bool
    {
        return $this->found->count() > self::LIMIT;
    }

    /**
     * Who searched for what is part of the trail (results are not: they
     * are visible through the client.viewed entries anyway).
     */
    public function updatedQ(string $value): void
    {
        if (mb_strlen(trim($value)) >= 3) {
            app(Auditor::class)->record('client.searched', null, ['term' => trim($value), 'call_id' => $this->call]);
        }
    }

    public function getCallRecordProperty(): ?Call
    {
        return $this->call ? Call::query()->find($this->call) : null;
    }

    public function tierOf(Client $client): ?ClientTier
    {
        return app(ClientTiers::class)->tierFor($client);
    }

    public function usableAnswers(Client $client): int
    {
        return app(ClientAnswers::class)->usableCount($client);
    }

    public function identifyUrl(Client $client): string
    {
        return Identify::getUrl(array_filter(['client' => $client->id, 'call' => $this->call]));
    }
}

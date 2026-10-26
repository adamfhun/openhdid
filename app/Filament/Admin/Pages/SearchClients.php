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
use App\Support\InputLimits;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
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
        return app(ClientSearch::class)->search(InputLimits::search($this->q), self::LIMIT + 1);
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
     * A search opened from the address (?q=…, a link or a bookmark) is cut
     * and recorded like a typed one: otherwise it would leave no trail.
     */
    public function mount(): void
    {
        $this->recordSearch($this->q);
    }

    /**
     * Who searched for what is part of the trail (results are not: they
     * are visible through the client.viewed entries anyway).
     */
    public function updatedQ(string $value): void
    {
        $this->recordSearch($value);
    }

    private function recordSearch(string $value): void
    {
        // The box takes at most the search limit; a longer value sent past
        // it is cut here, for the query and the audit log alike.
        if (mb_strlen($value) > InputLimits::SEARCH) {
            $this->q = mb_substr($value, 0, InputLimits::SEARCH);
        }

        $term = InputLimits::search($value);

        if (mb_strlen($term) >= 3) {
            app(Auditor::class)->record('client.searched', null, ['term' => $term, 'call_id' => $this->callId()]);
        }
    }

    public function getCallRecordProperty(): ?Call
    {
        return ($id = $this->callId()) !== null ? Call::query()->find($id) : null;
    }

    /**
     * The call from the address, when it is a UUID at all; anything else
     * names no call (and would not even compare with the UUID column on
     * MariaDB).
     */
    private function callId(): ?string
    {
        return is_string($this->call) && Str::isUuid($this->call) ? $this->call : null;
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
        return Identify::getUrl(array_filter(['client' => $client->id, 'call' => $this->callId()]));
    }
}

<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Permission;
use App\CallCenter\CallCenterService;
use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Identification\ClientAnswers;
use App\Identification\IdentificationException;
use App\Identification\MobileOtpService;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Identification by the code the caller dictates. The code is unique across
 * the system, so a correct code both finds and identifies the client.
 */
class VerifyCode extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?int $navigationSort = 2;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Helpdesk;

    protected static ?string $slug = 'verify-code';

    protected string $view = 'filament.admin.verify-code';

    #[Url]
    public ?string $call = null;

    /** @var array<string, mixed> */
    public ?array $data = ['code' => ''];

    #[Locked]
    public ?string $sessionId = null;

    public bool $failed = false;

    public static function getNavigationLabel(): string
    {
        return __('Verify code');
    }

    public function getTitle(): string
    {
        return __('Verify code');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::IdentificationRun->value) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(['code' => '']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                OneTimeCodeInput::make('code')
                    ->label(__('Code dictated by the caller'))
                    ->length(fn (): int => app(MobileOtpService::class)->length())
                    ->autofocus()
                    ->required()
                    ->extraAttributes(['class' => 'hdid-dictation-code'])
                    ->live()
                    ->afterStateUpdated(function (?string $state): void {
                        if (strlen((string) $state) === app(MobileOtpService::class)->length()) {
                            $this->verify();
                        }
                    }),
            ])
            ->statePath('data');
    }

    public function verify(): void
    {
        $code = (string) ($this->form->getState()['code'] ?? '');
        try {
            $session = app(CallCenterService::class)->verifyCodeForAgent($code, auth()->user(), $this->call);
        } catch (IdentificationException $e) {
            $this->form->fill(['code' => '']);
            $this->dispatch('hdid-code-reset');
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            return;
        }

        if ($session === null) {
            $this->failed = true;
            $this->sessionId = null;
            $this->form->fill(['code' => '']);
            $this->dispatch('hdid-code-reset');
            Notification::make()->title(__('Unknown or expired code'))->danger()->send();

            return;
        }

        $this->failed = false;
        $this->sessionId = $session->id;
        $this->form->fill(['code' => '']);
        Notification::make()->title(__('Identified: :name', ['name' => $session->client->name]))->success()->send();
    }

    public function startOver(): void
    {
        $this->sessionId = null;
        $this->failed = false;
        $this->form->fill(['code' => '']);
    }

    public function getSession(): ?IdSession
    {
        return $this->sessionId ? IdSession::query()->where('agent_user_id', auth()->id())->with(['client.phoneNumbers', 'client.externalRecord'])->find($this->sessionId) : null;
    }

    public function getIdentifiedClient(): ?Client
    {
        return $this->getSession()?->client;
    }

    public function getCall(): ?Call
    {
        return $this->call === null ? null : app(CallCenterService::class)->heldCallForAgent($this->call, auth()->user());
    }

    public function tierOf(Client $client): ?ClientTier
    {
        return app(ClientTiers::class)->tierFor($client);
    }

    public function usableAnswers(Client $client): int
    {
        return app(ClientAnswers::class)->usableCount($client);
    }

    public function clientUrl(Client $client): string
    {
        return ClientResource::getUrl('view', ['record' => $client]);
    }

    public function dashboardUrl(): string
    {
        return Dashboard::getUrl();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('another')->label(__('Check another code'))->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn () => $this->sessionId !== null)
                ->action(fn () => $this->startOver()),
        ];
    }
}

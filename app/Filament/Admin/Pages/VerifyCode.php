<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Permission;
use App\CallCenter\CallCenterService;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Identification\IdentificationException;
use App\Identification\MobileOtpService;
use App\Models\Call;
use BackedEnum;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Identification by the one-time code the caller reads out. The code is unique across
 * the system, so a correct code both finds and identifies the client; the agent then
 * lands on the client's record (AZO-07). Opened from the menu, the page binds the
 * check to the call the agent holds, so the pass is recorded on that call.
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
        $this->call ??= ClientResource::currentCallId();

        // A call id is a UUID; anything else in the address names no call
        // (and would not even compare with the UUID column on MariaDB).
        if ($this->call !== null && ! Str::isUuid($this->call)) {
            $this->call = null;
        }

        $this->form->fill(['code' => '']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                OneTimeCodeInput::make('code')
                    ->label(__('One-time identification code told by the caller'))
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
        abort_if($this->call !== null && ! Str::isUuid($this->call), 403);

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
            $this->form->fill(['code' => '']);
            $this->dispatch('hdid-code-reset');
            Notification::make()->title(__('Unknown or expired code'))->danger()->send();

            return;
        }

        $this->failed = false;
        $this->form->fill(['code' => '']);
        Notification::make()->title(__('Identified: :name', ['name' => $session->client->name]))->success()->send();

        // The pass is recorded; the client's record shows it with every detail.
        $this->redirect(ClientResource::getUrl('view', ['record' => $session->client]));
    }

    public function getCall(): ?Call
    {
        return $this->call === null || ! Str::isUuid($this->call) ? null : app(CallCenterService::class)->attachableCallForAgent($this->call, auth()->user());
    }
}

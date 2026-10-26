<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Clients\ClientLinks;
use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Concerns\GuardsUnsavedChanges;
use App\Filament\Concerns\LinksToDirectory;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Filament\Actions\Action;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

class EditClient extends EditRecord
{
    use GuardsUnsavedChanges;
    use LinksToDirectory {
        beforeSave as protected linksToDirectoryBeforeSave;
    }

    protected static string $resource = ClientResource::class;

    /** Set by the confirmed Save action; a plain form submit (Enter) never sets it. */
    private bool $saveConfirmed = false;

    /** How many linked clients this save reopens by ending the links of a closed sponsor. */
    private int $reopenedBySave = 0;

    /** The linked clients the question said the save reopens, to stop if that changed meanwhile. */
    #[Locked]
    public ?string $confirmedReopen = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ClientResource::deleteAction(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Taking the implicit premium package away from a sponsor ends its
     * links on save, and a new e-mail signs the client out everywhere: the
     * Save button asks first, naming the consequences. Defined as a named
     * action so it can be mounted by name (tests, modal).
     */
    protected function getSaveFormAction(): Action
    {
        return $this->saveAction();
    }

    public function saveAction(): Action
    {
        return $this->confirmingEmailChange(parent::getSaveFormAction()->submit(null))
            // A custom heading or description opens the modal on its own in Filament;
            // modal() keeps it closed unless a link would really end or the e-mail changes.
            ->requiresConfirmation(fn (): bool => $this->endingSponsorLinks() > 0 || $this->pendingEmailChange() !== null)
            ->modal(fn (): bool => $this->endingSponsorLinks() > 0 || $this->pendingEmailChange() !== null)
            ->modalHeading(fn (): string => match (true) {
                $this->endingSponsorLinks() > 0 && $this->pendingEmailChange() !== null => __('Save these changes?'),
                $this->pendingEmailChange() !== null => __('Change the e-mail address?'),
                default => __('End the links of this sponsor?'),
            })
            ->modalDescription(fn (): string|HtmlString|null => $this->saveDescription())
            ->mountUsing(function (): void {
                $this->confirmedEmailChange = $this->emailChangeFingerprint();
                $this->confirmedReopen = $this->reopenFingerprint();
            })
            ->action(function (Action $action): void {
                $this->assertEmailChangeAsConfirmed($action);

                if ($this->endingSponsorLinks() > 0 && $this->reopenFingerprint() !== $this->confirmedReopen) {
                    $this->confirmedReopen = $this->reopenFingerprint();
                    Notification::make()->title(__('The consequences changed: check them again'))
                        ->body(__('Which linked clients the save reopens changed while the question was open. The question now shows what saving does; confirm again to save.'))
                        ->warning()->send();
                    $action->halt();
                }

                $this->saveConfirmed = true;
                $this->save();
            })
            ->after(fn (array $arguments) => $this->followLeaveLink($arguments));
    }

    /**
     * The form can also be submitted with Enter, bypassing the button: a
     * change that ends links is then refused until it goes through the
     * button, and an e-mail change opens the question instead of saving.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        if (! $this->saveConfirmed && $this->endingSponsorLinks() > 0) {
            Notification::make()->title(__('Confirm the change with the Save button'))
                ->body(__('The chosen implicit package ends this sponsor\'s active links; the Save button asks for confirmation first.'))
                ->warning()->send();

            return;
        }

        if (! $this->saveConfirmed && $this->pendingEmailChange() !== null) {
            $this->mountAction('save');

            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    /**
     * The question of the Save button: the links that end, the e-mail
     * change and what it ends, or both.
     */
    private function saveDescription(): string|HtmlString|null
    {
        $sponsor = $this->endingSponsorLinks() > 0
            ? __('The chosen implicit package ":package" is not on the premium package list (:list), so this client can no longer sponsor others: its :n active link(s) end on save (:names). The linked clients are not closed; they keep whatever their own packages entitle them to.', [
                'package' => $this->chosenImplicitPackage() ?? '—',
                'list' => implode(', ', array_map(fn ($name): string => trim((string) $name), app(Settings::class)->array(SettingKey::PackagesPremium))) ?: '—',
                'n' => $this->endingSponsorLinks(),
                'names' => $this->endingLinkNames(),
            ]).$this->reopenSentence()
            : null;
        $email = $this->emailChangeDescription();

        return match (true) {
            $email === null => $sponsor,
            $sponsor === null => $email,
            default => new HtmlString(e($sponsor).'<br><br>'.$email->toHtml()),
        };
    }

    /**
     * The closed sponsor's linked clients closed only with it reopen when
     * its links end (ClientLinks::reopenedOnLinkEnd): the question names them.
     */
    private function reopenSentence(): string
    {
        /** @var Client $client */
        $client = $this->getRecord();
        $reopened = app(ClientLinks::class)->reopenedOnLinkEnd($client);

        if ($reopened->isEmpty()) {
            return '';
        }

        $names = $reopened->map(fn (Client $linked): string => app(ClientTiers::class)->isServed($linked) ? $linked->name : __('client not served at present'))
            ->sort(fn (string $a, string $b): int => strcoll($a, $b))->values();

        return ' '.__('Of them, :n closed only because this sponsor is closed reopen on save (:names): they can sign in to the portal and be identified again if their own packages entitle them.', [
            'n' => $reopened->count(),
            'names' => $names->take(10)->implode(', ').($names->count() > 10 ? ' …' : ''),
        ]);
    }

    private function reopenFingerprint(): ?string
    {
        /** @var Client $client */
        $client = $this->getRecord();

        return $this->endingSponsorLinks() > 0 ? hash('sha256', (string) json_encode(app(ClientLinks::class)->reopenedOnLinkEnd($client)->pluck('id')->sort()->values()->all())) : null;
    }

    /**
     * Active links that end if the form is saved as it stands: the client
     * sponsors others and the chosen implicit package is not premium.
     */
    public function endingSponsorLinks(): int
    {
        if (app(ClientTiers::class)->tierOfPackage($this->chosenImplicitPackage()) === ClientTier::Premium) {
            return 0;
        }

        /** @var Client $client */
        $client = $this->getRecord();

        return $client->activeLinks()->count();
    }

    /**
     * The implicit package as the form stands: a cleared field counts as
     * "none", only a field the form does not carry falls back to the record.
     */
    private function chosenImplicitPackage(): ?string
    {
        /** @var Client $client */
        $client = $this->getRecord();
        $implicit = array_key_exists('implicit_package', $this->data ?? []) ? $this->data['implicit_package'] : $client->implicit_package;

        return is_string($implicit) && trim($implicit) !== '' ? $implicit : null;
    }

    /**
     * The linked clients whose link would end, by name, so the dialog can be
     * checked against the Linked clients tab.
     */
    private function endingLinkNames(): string
    {
        /** @var Client $client */
        $client = $this->getRecord();
        $names = $client->activeLinks()->with('linked')->get()
            ->map(fn ($link): string => match (true) {
                $link->linked === null => __('deleted client'),
                ! app(ClientTiers::class)->isServed($link->linked) => __('client not served at present'),
                default => $link->linked->name,
            })
            ->sort(fn (string $a, string $b): int => strcoll($a, $b))
            ->values();

        return $names->take(10)->implode(', ').($names->count() > 10 ? ' …' : '');
    }

    /**
     * Back to the client page after saving, not stuck on the form.
     */
    protected function getRedirectUrl(): string
    {
        return ClientResource::afterSaveUrl($this->getRecord());
    }

    protected function beforeSave(): void
    {
        $this->linksToDirectoryBeforeSave();

        /** @var Client $client */
        $client = $this->getRecord();
        $this->reopenedBySave = $this->endingSponsorLinks() > 0 ? app(ClientLinks::class)->reopenedOnLinkEnd($client)->count() : 0;
    }

    protected function getSavedNotification(): ?Notification
    {
        $notification = $this->withDirectoryLink(parent::getSavedNotification());

        if ($notification !== null && $this->reopenedBySave > 0) {
            $notification->body(trim(($notification->getBody() ?? '').' '.__(':n linked client(s) closed with this sponsor reopened, because their link ended.', ['n' => $this->reopenedBySave])));
        }

        return $notification;
    }
}

<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Filament\Actions\Action;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    /** Set by the confirmed Save action; a plain form submit (Enter) never sets it. */
    private bool $sponsorChangeConfirmed = false;

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
     * links on save: the Save button asks first, with the number of links.
     * Defined as a named action so it can be mounted by name (tests, modal).
     */
    protected function getSaveFormAction(): Action
    {
        return $this->saveAction();
    }

    public function saveAction(): Action
    {
        return parent::getSaveFormAction()
            ->submit(null)
            // A custom heading or description opens the modal on its own in Filament;
            // modal() keeps it closed unless a link would really end.
            ->requiresConfirmation(fn (): bool => $this->endingSponsorLinks() > 0)
            ->modal(fn (): bool => $this->endingSponsorLinks() > 0)
            ->modalHeading(__('End the links of this sponsor?'))
            ->modalDescription(fn (): string => __('The chosen implicit package ":package" is not on the premium package list (:list), so this client can no longer sponsor others: its :n active link(s) end on save (:names). The linked clients are not closed; they keep whatever their own packages entitle them to.', [
                'package' => $this->chosenImplicitPackage() ?? '—',
                'list' => implode(', ', array_map(fn ($name): string => trim((string) $name), app(Settings::class)->array(SettingKey::PackagesPremium))) ?: '—',
                'n' => $this->endingSponsorLinks(),
                'names' => $this->endingLinkNames(),
            ]))
            ->modalSubmitActionLabel(__('Save'))
            ->action(function (): void {
                $this->sponsorChangeConfirmed = true;
                $this->save();
            });
    }

    /**
     * The form can also be submitted with Enter, bypassing the button: a
     * change that ends links is then refused until it goes through the button.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        if (! $this->sponsorChangeConfirmed && $this->endingSponsorLinks() > 0) {
            Notification::make()->title(__('Confirm the change with the Save button'))
                ->body(__('The chosen implicit package ends this sponsor\'s active links; the Save button asks for confirmation first.'))
                ->warning()->send();

            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
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
            ->map(fn ($link): string => $link->linked?->name ?? __('deleted client'))
            ->sort(fn (string $a, string $b): int => strcoll($a, $b))
            ->values();

        return $names->take(10)->implode(', ').($names->count() > 10 ? ' …' : '');
    }

    /**
     * Back to the client page after saving, not stuck on the form.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}

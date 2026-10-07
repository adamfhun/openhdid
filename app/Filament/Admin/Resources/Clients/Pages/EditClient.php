<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Models\Client;
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
            ->requiresConfirmation(fn (): bool => $this->endingSponsorLinks() > 0)
            ->modalHeading(__('End the links of this sponsor?'))
            ->modalDescription(fn (): string => __('The chosen implicit package is not premium, so this client can no longer sponsor others: its :n active link(s) end on save. The linked clients are not closed; they keep whatever their own packages entitle them to.', ['n' => $this->endingSponsorLinks()]))
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
        /** @var Client $client */
        $client = $this->getRecord();
        $implicit = $this->data['implicit_package'] ?? $client->implicit_package;

        if (app(ClientTiers::class)->tierOfPackage(is_string($implicit) ? $implicit : null) === ClientTier::Premium) {
            return 0;
        }

        return $client->activeLinks()->count();
    }

    /**
     * Back to the client page after saving, not stuck on the form.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}

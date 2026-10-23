<?php

namespace App\Filament\Admin\Resources\Clients\Pages;

use App\Audit\Auditor;
use App\Filament\Actions\WrapUpNoteAction;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Identification\CurrentIdentification;
use App\Models\Call;
use App\Models\IdSession;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Pages\Dashboard;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * The client's record. When the signed-in agent has just identified this
 * client (AZO-07), an "Identified" bar heads the page with the method, the
 * time and the call, and leaving the page asks first while the call is in
 * progress; the header's "Identified client" button leads back from anywhere.
 * The header keeps Identify and Edit only; every other button stands next to
 * the data it acts on (ClientResource::infolist).
 */
class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        app(Auditor::class)->record('client.viewed', $this->getRecord());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.identified-bar')
                ->visible(fn (): bool => $this->getCurrentIdentification() !== null)
                ->viewData(fn (): array => [
                    'identification' => $identification = $this->getCurrentIdentification(),
                    'guarded' => $identification !== null && app(CurrentIdentification::class)->guards($identification, auth()->user()),
                    'noteAction' => $this->getAction('wrapUpNote'),
                    'queueUrl' => Dashboard::getUrl(),
                ]),
            $this->getInfolistContentComponent(),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    /**
     * The agent's current identification when it is this client's: the pass
     * made on the call they hold, or the one made in the last few minutes.
     */
    public function getCurrentIdentification(): ?IdSession
    {
        $session = app(CurrentIdentification::class)->forAgent(auth()->user());

        return $session !== null && $session->client_id === $this->getRecord()->getKey() ? $session : null;
    }

    public function wrapUpNoteAction(): Action
    {
        return WrapUpNoteAction::for(function (): ?Call {
            $call = $this->getCurrentIdentification()?->call;

            return $call !== null && $call->isAttachableBy(auth()->user()) ? $call : null;
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            ClientResource::identifyAction(),
            EditAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Audit\UserRolesAudit;
use App\Auth\Permission;
use App\Auth\RoleAssignment;
use App\Filament\Actions\UnlockLoginAction;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Concerns\GuardsUnsavedChanges;
use App\Filament\Concerns\LinksToDirectory;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditUser extends EditRecord
{
    use GuardsUnsavedChanges;
    use LinksToDirectory {
        beforeSave as protected linksToDirectoryBeforeSave;
        afterSave as protected linksToDirectoryAfterSave;
    }

    protected static string $resource = UserResource::class;

    /** Set by the confirmed Save action; a plain form submit (Enter) never sets it. */
    private bool $saveConfirmed = false;

    /** @var list<string> the account's roles before this save */
    private array $rolesBeforeSave = [];

    /**
     * Runs on the opening, on every later request of the page and on the
     * save: an account the editor may not change (it holds a role they
     * could not grant, RoleAssignment::canManage) is refused and recorded,
     * also when it got that role after the page was opened.
     */
    protected function authorizeAccess(): void
    {
        $actor = auth()->user();
        $record = $this->getRecord();

        if ($actor instanceof User && $record instanceof User && $actor->can(Permission::UsersManage->value)) {
            try {
                app(RoleAssignment::class)->assertManageable($actor, $record, 'edit');
            } catch (AuthorizationException $e) {
                abort(403, $e->getMessage());
            }
        }

        parent::authorizeAccess();
    }

    /**
     * Unlock appears only while the login is locked; the sign-in binding's
     * own button stands in the "Company sign-in binding" block of the form.
     */
    protected function getHeaderActions(): array
    {
        return [
            UnlockLoginAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * A new e-mail signs the account out everywhere (and may hand it to
     * another person's EMD record): the Save button asks first, naming the
     * consequences. Defined as a named action so it can be mounted by name
     * (tests, the unsaved-changes dialog).
     */
    protected function getSaveFormAction(): Action
    {
        return $this->saveAction();
    }

    public function saveAction(): Action
    {
        return $this->confirmingEmailChange(parent::getSaveFormAction()->submit(null))
            ->action(function (Action $action): void {
                $this->assertEmailChangeAsConfirmed($action);
                $this->saveConfirmed = true;
                $this->save();
            })
            ->after(fn (array $arguments) => $this->followLeaveLink($arguments));
    }

    /**
     * The form can also be submitted with Enter, bypassing the button: an
     * e-mail change then opens the question instead of saving.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        if (! $this->saveConfirmed && $this->pendingEmailChange() !== null) {
            $this->mountAction('save');

            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    protected function beforeSave(): void
    {
        $this->linksToDirectoryBeforeSave();
        $this->rolesBeforeSave = UserRolesAudit::rolesOf($this->getRecord());
    }

    /**
     * Who gave whom which role, or took it away, is part of the trail.
     */
    protected function afterSave(): void
    {
        $this->linksToDirectoryAfterSave();
        UserRolesAudit::record($this->getRecord(), $this->rolesBeforeSave);
    }

    /**
     * Users have no view page: back to the list after saving.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotification(): ?Notification
    {
        return $this->withDirectoryLink(parent::getSavedNotification());
    }
}

<?php

namespace App\Filament\Concerns;

use App\Enums\DirectoryLinkOutcome;
use App\Models\Client;
use App\Models\User;
use App\Sync\AccountProvisioner;
use Filament\Notifications\Notification;

/**
 * Create and edit pages of users and clients: an account not linked to an
 * EMD record yet is offered to the stored records on save, and the
 * notification tells the outcome (linked, or why not).
 */
trait LinksToDirectory
{
    private ?DirectoryLinkOutcome $directoryLink = null;

    protected function afterCreate(): void
    {
        $this->linkToDirectory();
    }

    protected function afterSave(): void
    {
        $this->linkToDirectory();
    }

    private function linkToDirectory(): void
    {
        $record = $this->getRecord();

        $this->directoryLink = $record instanceof User || $record instanceof Client
            ? app(AccountProvisioner::class)->linkNow($record)
            : null;
    }

    private function withDirectoryLink(?Notification $notification): ?Notification
    {
        if ($notification === null || $this->directoryLink === null) {
            return $notification;
        }

        /** @var User|Client $record */
        $record = $this->getRecord();
        $notification->body($this->directoryLink->message($record));

        return $this->directoryLink->isLinked() ? $notification : $notification->warning()->persistent();
    }
}

<?php

namespace App\Clients;

use App\Audit\Auditor;
use App\Enums\ClientTier;
use App\Enums\PrincipalType;
use App\Models\Client;
use App\Models\ClientLink;
use App\Models\User;
use App\Sync\AccountProvisioner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Links between a client with an implicit premium package (the sponsor) and
 * clients whose premium access exists only because of that sponsor. The
 * link has one job: when the sponsor is closed, the linked accounts close.
 */
class ClientLinks
{
    public const CLOSE_REASON_SPONSOR = 'sponsor_closed';

    /** Why a link ended on its own: the sponsor no longer has an implicit premium package. */
    public const END_REASON_SPONSOR_INELIGIBLE = 'sponsor_ineligible';

    public function __construct(
        private readonly ClientTiers $tiers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Whether the client may sponsor others: open, with an implicit premium
     * package of its own. A closed sponsor would never propagate its closure
     * to a client linked afterwards, so it cannot take new links.
     */
    public function canSponsor(Client $client): bool
    {
        return ! $client->isClosed() && $this->tiers->hasImplicitPremium($client);
    }

    /**
     * Whether the client depends on someone else's premium: premium through
     * the directory's explicit package alone, with no implicit premium
     * package of its own. The explicit package is set on a dependent at a
     * sponsor's request, which is what the link records; a client without
     * it gains nothing from a link, so it cannot be linked (2026-10-23).
     */
    public function needsSponsor(Client $client): bool
    {
        return ! $this->tiers->hasImplicitPremium($client)
            && $this->tiers->tierFromDirectory($client) === ClientTier::Premium;
    }

    /**
     * @throws ValidationException
     */
    public function link(Client $sponsor, Client $client, ?User $by = null): ClientLink
    {
        if ($sponsor->is($client)) {
            throw ValidationException::withMessages(['client' => __('A client cannot be linked to itself.')]);
        }

        if ($sponsor->isClosed()) {
            throw ValidationException::withMessages(['client' => __('A closed client cannot get new linked clients. Reopen it first.')]);
        }

        if (! $this->canSponsor($sponsor)) {
            throw ValidationException::withMessages(['client' => __('Only a client with an implicit premium package can have linked clients.')]);
        }

        if (! $this->needsSponsor($client)) {
            throw ValidationException::withMessages(['client' => __('Only a client whose premium comes from an explicit package, without an implicit premium package of their own, can be linked to a sponsor.')]);
        }

        if ($client->activeSponsorLink()->exists()) {
            throw ValidationException::withMessages(['client' => __('This client is already linked to another client.')]);
        }

        $link = ClientLink::query()->create([
            'sponsor_client_id' => $sponsor->id,
            'linked_client_id' => $client->id,
            'created_by_user_id' => $by?->id,
        ]);

        $this->auditor->record('client.linked', $client, ['sponsor_id' => $sponsor->id, 'link_id' => $link->id], $by);

        // The warning now has its answer; a later unlink must warn again.
        $this->unmuteWarning($client, 'linked', $by);

        return $link;
    }

    /**
     * Premium only through the directory's explicit package, with no active
     * sponsor link and no muted warning. One rule for the warning triangle,
     * the list tab, the menu badge, the filter and the export.
     */
    public function isMissingSponsor(Client $client): bool
    {
        // Only the directory's explicit package asks for a link; a manual override has its own reason.
        return $this->needsSponsor($client)
            && ! $client->hasMutedLinkWarning()
            && $client->sponsor() === null;
    }

    /**
     * Query form of isMissingSponsor().
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function scopeMissingSponsor(Builder $query): Builder
    {
        $premium = $this->tiers->rawNames($this->tiers->packages(ClientTier::Premium));

        return $query
            ->whereIn('explicit_package', $premium)
            ->where(fn (Builder $q) => $q->whereNull('implicit_package')->orWhereNotIn('implicit_package', $premium))
            ->whereDoesntHave('sponsorLinks', fn (Builder $q) => $q->whereNull('ended_at'))
            ->linkWarningMuted(false);
    }

    public const MIN_MUTE_REASON_LENGTH = 10;

    /**
     * Silence the "explicit premium without a link" warning for one client
     * (a documented exception), with a reason and an optional end date. Own
     * columns: the directory sync never touches them.
     *
     * @throws ValidationException
     */
    public function muteWarning(Client $client, string $reason, ?Carbon $until = null, ?User $by = null): void
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_MUTE_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => __('Give a reason of at least :n characters.', ['n' => self::MIN_MUTE_REASON_LENGTH])]);
        }

        if ($until !== null && $until->isPast()) {
            throw ValidationException::withMessages(['until' => __('The end date must be in the future.')]);
        }

        $client->forceFill([
            'link_warning_muted_at' => now(),
            'link_warning_muted_until' => $until,
            'link_warning_muted_reason' => $reason,
            'link_warning_muted_by_user_id' => $by?->id,
        ])->save();

        $this->auditor->record('client.link_warning_muted', $client, ['reason' => $reason, 'until' => $until?->toIso8601String()], $by);
    }

    /**
     * The warning applies again. No-op when nothing was muted.
     */
    public function unmuteWarning(Client $client, string $how = 'manual', ?User $by = null): void
    {
        if ($client->link_warning_muted_at === null) {
            return;
        }

        $client->forceFill([
            'link_warning_muted_at' => null,
            'link_warning_muted_until' => null,
            'link_warning_muted_reason' => null,
            'link_warning_muted_by_user_id' => null,
        ])->save();

        $this->auditor->record('client.link_warning_unmuted', $client, ['how' => $how], $by);
    }

    public function unlink(ClientLink $link, ?User $by = null, ?string $reason = null): void
    {
        if (! $link->isActive()) {
            return;
        }

        $link->forceFill(['ended_at' => now(), 'ended_by_user_id' => $by?->id, 'ended_reason' => $reason])->save();
        $this->auditor->record('client.unlinked', $link->linked, array_filter(['sponsor_id' => $link->sponsor_client_id, 'link_id' => $link->id, 'reason' => $reason]), $by);
    }

    /**
     * A link is only a way for a client to get a higher service level
     * through a sponsor, and sponsoring rests on the sponsor's implicit
     * premium package alone. When that package stops being premium (the
     * directory sync or an admin changed it), every active link ends with
     * END_REASON_SPONSOR_INELIGIBLE. The linked clients keep whatever their
     * own packages entitle them to: a dependent closed only because the
     * sponsor was closed reopens, unless the directory dropped or
     * reclassified it meanwhile (keepClosedReason(), like in
     * reopenLinkedClients()). Returns the number of links ended.
     */
    public function endLinksOfIneligibleSponsor(Client $sponsor): int
    {
        return $this->endSponsorLinks($sponsor)['links'];
    }

    /**
     * Sweep for the case the package change did not go through a client
     * save: the premium package list itself changed in the settings.
     * Returns the number of links ended.
     */
    public function endLinksOfIneligibleSponsors(): int
    {
        return $this->sweepIneligibleSponsors()['links'];
    }

    /**
     * endLinksOfIneligibleSponsors() with what it did, for the report after
     * a package list save: the links ended, the linked clients reopened
     * because they had been closed only with their sponsor, and those kept
     * closed for the directory's reason. Each reopening and keeping closed
     * is audited (account.reopened / account.kept_closed with the sponsor),
     * the hourly sweep's too.
     *
     * @return array{links: int, reopened: int, kept_closed: int}
     */
    public function sweepIneligibleSponsors(): array
    {
        $premium = $this->tiers->packages(ClientTier::Premium);
        $total = ['links' => 0, 'reopened' => 0, 'kept_closed' => 0];

        Client::query()
            ->whereHas('sponsoredLinks', fn (Builder $q) => $q->whereNull('ended_at'))
            ->where(fn (Builder $q) => $q->whereNull('implicit_package')->orWhereNotIn('implicit_package', $this->tiers->rawNames($premium)))
            ->chunkById(100, function ($sponsors) use (&$total): void {
                foreach ($sponsors as $sponsor) {
                    foreach ($this->endSponsorLinks($sponsor) as $what => $count) {
                        $total[$what] += $count;
                    }
                }
            });

        return $total;
    }

    /**
     * @return array{links: int, reopened: int, kept_closed: int}
     */
    private function endSponsorLinks(Client $sponsor): array
    {
        $done = ['links' => 0, 'reopened' => 0, 'kept_closed' => 0];

        if ($this->tiers->hasImplicitPremium($sponsor)) {
            return $done;
        }

        $sponsor->activeLinks()->with('linked.externalRecord')->get()->each(function (ClientLink $link) use ($sponsor, &$done): void {
            $this->unlink($link, null, self::END_REASON_SPONSOR_INELIGIBLE);
            $done['links']++;

            $linked = $link->linked;
            if ($linked === null || ! $linked->isClosed() || $linked->closed_reason !== self::CLOSE_REASON_SPONSOR) {
                return;
            }

            if ($this->keepClosed($linked, $sponsor->id)) {
                $done['kept_closed']++;

                return;
            }

            $linked->reopen();
            $this->auditor->record('account.reopened', $linked, ['reason' => self::END_REASON_SPONSOR_INELIGIBLE, 'sponsor_id' => $sponsor->id]);
            $done['reopened']++;
        });

        return $done;
    }

    /**
     * The linked clients that ending this sponsor's links would reopen:
     * closed only because the sponsor was closed, and not kept closed for
     * the directory's reason (keepClosedReason()). What the save of a
     * sponsor's package change and a package list save name beforehand.
     *
     * @return Collection<int, Client>
     */
    public function reopenedOnLinkEnd(Client $sponsor): Collection
    {
        return $sponsor->activeLinks()->with('linked.externalRecord')->get()
            ->map(fn (ClientLink $link): ?Client => $link->linked)
            ->filter(fn (?Client $linked): bool => $linked !== null && $linked->isClosed() && $linked->closed_reason === self::CLOSE_REASON_SPONSOR && $this->keepClosedReason($linked) === null)
            ->values();
    }

    /**
     * Why a client closed only because of its sponsor stays closed although
     * the sponsor no longer holds it (reopened, or its link ended): the
     * directory dropped its record meanwhile, or reclassified it as staff.
     * Null when it may reopen. The account then stays closed for the
     * directory's reason, and the sync reopens it once the record is back as
     * a client (AccountProvisioner::reopenIfClosedBySync); otherwise the
     * account would reopen for a record the directory no longer has as a
     * client, since the sync does not close an account that is closed already.
     */
    public function keepClosedReason(Client $linked): ?string
    {
        $record = $linked->externalRecord;

        return match (true) {
            $record === null => null,
            $record->isMissing() => AccountProvisioner::CLOSE_REASON_MISSING,
            $record->kind !== PrincipalType::Client => AccountProvisioner::CLOSE_REASON_KIND_CHANGED,
            default => null,
        };
    }

    /**
     * The active link that holds this client closed: its sponsor is closed
     * (a removed sponsor too, since removing closes it first). While it
     * lasts, the client stays closed whatever path would reopen it
     * (NYT-06.1); reopening the sponsor or ending the link frees it.
     */
    public function closedSponsorLink(Client $client): ?ClientLink
    {
        return ClientLink::query()->active()
            ->where('linked_client_id', $client->getKey())
            ->whereHas('sponsor', fn (Builder $query) => $query->withTrashed()->whereNotNull('closed_at'))
            ->first();
    }

    /**
     * Switches a client closed with its sponsor to the directory's reason
     * when keepClosedReason() has one, audited as account.kept_closed.
     */
    private function keepClosed(Client $linked, string $sponsorId): bool
    {
        $reason = $this->keepClosedReason($linked);

        if ($reason === null) {
            return false;
        }

        $linked->forceFill(['closed_reason' => $reason])->save();
        $this->auditor->record('account.kept_closed', $linked, ['reason' => $reason, 'sponsor_id' => $sponsorId]);

        return true;
    }

    /**
     * Sponsor closed: close every actively linked client that is still open.
     */
    public function closeLinkedClients(Client $sponsor): int
    {
        $count = 0;

        $sponsor->activeLinks()->with('linked')->get()->each(function (ClientLink $link) use (&$count): void {
            if ($link->linked !== null && ! $link->linked->isClosed()) {
                $link->linked->close(self::CLOSE_REASON_SPONSOR);
                $this->auditor->record('account.closed', $link->linked, ['reason' => self::CLOSE_REASON_SPONSOR, 'sponsor_id' => $link->sponsor_client_id]);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Sponsor reopened: reopen the linked clients that were closed only
     * because of the sponsor. One the directory dropped or reclassified in
     * the meantime stays closed, now for the directory's reason
     * (keepClosedReason()), until the record returns as a client and the
     * sync reopens it.
     */
    public function reopenLinkedClients(Client $sponsor): int
    {
        // Reopened without an implicit premium package (the directory changed
        // it while the sponsor was away): it does not sponsor anyone any more.
        if (! $this->tiers->hasImplicitPremium($sponsor)) {
            $this->endLinksOfIneligibleSponsor($sponsor);

            return 0;
        }

        $count = 0;

        $sponsor->activeLinks()->with('linked.externalRecord')->get()->each(function (ClientLink $link) use (&$count): void {
            if ($link->linked !== null && $link->linked->isClosed() && $link->linked->closed_reason === self::CLOSE_REASON_SPONSOR) {
                if ($this->keepClosed($link->linked, $link->sponsor_client_id)) {
                    return;
                }

                $link->linked->reopen();
                $this->auditor->record('account.reopened', $link->linked, ['sponsor_id' => $link->sponsor_client_id]);
                $count++;
            }
        });

        return $count;
    }
}

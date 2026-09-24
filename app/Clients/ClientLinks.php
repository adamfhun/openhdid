<?php

namespace App\Clients;

use App\Audit\Auditor;
use App\Enums\ClientTier;
use App\Models\Client;
use App\Models\ClientLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Links between a client with an implicit premium package (the sponsor) and
 * clients whose premium access exists only because of that sponsor. The
 * link has one job: when the sponsor is closed, the linked accounts close.
 */
class ClientLinks
{
    public const CLOSE_REASON_SPONSOR = 'sponsor_closed';

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
     * Whether the client depends on someone else's premium: it has no
     * implicit premium package of its own.
     */
    public function needsSponsor(Client $client): bool
    {
        return ! $this->tiers->hasImplicitPremium($client);
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
            throw ValidationException::withMessages(['client' => __('This client has an implicit premium package of its own and does not need a link.')]);
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
            && $this->tiers->tierFromDirectory($client) === ClientTier::Premium
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
        $premium = $this->tiers->packages(ClientTier::Premium);

        return $query
            ->whereIn(DB::raw('lower(trim(explicit_package))'), $premium)
            ->where(fn (Builder $q) => $q->whereNull('implicit_package')->orWhereNotIn(DB::raw('lower(trim(implicit_package))'), $premium))
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

    public function unlink(ClientLink $link, ?User $by = null): void
    {
        if (! $link->isActive()) {
            return;
        }

        $link->forceFill(['ended_at' => now(), 'ended_by_user_id' => $by?->id])->save();
        $this->auditor->record('client.unlinked', $link->linked, ['sponsor_id' => $link->sponsor_client_id, 'link_id' => $link->id], $by);
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
     * because of the sponsor.
     */
    public function reopenLinkedClients(Client $sponsor): int
    {
        $count = 0;

        $sponsor->activeLinks()->with('linked')->get()->each(function (ClientLink $link) use (&$count): void {
            if ($link->linked !== null && $link->linked->isClosed() && $link->linked->closed_reason === self::CLOSE_REASON_SPONSOR) {
                $link->linked->reopen();
                $this->auditor->record('account.reopened', $link->linked, ['sponsor_id' => $link->sponsor_client_id]);
                $count++;
            }
        });

        return $count;
    }
}

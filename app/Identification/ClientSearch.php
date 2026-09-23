<?php

namespace App\Identification;

use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Models\Client;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fast agent search across name, e-mail, phone numbers and the external id.
 */
class ClientSearch
{
    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly ClientTiers $tiers,
    ) {}

    /**
     * @param  list<ClientTier>|null  $tiers  restrict to these service levels
     * @return Collection<int, Client>
     */
    public function search(string $term, int $limit = 20, ?array $tiers = null): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $e164 = $this->phones->normalize($term);
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        return Client::query()
            ->with(['phoneNumbers', 'externalRecord'])
            ->where(function ($q) use ($like, $e164, $digits, $term): void {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('externalRecord', fn ($r) => $r->where('company', 'like', $like)->when(ctype_digit($term), fn ($r) => $r->orWhere('external_id', 'like', $term.'%')));

                if ($e164 !== null) {
                    $q->orWhereHas('phoneNumbers', fn ($p) => $p->where('number_e164', $e164));
                } elseif (mb_strlen($digits) >= 4) {
                    $q->orWhereHas('phoneNumbers', fn ($p) => $p->where('number_e164', 'like', '%'.$digits.'%'));
                }
            })
            ->when($tiers !== null, fn ($q) => $this->tiers->scopeClients($q, $tiers))
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
}

<?php

namespace App\System;

use App\Auth\Contracts\Principal;
use App\Enums\PrincipalType;
use App\Models\Call;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\User;

/**
 * Recognises the demo data (the DemoSeeder, OPENHDID_DEMO_DATA=true) in a
 * database. Every demo account and its EMD record carries an address under
 * the reserved .test top-level domain (RFC 6761), which no real mailbox
 * has, and the demo calls carry the seeders' call id prefixes. The demo
 * passwords, PIN and answers are published in the source, so in production
 * (APP_ENV=production) the container start stops on them, the status page
 * turns red, a demo account cannot sign in, the EMD sync does not hand a
 * demo record to the real person who has its id, and hdid:make-admin
 * warns. Other environments (demo, local, staging) are left as they were.
 */
final class DemoData
{
    /** The reserved top-level domain of every demo address. */
    public const RESERVED_DOMAIN = '.test';

    /** Call id prefixes of the demo calls (DemoSeeder, DemoCallHistorySeeder). */
    public const CALL_ID_PREFIXES = ['demo-call-', 'demo-hist-'];

    /**
     * Whether demo data is refused here: in production only.
     */
    public static function refusedHere(): bool
    {
        return app()->isProduction();
    }

    public static function isDemoAddress(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));

        return str_contains($email, '@') && str_ends_with($email, self::RESERVED_DOMAIN);
    }

    public static function isDemoAccount(?Principal $principal): bool
    {
        return $principal !== null && self::isDemoAddress($principal->getEmail());
    }

    /**
     * A record the demo data created: its own address, or that of its
     * account of the record's kind, is a demo address. Only the relation
     * the sync loads for the record anyway is asked for.
     */
    public static function isDemoRecord(ExternalRecord $record): bool
    {
        if (self::isDemoAddress($record->email)) {
            return true;
        }

        $account = $record->kind === PrincipalType::Client ? $record->client : $record->user;

        return self::isDemoAccount($account);
    }

    /**
     * What of the demo data the database holds, by marker; deleted rows
     * count too, a soft delete leaves the published secrets in place.
     *
     * @return array{users: int, clients: int, records: int, calls: int}
     */
    public function found(): array
    {
        $address = '%'.self::RESERVED_DOMAIN;

        return [
            'users' => User::withTrashed()->where('email', 'like', $address)->count(),
            'clients' => Client::withTrashed()->where('email', 'like', $address)->count(),
            'records' => ExternalRecord::withTrashed()->where('email', 'like', $address)->count(),
            'calls' => Call::withTrashed()->where(function ($query): void {
                foreach (self::CALL_ID_PREFIXES as $prefix) {
                    $query->orWhere('external_call_id', 'like', $prefix.'%');
                }
            })->count(),
        ];
    }

    public function present(): bool
    {
        return array_sum($this->found()) > 0;
    }

    /**
     * The first few addresses and demo call ids found, so a message can
     * name what it saw: a database that is really the demo shows the
     * documented demo accounts, a real one that only has a stray .test
     * address shows just that address.
     *
     * @return list<string>
     */
    public function samples(int $limit = 5): array
    {
        $address = '%'.self::RESERVED_DOMAIN;
        $samples = [];

        foreach ([User::class, Client::class, ExternalRecord::class] as $model) {
            $samples = [...$samples, ...$model::withTrashed()->where('email', 'like', $address)->orderBy('email')->limit($limit)->pluck('email')->all()];
        }

        $samples = array_values(array_unique(array_map('strval', $samples)));

        if (count($samples) < $limit) {
            $calls = Call::withTrashed()->where(function ($query): void {
                foreach (self::CALL_ID_PREFIXES as $prefix) {
                    $query->orWhere('external_call_id', 'like', $prefix.'%');
                }
            })->orderBy('external_call_id')->limit($limit - count($samples))->pluck('external_call_id')->all();
            $samples = [...$samples, ...array_map('strval', $calls)];
        }

        return array_slice($samples, 0, $limit);
    }

    /**
     * Whether a production-only rule should refuse this address: a new
     * .test address would mark a real database as demo data.
     */
    public static function refusesNewAddress(?string $email): bool
    {
        return self::refusedHere() && self::isDemoAddress($email);
    }
}

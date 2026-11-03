<?php

namespace App\System;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Tells this installation apart from another one that reads and writes the
 * same Valkey/Redis under the same key prefix (a test and a production
 * installation of one image, both with the default APP_NAME). The id is a
 * short keyed digest of APP_URL under APP_KEY: the application servers of
 * one installation share both, two installations do not. It reveals
 * nothing of the key.
 *
 * The settings cache carries the id in its keys, so the two never mix, and
 * so do the locks and the scheduler's mutexes (lockName()), every rate
 * limit counter (InstallationRateLimiter) and the cached values that act as
 * security or transparency state (key()): the sign-in ceilings' notices,
 * the second-factor codes, the unlock mark of the question-and-answer
 * limit, a shown login link, the ID list status. The sessions carry it in
 * their stamp, the second-factor mark in its value and the remember-me
 * cookies in their password part (AuthSessions), so a session, a passed
 * second factor or a remember-me cookie of one installation is worth
 * nothing at the other, even on a shared session store or a cloned
 * database. Shared on purpose stay the queued jobs, the heartbeats and
 * short-lived display counts. The scheduler heartbeat carries the id in its
 * value and every worker heartbeat job carries the id of the installation
 * that sent it: when a heartbeat of another installation turns up, the
 * sighting is kept in the shared store, where both installations see it,
 * and their status pages turn red. The scheduler beats in the session store
 * too (SessionStore), which tells a shared session store apart.
 */
final class Installation
{
    /** Where sightings of another installation are kept, in the shared store. */
    public const CONFLICT_KEY = 'hdid.installation.conflict';

    /** How long a sighting is remembered. */
    public const CONFLICT_MINUTES = 15;

    /**
     * Sightings from one source (the scheduler, one queue) before the status
     * page calls it a shared store: right after an APP_KEY or APP_URL change
     * one heartbeat and one waiting heartbeat job still carry the old id.
     */
    public const CONFLICT_SIGHTINGS = 2;

    /** A heartbeat of another installation older than this says nothing (minutes). */
    public const FRESH_MINUTES = 3;

    /**
     * Twelve hex digits, shown on the status page to tell the two apart. The
     * URL is compared without a trailing slash, capitals or a written-out
     * default port (:443, :80), so the servers of one installation agree
     * even when their .env files differ in those.
     */
    public static function id(): string
    {
        return substr(hash_hmac('sha256', 'hdid.installation|'.self::normalizedUrl((string) config('app.url')), (string) config('app.key')), 0, 12);
    }

    /**
     * A lock or mutex name of this installation: the global locks (the EMD
     * sync, its dispatch and ID list, the PIN assignment, the settings save,
     * the call events), the per-account and per-number locks (the sign-in
     * lockout, the SMS budget, the SMS code check, the second factor, the
     * question-and-answer start, the PIN check) and the scheduler's and
     * isolated commands' mutexes carry the id, so two installations sharing
     * a Valkey/Redis under one key prefix, or a database cloned with the
     * same account ids, each run their own work instead of waiting for, or
     * skipping because of, the other's.
     */
    public static function lockName(string $name): string
    {
        return self::key($name);
    }

    /**
     * A cache key of this installation, for a value that acts as security or
     * transparency state: two installations on one store never read each
     * other's. Every new lock, rate limit or security cache key goes through
     * here (or lockName(), or the rate limiter, which adds it by itself).
     */
    public static function key(string $name): string
    {
        return self::prefix().$name;
    }

    /** What key() puts in front of a name: "hdid-<id>:". */
    public static function prefix(): string
    {
        return 'hdid-'.self::id().':';
    }

    private static function normalizedUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $port = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? null : $port;

        return $scheme.'://'.strtolower($parts['host']).($port === null ? '' : ':'.$port).strtolower(rtrim($parts['path'] ?? '', '/'));
    }

    /**
     * Write a heartbeat key with the time and this installation. A fresh
     * heartbeat of another installation found under the key is a sighting,
     * kept in the same store under $conflictKey. The store is the default
     * cache unless given (the session store, for its own heartbeat).
     */
    public static function beat(string $key, string $source, ?Repository $store = null, string $conflictKey = self::CONFLICT_KEY): void
    {
        $store ??= Cache::store();
        $previous = self::readBeat($store->get($key));

        if ($previous !== null && $previous['installation'] !== null && $previous['installation'] !== self::id() && $previous['at']->gte(now()->subMinutes(self::FRESH_MINUTES))) {
            self::sighted($previous['installation'], $source, $store, $conflictKey);
        }

        $store->put($key, ['at' => now()->toIso8601String(), 'installation' => self::id()], now()->addHours(6));
    }

    /**
     * A heartbeat value: the tagged form written by beat(), or the bare
     * timestamp of older releases and of the message job (installation
     * unknown, taken as this one).
     *
     * @return array{at: Carbon, installation: ?string}|null
     */
    public static function readBeat(mixed $value): ?array
    {
        if (is_string($value)) {
            return ['at' => Carbon::parse($value), 'installation' => null];
        }

        if (is_array($value) && is_string($value['at'] ?? null)) {
            return ['at' => Carbon::parse($value['at']), 'installation' => is_string($value['installation'] ?? null) ? $value['installation'] : null];
        }

        return null;
    }

    /**
     * Record that a heartbeat or a heartbeat job of another installation
     * turned up here ($source: "scheduler", "queue:<name>" or
     * "session_store"), in the default cache unless another store is given.
     */
    public static function sighted(string $other, string $source, ?Repository $store = null, string $conflictKey = self::CONFLICT_KEY): void
    {
        $store ??= Cache::store();
        $pair = [self::id(), $other];
        sort($pair);

        $current = $store->get($conflictKey);
        $sightings = is_array($current) && ($current['installations'] ?? null) === $pair ? (array) ($current['sightings'] ?? []) : [];
        $sightings[$source] = (int) ($sightings[$source] ?? 0) + 1;

        $store->put($conflictKey, [
            'installations' => $pair,
            'sightings' => $sightings,
            'source' => $source,
            'at' => now()->toIso8601String(),
        ], now()->addMinutes(self::CONFLICT_MINUTES));
    }

    /**
     * The other installation seen in the shared store lately, or null; in
     * the default cache unless another store is given.
     *
     * @return array{installation: string, source: string, at: Carbon}|null
     */
    public static function conflict(?Repository $store = null, string $conflictKey = self::CONFLICT_KEY): ?array
    {
        $marker = ($store ?? Cache::store())->get($conflictKey);

        $sightings = is_array($marker) ? array_map('intval', array_values((array) ($marker['sightings'] ?? []))) : [];

        if (max([0, ...$sightings]) < self::CONFLICT_SIGHTINGS) {
            return null;
        }

        $others = array_values(array_diff(array_map('strval', (array) ($marker['installations'] ?? [])), [self::id()]));

        if ($others === [] || ! is_string($marker['at'] ?? null)) {
            return null;
        }

        return ['installation' => $others[0], 'source' => (string) ($marker['source'] ?? ''), 'at' => Carbon::parse($marker['at'])];
    }
}

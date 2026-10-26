<?php

namespace App\System;

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;

/**
 * The overlap guard of a scheduled task (withoutOverlapping()) under a name
 * that carries this installation's id (Installation::lockName()): two
 * installations sharing a Valkey/Redis under the same key prefix each run
 * their own tasks, instead of one skipping the task the other is running.
 */
class InstallationEventMutex extends CacheEventMutex
{
    public function create(Event $event)
    {
        $name = self::nameOf($event);

        if ($this->shouldUseLocks($this->cache->store($this->store)->getStore())) {
            return $this->cache->store($this->store)->getStore()->lock($name, $event->expiresAt * 60)->acquire();
        }

        return $this->cache->store($this->store)->add($name, true, $event->expiresAt * 60);
    }

    public function exists(Event $event)
    {
        $name = self::nameOf($event);

        if ($this->shouldUseLocks($this->cache->store($this->store)->getStore())) {
            $lock = $this->cache->store($this->store)->getStore()->lock($name, $event->expiresAt * 60);

            return method_exists($lock, 'isLocked') ? $lock->isLocked() : ! $lock->get(fn () => true);
        }

        return $this->cache->store($this->store)->has($name);
    }

    public function forget(Event $event)
    {
        $name = self::nameOf($event);

        if ($this->shouldUseLocks($this->cache->store($this->store)->getStore())) {
            $this->cache->store($this->store)->getStore()->lock($name, $event->expiresAt * 60)->forceRelease();

            return;
        }

        $this->cache->store($this->store)->forget($name);
    }

    public static function nameOf(Event $event): string
    {
        return Installation::lockName($event->mutexName());
    }
}

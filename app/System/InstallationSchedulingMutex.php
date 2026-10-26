<?php

namespace App\System;

use DateTimeInterface;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\Event;

/**
 * The one-server guard of a scheduled task (onOneServer()) under a name that
 * carries this installation's id (Installation::lockName()): the servers of
 * one installation still run the task once, but another installation
 * sharing the Valkey/Redis runs its own as well.
 */
class InstallationSchedulingMutex extends CacheSchedulingMutex
{
    public function create(Event $event, DateTimeInterface $time)
    {
        $name = self::nameOf($event, $time);

        if ($this->shouldUseLocks($this->cache->store($this->store)->getStore())) {
            return $this->cache->store($this->store)->getStore()->lock($name, 3600)->acquire();
        }

        return $this->cache->store($this->store)->add($name, true, 3600);
    }

    public function exists(Event $event, DateTimeInterface $time)
    {
        $name = self::nameOf($event, $time);

        if ($this->shouldUseLocks($this->cache->store($this->store)->getStore())) {
            return ! $this->cache->store($this->store)->getStore()->lock($name, 3600)->get(fn () => true);
        }

        return $this->cache->store($this->store)->has($name);
    }

    public static function nameOf(Event $event, DateTimeInterface $time): string
    {
        return Installation::lockName($event->mutexName().$time->format('Hi'));
    }
}

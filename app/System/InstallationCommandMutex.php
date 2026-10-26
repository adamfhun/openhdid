<?php

namespace App\System;

use Illuminate\Console\CacheCommandMutex;

/**
 * The guard of an isolated command (migrate --isolated in the container)
 * under a name that carries this installation's id: the migration of one
 * installation no longer makes another one sharing the Valkey/Redis skip
 * its own migration as "already running".
 */
class InstallationCommandMutex extends CacheCommandMutex
{
    protected function commandMutexName($command)
    {
        return Installation::lockName(parent::commandMutexName($command));
    }
}

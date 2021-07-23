<?php

namespace Sellmate\Laravel\MultiTenant\DatabaseDrivers;

use Illuminate\Database\ConnectionInterface;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;

class SQLiteDriver implements DatabaseDriver
{
    public function Create(ConnectionInterface $connection, array $config)
    {
        return touch($config['database']);
    }

    public function Delete(ConnectionInterface $connection, array $config)
    {
        return unlink($config['database']);
    }
}

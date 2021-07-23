<?php

namespace Sellmate\Laravel\MultiTenant\DatabaseDrivers;

use Illuminate\Database\ConnectionInterface;

interface DatabaseDriver
{
    public function Create(ConnectionInterface $connection, array $config);
    public function Delete(ConnectionInterface $connection, array $config);
}

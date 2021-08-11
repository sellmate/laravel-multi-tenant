<?php

namespace Sellmate\Laravel\MultiTenant\DatabaseDrivers;

class SQLiteDriver extends DatabaseDriver
{
    public function setup(array $config)
    {
        $this->createDatabase($config['database']);
    }

    public function destroy(array $config)
    {
        $this->dropDatabase($config['database']);
    }
}

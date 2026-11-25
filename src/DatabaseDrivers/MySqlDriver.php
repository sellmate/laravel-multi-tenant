<?php

namespace Sellmate\Laravel\MultiTenant\DatabaseDrivers;

use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;

class MySqlDriver extends DatabaseDriver
{
    public function setup(array $config)
    {
        $database = $this->identifier($config['database']);
        $username = $this->identifier($config['username']);
        $password = $this->connection->escape($config['password']);

        return $this->connection->transaction(function () use ($database, $username, $password) {
            $this->createDatabase($database);

            if (!$this->connection->statement(<<<SQL
                CREATE USER IF NOT EXISTS `{$username}`@'%' IDENTIFIED BY {$password}
            SQL)) throw new TenantDatabaseException("Could not create user '{$username}'");

            if (!$this->connection->statement(<<<SQL
                GRANT ALL ON `{$database}`.* TO `{$username}`@'%'
            SQL)) throw new TenantDatabaseException("Could not grant privileges to user '{$username}' for '{$database}'");

            return true;
        });
    }

    public function destroy(array $config)
    {
        $database = $this->identifier($config['database']);
        $username = $this->identifier($config['username']);

        return $this->connection->transaction(function () use ($database, $username) {
            if (!$this->connection->statement(<<<SQL
                REVOKE ALL ON `{$database}`.* FROM `{$username}`@'%'
            SQL)) throw new TenantDatabaseException("Could not revoke privileges from user '{$username}' for '{$database}'");

            if (!$this->connection->statement(<<<SQL
                DROP USER IF EXISTS `{$username}`@'%'
            SQL)) throw new TenantDatabaseException("Could not drop user '{$username}'");

            $this->dropDatabase($database);

            return true;
        });
    }
}

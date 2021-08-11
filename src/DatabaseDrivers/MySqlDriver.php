<?php

namespace Sellmate\Laravel\MultiTenant\DatabaseDrivers;

use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;

class MySqlDriver extends DatabaseDriver
{
    public function Create(array $config)
    {
        return $this->connection->transaction(function () use ($config) {
            $this->createDatabase($config['database']);

            if (!$this->connection->statement(<<<SQL
                CREATE USER IF NOT EXISTS `{$config['username']}`@'%' IDENTIFIED BY '{$config['password']}'
            SQL)) throw new TenantDatabaseException("Could not create user '{$config['username']}'");

            if (!$this->connection->statement(<<<SQL
                GRANT ALL ON `{$config['database']}`.* TO `{$config['username']}`@'%'
            SQL)) throw new TenantDatabaseException("Could not grant privileges to user '{$config['username']}' for '{$config['database']}'");

            return true;
        });
    }

    public function Delete(array $config)
    {
        return $this->connection->transaction(function () use ($config) {
            if (!$this->connection->statement(<<<SQL
                REVOKE ALL ON `{$config['database']}`.* FROM `{$config['username']}`@'%'
            SQL)) throw new TenantDatabaseException("Could not revoke privileges from user '{$config['username']}' for '{$config['database']}'");

            if (!$this->connection->statement(<<<SQL
                DROP USER IF EXISTS `{$config['username']}`@'%'
            SQL)) throw new TenantDatabaseException("Could not drop user '{$config['username']}'");

            $this->dropDatabase($config['database']);

            return true;
        });
    }
}

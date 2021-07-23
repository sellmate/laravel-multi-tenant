<?php

namespace Sellmate\Laravel\MultiTenant\DatabaseDrivers;

use Illuminate\Database\ConnectionInterface;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;

class MySqlDriver implements DatabaseDriver
{
    public function Create(ConnectionInterface $connection, array $config)
    {
        return $connection->transaction(function () use ($connection, $config) {
            if (!$connection->statement(<<<SQL
                CREATE USER IF NOT EXISTS `{$config['username']}`@'%' IDENTIFIED BY '{$config['password']}'
            SQL)) throw new TenantDatabaseException("Could not create user '{$config['username']}'");

            if (!$connection->statement(<<<SQL
                CREATE DATABASE IF NOT EXISTS `{$config['database']}`
            SQL)) throw new TenantDatabaseException("Could not create database '{$config['database']}'");

            if (!$connection->statement(<<<SQL
                GRANT ALL ON `{$config['database']}`.* TO `{$config['username']}`@'%'
            SQL)) throw new TenantDatabaseException("Could not grant privileges to user '{$config['username']}' for '{$config['database']}'");

            return true;
        });
    }

    public function Delete(ConnectionInterface $connection, array $config)
    {
        return $connection->transaction(function () use ($connection, $config) {
            if (!$connection->statement(<<<SQL
                REVOKE ALL ON `{$config['database']}`.* FROM `{$config['username']}`@'%'
            SQL)) throw new TenantDatabaseException("Could not revoke privileges from user '{$config['username']}' for '{$config['database']}'");

            if (!$connection->statement(<<<SQL
                DROP DATABASE IF EXISTS `{$config['database']}`
            SQL)) throw new TenantDatabaseException("Could not drop database '{$config['database']}'");

            if (!$connection->statement(<<<SQL
                DROP USER IF EXISTS `{$config['username']}`@'%'
            SQL)) throw new TenantDatabaseException("Could not drop user '{$config['username']}'");

            return true;
        });
    }
}

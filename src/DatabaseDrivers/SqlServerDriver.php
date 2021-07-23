<?php

namespace Sellmate\Laravel\MultiTenant\DatabaseDrivers;

use Illuminate\Database\ConnectionInterface;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;

class SqlServerDriver implements DatabaseDriver
{
    public function Create(ConnectionInterface $connection, array $config)
    {
        if (!$connection->statement(<<<SQL
            IF NOT EXISTS(SELECT * FROM sys.databases WHERE name = '{$config['database']}')
            BEGIN
                CREATE DATABASE [{$config['database']}]
            END
        SQL)) throw new TenantDatabaseException("Could not create database '{$config['database']}'");

        return $connection->transaction(function () use ($connection, $config) {
            if (!$connection->statement(<<<SQL
                CREATE LOGIN [{$config['username']}] WITH
                    PASSWORD=N'{$config['password']}', 
                    DEFAULT_DATABASE=[{$config['database']}], 
                    DEFAULT_LANGUAGE=[한국어], 
                    CHECK_EXPIRATION=OFF, 
                    CHECK_POLICY=ON
            SQL)) throw new TenantDatabaseException("Could not create login '{$config['username']}'");

            if (!$connection->statement(<<<SQL
                USE [{$config['database']}];
                CREATE USER [{$config['username']}] 
                    FOR LOGIN [{$config['username']}];
            SQL)) throw new TenantDatabaseException("Could not create user '{$config['username']}'");

            if (!$connection->statement(<<<SQL
                USE [{$config['database']}];
                ALTER ROLE [db_owner] 
                    ADD MEMBER [{$config['username']}];
            SQL)) throw new TenantDatabaseException("Could not grant privileges to user '{$config['username']}' for '{$config['database']}'");

            return true;
        });
    }

    public function Delete(ConnectionInterface $connection, array $config)
    {
        $rows = $connection->select("SELECT spid FROM sysprocesses WHERE loginame='{$config['username']}'");
        foreach ($rows as $row) {
            $connection->statement("KILL {$row->spid}");
        }

        $connection->transaction(function () use ($connection, $config) {
            if (!$connection->statement(<<<SQL
                USE [{$config['database']}]
                DROP USER IF EXISTS [{$config['username']}]
            SQL)) throw new TenantDatabaseException("Could not drop user '{$config['username']}'");

            if (!$connection->statement(<<<SQL
                DROP LOGIN [{$config['username']}]
            SQL)) throw new TenantDatabaseException("Could not drop login '{$config['username']}'");
        });

        if (!$connection->statement(<<<SQL
            USE [master];
            ALTER DATABASE [{$config['database']}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE ;
            DROP DATABASE IF EXISTS [{$config['database']}];
        SQL)) throw new TenantDatabaseException("Could not drop database '{$config['database']}'");
    }
}

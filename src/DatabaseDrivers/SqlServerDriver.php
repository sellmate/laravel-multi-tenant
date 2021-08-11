<?php

namespace Sellmate\Laravel\MultiTenant\DatabaseDrivers;

use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;

class SqlServerDriver extends DatabaseDriver
{
    public function setup(array $config)
    {
        $this->createDatabase($config['database']);

        return $this->connection->transaction(function () use ($config) {
            if (!$this->connection->statement(<<<SQL
                CREATE LOGIN [{$config['username']}] WITH
                    PASSWORD=N'{$config['password']}', 
                    DEFAULT_DATABASE=[{$config['database']}], 
                    DEFAULT_LANGUAGE=[한국어], 
                    CHECK_EXPIRATION=OFF, 
                    CHECK_POLICY=ON
            SQL)) throw new TenantDatabaseException("Could not create login '{$config['username']}'");

            if (!$this->connection->statement(<<<SQL
                USE [{$config['database']}];
                CREATE USER [{$config['username']}] 
                    FOR LOGIN [{$config['username']}];
            SQL)) throw new TenantDatabaseException("Could not create user '{$config['username']}'");

            if (!$this->connection->statement(<<<SQL
                USE [{$config['database']}];
                ALTER ROLE [db_owner] 
                    ADD MEMBER [{$config['username']}];
            SQL)) throw new TenantDatabaseException("Could not grant privileges to user '{$config['username']}' for '{$config['database']}'");

            return true;
        });
    }

    public function destroy(array $config)
    {
        $rows = $this->connection->select("SELECT spid FROM sysprocesses WHERE loginame='{$config['username']}'");
        foreach ($rows as $row) {
            $this->connection->statement("KILL {$row->spid}");
        }

        $this->connection->transaction(function () use ($config) {
            if (!$this->connection->statement(<<<SQL
                USE [{$config['database']}]
                DROP USER IF EXISTS [{$config['username']}]
            SQL)) throw new TenantDatabaseException("Could not drop user '{$config['username']}'");

            if (!$this->connection->statement(<<<SQL
                DROP LOGIN [{$config['username']}]
            SQL)) throw new TenantDatabaseException("Could not drop login '{$config['username']}'");
        });

        // SQL Server 의 경우 database 삭제는 Transaction 내에 포함될 수 없음
        $this->connection->statement(<<<SQL
            USE [master];
            ALTER DATABASE [{$config['database']}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE ;
        SQL);
        $this->dropDatabase($config['database']);
    }
}

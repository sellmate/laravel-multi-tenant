<?php

namespace Sellmate\Laravel\MultiTenant\DatabaseDrivers;

use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;

class SqlServerDriver extends DatabaseDriver
{
    // Dots are safe inside [bracketed] identifiers.
    protected string $identifierPattern = '/^[A-Za-z0-9_.-]+\z/';

    public function setup(array $config)
    {
        $database = $this->identifier($config['database']);
        $username = $this->identifier($config['username']);
        $password = $this->connection->escape($config['password']);

        $this->createDatabase($database);

        return $this->connection->transaction(function () use ($database, $username, $password) {
            if (!$this->connection->statement(<<<SQL
                CREATE LOGIN [{$username}] WITH
                    PASSWORD=N{$password},
                    DEFAULT_DATABASE=[{$database}],
                    DEFAULT_LANGUAGE=[한국어],
                    CHECK_EXPIRATION=OFF,
                    CHECK_POLICY=ON
            SQL)) throw new TenantDatabaseException("Could not create login '{$username}'");

            if (!$this->connection->statement(<<<SQL
                USE [{$database}];
                CREATE USER [{$username}]
                    FOR LOGIN [{$username}];
            SQL)) throw new TenantDatabaseException("Could not create user '{$username}'");

            if (!$this->connection->statement(<<<SQL
                USE [{$database}];
                ALTER ROLE [db_owner]
                    ADD MEMBER [{$username}];
            SQL)) throw new TenantDatabaseException("Could not grant privileges to user '{$username}' for '{$database}'");

            return true;
        });
    }

    public function destroy(array $config)
    {
        $database = $this->identifier($config['database']);
        $username = $this->identifier($config['username']);

        $rows = $this->connection->select('SELECT spid FROM sysprocesses WHERE loginame = ?', [$username]);
        foreach ($rows as $row) {
            $this->connection->statement('KILL ' . (int) $row->spid);
        }

        $this->connection->transaction(function () use ($database, $username) {
            if (!$this->connection->statement(<<<SQL
                USE [{$database}]
                DROP USER IF EXISTS [{$username}]
            SQL)) throw new TenantDatabaseException("Could not drop user '{$username}'");

            if (!$this->connection->statement(<<<SQL
                DROP LOGIN [{$username}]
            SQL)) throw new TenantDatabaseException("Could not drop login '{$username}'");
        });

        // SQL Server 의 경우 database 삭제는 Transaction 내에 포함될 수 없음
        $this->connection->statement(<<<SQL
            USE [master];
            ALTER DATABASE [{$database}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE ;
        SQL);
        $this->dropDatabase($database);
    }
}

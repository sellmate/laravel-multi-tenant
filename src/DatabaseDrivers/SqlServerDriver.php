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

    /**
     * Idempotent: safe to run again after a partial failure (an already-dropped user, login or database is not an error).
     *
     * @throws TenantDatabaseException when a step fails
     */
    public function destroy(array $config)
    {
        $database = $this->identifier($config['database']);
        $username = $this->identifier($config['username']);

        $rows = $this->connection->select('SELECT spid FROM sysprocesses WHERE loginame = ?', [$username]);
        foreach ($rows as $row) {
            $this->run('KILL ' . (int) $row->spid, "Could not end sessions of login '{$username}'");
        }

        // USE on a missing database fails at compile time (Msg 911) even inside a false IF branch, so it
        // runs through EXEC, which only compiles the string when the branch is taken.
        $this->run(<<<SQL
            IF DB_ID(N'{$database}') IS NOT NULL EXEC(N'USE [{$database}]; DROP USER IF EXISTS [{$username}];')
        SQL, "Could not drop user '{$username}'");

        $this->run(<<<SQL
            IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'{$username}') DROP LOGIN [{$username}]
        SQL, "Could not drop login '{$username}'");

        $this->run(<<<SQL
            USE [master]; IF DB_ID(N'{$database}') IS NOT NULL EXEC(N'ALTER DATABASE [{$database}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
        SQL, "Could not close connections to database '{$database}'");

        $this->dropDatabase($database);

        return true;
    }

    private function run(string $sql, string $failure): void
    {
        try {
            $succeeded = $this->connection->statement($sql);
        } catch (\Throwable $th) {
            throw new TenantDatabaseException($failure, 0, $th);
        }
        if (!$succeeded) {
            throw new TenantDatabaseException($failure);
        }
    }
}

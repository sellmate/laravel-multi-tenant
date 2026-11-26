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

        try {
            return $this->createLoginAndUser($database, $username, $password);
        } catch (\Throwable $th) {
            // A failed step can leave the shared connection in the tenant database (see below). Moving it back
            // is best-effort: the step's own failure is what the caller must see.
            try {
                $this->connection->getPdo()->exec('USE [master]');
            } catch (\Throwable) {
            }
            throw $th;
        }
    }

    private function createLoginAndUser(string $database, string $username, string $password): bool
    {
        return $this->connection->transaction(function () use ($database, $username, $password) {
            $this->run(<<<SQL
                CREATE LOGIN [{$username}] WITH
                    PASSWORD=N{$password},
                    DEFAULT_DATABASE=[{$database}],
                    DEFAULT_LANGUAGE=[한국어],
                    CHECK_EXPIRATION=OFF,
                    CHECK_POLICY=ON
            SQL, "Could not create login '{$username}'");

            $this->run(<<<SQL
                USE [{$database}];
                CREATE USER [{$username}]
                    FOR LOGIN [{$username}];
            SQL, "Could not create user '{$username}'");

            // Ends in master: a connection left in the tenant database is cut off by any later
            // ALTER DATABASE ... WITH ROLLBACK IMMEDIATE on it (e.g. destroy()).
            $this->run(<<<SQL
                USE [{$database}];
                ALTER ROLE [db_owner]
                    ADD MEMBER [{$username}];
                USE [master];
            SQL, "Could not grant privileges to user '{$username}' for '{$database}'");

            return true;
        });
    }

    /**
     * Idempotent: safe to run again after a partial failure (an already-dropped login or database is not an error).
     * Ends the tenant login's sessions and any other session in the tenant database. The login is disabled
     * first and stays disabled if a later step fails. If another session keeps taking the only slot of a
     * SINGLE_USER tenant database, the drop is retried a few times and then fails; running it again succeeds.
     *
     * @throws TenantDatabaseException when a step fails
     */
    public function destroy(array $config)
    {
        $database = $this->identifier($config['database']);
        $username = $this->identifier($config['username']);

        // Every later step is guarded by IF EXISTS / DB_ID checks, and SQL Server hides other logins, other
        // sessions and databases from a login without these permissions, so without this check destroy()
        // would skip every step and report success. HAS_PERMS_BY_NAME returns NULL for a securable it cannot
        // resolve, which counts as missing. USE [master] because setup() leaves the shared connection in the
        // tenant database, and every later step runs in this context.
        $this->run(<<<SQL
            USE [master];
            IF ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'ALTER ANY LOGIN'), 0) = 0
                OR ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'ALTER ANY CONNECTION'), 0) = 0
                OR ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'VIEW SERVER STATE'), 0) = 0
                OR ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'VIEW ANY DATABASE'), 0) = 0
                OR (DB_ID(N'{$database}') IS NOT NULL
                    AND ISNULL(HAS_PERMS_BY_NAME(N'{$database}', 'DATABASE', 'CONTROL'), 0) = 0
                    AND ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'ALTER ANY DATABASE'), 0) = 0)
                THROW 50000, N'Missing permission: ALTER ANY LOGIN, ALTER ANY CONNECTION, VIEW SERVER STATE, VIEW ANY DATABASE, and CONTROL on the tenant database or ALTER ANY DATABASE are required.', 1;
        SQL, "Could not destroy tenant '{$database}': the tenant admin login lacks permissions");

        // Disabled first so the tenant app cannot reconnect between ending its sessions and DROP LOGIN.
        $this->run(<<<SQL
            IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'{$username}') ALTER LOGIN [{$username}] DISABLE
        SQL, "Could not disable login '{$username}'");

        $killTenantSessions = $this->killSessions('tenant_sessions', "login_name = N'{$username}'");
        $this->run(<<<SQL
            DECLARE @spid int, @kill nvarchar(30);
            {$killTenantSessions}
        SQL, "Could not end sessions of login '{$username}'");

        // The database user is not dropped separately: it is removed with the database, and dropping it
        // would need USE [db], which another session holding a SINGLE_USER database can block.
        // ROLLBACK IMMEDIATE does not evict the holder of a SINGLE_USER database (5064), so every session in
        // the database is killed first. Another session can still take the slot before the ALTER; that is
        // retried a bounded number of times. TRY/CATCH sees 5064 only as the generic 5069 that follows it, so
        // the retry also requires the database to be SINGLE_USER.
        $killDatabaseSessions = $this->killSessions('database_sessions', "database_id = DB_ID(N'{$database}')");
        $this->run(<<<SQL
            USE [master];
            IF DB_ID(N'{$database}') IS NOT NULL
            BEGIN
                DECLARE @spid int, @kill nvarchar(30), @attempt int = 0;
                WHILE 1 = 1
                BEGIN
                    {$killDatabaseSessions}
                    BEGIN TRY
                        EXEC(N'ALTER DATABASE [{$database}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
                        BREAK;
                    END TRY
                    BEGIN CATCH
                        IF ERROR_NUMBER() <> 5069
                            OR ISNULL(CAST(DATABASEPROPERTYEX(N'{$database}', 'UserAccess') AS nvarchar(20)), N'') <> N'SINGLE_USER'
                            THROW;
                        SET @attempt += 1;
                        -- The caught 5069 does not name the conflict, so say what kept failing.
                        IF @attempt >= 3
                            THROW 50000, N'ALTER DATABASE kept failing (5069) on the SINGLE_USER tenant database; another session probably holds its only slot (5064). Run destroy again.', 1;
                        -- Gives a client that reconnects immediately a chance to give up the slot first.
                        WAITFOR DELAY '00:00:00.200';
                    END CATCH;
                END;
                EXEC(N'DROP DATABASE [{$database}]');
            END
        SQL, "Could not drop database '{$database}'");

        $this->run(<<<SQL
            IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'{$username}') DROP LOGIN [{$username}]
        SQL, "Could not drop login '{$username}'");

        return true;
    }

    /**
     * T-SQL that kills every other user session matching $condition; the batch must declare
     * `@spid int` and `@kill nvarchar(30)` (once, as this may run inside a loop). System sessions also
     * appear in sys.dm_exec_sessions (e.g. background tasks in the database) and cannot be killed (6107).
     * A session can end on its own between being listed and being killed; KILL then fails with 6106,
     * which is ignored.
     */
    private function killSessions(string $cursor, string $condition): string
    {
        return <<<SQL
            DECLARE {$cursor} CURSOR LOCAL FAST_FORWARD FOR
                SELECT session_id FROM sys.dm_exec_sessions WHERE {$condition} AND is_user_process = 1 AND session_id <> @@SPID;
            OPEN {$cursor};
            FETCH NEXT FROM {$cursor} INTO @spid;
            WHILE @@FETCH_STATUS = 0
            BEGIN
                SET @kill = N'KILL ' + CAST(@spid AS nvarchar(11));
                BEGIN TRY EXEC(@kill); END TRY
                BEGIN CATCH IF ERROR_NUMBER() <> 6106 THROW; END CATCH;
                FETCH NEXT FROM {$cursor} INTO @spid;
            END;
            CLOSE {$cursor};
            DEALLOCATE {$cursor};
        SQL;
    }

    /**
     * Runs a batch on the raw PDO connection and reads every result set: pdo_sqlsrv reports an error
     * raised after the first statement of a batch only when its result set is reached, which
     * Connection::statement() never does.
     */
    private function run(string $sql, string $failure): void
    {
        $statement = null;
        try {
            $statement = $this->connection->getPdo()->prepare($sql);
            $succeeded = $statement->execute();
            while ($succeeded && $statement->nextRowset()) {
            }
            $statement->closeCursor();
        } catch (\Throwable $th) {
            // Leaves no pending result set on the shared connection. The batch has already failed, so an
            // error while closing must not replace $th as the reported cause.
            try {
                $statement?->closeCursor();
            } catch (\Throwable) {
            }
            throw new TenantDatabaseException($failure, 0, $th);
        }
        if (!$succeeded) {
            throw new TenantDatabaseException($failure);
        }
    }
}

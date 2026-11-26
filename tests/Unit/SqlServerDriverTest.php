<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Sellmate\Laravel\MultiTenant\DatabaseDrivers\SqlServerDriver;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class SqlServerDriverTest extends TestCase
{
    private const CONFIG = ['database' => 'laravel_alpha', 'username' => 'laravel_alpha', 'password' => 'secret'];

    private array $statements = [];

    private Builder $schema;

    private Connection $connection;

    /** What PDOStatement::execute() does for every batch. */
    private bool|\Throwable $executeOutcome = true;

    /** Thrown when the driver reads the next result set, as pdo_sqlsrv does for a later statement's error. */
    private ?\Throwable $laterResultSetError = null;

    /** Index of the only batch whose execute() returns false; null keeps $executeOutcome for all. */
    private ?int $failingBatch = null;

    /** Thrown by PDOStatement::closeCursor(). */
    private ?\Throwable $closeCursorError = null;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('prepare')->andReturnUsing(function (string $sql) {
            $this->statements[] = preg_replace('/\s+/', ' ', trim($sql));
            $index = count($this->statements) - 1;

            $statement = Mockery::mock(PDOStatement::class);
            $statement->shouldReceive('closeCursor')->andReturnUsing(function () {
                if ($this->closeCursorError !== null) {
                    throw $this->closeCursorError;
                }

                return true;
            });
            $statement->shouldReceive('execute')->andReturnUsing(function () use ($index) {
                if ($this->failingBatch !== null) {
                    return $index !== $this->failingBatch;
                }
                if ($this->executeOutcome instanceof \Throwable) {
                    throw $this->executeOutcome;
                }

                return $this->executeOutcome;
            });
            $statement->shouldReceive('nextRowset')->andReturnUsing(function () {
                if ($this->laterResultSetError !== null) {
                    throw $this->laterResultSetError;
                }

                return false;
            });

            return $statement;
        });

        $this->schema = Mockery::mock(Builder::class);
        $this->connection = Mockery::mock(Connection::class);
        $this->connection->shouldReceive('getSchemaBuilder')->andReturn($this->schema);
        $this->connection->shouldReceive('getPdo')->andReturn($pdo);
        $this->connection->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->connection->shouldReceive('escape')->andReturnUsing(fn ($value) => "'" . str_replace("'", "''", $value) . "'");

        DB::shouldReceive('connection')->with('tenant_admin')->andReturn($this->connection);
    }

    public static function unsafeIdentifiers(): array
    {
        return [
            'bracket in database' => [['database' => 'a]; DROP DATABASE [system'] + self::CONFIG],
            'quote in username' => [['username' => "a'; DROP LOGIN sa; --"] + self::CONFIG],
            'trailing newline' => [['username' => "laravel_alpha\n"] + self::CONFIG],
        ];
    }

    #[DataProvider('unsafeIdentifiers')]
    public function test_setup_and_destroy_reject_unsafe_identifiers_before_running_any_sql(array $config): void
    {
        $this->schema->shouldNotReceive('createDatabase');
        $this->schema->shouldNotReceive('dropDatabaseIfExists');
        $driver = new SqlServerDriver('tenant_admin');

        foreach (['setup', 'destroy'] as $method) {
            try {
                $driver->{$method}($config);
                $this->fail("Expected {$method} to reject the unsafe identifier");
            } catch (TenantDatabaseException) {
            }
        }

        $this->assertSame([], $this->statements);
    }

    public function test_setup_accepts_dotted_identifiers(): void
    {
        $this->schema->shouldReceive('createDatabase')->once()->with('laravel_shop.example');

        (new SqlServerDriver('tenant_admin'))->setup(['database' => 'laravel_shop.example', 'username' => 'laravel_shop.example'] + self::CONFIG);

        $this->assertStringContainsString('CREATE LOGIN [laravel_shop.example]', $this->statements[0]);
    }

    private static function killSessions(string $cursor, string $condition): string
    {
        return "DECLARE @spid int, @kill nvarchar(30); DECLARE {$cursor} CURSOR LOCAL FAST_FORWARD FOR SELECT session_id FROM sys.dm_exec_sessions WHERE {$condition} AND is_user_process = 1 AND session_id <> @@SPID; OPEN {$cursor}; FETCH NEXT FROM {$cursor} INTO @spid; WHILE @@FETCH_STATUS = 0 BEGIN SET @kill = N'KILL ' + CAST(@spid AS nvarchar(11)); BEGIN TRY EXEC(@kill); END TRY BEGIN CATCH IF ERROR_NUMBER() <> 6106 THROW; END CATCH; FETCH NEXT FROM {$cursor} INTO @spid; END; CLOSE {$cursor}; DEALLOCATE {$cursor};";
    }

    /**
     * Whitespace-normalized. Must match the step files in tests/sqlserver/destroy-rerun/ (names aside),
     * which run these statements against a real SQL Server; this test can only compare strings.
     */
    private static function destroyStatements(): array
    {
        return [
            'permissions' => "USE [master]; IF ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'ALTER ANY LOGIN'), 0) = 0 OR ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'ALTER ANY CONNECTION'), 0) = 0 OR ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'VIEW SERVER STATE'), 0) = 0 OR ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'VIEW ANY DATABASE'), 0) = 0 OR (DB_ID(N'laravel_alpha') IS NOT NULL AND ISNULL(HAS_PERMS_BY_NAME(N'laravel_alpha', 'DATABASE', 'CONTROL'), 0) = 0 AND ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'ALTER ANY DATABASE'), 0) = 0) THROW 50000, N'Missing permission: ALTER ANY LOGIN, ALTER ANY CONNECTION, VIEW SERVER STATE, VIEW ANY DATABASE, and CONTROL on the tenant database or ALTER ANY DATABASE are required.', 1;",
            'disable' => "IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'laravel_alpha') ALTER LOGIN [laravel_alpha] DISABLE",
            'kill' => self::killSessions('tenant_sessions', "login_name = N'laravel_alpha'"),
            'drop database' => "USE [master]; IF DB_ID(N'laravel_alpha') IS NOT NULL BEGIN "
                . self::killSessions('database_sessions', "database_id = DB_ID(N'laravel_alpha')")
                . " EXEC(N'ALTER DATABASE [laravel_alpha] SET SINGLE_USER WITH ROLLBACK IMMEDIATE'); EXEC(N'DROP DATABASE [laravel_alpha]'); END",
            'drop login' => "IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'laravel_alpha') DROP LOGIN [laravel_alpha]",
        ];
    }

    public function test_destroy_guards_every_step_so_it_can_be_rerun(): void
    {
        $this->schema->shouldNotReceive('dropDatabaseIfExists');

        $this->assertTrue((new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG));

        $this->assertSame(array_values(self::destroyStatements()), $this->statements);
    }

    public function test_destroy_checks_permissions_before_anything_else(): void
    {
        $this->executeOutcome = new PDOException('Missing permission');

        try {
            (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);
            $this->fail('Expected destroy to fail');
        } catch (TenantDatabaseException $e) {
            $this->assertStringContainsString('lacks permissions', $e->getMessage());
        }

        $this->assertCount(1, $this->statements);
        $this->assertStringStartsWith("USE [master]; IF ISNULL(HAS_PERMS_BY_NAME(NULL, NULL, 'ALTER ANY LOGIN'), 0) = 0", $this->statements[0]);
    }

    public function test_destroy_only_kills_other_user_sessions_and_ignores_only_vanished_ones(): void
    {
        (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);

        foreach ([$this->statements[2], $this->statements[3]] as $statement) {
            $this->assertStringContainsString('is_user_process = 1', $statement);
            $this->assertStringContainsString('session_id <> @@SPID', $statement);
            $this->assertStringContainsString('IF ERROR_NUMBER() <> 6106 THROW;', $statement);
        }
    }

    public function test_destroy_never_switches_into_the_tenant_database(): void
    {
        (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);

        foreach ($this->statements as $statement) {
            $this->assertStringNotContainsString('USE [laravel_alpha]', $statement);
        }
    }

    public function test_destroy_wraps_a_failing_step_and_keeps_the_cause(): void
    {
        $this->executeOutcome = new \RuntimeException('permission denied');
        $this->schema->shouldNotReceive('dropDatabaseIfExists');

        try {
            (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);
            $this->fail('Expected destroy to fail');
        } catch (TenantDatabaseException $e) {
            $this->assertSame('permission denied', $e->getPrevious()?->getMessage());
        }
    }

    public static function destroySteps(): array
    {
        return [
            'permissions' => [0, "Could not destroy tenant 'laravel_alpha': the tenant admin login lacks permissions"],
            'disable' => [1, "Could not disable login 'laravel_alpha'"],
            'kill' => [2, "Could not end sessions of login 'laravel_alpha'"],
            'drop database' => [3, "Could not drop database 'laravel_alpha'"],
            'drop login' => [4, "Could not drop login 'laravel_alpha'"],
        ];
    }

    #[DataProvider('destroySteps')]
    public function test_destroy_stops_at_a_step_that_reports_failure(int $step, string $message): void
    {
        $this->failingBatch = $step;

        try {
            (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);
            $this->fail('Expected destroy to fail');
        } catch (TenantDatabaseException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $this->assertCount($step + 1, $this->statements, 'no step may run after the failing one');
    }

    public function test_an_error_reported_on_a_later_result_set_fails_the_step(): void
    {
        $this->laterResultSetError = new PDOException('ALTER DATABASE permission denied');

        try {
            (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);
            $this->fail('Expected destroy to fail');
        } catch (TenantDatabaseException $e) {
            $this->assertSame('ALTER DATABASE permission denied', $e->getPrevious()?->getMessage());
        }
    }

    public function test_an_error_while_closing_does_not_replace_the_cause_of_a_failed_step(): void
    {
        $this->executeOutcome = new PDOException('Missing permission');
        $this->closeCursorError = new PDOException('cursor in error state');

        try {
            (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);
            $this->fail('Expected destroy to fail');
        } catch (TenantDatabaseException $e) {
            $this->assertSame('Missing permission', $e->getPrevious()?->getMessage());
        }
    }

    public function test_an_error_while_closing_after_a_successful_batch_fails_the_step(): void
    {
        $this->closeCursorError = new PDOException('connection lost while closing');

        try {
            (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);
            $this->fail('Expected destroy to fail');
        } catch (TenantDatabaseException $e) {
            $this->assertSame('connection lost while closing', $e->getPrevious()?->getMessage());
        }
    }

    public function test_setup_runs_every_step_through_the_result_set_check(): void
    {
        $this->schema->shouldReceive('createDatabase')->once();
        $this->laterResultSetError = new PDOException('CREATE USER failed');

        try {
            (new SqlServerDriver('tenant_admin'))->setup(self::CONFIG);
            $this->fail('Expected setup to fail');
        } catch (TenantDatabaseException $e) {
            $this->assertSame('CREATE USER failed', $e->getPrevious()?->getMessage());
        }
    }

    public function test_setup_quotes_the_password(): void
    {
        $this->schema->shouldReceive('createDatabase')->once()->with('laravel_alpha');

        (new SqlServerDriver('tenant_admin'))->setup(['password' => "p'w"] + self::CONFIG);

        $this->assertStringContainsString("PASSWORD=N'p''w'", $this->statements[0]);
    }
}

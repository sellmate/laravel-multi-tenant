<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = Mockery::mock(Builder::class);
        $this->connection = Mockery::mock(Connection::class);
        $this->connection->shouldReceive('getSchemaBuilder')->andReturn($this->schema);
        $this->connection->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $this->connection->shouldReceive('escape')->andReturnUsing(fn ($value) => "'" . str_replace("'", "''", $value) . "'");
        $this->connection->shouldReceive('statement')->andReturnUsing(function ($sql) {
            $this->statements[] = preg_replace('/\s+/', ' ', trim($sql));

            return true;
        })->byDefault();
        $this->connection->shouldReceive('select')->andReturn([])->byDefault();

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
        $this->connection->shouldNotReceive('select');
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

    public function test_destroy_only_kills_other_user_sessions_and_ignores_only_vanished_ones(): void
    {
        (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);

        foreach ([$this->statements[1], $this->statements[2]] as $statement) {
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
        $this->connection->shouldReceive('statement')->andThrow(new \RuntimeException('permission denied'));
        $this->schema->shouldNotReceive('dropDatabaseIfExists');

        try {
            (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);
            $this->fail('Expected destroy to fail');
        } catch (TenantDatabaseException $e) {
            $this->assertSame('permission denied', $e->getPrevious()?->getMessage());
        }
    }

    public function test_destroy_fails_when_a_step_reports_failure(): void
    {
        $this->connection->shouldReceive('statement')->andReturn(false);
        $this->schema->shouldNotReceive('dropDatabaseIfExists');

        try {
            (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);
            $this->fail('Expected destroy to fail');
        } catch (TenantDatabaseException $e) {
            $this->assertSame("Could not disable login 'laravel_alpha'", $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_setup_quotes_the_password(): void
    {
        $this->schema->shouldReceive('createDatabase')->once()->with('laravel_alpha');

        (new SqlServerDriver('tenant_admin'))->setup(['password' => "p'w"] + self::CONFIG);

        $this->assertStringContainsString("PASSWORD=N'p''w'", $this->statements[0]);
    }
}

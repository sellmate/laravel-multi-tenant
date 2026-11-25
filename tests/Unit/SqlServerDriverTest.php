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

    /**
     * Must match tests/sqlserver/destroy-rerun/destroy.sql and the first two statements of its verify.sql
     * S3 (names aside), which run them against a real SQL Server; this test can only compare strings.
     */
    public const DESTROY_STATEMENTS = [
        "IF DB_ID(N'laravel_alpha') IS NOT NULL EXEC(N'USE [laravel_alpha]; DROP USER IF EXISTS [laravel_alpha];')",
        "IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'laravel_alpha') DROP LOGIN [laravel_alpha]",
        "USE [master]; IF DB_ID(N'laravel_alpha') IS NOT NULL EXEC(N'ALTER DATABASE [laravel_alpha] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');",
    ];

    public function test_destroy_guards_every_step_so_it_can_be_rerun(): void
    {
        $this->schema->shouldReceive('dropDatabaseIfExists')->once()->with('laravel_alpha');

        $this->assertTrue((new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG));

        $this->assertSame(self::DESTROY_STATEMENTS, $this->statements);
    }

    public function test_destroy_ends_sessions_of_the_tenant_login_first(): void
    {
        $this->connection->shouldReceive('select')->with('SELECT spid FROM sysprocesses WHERE loginame = ?', ['laravel_alpha'])
            ->andReturn([(object) ['spid' => '53']]);
        $this->schema->shouldReceive('dropDatabaseIfExists');

        (new SqlServerDriver('tenant_admin'))->destroy(self::CONFIG);

        $this->assertSame('KILL 53', $this->statements[0]);
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
            $this->assertSame("Could not drop user 'laravel_alpha'", $e->getMessage());
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

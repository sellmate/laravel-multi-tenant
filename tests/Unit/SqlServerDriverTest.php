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
        });

        DB::shouldReceive('connection')->with('tenant_admin')->andReturn($this->connection);
    }

    public static function unsafeIdentifiers(): array
    {
        return [
            'bracket in database' => [['database' => 'a]; DROP DATABASE [system'] + self::CONFIG],
            'quote in username' => [['username' => "a'; DROP LOGIN sa; --"] + self::CONFIG],
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

    public function test_setup_quotes_the_password(): void
    {
        $this->schema->shouldReceive('createDatabase')->once()->with('laravel_alpha');

        (new SqlServerDriver('tenant_admin'))->setup(['password' => "p'w"] + self::CONFIG);

        $this->assertStringContainsString("PASSWORD=N'p''w'", $this->statements[0]);
    }
}

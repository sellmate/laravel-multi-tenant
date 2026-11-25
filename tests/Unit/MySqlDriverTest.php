<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Sellmate\Laravel\MultiTenant\DatabaseDrivers\MySqlDriver;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class MySqlDriverTest extends TestCase
{
    private const CONFIG = ['database' => 'laravel_alpha', 'username' => 'laravel_alpha', 'password' => 'secret'];

    private array $statements = [];

    private Builder $schema;

    private function driver(bool $statementResult = true): MySqlDriver
    {
        $this->schema = Mockery::mock(Builder::class);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($this->schema);
        $connection->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());
        $connection->shouldReceive('statement')->andReturnUsing(function ($sql) use ($statementResult) {
            $this->statements[] = preg_replace('/\s+/', ' ', trim($sql));

            return $statementResult;
        });
        DB::shouldReceive('connection')->with('tenant_admin')->andReturn($connection);

        return new MySqlDriver('tenant_admin');
    }

    public function test_setup_creates_database_user_and_grant(): void
    {
        $driver = $this->driver();
        $this->schema->shouldReceive('createDatabase')->once()->with('laravel_alpha');

        $this->assertTrue($driver->setup(self::CONFIG));

        $this->assertSame([
            "CREATE USER IF NOT EXISTS `laravel_alpha`@'%' IDENTIFIED BY 'secret'",
            "GRANT ALL ON `laravel_alpha`.* TO `laravel_alpha`@'%'",
        ], $this->statements);
    }

    public function test_destroy_revokes_drops_user_and_database(): void
    {
        $driver = $this->driver();
        $this->schema->shouldReceive('dropDatabaseIfExists')->once()->with('laravel_alpha');

        $this->assertTrue($driver->destroy(self::CONFIG));

        $this->assertSame([
            "REVOKE ALL ON `laravel_alpha`.* FROM `laravel_alpha`@'%'",
            "DROP USER IF EXISTS `laravel_alpha`@'%'",
        ], $this->statements);
    }

    public function test_setup_fails_when_a_statement_fails(): void
    {
        $driver = $this->driver(statementResult: false);
        $this->schema->shouldReceive('createDatabase');

        $this->expectException(TenantDatabaseException::class);
        $driver->setup(self::CONFIG);
    }
}

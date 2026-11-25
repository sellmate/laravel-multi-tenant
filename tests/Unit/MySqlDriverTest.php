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
        $connection->shouldReceive('escape')->andReturnUsing(fn ($value) => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'");
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

    public function test_destroy_drops_user_and_database(): void
    {
        $driver = $this->driver();
        $this->schema->shouldReceive('dropDatabaseIfExists')->once()->with('laravel_alpha');

        $this->assertTrue($driver->destroy(self::CONFIG));

        $this->assertSame([
            "DROP USER IF EXISTS `laravel_alpha`@'%'",
        ], $this->statements);
    }

    public function test_destroy_can_be_rerun_after_failing_partway(): void
    {
        $userExists = true;
        $steps = [];
        $this->schema = Mockery::mock(Builder::class);
        $this->schema->shouldReceive('dropDatabaseIfExists')->with('laravel_alpha')->andReturnUsing(function () use (&$steps) {
            $steps[] = 'drop database';
            if (count(array_keys($steps, 'drop database')) === 1) {
                throw new \RuntimeException('metadata lock wait timeout');
            }

            return true;
        });
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($this->schema);
        // Mirrors MySQL: REVOKE fails once the account is gone (1141), DROP USER IF EXISTS never does.
        $connection->shouldReceive('statement')->andReturnUsing(function ($sql) use (&$userExists, &$steps) {
            if (str_contains($sql, 'REVOKE') && ! $userExists) {
                throw new \RuntimeException('SQLSTATE[42000]: 1141 There is no such grant defined');
            }
            if (str_contains($sql, 'DROP USER')) {
                $steps[] = 'drop user';
                $userExists = false;
            }

            return true;
        });
        DB::shouldReceive('connection')->with('tenant_admin')->andReturn($connection);
        $driver = new MySqlDriver('tenant_admin');

        try {
            $driver->destroy(self::CONFIG);
            $this->fail('Expected the first destroy to fail on the database drop');
        } catch (TenantDatabaseException $e) {
            $this->assertSame('metadata lock wait timeout', $e->getPrevious()?->getMessage());
        }

        $this->assertTrue($driver->destroy(self::CONFIG));
        $this->assertSame(['drop user', 'drop database', 'drop user', 'drop database'], $steps);
    }

    public function test_destroy_wraps_a_failing_drop_user(): void
    {
        $this->schema = Mockery::mock(Builder::class);
        $this->schema->shouldNotReceive('dropDatabaseIfExists');
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($this->schema);
        $connection->shouldReceive('statement')->andThrow(new \RuntimeException('access denied for CREATE USER'));
        DB::shouldReceive('connection')->with('tenant_admin')->andReturn($connection);

        try {
            (new MySqlDriver('tenant_admin'))->destroy(self::CONFIG);
            $this->fail('Expected destroy to fail');
        } catch (TenantDatabaseException $e) {
            $this->assertSame('access denied for CREATE USER', $e->getPrevious()?->getMessage());
        }
    }

    public static function unsafeIdentifiers(): array
    {
        return [
            'backtick in database' => [['database' => 'a`; DROP DATABASE system; --'] + self::CONFIG],
            'quote in username' => [['username' => "a'@'%' IDENTIFIED BY 'x'; --"] + self::CONFIG],
            'empty username' => [['username' => ''] + self::CONFIG],
            'trailing newline' => [['database' => "laravel_alpha\n"] + self::CONFIG],
            'dot in database' => [['database' => 'laravel_alpha.example'] + self::CONFIG],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeIdentifiers')]
    public function test_setup_rejects_unsafe_identifiers_before_running_any_sql(array $config): void
    {
        $driver = $this->driver();
        $this->schema->shouldNotReceive('createDatabase');

        try {
            $driver->setup($config);
            $this->fail('Expected unsafe identifier to be rejected');
        } catch (TenantDatabaseException) {
            $this->assertSame([], $this->statements);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeIdentifiers')]
    public function test_destroy_rejects_unsafe_identifiers_before_running_any_sql(array $config): void
    {
        $driver = $this->driver();
        $this->schema->shouldNotReceive('dropDatabaseIfExists');

        try {
            $driver->destroy($config);
            $this->fail('Expected unsafe identifier to be rejected');
        } catch (TenantDatabaseException) {
            $this->assertSame([], $this->statements);
        }
    }

    public function test_setup_accepts_hyphenated_identifiers(): void
    {
        $driver = $this->driver();
        $this->schema->shouldReceive('createDatabase')->once()->with('laravel_alpha-1');

        $driver->setup(['database' => 'laravel_alpha-1', 'username' => 'laravel_alpha-1'] + self::CONFIG);

        $this->assertSame("GRANT ALL ON `laravel_alpha-1`.* TO `laravel_alpha-1`@'%'", $this->statements[1]);
    }

    public function test_setup_quotes_the_password(): void
    {
        $driver = $this->driver();
        $this->schema->shouldReceive('createDatabase');

        $driver->setup(['password' => "p'w"] + self::CONFIG);

        $this->assertSame("CREATE USER IF NOT EXISTS `laravel_alpha`@'%' IDENTIFIED BY 'p\\'w'", $this->statements[0]);
    }

    public function test_setup_fails_when_a_statement_fails(): void
    {
        $driver = $this->driver(statementResult: false);
        $this->schema->shouldReceive('createDatabase');

        $this->expectException(TenantDatabaseException::class);
        $driver->setup(self::CONFIG);
    }
}

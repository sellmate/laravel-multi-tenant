<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Sellmate\Laravel\MultiTenant\Commands\EnvCheck;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantCommandException;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class TenantCommandFailureTest extends TestCase
{
    private function fixturePath(): string
    {
        return __DIR__ . '/../fixtures/migrations/ok';
    }

    public function test_fails_when_no_tenant_matches(): void
    {
        $this->migrateSystemTenantsTable();
        $this->createTenant('alpha');

        $this->expectException(TenantCommandException::class);
        $this->expectExceptionMessage('No available tenants found');

        $this->artisan('migrate', ['--tenant' => true, '--domain' => 'nope'])->run();
    }

    public function test_fails_when_tenants_table_is_missing(): void
    {
        $this->expectException(TenantCommandException::class);
        $this->expectExceptionMessage('tenants table');

        $this->artisan('tenant:destroy', ['--domain' => 'alpha'])->run();
    }

    /**
     * A server connection outside dev-host-list. The check refuses it before connecting, so the
     * host never has to exist.
     */
    private function useRemoteConnection(array $config = ['host' => 'db.example.com']): void
    {
        config(['database.connections.remote' => ['driver' => 'mysql'] + $config]);
    }

    public function test_development_refuses_a_database_host_outside_the_dev_host_list(): void
    {
        $this->useRemoteConnection();

        try {
            $this->artisan('migrate', ['--database' => 'remote', '--path' => $this->fixturePath(), '--realpath' => true])->run();
            $this->fail('Expected the command to be refused');
        } catch (TenantCommandException $e) {
            $this->assertStringContainsString('운영DB', $e->getMessage());
        }
    }

    public function test_production_refuses_a_wrong_admin_secret(): void
    {
        config(['app.env' => 'production']);

        try {
            $this->artisan('migrate', ['--path' => $this->fixturePath(), '--realpath' => true, '--force' => true, '--secret' => 'wrong'])->run();
            $this->fail('Expected the command to be refused');
        } catch (TenantCommandException $e) {
            $this->assertStringContainsString('비밀번호가 일치하지 않습니다', $e->getMessage());
        }

        $this->assertFalse(Schema::connection('system')->hasTable('widgets'));
    }

    public function test_development_allows_a_hostless_sqlite_connection(): void
    {
        config([
            'multitenancy.dev-host-list' => [],
            'database.connections.local' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);

        $this->artisan('migrate', ['--database' => 'local', '--path' => $this->fixturePath(), '--realpath' => true])
            ->assertSuccessful();

        $this->assertTrue(Schema::connection('local')->hasTable('widgets'));
    }

    public function test_development_refuses_a_hostless_server_connection(): void
    {
        config(['multitenancy.dev-host-list' => []]);
        $this->useRemoteConnection(['database' => 'app']);

        $this->expectException(TenantCommandException::class);

        $this->artisan('migrate', ['--database' => 'remote', '--path' => $this->fixturePath(), '--realpath' => true])->run();
    }

    public function test_development_judges_the_host_from_the_connection_url(): void
    {
        $this->useRemoteConnection(['host' => '127.0.0.1', 'url' => 'mysql://user:pass@db.example.com/app']);

        $this->expectException(TenantCommandException::class);

        $this->artisan('migrate', ['--database' => 'remote', '--path' => $this->fixturePath(), '--realpath' => true])->run();
    }

    public function test_development_ignores_whitespace_around_listed_hosts(): void
    {
        config(['multitenancy.dev-host-list' => [' 10.0.0.5 ']]);
        $check = new class {
            use EnvCheck;

            public function allows(array $config): bool
            {
                return $this->isDevelopmentDatabase($config);
            }
        };

        $this->assertTrue($check->allows(['driver' => 'mysql', 'host' => '10.0.0.5']));
        $this->assertFalse($check->allows(['driver' => 'mysql', 'host' => '10.0.0.6']));
    }

    public function test_graceful_does_not_hide_a_refused_environment_check(): void
    {
        $this->useRemoteConnection();

        $this->expectException(TenantCommandException::class);

        $this->artisan('migrate', ['--database' => 'remote', '--path' => $this->fixturePath(), '--realpath' => true, '--graceful' => true])->run();
    }
}

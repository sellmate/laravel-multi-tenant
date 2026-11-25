<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use App\Models\System\Tenant;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantCommandException;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class TenantSetupCommandTest extends TestCase
{
    public function test_setup_with_migrate_creates_the_database_and_marks_the_tenant_done(): void
    {
        $this->migrateSystemTenantsTable();
        Tenant::create(['domain' => 'alpha', 'name' => 'alpha', 'db_host' => '127.0.0.1', 'setup_has_done' => false]);

        $this->artisan('tenant:setup', ['--domain' => 'alpha', '--migrate' => true])->assertSuccessful();

        $this->assertFileExists($this->tenantDatabaseFile('alpha'));
        $this->assertTrue(Tenant::where('domain', 'alpha')->first()->setup_has_done);
        $this->assertTrue($this->tenantDatabase('alpha')->getSchemaBuilder()->hasTable('migrations'));
    }

    private function createPendingTenant(string $host = '127.0.0.1'): void
    {
        $this->migrateSystemTenantsTable();
        Tenant::create(['domain' => 'alpha', 'name' => 'alpha', 'db_host' => $host, 'setup_has_done' => false]);
    }

    private function assertNothingWasSetUp(): void
    {
        $this->assertFileDoesNotExist($this->tenantDatabaseFile('alpha'));
        $this->assertFalse(Tenant::where('domain', 'alpha')->first()->setup_has_done);
    }

    public function test_development_refuses_a_tenant_host_outside_the_dev_host_list(): void
    {
        // A server driver, so the check judges the tenant's db_host; it refuses before connecting.
        config(['database.connections.tenant_admin' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => '', 'username' => 'admin', 'password' => '']]);
        $this->createPendingTenant('db.example.com');

        try {
            $this->artisan('tenant:setup', ['--domain' => 'alpha'])->run();
            $this->fail('Expected tenant:setup to be refused');
        } catch (TenantCommandException $e) {
            $this->assertStringContainsString('운영DB', $e->getMessage());
        }

        $this->assertNothingWasSetUp();
    }

    public function test_a_refused_tenant_stops_setup_before_any_tenant_is_created(): void
    {
        // `.invalid` never resolves, so even the allowed tenant cannot reach a real server.
        config([
            'multitenancy.dev-host-list' => ['dev.invalid'],
            'database.connections.tenant_admin' => ['driver' => 'mysql', 'host' => 'dev.invalid', 'database' => '', 'username' => 'admin', 'password' => ''],
        ]);
        $this->createPendingTenant('dev.invalid');
        Tenant::create(['domain' => 'beta', 'name' => 'beta', 'db_host' => 'db.example.com', 'setup_has_done' => false]);

        try {
            $this->artisan('tenant:setup')->run();
            $this->fail('Expected tenant:setup to be refused');
        } catch (TenantCommandException) {
        }

        $this->assertNothingWasSetUp();
        $this->assertFalse(Tenant::where('domain', 'beta')->first()->setup_has_done);
    }

    public function test_production_fails_clearly_when_no_password_can_be_entered(): void
    {
        $this->createPendingTenant();
        config(['app.env' => 'production']);

        try {
            \Illuminate\Support\Facades\Artisan::call('tenant:setup', ['--domain' => 'alpha', '--no-interaction' => true]);
            $this->fail('Expected tenant:setup to be refused');
        } catch (TenantCommandException $e) {
            $this->assertStringContainsString('관리 비밀번호가 필요합니다', $e->getMessage());
        }

        $this->assertNothingWasSetUp();
    }

    public function test_production_refuses_a_wrong_admin_password(): void
    {
        $this->createPendingTenant();
        config(['app.env' => 'production']);

        try {
            $this->artisan('tenant:setup', ['--domain' => 'alpha'])
                ->expectsQuestion('관리 비밀번호를 입력하세요', 'wrong')
                ->run();
            $this->fail('Expected tenant:setup to be refused');
        } catch (TenantCommandException $e) {
            $this->assertStringContainsString('비밀번호가 일치하지 않습니다', $e->getMessage());
        }

        $this->assertNothingWasSetUp();
    }

    public function test_failed_migrate_fails_setup_but_keeps_the_created_database_reachable(): void
    {
        $this->migrateSystemTenantsTable();
        Tenant::create(['domain' => 'alpha', 'name' => 'alpha', 'db_host' => '127.0.0.1', 'setup_has_done' => false]);
        $this->app->useDatabasePath(__DIR__ . '/../fixtures/failing-database');

        try {
            $this->artisan('tenant:setup', ['--domain' => 'alpha', '--migrate' => true])->run();
            $this->fail('Expected the failing migration to abort setup');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture migration failure', $e->getMessage());
        }

        // The database exists, so tenant commands (migrate --tenant to retry, tenant:destroy to clean up) must still find it.
        $this->assertTrue(Tenant::where('domain', 'alpha')->first()->setup_has_done);
    }
}

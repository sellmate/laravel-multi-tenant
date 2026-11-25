<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use App\Models\System\Tenant;
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

    public function test_setup_leaves_the_tenant_pending_when_migrate_fails(): void
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

        $this->assertFalse(Tenant::where('domain', 'alpha')->first()->setup_has_done);
    }
}

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
}

<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use App\Models\System\Tenant;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class TenantDestroyCommandTest extends TestCase
{
    public function test_destroy_drops_the_database_and_marks_the_tenant_pending(): void
    {
        $this->migrateSystemTenantsTable();
        $this->createTenant('alpha');

        $this->artisan('tenant:destroy', ['--domain' => 'alpha'])->assertSuccessful();

        $this->assertFileDoesNotExist($this->tenantDatabaseFile('alpha'));
        $this->assertFalse(Tenant::where('domain', 'alpha')->first()->setup_has_done);
    }
}

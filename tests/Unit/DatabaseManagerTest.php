<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Unit;

use App\Models\System\Tenant;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantDatabaseException;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class DatabaseManagerTest extends TestCase
{
    private function tenant(): Tenant
    {
        touch($this->tenantDatabaseFile('alpha'));

        return (new Tenant)->forceFill(['id' => 7, 'domain' => 'alpha', 'db_host' => '127.0.0.1']);
    }

    public function test_tenant_credentials_are_derived_from_app_name_domain_and_key(): void
    {
        config(['multitenancy.key' => 'k3y']);

        (new DatabaseManager)->setTenantConnection($this->tenant());

        $this->assertSame('laravel_alpha', config('database.connections.tenant.username'));
        $this->assertSame(sha1('7alphak3y'), config('database.connections.tenant.password'));
    }

    public function test_falls_back_to_app_key(): void
    {
        config(['multitenancy.key' => null, 'app.key' => 'app-k3y']);

        (new DatabaseManager)->setTenantConnection($this->tenant());

        $this->assertSame(sha1('7alphaapp-k3y'), config('database.connections.tenant.password'));
    }

    public function test_refuses_to_derive_a_password_without_any_key(): void
    {
        config(['multitenancy.key' => null, 'app.key' => null]);

        $this->expectException(TenantDatabaseException::class);

        (new DatabaseManager)->setTenantConnection($this->tenant());
    }
}

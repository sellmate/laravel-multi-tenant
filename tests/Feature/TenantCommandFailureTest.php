<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use Illuminate\Support\Facades\Schema;
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

    public function test_development_refuses_a_database_host_outside_the_dev_host_list(): void
    {
        config(['multitenancy.dev-host-list' => ['10.0.0.1']]);

        try {
            $this->artisan('migrate', ['--path' => $this->fixturePath(), '--realpath' => true])->run();
            $this->fail('Expected the command to be refused');
        } catch (TenantCommandException $e) {
            $this->assertStringContainsString('운영DB', $e->getMessage());
        }

        $this->assertFalse(Schema::connection('system')->hasTable('widgets'));
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

    public function test_graceful_does_not_hide_a_refused_environment_check(): void
    {
        config(['multitenancy.dev-host-list' => ['10.0.0.1']]);

        $this->expectException(TenantCommandException::class);

        $this->artisan('migrate', ['--path' => $this->fixturePath(), '--realpath' => true, '--graceful' => true])->run();
    }
}

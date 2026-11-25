<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class MigrateCommandTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__ . '/../fixtures/migrations/' . $name;
    }

    public function test_migrate_runs_on_system_connection(): void
    {
        $this->artisan('migrate', ['--path' => $this->fixturePath('ok'), '--realpath' => true])
            ->assertSuccessful();

        $this->assertTrue(Schema::connection('system')->hasTable('widgets'));
    }

    public function test_tenant_migrate_with_domain_all_targets_every_tenant(): void
    {
        $this->migrateSystemTenantsTable();
        $this->createTenant('alpha');
        $this->createTenant('beta');

        $this->artisan('migrate', ['--tenant' => true, '--domain' => 'all', '--path' => $this->fixturePath('ok'), '--realpath' => true])
            ->assertSuccessful();

        $this->assertTrue($this->tenantDatabase('alpha')->getSchemaBuilder()->hasTable('widgets'));
        $this->assertTrue($this->tenantDatabase('beta')->getSchemaBuilder()->hasTable('widgets'));
    }

    public function test_tenant_migrate_with_domain_list_targets_only_listed_tenants(): void
    {
        $this->migrateSystemTenantsTable();
        $this->createTenant('alpha');
        $this->createTenant('beta');
        $this->createTenant('gamma');

        $this->artisan('migrate', ['--tenant' => true, '--domain' => 'alpha,gamma', '--path' => $this->fixturePath('ok'), '--realpath' => true])
            ->assertSuccessful();

        $this->assertTrue($this->tenantDatabase('alpha')->getSchemaBuilder()->hasTable('widgets'));
        $this->assertFalse($this->tenantDatabase('beta')->getSchemaBuilder()->hasTable('widgets'));
        $this->assertTrue($this->tenantDatabase('gamma')->getSchemaBuilder()->hasTable('widgets'));
    }

    public function test_graceful_turns_a_failing_migration_into_success(): void
    {
        $this->artisan('migrate', ['--path' => $this->fixturePath('failing'), '--realpath' => true, '--graceful' => true])
            ->assertSuccessful();
    }

    public function test_failing_migration_throws_without_graceful(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture migration failure');

        $this->artisan('migrate', ['--path' => $this->fixturePath('failing'), '--realpath' => true])->run();
    }
}

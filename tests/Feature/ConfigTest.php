<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_package_defaults_apply_without_a_published_config(): void
    {
        $this->assertSame('system', config('multitenancy.system-connection'));
        $this->assertSame('tenant', config('multitenancy.tenant-connection'));
        $this->assertSame('domain', config('multitenancy.tenant-id-column'));
        $this->assertFalse(config('multitenancy.skip-migrations-in-parallel-tests'));
    }

    public function test_migrate_is_skipped_in_testing_when_configured(): void
    {
        config(['multitenancy.skip-migrations-in-parallel-tests' => true]);

        $this->artisan('migrate', ['--path' => __DIR__ . '/../fixtures/migrations/ok', '--realpath' => true])
            ->assertSuccessful();

        $this->assertFalse(Schema::connection('system')->hasTable('widgets'));
    }
}

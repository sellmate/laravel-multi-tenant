<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use App\Models\System\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Sellmate\Laravel\MultiTenant\Exceptions\EnvironmentRefusedException;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantCommandException;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantsFailedException;
use Sellmate\Laravel\MultiTenant\Tests\fixtures\WidgetSeeder;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class SeedCommandTest extends TestCase
{
    private function fixtureMigrations(): string
    {
        return __DIR__ . '/../fixtures/migrations/ok';
    }

    /**
     * Each tenant is set up with the `widgets` table.
     */
    private function setUpTenants(string ...$domains): void
    {
        $this->migrateSystemTenantsTable();
        foreach ($domains as $domain) {
            $this->createTenant($domain);
        }
        $this->artisan('migrate', ['--tenant' => true, '--path' => $this->fixtureMigrations(), '--realpath' => true])->assertSuccessful();
    }

    private function runSeeder(array $options = []): int
    {
        return Artisan::call('db:seed', ['--class' => WidgetSeeder::class] + $options);
    }

    private function widgets(string $domain): int
    {
        return $this->tenantDatabase($domain)->table('widgets')->count();
    }

    public function test_seeds_only_tenants_whose_setup_has_done(): void
    {
        $this->setUpTenants('alpha', 'beta');
        // No widgets table: seeding it would fail.
        $this->createTenant('pending')->update(['setup_has_done' => false]);

        $this->assertSame(0, $this->runSeeder(['--tenant' => true]));

        $this->assertSame(1, $this->widgets('alpha'));
        $this->assertSame(1, $this->widgets('beta'));
    }

    public function test_domain_accepts_a_comma_separated_list(): void
    {
        $this->setUpTenants('alpha', 'beta', 'gamma');

        $this->runSeeder(['--tenant' => true, '--domain' => 'alpha,gamma']);

        $this->assertSame(1, $this->widgets('alpha'));
        $this->assertSame(0, $this->widgets('beta'));
        $this->assertSame(1, $this->widgets('gamma'));
    }

    public function test_domain_matches_the_configured_tenant_id_column(): void
    {
        $this->setUpTenants('alpha', 'beta');
        Tenant::where('domain', 'alpha')->update(['name' => 'Alpha Co']);
        config(['multitenancy.tenant-id-column' => 'name']);

        $this->runSeeder(['--tenant' => true, '--domain' => 'Alpha Co']);

        $this->assertSame(1, $this->widgets('alpha'));
        $this->assertSame(0, $this->widgets('beta'));
    }

    public function test_fails_when_no_tenant_matches(): void
    {
        $this->setUpTenants('alpha');

        $this->expectException(TenantCommandException::class);
        $this->expectExceptionMessage('No available tenants found');

        $this->runSeeder(['--tenant' => true, '--domain' => 'nope']);
    }

    public function test_production_refuses_a_wrong_admin_secret(): void
    {
        $this->setUpTenants('alpha');
        config(['app.env' => 'production']);

        try {
            $this->runSeeder(['--tenant' => true, '--force' => true, '--secret' => 'wrong']);
            $this->fail('Expected the environment check to refuse');
        } catch (EnvironmentRefusedException) {
        }

        $this->assertSame(0, $this->widgets('alpha'));
    }

    public function test_restores_the_default_connection_after_seeding_tenants(): void
    {
        $this->setUpTenants('alpha');
        DB::setDefaultConnection('tenant_admin');
        config(['passport.connection' => 'tenant_admin']);

        $this->runSeeder(['--tenant' => true]);

        $this->assertSame('tenant_admin', DB::getDefaultConnection());
        $this->assertSame('tenant_admin', config('passport.connection'));
    }

    public function test_continue_on_error_seeds_the_tenants_after_a_failing_one(): void
    {
        $this->setUpTenants('alpha', 'beta', 'gamma');
        $this->tenantDatabase('beta')->getSchemaBuilder()->drop('widgets');

        try {
            $this->runSeeder(['--tenant' => true, '--continue-on-error' => true]);
            $this->fail('Expected the run to fail at the end');
        } catch (TenantsFailedException $e) {
            $this->assertSame(['beta'], $e->failedTenants);
        }

        $this->assertSame(1, $this->widgets('alpha'));
        $this->assertSame(1, $this->widgets('gamma'));
    }

    public function test_migrate_with_seed_seeds_each_tenant_database(): void
    {
        $this->migrateSystemTenantsTable();
        $this->createTenant('alpha');
        $this->createTenant('beta');

        $this->artisan('migrate', [
            '--tenant' => true,
            '--path' => $this->fixtureMigrations(),
            '--realpath' => true,
            '--seed' => true,
            '--seeder' => WidgetSeeder::class,
        ])->assertSuccessful();

        $this->assertSame(1, $this->widgets('alpha'));
        $this->assertSame(1, $this->widgets('beta'));
    }

    public function test_migrate_with_seed_without_tenant_seeds_the_system_database(): void
    {
        $this->artisan('migrate', [
            '--path' => $this->fixtureMigrations(),
            '--realpath' => true,
            '--seed' => true,
            '--seeder' => WidgetSeeder::class,
        ])->assertSuccessful();

        $this->assertSame(1, DB::connection('system')->table('widgets')->count());
    }

    public function test_a_named_database_is_checked_like_a_tenant(): void
    {
        $this->artisan('migrate', ['--path' => $this->fixtureMigrations(), '--realpath' => true])->assertSuccessful();
        config(['app.env' => 'production']);

        try {
            $this->runSeeder(['--database' => 'system', '--force' => true, '--secret' => 'wrong']);
            $this->fail('Expected the environment check to refuse');
        } catch (EnvironmentRefusedException) {
        }

        $this->assertSame(0, DB::connection('system')->table('widgets')->count());
    }

    public function test_refresh_with_seed_seeds_each_tenant_database(): void
    {
        $this->setUpTenants('alpha');

        $this->artisan('migrate:refresh', [
            '--tenant' => true,
            '--path' => $this->fixtureMigrations(),
            '--realpath' => true,
            '--seed' => true,
            '--seeder' => WidgetSeeder::class,
        ])->assertSuccessful();

        $this->assertSame(1, $this->widgets('alpha'));
    }

    public function test_without_tenant_seeds_the_system_database(): void
    {
        $this->artisan('migrate', ['--path' => $this->fixtureMigrations(), '--realpath' => true])->assertSuccessful();

        $this->assertSame(0, $this->runSeeder());

        $this->assertSame(1, DB::connection('system')->table('widgets')->count());
    }
}

<?php

namespace Sellmate\Laravel\MultiTenant\Tests;

use App\Models\System\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Sellmate\Laravel\MultiTenant\Providers\MultiTenantServiceProvider;
use Sellmate\Laravel\MultiTenant\Providers\PassportServiceProvider;

abstract class TestCase extends BaseTestCase
{
    private string $originalWorkingDirectory;

    private string $workingDirectory;

    protected function setUp(): void
    {
        // DatabaseManager opens tenant SQLite files at the relative path database/<name>.sqlite,
        // so each test runs inside its own temporary directory.
        $this->originalWorkingDirectory = getcwd();
        $this->workingDirectory = sys_get_temp_dir() . '/multitenant-test-' . bin2hex(random_bytes(6));
        mkdir($this->workingDirectory . '/database', 0777, true);
        chdir($this->workingDirectory);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        chdir($this->originalWorkingDirectory);
        File::deleteDirectory($this->workingDirectory);
    }

    protected function getPackageProviders($app)
    {
        return [MultiTenantServiceProvider::class, PassportServiceProvider::class];
    }

    /**
     * Every connection is SQLite (in-memory or a file in the temporary directory) so tests never reach a real server.
     */
    protected function defineEnvironment($app)
    {
        // EnvCheck refuses hosts outside dev-host-list; SQLite ignores the host.
        $sqlite = [
            'driver' => 'sqlite',
            'host' => '127.0.0.1',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        $app['config']->set('app.name', 'Laravel');
        $app['config']->set('database.default', 'system');
        $app['config']->set('database.connections.system', $sqlite);
        $app['config']->set('database.connections.tenant', $sqlite);
        $app['config']->set('database.connections.tenant_admin', $sqlite);
        $app['config']->set('multitenancy.dev-host-list', ['127.0.0.1']);
    }

    protected function migrateSystemTenantsTable(): void
    {
        $this->artisan('migrate', [
            '--path' => realpath(__DIR__ . '/../database/migrations/system'),
            '--realpath' => true,
        ])->assertSuccessful();
    }

    protected function createTenant(string $domain): Tenant
    {
        touch($this->tenantDatabaseFile($domain));

        return Tenant::create([
            'domain' => $domain,
            'name' => $domain,
            'db_host' => '127.0.0.1',
            'setup_has_done' => true,
        ]);
    }

    protected function tenantDatabaseFile(string $domain): string
    {
        return $this->workingDirectory . '/database/laravel_' . $domain . '.sqlite';
    }

    /**
     * Opens a tenant's database file on a separate connection so assertions never depend on
     * which tenant the package's `tenant` connection currently points to.
     */
    protected function tenantDatabase(string $domain): \Illuminate\Database\Connection
    {
        config(['database.connections.probe' => [
            'driver' => 'sqlite',
            'database' => $this->tenantDatabaseFile($domain),
            'prefix' => '',
        ]]);
        DB::purge('probe');

        return DB::connection('probe');
    }
}

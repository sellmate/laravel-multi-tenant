<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use App\Models\System\Tenant;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantCommandException;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;

class SecretOptionTest extends TestCase
{
    public static function commandsCheckingTheEnvironment(): array
    {
        return [
            'migrate' => ['migrate', ['--force' => true], null],
            'migrate:rollback' => ['migrate:rollback', ['--force' => true], null],
            'migrate:reset' => ['migrate:reset', ['--force' => true], null],
            'migrate:refresh' => ['migrate:refresh', ['--force' => true], null],
            'migrate --tenant' => ['migrate', ['--force' => true, '--tenant' => true, '--domain' => 'alpha'], true],
            'migrate:rollback --tenant' => ['migrate:rollback', ['--force' => true, '--tenant' => true, '--domain' => 'alpha'], true],
            'migrate:reset --tenant' => ['migrate:reset', ['--force' => true, '--tenant' => true, '--domain' => 'alpha'], true],
            'migrate:refresh --tenant' => ['migrate:refresh', ['--force' => true, '--tenant' => true, '--domain' => 'alpha'], true],
            'tenant:destroy' => ['tenant:destroy', ['--domain' => 'alpha'], true],
            'tenant:setup' => ['tenant:setup', ['--domain' => 'alpha'], false],
        ];
    }

    /**
     * Non-interactive, so a command that ignored --secret would fail with "password required"
     * instead of comparing the given secret.
     */
    #[DataProvider('commandsCheckingTheEnvironment')]
    public function test_secret_option_is_checked_in_production(string $command, array $arguments, ?bool $tenantSetUp): void
    {
        if ($tenantSetUp !== null) {
            $this->migrateSystemTenantsTable();
            touch($this->tenantDatabaseFile('alpha'));
            Tenant::create(['domain' => 'alpha', 'name' => 'alpha', 'db_host' => '127.0.0.1', 'setup_has_done' => $tenantSetUp]);
        }
        config(['app.env' => 'production']);

        try {
            Artisan::call($command, $arguments + ['--secret' => 'wrong', '--no-interaction' => true]);
            $this->fail("Expected {$command} to be refused");
        } catch (TenantCommandException $e) {
            $this->assertStringContainsString('비밀번호가 일치하지 않습니다', $e->getMessage());
        }

        if ($tenantSetUp !== null) {
            $this->assertSame($tenantSetUp, Tenant::where('domain', 'alpha')->first()->setup_has_done);
            $this->assertFileExists($this->tenantDatabaseFile('alpha'));
        }
    }

    public function test_a_falsy_looking_secret_is_still_compared(): void
    {
        config(['app.env' => 'production']);

        $this->expectException(TenantCommandException::class);
        $this->expectExceptionMessage('비밀번호가 일치하지 않습니다');

        Artisan::call('migrate:rollback', ['--force' => true, '--secret' => '0', '--no-interaction' => true]);
    }

    public function test_refresh_with_tenant_targets_tenants_by_the_configured_id_column(): void
    {
        $this->migrateSystemTenantsTable();
        touch($this->tenantDatabaseFile('alpha'));
        Tenant::create(['domain' => 'alpha', 'name' => 'Alpha Inc', 'db_host' => '127.0.0.1', 'setup_has_done' => true]);
        config(['multitenancy.tenant-id-column' => 'name']);
        $path = __DIR__ . '/../fixtures/migrations/ok';

        $this->artisan('migrate:refresh', ['--tenant' => true, '--domain' => 'Alpha Inc', '--path' => $path, '--realpath' => true])
            ->assertSuccessful();

        $this->assertTrue($this->tenantDatabase('alpha')->getSchemaBuilder()->hasTable('widgets'));
    }
}

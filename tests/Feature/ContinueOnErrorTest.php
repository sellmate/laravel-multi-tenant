<?php

namespace Sellmate\Laravel\MultiTenant\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Sellmate\Laravel\MultiTenant\Exceptions\EnvironmentRefusedException;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantsFailedException;
use Sellmate\Laravel\MultiTenant\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class ContinueOnErrorTest extends TestCase
{
    private BufferedOutput $output;

    private function migrateOptions(array $options = []): array
    {
        return ['--tenant' => true, '--path' => __DIR__ . '/../fixtures/migrations/ok', '--realpath' => true] + $options;
    }

    /**
     * alpha, beta and gamma, in that order; beta already has the `widgets` table the fixture creates,
     * so migrating beta fails.
     */
    private function tenantsWithBetaBroken(): void
    {
        $this->migrateSystemTenantsTable();
        foreach (['alpha', 'beta', 'gamma'] as $domain) {
            $this->createTenant($domain);
        }
        $this->tenantDatabase('beta')->getSchemaBuilder()->create('widgets', fn ($table) => $table->id());
    }

    private function runCommand(string $command, array $parameters): int
    {
        $this->output = new BufferedOutput();

        return Artisan::call($command, $parameters, $this->output);
    }

    private function migrated(string $domain): bool
    {
        return $this->tenantDatabase($domain)->getSchemaBuilder()->hasTable('migrations')
            && $this->tenantDatabase($domain)->table('migrations')->count() > 0;
    }

    public function test_by_default_the_first_failure_stops_the_run_and_says_where(): void
    {
        $this->tenantsWithBetaBroken();

        try {
            $this->runCommand('migrate', $this->migrateOptions());
            $this->fail('Expected the failing tenant to stop the run');
        } catch (QueryException) {
        }

        $this->assertTrue($this->migrated('alpha'));
        $this->assertFalse($this->migrated('gamma'), 'tenants after the failure must not run by default');
        $this->assertStringContainsString("Stopped at 'beta'; 1 of 3 tenants were done before it. Not run: gamma.", $this->output->fetch());
    }

    public function test_continue_on_error_runs_the_remaining_tenants_and_fails_at_the_end(): void
    {
        $this->tenantsWithBetaBroken();

        try {
            $this->runCommand('migrate', $this->migrateOptions(['--continue-on-error' => true]));
            $this->fail('Expected the run to fail at the end');
        } catch (TenantsFailedException $e) {
            $this->assertSame(['beta'], $e->failedTenants);
            $this->assertStringContainsString('1 of 3 tenants failed: beta', $e->getMessage());
        }

        $this->assertTrue($this->migrated('alpha'));
        $this->assertTrue($this->migrated('gamma'));
        $this->assertStringContainsString("'beta' failed:", $this->output->fetch());
    }

    public function test_continue_on_error_reports_success_when_every_tenant_succeeds(): void
    {
        $this->migrateSystemTenantsTable();
        $this->createTenant('alpha');
        $this->createTenant('beta');

        $this->assertSame(0, $this->runCommand('migrate', $this->migrateOptions(['--continue-on-error' => true])));
        $this->assertStringContainsString('All 2 tenants succeeded.', $this->output->fetch());
    }

    public function test_continue_on_error_never_continues_past_a_refused_environment_check(): void
    {
        $this->tenantsWithBetaBroken();
        config(['app.env' => 'production']);

        try {
            $this->runCommand('migrate', $this->migrateOptions(['--continue-on-error' => true, '--force' => true, '--secret' => 'wrong']));
            $this->fail('Expected the environment check to refuse');
        } catch (EnvironmentRefusedException) {
        }

        foreach (['alpha', 'beta', 'gamma'] as $domain) {
            $this->assertFalse($this->migrated($domain), "{$domain} must not run after a refused environment check");
        }
    }

    public function test_graceful_tolerates_the_failures_collected_by_continue_on_error(): void
    {
        $this->tenantsWithBetaBroken();

        $this->assertSame(0, $this->runCommand('migrate', $this->migrateOptions(['--continue-on-error' => true, '--graceful' => true])));
        $this->assertTrue($this->migrated('gamma'));
    }

    public static function tenantCommands(): array
    {
        return array_map(fn ($command) => [$command], [
            'migrate', 'migrate:install', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'migrate:status',
            'db:seed', 'passport:client', 'passport:install', 'passport:hash', 'passport:purge',
            'tenant:setup', 'tenant:destroy',
        ]);
    }

    #[DataProvider('tenantCommands')]
    public function test_every_tenant_command_accepts_continue_on_error(string $command): void
    {
        $option = $this->app[Kernel::class]->all()[$command]->getDefinition()->getOption('continue-on-error');

        $this->assertFalse($option->acceptValue());
    }
}
